<?php
/***
 * Candy框架 会话控制Session类
 *
 * 该类提供了基于缓存的自定义会话管理机制，支持将session数据存储在各种缓存系统中。
 * 主要功能：
 * - 使用自定义会话处理器替代默认的文件存储
 * - 支持通过缓存对象管理session数据
 * - 实现完整的session生命周期管理
 * - 支持自定义session ID
 * - 自动处理session的创建、读取、写入和销毁
 *
 * 使用场景：
 * - 需要将会话数据存储在分布式缓存系统中（如Redis、Memcached等）
 * - 多服务器环境下的会话共享
 * - 需要自定义会话过期策略的场景
 * - 需要更高效的会话管理机制
 *
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-26 11:31:47
 * @final 该类为最终类，不允许继承
 * @example
 * ```
 * // 初始化Session类，传入缓存对象
 * $cache = new \Candy\Core\Cache();
 * \Candy\Extend\Session::start($cache);
 *
 * // 使用自定义session ID初始化
 * \Candy\Extend\Session::start($cache, 'custom-session-id');
 *
 * // 操作session数据
 * $_SESSION['user_id'] = 123;
 * $_SESSION['username'] = 'admin';
 *
 * // 获取session数据
 * $user_id = $_SESSION['user_id'] ?? null;
 * ```
 * @requires PHP 7.0+
 * @note
 * - 该类必须配合实现了标准缓存接口的对象使用
 * - 会话数据实际存储在缓存系统中，具有自动过期机制
 * - 使用前需要确保缓存系统可用且已正确配置
 * - 不支持 Workerman 长连接模式（Workerman 自带 Session 管理）
 */

declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Session {
	/**
	 * 缓存处理器实例
	 * @var object|null
	 */
	private static $handler = null;
	
	/**
	 * 会话生命周期（秒）
	 * @var int
	 */
	private static $lifetime = 0;

	/**
	 * 初始化并启动会话
	 *
	 * 该方法负责设置自定义会话处理器并启动PHP会话。它是使用Session类的入口点，
	 * 必须在任何会话操作之前调用。
	 *
	 * @param object $cacheobj 缓存对象，必须实现get/set/delete等标准缓存接口方法
	 * @param string $sessionid 可选的自定义会话ID，为空时由PHP自动生成
	 * @return bool 会话启动成功返回true，失败返回false
	 * @throws \Exception 当会话启动失败时会捕获异常
	 * @note
	 * - 如果会话已激活，将先销毁现有会话
	 * - 设置SECACHE常量标记会话已初始化
	 * - 使用传入的缓存对象作为会话数据存储后端
	 * @example
	 * ```
	 * // 基本用法
	 * $cache = new \Candy\Core\Cache();
	 * if (\Candy\Extend\Session::start($cache)) {
	 *     // 会话已成功启动
	 *     $_SESSION['user'] = 'admin';
	 * }
	 * ```
	 */
	public static function start(object $cacheobj, string $sessionid = ''): bool
	{
		// 强制彻底关闭任何已存在的会话
		// 仅在会话真正处于活动状态时才 destroy，否则 PHP 8.x 会报
		// "session_destroy(): Trying to destroy uninitialized session"（生产日志里 57 次）
		if(session_status() === PHP_SESSION_ACTIVE){
			session_unset();
			session_destroy();
		}
		session_write_close();

		// 重新初始化
		self::$handler = $cacheobj;
		self::$lifetime = (int)ini_get('session.gc_maxlifetime') ?: 1440;

		// 关闭自动 GC
		ini_set('session.gc_probability', 0);
		ini_set('session.gc_divisor', 1);
		ini_set('session.use_cookies', 1);

		// PHP 8.4 正确注册处理器
		$handler = new class extends \SessionHandler {
			/**
			 * Open
			 * @param mixed $path
			 * @param mixed $name
			 * @param mixed $path
			 * @param mixed $name
			 * @return bool
			 */
			public function open($path, $name): bool { return Session::open($path, $name); }
			/**
			 * Close
			 * @return bool
			 */
			public function close(): bool { return Session::close(); }
			/**
			 * Read
			 * @param mixed $id
			 * @param mixed $id
			 * @return string
			 */
			public function read($id): string { return Session::read($id); }
			/**
			 * Write
			 * @param mixed $id
			 * @param mixed $data
			 * @param mixed $id
			 * @param mixed $data
			 * @return bool
			 */
			public function write($id, $data): bool { return Session::write($id, $data); }
			/**
			 * Destroy
			 * @param mixed $id
			 * @param mixed $id
			 * @return bool
			 */
			public function destroy($id): bool { return Session::destroy($id); }
			/**
			 * Gc
			 * @param mixed $maxlifetime
			 * @param mixed $maxlifetime
			 * @return int
			 */
			public function gc($maxlifetime): int { return Session::gc($maxlifetime) ? 1 : 0; }
		};

		session_set_save_handler($handler);

		// 设置自定义ID
		if (!empty($sessionid)) {
			session_id($sessionid);
		}

		try {
			session_start();
			//强制脚本结束一定写入
			register_shutdown_function(function() {
				session_write_close();
			});
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * 会话打开回调函数
	 *
	 * 这是PHP会话机制的回调方法，在会话开始时自动调用。
	 * 在当前实现中，由于使用缓存对象存储会话数据，此方法只需返回true。
	 *
	 * @param string $path 会话文件保存路径（标准PHP会话参数，当前实现未使用）
	 * @param string $name 会话名称（标准PHP会话参数，当前实现未使用）
	 * @return bool 始终返回true，表示会话打开成功
	 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
	 */
	public static function open(string $path, string $name): bool
	{
		return true;
	}

	/**
	 * 会话关闭回调函数
	 *
	 * 这是PHP会话机制的回调方法，在会话结束时自动调用。
	 * 在当前实现中，由于使用缓存对象存储会话数据，此方法只需返回true。
	 *
	 * @return bool 始终返回true，表示会话关闭成功
	 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
	 */
	public static function close(): bool
	{
		return true;
	}

	/**
	 * 从缓存中读取会话数据
	 *
	 * 这是PHP会话机制的回调方法，用于读取指定会话ID对应的会话数据。
	 * 数据实际存储在通过start方法传入的缓存对象中。
	 *
	 * @param string $PHPSESSID 要读取的会话ID
	 * @return string 返回会话数据字符串，如果会话不存在或读取失败则返回空字符串
	 * @note
	 * - 会话ID会通过sessionKey方法转换为缓存键
	 * - 如果缓存中不存在该会话数据或读取失败，返回空字符串
	 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
	 */
	public static function read(string $PHPSESSID): string
	{
		$out = self::$handler->get(self::sessionKey($PHPSESSID));
		//p(self::sessionKey($PHPSESSID), 'read');
		// 严格判断，只有 false 或 null 才返回空字符串
		if($out === false || $out === null){
			return '';
		}
		
		return (string)$out;
	}

	/**
	 * 将会话数据写入缓存
	 *
	 * 这是PHP会话机制的回调方法，用于将会话数据写入缓存系统。
	 * 在会话结束或调用session_write_close()时自动执行。
	 *
	 * @param string $PHPSESSID 会话ID
	 * @param mixed $data 要存储的会话数据（通常是序列化后的字符串）
	 * @return bool 数据写入成功返回true，失败返回false
	 * @note
	 * - 会话ID会通过sessionKey方法转换为缓存键
	 * - 依赖缓存对象的set方法实现数据存储，并设置过期时间
	 * - 过期时间使用 session.gc_maxlifetime 配置
	 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
	 */
	public static function write(string $PHPSESSID, mixed $data): bool
	{
		//p( $PHPSESSID, $data, 'write');exit;
		// 设置过期时间，使用 session.gc_maxlifetime 配置
		$result = self::$handler->set(self::sessionKey($PHPSESSID), $data, self::$lifetime);
		return (bool)$result;
	}

	/**
	 * 销毁指定的会话数据
	 *
	 * 这是PHP会话机制的回调方法，用于删除指定会话ID的所有数据。
	 * 在调用session_destroy()时自动执行。
	 *
	 * @param string $PHPSESSID 要销毁的会话ID
	 * @return bool 会话销毁成功返回true，失败返回false
	 * @note
	 * - 实际是调用缓存对象的delete方法删除对应的缓存数据
	 * - 会话ID会通过sessionKey方法转换为缓存键
	 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
	 */
	public static function destroy(string $PHPSESSID): bool
	{
		$result = self::$handler->delete(self::sessionKey($PHPSESSID));
		return (bool)$result;
	}

	/**
	 * 会话垃圾回收回调函数
	 *
	 * 这是PHP会话机制的回调方法，用于清理过期的会话数据。
	 * 由于当前实现使用缓存系统存储会话数据，缓存系统通常有自己的过期策略，
	 * 因此此方法无需执行额外的回收操作。
	 *
	 * @param int $lifetime 会话最大生存时间（秒）
	 * @return bool 始终返回true，表示垃圾回收操作成功
	 * @note
	 * - 缓存系统（如Memcache/Redis）有自己的过期回收机制
	 * - 此方法在PHP会话系统的垃圾回收周期触发
	 * - 已通过 ini_set('session.gc_probability', '0') 禁用PHP自动GC
	 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
	 */
	public static function gc(int $lifetime): bool
	{
		// 无需额外回收，缓存系统有自己的过期机制
		return true;
	}

	/**
	 * 创建Session类的实例
	 *
	 * 获取Session类的单例实例。虽然Session类的主要方法都是静态的，
	 * 但此方法提供了面向对象的使用方式。
	 *
	 * @return Session 返回Session类的新实例
	 * @note
	 * - 由于Session类中的大部分方法都是静态的，通常不需要获取实例
	 * - 此类被声明为final，不能被继承
	 * @example
	 * ```
	 * // 获取Session实例
	 * $session = (new \Candy\Extend\Session())->instance();
	 * // 但通常直接使用静态方法更方便
	 * \Candy\Extend\Session::start($cache);
	 * ```
	 */
	public function instance(): Session
	{
		return new self();
	}

	/**
	 * 生成会话缓存键
	 *
	 * 此私有方法用于将会话ID转换为缓存系统中使用的键名。
	 * 通过添加表前缀来避免缓存键冲突，确保会话数据的唯一性。
	 *
	 * @param string $PHPSESSID 原始会话ID
	 * @return string 返回格式化后的缓存键名，格式为"表前缀+会话ID"
	 * @internal 此方法仅在Session类内部使用，用于构建缓存键名
	 * @note
	 * - 缓存键由全局配置中的表前缀(TABPREFIX)和会话ID组合而成
	 * - 如果 TABPREFIX 未配置，使用默认前缀 'session_'
	 * - 此方法确保了在多应用场景下会话数据不会发生冲突
	 */
	private static function sessionKey(string $PHPSESSID): string
	{
		$prefix = G('TABPREFIX') ?: 'session_';
		return $prefix . $PHPSESSID;
	}
}

