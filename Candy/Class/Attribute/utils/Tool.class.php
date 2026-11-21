<?php
/**
 * Attribute工具类
 * 
 * 该类提供了一套完整的属性和文档注释处理工具，用于解析控制器类及其方法的
 * 注释信息，支持PHP 8 Attributes和传统文档注释两种方式，主要用于权限控制、
 * 菜单生成和文档自动生成等功能。
 * 
 * @package Candy\Extend\Attribute\utils
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-11-19 13:00:11 $
 */
declare(strict_types=1);
namespace Candy\Extend\Attribute\utils;
use \Candy\Core\Debug;

class Tool
{
	/**
	 * 节点扫描算法版本。
	 *
	 * 该版本会写入源码清单指纹；扫描算法有不兼容调整时递增，避免复用旧的派生节点缓存。
	 */
	private const NODE_SCAN_VERSION = 'node-scan-v2';
	/**
	 * DEBUG 模式下源码清单的跨请求检查间隔（秒）。
	 *
	 * 发布或修改注解后仍可通过 Node::clearNodeCache() 立即失效；短 TTL 只避免每个请求递归遍历全部控制器目录。
	 */
	private const NODE_SOURCE_CHECK_TTL = 30;

	private static $objCacheList = [];	//映射列表
	private static $classListCache = [];	//getClassList 进程内缓存
	private static $annotationInfoCache = [];	//getAnnotationInfo 进程内缓存（DEBUG 不写）

	private static $lastNodeSourceRevision = '';

	/**
	 * 重置控制器扫描缓存（改注解/菜单后调用）
	 */
	public static function resetScanCache(): void
	{
		self::$objCacheList = [];
		self::$classListCache = [];
		self::$annotationInfoCache = [];
	}

	/**
	 * 获取控制器源码清单的稳定指纹。
	 *
	 * DEBUG 模式下按短 TTL 检查文件清单；只有清单发生变化时，才对新增或变更文件计算文本 hash，
	 * 并更新共享清单。这样可以复用节点派生缓存，同时避免每个导航请求递归遍历全部控制器目录。
	 * 发布或修改注解后可调用 Node::clearNodeCache() 立即失效；请求级缓存由 T() 隔离，
	 * 长驻服务不会把不同请求或协程的指纹混用。
	 *
	 * @return string 当前控制器源码清单指纹
	 */
	public static function getNodeSourceRevision(): string
	{
		$requestRevision = function_exists('T')
			? T('candy_node_source_revision', '', null)
			: null;
		if(is_string($requestRevision) && $requestRevision !== ''){
			return $requestRevision;
		}

		$storedManifest = function_exists('G') ? G('nodeSourceManifest') : null;
		$storedManifest = is_array($storedManifest) ? $storedManifest : [];
		$storedRevision = '';
		if(($storedManifest['version'] ?? null) === self::NODE_SCAN_VERSION
			&& is_string($storedManifest['fingerprint'] ?? null)
			&& $storedManifest['fingerprint'] !== ''){
			$storedRevision = $storedManifest['fingerprint'];
		}
		$checkedAt = (int)($storedManifest['checked_at'] ?? 0);
		if($storedRevision !== '' && $checkedAt > 0
			&& (time() - $checkedAt) < self::NODE_SOURCE_CHECK_TTL){
			if(function_exists('T')){
				T('candy_node_source_revision', $storedRevision);
			}
			return $storedRevision;
		}

		$sourceStats = self::buildControllerFileStats();
		$statFingerprint = self::fingerprintSourceData($sourceStats);
		$manifestUsable = $storedRevision !== ''
			&& ($storedManifest['stat_fingerprint'] ?? null) === $statFingerprint;

		if($manifestUsable){
			$revision = $storedRevision;
			$storedManifest['checked_at'] = time();
			if(function_exists('G')){
				G('nodeSourceManifest', $storedManifest, 0);
			}
		}else{
			$manifest = self::buildControllerFileManifest($sourceStats, $storedManifest);
			$manifest['checked_at'] = time();
			$revision = $manifest['fingerprint'];
			if(function_exists('G')){
				G('nodeSourceManifest', $manifest, 0);
			}
		}

		$sourceChanged = self::$lastNodeSourceRevision !== ''
			&& self::$lastNodeSourceRevision !== $revision;
		$sharedCacheChanged = $storedRevision !== $revision;
		self::$lastNodeSourceRevision = $revision;
		if(($sourceChanged || $sharedCacheChanged)
			&& class_exists(\Candy\Core\Node::class, false)){
			\Candy\Core\Node::clearNodeCache();
		}

		if(function_exists('T')){
			T('candy_node_source_revision', $revision);
		}
		return $revision;
	}

	/**
	 * 收集应用和插件控制器文件的轻量元数据。
	 *
	 * @return array<string, array{size:int,mtime:int}> 按相对路径排序的文件元数据
	 */
	private static function buildControllerFileStats(): array
	{
		$root = rtrim((string)C('CANDYROOT'), '/\\');
		$stats = [];
		foreach([$root . '/Application', $root . '/Addons'] as $sourceRoot){
			if(!is_dir($sourceRoot)){
				continue;
			}

			try{
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS)
				);
			}catch(\UnexpectedValueException $exception){
				continue;
			}

			foreach($iterator as $fileInfo){
				if(!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php'){
					continue;
				}
				$path = str_replace('\\', '/', $fileInfo->getPathname());
				if(stripos($path, '/Controls/') === false){
					continue;
				}
				$relative = ltrim(substr($path, strlen($root)), '/');
				$stats[$relative] = [
					'size' => (int)$fileInfo->getSize(),
					'mtime' => (int)$fileInfo->getMTime(),
				];
			}
		}

		ksort($stats, SORT_STRING);
		return $stats;
	}

	/**
	 * 根据文件元数据和旧清单生成文本 hash 清单。
	 *
	 * 大多数请求只比较 stat_fingerprint；仅新增或大小/修改时间变化的文件会重新读取文本。
	 *
	 * @param array<string, array{size:int,mtime:int}> $sourceStats 当前文件元数据
	 * @param array<string, mixed> $storedManifest 上一次持久化的清单
	 * @return array{version:string,stat_fingerprint:string,fingerprint:string,files:array<string,array{size:int,mtime:int,hash:string}>}
	 */
	private static function buildControllerFileManifest(array $sourceStats, array $storedManifest): array
	{
		$oldFiles = isset($storedManifest['files']) && is_array($storedManifest['files'])
			? $storedManifest['files']
			: [];
		$files = [];
		$root = rtrim((string)C('CANDYROOT'), '/\\');
		foreach($sourceStats as $relative => $stat){
			$oldFile = isset($oldFiles[$relative]) && is_array($oldFiles[$relative])
				? $oldFiles[$relative]
				: [];
			$hash = '';
			if((int)($oldFile['size'] ?? -1) === $stat['size']
				&& (int)($oldFile['mtime'] ?? -1) === $stat['mtime']
				&& is_string($oldFile['hash'] ?? null)
				&& $oldFile['hash'] !== ''){
				$hash = $oldFile['hash'];
			}

			if($hash === ''){
				$path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
				$hash = is_file($path)
					? (hash_file('sha256', $path) ?: '')
					: '';
				if($hash === ''){
					$hash = hash('sha256', $relative . ':' . $stat['size'] . ':' . $stat['mtime']);
				}
			}

			$files[$relative] = [
				'size' => $stat['size'],
				'mtime' => $stat['mtime'],
				'hash' => $hash,
			];
		}
		ksort($files, SORT_STRING);

		return [
			'version' => self::NODE_SCAN_VERSION,
			'stat_fingerprint' => self::fingerprintSourceData($sourceStats),
			'fingerprint' => self::fingerprintSourceData([
				'version' => self::NODE_SCAN_VERSION,
				'files' => array_map(static function(array $file): string {
					return $file['hash'];
				}, $files),
			]),
			'files' => $files,
		];
	}

	/**
	 * 对规范化源码清单计算 hash。
	 *
	 * @param array<string, mixed> $data 待计算的数据
	 * @return string SHA-256 指纹
	 */
	private static function fingerprintSourceData(array $data): string
	{
		return hash(
			'sha256',
			(string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}
	/**
     * 获取反射类中的所有注释信息，包括类和方法的注解、标题、权限等
     *
     * 该方法用于解析控制器类的反射对象，提取类和公共方法的注释信息，
     * 支持PHP 8 Attributes和传统文档注释两种方式，优先使用Attributes。
     *
     * @param string $class 完整的类名（包含命名空间）
     * @param \ReflectionClass $reflect 类的反射对象
     * @return array 包含方法注释信息的数组，键为方法名，值为方法的详细信息数组
	 * 结构示例：
     * [
     *     'methodName' => [
     *         'Title' => '方法标题',
     *         'Auth' => true|false, // 是否需要权限
     *         'Menu' => true|false, // 是否显示在菜单
     *         'Log' => true|false,  // 是否记录日志
     *         'Desc' => '方法描述',
     *         'Group' => '分组名'   // 控制器分组（如果存在）
     *     ]
     * ]
     */
    public static function getAnnotationInfo(string $class,\ReflectionClass $reflect): array
    {
		if(\Candy\Core\Node::isNoAuthController($reflect)){
			return self::buildNoAuthMethodList($class, $reflect);
		}

		// 策略（2026-11-19）：DEBUG 不缓存（改注解立即生效），生产走进程内缓存。
		$annotationKey = ltrim($class, '\\');
		if(\Candy\Core\Node::useNodeCache() && isset(self::$annotationInfoCache[$annotationKey])){
			return self::$annotationInfoCache[$annotationKey];
		}

		$methodList = [];
		
		//函数列表
		foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			//只提取当前控制器的操作
			if($method->class == ltrim($class, "\\")){
				//私有方法跳过
				if($method->name[0] == '_') continue;
				//解析注释
				$methodData = self::parseMethodAnnotation($reflect, $method);
				$methodList[$method->name]  = $methodData;
				
			}
		}
		
		if(\Candy\Core\Node::useNodeCache()){
			self::$annotationInfoCache[$annotationKey] = $methodList;
		}

		return $methodList;
	}

	/**
	 * 为免 RBAC 控制器生成方法节点
	 *
	 * 仍解析 Title / Menu / Icon / Log 等展示与审计字段，但 Auth 恒为 false（不进权限分配树）
	 *
	 * @param string $class 完整类名
	 * @param \ReflectionClass $reflect 控制器反射类
	 * @return array<string, array<string, mixed>>
	 */
	private static function buildNoAuthMethodList(string $class, \ReflectionClass $reflect): array
	{
		$methodList = [];

		foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if($method->class !== ltrim($class, '\\')){
				continue;
			}
			if(str_starts_with($method->name, '_')){
				continue;
			}
			$methodList[$method->name] = self::stubNoAuthMethodNode($reflect, $method);
		}

		return $methodList;
	}

	/**
	 * 免 RBAC 控制器的方法节点（Auth 恒为 false，可带侧栏菜单）
	 *
	 * @param \ReflectionClass $reflect 控制器反射类
	 * @param \ReflectionMethod $method 方法反射
	 * @return array<string, mixed>
	 */
	private static function stubNoAuthMethodNode(\ReflectionClass $reflect, \ReflectionMethod $method): array
	{
		// 解析注解以支持 menu/title/icon；整类免 RBAC 时强制 Auth=false
		$data = self::parseMethodAnnotation($reflect, $method);
		$data['Auth'] = false;
		if ($data['Title'] === '') {
			$data['Title'] = ucfirst($method->name);
		}
		// 方法注解可覆盖 classGroup（如 group: 'Content'）
		if ($reflect->hasConstant('classGroup') && !isset($data['Group'])) {
			$data['Group'] = $reflect->getConstant('classGroup');
		}

		return $data;
	}

	/**
	 * 判断方法是否声明了 NotParse 标记。
	 *
	 * NotParse 只抑制菜单节点，不丢弃同一方法上的 Auth、Title 等权限元数据。
	 *
	 * @param \ReflectionMethod $method 待解析的方法反射对象
	 * @return bool 是否声明了 NotParse
	 */
	private static function hasNotParseMarker(\ReflectionMethod $method): bool
	{
		foreach ($method->getAttributes() as $attribute) {
			$name = ltrim($attribute->getName(), '\\');
			if ($name === 'NotParse'
				|| $name === 'Candy\\Extend\\Attribute\\Annotations\\NotParse'
				|| str_ends_with($name, '\\NotParse')
			) {
				return true;
			}
		}

		$comment = $method->getDocComment();
		return is_string($comment)
			&& preg_match('/@NotParse(?:\s|\(|\*|$)/i', $comment) === 1;
	}

	/**
	 * 解析函数标注
	 */
	public static function parseMethodAnnotation(\ReflectionClass $reflect, \ReflectionMethod $method): array
	{
		//默认权限
		$methodData = [
			'Title'  => '',
			'Auth'  => false,
			'Menu'  => false,
			'Log'  => false,
			'Desc'  => ''
		];
		
		//函数注解检查
		$methodReflection = $method->getAttributes();
		
		if (count($methodReflection) < 1) {
			//获取注释
			$comment = $method->getDocComment() ?? '';
			//分割注释 以换行符做标准
			if(empty($comment) === false){
				$lines = explode(PHP_EOL, trim($comment, "/* \n\r\t"));
				foreach($lines as $line){
					if(stripos($line, '@') === false){
						//第一次匹配的作为title
						empty($methodData['Title']) && $methodData['Title'] = preg_replace("/\s/", '', $line);
					}else{
						//检查是否是类处理
						preg_match('|@(.*?)\((.*?)\)|iU', trim($line), $matches);
						if(count($matches) > 0){
							//类库处理
							$className = $matches[1];
							if($className === 'NotParse'){
								return [];
							}elseif($className === 'NotDebug'){
								debug(0);
								return [];
							}else{
								$alias_name = $className;
								$className = (strpos($className, '\\') === false ? '\\Candy\\Extend\\Attribute\\Annotations\\' : '') . $className;
								//优先内置库
								$args = explode(',', $matches[2]);
								$args = self::AnnotationParams($args);
								if(class_exists($className)){
									$methodData[strtolower($matches[1])] = (new $className(...$args))->data();
									class_alias($className, $alias_name);
								}else{
									//项目内扩展注解类
									$className = str_replace('\\Candy\\Extend\\Attribute\\Annotations', '\\APP\\Extend\\Annotations', $className);
									if(class_exists($className)){
										$methodData[strtolower($matches[1])] = (new $className(...$args))->data();
										class_alias($className, $alias_name);
									}else{
										Debug::showMessage('注释引用类库['. $className  .']不存在请检查！', 'Annotation');
									}
								}
							}
						}else{
							//匹配处理项目
							preg_match('|@(.*?) (.*?)|iU', preg_replace('/\s+/', ' ', trim($line)), $matches);
							if(count($matches) > 0){
								//检查是否是需要函数处理
								preg_match('|(.*?)\((.*?)\)|iU', $matches[2], $ms);
								if(count($ms) > 0){
									//函数处理
									$funcName = $ms[1];
									if(function_exists($funcName)){
										$args = explode(',', $ms[2]);
										$args = self::AnnotationParams($args);
										$methodData[strtolower($matches[1])] = $funcName(...$args);
									}else{
										Debug::showMessage('注释引用函数['. $funcName  .']不存在请检查！', 'Annotation');
									}
								}else{
									if(ucfirst($matches[1]) == 'Describe'){
										$methodData['Desc'] = $matches[2];
										continue;
									}
									//字符串形式 auth menu log这三项必须是布尔型
									$methodData[ucfirst($matches[1])] = in_array(ucfirst($matches[1]),['Auth','Menu','Log']) ? $matches[2] === 'true' : (stripos($matches[2], ',') === false ? $matches[2] : explode(',', $matches[2]));
								}
							}
						}
					}
				}
			}
			// 分组：未在注解声明 Group 时回退控制器 classGroup
			if ($reflect->hasConstant('classGroup') && !isset($methodData['Group'])) {
				$methodData['Group'] = $reflect->getConstant('classGroup');
			}
		}else{
			$methodData = [];
			foreach ($methodReflection as $attribute) {
				$className = $attribute->getName();
				// Apidoc 文档注解（ApiContract / Title / Query 等）不参与 Node 权限合并
				if (\Candy\Extend\Attribute\utils\ApidocAttribute::isApidocClass($className)) {
					continue;
				}
				$frameworkAnnotation = self::resolveFrameworkAnnotationClass($className);
				if ($frameworkAnnotation !== null) {
					$className = $frameworkAnnotation;
				} elseif(class_exists($className)){
					$data = $attribute->newInstance()->get();
					if (is_array($data)) {
						$methodData = array_merge($methodData, $data);
					}
					continue;
				} else {
					$alias_name = $className;
					$className = str_replace('Annotations', '\\Candy\\Extend\\Attribute\\Annotations', $className);
					
					//优先框架内的注解类
					if(class_exists($className)){
						class_alias($className, $alias_name);
						$data = $attribute->newInstance()->get();
						if (is_array($data)) {
							$methodData = array_merge($methodData, $data);
						}
						continue;
					}
					
					$alias_name = $className;
					$className = str_replace('\\Candy\\Extend\\Attribute\\Annotations', '\\APP\\Extend\\Annotations', $className);
					if(class_exists($className)){
						class_alias($className, $alias_name);
						$data = $attribute->newInstance()->get();
						if (is_array($data)) {
							$methodData = array_merge($methodData, $data);
						}
					}else{
						Debug::showMessage('注释引用类库['. $className  .']不存在请检查！', 'Annotation');
					}
					continue;
				}
				$data = $attribute->newInstance()->get();
				if (is_array($data)) {
					$methodData = array_merge($methodData, $data);
				}
			}
			// 分组：方法 #[Annotation(group:)] 优先，否则用 classGroup
			if ($reflect->hasConstant('classGroup') && !isset($methodData['Group'])) {
				$methodData['Group'] = $reflect->getConstant('classGroup');
			}
		}

		if (self::hasNotParseMarker($method) && ($methodData['Menu'] ?? false) === true) {
			$methodData['Menu'] = false;
		}
			
		//补充一下名称
		if(is_string($methodData) === false && empty($methodData['Title'])){
			$methodData['Title'] = ucfirst($method->name) . '操作';
		}

		return $methodData;
	}
	
	/**
     * 获取控制器类列表及其方法节点信息
     *
     * 该方法扫描指定应用的控制器目录，获取所有控制器类的信息，包括类名称、方法列表等。
     * 支持缓存机制；DEBUG 模式通过源码指纹保证变更后自动重扫，生产模式复用持久化缓存。
     *
     * @param string|null $appName 应用名称，默认为当前应用
     * @param string|null $type 控制器类型，可选参数
     * @return array 控制器类信息数组
	 * 结构示例：
     * [
     *     'ControllerName' => [
     *         'Name' => '控制器标题',
     *         'Actions' => [
     *             'methodName' => [
     *                 'Title' => '方法标题',
     *                 'Auth' => true|false,
     *                 'Menu' => true|false,
     *                 'Log' => true|false,
     *                 'Desc' => '方法描述'
     *             ]
     *         ]
     *     ]
     * ]
     */
    public static function getClassList(?string $appName = null, $type = null): array
    {
		//指定默认appName
		empty($appName) && $appName = C('APPNAME');
		$useCache = \Candy\Core\Node::useNodeCache();
        
		// DEBUG 模式也复用进程内缓存，源码指纹变化时由 Node 统一清除。
		if($useCache && isset(self::$classListCache[$appName])) {
			$cached = self::$classListCache[$appName];
			return is_array($cached) ? $cached : [];
		}
		
		//读取 G() 持久化缓存
		if($useCache){
			$nodes = G('nodeMethodList');
			if(is_array($nodes) && array_key_exists($appName, $nodes)){
				$list = $nodes[$appName];
				// 历史脏数据可能写入 false（无控制器应用），必须规范成空数组
				if (!is_array($list)) {
					$list = [];
				}
				self::$classListCache[$appName] = $list;
				return $list;
			}
		}
		
		//获取当前应用所有反射对象
        $objCacheList = self::eachController($appName, $type);
		
		if(count($objCacheList) > 0){
			//循环处理
			foreach($objCacheList as $className=>$reflection){
				// 操作名：仅去掉运行时类名末尾 Action 后缀（禁止全局 str_replace，否则 ActionTplAction → Tpl）
				$shortName = $reflection->getShortName();
				$action = str_ends_with($shortName, 'Action')
					? substr($shortName, 0, -strlen('Action'))
					: $shortName;

				//检查分类
				$classGroup = '';
				if($reflection->hasConstant('classGroup'))
					$classGroup = $reflection->getConstant('classGroup');

				//标题（优先级：Attribute > 旧格式注释 > 默认值）
				$title = '';
				if(empty($classGroup) || $classGroup == $action){
					// 1. 优先读取 PHP 8 Attribute #[Annotation(name: 'xxx')]
					$classAttributes = $reflection->getAttributes();
					foreach ($classAttributes as $attr) {
						$attrName = $attr->getName();
						if($attrName === 'Annotation' || stripos($attrName, 'Annotation') !== false){
							$args = $attr->getArguments();
							if(isset($args['name'])){
								$title = $args['name'];
								break;
							}elseif(isset($args['title'])){
								$title = $args['title'];
								break;
							}
						}
					}

					// 2. 旧格式 @Name 注释
					if(empty($title)){
						$titleStr = $reflection->getDocComment();
						if($titleStr){
							preg_match('/@name\s+([^\r\n\*]+)/i', $titleStr, $matches);
							$title = trim($matches[1] ?? '');
						}
						// 注释中未找到，尝试读取文件内容
						if(empty($title)){
							$file = $reflection->getFileName();
							preg_match('/@name\s+([^\r\n\*]+)/i', \Candy\Core\CipherFile::getInstance()->fileGetContents($file), $matches);
							$title = trim($matches[1] ?? '');
						}
					}

					// 3. 默认值
					if(empty($title))
						$title = $action.'控制器';
				}
				
				// Home 的运行时 Action 缓存历史上可能残留旧 Menu=false，
				// 所以只在 **DEBUG** 下按当前源码强制重算（保证改注解立即生效）；
				// 生产环境统一走 getMethodList() 的缓存（进程内静态 + G()/共享缓存）。
				$methodData = (isDebug() && $appName === 'Home')
					? self::getAnnotationInfo($className, $reflection)
					: self::getMethodList($appName, $className);
				$ctrlInfo = [
					'Name' => $title,
					'Actions' => $methodData,
				];
				if ($reflection->hasConstant('classGroup')) {
					$ctrlInfo['Group'] = $reflection->getConstant('classGroup');
				}
				if ($reflection->hasConstant('classGroupOrder')) {
					$ctrlInfo['GroupOrder'] = (int)$reflection->getConstant('classGroupOrder');
				}
				// 组内排序回退：方法注解 order 未声明时使用 classOrder
				if ($reflection->hasConstant('classOrder')) {
					$ctrlInfo['Order'] = (int)$reflection->getConstant('classOrder');
				}
				self::$classListCache[$appName][$action] = $ctrlInfo;
			}
		}else{
			// 无控制器时写空数组，禁止写 false（否则 G 缓存回读会触发返回类型错误）
			self::$classListCache[$appName] = [];
		}
		
		// DEBUG 和生产环境都写入 G()，源码指纹负责保证缓存与源码版本一致。
		if($useCache)
			G('nodeMethodList', self::$classListCache, 0);
		
        $result = self::$classListCache[$appName] ?? [];
        return is_array($result) ? $result : [];
    }
	
	/**
	 * 获取控制器类的方法节点列表
	 * 
	 * 该方法用于获取指定控制器类的所有方法节点信息，优先从缓存中获取，
	 * 如果缓存中不存在，则通过反射和注释解析获取，并更新缓存。
	 * 
	 * @param string $appName 应用名称
	 * @param string $control 控制器类名（包含命名空间）
	 * @return array 方法节点列表数组，键为方法名，值为方法的详细信息
	 * 结构示例：
	 * [
	 *     'methodName' => [
	 *         'Title' => '方法标题',
	 *         'Auth' => true|false, // 是否需要权限
	 *         'Menu' => true|false, // 是否显示在菜单
	 *         'Log' => true|false,  // 是否记录日志
	 *         'Desc' => '方法描述'
	 *     ]
	 * ]
	 */
	private static function getMethodList(string $appName,string $control): array
	{
		$methods = \Candy\Core\Node::getMethodsData();
		if(\Candy\Core\Node::useNodeCache() && !empty($methods[$control])){
			return $methods[$control];
		}
		if(stripos($control, 'APP\\') !== false){
			$objCacheList = self::eachController($appName);
			if(isset($objCacheList[$control])){
				$methods[$control] = self::getAnnotationInfo($control, $objCacheList[$control]);
				\Candy\Core\Node::setMethodsData(array_merge(\Candy\Core\Node::getMethodsData(), $methods));
			}
		}
		return $methods[$control] ?? [];
	}
	
	/**
	 * 扫描插件控制器
	 *
	 * 该方法用于扫描指定应用下的所有插件控制器，目前为空实现，可在后续版本中扩展。
	 * 主要用于支持插件系统的控制器识别和处理。
	 *
	 * @param string $appName 应用名称
	 * @return array 插件控制器列表
	 */
	private static function eachAddonController(string $appName): array
	{
		//p(Hook::getEnableHooks(1));
		return [];
	}
	
	/**
	 * 扫描控制器文件并创建反射对象
	 *
	 * 该方法扫描指定应用下的控制器目录，识别所有控制器文件，
	 * 排除Common控制器，为每个控制器创建反射对象，并按指定顺序排序。
	 * 支持缓存机制，避免重复扫描和反射操作。
	 *
	 * @param string|null $appName 应用名称，默认为null
	 * @param string|null $type 控制器类型，可选参数
	 * @return array 控制器反射对象列表，键为类名，值为反射对象
	 */
	private static function eachController(?string $appName = null,?string $type = null): array
	{
		if(\Candy\Core\Node::useNodeCache() && isset(self::$objCacheList[$appName])) return self::$objCacheList[$appName];
		
		//获取APP目录
		$appPath = getAppPath($appName, $type);
		
		if($appPath){
			//APP自有控制器
			$ctrs = \Candy\Extend\Dir::getFiles($appPath.'Controls/', '*.php');
			
			//插件类控制器
			$pctrs = self::eachAddonController($appName);
			
			//合并控制器组
			$ctrs = array_merge($ctrs, $pctrs);
			
			$objCacheList = [];
			$i = 0;
			
			foreach ($ctrs as $file){
				if (!preg_match("|/(\w+)/(\w+)/Controls/(.+)\.class.php$|", $file, $matches)) continue;
				
				//获取APP目录除Common控制器
				if($matches[3] != 'Common'){
					if($matches[1] == 'Application'){
						$className = self::classCacheFile($matches[2], $matches[3]);
					}else{
						$className = self::classCacheFile($matches[2], $matches[3], 'addon');
					}
					
					$refClass = \Candy\Core\Container::getInstance()->invokeReflectionClass($className);
					// 整控制器免 RBAC：仍纳入 classList（侧栏 menu 可展示），Auth 由 getAnnotationInfo 强制为 false
					//排序
					$order = $refClass->hasConstant('classOrder') ? $refClass->getConstant('classOrder') : $i;
					//防止覆盖
					while(isset($objCacheList[$order])){
						$order++;
					};
					$objCacheList[$order] = [$className, $refClass];
					$i++;
				}
			}
			// 按 classOrder 升序写入（越小越靠前）；组内最终序以 Node 显式排序为准
			if ($objCacheList !== []) {
				ksort($objCacheList, SORT_NUMERIC);
				foreach ($objCacheList as $cache) {
					[$class, $ref] = $cache;
					self::$objCacheList[$appName][$class] = $ref;
				}
			}
		}
		return self::$objCacheList[$appName] ?: [];
	}
	
	/**
     * 生成控制器类的完整类名
     *
     * 该方法根据应用名称、控制器名称和类型，生成符合命名空间规范的完整类名。
     * 自动添加Action后缀（除Common和CommonExtend控制器外）。
     *
     * @param string $appName 应用名称
     * @param string $className 控制器名称
     * @param string $type 控制器类型，可选值：'app'（默认）或 'addon'
     * @return string 完整的类名（包含命名空间）
     */
	private static function classCacheFile(string $appName,string $className,string $type = 'app'): string
	{
		//命名空间
		$nameSpace = (($type == 'app') ? 'APP\\Control\\' : 'APP\\Addon\\') . $appName . '\\';
		$className = $nameSpace . $className . (in_array($className, ['Common', 'CommonExtend']) ? '' : 'Action');
		
		return $className;
	}


	/**
	 * 权限/菜单 #[Annotation] 解析为规范类并注册全局桥接
	 */
	private static function resolveFrameworkAnnotationClass(string $attributeName): ?string
	{
		$canonical = \Candy\Extend\Attribute\Annotations\Annotation::class;
		$isFramework = $attributeName === 'Annotation'
			|| $attributeName === '\\Annotation'
			|| $attributeName === $canonical
			|| preg_match('/^APP\\\\(?:Control|Addon)\\\\[a-zA-Z_][a-zA-Z0-9_]*\\\\Annotation$/', $attributeName) === 1;

		if (!$isFramework) {
			return null;
		}

		if (!class_exists($canonical)) {
			requireCache(CANDYPATH . 'Candy/Class/Vendor/Annotation.class.php');
		}
		if (!class_exists('Annotation', false)) {
			class_alias($canonical, 'Annotation');
		}
		if ($attributeName !== $canonical && !class_exists($attributeName, false)) {
			class_alias($canonical, $attributeName);
		}

		return $canonical;
	}

	/**
	 * 处理注解参数
	 */
	private static function AnnotationParams(array $args): array
	{
		//目前只处理字符串、布尔值  其他类型暂不处理
		foreach ($args as $key => $val) {
			$val = trim($val);
			//如果开头和结尾是 " 那么时字符
			if($val[0] == '"' && $val[strlen($val)-1] == '"'){
				$val = substr($val, 1, -1);
			} else {
				//如果是数字，转换为整数
				if($var = 'true' || $var = 'false'){
					$val = $var == 'true' ? true : false;
				}
			}
			$args[$key] = $val;
		}
		return $args;
	}
}
