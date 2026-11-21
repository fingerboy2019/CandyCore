<?php
/**
 * 全局函数懒加载转发器
 *
 * 将全局函数调用（如 extractZip、getLocalIP）映射到对应服务类的方法。
 * 避免定义大量全局函数，统一通过 match 集中管理。
 *
 * @package Candy\Core\Trait\Func
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Func;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class FuncLoader
{
	/**
	 * 转发函数调用到对应服务类
	 *
	 * @param string $name 函数名
	 * @param array<int, mixed> $args 参数列表
	 * @return mixed 服务类方法返回值
	 * @throws \BadFunctionCallException 未知函数名
	 */
	public static function forward(string $name, array $args): mixed
	{
		return match ($name) {
			'extractZip' => N(ArchiveService::class)->extract(...$args),
			'makeZip' => N(ArchiveService::class)->make(...$args),
			'getLocalIP' => N(MirrorClient::class)->getLocalIp(...$args),
			'getMirContents' => N(MirrorClient::class)->getContents(...$args),
			'setMirContents' => self::voidForward(MirrorClient::class, 'setContents', $args),
			'makeMirForSave' => self::voidForward(MirrorClient::class, 'makeForSave', $args),
			'rpc' => N(RpcBridge::class)->client(...$args),
			'verificationCode' => self::voidForward(CaptchaRenderer::class, 'render', $args),
			default => throw new \BadFunctionCallException("Unknown lazy function: {$name}"),
		};
	}

	/**
	 * 重置静态缓存（用于测试或进程重启）
	 *
	 * @return void
	 */
	public static function reset(): void
	{
		// FuncLoader 只做转发、不持有任何静态缓存，之前这里调 N(null, null) 是错的：
		// N() 的第一个参数声明为 string，传 null 直接 TypeError（冒到 worker 日志里）。
		// 重置语义应落在容器上：清掉作用域实例，下次转发重新实例化。
		if (class_exists('\\Candy\\Core\\Container')) {
			$container = \Candy\Core\Container::getInstance();
			if (method_exists($container, 'flushScopedInstances')) {
				$container->flushScopedInstances();
			}
		}
	}

	/**
	 * 无返回值转发
	 *
	 * @param string $class 服务类名
	 * @param string $method 方法名
	 * @param array $args 参数列表
	 * @return null
	 */
	private static function voidForward(string $class, string $method, array $args): null
	{
		N($class)->{$method}(...$args);
		return null;
	}
}
