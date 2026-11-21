<?php
declare(strict_types=1);
namespace Candy\Core\Trait\View;

use Candy\Core\Debug;
use Candy\Core\View as ViewHost;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 模板编译缓存与文件 I/O
 */
final class CompileCache
{
	/** @var array<string, array{content:string,time:int}> 进程内渲染结果缓存（须每请求清空） */
	private static array $fileCache = [];

    /**
     *   construct
     * @param mixed $view
     * @param mixed $compiler
     * @return mixed
     */
    public function __construct(private ViewHost $view, private TagCompiler $compiler) {}

	/**
	 * 清空进程内模板渲染缓存
	 *
	 * Workerman 长驻时本缓存会把已渲染 HTML（含当次 assign 变量）跨请求复用，
	 * 导致 title/正文串页。每请求开始时由 WebServer 调用。
	 *
	 * @return void
	 * @access public
	 * @static
	 */
	public static function clearRequestFileCache(): void
	{
		self::$fileCache = [];
	}

/**
 * Compile File
 * @param mixed $template_name
 * @param mixed $cacheId
 * @param mixed $single
 * @param mixed $templateDir
 * @param mixed $compile_dir
 * @return string
 */
public function compileFile(?string $template_name, string $cacheId = '', bool $single = false, ?string $templateDir = null, string $compile_dir = ''): string
	{
		$cacheKey = $template_name . $cacheId . $single . $templateDir . $compile_dir;

		// Workerman 下禁止进程内「渲染结果」缓存：缓存的是已代入变量的 HTML，跨请求会串 title/正文
		$allowFileCache = !function_exists('isWebServer') || !isWebServer();

		// 检查内存缓存
		if ($allowFileCache && isset(self::$fileCache[$cacheKey]) && (time() - self::$fileCache[$cacheKey]['time']) < 10) {
			return self::$fileCache[$cacheKey]['content'];
		}
      
		// 先解析模板名称与实际路径（style / lastPath / templateDir / tenant 已在此步纳入），
		// 再用完整源文件路径作为编译缓存 key，确保不同上下文下同一逻辑名的模板
		// 互不串用编译缓存。
		$this->view->templateExist($template_name, $single);
		$this->resolveCompilePath($template_name, $compile_dir, $cacheId);
      
		// 模板全局变量
		if(!empty($this->view->getRef())){
			extract($this->view->getRef()); // 使用 extract 替代 foreach，更高效
		}
      
		// 创建目录（使用缓存避免重复检查）
		static $dirCache = [];
		if (!isset($dirCache[$this->view->getCompileDir()]) || !$dirCache[$this->view->getCompileDir()]) {
			if (!is_dir($this->view->getCompileDir())) {
				\Candy\Extend\Dir::create($this->view->getCompileDir());
				$dirCache[$this->view->getCompileDir()] = true;
			}
		}
      
		$contents = '';
      
		// 过期不存在
		if($this->checkCompiled($this->view->getTemplatePath(), $this->view->getCompilePath()) === false){
			$_contents = $this->templateRead($this->view->getTemplatePath());//读取模板的内容
			$contents = $this->compiler->compileContent($_contents);//内容编译
			$this->templateWrite($this->view->getCompilePath(),$contents);
		}
      
		// 加载
		ob_start();
		$this->view->runTemplateInclude($this->view->getCompilePath());
		$contents = ob_get_contents();
		ob_end_clean();
      
		// 缓存结果（仅非 WebServer；长驻进程见 clearRequestFileCache）
		if ($allowFileCache) {
			self::$fileCache[$cacheKey] = [
				'content' => $contents,
				'time' => time()
			];
			if (count(self::$fileCache) > 100) {
				array_shift(self::$fileCache);
			}
		}
      
		return $contents;
	}

/**
 * Template Name
 * @param mixed $name
 * @param mixed $single
 */
public function templateName(?string $name = null,bool $single = false): void
	{
		if(!$single || is_null($name)){
			if(is_null($name)){
				$name = ucfirst($this->view->getControl()). $this->view->getTplSeparate() . $this->view->getAction();
			}elseif(str_contains($name, '/')){
				$name = ucfirst(str_replace('/', $this->view->getTplSeparate(), $name));
			}else{
				$name = ucfirst($this->view->getControl()). $this->view->getTplSeparate() .$name;
			}	
		}
		
		$name .= $this->view->getTplSuffix();
		
		$this->view->setTplName($name);
	}

/**
 * Template Path
 * @return string
 */
public function templatePath(): string
	{
		$tplName = $this->view->getTplName();
		$style = (string) C('TPLSTYLE');
		$lastPath = (string) C('LASTPATH');
		$styleSeg = ($style !== '' ? $style . '/' : '');
		$lastSeg = ($lastPath !== '' ? ltrim($lastPath, '/') . '/' : '');
		$tid = \Candy\Core\Bootstrap::tenantId();
		$hm = C('HALLMARK');
		$hmVal = ($hm !== null && $hm !== '' && $tplName !== 'Public/404') ? (string) $hm : '';

		$candidates = [];

		// 插件：Plugins/{Plug}/Views/Hallmark/{val}/… → Tenant/{id}/… → 公共插件 Views
		$plugRoot = C('PLUGPATH') . C('RAPPNAME') . '/';
		if ($hmVal !== '') {
			$candidates[] = $plugRoot . 'Views/Hallmark/' . $hmVal . '/' . $styleSeg . $lastSeg . $tplName;
			$candidates[] = $plugRoot . 'Views/Hallmark/' . $hmVal . '/' . $tplName;
		}
		$plugTenantStyled = \Candy\Core\Bootstrap::tenantOverlayPath($plugRoot, 'Views', $styleSeg . $lastSeg . $tplName);
		$plugTenantFlat = \Candy\Core\Bootstrap::tenantOverlayPath($plugRoot, 'Views', $tplName);
		if ($plugTenantStyled !== null) {
			$candidates[] = $plugTenantStyled;
		}
		if ($plugTenantFlat !== null) {
			$candidates[] = $plugTenantFlat;
		}
		$candidates[] = $plugRoot . 'Views/' . $style . $lastPath . '/' . $tplName;

		// 应用 / 扩展：{App|Addons}/Views/Hallmark/{val}/… → Tenant/{id}/…
		$appRoot = \Candy\Core\Bootstrap::resolveAppRoot((string) C('APPNAME'));
		if ($hmVal !== '') {
			$candidates[] = $appRoot . 'Views/Hallmark/' . $hmVal . '/' . $styleSeg . $lastSeg . $tplName;
			$candidates[] = $appRoot . 'Views/Hallmark/' . $hmVal . '/' . $tplName;
		}
		$appTenantFlat = \Candy\Core\Bootstrap::tenantOverlayPath($appRoot, 'Views', $tplName);
		$appTenantStyled = \Candy\Core\Bootstrap::tenantOverlayPath($appRoot, 'Views', $styleSeg . $lastSeg . $tplName);
		if ($appTenantFlat !== null) {
			$candidates[] = $appTenantFlat;
		}
		if ($appTenantStyled !== null) {
			$candidates[] = $appTenantStyled;
		}

		// 外部模板根（Home@./Template）
		$tplPathConfigured = C('TPL_PATH');
		if ($tplPathConfigured !== null && $tplPathConfigured !== '' && is_string($tplPathConfigured)) {
			$extRoot = C('CANDYROOT') . trim(str_replace('\\', '/', $tplPathConfigured), './') . '/';
			if ($hmVal !== '') {
				if ($style !== '') {
					$candidates[] = $extRoot . $style . '/Hallmark/' . $hmVal . '/' . $lastSeg . $tplName;
					$candidates[] = $extRoot . $style . '/Hallmark/' . $hmVal . '/' . $tplName;
				}
				$candidates[] = $extRoot . 'Hallmark/' . $hmVal . '/' . $styleSeg . $lastSeg . $tplName;
			}
			if ($tid !== null) {
				if ($style !== '') {
					$candidates[] = $extRoot . $style . '/Tenant/' . $tid . '/' . $lastSeg . $tplName;
					$candidates[] = $extRoot . $style . '/Tenant/' . $tid . '/' . $tplName;
				}
				$candidates[] = $extRoot . 'Tenant/' . $tid . '/' . $styleSeg . $lastSeg . $tplName;
			}
		}

		// 当前 TEMPLATE_DIR 下的覆写（与公共 templateDir 同层对照）
		$templateDir = rtrim(str_replace('\\', '/', (string) $this->view->getTemplateDir()), '/');
		if ($hmVal !== '' && $templateDir !== '') {
			$candidates[] = $templateDir . '/Hallmark/' . $hmVal . '/' . $tplName;
		}
		if ($tid !== null && $templateDir !== '') {
			$candidates[] = $templateDir . '/Tenant/' . $tid . '/' . $tplName;
		}

		// 公共 templateDir
		$candidates[] = $templateDir . '/' . $tplName;

		foreach ($candidates as $candidate) {
			if ($candidate === '' || $candidate === '/' . $tplName) {
				continue;
			}
			if (file_exists($candidate)) {
				$this->view->setTemplatePath($candidate);
				if (str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $plugRoot), '/') . '/')) {
					$this->view->assign(
						'extendres',
						str_replace(C('CANDYROOT'), '/', $plugRoot) . 'Views/' . $style . $lastPath . '/Resource'
					);
				}
				return $candidate;
			}
		}

		$this->view->setTemplatePath($templateDir . '/' . $tplName);
		return $this->view->getTemplatePath();
	}

/**
 * Cache Path
 * @param mixed $cacheId
 */
public function cachePath(string $cacheId): void
	{
		//缓存路径
	if(G('CSTART')){
			//纯静态
			$server = I('server.', [], '');
			[$uri,] = explode('?', (string)($server['REQUEST_URI'] ?? '/'), 2);
			stripos($uri, '.php') && [,$uri] = explode('.php', $uri, 2);
			(empty($uri) || $uri == '/') && $uri = '/index.html';
			$this->view->setCachePath(C('CANDYROOT') . ltrim($uri, '/'));
		}else{
			$get = I('get.', [], '');
			$sign = implode('', $get);
			if(empty($cacheId)){
				$this->view->setCachePath($this->view->getCacheDir() . '/c_' . md5(C('APPNAME'). $this->view->getTplName() .$sign).'.php');
			}else{
				$this->view->setCachePath($this->view->getCacheDir() . '/c_' . md5(C('APPNAME'). $this->view->getTplName() .$sign.$cacheId).'.php');
			}
		}
	}

/**
 * Resolve Compile Path
 * @param mixed $template_name
 * @param mixed $compile_dir
 * @param mixed $cacheId
 */
public function resolveCompilePath(string $template_name,string $compile_dir = '',string $cacheId = ''): void
	{
		// 以已解析的完整源文件路径作为编译缓存 key 基准。
		$sourceKey = $this->view->getTemplatePath() ?: $template_name;

		//缓存标识
		$cache = '';
		if(!empty($cacheId)){
			$cache = '_' . md5($cacheId);
		}
		
		$new_compile_name = '%'. md5($sourceKey . $cache) .'.php';
		
		if($compile_dir != ''){
			$this->view->setCompilePath($compile_dir.'/'.$new_compile_name);
		}else{
			$this->view->setCompilePath( $this->view->getCompileDir().'/'.$new_compile_name);
		}
	}

/**
 * Is Cached
 * @param mixed $template_name
 * @param mixed $templateDir
 * @param mixed $cacheId
 * @return bool
 */
public function isCached(string $template_name ,string $templateDir,string $cacheId=''): bool
	{
		//检查缓存
		if(file_exists($this->view->getCachePath()) && (filemtime($this->view->getCachePath()) + $this->view->getCacheLifeTime() > time())){
			return true;
		}else{
			return false;
		}
	}

/**
 * Check Compiled
 * @param mixed $templatePath
 * @param mixed $compilePath
 * @return bool
 */
public function checkCompiled(string $templatePath,string $compilePath): bool
	{
		//比较缓存时间
		if(file_exists($templatePath) && file_exists($compilePath) && filemtime($templatePath) <= filemtime($compilePath)){
			return true;
		}
		return false;
	}

/**
 * Template Read
 * @param mixed $filename
 * @return mixed
 */
public function templateRead(string $filename)
	{
        if(file_exists($filename) && is_readable($filename) && ($fd = @fopen($filename, 'rb'))){
            $contents = fileRead($filename);
			
			//处理钩子
			$contents = preg_replace_callback('#<!--\{hook\s+([^}]+)\}-->#is', 'Candy\Core\Hook::processHookCallback', $contents);
			
			//处理继承
			$search = '~'. preg_quote($this->view->getLeftDelimiter(), '~') ."\s*extend(.*?)=['\"](.*?)['\"](.*?)\s*". preg_quote($this->view->getRightDelimiter(), '~') .'~s';
			preg_match_all($search, $contents, $_match);
			
			//循环处理
			while(count($_match[1]) > 0){
				foreach($_match[1] as $key=>$match){
					if(trim($match) != 'name'){
						//置换为空 防止死循环
						$contents = str_replace($_match[0][$key], '', $contents);
						continue;
					}
					
					//读取模版
					$_file = $_match[2][$key];
					$fileName = str_replace('/', $this->view->getTplSeparate(), $_file);
					$filePath =  $this->view->getTemplateDir(). '/'. $fileName . $this->view->getTplSuffix();
					if(file_exists($filePath)){
						$fileContent = fileRead($filePath);
					}else{
						$fileContent = '';
					}
					$contents = str_replace($_match[0][$key], $fileContent, $contents);
				}
				preg_match_all($search, $contents, $_match);
			}
			
			//处理注释类变量标签
			$contents = str_replace('<!--'. $this->view->getLeftDelimiter(),$this->view->getLeftDelimiter(),$contents);
			$contents = str_replace($this->view->getRightDelimiter() .'-->',$this->view->getRightDelimiter(),$contents);
            
			//检查是否带有php代码 如果有则拒绝解析
			if(stripos($contents, '<?php') === false && stripos($contents, '<?') === false && stripos($contents, '?>') === false){
			    return $contents;
			}else{
			    return '模板文件内禁止插入php代码！';
			}
        }else{
            Debug::showMessage('模板：'. $filename .'不存在！', 'View');
        }
    }

/**
 * Template Write
 * @param mixed $compilePath
 * @param mixed $contents
 * @return bool
 */
public function templateWrite(string $compilePath,string $contents): bool
	{
		if(empty($contents)) return false;

		$sourceTpl = str_replace('\\', '/', $this->view->getTemplatePath());
		$root = rtrim((string)C('CANDYROOT'), '/\\');
		if ($root !== '' && str_starts_with($sourceTpl, $root)) {
			$sourceTpl = substr($sourceTpl, strlen($root)) ?: $sourceTpl;
		}
		$sourceTpl = '/' . ltrim($sourceTpl, '/');

	    $contents = '<?php /* CandyFrame Version '. CANDY_VERSION .', Created on '. date('Y-m-d H:i:s', gmTime()) .' Compiled from '. $sourceTpl .' */ ?>'. PHP_EOL . $contents;
		
		if(!(fileWrite($compilePath, $contents))){
			Debug::addMsg('<font color="red">系统无法写入文件'.$compilePath.'。</font>', 4);
			return false;
		}
		return true;
	}

}
