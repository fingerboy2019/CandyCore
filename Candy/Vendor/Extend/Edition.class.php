<?php
/**
 * Edition - 框架版本控制与代码安全管理类
 * 
 * 该类提供了Candy框架的版本管理、打包压缩和代码加密等核心功能，主要职责包括：
 * - 构建框架版本并记录文件变更
 * - 生成框架的压缩包（ZIP格式）
 * - 提供多段式代码加密保护机制
 * - 管理受保护的文件和目录
 * 
 * 核心功能：
 * - 版本构建：扫描并记录框架文件状态，生成版本快照
 * - 版本打包：将框架文件打包为ZIP格式，支持基本版本和完整版本
 * - 代码加密：对指定PHP文件进行多段式加密，支持单文件、目录和模块级加密
 * 
 * 使用场景：
 * - 框架升级和版本管理
 * - 应用部署前的代码保护
 * - 商业代码的版权保护
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-04-23 12:59:37
 * @final 本类为最终实现禁止继承
 */

//语法严格模式开启
declare(strict_types=1);

namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Edition {
    /**
     * 受保护的文件列表
     * 
     * 这些文件在版本构建、打包和加密过程中将被排除，主要包括配置文件和敏感文件。
     * 保护这些文件可以防止敏感信息泄露和配置被意外修改。
     * 
     * @var array 包含文件名或文件路径的数组
     */
    private static $protectFiles = [
			CANDYPATH . 'Candy/Vendor/Extend/Edition.class.php',
		];
    
    /**
     * 受保护的目录列表
     * 
     * 这些目录在版本构建、打包和加密过程中将被排除，主要包括Workerman相关组件目录。
     * 保护这些目录可以确保第三方库不被修改，保持框架的稳定性和兼容性。
     * 
     * @var array 包含目录名或目录路径的数组
     */
	private static $protectDirs = [
			CANDYPATH . 'Candy/Vendor/{Apidoc,Catlog,Check,Firebase,QL,Revolt}',
			CANDYPATH . 'Candy/Vendor/Workerman/{Channel,Coroutine,Crontab,Dns,FileMonitor,Ftp,GatewayWorker,Mqtt,PHPSocketIO,Proxy,Socket,Stomp,Webdav}',
			CANDYPATH . 'Runtime/{Cache,Comps,Controls,Data,Logs,Models,Tmps}',
		];
	
	/**
     * 构建框架版本并记录文件状态信息
     * 
     * 该方法扫描框架目录中的PHP文件，生成文件状态快照，记录文件的路径、大小、修改时间等信息。
     * 生成的版本信息被保存在系统配置中，可用于后续的版本比对、文件变更检测等操作。
     * 
     * 执行流程：
     * 1. 获取框架根目录路径
     * 2. 扫描框架目录下的所有PHP文件
     * 3. 过滤受保护的文件和目录
     * 4. 收集每个文件的状态信息（路径、大小、修改时间、内容哈希值等）
     * 5. 将版本信息保存到系统配置中
     * 
     * @return void
     */
	public static function build(): void
	{
		$versionList = F('versionlist');
		$versionInfo = F('build' . CANDY_VERSION);
		if(!$versionInfo){
			//基本配置
			$config = [
				[
					CANDYPATH . 'Candy/',
					CANDYPATH . 'Doc/'
				],
				[
					'files'=>self::$protectFiles,
					'dirs'=>self::$protectDirs
				],
				true
			];
			//获取所有文件列表
			$files = \Candy\Extend\Dir::getFiles($config);
			
			//存在老版本
			if($versionList){
				$last = end($versionList);
				foreach($files as $key=>$info){
					if($info['time'] < $last['buildDate']){
						unset($files[$key]);
					}
				}
			}
			$versionList[] = ['version'=>CANDY_VERSION, 'buildDate'=>time()];
			F('build' . CANDY_VERSION, $files, 0);
			F('versionlist', $versionList, 0);
			if(isWebServer()){
				echo 'Candy' . CANDY_VERSION .'版本已创建成功。';
			}else{
				showExceptionMsg('halt', 'Candy' . CANDY_VERSION .'版本已创建成功。', '版本创建成功');
			}
		}else{
			if(isWebServer()){
				echo 'Candy' . CANDY_VERSION .'版本已存在无需重建。';
			}else{
				showExceptionMsg('halt', 'Candy' . CANDY_VERSION .'版本已存在无需重建。');
			}
		}
	}
	
	/**
     * 打包框架版本为ZIP压缩文件
     * 
     * 该方法将框架文件打包为ZIP格式，支持生成不同类型的版本包，包括基本版本和完整版本。
     * 打包过程中会过滤受保护的文件和目录，确保敏感信息不被包含在打包文件中。
     * 
     * 压缩流程：
     * 1. 根据传入参数数组确定打包内容范围
     * 2. 创建临时目录和ZIP文件
     * 3. 扫描并过滤需要打包的文件
     * 4. 将符合条件的文件添加到ZIP压缩包中
     * 5. 生成压缩文件并输出相关信息
     * 
     * @param array $argv 参数数组，第一个元素表示打包类型：'base'表示基本版，'full'表示完整版
     * @return void
     */
	public static function zip(array $argv = []): void
	{
		$type = empty($argv) ? 'base' : array_shift($argv);
		//压缩文件名
		$fileName = 'Candy' . CANDY_VERSION . '_' . date('YmdHis', time()) . '.zip';
		//压缩地址
		$pathName = CANDYPATH . 'Public/Version/' . $fileName;
		//基本配置
		$proctect = $type == 'base' ? ['files'=>self::$protectFiles, 'dirs'=>self::$protectDirs] : [];
		$config = [CANDYPATH . 'Candy/', $proctect, true];
		//获取所有文件列表
		$files = F('build' . CANDY_VERSION);
		if(!$files){
			if(isWebServer()){
				echo 'Candy' . CANDY_VERSION .'版本不存在，请先创建。';
			}else{
				showExceptionMsg('halt', 'Candy' . CANDY_VERSION .'版本不存在，请先创建。');
			}
		}else{
			//添加上文件
			$files[] = ['path' => CANDYPATH . 'favicon.ico'];
			$files[] = ['path' => CANDYPATH . 'index.php'];
			$files[] = ['path' => CANDYPATH . 'candy'];
			$files[] = ['path' => CANDYPATH . 'Readme.md'];
			//压缩
			makeZip($pathName, $files, CANDYPATH);
			if(isWebServer()){
				echo basename($fileName) . '创建成功。';
			}else{
				showExceptionMsg('halt', basename($fileName) . '创建成功。', '版本打包成功');
			}
		}
		
	}
	
	/**
     * 对PHP代码进行多段式加密处理
     * 
     * 该方法提供了PHP代码的加密保护功能，支持对单个文件、整个目录或指定模块进行加密。
     * 采用多段式加密机制，提高了代码的安全性和反破解能力。加密后的代码仍然可以正常执行。
     * 
     * 加密流程：
     * 1. 解析输入参数，确定加密目标和范围
     * 2. 遍历需要加密的PHP文件
     * 3. 读取文件内容并进行预处理
     * 4. 使用saveCode方法进行多段式加密处理
     * 5. 输出加密结果信息
     * 
     * @param array $argv 参数数组，包含以下内容：
     *                    - 第一个元素：加密类型（'file'表示文件，'dir'表示目录，'model'表示模块）
     *                    - 第二个元素：加密目标路径或模块名
     * @return void
     * @throws Exception 当加密失败或参数无效时抛出异常
     */
	public static function encrypt(array $argv = []): void
	{
		if(empty($argv)){
			if(isWebServer()){
				echo '请提供需要加密的文件路径。';
			}else{
				showExceptionMsg('halt', '请提供需要加密的文件路径。');
			}
			exit;
		}else{
			$path = array_shift($argv);
			$key =  empty($argv) ? 'candy' : array_shift($argv);
			$format =  empty($argv) ? '//point' : array_shift($argv);
			if(strpos($path, '@') === false){
				$file = C('CANDYROOT') . $path;
				if(file_exists($file)){
					//是文件
					self::saveCode($file, $key, $format);
				}if(is_dir($file) || is_dir(C('CANDYROOT') . 'Application/' . rtrim($path, '/') . '/')){
					//是目录
					$dir = is_dir($file) ? $file : C('CANDYROOT') . 'Application/' . rtrim($path, '/') . '/Controls/';
					$classList = glob($dir .'*.php');
					if(count($classList))
						foreach($classList as $file){
							self::saveCode($file, $key, $format);
						}
				}else{
					if(isWebServer()){
						echo '需要加密的文件不存在。';
					}else{
						showExceptionMsg('halt', '需要加密的文件不存在。');
					}
					exit;
				}
			}else{
				//控制器或Model
				$paths = explode('@', $path);
				$files = [];
				//具体的文件
				if(count($paths) > 2){
					[$type, $app, $name] = $paths;
					$file = C('CANDYROOT') . 'Application/' . $app . '/' . $type . '/' . $name . '.class.php';
					if(is_file($file))
						$files[] = $file;
				}else{
					[$app, $type] = $paths;
					//先按目录检查
					$dir = C('CANDYROOT') . 'Application/' . $app . '/' . $type . '/';
					if(is_dir($dir)){
						$files = glob($dir .'*.php');
					}else{
						$file = C('CANDYROOT') . 'Application/' . $app . '/Controls/' . $type . '.class.php';
						if(is_file($file))
							$files[] = $file;
						
						$file = C('CANDYROOT') . 'Application/' . $app . '/Models/' . $type . '.class.php';
						
						if(is_file($file))
							$files[] = $file;
					}
					
				}
				
				if(count($files) > 0){
					foreach($files as $file){
						self::saveCode($file, $key, $format);
					}
				}else{
					if(isWebServer()){
						echo '需要加密的文件不存在。';
					}else{
						showExceptionMsg('halt', '需要加密的文件不存在。');
					}
					exit;
				}
			}
			
			if(isWebServer()){
				echo '加密成功。';
			}else{
				showExceptionMsg('halt', '加密成功。');
			}
		}
	}
	
    /**
     * 代码转存与多段式加密处理
     * 
     * 该方法是代码加密的核心实现，负责对PHP文件内容进行读取、解析、分段和加密处理。
     * 采用特殊的多段式加密机制，通过提取注释、分段处理和多层加密，提高代码的安全性。
     * 加密过程中会自动备份原始文件，确保数据安全。
     * 
     * 执行流程：
     * 1. 读取PHP文件原始内容
     * 2. 检查文件是否已被加密（避免重复加密）
     * 3. 提取文件头部的文档注释
     * 4. 根据指定格式分割代码内容
     * 5. 获取多段式加密密钥（通过getAuthCode方法）
     * 6. 对代码进行分段加密处理
     * 7. 将加密后的内容与原始注释合并
     * 8. 备份原始文件并保存加密后的内容
     * 
     * @param string $file 文件路径，指定要加密的PHP文件
     * @param string $key 加密密钥，用于生成多段式加密的子密钥
     * @param string $format 代码分段格式，用于确定代码分割点
     * @return void
     */
	private static function saveCode(string $file,string $key,string $format): void
	{
		static $code = [];
		
		//原始数据
		$raw = file_get_contents($file);
		if(stristr($raw, '-----BEGIN CIPHERTEXT-----') === false){
			//取出注释
			preg_match('/(\/\*{3})([\s\S]*)(\*\/)/U', $raw, $matches);
			$search = empty($matches) ? '<?php' : ['<?php', $matches[0]];
			$content = str_replace($search,'', $raw);
			$contentList = explode($format, $content);
			$content = str_replace($format, '', $content);
			$count = count($contentList) - 1;
			
			//分段
			if($count > 1 || $count == 0){
				//备份
				file_put_contents($file.'.bak', $raw);
				$s = 0;
				//获取分段式秘钥
				count($code) > 0 OR $code = self::getAuthCode($key);
				
				//分段加密
				if($count > 1){
					$s = array_pop($code);
					$first = array_shift($contentList);
					$end = array_pop($contentList);
					$mk = 0;
					while(!empty($contentList)){
						$mstr = array_shift($contentList);
						$nstr = \Candy\Extend\Mcrypt::code($mstr, $code['key'][$mk]);
						$content = str_replace($mstr, '//CIPHER'.$nstr."\n", $content);
						$mk < 4 && $mk++;
					}
					
				}
				$content = "/**\n-----BEGIN CIPHERTEXT-----\n" . \Candy\Extend\Mcrypt::code($content, 'localhost') . $s ."\n-----END CIPHERTEXT-----\n*/";
				
				//插入校验码
				//CRC32();
				
				//保存密文
				file_put_contents($file, "<?php\n" . (empty($matches) ? '' : $matches[0]) . "\n" . $content);
			}
		}
	}
	
    /**
     * 生成多段式加密认证码和密钥
     * 
     * 该方法是代码加密系统的关键组件，负责生成用于多段式加密的认证码和子密钥。
     * 支持基于域名、IP地址和时间的绑定功能，可以实现加密代码的授权控制。
     * 认证码生成后会被缓存，提高重复调用的效率。
     * 
     * 功能特点：
     * - 支持域名绑定：通过H标记绑定特定域名
     * - 支持IP地址绑定：通过I标记绑定特定IP地址
     * - 支持时间限制：通过T标记设置加密代码的有效期
     * - 生成多组子密钥：用于代码的分段加密
     * 
     * @param string $key 主密钥字符串，支持特殊标记：
     *                    - #@H:domain 绑定域名
     *                    - #@I:ip 绑定IP地址
     *                    - #@T:timestamp 设置有效期
     * @return array 包含认证码状态和多组加密子密钥的关联数组
     */
	private static function getAuthCode(string $key){
		$authcode = readInc('AuthCode');
		if(!empty($authcode)) return $authcode;
		$rand = '0123456789ABCDEFGHIGKLMNOPQRSTUVWXYZ';
		
		//绑定域名和IP
		if(strpos($key, '#@')){
			$host = $ip = '';
			$time = 0;
			$supplement = explode('#@', $key);
			$key = array_shift($supplement);
			$r = $rand[rand(1,15)];
			$s = hexdec('0x'.$r);
			$auth_st = $auth_key = $r . '000';
			$time = dechex((int)substr($sss, 1));
			while(count($supplement) > 0){
				$sss = array_shift($supplement);
				$head = substr($sss, 0, 1);
				[$auth_key, $auth_st] = match($head){
					'H' => [
						substr_replace($auth_key, substr(md5(substr($sss, 1)), $s, 1), 1, 1),
						substr_replace($auth_st, 'X', 1, 1)
					],
					'I' => [
						substr_replace($auth_key, substr(md5(substr($sss, 1)), $s*2, 1), 2, 1),
						substr_replace($auth_st, 'X', 2, 1)
					],
					'T' => [
						substr_replace($auth_key, $time, 3, 1),
						substr_replace($auth_st, $time, 3, 1)
					],
					default => [$auth_key, $auth_st],
				};
			}
			$auth_st = '-' . strtoupper($auth_st);
			$auth_key = strtoupper($auth_key);
		}
		
		//生成密钥组
		$code = str_split(md5($key), 8);
		$s = ord($code[0])%4;
		for($n=0;$n<4;$n++){
			$format = '................';
			for($i=0;$i<4;$i++){
				$t = substr($code[$i], 0, $n+1);
				$format = substr_replace($format, $t, $s+$i*4, 0);
			}
			$start = '';
			for($i=0;$i<4;$i++){
				if($format[$i+16] > 0){
					$start .= $format[$i+16];
				}else{
					$start .= $format[$i];
				}
			}
			$auth = strtoupper(implode('-',str_split(substr(substr_replace($format, $start, 0, 4), 0, 16), 4)));
			$tmpcode = str_replace(['-','.'], '', $auth);
			
			//补齐
			while(strpos($auth, '.') !== false){
				$auth = substr_replace($auth, $rand[rand(0,35)], strpos($auth, '.'), 1);
			}
			$authcode['code'][] = $auth . ($auth_st ??= '');
			$authcode['key'][] = $tmpcode . ($auth_key ??= '');
		}
		
		//识别码
		$authcode[] = $s;
		editInc('AuthCode', $authcode);
		return $authcode;
	}
}