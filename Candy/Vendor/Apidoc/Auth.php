<?php
declare(strict_types = 1);

namespace Apidoc;

use \Apidoc\exception\ErrorException;
use \Apidoc\utils\{ConfigProvider,Helper};

/**
 * Apidoc 认证类
 * 
 * 该类负责 Apidoc 文档系统的认证功能，包括密码验证、令牌生成和验证等
 */
class Auth
{
    /**
     * 认证配置
     * 
     * 存储认证相关的配置信息，如密钥、过期时间等
     * 
     * @var array 认证配置数组
     */
    protected $authConfig = [];

    /**
     * 构造函数
     * 
     * 初始化认证配置
     * 
     * @param array $config Apidoc 配置信息
     */
    public function __construct($config)
    {
        // 获取认证配置
        $authConfig = !empty($config['auth'])?$config['auth']:[];
        
        // 设置默认密钥
        if (empty($authConfig['secret_key'])){
            $authConfig['secret_key'] = "apidoc#code";
        }
        
        // 设置默认过期时间（24小时）
        if (empty($authConfig['expire'])){
            $authConfig['expire'] = 86400;
        }
        
        $this->authConfig = $authConfig;
    }

    /**
     * 验证密码是否正确
     * 
     * 验证用户输入的密码是否正确，并生成访问令牌
     * 
     * @param string $password 用户输入的密码（MD5加密后）
     * @param string $appKey 应用密钥
     * 
     * @return false|string 如果密码正确则返回生成的访问令牌，否则返回 false
     * 
     * @throws ErrorException 如果密码验证失败则抛出异常
     */
    public function verifyAuth(string $password, string $appKey)
    {
        $authConfig = $this->authConfig;
        
        // 如果提供了应用密钥，则尝试使用应用密码验证
        if (!empty($appKey)) {
            $currentAppConfig = Helper::getCurrentAppConfig($appKey);
            $currentApp  = $currentAppConfig['appConfig'];
            
            // 如果应用配置了密码，则使用应用密码验证
            if (!empty($currentApp) && !empty($currentApp['password'])) {
                // 验证应用密码
                if (md5($currentApp['password']) === $password) {
                    return $this->createToken($currentApp['password']);
                }
                throw new ErrorException("password error");
            }
        }
        
        // 如果全局认证启用，则使用全局密码验证
        if ($authConfig['enable']) {
            // 验证全局密码
            if (md5($authConfig['password']) === $password) {
                return $this->createToken($authConfig['password']);
            }
            throw new ErrorException("password error");
        }
        
        return false;
    }

    /**
     * 验证token是否可用
     * 
     * 验证访问令牌是否有效，支持应用级令牌和全局令牌验证
     * 
     * @param array $params 请求参数，包含 token 和 appKey
     * 
     * @return bool 如果令牌有效则返回 true
     * 
     * @throws ErrorException 如果令牌不存在或无效则抛出异常
     */
    public function checkAuth($params=[]): bool
    {
        $authConfig = $this->authConfig;
        $token = !empty($params['token'])?$params['token']:"";
        $appKey = !empty($params['appKey'])?$params['appKey']:"";
        
        // 如果提供了应用密钥，则尝试使用应用级令牌验证
        if (!empty($appKey)) {
            $currentAppConfig = Helper::getCurrentAppConfig($appKey);
            $currentApp  = $currentAppConfig['appConfig'];
            
            // 如果应用配置了密码，则必须使用应用级令牌
            if (!empty($currentApp) && !empty($currentApp['password'])) {
                if (empty($token)) {
                    throw new ErrorException("token not found");
                }
                // 验证应用级令牌
                if ($this->checkToken($token, $currentApp['password'])) {
                    return true;
                } else {
                    throw new ErrorException("token error");
                }
            } else if (empty($authConfig['enable'])) {
                // 如果全局认证未启用，则直接返回 true
                return true;
            }
        }
        
        // 全局令牌验证
        if($authConfig['enable'] && empty($token)){
            throw new ErrorException("token not found");
        }else if (!empty($token) && !$this->checkToken($token, "")  && $authConfig['enable']) {
            throw new ErrorException("token error");
        }
        
        return true;
    }


    /**
     * 获取令牌代码
     * 
     * 生成令牌的代码部分，使用双重 MD5 加密密码
     * 
     * @param string $password 原始密码
     * 
     * @return string 令牌代码
     */
    protected static function getTokenCode(string $password): string
    {
        return md5(md5($password));
    }


    /**
     * 创建访问令牌
     * 
     * 根据密码创建访问令牌，包含令牌代码和过期时间
     * 
     * @param string $password 原始密码
     * @param int|null $expire 过期时间（秒），默认使用配置中的过期时间
     * 
     * @return string 生成的访问令牌
     */
    public function createToken(string $password, ?int $expire = null): string
    {
        $authConfig = $this->authConfig;
        
        // 如果未指定过期时间，则使用配置中的过期时间
        if ($expire === null) {
            $expire = $authConfig['expire'];
        }
        
        // 构建令牌数据
        $data = [
            'key'=>static::getTokenCode($password),
            'expire'=>time()+$expire
        ];
        
        // 编码令牌数据
        $code = json_encode($data);
        
        // 加密令牌
        return static::handleToken($code, "CE",$authConfig['secret_key']);
    }

    /**
     * 验证访问令牌
     * 
     * 验证访问令牌是否有效，包括令牌代码验证和过期时间验证
     * 
     * @param string $token 访问令牌
     * @param string $password 原始密码，如果为空则使用全局密码
     * 
     * @return bool 如果令牌有效则返回 true，否则返回 false
     */
    public function checkToken(string $token, string $password): bool
    {
        $authConfig = $this->authConfig;
        
        // 如果密码为空，则使用全局密码
        if (empty($password)){
            $password = $authConfig['password'];
        }
        
        // 解密令牌
        $decode = static::handleToken($token, "DE",$authConfig['secret_key']);
        $deData = json_decode($decode,true);

        // 验证令牌代码和过期时间
        if (!empty($deData['key']) && $deData['key'] === static::getTokenCode($password) && !empty($deData['expire']) && $deData['expire']>time()){
            return true;
        }

        return false;
    }



    /**
     * 处理访问令牌
     * 
     * 对访问令牌进行加密或解密操作
     * 
     * @param string $string 要处理的字符串
     * @param string $operation 操作类型，'DE' 表示解密，'CE' 表示加密
     * @param string $key 密钥
     * @param int $expiry 过期时间（秒），仅在加密时有效
     * 
     * @return string 处理后的字符串
     */
    protected static function handleToken(string $string, string $operation = 'DE', string $key = '', int $expiry = 0):string
    {
        $ckey_length   = 4;
        $key           = md5($key);
        $keya          = md5(substr($key, 0, 16));
        $keyb          = md5(substr($key, 16, 16));
        $keyc          = $ckey_length ? ($operation == 'DE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';
        $cryptkey      = $keya . md5($keya . $keyc);
        $key_length    = strlen($cryptkey);
        $string        = $operation == 'DE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;
        $string_length = strlen($string);
        $result        = '';
        $box           = range(0, 255);
        $rndkey        = [];
        
        // 生成随机密钥
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }
        
        // 打乱盒
        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        
        // 加密或解密数据
        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result  .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }
        
        // 解密操作
        if ($operation == 'DE') {
            $subNumber = (int)substr($result, 0, 10);
            if (($subNumber == 0 || $subNumber - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26) . $keyb), 0, 16)) {
                return substr($result, 26);
            } else {
                return '';
            }
        } else {
            // 加密操作
            return $keyc . str_replace('=', '', base64_encode($result));
        }
    }

}