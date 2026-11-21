<?php
/***
 * Rpc 全局函数桥接
 *
 * 业务说明：rpc() 懒加载入口，配置地址并返回客户端代理
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:20:44 $
 *
 * @package Candy\Core\Trait\Func
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Func;

use Candy\Extend\Rpc\Client;
use Candy\Extend\Rpc\Config;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * rpc() → Client
 */
final class RpcBridge
{
	/**
	 * 创建 Rpc 客户端代理
	 *
	 * @param string                           $serverName 服务名（对应 Registry）
	 * @param array<int|string,mixed>          $config     地址列表或选项数组
	 * @return object Client 代理
	 */
	public function client(string $serverName, array $config = []): object
	{
		$addresses = [];
		$options = [];

		if ($config !== [] && array_is_list($config)) {
			foreach ($config as $item) {
				if (is_string($item) && str_starts_with($item, 'tcp://')) {
					$addresses[] = $item;
				}
			}
		} else {
			if (isset($config['address'])) {
				$addresses = array_values((array)$config['address']);
			} elseif (isset($config['addresses'])) {
				$addresses = array_values((array)$config['addresses']);
			} else {
				foreach ($config as $key => $value) {
					if (is_int($key) && is_string($value) && str_starts_with($value, 'tcp://')) {
						$addresses[] = $value;
					}
				}
			}
			foreach (['protocol', 'name', 'version', 'auto_apply'] as $optKey) {
				if (array_key_exists($optKey, $config)) {
					$options[$optKey] = $config[$optKey];
				}
			}
		}

		if ($addresses === []) {
			$listen = Config::listen();
			$hostPort = str_contains($listen, '://') ? explode('://', $listen, 2)[1] : $listen;
			$hostPort = preg_replace('/^0\.0\.0\.0:/', '127.0.0.1:', $hostPort) ?: $hostPort;
			$addresses = ['tcp://' . $hostPort];
		}

		return Client::instance($serverName, $addresses, $options);
	}
}
