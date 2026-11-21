<?php
/**
 * Candy框架 文件夹操作类
 * 
 * Dir类提供了一套完整的目录操作API，用于管理文件系统中的目录和文件。
 * 该类支持目录的创建、删除、复制、移动，以及文件列表获取、目录状态检查等功能。
 * 同时实现了类似SPL DirectoryIterator的接口方法，便于与PHP标准库兼容使用。
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-04-12 13:45:33
 * @final 该类不允许被继承
 */
declare(strict_types=1);
namespace Candy\Extend;

final class Dir {

    /**
     * 存储目录列表数据的私有静态数组
     * 
     * 用于缓存listFile方法获取的目录和文件信息，提高重复调用性能。
     * 
     * @var array 目录和文件的详细信息数组
     */
    private static array $cachedListing = [];

    /**
     * 目录列表缓存：guid => ['mtime' => int, 'data' => array]
     *
     * 键含「目录 + 模式 + 保护列表」，失效依据是目录自身的 mtime
     * （新增/删除条目会改 mtime，改文件内容不会 —— 对“找类文件”这个用途正好）。
     *
     * @var array<string, array{mtime:int, data:array}>
     */
    private static array $listFileCache = [];

    /** @var int 命中次数（排障用，不参与业务） */
    private static int $listFileCacheHits = 0;

    /** @var int 扫描次数（排障用，不参与业务） */
    private static int $listFileScans = 0;

    /**
     * 编译缓存目录的代际（Runtime/Comps 的 mtime）
     *
     * 部署清缓存会清空 Runtime/Comps，其 mtime 随之变化；
     * 代际一变就把目录列表缓存整体作废 —— 这就是缓存失效的“触发点”，
     * 挂在框架自己的编译缓存上，业务层不需要知道。
     *
     * @var int|null
     */
    private static ?int $listFileEpoch = null;
    
    /**
     * 错误信息存储变量
     * 
     * 用于存储操作过程中可能发生的错误信息。
     * 
     * @var string 错误信息字符串
     */
    public static $error = "";

    /**
     * 递归创建目录
	 *
     * 创建指定路径的目录，如果父目录不存在也会一并创建。
     * 可指定目录的权限模式，默认为0755（所有者可读写执行，其他人可读可执行）。
     * 如果目录已存在，则直接返回true，避免重复操作。
     * 
     * @param string $path 要创建的目录路径
     * @param int $mode 目录权限模式，默认为0755
     * @return bool 创建成功返回true，失败返回false
     * @example
     * ```php
     * // 创建单个目录
     * Dir::create('./new/directory');
     * 
     * // 创建多级目录
     * Dir::create('./parent/child/grandchild');
     * 
     * // 自定义权限创建目录
     * Dir::create('./secure/dir', 0700);
     * ```
     */
    public static function create(string $path,int $mode = 0755): bool
	{
		if(is_dir($path)) return true;
		$path = str_replace("\\", "/", $path);
		
		//解决跨域
		$path = str_replace(C('CANDYROOT'), '', $path);
		if(substr($path, -1) != '/') $path = $path.'/';
		$temp = explode('/', $path);
		$cur_dir = C('CANDYROOT');
		$max = count($temp) - 1;
		for($i=0; $i<$max; $i++) {
			$cur_dir .= $temp[$i].'/';
			if (@is_dir($cur_dir)) continue;
			@mkdir($cur_dir, $mode, true);
			@chmod($cur_dir, $mode);
		}
		return is_dir($path);
    }

    /**
     * 列出目录中的文件和子目录
	 *
     * 递归获取指定目录下的所有文件和子目录信息。
     * 每个文件和目录信息包含完整的元数据，如名称、路径、大小、权限等。
     * 使用静态缓存提高性能，避免重复扫描相同的目录结构。
     * 支持文件匹配模式过滤，并可排除指定的受保护目录。
     * 
     * @param string $pathname 要扫描的目录路径，必须以斜杠结尾
     * @param string $pattern 文件匹配模式，默认为'*'（匹配所有文件），支持glob通配符
     * @param array $protect 受保护的目录列表，不会递归扫描这些目录
     * @return array 返回包含文件和目录详细信息的数组
     * @example
     * ```php
     * // 获取所有文件信息
     * $allFiles = Dir::listFile('./path/to/dir/');
     * 
     * // 仅列出PHP文件
     * $phpFiles = Dir::listFile('./path/to/dir/', '*.php');
     * 
     * // 限制递归扫描，排除特定目录
     * $files = Dir::listFile('./path/to/dir/', '*', ['./path/to/dir/exclude']);
     * ```
     */
    public static function listFile(string|array $pathname,string $pattern = '*',array $protect = []): array
	{

        if(is_array($pathname)){
            $dirs = [];
            foreach($pathname as $key => $item){
                $dirs = array_merge($dirs, self::listFile($item, $pattern, $protect));
            }
            return $dirs;
        }

        //整理$protect，让条目支持 /path_base/{path1,path2} 格式 把该模式自动展开成 /path_base/path1 和/path_base/path2
        $ext = [];
        foreach($protect as $key => $item){
            if(strpos($item, '{') !== false){
                unset($protect[$key]);
                $basepath = substr($item, 0, strpos($item, '{'));
                $paths = explode(',', substr($item, strpos($item, '{') + 1, -1));
                foreach($paths as $path){
                    $ext[] = $basepath . $path;
                }
            }
        }
        if(count($ext) > 0)
            $protect = array_merge($protect, $ext);
        
        // 代际校验：Runtime/Comps 被清空/重建（部署、重编译）后整表作废
        self::syncListFileEpoch();

        // 缓存键必须带上 protect：同一目录在不同保护列表下结果不同
        $guid  = md5($pathname . $pattern . '|' . implode(',', $protect));
        $mtime = @filemtime(rtrim($pathname, '/\\') ?: $pathname);

        // 目录 mtime 一致 = 条目没增删，直接复用上次扫描结果。
        // ⚠️ mtime 只有秒级精度：同一秒内「先加后删」两次变动会得到相同 mtime，
        // 所以最近 2 秒内变动过的目录一律按「热」处理、强制重扫，避免漏掉同秒改动。
        $recent = $mtime !== false && $mtime >= time() - 2;
        if (!$recent && isset(self::$listFileCache[$guid]) && $mtime !== false
            && self::$listFileCache[$guid]['mtime'] === $mtime) {
            self::$listFileCacheHits++;
            self::$cachedListing = self::$listFileCache[$guid]['data'];
            return self::$cachedListing;
        }

        self::$listFileScans++;
        {
            // 初始化返回数组
            $dir = [];
            // 使用glob函数获取匹配指定模式的文件列表
            $list = glob($pathname . $pattern);
			
			//指定类型时添加上目录
			if($pattern != '*'){
				// 当指定了特定文件模式时，额外获取所有子目录
				$dirList = glob($pathname . '*', GLOB_ONLYDIR);
				$list = array_merge($list, $dirList);
			}
            
            // 遍历所有匹配的文件和目录
            foreach ($list as $file) {
				// 为每个文件/目录收集详细信息
				$dir[] = [
					'filename'=>preg_replace('/^.+[\\\\\\/]/', '', $file),  // 提取文件名
					'pathname'=>realpath($file),  // 获取规范化的绝对路径
					'owner'=>fileowner($file),    // 文件所有者ID
					'perms'=>fileperms($file),    // 文件权限
					'inode'=>fileinode($file),    // 文件inode编号
					'group'=>filegroup($file),    // 文件组ID
					'path'=>dirname($file),       // 目录路径
					'atime'=>fileatime($file),    // 最后访问时间
					'ctime'=>filectime($file),    // inode修改时间
					'size'=>filesize($file),      // 文件大小
					'type'=>filetype($file),      // 文件类型
					'ext'=>is_file($file) && stripos($file, '.') ? strtolower(substr(strrchr(basename($file), '.'), 1)) : '',  // 文件扩展名
					'mtime'=>filemtime($file),    // 最后修改时间
					'isDir'=>is_dir($file),       // 是否为目录
					'isFile'=>is_file($file),     // 是否为文件
					'isLink'=>is_link($file),     // 是否为符号链接
					'isReadable'=>is_readable($file),  // 是否可读
					'isWritable'=>is_writable($file)   // 是否可写
				];
				
				// 递归逻辑：如果当前项是目录且不在保护列表中，则递归处理子目录
				if(is_dir($file) && !in_array($file, $protect)){
					// 获取子目录的文件列表
					$sonList = self::listFile(realpath($file) . '/', $pattern, $protect);
					// 合并子目录的结果到当前结果数组
					$dir = array_merge($dir, $sonList);
				}
            }
			
            // 对结果排序：确保目录排在文件前面
            usort($dir, function ($a,$b){
				$k  =  "isDir";
				if($a[$k]  ==  $b[$k])  return  0;
				return  $a[$k]>$b[$k]?-1:1;
			});
            
            // 更新静态属性和缓存（把目录 mtime 一起存，供下次判断是否失效）
            self::$cachedListing = $dir;
            if ($mtime !== false) {
                self::$listFileCache[$guid] = ['mtime' => $mtime, 'data' => $dir];
            }
        }

		return self::$cachedListing ?? [];
    }

    /**
     * 清空目录列表缓存
     *
     * 用于部署后/批量增删文件后立即生效；正常运行时靠目录 mtime 自动失效，
     * 一般不需要手动调用。
     *
     * @return void
     * @access public
     * @static
     */
    public static function flushListFileCache(): void
    {
        self::$listFileCache = [];
        self::$cachedListing = [];
        self::$listFileEpoch = null;
    }

    /**
     * 同步编译缓存代际，变化时作废整张目录列表缓存
     *
     * @return void
     * @access private
     * @static
     */
    private static function syncListFileEpoch(): void
    {
        $runtime = (string) C('RUNTIME');
        if ($runtime === '') {
            return;
        }
        $epoch = @filemtime(rtrim($runtime, '/\\') . '/Comps');
        if ($epoch === false) {
            return;
        }
        if (self::$listFileEpoch === null) {
            self::$listFileEpoch = $epoch;
            return;
        }
        if (self::$listFileEpoch !== $epoch) {
            self::$listFileEpoch = $epoch;
            self::$listFileCache = [];
        }
    }

    /**
     * 目录列表缓存统计（排障用）
     *
     * @return array{hits:int, scans:int, entries:int}
     * @access public
     * @static
     */
    public static function listFileCacheStats(): array
    {
        return [
            'hits'    => self::$listFileCacheHits,
            'scans'   => self::$listFileScans,
            'entries' => count(self::$listFileCache),
        ];
    }

    /**
     * 获取数组中的当前元素（单元）
	 *
     * 返回数组中的当前元素，使用PHP内置的current()函数。
     * 如果提供的参数不是数组，则返回false。
     * 
     * @param array $arr 要操作的数组
     * @return mixed 返回数组的当前元素，非数组时返回false
     * @example
     * ```php
     * // 获取数组当前元素
     * $files = Dir::listFile('./path/to/dir/');
     * $currentFile = Dir::current($files);
     * ```
     */
    public static function current(array $arr): mixed
	{
        if (!is_array($arr)) {
            return false;
        }
        return current($arr);
    }

    /**
     * 文件上次访问时间
	 *
     * @return integer
     */
    public function getATime(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['atime'];
    }

    /**
     * 取得文件的 inode 修改时间
	 *
     * @return integer
     */
    public function getCTime(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['ctime'];
    }

    /**
     * 遍历子目录文件信息
	 *
     * @return DirectoryIterator
     */
    public function getChildren()
	{
        $current = $this->current($this->cachedListing);
        if ($current['isDir']) {
            return new Dir($current['pathname']);
        }
        return false;
    }

    /**
     * 取得文件名
	 *
     * @return string
     */
    public function getFilename(): string
	{
        $current = $this->current($this->cachedListing);
        return $current['filename'];
    }

    /**
     * 取得文件的组
	 *
     * @return integer
     */
    public function getGroup(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['group'];
    }

    /**
     * 取得文件的 inode
	 *
     * @return integer
     */
    public function getInode(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['inode'];
    }

    /**
     * 取得文件的上次修改时间
	 *
     * @return integer
     */
    public function getMTime(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['mtime'];
    }

    /**
     * 取得文件的所有者
	 *
     * @return string
     */
    function getOwner(): string
	{
        $current = $this->current($this->cachedListing);
        return $current['owner'];
    }

    /**
     * 取得文件路径，不包括文件名
	 *
     * @return string
     */
    public function getPath(): string
	{
        $current = $this->current($this->cachedListing);
        return $current['path'];
    }

    /**
     * 取得文件的完整路径，包括文件名
	 *
     * @return string
     */
    public function getPathname(): string
	{
        $current = $this->current($this->cachedListing);
        return $current['pathname'];
    }

    /**
     * 取得文件的权限
	 *
     * @return integer
     */
    public function getPerms(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['perms'];
    }

    /**
     * 取得文件的大小
	 *
     * @return integer
     */
    public function getSize(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['size'];
    }

    /**
     * 取得文件类型
	 *
     * @return string
     */
    public function getType(): int
	{
        $current = $this->current($this->cachedListing);
        return $current['type'];
    }

    /**
     * 是否为目录
	 *
     * @return boolen
     */
    public function isDir(): bool
	{
        $current = $this->current($this->cachedListing);
        return $current['isDir'];
    }

    /**
     * 是否为文件
	 *
     * @return boolen
     */
    public function isFile(): bool
	{
        $current = $this->current($this->cachedListing);
        return $current['isFile'];
    }

    /**
     * 文件是否为一个符号连接
	 *
     * @return boolen
     */
    public function isLink(): bool
	{
        $current = $this->current($this->cachedListing);
        return $current['isLink'];
    }

    /**
     * 文件是否可以执行
	 *
     * @return boolen
     */
    public function isExecutable(): bool
	{
        $current = $this->current($this->cachedListing);
        return $current['isExecutable'];
    }

    /**
     * 文件是否可读
	 *
     * @return boolen
     */
    public function isReadable(): bool
	{
        $current = $this->current($this->cachedListing);
        return $current['isReadable'];
    }

    /**
     * 获取目录的迭代器
	 *
     * 创建并返回当前目录内容的ArrayObject迭代器，用于遍历目录内容。
     * 可以使用标准的Iterator接口方法来遍历目录中的文件和子目录。
     * 
     * @return \ArrayObject 返回目录内容的数组迭代器对象
     * @example
     * ```php
     * // 使用迭代器遍历目录
     * $dir = new Dir('./path/to/dir');
     * $iterator = $dir->getIterator();
     * foreach ($iterator as $item) {
     *     echo $item['filename'] . PHP_EOL;
     * }
     * ```
     */
    public function getIterator(): mixed
	{
        return new \ArrayObject($this->cachedListing);
    }
	
	/**
     * 返回目录的数组信息
	 *
     * @return array
     */
    public function toArray(): array
	{
        return $this->cachedListing;
    }
	
    /**
     * 判断目录是否为空
	 *
     * @return boolen
     */
    public function isEmpty(string $directory): bool
	{
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    /**
     * 获取目录中的文件列表
	 *
     * 获取指定目录下符合条件的所有文件列表，并可按指定字段排序。
     * 支持排除指定文件和目录，以及返回完整的文件信息或仅文件路径。
     * 使用静态缓存提高性能，避免重复扫描相同的目录。
     * 
     * @param string|array $directory 目录路径，或包含[目录路径, 保护设置, 是否返回完整信息]的数组
     * @param string $pattern 文件匹配模式，默认为'*'（匹配所有文件）
     * @param string $type 排序字段，可选值：name（文件名）、size（文件大小）、time（访问时间）
     * @param string $sort 排序方向，可选值：DESC（降序）、ASC（升序）
     * @return array 返回文件路径数组或包含文件详细信息的数组
     * @example
     * ```php
     * // 获取所有文件，默认按文件名降序排列
     * $files = Dir::getFiles('./path/to/dir');
     * 
     * // 获取PHP文件并按大小排序
     * $phpFiles = Dir::getFiles('./path/to/dir', '*.php', 'size', 'ASC');
     * 
     * // 排除特定文件和目录，返回完整信息
     * $options = [
     *     './path/to/dir',
     *     ['files' => ['.gitignore'], 'dirs' => ['.git']],
     *     true
     * ];
     * $files = Dir::getFiles($options);
     * ```
     */
    public static function getFiles($directory,string $pattern = '*', string $type = 'name',string $sort = 'DESC'): array
	{
		// 静态缓存：避免重复扫描相同的目录和过滤条件
		static $_listFiles = [];
		
		// 处理保护设置和完整信息选项
		$protect = $full = false;
		if(is_array($directory)){
			[$directory, $protect, $full] = $directory;
		}
		// $protect 初始值是 false，直接 $protect['files'] ??= [] 会触发
		// "Automatic conversion of false to array is deprecated"（PHP 8.5）
		if(!is_array($protect)){
			$protect = [];
		}
		$protectFiles = $protect['files'] ?? [];  // 要排除的文件列表
		$protectDirs = $protect['dirs'] ?? [];    // 要排除的目录列表
		
		// 生成唯一缓存键，确保相同参数组合返回缓存结果
		$guid = md5($directory . $pattern . $type . $sort);
        if (!isset($_listFiles[$guid])) {
			// 利用listFile方法获取文件列表，传入保护目录列表以排除特定目录
			$list = self::listFile($directory, $pattern, $protectDirs);
			$files = [];

			// 初始化排序字段数组，避免未定义变量
			$name = [];
			$size = [];
			$time = [];

			// 过滤文件，仅包含普通文件且不在保护文件列表中
			foreach($list as $file){
				if($file['isFile'] && !in_array($file['filename'], $protectFiles)){
					// 分别收集用于排序的字段值
					$name[] = $file['filename'];
					$size[] = $file['size'];
					$time[] = $file['atime']; // 获取文件最近访问时间

					// 根据full参数决定返回格式
					// full=true时返回详细信息数组，否则仅返回路径名
					$files[] = $full ? ['name'=>$file['filename'],'size'=>$file['size'],'time'=>$file['atime'],'path'=>$file['pathname']] : $file['pathname'];
				}
			}

			// 根据指定类型和排序方向对文件进行排序
			// 使用安全的字段映射替代可变变量 $$type
			$sortField = match($type){
				'name' => $name,
				'size' => $size,
				'time' => $time,
				default => $name,
			};
			$sort == 'DESC' ? array_multisort($sortField, SORT_DESC, SORT_STRING, $files) : array_multisort($sortField, SORT_ASC, SORT_STRING, $files);
			
			// 缓存结果，以便下次相同调用直接返回
			$_listFiles[$guid] = $files;
		}
		
		return $_listFiles[$guid];
    }
	
    /**
     * 获取目录的内容列表
	 *
     * 简单获取目录下的所有项目（文件和子目录），不包括'.'和'..'。
     * 此方法不递归处理子目录，仅返回当前目录的直接内容。
     * 
     * @param string $directory 要列出内容的目录路径
     * @return array 返回目录内容列表
     * @example
     * ```php
     * // 获取目录下所有文件和子目录
     * $items = Dir::getList('./path/to/dir');
     * ```
     */
    public static function getList(string $directory): array
	{
        $scandir = scandir($directory);
        $dir = [];
        foreach ($scandir as $k => $v) {
            if ($v == '.' || $v == '..') {
                continue;
            }
            $dir[] = $v;
        }
        return $dir;
    }

    /**
     * 递归删除目录及其所有内容
	 *
     * 删除指定目录及其所有子目录和文件。
     * 这个操作不可逆，请谨慎使用，确保不会删除重要数据。
     * 
     * @param string $directory 要删除的目录路径
     * @param bool $subdir 是否递归删除子目录，默认为true
     * @return bool 操作成功返回true，目录不存在返回false
     * @example
     * ```php
     * // 删除目录及其所有内容
     * Dir::delDir('./path/to/delete');
     * ```
     */
    public static function delDir(string $directory,bool $subdir = true)
	{
        if (is_dir($directory) == false) {
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                is_dir("$directory/$file") ? Dir::delDir("$directory/$file") : @unlink("$directory/$file");
            }
        }
        if (readdir($handle) == false) {
            closedir($handle);
            @rmdir($directory);
        }
    }

    /**
     * 删除目录下的所有文件，但保留目录本身
	 *
     * 仅删除目录中的文件，不会删除子目录，也不会删除目录本身。
     * 这个操作不可逆，请谨慎使用。
     * 
     * @param string $directory 要清空文件的目录路径
     * @return bool 操作成功返回true，目录不存在返回false
     * @example
     * ```php
     * // 清空目录中的所有文件
     * Dir::del('./path/to/clear');
     * ```
     */
    public static function del(string $directory): bool
	{
        if (is_dir($directory) == false) {
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != ".." && is_file("$directory/$file")) {
                @unlink("$directory/$file");
            }
        }
        closedir($handle);
        return true; // 操作成功返回true
    }

    /**
     * 递归复制目录及其所有内容
	 *
     * 将源目录及其所有子目录和文件复制到目标位置。
     * 支持排除特定文件和目录，以及保留文件权限。
     * 目标目录不存在时会自动创建。
     * 
     * @param string $source 源目录路径
     * @param string $destination 目标目录路径
     * @return bool 复制成功返回true，失败返回false
     * @example
     * ```php
     * // 基本复制
     * Dir::copyDir('./source/dir', './destination/dir');
     * ```
     */
    public static function copyDir(string $source,string $destination): bool
	{
        if (is_dir($source) == false) {
            return false;
        }
        if (is_dir($destination) == false) {
            mkdir($destination, 0755, true);
        }
        $handle = opendir($source);
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                if (is_dir("$source/$file")) {
                    self::copyDir("$source/$file", "$destination/$file");
                } else {
                    copy("$source/$file", "$destination/$file");
                }
            }
        }
        closedir($handle);
        return true; // 操作成功返回true
    }
	
    /**
     * 移动或重命名目录
	 *
     * 将源目录移动到目标位置，相当于重命名目录。
     * 如果目标目录不存在，将进行重命名；如果目标目录存在且为空，则会尝试移动。
     * 此方法使用PHP的rename()函数，对于跨设备移动可能会失败。
     * 
     * @param string $source 源目录路径
     * @param string $destination 目标目录路径
     * @return bool 移动成功返回true，失败返回false
     * @example
     * ```php
     * // 移动目录
     * Dir::moveDir('./old/path', './new/path');
     * ```
     */
    public static function moveDir(string $source,string $destination): bool
	{
        if (is_dir($source) == false) {
            return false;
        }
        // 使用PHP原生rename函数移动目录
        return rename($source, $destination);
        $handle = opendir($source);
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                if (is_dir("$source/$file")) {
                    self::moveDir("$source/$file", "$destination/$file");
					@rmdir("$source/$file");
                } else {
                    copy("$source/$file", "$destination/$file");
					@unlink("$source/$file");
                }
            }
        }
        closedir($handle);
    }
	
    /**
     * 获取目录的状态信息
	 *
     * 返回目录的详细状态信息，包括权限、所有者、大小、创建时间、修改时间等。
     * 如果目录不存在，返回空数组。
     * 
     * @param string|array $path 要获取状态的目录路径或路径数组
     * @return array 返回状态信息数组
     * @example
     * ```php
     * // 获取目录状态
     * $state = Dir::state('./path/to/dir');
     * ```
     */
    public static function state($path): array
	{
		if(is_string($path)) $path = [$path];
		
		clearstatcache();
		$info = [];
		foreach ($path as $key => $val){
			$tempArray = [];
			if(file_exists($val)){
				$tempArray['R'] = is_readable($val) ? 'Y' : 'N';
				$tempArray['W'] = is_writable($val) ? 'Y' : 'N';
				if(is_dir($val)) $tempArray['Ex'] = is_executable($val) ? 'Y' : 'N';
			}
			else{
				$tempArray = [];
			}
			$info[$val] = $tempArray;
		}
		return $info;
    }
	
    /**
     * 计算目录总大小
	 *
     * 递归计算目录及其所有子目录和文件的总大小，并返回格式化后的大小字符串。
     * 此方法可能会消耗大量资源，特别是对于包含大量文件的大型目录。
     * 
     * @param string $path 要计算大小的目录路径
     * @param int $dept 递归层数，通常无需手动指定
     * @return string 返回格式化后的目录大小字符串
     * @example
     * ```php
     * // 计算目录大小
     * $size = Dir::size('./path/to/dir');
     * ```
     */
    public static function size(string $path,int $dept = 0): string
	{
		$dirSize=0;
		if(file_exists($path) && $dirHandle=@opendir($path)){
			while($fileName = readdir($dirHandle)){
				if($fileName != '.' && $fileName != '..'){
					$subFile=$path .'/'. $fileName;
					if(is_dir($subFile))
						$dirSize += self::size($subFile, $dept+1);
					if(is_file($subFile))
						$dirSize += filesize($subFile);
				}
			}
			closedir($dirHandle);
			if($dept == 0){
				return sizeCount($dirSize);
			}
		}
        return $dirSize;
    }

    /**
     * 列举目录下一层内容（不递归子目录）
     *
     * 基于 listFile 获取当前目录直接子项的完整元数据
     * 子目录列入保护列表，避免向下递归扫描
     *
     * @param string $directory 目录绝对路径
     * @return array<int, array<string, mixed>> listFile 同结构条目
     */
    public static function listChildren(string $directory): array
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        if (!is_dir(rtrim($directory, '/'))) {
            return [];
        }
        $protect = glob($directory . '*', GLOB_ONLYDIR) ?: [];
        return self::listFile($directory, '*', $protect);
    }

    /**
     * 格式化权限与所有者展示文本
     *
     * 支持传入绝对路径，或 listFile / listChildren 返回的单条元数据
     * 展示格式与后台文件管理一致，如 755/www
     *
     * @param string|array $pathOrMeta 绝对路径或 listFile 元数据数组
     * @return string
     */
    public static function permOwner(string|array $pathOrMeta): string
    {
        if (is_array($pathOrMeta)) {
            $perms = (int)($pathOrMeta['perms'] ?? 0);
            if ($perms <= 0) {
                return '-';
            }
            $mode = substr(sprintf('%o', $perms), -3);
            return $mode . '/' . self::resolveOwnerName((int)($pathOrMeta['owner'] ?? 0));
        }

        if (!file_exists($pathOrMeta)) {
            return '-';
        }
        $perms = fileperms($pathOrMeta);
        if ($perms === false) {
            return '-';
        }
        $mode = substr(sprintf('%o', $perms), -3);
        $ownerUid = fileowner($pathOrMeta);
        return $mode . '/' . self::resolveOwnerName($ownerUid !== false ? (int)$ownerUid : 0);
    }

    /**
     * 将 UID 解析为可读用户名
     *
     * @param int $uid 文件所有者 UID
     * @return string
     * @access private
     */
    private static function resolveOwnerName(int $uid): string
    {
        if ($uid > 0 && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info) && !empty($info['name'])) {
                return (string)$info['name'];
            }
        }
        return 'www';
    }
}

