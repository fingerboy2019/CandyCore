<?php
/**
 * Candy框架 应用构建类
 * 
 * 系统项目生成器核心类，负责创建项目结构、应用程序、扩展插件等系统组件
 * 提供自动化的目录创建、文件生成、项目初始化功能
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-27 10:00:00
 * @final 该类设计为最终类，不允许继承
 * 
 * @note 此类是框架自动初始化和应用创建的核心组件，通常不需要手动调用
 * 除非在特殊情况下需要创建新的应用或扩展
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Build {
	/**
	 * 提示消息数组，用于记录构建过程中的各种操作信息
	 * 
	 * @var array
	 * @access private
	 * @static
	 */
	private static $mess = [];
	
	/**
	 * 创建或写入文件
	 * 
	 * 该方法用于在指定路径创建文件并写入内容，如果文件已存在则跳过操作
	 * 自动创建文件所在的目录结构，并设置适当的文件权限
	 * 
	 * @param string $fileName 需要创建的文件完整路径
	 * @param string $str 需要写入文件的内容字符串
	 * @return void
	 * @access public
	 * @static
	 */
	public static function touch(string $fileName, string $str): void
	{
		if (!fileExistsCase($fileName)) {
			$dir = dirname($fileName);
			if (!is_dir($dir)) {
				self::mkdir([$dir]);
			}
			
			if (file_put_contents($fileName, $str) !== false) {
				self::$mess[] = "创建文件 {$fileName} 成功.";
				@chmod($fileName, 0644); // 设置适当文件权限
			} else {
				self::$mess[] = "创建文件 {$fileName} 失败!";
			}
		}
	}
	
	/**
	 * 创建目录结构
	 * 
	 * 该方法用于批量创建目录，确保每个指定的目录都存在
	 * 自动为创建的目录设置适当的访问权限（0755）
	 * 如果目录已存在则跳过操作
	 * 
	 * @param array $dirs 要创建的目录路径数组
	 * @return void
	 * @access private
	 * @static
	 */
	private static function mkdir(array $dirs): void
	{
		foreach ($dirs as $dir) {
			if (is_dir($dir) === false && \Candy\Extend\Dir::create($dir) != false) {
				self::$mess[] = "创建目录 {$dir} 成功.";
				@chmod($dir, 0755); // 确保目录权限正确
			}
		}
	}
	
	/**
	 * 创建系统运行时的目录和文件
	 * 
	 * 该方法负责创建框架运行所必需的临时文件和缓存目录
	 * 包括缓存目录、模板编译目录、控制器缓存、模型缓存等
	 * 根据当前环境（普通应用或插件应用）创建不同的目录结构
	 * 
	 * @return void
	 * @access private
	 * @static
	 * @note 此方法由框架内部调用，通常不需要手动执行
	 */
	private static function runtime(): void
	{
		//传参
		$appName = C('APPNAME');
		$tmpPath = C('TMPPATH');
		
		$dirs = [
				C('RUNTIME'),   //系统的缓存目录
				C('RUNTIME').'Cache/',   //系统的缓存目录
				C('RUNTIME').'Comps/',   //模板的组合文件
				C('RUNTIME').'Data/cache',    //文件缓存数据
				C('RUNTIME').'Controls/',
				C('RUNTIME').'Models/',
				C('RUNTIME').'Services/',
				C('RUNTIME').'Validates/',
				C('RUNTIME').'Schemas/',
				C('RUNTIME').'Logs/',
				C('RUNTIME').'Tmps/'
			];
		
		if(is_null(C('ISADDON'))){
			$dirs[] = C('RUNTIME').'Cache/'. $tmpPath;
			$dirs[] = C('RUNTIME').'Comps/'. $tmpPath .'/'. C('TPLSTYLE');
			$dirs[] = C('RUNTIME').'Controls/'. $tmpPath;
			$dirs[] = C('RUNTIME').'Models/'. $tmpPath;
			$dirs[] = C('RUNTIME').'Services/'. $tmpPath;
			$dirs[] = C('RUNTIME').'Validates/'. $tmpPath;
			$dirs[] = C('RUNTIME').'Schemas/'. $tmpPath;
		}else{
			$dirs[] = C('RUNTIME').'Cache/Addons/'. $appName;
			$dirs[] = C('RUNTIME').'Comps/Addons/'. $appName;
			$dirs[] = C('RUNTIME').'Controls/Addons/'. $appName;
			$dirs[] = C('RUNTIME').'Models/Addons/'. $appName;
			$dirs[] = C('RUNTIME').'Services/Addons/'. $appName;
			$dirs[] = C('RUNTIME').'Validates/Addons/'. $appName;
			$dirs[] = C('RUNTIME').'Schemas/Addons/'. $appName;
		}
		
		self::mkdir($dirs);   //创建目录	
	}
		
	/**
	 * 开始创建项目或初始化系统
	 * 
	 * 该方法是框架初始化的核心入口，负责创建整个项目的目录结构、配置文件和基础类文件
	 * 执行流程：
	 * 1. 检查初始化锁文件，避免重复初始化
	 * 2. 处理MD相关功能
	 * 3. 创建运行时目录
	 * 4. 根据请求参数创建不同类型的应用（主应用、扩展或插件）
	 * 5. 生成各种配置文件和基础类文件
	 * 6. 记录初始化日志
	 * 
	 * @return void
	 * @access public
	 * @static
	 * @note 通常通过URL参数start触发，或在框架首次运行时自动调用
	 */
	public static function start(): void
	{
		$get = I('get.', [], '');
		//文件锁，一旦生成，就不再创建
		$structFile = C('RUNTIME').'init.lock';  //初始化锁

		// 🟢 缓存清理后的锁重建机制
		// 如果锁文件不存在，但默认项目(Home)已创建，说明是手动清理了Runtime缓存
		// 此时只需重建锁文件，跳过后续的项目初始化流程，避免覆盖现有配置或触发重复安装
		$homePath = C('CANDYROOT') . 'Application/Home/';
		if (!file_exists($structFile) && is_dir($homePath)) {
			self::touch($structFile, '缓存已清理，系统锁于 ' . date('Y-m-d H:i:s') . ' 重新生成。');
			// 设置初始化状态，防止后续逻辑误判为未初始化
			C('APP_INIT_STATUS', true);
		}

		//MD相关功能：未创建项目时的文档预览，或 Doc/index.php 文档中心入口
		if (
			(is_null(C('APP_INIT_STATUS')) && isset($get['start']) === false)
			|| C('MARKDOWNREAD') === true
		) {
			Markdown::start();
			stop();
		} else {
			//初始化runtime目录
			if(!is_dir(C('RUNTIME')))
				self::runtime();

			//传参
			$appPath = C('APP_PATH');
			$appName = C('APPNAME');
			$tplPrefix = G('TPLPREFIX');
			$app = C('APP');
			
			//自动创建APP目录 初次创建除外
			if((isset($get['add']) && (!is_dir($appPath) || ((isset($get['name']) || in_array($get['add'], ['', 'addon', 'plug'])))) && is_null(C('ISADDON'))) || (is_null(C('APP_INIT_STATUS')) && $appName == 'Home' && isset($get['start']))){
				//创建项目或扩展
				if(is_null(C('APP_INIT_STATUS'))){
					//单框架多项目
					if(defined('ALLINONE')){
						//创建框架配置文件
						self::touch(CANDYPATH.'Config/Env.php', str_replace('DIR', C('CANDYROOT'),base64_decode('PD9waHAKLy/pobnnm67phY3nva7kv6Hmga8KCnJldHVybiBbCgknd2VicycJPT4gWwoJCSdkZWZhdWx0JyA9PiBbCgkJCSdwYXRoJyA9PidESVInCgkJXQoJXQpdOwo=')));
					}
					
					//配置初始化
					$fileName = C('INCPATH').'Config.inc.php';
					if(!fileExistsCase($fileName)){
						//添加配置模板
						self::touch($fileName, base64_decode('PD9waHAKLyoqCiAqIENhbmR5IEZyYW1ld29yayDmoLjlv4PphY3nva7mlofku7YKICovCnJldHVybiBbCgknQ0hBUlNFVCcJCT0+ICd1dGY4bWI0JywKCSdEUklWRVInCQk9PiAncGRvJywKCSdEQicJCQk9PiBbCgkJJ2RlZmF1bHQnID0+IFsKCQkJJ0hPU1QnID0+ICcnLAoJCQknVVNFUicgPT4gJycsCgkJCSdQQVNTJyA9PiAnJywKCQkJJ0RCTkFNRScgPT4gJycsCgkJCSdUQUJQUkVGSVgnID0+ICcnLAoJCV0sCgldLAoJLy8g5Ya757uT6ZSuIENTVEFSVO+8mumhtemdoue8k+WtmO+8iOmdmeaAgeWMlu+8ieW8gOWFswoJJ0NTVEFSVCcJCT0+IGZhbHNlLAoJJ0NUSU1FJwkJCT0+IDM2MDAsCgknQ1RZUEUnCQkJPT4gMCwKCS8vIOWGu+e7k+mUriBIQUxMTUFSS++8muWNg+S6uuWNg+mdouagh+iusO+8jOWmgiBtb2JpbGUKCS8vICdIQUxMTUFSSScgPT4gbnVsbCwKCS8vJ0NBQ0hFJwkJCT0+IFsKCS8vCSdkZWZhdWx0JwkJPT4gJ3JlZGlzJywKCS8vCSdvcHRpb25zJwkJPT4gWwoJLy8JCSdzZXJ2ZXJzJz0+WwoJLy8JCQlbJ2hvc3QnID0+ICdsb2NhbGhvc3QnLCAncG9ydCcgPT4gNjM3OSwgJ3Bhc3N3b3JkJyA9PiAnJ10sCgkvLwkJXSwKCS8vCV0sCgkvL10sCl07'));
					}
					
					// __BUILD_ENDPOINT__ 框架端点配置（默认全部不开启）
					$__epFiles = [
						'Endpoint' => 'PD9waHAKLyoqCiAqIOahhuaetuerr+eCuemFjee9ru+8iOmhueebrueUn+aIkO+8iQogKgogKiDpu5jorqTlhajpg6jkuI3lvIDlkK/vvJrlnKggZW5hYmxlIOmHjOWKoOi3r+W+hOWNs+WPr+WQr+eUqOOAggogKiDnq6/ngrnot6/lvoTlv4XpobvkuKTmrrXvvIjlpoIgc3lzL2hlYWx0aO+8ie+8jOS4lCBjYWxsYmFjayDlv4XpobvmmK8gQ2xhc3M6Om1ldGhvZCDlrZfnrKbkuLLjgIIKICoKICogQGF1dGhvciDmoYbmnrboh6rliqjnlJ/miJAKICovCmRlZmluZWQoJ0NBTkRZJykgT1IgZGllKCdZb3UgQXJlIEEgQmFkIEd1eS4gb19PPz8/Jyk7CgpyZXR1cm4gWwogICAgLy8g5ZCv55So55qE56uv54K577yI56m65pWw57uEID0g5YWo6YOo5LiN5byA5ZCv77yJCiAgICAvLyDlhoXnva7lj6/pgInvvJonc3lzL2hlYWx0aCfvvIhEQi/no4Hnm5jlgaXlurfmo4Dmn6XvvInjgIEnc3lzL3Bpbmcn77yI5pyA5bCP5a2Y5rS75o6i5rWL77yJCiAgICAnZW5hYmxlJyA9PiBbXSwKCiAgICAvLyDorr/pl67ku6TniYzvvJrpnZ7nqbrml7bmiYDmnInnq6/ngrnpg73opoHmsYIgP3Rva2VuPXh4eAogICAgLy8g5oiW6K+35rGC5aS0IEF1dGhvcml6YXRpb246IEJlYXJlciB4eHjvvJvnlZnnqbogPSDkuI3moKHpqowKICAgICd0b2tlbicgID0+ICcnLAogICAgJ3Rva2VucycgPT4gWwogICAgICAgIC8vICdzeXMvaGVhbHRoJyA9PiAn5Y2V54us57uZ5YGl5bq35qOA5p+l55qE5Luk54mMJywKICAgIF0sCgogICAgLy8g5bqU55So6Ieq5a6a5LmJ56uv54K577yI6ZSuID0g6Lev5b6E77yM5YC8ID0gW+exu+WQjSwg5pa55rOV5ZCNXe+8iQogICAgJ2VuZHBvaW50cycgPT4gWwogICAgICAgIC8vICdteS90YXNrJyA9PiBbXEFQUFxFbmRwb2ludFxNeVRhc2s6OmNsYXNzLCAncnVuJ10sCiAgICBdLApdOw==',
						'Health'   => 'PD9waHAKLyoqCiAqIOWBpeW6t+ajgOafpemFjee9ru+8iOmhueebrueUn+aIkO+8iQogKgogKiDku4XlnKggRW5kcG9pbnQuaW5jLnBocCDlkK/nlKggc3lzL2hlYWx0aCDml7bnlJ/mlYjjgIIKICoKICogQGF1dGhvciDmoYbmnrboh6rliqjnlJ/miJAKICovCmRlZmluZWQoJ0NBTkRZJykgT1IgZGllKCdZb3UgQXJlIEEgQmFkIEd1eS4gb19PPz8/Jyk7CgpyZXR1cm4gWwogICAgLy8g5ZCv55So55qE5qOA5p+l6aG577yaZGLjgIFkaXNr44CBbWVtb3J5CiAgICAnY2hlY2tzJyA9PiBbJ2RiJywgJ2Rpc2snXSwKCiAgICAvLyDno4Hnm5jliankvZnnqbrpl7TkvY7kuo7mraTlgLzvvIhHQu+8ieWIpOWumuS4uuS4jeWBpeW6twogICAgJ2Rpc2tfbWluX2diJyA9PiAxLAoKICAgIC8vIOWGheWtmOWzsOWAvOmrmOS6juatpOWAvO+8iE1C77yJ5Yik5a6a5Li65LiN5YGl5bq377ybMCA9IOS4jeajgOafpQogICAgJ21lbW9yeV9tYXhfbWInID0+IDAsCl07',
					];
					foreach ($__epFiles as $__epName => $__epB64) {
						$__epFile = C('INCPATH') . $__epName . '.inc.php';
						if (!fileExistsCase($__epFile)) {
							$__epBody = base64_decode($__epB64);
							// __BUILD_ENDPOINT_TOKEN__ 端点令牌在初始化时生成，直接写进配置
							if ($__epName === 'Endpoint') {
								$__epBody = str_replace(
									"'token'  => '',",
									"'token'  => '" . bin2hex(random_bytes(24)) . "',",
									$__epBody
								);
							}
							self::touch($__epFile, $__epBody);
						}
					}

					//扩展目录初始化
					$dirs = [
						C('SOURCEPATH') . 'Vendor/',   	//项目引用类库目录
						C('SOURCEPATH') . 'Event/',   	//事件类库目录
						C('SOURCEPATH') . 'Class/',    //项目的通用类
						C('SOURCEPATH') . 'Middleware/',    //中间件类
						C('SOURCEPATH') . 'Func/',    //项目的通用函数库
						C('SOURCEPATH') . 'Common/',    //项目基类
						C('SOURCEPATH') . 'Server/',   	//服务自启脚本
						C('CANDYROOT') . 'Public/uploads/',  //系统公共上传文件目录
						C('CANDYROOT') . 'Public/css/',      //系统公css共目录
						C('CANDYROOT') . 'Public/js/',       //系统公共javascript目录
						C('CANDYROOT') . 'Public/images/',   //系统公共图片目录
						C('CANDYROOT') . 'Public/thumbs/',	//系统缩略图目录
					];
					self::mkdir($dirs);
					
					//复制demo示例
					\Candy\Extend\Dir::copyDir(CANDYPATH.'Candy/Tpl/demo/Hook', C('CANDYROOT').'Plugins/Demo/Hook');
					copy(CANDYPATH.'Candy/Tpl/demo/conf.php', C('CANDYROOT').'Plugins/Demo/conf.php');
					
					//添加公共404
					self::touch(C('CANDYROOT').'Public/404'. $tplPrefix, base64_decode('PCFET0NUWVBFIGh0bWw+PGh0bWw+PGhlYWQ+PG1ldGEgY2hhcnNldD0idWZ0LTgiPjx0aXRsZT40MDQvcGFnZSBub3QgZm91bmQuPC90aXRsZT48bWV0YSBodHRwLWVxdWl2PSJYLVVBLUNvbXBhdGlibGUiIGNvbnRlbnQ9IklFPWVkZ2UsY2hyb21lPTEiPjxtZXRhIG5hbWU9InZpZXdwb3J0IiBjb250ZW50PSJ3aWR0aD1kZXZpY2Utd2lkdGgsIGluaXRpYWwtc2NhbGU9MSwgbWF4aW11bS1zY2FsZT0xIj48bWV0YSBuYW1lPSJhcHBsZS1tb2JpbGUtd2ViLWFwcC1zdGF0dXMtYmFyLXN0eWxlIiBjb250ZW50PSJibGFjayI+PG1ldGEgbmFtZT0iYXBwbGUtbW9iaWxlLXdlYi1hcHAtY2FwYWJsZSIgY29udGVudD0ieWVzIj48c3R5bGU+aHRtbCwgYm9keSB7IG1hcmdpbjogMDsgZm9udC1mYW1pbHk6ICJBcmlhbCIsICJNaWNyb3NvZnQgWWFIZWkiLCAi6buR5L2TIiwgIuWui+S9kyIsIHNhbnMtc2VyaWY7IGZvbnQtc2l6ZTogMTRweDt9LmNvbnRlbnQgeyB3aWR0aDogMzVyZW07IG1hcmdpbjogMCBhdXRvOyBkaXNwbGF5OiBibG9jazsgbWFyZ2luLXRvcDogMTAwcHg7fS5jb250ZW50LWZvZiB7IHdpZHRoOiAxMDAlOyBmb250LXNpemU6IDEwcmVtOyBjb2xvcjogI0I0QkNDQzsgdGV4dC1hbGlnbjogY2VudGVyO30uY29udGVudC1kaXZpZGUgeyB3aWR0aDogMTAwJTsgbWFyZ2luLXRvcDogMTBweDsgYm9yZGVyLWJvdHRvbTogMXB4IHNvbGlkICNFREYyRkM7fS5jb250ZW50LW1zZyB7IHdpZHRoOiAxMDAlOyB0ZXh0LWFsaWduOiBjZW50ZXI7IGZvbnQtc2l6ZTogMS4zcmVtOyBjb2xvcjogI0Q4RENFNTsgbWFyZ2luLXRvcDogMjBweDsgZm9udC13ZWlnaHQ6IDMwMDt9PC9zdHlsZT48L2hlYWQ+PGJvZHk+PGRpdiBjbGFzcz0iY29udGVudCI+PGRpdiBjbGFzcz0iY29udGVudC1mb2YiPjQwNDwvZGl2PjxkaXYgY2xhc3M9ImNvbnRlbnQtZGl2aWRlIj48L2Rpdj48ZGl2IGNsYXNzPSJjb250ZW50LW1zZyI+cGFnZSBub3QgZm91bmQuPC9kaXY+PC9kaXY+PC9ib2R5PjwvaHRtbD4='));
					
					//配置信息
					$incInfo = base64_decode('PD9waHAKLyoqCiAq5omp5bGV6YWN572uCiAqKi8KcmV0dXJuIFsKCSdBRERPTl9VUERBVEUnID0+CTAsCQkJCQkvL+abtOaWsOaXtumXtAoJJ0FERE9OX0xJU1QnID0+IFsJCQkJLy/mj5Lku7booagKCQknRGVtbycgPT4gWwoJCQknZW5hYmxlJwkJID0+IDAsCgkJCSdpbnN0YWxsZWQnCQkgPT4gMCwKCQkJJ2FkZG9uaWQnCQkgPT4gMTAKCQldLAoJXSwKXTsK');
					
					//配置文件
					self::touch(C('INCPATH').'Plug.inc.php', str_replace('ADDON', 'PLUG', $incInfo));
					
					if(is_null($app)){
						$addondirs = [
							C('CANDYROOT') . 'Addons/Demo/',
							C('RUNTIME') . 'Cache/Addons/',   //系统的缓存目录
							C('RUNTIME') . 'Comps/Addons/',   //模板的组合文件
							C('RUNTIME') . 'Controls/Addons/', 
							C('RUNTIME') . 'Models/Addons/',
							C('RUNTIME') . 'Services/Addons/'
						];
						self::mkdir($addondirs);
						self::touch(C('INCPATH').'Addon.inc.php', $incInfo);
						
						//复制demo示例
						\Candy\Extend\Dir::copyDir(CANDYPATH.'Candy/Tpl/demo/Addon', C('CANDYROOT').'Addons/Demo/');
					}
					
					//全局路由
					if(is_null($app))
						self::touch(C('INCPATH') . 'Route.inc.php', base64_decode('PD9waHAKLyoqCiAqIOe9keermei3r+eUsemFjee9rgogKiovCnJldHVybiBbCgknVVJMX01PREVMJwkJCQk9PgkyLAkJCQkJLy8gVVJM6K6/6Zeu5qih5byPIDDlhbzlrrkgMee7n+S4gCAyUEFUSElORk8KCSdVUkxfUk9VVEVSX09OJyAgIAkJPT4gdHJ1ZSwJCQkJLy8g5byA5ZCv6Lev55SxCgkvLydVUkxfREVQUicgICAJCQk9PiAnLScsCQkJCQkvLyBVUkzot6/lvoTliIbpmpTnrKbvvIjlhrvnu5PplK4gVVJMX0RFUFLvvIzpu5jorqQv77yM5LiN5Y+v5Li6Lu+8iQoJJ1VSTF9IVE1MX1NVRkZJWCcJCT0+Jy5zaHRtbCcsCQkJCS8vIOS8qumdmeaAgeWQjue8gAoJJ1VSTF9NQVBfUlVMRVMnIAkJPT4gWwoJCSdzaG93L2luZGV4JyAgICAgICAgPT4gJ2luZGV4L2luZGV4JywKCV0sCgknVVJMX1JPVVRFX1JVTEVTJyAJCT0+IFsKCQknc2hvdy86aWQnICAgICAgICA9PiAnaW5kZXgvaW5kZXgnLAoJXSwKXTs='));
					
					//初始化文件
					self::touch(C('INCPATH').'Init.inc.php', base64_decode('PD9waHAKLy8g57O757uf5Yid5aeL5YyW5paH5Lu2CmRlZmluZWQoJ0NBTkRZQ01TJykgT1IgZGVmaW5lKCdDQU5EWUNNUycsIHRydWUpOwoKLy8g6L+Q6KGM5qih5byP77yI5byA5Y+RL+iwg+ivle+8ieeUseahhuaetiBTeXMg6YWN572u55qEIHJ1bnRpbWUg5YiG57uE5Yaz5a6a77yM5pys5paH5Lu25LiN5YaN5aOw5piO44CCCgovLyDoh6rlrprkuYnpu5jorqTot6/nlLHvvIhVcmxQYXJzZXIg6Kej5p6Q5YmN5YaZ5YWlIEfvvIkKLy9HKCdpbmRleEluaXRVcmxQYXJzZXInLCBbJ2FwcCc9PidBZG1pbicsJ2NvbnRyb2wnPT4nTG9naW4nLCdhY3Rpb24nPT4nY29kZSddKTs='));

					//基础配置文件
					self::touch(C('INCPATH').'System.inc.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8v6buY6K6k6aG555uu5qih5p2/6Lev5b6ECiR0cGxwYXRoID0gJ0hvbWVALi9UZW1wbGF0ZSc7CgovL+ezu+e7n+WfuuehgOmFjee9ruaWh+S7tgo='));
					
					//扩展函数库初始化
					self::touch(C('SOURCEPATH').'Func/global.func.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8vIOmhueebrue6p+mAmueUqOWHveaVsOWjsOaYjuWcqOatpOaWh+S7tgoKLyoqCiAqIOi3r+eUseWvueavlOWJjeWkhOeQhiBQQVRIX0lORk8KICogQHBhcmFtIHN0cmluZyAkcGF0aGluZm8KICovCmZ1bmN0aW9uIGJlZm9yZUNvbnRyYXN0Um91dGUoJHBhdGhpbmZvKSB7CglyZXR1cm4gJHBhdGhpbmZvOwp9CgovKioKICogSFRNTCDovpPlh7rliY3lpITnkIYKICogQHBhcmFtIHN0cmluZyAkY29udGVudAogKi8KZnVuY3Rpb24gYmVmb3JlSHRtbFNob3coJGNvbnRlbnQpIHsKCXJldHVybiAkY29udGVudDsKfQoKLyoqCiAqIFVybFBhcnNlciDot6/nlLHphY3nva7mianlsZXvvIjpkqnlrZAgc2V0VXJsUGFyc2VyQ29uZmln77yJCiAqIEByZXR1cm4gYXJyYXk8c3RyaW5nLCBtaXhlZD4KICovCmZ1bmN0aW9uIHNldFVybFBhcnNlckNvbmZpZygpOiBhcnJheSB7CglyZXR1cm4gW107Cn0='));
				
					//特殊路径类库
					self::touch(C('INCPATH').'Specialclass.inc.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8v54m55q6K57G75bqTCnJldHVybiBbXTs='));
				
					//特殊路径类库
					self::touch(C('INCPATH').'AttackCode.inc.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8vR0VU5ZKMUE9TVOmdnuazleWtl+espui/h+a7pOmFjee9ru+8iOmYsumdnuazleWtl+espuaUu+WHu++8iQpyZXR1cm4gWwoJLy9HRVTlgLzpnZ7ms5XlrZfnrKbov4fmu6QKCSdnZXQnICA9PiBbCgkJJ3NlbGVjdCAnLAoJCSdpbnNlcnQgJywKCQknXCcnLAoJCScvKicsCgkJJyonLAoJCScuLi8nLAoJCScuLlxcJywKCQkndW5pb24gJywKCQknaW50byAnLAoJCSdsb2FkX2ZpbGUoJywKCQknb3V0ZmlsZSAnLAoJCSc8c2NyaXB0JywKCV0sCgkvL1BPU1TlgLzpnZ7ms5XlrZfnrKbov4fmu6QKCSdwb3N0JyA9PiBbCgkJJzxzY3JpcHQnLAoJCSc8c3R5bGUnLAoJCSc8bWV0YScsCgldLApdOwo='));
					
					//命令行配置文件
					self::touch(C('INCPATH').'StandardCommand.inc.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8v5qGG5p625omp5bGV5ZG95LukCnJldHVybiBbCgkndGVzdCc9PmZ1bmN0aW9uICgkc3RyKXsKCQllY2hvICRzdHI7Cgl9Cl07'));
					
					//项目基类
					self::touch(C('SOURCEPATH').'Common/Base.class.php', base64_decode('PD9waHAKLyoqCiAqIOaJgOaciemhueebrueahOWfuuexuwogKiovCm5hbWVzcGFjZSBDYW5keVxBUFBcQ29tbW9uOwoKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCkNsYXNzIEJhc2UgZXh0ZW5kcyBcQ2FuZHlcQ29yZVxBY3Rpb24gewoJCn0K'));
					
					//创建项目或扩展
					self::bulidSpaces('Home');

					//初建会有类库加载不全的问题 用js强制刷新一次
					echo '<script>location.reload();</script>';exit;
				}else{
					//创建项目或扩展
					$name = $get['name'] ?? C('APPNAME');
					self::bulidSpaces($name, $get['add']);
					showExceptionMsg('halt', $name . '项目创建成功。');
				}
				
				if(is_null(C('APP_INIT_STATUS'))){
					$mkmsg = '';
					foreach(self::$mess as $mess){
						$mkmsg .= $mess . "\n\t";
					}
					self::touch($structFile, $mkmsg);
				}
			}
		}
	}
	
	/**
	 * 创建相应空间应用
	 * 
	 * 该方法用于创建不同类型的应用空间，包括主应用、插件和扩展
	 * 根据传入的类型参数，创建相应的目录结构和基础文件
	 * 支持三种类型：
	 * - 空字符串：创建主应用
	 * - 'addon'：创建扩展应用
	 * - 'plug'：创建插件
	 * 
	 * @param string $name 应用名称，将转换为首字母大写的格式
	 * @param string $type 应用类型，可选值：''(主应用)、'addon'(扩展)、'plug'(插件)
	 * @return bool 是否创建成功
	 * @access public
	 * @static
	 * @throws bool 当应用名称为空或应用已存在时，会输出错误信息并返回false
	 */
	public static function bulidSpaces($name, $type = ''): bool
	{
		switch($type)
		{
			case 'addon':
				include C('INCPATH') . 'System.inc.php';
				if(is_null(G('Addons'))){
					if(isWebServer()){
						echo '项目未开启Addon功能。';
					}else{
						showExceptionMsg('halt', '项目未开启Addon功能。');
					}
					return false;
				}
				
				$addonName = ucfirst(strtolower($name));
				if(empty($addonName)){
					if(isWebServer()){
						echo 'Addon的名称不能为空。';
					}else{
						showExceptionMsg('halt', 'Addon的名称不能为空。');
					}
					return false;
				}
				
				//防止重新创建
				if(is_dir(C('CANDYROOT') . 'Addons/'. $addonName .'/')){
					if(isWebServer()){
						echo '该扩展已存在。';
					}else{
						showExceptionMsg('halt', '该扩展已存在。');
					}
					return false;
				}
				
				$addondirs = [
					C('CANDYROOT') . 'Addons/'. $addonName .'/'
				];
				self::mkdir($addondirs);
				\Candy\Extend\Dir::copyDir(CANDYPATH.'Candy/Tpl/demo/Addon', C('CANDYROOT').'Addons/'. $addonName .'/');
				
				//替换命名空间
				file_put_contents(C('CANDYROOT').'Addons/'. $addonName .'/Controls/Common.class.php', str_replace('APP\\Addon\\Demo', 'APP\\Addon\\'.$addonName, file_get_contents(C('CANDYROOT').'Addons/'. $addonName .'/Controls/Common.class.php')));
				file_put_contents(C('CANDYROOT').'Addons/'. $addonName .'/Controls/Index.class.php', str_replace('APP\\Addon\\Demo', 'APP\\Addon\\'.$addonName, file_get_contents(C('CANDYROOT').'Addons/'. $addonName .'/Controls/Index.class.php')));
				break;
			case 'plug':
				$plugName = ucfirst(strtolower($name));
				if(empty($plugName)){
					if(isWebServer()){
						echo 'Plugin的名称不能为空。';
					}else{
						showExceptionMsg('halt', 'Plugin的名称不能为空。');
					}
					return false;
				}
				
				//防止重新创建
				if(is_dir(C('CANDYROOT') . 'Plugins/'. $plugName .'/')){
					if(isWebServer()){
						echo '该插件已存在。';
					}else{
						showExceptionMsg('halt', '该插件已存在。');
					}
					return false;
				}
				
				//插件目录
				$dirs = [
					C('CANDYROOT') . 'Plugins/'. $plugName .'/Controls/Home/',
					C('CANDYROOT') . 'Plugins/'. $plugName .'/Languages/Home/',
					C('CANDYROOT') . 'Plugins/'. $plugName .'/Models/Home/',
					C('CANDYROOT') . 'Plugins/'. $plugName .'/Services/Home/',
					C('CANDYROOT') . 'Plugins/'. $plugName .'/Views/Home/' . $tplStyle .'/Resource/css/',
					C('CANDYROOT') . 'Plugins/'. $plugName .'/Views/Home/' . $tplStyle .'/Resource/js/',
					C('CANDYROOT') . 'Plugins/'. $plugName .'/Views/Home/' . $tplStyle .'/Resource/images/',
				];
				self::mkdir($dirs);
				\Candy\Extend\Dir::copyDir(CANDYPATH.'Candy/Tpl/demo/Hook', C('CANDYROOT').'Plugins/'. $plugName .'/Hook'); 
				copy(CANDYPATH.'Candy/Tpl/demo/conf.php', C('CANDYROOT').'Plugins/'. $plugName .'/conf.php');
				break;
			default :
				$appName = ucfirst(strtolower($name));
				
				//补充定义
				if(is_null(G('SEPARATE')) && is_null(G('TPLPREFIX'))){
					//CLI模式进来的
					include C('INCPATH') . 'System.inc.php';
					
					//定义模板后缀
					isset($separate) OR $separate = '/';

					//定义模板后缀
					isset($tplPrefix) OR $tplPrefix = '.html';
				}else{
					$separate = G('SEPARATE');
					$tplPrefix = G('TPLPREFIX');
				}
				
				//强制Home模板配置
				$tplStyle = C('TPLSTYLE');
				if($appName == 'Home'){
					$tplStyle = 'default';
					C(['TPLSTYLE'=>$tplStyle,'APP_TPL_PATH'=>'Home@./Template']);
				}else{
					//默认模板目录
					is_null($tplStyle) && $tplStyle = 'default';
				}
				
				$appPath = C('CANDYROOT') . 'Application/'. $appName .'/';
				//is_null(C('LAPPNAME')) && C('LAPPNAME', $appName);
				
				//防止重新创建
				if(is_dir($appPath)){
					if(isWebServer()){
						echo '该项目已存在。';
					}else{
						showExceptionMsg('halt', '该项目已存在。');
					}
					return false;
				}
				
				//APP目录结构
				$appdirs = [
					$appPath,						//当前的应用目录
					$appPath . 'Models/',			//当前应用的模型目录
					$appPath . 'Services/',			//当前应用的服务目录
					$appPath . 'Validates/',			//当前应用的验证器目录
					$appPath . 'Schemas/',			//当前应用的响应 Schema 目录
					$appPath . 'Vendor/',			//当前应用的类库扩展目录
					$appPath . 'Controls/',			//当前应用的控制器目录
					$appPath . 'Languages/zh-cn/',	//当前应用的语言库目录
				];
				self::mkdir($appdirs);
				
				//扩展目录
				is_null(C('RAPPNAME')) && C('RAPPNAME', $appName);
				$plugdirs = [
					C('PLUGPATH') . C('RAPPNAME') . '/Models/',
					C('PLUGPATH') . C('RAPPNAME') . '/Services/',
					C('PLUGPATH') . C('RAPPNAME') . '/Validates/',
					C('PLUGPATH') . C('RAPPNAME') . '/Schemas/',
					C('PLUGPATH') . C('RAPPNAME') . '/Controls/',
					C('PLUGPATH') . C('RAPPNAME') . '/Languages/zh-cn/',
					C('PLUGPATH') . C('RAPPNAME') . '/Views/' . $tplStyle .'/Resource/css/',
					C('PLUGPATH') . C('RAPPNAME') . '/Views/' . $tplStyle .'/Resource/js/',
					C('PLUGPATH') . C('RAPPNAME') . '/Views/' . $tplStyle .'/Resource/images/',
				];
				self::mkdir($plugdirs);
				\Candy\Extend\Dir::copyDir(CANDYPATH.'Candy/Tpl/demo/Hook', C('CANDYROOT').'Plugins/'. C('RAPPNAME') .'/Hook');
				
				$path = $appPath . 'Views/';
				$success = $appPath .'Views/'. $tplStyle . '/Public'. $separate .'success' . $tplPrefix;
				//自定义模板路径
				if(!is_null(C('APP_TPL_PATH'))){
					[$app, $tpl_path] = explode('@', C('APP_TPL_PATH'));
					if($appName == $app){
						$path = str_replace('./', C('CANDYROOT'), rtrim($tpl_path,'/')) . '/';
						//基础模板
						$success = $path. $tplStyle . '/Public'. $separate .'success'. $tplPrefix;
					}
				}
				
				//模板目录
				$views = [
					$path. $tplStyle . '/Resource/css/',		//当前应用模板CSS目录
					$path. $tplStyle . '/Resource/js/',			//当前应用模板js目录
					$path. $tplStyle . '/Resource/images/'		//当前应用模板图标目录
				];
				
				if($separate == '/'){
					$views[] = $path. $tplStyle . '/Public/';
					$views[] = $path. $tplStyle . '/Index/';
				}
				
				//创建模板目录
				self::mkdir($views);		
				
				if($tplStyle == 'default'){
					$index = rtrim($path,'/') . '/'. $tplStyle . '/Index'. $separate .'index'. $tplPrefix;
					self::touch($index, str_replace('{APP}', $appName, base64_decode('PCFkb2N0eXBlIGh0bWw+PGh0bWw+PGhlYWQ+PHRpdGxlPuasoui/juS9v+eUqENhbmR577yBPC90aXRsZT48L2hlYWQ+PGJvZHk+PHN0eWxlIHR5cGU9InRleHQvY3NzIj4qeyBwYWRkaW5nOiAwOyBtYXJnaW46IDA7IH0gYm9keXsgaGVpZ2h0OiAxMDAlOyB3aWR0aDogMTAwJTsgYmFja2dyb3VuZC1jb2xvcjojZjNmNWZhOyBmb250LWZhbWlseTogIuW+rui9r+mbhem7kSI7IGNvbG9yOiAjMzMzO2ZvbnQtc2l6ZToyNHB4fSBoMXsgZm9udC1zaXplOiAxMDBweDsgZm9udC13ZWlnaHQ6IG5vcm1hbDsgbWFyZ2luLWJvdHRvbTogMTJweDsgfSBweyBsaW5lLWhlaWdodDogMS44ZW07IGZvbnQtc2l6ZTogMzZweCB9PC9zdHlsZT48ZGl2IHN0eWxlPSJwYWRkaW5nOiAyNHB4IDQ4cHg7Ij4gPGgxPjopPC9oMT48IS0te2hvb2sgZGVtby5odG1sfS0tPjxici8+WyDmgqjnjrDlnKjorr/pl67nmoTmmK97QVBQfemhueebrkluZGV45o6n5Yi25Zmo55qEaW5kZXjmqKHlnZcgXTwvZGl2PjwvYm9keT48L2h0bWw+')));
				}
				
				//创建统一的消息模板
				if(!fileExistsCase($success)){
					//创建模板文件
					copy(CANDYPATH.'Candy/Tpl/success.tpl', $success);
					chmod($success,0777);
					
					//添加模板404
					$tpl404 = $path. $tplStyle . '/Public'. $separate .'404'. $tplPrefix;
					self::touch($tpl404, 'This is 404 file.');
				}
				
				//项目基类初始化
				self::touch($appPath.'Controls/Common.class.php', str_replace('{APPNAME}', $appName, base64_decode('PD9waHAKbmFtZXNwYWNlIEFQUFxDb250cm9sXHtBUFBOQU1FfTsKCmRlZmluZWQoJ0NBTkRZJykgT1IgZGllKCdZb3UgQXJlIEEgQmFkIEd1eS4gb19PPz8/Jyk7CgpDbGFzcyBDb21tb24gZXh0ZW5kcyBCYXNlIHsKCWZ1bmN0aW9uIGluaXQoKXsKCgl9CQkKfQ==')));
		
				//项目控制器初始化
				self::touch($appPath.'Controls/Index.class.php', str_replace('{APPNAME}', $appName, base64_decode('PD9waHAKbmFtZXNwYWNlIEFQUFxDb250cm9sXHtBUFBOQU1FfTsKCmRlZmluZWQoJ0NBTkRZJykgT1IgZGllKCdZb3UgQXJlIEEgQmFkIEd1eS4gb19PPz8/Jyk7CgpDbGFzcyBJbmRleCB7CglmdW5jdGlvbiBpbmRleCgpewoJCS8vaG9vayBkZW1vLnBocAoJCSR0aGlzLT5kaXNwbGF5KCk7Cgl9CQp9')));
				
				//项目基础语言库初始化		
				self::touch($appPath.'Languages/zh-cn/Common.lang.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8v6aG555uu5YWs5YWx6K+t6KiA5bqTCnJldHVybiBbXTsK'));
				
				//项目操作语言库初始化
				self::touch($appPath.'Languages/zh-cn/Index.lang.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8v6aG555uu5pON5L2c6K+t6KiA5bqTCnJldHVybiBbXTsK'));
				
				//初始化路由
				$route = C('INCPATH') . $appName . 'Route.inc.php';
				self::touch($route, base64_decode('PD9waHAKLyoqCiAqIOe9keermei3r+eUsemFjee9rgogKiovCnJldHVybiBbCgknVVJMX01PREVMJyA9PgkxLAoJJ1VSTF9ST1VURVJfT04nID0+IHRydWUsCgkvLydVUkxfREVQUicgPT4gJy0nLCAvLyBVUkzot6/lvoTliIbpmpTnrKbvvIjlhrvnu5PplK4gVVJMX0RFUFLvvIzpu5jorqQv77yM5LiN5Y+v5Li6Lu+8iQoJJ1VSTF9IVE1MX1NVRkZJWCcgPT4nLmh0bWwnLAoJJ1VSTF9NQVBfUlVMRVMnID0+IFsKCQknc2hvdy9pbmRleCcgPT4gJ0hvbWUvSW5kZXgvaW5kZXgnLAoJXSwKCSdVUkxfUk9VVEVfUlVMRVMnID0+IFsKCQknc2hvdy86aWQnID0+ICdIb21lL2luZGV4L2luZGV4JywKCV0sCl07'));
				
				//项目生成记录
				self::touch(C('RUNTIME').'init.lock', implode(PHP_EOL, self::$mess));
				
		}
		return true;
	}

	/**
	 * 创建MD-Blog的配置文件和基本结构
	 * 
	 * 该方法用于初始化Markdown博客系统所需的基础文件和目录结构
	 * 包括以下组件：
	 * 1. 博客配置文件
	 * 2. 默认首页文件
	 * 3. 默认文章分类目录和文件
	 * 4. 各类视图模板文件（首页、文章、分页、404）
	 * 
	 * @return bool 是否初始化成功，成功返回true
	 * @access public
	 * @static
	 * @note 此方法为MD-Blog功能提供基础支持，与主框架功能分离
	 */
	public static function blogInit(): bool
	{
		if (\Candy\Extend\Doc\Blog\Config::isInstalled() === false) {
			$contentDir = \Candy\Extend\Str::lower(\Candy\Extend\Str::random(8, 2));
			self::touch(
				\Candy\Extend\Doc\Blog\Config::incPath(),
				\Candy\Extend\Doc\Blog\Config::defaultIncContent($contentDir)
			);
			self::touch(
				CANDYPATH . 'Doc/Blog/' . $contentDir . '/README.md',
				base64_decode('IyMgQ2FuZHnljZrlrqLliJvlu7rmiJDlip8KCuasoui/juiuv+mXriBDYW5keSBNYXJrZG93biDljZrlrqLjgILmlofnq6DlrZjmlL7lnKjphY3nva7pobkgYGNvbnRlbnRfZGlyYCDlr7nlupTnm67lvZXvvIzor7fpgJrov4cgYC9CbG9nL2Ag6K6/6Zeu77yM5Yu/5a+55aSW5pq06Zyy56OB55uY6Lev5b6E44CC')
			);
			self::touch(
				CANDYPATH . 'Doc/Blog/' . $contentDir . '/默认分类/README.md',
				base64_decode('IyDpu5jorqTliIbnsbsKCuWcqOatpOebruW9leS4i+a3u+WKoOaWh+eroCBNYXJrZG93biDmlofku7bjgII=')
			);
			self::touch(CANDYPATH.'Doc/Blog/views/index.tpl.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKcmVxdWlyZSBDKCdDQU5EWVJPT1QnKSAuICdDYW5keS9DbGFzcy9Eb2MvQmxvZ01hcmtkb3duLnRwbC5waHAnOwo='));
			self::touch(CANDYPATH.'Doc/Blog/views/post.tpl.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKcmVxdWlyZSBDKCdDQU5EWVJPT1QnKSAuICdDYW5keS9DbGFzcy9Eb2MvQmxvZ01hcmtkb3duLnRwbC5waHAnOwo='));
			self::touch(CANDYPATH.'Doc/Blog/views/list.tpl.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKcmVxdWlyZSBDKCdDQU5EWVJPT1QnKSAuICdDYW5keS9DbGFzcy9Eb2MvQmxvZ01hcmtkb3duLnRwbC5waHAnOwo='));
			self::touch(CANDYPATH.'Doc/Blog/views/404.tpl.php', base64_decode('PD9waHAKJGNvbnRlbnQ9JzwhRE9DVFlQRSBodG1sPjxodG1sPjxoZWFkPjxtZXRhIGNoYXJzZXQ9InVmdC04Ij48dGl0bGU+NDA0L3BhZ2Ugbm90IGZvdW5kLjwvdGl0bGU+PG1ldGEgaHR0cC1lcXVpdj0iWC1VQS1Db21wYXRpYmxlIiBjb250ZW50PSJJRT1lZGdlLGNocm9tZT0xIj48bWV0YSBuYW1lPSJ2aWV3cG9ydCIgY29udGVudD0id2lkdGg9ZGV2aWNlLXdpZHRoLCBpbml0aWFsLXNjYWxlPTEsIG1heGltdW0tc2NhbGU9MSI+PG1ldGEgbmFtZT0iYXBwbGUtbW9iaWxlLXdlYi1hcHAtc3RhdHVzLWJhci1zdHlsZSIgY29udGVudD0iYmxhY2siPjxtZXRhIG5hbWU9ImFwcGxlLW1vYmlsZS13ZWItYXBwLWNhcGFibGUiIGNvbnRlbnQ9InllcyI+PHN0eWxlPmh0bWwsIGJvZHkgeyBtYXJnaW46IDA7IGZvbnQtZmFtaWx5OiAiQXJpYWwiLCAiTWljcm9zb2Z0IFlhSGVpIiwgIum7keS9kyIsICLlrovkvZMiLCBzYW5zLXNlcmlmOyBmb250LXNpemU6IDE0cHg7fS5jb250ZW50IHsgd2lkdGg6IDM1cmVtOyBtYXJnaW46IDAgYXV0bzsgZGlzcGxheTogYmxvY2s7IG1hcmdpbi10b3A6IDEwMHB4O30uY29udGVudC1mb2YgeyB3aWR0aDogMTAwJTsgZm9udC1zaXplOiAxMHJlbTsgY29sb3I6ICNCNEJDQ0M7IHRleHQtYWxpZ246IGNlbnRlcjt9LmNvbnRlbnQtZGl2aWRlIHsgd2lkdGg6IDEwMCU7IG1hcmdpbi10b3A6IDEwcHg7IGJvcmRlci1ib3R0b206IDFweCBzb2xpZCAjRURGMkZDO30uY29udGVudC1tc2cgeyB3aWR0aDogMTAwJTsgdGV4dC1hbGlnbjogY2VudGVyOyBmb250LXNpemU6IDEuM3JlbTsgY29sb3I6ICNEOERDRTU7IG1hcmdpbi10b3A6IDIwcHg7IGZvbnQtd2VpZ2h0OiAzMDA7fTwvc3R5bGU+PC9oZWFkPjxib2R5PjxkaXYgY2xhc3M9ImNvbnRlbnQiPjxkaXYgY2xhc3M9ImNvbnRlbnQtZm9mIj40MDQ8L2Rpdj48ZGl2IGNsYXNzPSJjb250ZW50LWRpdmlkZSI+PC9kaXY+PGRpdiBjbGFzcz0iY29udGVudC1tc2ciPnBhZ2Ugbm90IGZvdW5kLjwvZGl2PjwvZGl2PjwvYm9keT48L2h0bWw+Jzs='));
		}
		
		return true;
	}
}
