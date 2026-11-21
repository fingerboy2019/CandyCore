# Session 类

## 概述

*
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
 * $cache = new \Candy\

## 主要方法


### start()


### open()


### close()


### read()


### write()


### destroy()


### gc()


### /**
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
	public function instance():()


### /**
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
	private static function sessionKey(string $PHPSESSID):()

