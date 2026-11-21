<?php
declare(strict_types=1);

namespace Apidoc;

use \Apidoc\parses\{ParseApiDetail,ParseCodeTemplate,ParseApiMenus,ParseMarkdown};
use \Apidoc\utils\{ApiShare,Cache,ConfigProvider,DirAndFile,Helper,Lang,Request};
use \Apidoc\exception\ErrorException;
use Candy\Extend\OpenApi\DocumentBuilder;

/**
 * Apidoc 核心控制器类
 * 
 * 该类是 Apidoc 文档系统的核心控制器，负责处理所有文档相关的请求，
 * 包括文档配置获取、权限验证、API 菜单和详情获取、Markdown 文档处理等功能。
 */
class Controller
{
    /**
     * 配置信息
     * 
     * 存储 Apidoc 的完整配置信息
     * 
     * @var array Apidoc 配置数组
     */
    protected $config;

    /**
     * 请求参数
     * 
     * 存储当前请求的所有参数
     * 
     * @var array 请求参数数组
     */
    protected $requestParams = [];

    /**
     * 语言设置
     * 
     * 当前文档的语言设置
     * 
     * @var string 语言代码
     */
    protected $lang = "";

    /**
     * 初始化控制器
     * 
     * 加载配置、获取请求参数、设置语言并根据需要进行权限验证
     * 
     * @param bool $checkAuth 是否进行权限验证
     * 
     * @throws ErrorException 如果 Apidoc 被禁用则抛出异常
     * 
     * @return void
     */
    public function init($checkAuth = false)
    {
        //关闭调试
        debug(0);
        
        // 加载配置
        $this->config = ConfigProvider::get();
        
        // 检查 Apidoc 是否启用
        if (isset($this->config['enable']) && $this->config['enable'] === false) {
            throw new ErrorException("apidoc close");
        }
        
        // 获取请求参数
        if (!empty($this->config['request_params'])) {
            $this->requestParams = $this->config['request_params'];
        } else {
            $this->requestParams = (new Request())->param();
        }
        
        // 设置语言
        if (!empty($this->requestParams['lang']) && !empty($this->config['lang_register_function'])) {
            $this->lang = $this->requestParams['lang'];
            $this->config['lang_register_function']($this->lang);
        }
        
        // 进行权限验证
        if ($checkAuth) {
            (new Auth($this->config))->checkAuth($this->requestParams);
        }
    }

    /**
     * 验证权限
     * 
     * 根据请求参数进行权限验证，支持分享链接权限验证
     * 
     * @return array|null 如果是分享链接验证，返回分享数据；否则返回 null
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    protected function checkAuth()
    {
        $config = $this->config;
        $params = $this->requestParams;
        
        // 分享链接权限验证
        if (!empty($params['shareKey'])) {
            $shareData = (new ApiShare())->checkShareAuth($config, $params);
            $appKey = !empty($params['appKey']) ? $params['appKey'] : "";
            
            // 验证应用权限
            if (!empty($shareData['appKeys']) && !in_array($appKey, $shareData['appKeys'])) {
                throw new ErrorException("share not exists");
            }
            
            return $shareData;
        } else {
            // 常规权限验证
            (new Auth($config))->checkAuth($params);
        }
    }


    /**
     * 获取前端配置
     * 
     * 获取 Apidoc 前端需要的配置信息，支持分享链接权限验证
     * 
     * @return string JSON 格式的配置信息
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    public function getConfig()
    {
        $this->init(false);
        $params = $this->requestParams;
        
        // 分享链接权限验证
        if (!empty($params['shareKey'])) {
            $shareData = (new ApiShare())->checkShareAuth($this->config, $params);
            
            // 根据分享数据获取相应的前端配置
            if (!empty($shareData['appKeys'])) {
                $config = ConfigProvider::getFeConfig($shareData['appKeys']);
            } else {
                $config = ConfigProvider::getFeConfig();
            }
        } else {
            // 无 token 时仍返回前端配置（含 auth.enable），以便弹出登录框
            // 有 token 时校验有效性，避免脏 token 继续使用
            if (!empty($params['token'])) {
                (new Auth($this->config))->checkAuth($params);
            }
            $config = ConfigProvider::getFeConfig();
        }
	
        return Helper::showJson(0, "", $config);
    }

    /**
     * 验证密码并生成访问令牌
     * 
     * 验证用户密码并生成访问令牌，支持分享链接密码验证
     * 
     * @return string JSON 格式的响应，包含生成的访问令牌
     * 
     * @throws ErrorException 如果密码为空或验证失败则抛出异常
     */
    public function verifyAuth()
    {
        $this->init();
        $config = $this->config;
        $params = $this->requestParams;
        
        // 检查密码是否为空
        if (empty($params['password'])) {
            throw new ErrorException("password not found");
        }
        
        $appKey = !empty($params['appKey']) ? $params['appKey'] : "";
        
        // 分享链接密码验证
        if (!empty($params['shareKey'])) {
            $shareData = (new ApiShare())->getShareDetailByKey($params['shareKey']);
            
            // 验证分享密码
            if (!empty($shareData['password']) && $params['password'] === md5($shareData['password'])) {
                $hasAuth = (new Auth($config))->createToken($params['password']);
            } else {
                throw new ErrorException("password error");
            }
        } else {
            // 验证应用密钥和认证配置
            if (!$appKey && !(!empty($config['auth']) && $config['auth']['enable'])) {
                throw new ErrorException("password error");
            }
            
            // 常规密码验证
            $hasAuth = (new Auth($config))->verifyAuth($params['password'], $appKey);
        }
        
        // 返回生成的访问令牌
        $res = [
            "token" => $hasAuth
        ];
        return Helper::showJson(0, "", $res);
    }

    /**
     * 获取API文档菜单
     * 
     * 根据应用密钥获取API文档菜单，支持分享链接权限验证和API过滤
     * 
     * @return string JSON 格式的API菜单数据
     * 
     * @throws ErrorException 如果应用密钥为空或权限验证失败则抛出异常
     */
    public function getApiMenus()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 检查应用密钥是否为空
        if (empty($params['appKey'])) {
            throw new ErrorException("appkey not found");
        }
        
        $appKey = $params['appKey'];
        
        // 权限验证
        $shareData = $this->checkAuth();
        
        // 获取当前应用配置
        $currentAppConfig = Helper::getCurrentAppConfig($appKey);
        $currentApp = $currentAppConfig['appConfig'];
        
        // 获取API菜单数据
        $apiData = $this->getApiMenusByAppKey($appKey);
        $groups = !empty($currentApp['groups']) ? $currentApp['groups'] : [];
        
        // 如果是API类型的分享链接，根据分享数据过滤API菜单
        if (!empty($params['shareKey']) && $shareData['type'] == 'api') {
            $apiData['data'] = Helper::filterTreeNodesByKeys($apiData['data'], $shareData['apiKeys'], 'menuKey');
        }
        
        // 构建响应数据
        $json = [
            'data' => $apiData['data'],
            'app' => $currentApp,
            'groups' => $groups,
            'tags' => $apiData['tags'],
        ];
        
        return Helper::showJson(0, "", $json);
    }

    /**
     * 根据应用密钥获取API菜单
     * 
     * 根据应用密钥获取API菜单数据，支持缓存
     * 
     * @param string $appKey 应用密钥
     * 
     * @return array API菜单数据
     */
    protected function getApiMenusByAppKey($appKey)
    {
        $config = $this->config;
        
        // 如果缓存启用，则尝试从缓存获取数据
        if (!empty($config['cache']) && $config['cache']['enable']) {
            $cacheKey = Helper::getCacheKey('apiMenu', $appKey, $this->lang);
            $cacheData = (new Cache())->get($cacheKey);
            
            // 如果缓存存在且不强制重新加载，则使用缓存数据
            if ($cacheData && empty($this->requestParams['reload'])) {
                $apiData = $cacheData;
            } else {
                // 生成数据并缓存
                $apiData = (new ParseApiMenus($config))->renderApiMenus($appKey);
                (new Cache())->set($cacheKey, $apiData);
            }
        } else {
            // 生成数据
            $apiData = (new ParseApiMenus($config))->renderApiMenus($appKey);
        }
        
        return $apiData;
    }


    /**
     * 获取所有API文档菜单
     * 
     * 获取所有应用的API文档菜单数据，需要权限验证
     * 
     * @return string JSON 格式的所有API菜单数据
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    public function getAllApiMenus()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 处理应用配置
        $configApps = Helper::handleAppsConfig($config['apps'], false, $config);
        
        // 获取所有应用的API菜单数据
        $data = ApiShare::getAppShareApis($config, $configApps, "", [], false);
        
        return Helper::showJson(0, "", $data);
    }

    /**
     * 获取API接口明细
     * 
     * 根据接口路径获取API接口的详细信息，支持缓存和权限验证
     * 
     * @return string JSON 格式的API接口明细数据
     * 
     * @throws ErrorException 如果接口路径为空或权限验证失败则抛出异常
     */
    public function getApiDetail()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 检查接口路径是否为空
        if (empty($params['path'])) {
            throw new ErrorException("path not found");
        }
        
        $appKey = !empty($params['appKey']) ? $params['appKey'] : "";
        $apiKey = urldecode($params['path']);
        
        // 权限验证
        $this->checkAuth();
        
        // 如果缓存启用，则尝试从缓存获取数据
        if (!empty($config['cache']) && $config['cache']['enable']) {
            $cacheKey = Helper::getCacheKey('apiDetail', $appKey, $this->lang, $params['path']);
            $cacheData = (new Cache())->get($cacheKey);
            
            // 如果缓存存在且不强制重新加载，则使用缓存数据
            if ($cacheData && empty($params['reload'])) {
                $res = $cacheData;
            } else {
                // 生成数据并缓存
                $res = (new ParseApiDetail($config))->renderApiDetail($appKey, $apiKey);
                if (is_array($res)) {
                    (new Cache())->set($cacheKey, $res);
                }
            }
        } else {
            // 生成数据
            $res = (new ParseApiDetail($config))->renderApiDetail($appKey, $apiKey);
        }

        // 无文档意图 / 父类方法 / NotParse：parse 返回 false，禁止对 false 写数组键（PHP 8.4 弃用）
        if (!is_array($res) || $res === []) {
            throw new ErrorException('api detail not found', [
                'path' => $apiKey,
            ]);
        }

        // 添加应用密钥到响应数据
        $res['appKey'] = $appKey;

        return Helper::showJson(0, "", $res);
    }


    /**
     * 获取Markdown文档菜单
     * 
     * 获取Markdown文档的菜单结构，支持指定应用和权限验证
     * 
     * @return string JSON 格式的Markdown文档菜单数据
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    public function getMdMenus()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        $appKey = "";
        
        // 如果指定了应用密钥，则使用指定的应用
        if (!empty($params['appKey'])) {
            $appKey = $params['appKey'];
        }
        
        // 权限验证
        $this->checkAuth();
        
        // 获取Markdown文档菜单
        $docs = (new ParseMarkdown($config))->getDocsMenu($appKey, $this->lang);
        
        return Helper::showJson(0, "", $docs);
    }

    /**
     * 获取Markdown文档内容
     * 
     * 根据文档路径获取Markdown文档的内容，支持权限验证
     * 
     * @return string JSON 格式的Markdown文档内容
     * 
     * @throws ErrorException 如果文档路径或应用密钥为空则抛出异常
     */
    public function getMdDetail()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 权限验证
        $this->checkAuth();
        
        try {
            // 检查文档路径是否为空
            if (empty($params['path'])) {
                throw new ErrorException("mdPath not found");
            }
            
            // 检查应用密钥是否为空
            if (empty($params['appKey'])) {
                throw new ErrorException("appkey not found");
            }

            // 获取文档内容
            $path = urldecode($params['path']);
            $content = (new ParseMarkdown($config))->getContent($params['appKey'], $path, $this->lang);
            
            // 构建响应数据
            $res = [
                'content' => $content,
            ];
            
            return Helper::showJson(0, "", $res);

        } catch (ErrorException $e) {
            // 异常处理
            return Helper::showJson($e->getCode(), $e->getMessage());
        }
    }


    /**
     * 创建代码生成器
     * 
     * 创建 API 接口的代码生成器，需要权限验证
     * 
     * @return string JSON 格式的代码生成器数据
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    public function createGenerator()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 创建代码生成器
        $res = (new generator\Index($config))->create($params);
        
        return Helper::showJson(0, "", $res);
    }

    /**
     * 删除所有API接口缓存
     * 
     * 删除所有API接口的缓存文件，需要权限验证
     * 
     * @return string JSON 格式的响应，包含被删除的缓存目录路径
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    public function cancelAllCache()
    {
        $this->init(true);
        $config = $this->config;
        
        // 构建缓存目录路径
        $path = APIDOC_STORAGE_PATH . $config['cache']['folder']. '/apis';
        
        // 删除缓存目录
        $res = DirAndFile::deleteDir($path);
        
        return Helper::showJson(0, "", $path);
    }

    /**
     * 生成所有API接口缓存
     * 
     * 为所有应用的API接口生成缓存，包括菜单和接口明细，需要权限验证
     * 
     * @return string JSON 格式的响应，表示缓存生成是否成功
     * 
     * @throws ErrorException 如果权限验证失败或缓存生成过程中发生错误则抛出异常
     * @throws \ReflectionException 如果API反射过程中发生错误则抛出异常
     */
    public function createAllCache()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 获取所有应用配置
        $apps = Helper::getAllApps($config['apps']);
        $cache = new Cache();
        
        // 删除旧的缓存目录
        DirAndFile::deleteDir(APIDOC_STORAGE_PATH . $config['cache']['folder'] . '/' . 'apis');
        
        if (!empty($apps) && count($apps)) {
            try {
                // 遍历所有应用
                foreach ($apps as $app) {
                    // 获取应用密钥
                    $appKey = $app['appKey'];
                    
                    // 生成并缓存API菜单
                    $controllerData = (new ParseApiMenus($config))->renderApiMenus($appKey, true);
                    
                    // 如果有控制器数据，则生成并缓存API接口明细
                    if (!empty($controllerData['data']) && count($controllerData['data'])) {
                        foreach ($controllerData['data'] as $controller) {
                            if (!empty($controller['children']) && count($controller['children'])) {
                                foreach ($controller['children'] as $item) {
                                    if (!empty($item['url']) && !empty($item['menuKey'])) {
                                        // 生成API接口明细
                                        $apiDetail = (new ParseApiDetail($config))->renderApiDetail($appKey, urldecode($item['menuKey']));
                                        
                                        // 缓存API接口明细
                                        $apiDetailCacheKey = Helper::getCacheKey('apiDetail', $appKey, $this->lang, $item['menuKey']);
                                        $cache->set($apiDetailCacheKey, $apiDetail);
                                    }
                                }
                            }
                        }
                    }
                    
                    // 缓存API菜单
                    $cacheKey = Helper::getCacheKey('apiMenu', $appKey, $this->lang);
                    $cache->set($cacheKey, $controllerData);
                }
            } catch (\ReflectionException $e) {
                // 如果发生反射异常，则删除缓存目录并重新抛出异常
                DirAndFile::deleteDir(APIDOC_STORAGE_PATH . $config['cache']['folder'] . '/' . 'apis');
                throw new ErrorException($e->getMessage());
            }
        }
        
        return Helper::showJson(0, "", true);
    }

    /**
     * 生成代码模板内容
     * 
     * 根据请求参数生成API接口的代码模板，需要权限验证
     * 
     * @return string JSON 格式的响应，包含生成的代码模板
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    public function renderCodeTemplate()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 生成代码模板
        $code = (new ParseCodeTemplate($config))->renderCode($params);
        
        return Helper::showJson(0, "", [
            'code' => $code
        ]);
    }


    /**
     * 添加接口分享
     * 
     * 添加API接口的分享记录，支持应用分享和API分享，需要权限验证
     * 
     * @return string JSON 格式的响应，包含分享记录的详细信息
     * 
     * @throws ErrorException 如果必要参数为空则抛出异常
     */
    public function addApiShare()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 检查分享名称是否为空
        if (empty($params['name'])) {
            throw new ErrorException('field not found', ['field' => 'name']);
        }
        
        // 检查分享类型是否为空
        if (empty($params['type'])) {
            throw new ErrorException('field not found', ['field' => 'type']);
        }
        
        // 如果是应用分享，检查应用密钥是否为空
        if ($params['type'] == 'app' && empty($params['appKeys'])) {
            throw new ErrorException('field not found', ['field' => 'appKeys']);
        }
        
        // 如果是API分享，检查API密钥是否为空
        if ($params['type'] == 'api' && empty($params['apiKeys'])) {
            throw new ErrorException('field not found', ['field' => 'apiKeys']);
        }
        
        // 添加分享记录
        $res = (new ApiShare())->addApiShare($params);
        
        return Helper::showJson(0, "", $res);
    }

    /**
     * 获取接口分享分页列表
     * 
     * 获取API接口分享记录的分页列表，需要权限验证
     * 
     * @return string JSON 格式的分页列表数据
     * 
     * @throws ErrorException 如果权限验证失败则抛出异常
     */
    public function getApiShareList()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 设置分页参数
        $pageIndex = !empty($params['pageIndex']) ? $params['pageIndex'] : 1;
        $pageSize = 5;
        
        // 获取分页列表
        $res = (new ApiShare())->getSharePageList($config, $pageIndex, $pageSize);
        
        return Helper::showJson(0, "", $res);
    }

    /**
     * 获取接口分享记录明细
     * 
     * 根据分享密钥获取API接口分享记录的详细信息，需要权限验证
     * 
     * @return string JSON 格式的分享记录明细数据
     * 
     * @throws ErrorException 如果分享密钥为空则抛出异常
     */
    public function getApiShareDetail()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 检查分享密钥是否为空
        if (empty($params['key'])) {
            throw new ErrorException('field not found', ['field' => 'key']);
        }
        
        // 获取分享记录明细
        $cacheData = (new ApiShare())->getShareDetailByKey($params['key']);
        
        return Helper::showJson(0, "", $cacheData);
    }

    /**
     * 删除接口分享记录
     * 
     * 根据分享密钥删除API接口分享记录，需要权限验证
     * 
     * @return string JSON 格式的响应，表示删除是否成功
     * 
     * @throws ErrorException 如果分享密钥为空则抛出异常
     */
    public function deleteApiShare()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 检查分享密钥是否为空
        if (empty($params['key'])) {
            throw new ErrorException('field not found', ['field' => 'key']);
        }
        
        // 获取分享记录的缓存键
        $cacheKey = ApiShare::getShareCacheKey($params['key']);
        
        // 删除分享记录
        $res = (new Cache())->delete($cacheKey);
        
        return Helper::showJson(0, "", $res);
    }

    /**
     * 处理接口分享操作
     * 
     * 处理API接口分享的相关操作，如复制分享链接等，需要权限验证
     * 
     * @return string JSON 格式的响应，表示操作是否成功
     * 
     * @throws ErrorException 如果分享密钥或操作索引为空则抛出异常
     */
    public function handleApiShareAction()
    {
        $this->init(true);
        $config = $this->config;
        $params = $this->requestParams;
        
        // 检查分享密钥是否为空
        if (empty($params['key'])) {
            throw new ErrorException('field not found', ['field' => 'key']);
        }
        
        // 检查操作索引是否为空
        if (!isset($params['index'])) {
            throw new ErrorException('field not found', ['field' => 'index']);
        }
        
        // 处理分享操作
        $res = (new ApiShare())->handleApiShareAction($config, $params['key'], $params['index']);
        
        return Helper::showJson(0, "", $res);
    }

    /**
     * 导出 OpenAPI 3.0 文档
     *
     * 与 CLI openapi:export 同源；默认校验 Apidoc token（openapi.public=true 可匿名）
     * 响应体为完整 OAS JSON，不用 code/message 信封包裹
     *
     * @return mixed JSON 响应
     *
     * @throws ErrorException 参数或鉴权失败
     */
    public function getOpenApi()
    {
        $this->init(false);
        $config = $this->config;
        $params = $this->requestParams;
        $openapiCfg = is_array($config['openapi'] ?? null) ? $config['openapi'] : [];

        if (isset($openapiCfg['enable']) && $openapiCfg['enable'] === false) {
            throw new ErrorException('OpenAPI export disabled');
        }

        // 与 apiMenus 对齐：未声明 public 时走文档站鉴权
        if (empty($openapiCfg['public'])) {
            $this->checkAuth();
        }

        $appKey = !empty($params['appKey']) ? (string)$params['appKey'] : 'admin';
        $options = [];
        if (!empty($openapiCfg['server']) && is_string($openapiCfg['server'])) {
            $options['server'] = $openapiCfg['server'];
        }

        try {
            $doc = DocumentBuilder::build($appKey, $options);
        } catch (\Throwable $e) {
            throw new ErrorException($e->getMessage());
        }

        if (!empty($params['download'])) {
            $filename = 'openapi-' . preg_replace('/[^a-z0-9_-]/i', '', $appKey) . '.json';
            if (function_exists('header')) {
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            }
        }

        // 直接返回 OAS 根对象（非 Apidoc 业务信封）
        return function_exists('json')
            ? json($doc)
            : json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}