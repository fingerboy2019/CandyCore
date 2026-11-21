<?php
/***
 * Markdown类 - Candy框架文档渲染与博客系统
 * 
 * 提供Markdown文档解析、渲染和导航生成功能，支持两种运行模式：框架文档模式和博客模式。
 * 自动处理文档目录结构，生成导航菜单，支持上一篇/下一篇文章切换，并根据.order文件进行自定义排序。
 * 
 * 主要功能：
 * - Markdown文档解析与HTML渲染
 * - 动态菜单结构生成
 * - 目录自定义排序（基于.order文件）
 * - 支持框架文档和个人博客两种模式
 * - 自动处理目录默认页面和文件路径解析
 * - 提供页面导航功能（上一篇/下一篇）
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com) $
 * @date 2019-08-01 23:00:09 $   
 * @final 该类不允许被继承
 */

declare(strict_types=1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Markdown
{
    /**
     * 配置选项数组
     * 
     * 存储Markdown渲染和博客系统的配置选项，包括文档根目录、模板路径等
     * 在博客模式下从 Source/Inc/BlogConfig.inc.php 加载，在文档模式下为空
     * 
     * @var array 配置选项键值对数组
     */
    private static $conf = [];

    /**
     * 默认首页文件名
     * 
     * 定义目录默认显示的Markdown文件名称，默认为'README.md'
     * 当访问目录时会自动加载该文件
     * 
     * @var string 默认首页文件名
     */
    private static $index = 'README.md';

    /**
     * 文档文件目录路径
     * 
     * 存储Markdown文件所在的根目录路径，根据运行模式自动设置：
     * - 框架文档模式：默认为Doc/Candy/
     * - 博客模式：从配置中加载或使用Doc/Blog/
     * 
     * @var string 文档文件目录路径
     */
    private static $filesDir;
    
    /**
     * 基础访问路径
     * 
     * 存储URL基础路径前缀，用于生成正确的链接地址
     * 默认值为'/'；访问 /Doc/ 时为 '/Doc/'，访问 /Blog/ 时为 '/Blog/'
     * 
     * @var string URL基础路径前缀
     */
    private static $basePath = '/';

	/** @return array<string,mixed> 当前请求 SERVER 数据 */
	private static function requestServer(): array
	{
		return I('server.', [], '');
	}

	/** @return array<string,mixed> 当前请求 GET 数据 */
	private static function requestGet(): array
	{
		return I('get.', [], '');
	}

	/** @return array<string,mixed> 当前请求 POST 数据 */
	private static function requestPost(): array
	{
		return I('post.', [], '');
	}

	/** 同步受控的请求路径改写到 R() 与遗留 SERVER。 */
	private static function setRequestServer(string $key, mixed $value): void
	{
		if (Request::inCoroutine()) {
			R()->setServer($key, $value);
		} else {
			$_SERVER[$key] = $value;
		}
	}
    
    /**
     * Markdown功能入口
     * 
     * 初始化Markdown系统，根据运行环境自动选择框架文档模式或博客模式
     * - 在博客模式下，读取 Source/Inc/BlogConfig.inc.php 配置并处理初始化请求
     * - 在文档模式下，使用默认配置处理文档显示
     * 自动加载并解析Markdown文件内容，最终通过Respond类输出HTML响应
     * 
     * @return void
     */
	public static function start(): void
	{
        debug(0);
		$get = self::requestGet();
		$server = self::requestServer();

        if (isset($get['init']) && $get['init'] === 'mdblog') {
            Build::blogInit();
            $uriLower = strtolower(explode('?', $server['REQUEST_URI'] ?? '', 2)[0]);
            $dest = \Candy\Extend\Doc\Blog\Config::isStandaloneHost() ? '/' : '/Blog/';
            if (\Candy\Extend\Doc\Blog\Config::isStandaloneHost()) {
                $onBlogEntry = $uriLower === '' || $uriLower === '/';
            } else {
                $onBlogEntry = $uriLower === '/blog' || str_starts_with($uriLower, '/blog/');
            }
            if (!$onBlogEntry) {
                header('Location: ' . $dest);
                stop();
            }
        }

        self::$conf = [];
        self::$basePath = '/';

        if (self::isBlogMode()) {
            if (!\Candy\Extend\Doc\Blog\Config::isInstalled()) {
                showExceptionMsg('halt', '博客未初始化，请访问 ?init=mdblog 完成博客初始化');
            }

            self::$conf = \Candy\Extend\Doc\Blog\Config::load();
            self::$filesDir = self::resolveBlogFilesDir();

            if (!is_dir(self::$filesDir)) {
                showExceptionMsg('halt', '博客正文目录不存在:' . self::$filesDir);
            }

            $content = self::getMarkdownContent();
        } else {
            self::$filesDir = C('CANDYROOT') . 'Doc/Candy/';
            if (!is_dir(self::$filesDir)) {
                showExceptionMsg('halt', '使用文档文件丢失,请重新下载后重试!');
            }

            $content = self::getMarkdownContent();
        }

        //输出内容（无刷新加载文档时返回 JSON）
        $respondType = ($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' ? 'json' : 'html';
        Respond::create($content, $respondType, 200)->send();

        stop();
    }

    /**
     * 是否为 Markdown 博客模式
     *
     * 博客模式：/Blog/ 路径前缀，或 BlogConfig.blog_domain 绑定的独立域名
     *
     * @return bool
     * @access private
     * @static
     */
	private static function isBlogMode(): bool
    {
        if (\Candy\Extend\Doc\Blog\Config::isStandaloneHost()) {
            return true;
        }

		$server = self::requestServer();
        $uriLower = strtolower(explode('?', $server['REQUEST_URI'] ?? '', 2)[0]);

        return $uriLower === '/blog' || str_starts_with($uriLower, '/blog/');
    }

    /**
     * 博客安装根目录（含 views、Static，不含正文子目录）
     *
     * @return string 绝对路径，末尾无斜杠
     * @access private
     * @static
     */
    private static function blogInstallRoot(): string
    {
        $docRoot = self::$conf['doc_root'] ?? 'Doc/Blog/';

        return rtrim(
            str_starts_with((string) $docRoot, '/')
                ? (string) $docRoot
                : C('CANDYROOT') . ltrim((string) $docRoot, '/'),
            '/'
        );
    }

    /**
     * 博客正文目录名（BlogConfig.inc.php 中 content_dir，8 位随机串）
     *
     * @return string
     * @access private
     * @static
     */
    private static function blogContentDir(): string
    {
        $dir = (string) (self::$conf['content_dir'] ?? '');

        return preg_match('/^[a-z0-9]{8}$/', $dir) ? $dir : '';
    }

    /**
     * 解析博客 Markdown 正文根目录（doc_root + content_dir）
     *
     * @return string 绝对路径，末尾带 /
     * @access private
     * @static
     */
    private static function resolveBlogFilesDir(): string
    {
        $contentDir = self::blogContentDir();
        if ($contentDir === '') {
            showExceptionMsg('halt', '博客 content_dir 未配置，请检查 Source/Inc/BlogConfig.inc.php');
        }

        return self::blogInstallRoot() . '/' . $contentDir . '/';
    }

    /**
     * 拒绝 URL 中出现正文目录名或历史 post 段（防止泄露磁盘布局）
     *
     * @param array $segments URL 路径分段
     * @return void
     * @access private
     * @static
     */
    private static function rejectBlogLeakSegments(array $segments): void
    {
        if (empty(self::$conf)) {
            return;
        }

        $blocked = array_filter([
            self::blogContentDir(),
            'post',
        ]);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === null) {
                continue;
            }
            if (in_array(strtolower((string) $segment), array_map('strtolower', $blocked), true)) {
                respond('File Not Found.', 404);
            }
        }
    }

    /**
     * 博客磁盘相对路径转公开 URL 路径
     *
     * @param string $diskRelative 相对正文根目录的路径，如 分类/文.md
     * @return string URL 段，如 分类/文.md
     * @access private
     * @static
     */
    private static function blogDiskPathToUrlPath(string $diskRelative): string
    {
        return trim(str_replace('\\', '/', $diskRelative), '/');
    }

    /**
     * 剥离 URL 中的 /Doc/ 或 /Blog/ 前缀并设置 basePath
     *
     * @return void
     * @access private
     * @static
     */
	private static function normalizeRequestUriPrefix(): void
	{
		$server = self::requestServer();
        $rawUri = $server['REQUEST_URI'] ?? '';
        $uriLower = strtolower(explode('?', $rawUri, 2)[0]);

        if (\Candy\Extend\Doc\Blog\Config::isStandaloneHost()) {
            self::$basePath = '/';

            return;
        }

        if ($uriLower === '/blog' || str_starts_with($uriLower, '/blog/')) {
			self::setRequestServer('REQUEST_URI', substr($rawUri, 5));
            self::$basePath = '/Blog/';

            return;
        }

        if ($uriLower === '/doc' || str_starts_with($uriLower, '/doc/')) {
			self::setRequestServer('REQUEST_URI', substr($rawUri, 5));
            self::$basePath = '/Doc/';
        }
    }

    /**
     * 导航菜单用：为无前缀的 currentPath 补上 basePath
     *
     * @param string $currentPath 当前访问 URL 路径
     * @return string
     * @access private
     * @static
     */
    private static function normalizeNavCurrentPath(string $currentPath): string
    {
        $base = rtrim(self::$basePath, '/');
        if ($base === '') {
            return $currentPath;
        }

        $lowerPath = strtolower($currentPath);
        $lowerBase = strtolower($base);
        if (str_starts_with($lowerPath, $lowerBase . '/') || $lowerPath === $lowerBase) {
            return $currentPath;
        }

        return $base . ($currentPath === '/' ? '' : $currentPath);
    }

    /**
     * 获取并解析Markdown文件内容
     * 
     * 根据当前URL请求解析文件路径，读取并解析Markdown文件，生成HTML内容
     * 处理URL路径规范化、文档目录默认页面逻辑、菜单生成和模板渲染
     * 根据运行模式（文档或博客）采用不同的处理流程和模板
     * 
     * @return string 生成的HTML内容，包含解析后的Markdown和导航菜单
     */
	private static function getMarkdownContent(): string
	{
        debug(0);

        self::normalizeRequestUriPrefix();
		$server = self::requestServer();
		$get = self::requestGet();
		$post = self::requestPost();

        // 全文搜索 API（JSON）
        if (
			($server['REQUEST_METHOD'] ?? '') === 'POST'
            && ($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'SearchRequest'
        ) {
            self::handleSearchRequest();
        }

        // 门户 feed 分页 API（首页加载更多 / 分类·标签列表无限滚动）
        if (($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'BlogFeedRequest') {
            self::handleBlogFeedRequest();
        }

        // 博客评论：验证码图（<img> 无法带自定义 Header，用 query 触发）
        if (
            !empty(self::$conf)
			&& (string) ($get['blog_comment'] ?? '') !== ''
            && strtolower((string) ($get['action'] ?? '')) === 'captcha'
        ) {
            if (function_exists('verificationCode')) {
                verificationCode('', '', 38, 100);
            }
            stop();
        }

        // 博客评论提交 / 验证码
        if (($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'BlogCommentRequest') {
            self::handleBlogCommentRequest();
        }

        //获取文件路径 并 对URL解码
		$uri = urldecode(trim((string)($server['REQUEST_URI'] ?? ''), '/'));

        //不接受参数
        stripos($uri, '?') !== false && [$uri] = explode('?', $uri, 2);

        // Blog：RSS / 标签归档 / 搜索页；Doc：RSS / Sitemap（优先于普通路径）
        $blogTagArchive = '';
        if (!empty(self::$conf)) {
            if ($uri === 'search' || $uri === 'search/') {
                self::serveBlogSearch(trim((string) ($get['q'] ?? '')));
            }
            if ($uri === 'rss.xml') {
                self::serveBlogRss();
            }
            if ($uri === 'sitemap.xml') {
                self::serveBlogSitemap();
            }
            if (preg_match('#^tag/([^/]+)$#u', $uri, $tagMatch)) {
                $blogTagArchive = trim(rawurldecode((string) $tagMatch[1]));
                if ($blogTagArchive === '' || str_contains($blogTagArchive, '..')) {
                    respond('File Not Found.', 404);
                }
            }
        }

        // Doc 模式（框架文档站）：RSS / Sitemap
        if (empty(self::$conf)) {
            if ($uri === 'rss.xml') {
                self::serveDocRss();
            }
            if ($uri === 'sitemap.xml') {
                self::serveDocSitemap();
            }
        }

        //解析路径
        $uris = explode('/', $uri);
        if (!empty($uris[0])) {
            self::assertSafeUriSegments($uris);
        }
        if (!empty(self::$conf)) {
            self::rejectBlogLeakSegments($uris);
        }

        //带路径
        $dir = $readFile = $search = '';
        if(count($uris) > 0 && !empty($uris[0])){
        	//文件名称
        	$file = array_pop($uris);
        	//文件路径
        	$dir = implode('/', $uris);
            $tplName = 'post';
        }else{
            //根目录文件 或 主页
            $file = empty($uris[0])
                ? (is_string(self::$conf['index'] ?? null) && (self::$conf['index'] ?? '') !== ''
                    ? (string) self::$conf['index']
                    : '')
                : $uris[0];
            $tplName = 'index';
        }

        // 处理post请求
		if (($server['REQUEST_METHOD'] ?? '') === 'POST') {
            //处理密码提交
            if(isset($post['doc_password'], $post['doc_key'])) {
                // 处理密码提交
				$docKey = $post['doc_key'];
				$password = $post['doc_password'];
                session('doc_password',[$docKey=>$password]);
            }
        }

        //文件路径
        $file = empty($readFile) ? self::$filesDir . (empty($dir) ? '' : $dir . '/') . $file : $readFile;

        // 标签归档：映射到正文根目录，复用 list 模板（不依赖磁盘上的 tag/ 目录）
        if ($blogTagArchive !== '') {
            $file = rtrim(self::$filesDir, '/\\');
            $dir = '';
        }

        //默认文件
        $is_dir = false;
        $list = [];
        $blogListCategory = '';
        if (is_dir($file)){
            $is_dir = true;
            $blogListCategory = basename(rtrim(str_replace('\\', '/', $file), '/'));
            $readmePath = rtrim($file, '/') . '/README.md';
            if (file_exists($readmePath)) {
                $file = $readmePath;
            } else {
                $list = array_filter(scandir($file), function($item) {
                    return str_ends_with($item, '.md') && $item !== 'README.md';
                });
                if (!empty($list)) {
                    usort($list, function($a, $b) use ($file) {
                        return filemtime($file . '/' . $a) <=> filemtime($file . '/' . $b);
                    });
                    $file = $file . '/' . $list[0];
                }
            } 
        }
        
        //框架DOC模式
        if(empty(self::$conf)){
            // 兼容 .html 伪静态与裸路径：先剥 .html 假后缀，再补 .md
            if (str_ends_with($file, '.html')) {
                $file = substr($file, 0, -5);
            }
            //如果文件名称不是 .md结尾
            if(str_ends_with($file, '.md') === false){
                $file = $file. '.md';
            }

            $file = self::resolveDocFilePath($file, false);
            if ($file === null) {
                self::serveDocNotFound();
            }

			if ((string)(self::requestGet()['download'] ?? '') === '1') {
                self::sendMarkdownDownload($file);
            }
            
            // 菜单功能
            [$nav, $prevPage, $nextPage] = self::generateMenuStructure($search);
            
            //文件内容
            [$mark , $content] = \Candy\Extend\Doc\Markdown\Engine::parseFile($file, true, self::buildDocParseConfig($file));

            if(isset($mark['meta']) && count($mark['meta']) > 0) extract($mark['meta'], EXTR_PREFIX_IF_EXISTS, 'meta_');

            // 标题
            if(isset($title) == false){
                //文件名称
                $name = pathinfo($file, PATHINFO_FILENAME);
                //标题
                $title = $name . '.md' === self::$index? 'Candy框架文档' : $name . '-' . 'Candy框架文档';
            }
            $plainTitle = (string) $title;
            $plainDescription = isset($description) ? (string) $description : '';
            if ($plainDescription === '' && $tplName === 'index') {
                $plainDescription = 'Candy 框架开发手册：框架能力、类库 API、扩展与第三方集成文档。';
            }
            $title = '<title>' . htmlspecialchars($plainTitle, ENT_QUOTES, 'UTF-8') . '</title>';
            $keywords = isset($keywords) ? '<meta name="keywords" content="' . htmlspecialchars((string) $keywords, ENT_QUOTES, 'UTF-8') . '">' : '';
            $description = $plainDescription !== '' ? '<meta name="description" content="' . htmlspecialchars($plainDescription, ENT_QUOTES, 'UTF-8') . '">' : '';

            extract(\Candy\Extend\Doc\Markdown\Engine::buildDocTemplateVars(
                $mark['meta'] ?? [],
                $plainTitle,
                $plainDescription,
                self::buildCanonicalPageUri($file)
            ));

            $docStyle = (string) (($mark['meta']['style'] ?? null) ?: 'default');
            $headAssets = \Candy\Extend\Doc\Site\Assets::headHtml($mark, $docStyle);

            \Candy\Extend\Doc\Site\Context::boot([
                'tpl' => 'page',
                'page' => [
                    'manual_title' => 'Candy框架开发手册',
                    'home_url' => '/Doc/',
                    'plain_title' => $plainTitle,
                    'content' => $content ?? '',
                    'doc_meta' => (string) ($docMeta ?? ''),
                    'nav_html' => (string) ($nav ?? ''),
                    'prev_page' => is_array($prevPage ?? null) ? $prevPage : [],
                    'next_page' => is_array($nextPage ?? null) ? $nextPage : [],
                    'mark' => is_array($mark) ? $mark : [],
                    'sidebar_enabled' => ($sidebarEnabled ?? true) !== false,
                    'logo_html' => (string) ($logoHtml ?? ''),
                    'doc_theme' => \Candy\Extend\Doc\Site\Assets::selectedTheme((string) ($docTheme ?? '')),
                    'html_lang' => (string) ($htmlLang ?? 'zh-CN'),
                    'title_tag' => (string) ($title ?? ''),
                    'keywords' => (string) ($keywords ?? ''),
                    'description_tag' => (string) ($description ?? ''),
                    'canonical' => (string) ($canonical ?? ''),
                    'favicon' => (string) ($favicon ?? ''),
                    'open_graph' => (string) ($openGraph ?? ''),
                    'head_assets' => $headAssets,
                    'show_print' => !empty($showPrint),
                    'show_download' => !empty($showDownload),
                    'download_url' => (string) ($downloadUrl ?? ''),
                ],
            ]);
            $doc = \Candy\Extend\Doc\Site\Context::viewData();

            //样式模板 如果元数据里有样式模板的值优先使用
            $mdTpl = C('CANDYROOT') . ($tpl ?? 'Candy/Class/Doc/Site/layout.tpl.php');
        }else{
            //MD-Blog模式
            if($is_dir && $tplName != 'index'){
                $tplName = 'list';
            }

            // 文章 URL 不含 .md 后缀，解析前补全扩展名（兼容 .html 伪静态与裸路径）
            if ($is_dir === false) {
                if (str_ends_with($file, '.html')) {
                    $file = substr($file, 0, -5);
                }
                if (str_ends_with($file, '.md') === false) {
                    $file .= '.md';
                }
            }

            if(is_file($file) === false && $is_dir === false){
                $tplName = '404';
            } elseif (is_file($file)) {
                $resolved = self::resolveDocFilePath($file, false);
                if ($resolved === null) {
                    $tplName = '404';
                } else {
                    $file = $resolved;
                    // 公开侧：草稿文章一律 404（后台仍可编辑磁盘文件）
                    if ($tplName === 'post' || (!$is_dir && $tplName !== 'list' && $tplName !== 'index')) {
                        $rawPeek = @file_get_contents($file);
                        if (is_string($rawPeek) && $rawPeek !== '') {
                            $peekMeta = \Candy\Extend\Doc\Markdown\Engine::peekMetaFromText($rawPeek);
                            if (\Candy\Extend\Doc\Blog\Index::isDraftMeta($peekMeta)) {
                                $tplName = '404';
                            }
                        }
                    }
                }
            }

            if ($tplName !== '404') {
                // 生成博客菜单
                [$nav, $prevPage, $nextPage] = self::generateMenuStructure($search);
                
                // 解析Markdown
                [$mark , $content] = \Candy\Extend\Doc\Markdown\Engine::parseFile($file, false, self::buildDocParseConfig($file));

                if(isset($mark['meta']) && count($mark['meta']) > 0) extract($mark['meta'], EXTR_PREFIX_IF_EXISTS, 'meta_');

                if (!isset($title)) {
                    $name = pathinfo($file, PATHINFO_FILENAME);
                    $title = $name;
                }
                $plainTitle = (string) $title;
                $plainDescription = isset($description) ? (string) $description : '';
                $blogMeta = is_array($mark['meta'] ?? null) ? $mark['meta'] : [];
                self::applyBlogArticleMeta($blogMeta, $tplName, is_string($file) ? $file : null);
                $mark['meta'] = $blogMeta;
                extract(\Candy\Extend\Doc\Markdown\Engine::buildDocTemplateVars(
                $blogMeta,
                $plainTitle,
                $plainDescription,
                self::buildCanonicalPageUri($file)
            ));
            }

            $blogSiteName = (string) (self::$conf['site_name'] ?? '博客');
            $blogSiteDescription = (string) (self::$conf['site_description'] ?? '');
            $blogTplName = $tplName;
            $blogConf = self::$conf;
            $blogPortal = [];
            $blogCurrentPath = self::normalizeNavCurrentPath(
				(string) (parse_url((string)(self::requestServer()['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/')
            );
            $blogMastNav = \Candy\Extend\Doc\Blog\Index::buildMastNav(
                self::$filesDir,
                self::$basePath,
                self::$conf,
                $blogCurrentPath
            );
            if ($tplName === 'index') {
                $blogPortal = \Candy\Extend\Doc\Blog\Index::buildPortalData(
                    self::$filesDir,
                    self::$basePath,
                    self::$conf
                );
            }

            $blogCategoryName = '';
            $blogCategoryPosts = [];
            $blogCategoryTotal = 0;
            $blogListScope = 'category';
            $blogTagName = '';
            $blogTagTotal = 0;
            if ($tplName === 'list') {
                if ($blogTagArchive !== '') {
                    $blogListScope = 'tag';
                    $blogTagName = $blogTagArchive;
                    $blogCategoryName = '标签：' . $blogTagName;
                    $allTagPosts = \Candy\Extend\Doc\Blog\Index::listPostsByTag(
                        self::$filesDir,
                        self::$basePath,
                        $blogTagName
                    );
                    $blogTagTotal = count($allTagPosts);
                    $blogCategoryTotal = $blogTagTotal;
                    $listPageSize = max(1, (int) ((self::$conf['feed_limits']['list_page'] ?? 8)));
                    $blogCategoryPosts = array_slice($allTagPosts, 0, $listPageSize);
                    $plainTitle = $blogCategoryName;
                    $plainDescription = '标签「' . $blogTagName . '」下的文章';
                } else {
                    $blogCategoryName = $dir !== ''
                        ? trim(str_replace('\\', '/', $dir), '/')
                        : $blogListCategory;
                    if ($blogCategoryName !== '') {
                        $allCategoryPosts = \Candy\Extend\Doc\Blog\Index::listPostsByCategory(
                            self::$filesDir,
                            self::$basePath,
                            basename(str_replace('\\', '/', $blogCategoryName))
                        );
                        $blogCategoryTotal = count($allCategoryPosts);
                        $listPageSize = max(1, (int) ((self::$conf['feed_limits']['list_page'] ?? 8)));
                        $blogCategoryPosts = array_slice($allCategoryPosts, 0, $listPageSize);
                    }
                }
                // 分类/标签列表由 category-list 模板渲染，勿将 README 正文当作页面内容
                $content = '';
                $page = '';
            }

            if ($tplName !== '404') {
                extract(self::resolveBlogHeadMeta(
                    $tplName,
                    $blogSiteName,
                    $blogSiteDescription,
                    $blogCategoryName,
                    [
                        'plainTitle' => $plainTitle ?? '',
                        'plainDescription' => $plainDescription ?? null,
                        'keywords' => $keywords ?? null,
                        'openGraph' => $openGraph ?? null,
                        'mark' => $mark ?? null,
                        'file' => $file ?? null,
                    ]
                ), EXTR_OVERWRITE);
            }

            $blogVars = \Candy\Extend\Doc\Blog\Vars::resolve(self::$conf, $tplName);
            if ($blogVars !== []) {
                extract($blogVars, EXTR_SKIP);
            }

            if ($tplName === 'post' && is_string($content)) {
                $content = self::injectBlogArticleCover(
                    $content,
                    is_array($mark ?? null) ? $mark : [],
                    (string) ($plainTitle ?? ''),
                    $file ?? null
                );
            }

            $blogPostRel = '';
            if ($tplName === 'post' && is_string($file ?? null) && is_file($file)) {
                $blogPostRel = ltrim(str_replace('\\', '/', str_replace(
                    rtrim(self::$filesDir, '/\\'),
                    '',
                    str_replace('\\', '/', $file)
                )), '/');
            }

            $headAssets = \Candy\Extend\Doc\Blog\Assets::headHtml(
                is_array($mark ?? null) ? $mark : [],
                '/Doc/Blog/Static/',
                $tplName,
                self::$basePath,
                $blogSiteName
            );

            $commentSettings = \Candy\Extend\Doc\Blog\Comment::settings(self::$conf);
            $commentTreeHtml = '';
            $commentTotal = 0;
            if ($tplName === 'post' && $blogPostRel !== '' && $commentSettings['enabled']) {
                $pub = \Candy\Extend\Doc\Blog\Comment::publicTree(
                    self::$filesDir,
                    $blogPostRel,
                    (int) $commentSettings['max_depth']
                );
                $commentTotal = (int) ($pub['total'] ?? 0);
                $commentTreeHtml = \Candy\Extend\Doc\Blog\Comment::renderPublicListHtml(
                    self::$filesDir,
                    $blogPostRel,
                    (int) $commentSettings['max_depth']
                );
            }

            \Candy\Extend\Doc\Blog\Context::boot([
                'files_dir' => self::$filesDir,
                'base_path' => self::$basePath,
                'conf' => self::$conf,
                'tpl' => $tplName,
                'current_path' => $blogCurrentPath,
                'page' => [
                    'portal' => $blogPortal,
                    'mast_nav' => $blogMastNav,
                    'list_scope' => $blogListScope,
                    'category_name' => $blogCategoryName,
                    'category_intro' => (string) ($plainDescription ?? ''),
                    'category_total' => $blogCategoryTotal,
                    'tag_name' => $blogTagName,
                    'tag_total' => $blogTagTotal,
                    'post_rel' => $blogPostRel,
                    'comment_enabled' => $commentSettings['enabled'] && $blogPostRel !== '',
                    'comment_captcha' => !empty($commentSettings['captcha']),
                    'comment_total' => $commentTotal,
                    'comment_html' => $commentTreeHtml,
                    'comment_emojis' => \Candy\Extend\Doc\Markdown\Engine::commonEmojis(),
                    'nav_html' => $nav ?? '',
                    'prev_page' => $prevPage ?? [],
                    'next_page' => $nextPage ?? [],
                    'content' => $content ?? '',
                    'plain_title' => (string) ($plainTitle ?? ''),
                    'doc_meta' => (string) ($docMeta ?? ''),
                    'mark' => is_array($mark ?? null) ? $mark : [],
                    'sidebar_enabled' => $sidebarEnabled ?? true,
                    'doc_theme' => \Candy\Extend\Doc\Site\Assets::selectedTheme((string) ($docTheme ?? '')),
                    'html_lang' => (string) ($htmlLang ?? 'zh-CN'),
                    'title_tag' => (string) ($title ?? ''),
                    'keywords' => (string) ($keywords ?? ''),
                    'description_tag' => (string) ($description ?? ''),
                    'canonical' => (string) ($canonical ?? ''),
                    'favicon' => (string) ($favicon ?? ''),
                    'open_graph' => (string) ($openGraph ?? ''),
                    'head_assets' => $headAssets,
                    'show_print' => $showPrint ?? false,
                    'show_download' => $showDownload ?? false,
                    'download_url' => (string) ($downloadUrl ?? ''),
                ],
            ]);
            $blog = \Candy\Extend\Doc\Blog\Context::viewData();
            
            // 加载博客模板
            if(isset(self::$conf['template'])){
                $mdTpl = self::$conf['template'] . $tplName . '.tpl.php';
            } else {
                $mdTpl = C('CANDYROOT') . ($tpl ?? 'Doc/Blog/views/' . $tplName . '.tpl.php');
            }
        }
        
        include $mdTpl;

        // 模板在 XMLHttpRequest 下组装 $res，需输出 JSON 供 loadPage 使用
        if (
			(self::requestServer()['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            && isset($res)
            && is_array($res)
        ) {
            return json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $content;
    }

    /**
     * 构建文档解析器 URL 上下文
     *
     * 将 Core 的 basePath（/ 根模式 或 /Doc/ 兼容模式）与当前 .md 相对 Doc/Candy 的目录
     * 注入 Extend Markdown，供相对链接与面包屑生成一致的访问路径
     *
     * @param string $filePath 当前文档绝对路径
     * @return array{urlBasePath:string,pageUrlBase:string}
     * @access private
     * @static
     */
    private static function buildDocParseConfig(string $filePath): array
    {
        $relativeDir = trim(str_replace('\\', '/', dirname(str_replace(self::$filesDir, '', $filePath))), '/.');
        if (!empty(self::$conf)) {
            $relativeDir = self::blogDiskPathToUrlPath($relativeDir);
        }
        $urlBase = rtrim(self::$basePath, '/');
        $pageUrlBase = $relativeDir === ''
            ? ($urlBase === '' ? '/' : $urlBase)
            : ($urlBase === '' ? '' : $urlBase) . '/' . $relativeDir;

        return [
            'urlBasePath' => self::$basePath,
            'pageUrlBase' => $pageUrlBase,
            'docRoot' => self::$filesDir,
            'docFileDir' => dirname($filePath),
        ];
    }

    /**
     * 构建当前文档的规范访问路径
     *
     * /Doc/ 模式下 REQUEST_URI 会去掉 /Doc/ 前缀用于磁盘路径解析，
     * 下载链接须使用带 urlBasePath 的绝对路径，避免浏览器按当前目录拼接出重复段
     *
     * @param string $filePath 当前文档绝对路径
     * @return string 以 / 开头的页面 URL（不含查询串）
     * @access private
     * @static
     */
    private static function buildCanonicalPageUri(string $filePath): string
    {
        $parseConfig = self::buildDocParseConfig($filePath);
        $pageUrlBase = rtrim((string) $parseConfig['pageUrlBase'], '/');
        $baseName = pathinfo($filePath, PATHINFO_FILENAME);
        $indexStem = pathinfo(self::$index, PATHINFO_FILENAME);

        if ($baseName === $indexStem) {
            return $pageUrlBase === '' ? '/' : $pageUrlBase . '/';
        }

        return ($pageUrlBase === '' ? '' : $pageUrlBase) . '/' . $baseName;
    }

    /**
     * 拒绝 URI 中的 . / .. 段，防止目录穿透
     *
     * @param array $segments URL 路径分段
     * @return void
     * @access private
     * @static
     */
    private static function assertSafeUriSegments(array $segments): void
    {
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                showExceptionMsg('halt', '非法的文档路径');
            }
        }
    }

    /**
     * 判断真实路径是否位于文档根目录之下
     *
     * @param string $realPath 已解析的真实路径
     * @param string $docRoot 文档根目录
     * @return bool
     * @access private
     * @static
     */
    private static function isPathUnderDocRoot(string $realPath, string $docRoot): bool
    {
        $realFile = realpath($realPath);
        $realRoot = realpath($docRoot);
        if ($realFile === false || $realRoot === false) {
            return false;
        }
        $root = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($realFile, $root);
    }

    /**
     * 博客文章元信息补全
     *
     * 文章页：默认作者、从目录推断分类、生成分类列表 URL
     *
     * @param array $meta Front Matter（引用传递）
     * @param string $tplName 页面模板名
     * @param string|null $file 当前 .md 绝对路径
     * @return void
     * @access private
     * @static
     */
    private static function applyBlogArticleMeta(array &$meta, string $tplName, ?string $file): void
    {
        if ($tplName !== 'post' || empty(self::$conf)) {
            return;
        }

        if (trim((string) ($meta['author'] ?? '')) === '') {
            $meta['author'] = (string) (self::$conf['default_author'] ?? '博主');
        }

        if (trim((string) ($meta['category'] ?? '')) === '' && is_string($file) && $file !== '') {
            $relative = str_replace('\\', '/', str_replace(self::$filesDir, '', $file));
            $dir = trim(dirname($relative), '/.');
            if ($dir !== '' && $dir !== '.') {
                $meta['category'] = basename($dir);
            }
        }

        $category = trim((string) ($meta['category'] ?? ''));
        if ($category !== '') {
            $meta['category_url'] = rtrim(self::$basePath, '/') . '/' . rawurlencode($category) . '/';
        }
    }

    /**
     * 博客页 head 元信息：补全标题回退并输出 title / keywords / description 标签
     *
     * 与框架 Doc 模式一致，将纯文本标题与描述包装为 HTML meta
     * 首页用站点名，文章与分类页在标题后追加站点名（若尚未包含）
     *
     * @param string $tplName 页面模板名 index|post|list
     * @param string $siteName 站点名称
     * @param string $siteDescription 站点描述
     * @param string $categoryName 分类列表页当前分类名
     * @param array $context 已解析的标题、描述与模板变量
     * @return array{plainTitle:string,plainDescription:string,title:string,keywords:string,description:string,openGraph?:string}
     * @access private
     * @static
     */
    private static function resolveBlogHeadMeta(
        string $tplName,
        string $siteName,
        string $siteDescription,
        string $categoryName,
        array $context
    ): array {
        $plainTitle = (string) ($context['plainTitle'] ?? '');
        $plainDescription = $context['plainDescription'] ?? null;
        $keywords = $context['keywords'] ?? null;
        $openGraph = $context['openGraph'] ?? null;
        $mark = is_array($context['mark'] ?? null) ? $context['mark'] : [];
        $file = $context['file'] ?? null;

        if ($plainTitle === '') {
            $plainTitle = match ($tplName) {
                'index' => $siteName,
                'list' => $categoryName !== '' ? $categoryName : '分类',
                default => $siteName,
            };
        }

        if ($plainDescription === null) {
            $plainDescription = $tplName === 'index' ? $siteDescription : '';
        }
        $plainDescription = (string) $plainDescription;

        $headVars = [];
        if ($openGraph === null) {
            $meta = is_array($mark['meta'] ?? null) ? $mark['meta'] : [];
            $canonicalFile = is_string($file) && is_file($file) ? $file : '';
            $headVars = \Candy\Extend\Doc\Markdown\Engine::buildDocTemplateVars(
                $meta,
                $plainTitle,
                $plainDescription,
                $canonicalFile !== '' ? self::buildCanonicalPageUri($canonicalFile) : ''
            );
            $openGraph = $headVars['openGraph'] ?? '';
        }

        $documentTitle = self::buildBlogPageTitle($tplName, $plainTitle, $siteName);

        return array_merge($headVars, [
            'plainTitle' => $plainTitle,
            'plainDescription' => $plainDescription,
            'openGraph' => (string) $openGraph,
            'title' => '<title>' . htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') . '</title>',
            'keywords' => isset($keywords) && (string) $keywords !== ''
                ? '<meta name="keywords" content="' . htmlspecialchars((string) $keywords, ENT_QUOTES, 'UTF-8') . '">'
                : '',
            'description' => $plainDescription !== ''
                ? '<meta name="description" content="' . htmlspecialchars($plainDescription, ENT_QUOTES, 'UTF-8') . '">'
                : '',
        ]);
    }

    /**
     * 生成浏览器标签页标题
     *
     * 首页仅显示站点名；文章与分类在正文标题后追加「 - 站点名」
     *
     * @param string $tplName 页面模板名
     * @param string $plainTitle 页面纯文本标题
     * @param string $siteName 站点名称
     * @return string
     * @access private
     * @static
     */
    private static function buildBlogPageTitle(string $tplName, string $plainTitle, string $siteName): string
    {
        if ($tplName === 'index' || $plainTitle === '') {
            return $plainTitle !== '' ? $plainTitle : $siteName;
        }

        if ($siteName === '' || str_ends_with($plainTitle, ' - ' . $siteName)) {
            return $plainTitle;
        }

        return $plainTitle . ' - ' . $siteName;
    }

    /**
     * 解析并校验文档路径必须位于文档根目录内
     *
     * @param string $candidatePath 拼接后的候选路径
     * @param bool $haltOnFail 失败时是否直接 halt
     * @return string|null 合法的真实路径，失败返回 null
     * @access private
     * @static
     */
    private static function resolveDocFilePath(string $candidatePath, bool $haltOnFail = true): ?string
    {
        $real = realpath($candidatePath);
        if ($real === false || !is_file($real) || !self::isPathUnderDocRoot($real, self::$filesDir)) {
            if ($haltOnFail) {
                showExceptionMsg(
                    'halt',
                    '目录中没有可用文档:' . str_replace(C('CANDYROOT'), '', $candidatePath)
                );
            }

            return null;
        }

        return $real;
    }

    /**
     * 生成动态菜单结构
     * 
     * 获取当前访问路径，规范化处理后调用getNavList方法生成完整的菜单HTML结构
     * 同时返回当前页面的上一篇和下一篇文章信息，用于页面导航功能
     * 
     * @return array 包含菜单HTML、上一篇页面信息和下一篇页面信息的数组
     */
    private static function generateMenuStructure(string $search): array
    {
        $menuHtml = '';

        // 获取当前访问路径
		$currentUri = urldecode(trim((string)(self::requestServer()['REQUEST_URI'] ?? ''), '/'));
        if (stripos($currentUri, '?') !== false) {
            [$currentUri] = explode('?', $currentUri, 2);
        }
        // 剥 .html 伪静态假后缀，保证当前路径与导航链接匹配
        if (str_ends_with($currentUri, '.html')) {
            $currentUri = substr($currentUri, 0, -5);
        }
        $currentPath = '/' . trim($currentUri, '/');
        $currentPath = self::normalizeNavCurrentPath($currentPath);

        $navRoot = '/';

        return self::getNavList($currentPath, $navRoot, $search);
    }

    /**
     * 加载.order文件中的排序规则
     * 
     * 读取指定目录下的.order文件，解析其中的内容作为目录和文件的排序规则
     * 忽略空行和以#开头的注释行，返回有效的排序项数组
     * 
     * @param string $dirPath 要加载.order文件的目录路径
     * @return array 排序规则数组，包含需要按顺序显示的文件名和目录名
     */
    private static function loadOrderList(string $dirPath): array
    {
        $orderFile = $dirPath . '/.order';
        if (!file_exists($orderFile)) return [];
        
        $orderList = [];
        $lines = file($orderFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '#') !== 0) {
                $orderList[] = $line;
            }
        }
        return $orderList;
    }

    /**
     * 目录和文件排序处理
     * 
     * 根据自定义排序规则（.order文件）或默认规则对目录和文件进行排序
     * 首先按照.order文件中指定的顺序排列，如果没有自定义排序则按默认规则（目录在前，文件在后）
     * 
     * @param string $basePath 要排序的基础目录路径
     * @param array $customOrder 自定义排序规则数组
     * @return array 排序后的目录和文件路径数组
     */
    private static function sortDirs(string $basePath, array $customOrder): array
    {
        //规范目录
        $basePath = rtrim($basePath, '/') . '/';
        $path = str_replace(self::$filesDir, '', $basePath);
        $rootItems = scandir($basePath);
        
        //排序规则  有线按照 .order 再按照目录在前文件在后
        $dirs = [];
        $files = [];
        foreach ($rootItems as $item) {
            if ($item === '.' || $item === '..' || $item === '.order') continue;
            $fullPath = $basePath . '/' . $item;
            if (is_dir($fullPath)) {
                $dirs[] = $path . $item;
            } elseif (str_ends_with($item, '.md') && $item !== 'README.md') {
                $files[] = $path . $item;
            }
        }

        // 新增根目录自定义排序逻辑
        if (!empty($customOrder)) {
            // 合并目录和文件进行统一排序
            $allItems = array_merge($dirs, $files);
            $orderedItems = [];

            // 按.order顺序过滤和排序
            foreach ($customOrder as $item) {
                //优先对比文件名
                if (in_array(trim($item). '.md', $allItems)) {
                    $orderedItems[] = trim($item). '.md';
                }else{
                    if (in_array(trim($item), $allItems)) {
                        $orderedItems[] = trim($item);
                    }
                }
            }
            
            // 合并剩余未排序项（保持目录和文件混合状态）
            $remainingItems = array_diff($allItems, $orderedItems);

            // 最终合并排序结果
            $sortedItems = array_merge($orderedItems, $remainingItems);
        } else {
            // 原有排序逻辑保持
            $sortedItems = array_merge($dirs, $files);
        }

        return $sortedItems;
    }

    /**
     * 获取目录导航列表并生成HTML结构
     * 
     * 递归遍历目录结构，生成嵌套的导航菜单HTML，并计算当前页面的上一篇和下一篇文章信息
     * 处理活动状态标识，为当前访问的页面添加active类
     * 
     * @param string $currentPath 当前访问的URL路径
     * @param string $itemPath 当前处理的目录路径
     * @return array 包含导航菜单HTML、上一篇页面信息和下一篇页面信息的数组
     */
    private static function getNavList(string $currentPath, string $itemPath, string $search): array
    {
        $dirPath = rtrim(self::$filesDir, '/') . $itemPath;
        $tmpPage = '';
        $prevPage = '';
        $nextPage = '';
        $menuHtml = '';
        
        //获取排序
        $customOrder = self::loadOrderList($dirPath);
    
        //获取排序后的目录列表
        $sortedItems = self::sortDirs($dirPath, $customOrder);
        
        if(empty($sortedItems)) return ['', $prevPage, $nextPage];
        $tmpPage = ['url'=>'','title'=>''];
        foreach ($sortedItems as $item) {
        	//全路径
            $fullPath = rtrim(self::$filesDir, '/') . '/' . $item;
            
            // 获取基础名称（去除.md后缀）
            $baseName = pathinfo($item, PATHINFO_FILENAME);
            $urlItem = empty(self::$conf) ? $item : self::blogDiskPathToUrlPath($item);
            // 文章链接：Doc 模式（empty conf）带 .html 伪静态后缀，目录不带
            if (stripos($urlItem, '.md') !== false) {
                $itemPath = self::$basePath . substr($urlItem, 0, -3)
                    . (empty(self::$conf) ? '.html' : '');
            } else {
                $itemPath = self::$basePath . $urlItem;
            }
            
            //是目录读取子目录列表
            if(is_dir($fullPath)){
                [$html, $subPrev, $subNext] = self::getNavList($currentPath, '/' . $item, $search);
                // 仅当子目录解析出有效 prev/next 时采用，避免同级其他目录的空结果覆盖
                if (!empty($subPrev)) {
                    $prevPage = $subPrev;
                }
                if (!empty($subNext)) {
                    $nextPage = $subNext;
                }
                $urlSegment = empty(self::$conf) ? $item : self::blogDiskPathToUrlPath($item);
                // 博客分类目录链接带尾斜杠，与列表页 URL 一致，避免 AJAX 误判为文章页
                if (!empty(self::$conf) && !str_ends_with($itemPath, '/')) {
                    $itemPath .= '/';
                }
                $isActive = $urlSegment !== ''
                    && (str_contains($currentPath, '/' . $urlSegment . '/')
                        || str_ends_with(rtrim($currentPath, '/'), '/' . $urlSegment));
                $parentHref = empty(self::$conf) ? $itemPath : '#';
                $menuHtml .= '<li class="has-children' . ($isActive ? ' active' : '') . '">';
                $menuHtml .= '<a'.($isActive ? ' class="checked"' : '').' href="'.$parentHref.'" data-nav-toggle="category">'.$baseName.'</a>';
                $menuHtml .= '<ul class="submenu">';
            	$menuHtml .= $html;
                $menuHtml .= '</ul></li>';
            }else{
                $currentPath = self::normalizeNavCurrentPath($currentPath);

                // 搜索关键词
                if($search !== '' && strpos($baseName, $search) === false) continue;
                
                $isActive = (stripos($currentPath, '.md') !== false ? substr($currentPath, 0, -3) : $currentPath) === $itemPath
                    || (str_ends_with($itemPath, '.html') && (stripos($currentPath, '.html') !== false ? substr($currentPath, 0, -5) : $currentPath) === substr($itemPath, 0, -5));
                $menuHtml .= '<li>';
                $menuHtml .= sprintf('<a'.($isActive ? ' class="checked"' : '').' href="%s">%s</a>', $itemPath, $baseName);
                $menuHtml .= '</li>';
                if($isActive) $prevPage = $tmpPage;
                $tmpBase = str_ends_with((string) ($tmpPage['url'] ?? ''), '.html') ? substr((string) $tmpPage['url'], 0, -5) : (string) ($tmpPage['url'] ?? '');
                $curBase = str_ends_with((string) $currentPath, '.html') ? substr((string) $currentPath, 0, -5) : (string) $currentPath;
                if('/' . basename($tmpBase) === '/' . basename($curBase)) $nextPage = ['url'=>$itemPath,'title'=>$baseName];
            }
            $tmpPage = ['url'=>$itemPath,'title'=>$baseName];
        }
        return [$menuHtml, $prevPage, $nextPage];
    }

    /**
     * 输出 Markdown 源文件下载
     *
     * 校验路径位于文档根目录内、元数据 download:true 及密码保护
     *
     * @param string $filePath 已解析的 .md 绝对路径
     * @return void
     * @access private
     * @static
     */
    private static function sendMarkdownDownload(string $filePath): void
    {
        if (!is_file($filePath) || !str_ends_with($filePath, '.md')) {
            showExceptionMsg('halt', '文档不存在');
        }

        $realFile = realpath($filePath);
        $realRoot = realpath(self::$filesDir);
        if ($realFile === false || $realRoot === false || !self::isPathUnderDocRoot($realFile, $realRoot)) {
            showExceptionMsg('halt', '非法的文档路径');
        }

        $raw = file_get_contents($realFile);
        if ($raw === false) {
            showExceptionMsg('halt', '无法读取文档');
        }

        $meta = \Candy\Extend\Doc\Markdown\Engine::peekMetaFromText($raw);
        if (!\Candy\Extend\Doc\Markdown\Engine::isMetaFeatureEnabled($meta, 'download')) {
            showExceptionMsg('halt', '此文档不允许下载');
        }

        if (!\Candy\Extend\Doc\Markdown\Engine::verifyDocPassword($raw, $meta)) {
            showExceptionMsg('halt', '需要文档密码才能下载');
        }

        $filename = basename($realFile);
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($raw));
        header('X-Content-Type-Options: nosniff');
        echo $raw;
        stop();
    }

    /**
     * 文章页正文首标题下插入封面图
     *
     * @param string $content HTML 正文
     * @param array $mark 解析标记
     * @param string $plainTitle 标题
     * @param mixed $file 文件路径
     * @return string
     * @access private
     * @static
     */
    private static function injectBlogArticleCover(string $content, array $mark, string $plainTitle, mixed $file): string
    {
        if (($mark['password'] ?? false) != false || $content === '') {
            return $content;
        }

        $postFilename = is_string($file) ? pathinfo($file, PATHINFO_FILENAME) : ($plainTitle !== '' ? $plainTitle : 'blog');
        $postMeta = is_array($mark['meta'] ?? null) ? $mark['meta'] : [];
        $coverUrl = \Candy\Extend\Doc\Blog\Index::resolveCoverUrl($postMeta, $postFilename, '960/540');
        $coverAlt = htmlspecialchars($plainTitle !== '' ? $plainTitle : $postFilename, ENT_QUOTES, 'UTF-8');
        $coverEsc = htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8');
        $figure = '<figure class="blog-article-cover"><img src="' . $coverEsc . '" alt="' . $coverAlt . '" loading="lazy"></figure>';

        if (preg_match('/<\/h1>/i', $content)) {
            return (string) preg_replace('/<\/h1>/i', '</h1>' . $figure, $content, 1);
        }

        return $figure . $content;
    }

    /**
     * 处理文档全文搜索请求
     *
     * 返回 JSON：关键词、命中总数、结果列表（标题、URL、摘要）
     *
     * @return void
     * @access private
     * @static
     */
	private static function handleSearchRequest(): void
	{
		debug(0);
		$keyword = trim((string) (self::requestPost()['keyword'] ?? ''));
        $limit = 20;
        $options = [];

        if (!empty(self::$conf)) {
            $options['skip_readme'] = true;
            $limit = max(1, min(50, (int) (self::$conf['search_limit'] ?? 20)));
        } else {
            // Doc 模式：文章链接带 .html 伪静态后缀
            $options['url_suffix'] = '.html';
        }

        $payload = \Candy\Extend\Doc\Site\Search::query(
            self::$filesDir,
            self::$basePath,
            $keyword,
            $limit,
            $options
        );
        Respond::create(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json', 200)->send();
        stop();
    }

    /**
     * 渲染文档站优雅 404 页（复用侧栏/搜索/布局）
     *
     * @return void
     * @access private
     * @static
     */
    private static function serveDocNotFound(): void
    {
        debug(0);
        [$nav, , ] = self::generateMenuStructure('');

        $headAssets = \Candy\Extend\Doc\Site\Assets::headHtml([], 'default');
        \Candy\Extend\Doc\Site\Context::boot([
            'tpl' => '404',
            'page' => [
                'manual_title' => 'Candy框架开发手册',
                'home_url' => '/Doc/',
                'plain_title' => '页面未找到',
                'content' => '<div class="doc-404"><h1 class="doc-404-code">404</h1>'
                    . '<p class="doc-404-text">您访问的文档不存在或已被移动。</p>'
                    . '<p class="doc-404-tip">请通过左侧导航查找，或使用搜索框检索内容。</p>'
                    . '<p class="doc-404-action"><a class="doc-404-link" href="/Doc/">返回文档首页</a></p></div>',
                'doc_meta' => '',
                'nav_html' => (string) ($nav ?? ''),
                'prev_page' => [],
                'next_page' => [],
                'mark' => [],
                'sidebar_enabled' => true,
                'logo_html' => '',
                'doc_theme' => \Candy\Extend\Doc\Site\Assets::selectedTheme(),
                'html_lang' => 'zh-CN',
                'title_tag' => '<title>404 - Candy框架文档</title>',
                'keywords' => '',
                'description_tag' => '',
                'favicon' => '',
                'open_graph' => '',
                'head_assets' => $headAssets,
                'show_print' => false,
                'show_download' => false,
                'download_url' => '',
            ],
        ]);

        http_response_code(404);
        // 走完整引导（layout.tpl.php → shell 含 head/CSS + page 布局），
        // 直接 include page.tpl.php 会缺失 <head> 资源
        $doc = \Candy\Extend\Doc\Site\Context::viewData();
        include C('CANDYROOT') . 'Candy/Class/Doc/Site/layout.tpl.php';
        // layout.tpl.php 将完整 HTML 渲染到 $content（不 echo），此处输出
        echo (string) ($content ?? '');
        stop();
    }

    /**
     * 文档站 RSS 2.0（遍历 Doc/Candy 递归 .md）
     *
     * @return void
     * @access private
     * @static
     */
    private static function serveDocRss(): void
    {
        debug(0);
        $rootDir = rtrim(self::$filesDir, '/');
        $docs = self::collectDocPages($rootDir);

        $host = (string) (($_SERVER['HTTP_HOST'] ?? '') ?: 'localhost');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $origin = $scheme . '://' . $host;
        $base = rtrim(self::$basePath, '/');
        $channelLink = $origin . ($base === '' ? '/' : $base . '/');
        $feedLink = $origin . ($base === '' ? '/rss.xml' : $base . '/rss.xml');

        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $itemsXml = '';
        foreach (array_slice($docs, 0, 50) as $d) {
            $title = (string) ($d['title'] ?? '');
            $link = $origin . (string) ($d['url'] ?? '');
            $desc = (string) ($d['description'] ?? '');
            $dateTs = (int) ($d['mtime'] ?? time());
            $pubDate = gmdate('D, d M Y H:i:s', $dateTs > 0 ? $dateTs : time()) . ' GMT';
            $cat = (string) ($d['category'] ?? '');
            $itemsXml .= "\n    <item>\n"
                . '      <title>' . $esc($title) . "</title>\n"
                . '      <link>' . $esc($link) . "</link>\n"
                . '      <guid isPermaLink="true">' . $esc($link) . "</guid>\n"
                . '      <pubDate>' . $esc($pubDate) . "</pubDate>\n"
                . '      <description>' . $esc($desc) . "</description>\n";
            if ($cat !== '') {
                $itemsXml .= '      <category>' . $esc($cat) . "</category>\n";
            }
            $itemsXml .= '    </item>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . "\n<rss version=\"2.0\">\n  <channel>\n"
            . "\n    <title>" . $esc('Candy 框架开发手册') . "</title>\n"
            . "\n    <link>" . $esc($channelLink) . "</link>\n"
            . "\n    <description>" . $esc('Candy 框架文档：类库 API、框架使用与扩展指南') . "</description>\n"
            . "\n    <language>zh-cn</language>"
            . $itemsXml
            . "\n  </channel>\n</rss>\n";

        Respond::create($xml, 'html', 200)
            ->contentType('application/rss+xml')
            ->send();
        stop();
    }

    /**
     * 文档站 Sitemap（含文章与目录 URL）
     *
     * @return void
     * @access private
     * @static
     */
    private static function serveDocSitemap(): void
    {
        debug(0);
        $rootDir = rtrim(self::$filesDir, '/');
        $docs = self::collectDocPages($rootDir);

        $host = (string) (($_SERVER['HTTP_HOST'] ?? '') ?: 'localhost');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $origin = $scheme . '://' . $host;
        $base = rtrim(self::$basePath, '/');
        $baseUrl = $origin . ($base === '' ? '/' : $base . '/');

        $urls = ['<url><loc>' . htmlspecialchars($baseUrl, ENT_XML1, 'UTF-8') . '</loc></url>'];
        foreach ($docs as $d) {
            $urls[] = '<url><loc>' . htmlspecialchars($origin . (string) ($d['url'] ?? ''), ENT_XML1, 'UTF-8') . '</loc>'
                . '<lastmod>' . gmdate('Y-m-d', (int) ($d['mtime'] ?? time())) . '</lastmod></url>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">"
            . "\n  " . implode("\n  ", $urls)
            . "\n</urlset>\n";

        Respond::create($xml, 'html', 200)
            ->contentType('application/xml')
            ->send();
        stop();
    }

    /**
     * 收集文档站页面（递归 .md，排除 README，附 title/url/描述/mtime）
     *
     * @param string $rootDir 文档根目录
     * @return array<int, array{title:string,url:string,description:string,mtime:int,category:string}>
     * @access private
     * @static
     */
    private static function collectDocPages(string $rootDir): array
    {
        $base = rtrim(self::$basePath, '/');
        $pages = [];
        if (!is_dir($rootDir)) {
            return $pages;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                continue;
            }
            if (strcasecmp($file->getFilename(), 'README.md') === 0) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', str_replace($rootDir, '', $file->getPathname())), '/');
            $urlPath = substr($relative, 0, -3) . '.html';
            $meta = [];
            $raw = (string) @file_get_contents($file->getPathname());
            if (str_starts_with(ltrim($raw), '---')) {
                $meta = \Candy\Extend\Doc\Markdown\Engine::peekMetaFromText($raw);
            }

            $filename = pathinfo($relative, PATHINFO_FILENAME);
            $pages[] = [
                'title' => (string) ($meta['title'] ?? $filename),
                'url' => ($base === '' ? '' : $base) . '/' . trim(str_replace('\\', '/', $urlPath), '/'),
                'description' => (string) ($meta['description'] ?? ''),
                'mtime' => (int) $file->getMTime(),
                'category' => (string) ($meta['category'] ?? (strpos($relative, '/') !== false ? explode('/', $relative)[0] : '')),
            ];
        }

        usort($pages, static fn(array $a, array $b): int => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));

        return $pages;
    }

    /**
     * 输出博客全文搜索独立结果页
     *
     * 公开地址：路径模式 `/Blog/search?q=xx`，独立域名 `/search?q=xx`
     *
     * @param string $keyword 搜索关键词
     * @return void
     * @access private
     * @static
     */
    private static function serveBlogSearch(string $keyword): void
    {
        debug(0);
        $keyword = trim($keyword);
        if (mb_strlen($keyword, 'UTF-8') > 100) {
            $keyword = mb_substr($keyword, 0, 100, 'UTF-8');
        }

        $limit = max(1, min(200, (int) (self::$conf['search_limit'] ?? 20)));
        $payload = \Candy\Extend\Doc\Site\Search::query(
            self::$filesDir,
            self::$basePath,
            $keyword,
            $limit,
            ['skip_readme' => true]
        );

        $blogSiteName = (string) (self::$conf['site_name'] ?? '博客');
        $blogCurrentPath = self::normalizeNavCurrentPath(
            (string) (parse_url((string) (self::requestServer()['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/')
        );
        $headAssets = \Candy\Extend\Doc\Blog\Assets::headHtml(
            [],
            '/Doc/Blog/Static/',
            'list',
            self::$basePath,
            $blogSiteName
        );

        \Candy\Extend\Doc\Blog\Context::boot([
            'files_dir' => self::$filesDir,
            'base_path' => self::$basePath,
            'conf' => self::$conf,
            'tpl' => 'search',
            'current_path' => $blogCurrentPath,
            'page' => [
                'portal' => [],
                'mast_nav' => [],
                'head_assets' => $headAssets,
                'plain_title' => $keyword !== '' ? '搜索：' . $keyword : '全站搜索',
                'title_tag' => ($keyword !== '' ? '搜索「' . $keyword . '」 - ' : '全站搜索 - ') . $blogSiteName,
                'description_tag' => '',
                'keywords' => '',
                'favicon' => '',
                'open_graph' => '',
                'sidebar_enabled' => false,
                'html_lang' => 'zh-CN',
                'doc_theme' => '',
                'search' => [
                    'keyword' => $keyword,
                    'total' => (int) ($payload['total'] ?? 0),
                    'results' => array_slice((array) ($payload['results'] ?? []), 0, 100),
                ],
            ],
        ]);

        $blog = \Candy\Extend\Doc\Blog\Context::viewData();
        include C('CANDYROOT') . 'Doc/Blog/views/search.tpl.php';
        stop();
    }

    /**
     * 输出博客 RSS 2.0 并终止请求
     *
     * 公开地址：路径模式 `/Blog/rss.xml`，独立域名 `/rss.xml`
     *
     * @return void
     * @access private
     * @static
     */
    private static function serveBlogRss(): void
    {
        debug(0);
        $xml = \Candy\Extend\Doc\Blog\Feed::buildRssXml(
            self::$filesDir,
            self::$basePath,
            self::$conf
        );
        Respond::create($xml, 'html', 200)
            ->contentType('application/rss+xml')
            ->send();
        stop();
    }

    /**
     * 输出博客 Sitemap 并终止请求
     *
     * 公开地址：路径模式 `/Blog/sitemap.xml`，独立域名 `/sitemap.xml`
     *
     * @return void
     * @access private
     * @static
     */
    private static function serveBlogSitemap(): void
    {
        debug(0);
        $xml = \Candy\Extend\Doc\Blog\Feed::buildSitemapXml(
            self::$filesDir,
            self::$basePath,
            self::$conf
        );
        Respond::create($xml, 'html', 200)
            ->contentType('application/xml')
            ->send();
        stop();
    }

    /**
     * 处理博客评论 API（提交 / 验证码图）
     *
     * Header：X-Requested-With: BlogCommentRequest
     * Query/Body：action=submit|captcha
     *
     * @return void
     * @access private
     * @static
     */
	private static function handleBlogCommentRequest(): void
	{
		debug(0);
		$get = self::requestGet();
		$post = self::requestPost();
		$server = self::requestServer();
		$action = strtolower(trim((string) ($get['action'] ?? $post['action'] ?? 'submit')));

        if ($action === 'captcha') {
            if (function_exists('verificationCode')) {
                verificationCode('', '', 38, 100);
            }
            stop();
        }

		if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            Respond::create(json_encode([
                'code' => 1,
                'message' => '请使用 POST 提交',
            ], JSON_UNESCAPED_UNICODE), 'json', 405)->send();
            stop();
        }

		$input = $post;
		if ($input === []) {
			$raw = I('raw');
            $decoded = json_decode($raw ?: '', true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        $result = \Candy\Extend\Doc\Blog\Comment::submit(self::$filesDir, $input, self::$conf);
        Respond::create(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json', 200)->send();
        stop();
    }

    /**
     * 处理门户 feed 分页请求
     *
     * 首页「加载更多」与分类/标签列表无限滚动共用；返回 HTML 片段与下一页 offset
     *
     * @return void
     * @access private
     * @static
     */
	private static function handleBlogFeedRequest(): void
	{
		debug(0);
		$get = self::requestGet();
		$scope = strtolower(trim((string) ($get['scope'] ?? 'home')));
        if ($scope !== 'home' && $scope !== 'category' && $scope !== 'tag') {
            $scope = 'home';
        }
		$offset = max(0, (int) ($get['offset'] ?? 0));
		$limit = max(1, min(24, (int) ($get['limit'] ?? 6)));
		$category = trim((string) ($get['category'] ?? ''));
		$tag = trim((string) ($get['tag'] ?? ''));

        if ($scope === 'category' && $category === '') {
            Respond::create(json_encode([
                'code' => 1,
                'message' => '缺少分类参数',
                'data' => null,
            ], JSON_UNESCAPED_UNICODE), 'json', 400)->send();
            stop();
        }
        if ($scope === 'tag' && $tag === '') {
            Respond::create(json_encode([
                'code' => 1,
                'message' => '缺少标签参数',
                'data' => null,
            ], JSON_UNESCAPED_UNICODE), 'json', 400)->send();
            stop();
        }

        $page = \Candy\Extend\Doc\Blog\Index::listPostsPage(
            self::$filesDir,
            self::$basePath,
            $offset,
            $limit,
            $scope === 'category' ? $category : null,
            $scope === 'tag' ? $tag : null
        );

        $imgBase = '/Doc/Blog/Static/img/';
        $html = ($scope === 'category' || $scope === 'tag')
            ? \Candy\Extend\Doc\Blog\Index::renderListCardsHtml($page['items'], $imgBase)
            : \Candy\Extend\Doc\Blog\Index::renderHomeFeedCardsHtml($page['items'], $imgBase);

        Respond::create(json_encode([
            'code' => 0,
            'message' => '',
            'data' => [
                'html' => $html,
                'count' => count($page['items']),
                'has_more' => $page['has_more'],
                'next_offset' => $page['next_offset'],
                'total' => $page['total'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json', 200)->send();
        stop();
    }
}
