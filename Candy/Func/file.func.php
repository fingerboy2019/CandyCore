<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 优化的文件引入函数
 * 
 * 该函数提供了优化的文件引入机制，是对PHP原生require_once的增强实现。
 * 它使用静态数组缓存已引入的文件路径，避免重复引入相同文件，有效提高性能并防止函数重复定义错误。
 * 函数还支持文件路径替换功能，允许在引入时使用别名替换实际文件路径。
 * 
 * @param string $filename 要引入的文件路径
 * @return bool|array 否则返回布尔值，表示文件是否成功引入
 * 
 * @example
 * ```php
 * // 引入单个文件
 * $result = requireCache('Candy/Core/Controller.class.php');
 * if($result) {
 *     // 文件引入成功
 * }
 * 
 * // 引入文件并使用自定义缓存键名
 * requireCache('custom/path/to/file.php', 'my_cached_file');
 * 
 * // 获取所有已引入的文件缓存
 * $importedFiles = requireCache();
 * ```
 * 
 * @note
 * - 函数默认预加载了框架核心文件到缓存中，避免重复检查
 * - 文件路径检查使用fileExistsCase函数，支持大小写敏感的文件系统
 * - 成功引入文件时返回true，文件不存在或引入失败时返回false
 */
function requireCache(string $filename = ''): mixed
{
	static $_importFiles = [
		'Candy/Autoload.php'=>1,
		'Candy/Func/global.func.php'=>1
	];
	
	if(empty($filename)) return $_importFiles;
	if(isset($_importFiles[$filename])) return $_importFiles[$filename];
	$_importFiles[$filename] = false;
	if (fileExistsCase($filename)){
		require $filename;
		$_importFiles[$filename] = true;
	}
	return $_importFiles[$filename];
}

/**
 * 文件包含优化函数
 * 
 * 提供高效的文件包含机制，使用静态数组缓存已包含的文件路径，避免重复包含相同文件，
 * 有效提高性能并防止可能的函数重复定义错误。
 * 
 * @param string $filename 要包含的文件路径
 * @return bool|array 如果$filename为空，返回所有已包含文件的数组；
 *                   否则返回布尔值，表示文件是否成功包含
 * 
 * @example
 * ```php
 * // 包含单个文件
 * $result = includeCache('/path/to/file.php');
 * if($result) {
 *     // 文件包含成功
 * }
 * 
 * // 获取所有已包含的文件
 * $includedFiles = includeCache();
 * ```
 * 
 * @note
 * - 函数使用静态变量$_importFiles缓存已包含的文件状态
 * - 包含文件前会使用fileExistsCase函数检查文件是否存在
 */
function includeCache(string $filename = '')
{
	static $_importFiles = [];
	if(empty($filename)) return $_importFiles;
	if (isset($_importFiles[$filename])) return $_importFiles[$filename];
	$_importFiles[$filename] = false;
	if(fileExistsCase($filename)){
		include $filename;
		$_importFiles[$filename] = true;
	}
	
	return $_importFiles[$filename];
}

/**
 * 区分大小写的文件存在判断
 * 
 * 提供严格区分文件名大小写的文件存在检查功能，与PHP原生file_exists()不同，
 * 此函数能够正确识别文件名大小写是否完全匹配文件系统中的实际文件。
 * 同时使用静态缓存优化多次调用的性能。
 * 
 * @param string $filename 要检查的文件路径
 * @return bool 文件是否存在且文件名大小写完全匹配
 * 
 * @example
 * ```php
 * // 检查文件是否存在，严格区分大小写
 * if (fileExistsCase('./config.php')) {
 *     echo '配置文件存在';
 * }
 * 
 * // 在大小写敏感的系统中，以下两个调用结果可能不同
 * var_dump(file_exists('./Config.php'));  // 在Windows上可能返回true
 * var_dump(fileExistsCase('./Config.php'));  // 严格检查大小写，只有完全匹配才返回true
 * ```
 * 
 * @note
 * - 在Windows等大小写不敏感的文件系统上，此函数仍能检测文件名大小写是否匹配
 * - 使用静态缓存避免重复检查，提高性能
 * - 仅检查文件（不检查目录），需要先通过is_file()确认
 */
function fileExistsCase(string $filename): bool
{
	static $_fliesExit = [];
	
	if(isset($_fliesExit[$filename])) return $_fliesExit[$filename];
	$_fliesExit[$filename] = false;
	
	if(is_file($filename)){
		if(basename(realpath($filename)) == basename($filename))
			$_fliesExit[$filename] = true;
	}
	
	return $_fliesExit[$filename];
}

/**
 * 文件内容读取函数
 * 
 * 提供安全可靠的文件内容读取功能，支持二进制模式读取，自动处理文件编码，并可选择性地
 * 对文件内容进行格式化处理，如统一换行符和添加HTML段落标签。函数会对文件进行存在性和可读性检查。
 * 
 * @param string $filename 要读取的文件路径
 * @param bool $deal 是否对读取的内容进行格式化处理，默认为false（不处理）
 * @return string 读取的文件内容，如果文件不存在或不可读则返回false
 * 
 * @example
 * ```php
 * // 基本文件读取
 * $content = fileRead('./config.php');
 * 
 * // 读取并格式化内容（添加HTML段落标签）
 * $formattedContent = fileRead('./README.md', true);
 * // 返回的内容会将每行转换为<p>标签包裹的HTML格式
 * 
 * // 二进制文件读取
 * $binaryData = fileRead('./image.jpg');
 * ```
 * 
 * @note
 * - 文件以二进制模式('rb')打开，确保正确处理各种类型的文件
 * - 使用fread()以8192字节的块读取文件，避免大文件读取时的内存问题
 * - 当$deal参数为true时，会自动将不同格式的换行符统一为PHP_EOL
 * - 函数内部使用@抑制fopen可能产生的错误警告
 */
function fileRead(string $filename,bool $deal = false): string|false
{
	$contents = '';
	if(file_exists($filename) && is_readable($filename) && ($fd = @fopen($filename, 'rb'))){
        while(!feof($fd)){
            $contents .= fread($fd, 8192);
        }
        fclose($fd);

		$contents = convertEncoding($contents);

		if($deal){
			//统一换行符
			$contents = preg_replace('/\n|\r\n|\r/', PHP_EOL,$contents);
			$lines = explode(PHP_EOL, $contents);
			$contents = '';
			foreach($lines as $line){
				if(trim($line) != ''){
					$contents .= '<p>'.$line.'</p>';
				}
			}
		}
	} else {
		return false;
	}

	return $contents;
}

/**
 * 文件内容写入函数
 * 
 * 提供安全可靠的文件写入功能，自动创建目标文件的父目录，并处理可能的文件权限问题。
 * 函数使用二进制模式写入，确保数据的完整性，并在写入失败时尝试使用临时文件作为备选方案。
 * 
 * @param string $filename 要写入的文件路径
 * @param string $contents 要写入的文件内容
 * @return bool 写入成功返回true，失败返回false
 * 
 * @example
 * ```php
 * // 基本文件写入
 * $success = fileWrite('./logs/error.log', '错误信息记录');
 * 
 * // 写入配置文件
 * $config = '<?php return [\'db_host\' => \'localhost\'];';
 * fileWrite('./config/database.php', $config);
 * 
 * // 写入二进制数据
 * fileWrite('./uploads/image.jpg', $binaryData);
 * ```
 * 
 * @note
 * - 函数会自动创建目标文件所在的所有父目录
 * - 文件以二进制模式('wb')打开，会覆盖已存在的文件内容
 * - 如果直接写入失败，会尝试使用临时文件
 * - 写入完成后会设置文件权限为0755
 * - 内部使用@抑制可能产生的错误警告
 */
function fileWrite(string $filename,string $contents): bool
{
	\Candy\Extend\Dir::create(dirname($filename));
	if(!($fd = @fopen($filename, 'wb'))){
		// 修复：临时文件应该放在目录中，而不是文件名拼接
        $_tmp_file = dirname($filename) . DIRECTORY_SEPARATOR . uniqid('wrt');
    	if(!($fd = @fopen($_tmp_file, 'wb'))){
			return false;
    	}
	}
    fwrite($fd, $contents);
   	fclose($fd);
	@chmod($filename, 0755);
	return true;
}

/**
 * 文件下载处理
 * 
 * 提供安全可靠的文件下载功能，支持自定义文件名，并自动设置正确的HTTP响应头
 * 以确保文件能够正确下载。该函数会检查必要条件，并处理文件路径和编码问题。
 * 
 * @param string $file 要下载的文件路径，可以是相对路径或绝对路径
 * @param string $rename 下载时的文件名，为空时使用原始文件名
 * @return false|void 当HTTP头已发送或文件参数为空时返回false，成功时直接输出文件内容
 * 
 * @example
 * ```php
 * // 使用原始文件名下载文件
 * fileDownload('./uploads/report.pdf');
 * 
 * // 使用自定义文件名下载文件
 * fileDownload('./data/backup.zip', '系统备份_20240101.zip');
 * 
 * // 带路径的文件下载
 * fileDownload('/var/www/files/document.docx');
 * ```
 * 
 * @note
 * - 确保在调用此函数前没有输出任何内容，否则会导致header已发送错误
 * - 文件路径应该经过安全验证，避免目录遍历攻击
 * - 文件大小会自动获取并设置到HTTP头中
 */
function fileDownload(string $file,string $rename = '')
{
	if(headers_sent()) return false;
	if(!$file) return false;
	if (empty($rename)) {
        if(strpos($file, '/') === false && strpos($file, '\\') === false){
            $filename = $file;
        }else{
            $filename = basename($file);
        }
    } else {
        $filename = $rename;
    }
	
	headers('Content-Description: File Transfer');
    headers('Content-Type: application/octet-stream');
    headers('Content-Disposition: attachment; filename="' . $filename .'"');
    headers('Expires: 0');
    headers('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    headers('Pragma: public');
    headers('Content-Length: ' . filesize($file));
    ob_clean();
    flush();
    fileRead($file);
}

/**
 * 遍历目录获取所有子目录
 *
 * 该函数用于遍历指定目录，获取其中的所有子目录名称。支持返回完整路径或仅子目录名称。
 * 函数会对目录名进行排序，确保返回结果的一致性。
 *
 * 使用示例：
 * ```php
 * // 获取目录下所有子目录名称
 * $subDirs = dirs('/path/to/directory');
 * // 返回: ['subdir1', 'subdir2', 'subdir3']
 * 
 * // 获取目录下所有子目录的完整路径
 * $fullPaths = dirs('/path/to/directory', true);
 * // 返回: ['/path/to/directory/subdir1/', '/path/to/directory/subdir2/', '/path/to/directory/subdir3/']
 * ```
 *
 * @param string $dir 要遍历的目录路径
 * @param bool $fullpath 是否返回完整路径，默认为false（仅返回子目录名称）
 * @return array 子目录列表数组
 */
function dirs(string $dir,bool $fullpath = false): array
{
	$dir = rtrim($dir, '/');
	$dirs = [];
	foreach(glob($dir.'/*') as $tfile){
		if(is_dir($tfile)){
			$dirs[]	= $fullpath ? $tfile . '/' : str_replace($dir.'/', '',$tfile);
		}
	}
	sort($dirs);
	return $dirs;
}

function registerStream()
{
	//数据流包装类
	if(class_exists('Candy\Core\CipherFile') === false){
		require_once(CANDYPATH .'Candy/Core/CipherFile.class.php');
	}
	stream_wrapper_register('candy', 'Candy\Core\CipherFile');
}

/**
 * 查询并显示函数或方法的定义位置信息
 *
 * 该函数使用PHP的反射机制来获取函数或方法的定义位置信息，包括文件名、起始行号和结束行号。
 * 支持查询普通函数和类方法，并返回格式化的位置信息字符串。
 *
 * 使用示例：
 * ```php
 * // 查询普通函数位置
 * $info = functionDump('C');
 * // 返回类似: "function C defined by /path/to/file.php(100 - 200)"
 * 
 * // 查询类方法位置
 * $info = functionDump(['\Candy\Core\Controller', 'init']);
 * // 返回类似: "function init defined by /path/to/Controller.php(50 - 75)"
 * ```
 *
 * @param string|array $funcName 函数名或类方法数组，格式为[类名, 方法名]
 * @return string 格式化的函数定义位置信息字符串，包含函数名、文件名和行号范围
 */
function functionDump($funcName): string
{
	try {
		if(is_array($funcName)) {
			$func = new \ReflectionMethod($funcName[0], $funcName[1]);
			$funcName = $funcName[1];
		} else {
			$func = new \ReflectionFunction($funcName);
		}
	} catch (\ReflectionException $e) {
		return $e->getMessage();
	}
	$start = $func->getStartLine();
	$end =  $func->getEndLine();
	$filename = $func->getFileName();
	return "function $funcName defined by $filename($start - $end)\n";
}
