<?php
/**
 * 限流器注解类
 * 
 * 用于在Candy框架中定义和处理请求限流，支持多种限流策略和自定义限流键。
 * 该类实现了PHP的Attribute特性，可应用于控制器方法。
 * 
 * 主要功能：
 * - 支持基于用户ID、会话ID、IP地址的限流
 * - 支持自定义限流键和回调函数
 * - 可配置限流次数和时间窗口
 * - 支持自定义限流消息和异常类型
 * 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-01-22
 * 
 * @example
 * ```php
 * // 基本用法 - 限制每分钟10次请求
 * #[RateLimiter(limit: 10)]
 * public function index() {}
 * 
 * // 自定义时间窗口 - 每分钟100次请求
 * #[RateLimiter(limit: 100, ttl: 60, key: RateLimiter::UID)]
 * public function list() {}
 * 
 * // 会话限流 - 每人每分钟只能发1次邮件
 * #[RateLimiter(limit: 1, ttl: 60, key: RateLimiter::SID, message: '每人每分钟只能发1次邮件')]
 * public function sendEmail() {}
 * 
 * // 每天限流 - 每个用户每天只能领取一次优惠券
 * #[RateLimiter(limit: 1, ttl: 24*60*60, key: RateLimiter::UID, message: '每个用户每天只能领取一次优惠券')]
 * public function receiveCoupon() {}
 * 
 * // 自定义回调 - 每个手机号一天最多5条短信
 * #[RateLimiter(limit: 5, ttl: 24*60*60, key: [UserController::class, 'getMobile'], message: '每个手机号一天最多5条短信')]
 * public function sendSms() {}
 * 
 * // 自定义异常 - IP限流并抛出RuntimeException
 * #[RateLimiter(limit: 1, ttl: 10, key: RateLimiter::IP, message: '频率受限', exception: RuntimeException::class)]
 * public function sensitiveOperation() {}
 * ```
 * 
 * @best-practice
 * 1. **合理设置限流参数**: 根据接口重要性和资源消耗设置合适的limit和ttl
 * 2. **选择合适的限流键**: 敏感操作建议使用UID，一般操作可使用IP
 * 3. **明确的错误消息**: 设置清晰的message便于用户理解
 * 4. **异常类型选择**: 根据业务场景选择合适的异常类型
 * 5. **回调函数使用**: 当需要基于复杂逻辑生成限流键时使用回调函数
 */
declare(strict_types=1);

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 限流器
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class RateLimiter extends AbstractAnnotation
{
    /**
     * 限流键类型常量
     */
    public const UID = 'uid'; // 用户ID
    public const SID = 'sid'; // 会话ID
    public const IP = 'ip';   // IP地址

    /**
     * @var string
     */
    protected static string $prefix = 'rate-limiter';
    
    /**
     * 限流次数
     * @var int
     */
    private int $limit;
    
    /**
     * 时间窗口（秒）
     * @var int
     */
    private int $ttl;
    
    /**
     * 限流键
     * @var string|array
     */
    private $key;
    
    /**
     * 限流消息
     * @var string
     */
    private string $message;
    
    /**
     * 抛出的异常类
     * @var string
     */
    private string $exception;
    
    /**
     * 缓存对象
     */
    private $cache;
    
    /**
     * 构造函数 - 初始化限流器参数
     * 
     * @param int $limit 限流次数
     * @param int $ttl 时间窗口（秒），默认60秒
     * @param string|array $key 限流键，默认IP
     * @param string $message 限流消息，默认'请求过于频繁，请稍后再试'
     * @param string $exception 抛出的异常类，默认'\RuntimeException'
     */
    public function __construct(...$params)
    {
        parent::__construct(...$params);
        
        // 设置默认值
        $this->limit ??= 10;
        $this->ttl ??= 60;
        $this->key ??= self::IP;
        $this->message ??= '请求过于频繁，请稍后再试';
        $this->exception ??= '\\RuntimeException';
        $this->cache = Candy\Core\Cache::store();
    }
    
    /**
     * 获取限流配置数据
     * 
     * @return array 限流配置数组
     */
    public function get(): array
    {
        return [
            'limit' => $this->limit,
            'ttl' => $this->ttl,
            'key' => $this->key,
            'message' => $this->message,
            'exception' => $this->exception
        ];
    }
    
    /**
     * 生成限流键
     * 
     * @return string 限流键
     */
    public function generateKey(): string
    {
        if (is_array($this->key)) {
            // 回调函数形式 [Class::class, 'method']
            [$class, $method] = $this->key;
            if (class_exists($class) && method_exists($class, $method)) {
                return call_user_func([$class, $method]);
            }
        } elseif (is_callable($this->key)) {
            // 可调用对象
            return call_user_func($this->key);
        } elseif (is_string($this->key)) {
            // 预定义常量或字符串
            switch ($this->key) {
                case self::UID:
                    $uid = (string)(session('userId') ?? 'guest');
                    return self::$prefix . ':' . $this->key . ':' . $uid;
                case self::SID:
                    $sid = session_id() ?? 'no-session';
                    return $this->key . ':' . $sid;
                case self::IP:
					$ip = \Candy\Extend\Network\Client::getClientIp(0, false);
                    return $this->key . ':' . $ip;
                default:
                    return $this->key;
            }
        }
        
        // 默认返回IP
		return \Candy\Extend\Network\Client::getClientIp(0, false);
    }
    
    /**
     * 检查是否超过限流
     * 
     * @return bool 是否超过限流
     */
    public function check(): bool
    {
        if($this->cache === false) return false;
        
        $key = $this->generateKey();
        $cacheKey = self::$prefix . ':' . $key;
        
        // 获取当前计数
        $count = $this->cache->get($cacheKey, 0);
        
        if ($count >= $this->limit) {
            return true; // 超过限流
        }
        
        // 增加计数
        $this->cache->set($cacheKey, $count + 1, $this->ttl);
        
        return false; // 未超过限流
    }
    
    /**
     * 执行限流检查，超过则抛出异常
     * 
     * @throws \Exception
     */
    public function handle(): void
    {
        if ($this->check()) {
            $exceptionClass = $this->exception;
            throw new $exceptionClass($this->message);
        }
    }
    
    /**
     * 返回处理后的限流配置数据
     * 
     * @return array 限流配置数组
     */
    public function data(): array
    {
        return $this->get();
    }
}
