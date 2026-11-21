<?php
/***
 * DB 写操作与类 Validate 桥接
 *
 * 业务说明：insert/update 开启验证时按约定解析 Validates 类；无类则仅校验 CSRF
 * 约定：APP\Validate\{App}\{Table}Validate，scene 为 add|mod（兼 create|update）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 18:43:40 $
 *
 * @package Candy\Extend\Validate
 */

declare(strict_types=1);

namespace Candy\Extend\Validate;

use Candy\Core\Request;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * ORM 写路径验证桥
 */
final class DbBridge
{
	/**
	 * 执行写前校验
	 *
	 * @param object $db DBServer
	 * @param array<string,mixed> $data 数据
	 * @param string $action add|mod
	 * @return array{ok:bool,message:string}
	 */
	public static function check(object $db, array $data, string $action): array
	{
		$server = I('server.', [], '');
		$token = '';
		if (isset($data['token'])) {
			$token = (string)$data['token'];
		} elseif (isset($data['csrf'])) {
			$token = (string)$data['csrf'];
		} elseif (!empty($server['HTTP_USER_TOKEN_CSRF'])) {
			$token = (string)$server['HTTP_USER_TOKEN_CSRF'];
		}

		if ($token !== '' && !Csrf::check($token)) {
			return ['ok' => false, 'message' => 'Token令牌无效请刷新后重新提交'];
		}

		// 无 token 时：无 Referer 同源则拒绝（对齐原 Validate::check）
		if ($token === '') {
			$ref = (string)($server['HTTP_REFERER'] ?? '');
			$host = (string)($server['HTTP_HOST'] ?? '');
			if ($ref === '' || ($host !== '' && stripos($ref, $host) === false)) {
				return ['ok' => false, 'message' => 'CSRF令牌验证失败请重新请求'];
			}
		}

		$validator = self::resolve($db);
		if ($validator === null) {
			return ['ok' => true, 'message' => ''];
		}

		$scene = self::resolveScene($validator, $action);
		$ok = $validator->data($data)->setDb($db)->scene($scene)->check();
		return [
			'ok'      => $ok,
			'message' => $ok ? '' : $validator->getError(),
		];
	}

	/**
	 * 解析 Validate 类
	 *
	 * @param object $db DB
	 * @return Base|null
	 */
	public static function resolve(object $db): ?Base
	{
		// Model 可声明 public string $validateClass = UserValidate::class
		if (isset($db->validateClass) && is_string($db->validateClass) && $db->validateClass !== '') {
			$class = $db->validateClass;
			if (is_subclass_of($class, Base::class)) {
				return $class::make();
			}
		}

		$app = self::guessApp($db);
		$table = self::guessTable($db);
		if ($table === '') {
			return null;
		}

		$candidates = [
			'APP\\Validate\\' . $app . '\\' . $table . 'Validate',
			'APP\\Validate\\' . $app . '\\' . $table,
		];
		foreach ($candidates as $class) {
			if (class_exists($class) && is_subclass_of($class, Base::class)) {
				return $class::make();
			}
		}
		return null;
	}

	/**
	 * @param Base $validator 实例
	 * @param string $action add|mod
	 * @return string
	 */
	private static function resolveScene(Base $validator, string $action): string
	{
		$action = strtolower($action);
		$prefer = $action === 'add' || $action === 'create'
			? ['add', 'create', 'store']
			: ['mod', 'update', 'save'];
		$scenes = (new \ReflectionClass($validator))->getDefaultProperties()['scenes'] ?? [];
		if (!is_array($scenes)) {
			$scenes = [];
		}
		foreach ($prefer as $name) {
			if (isset($scenes[$name])) {
				return $name;
			}
		}
		return $prefer[0];
	}

	/**
	 * @param object $db DB
	 * @return string
	 */
	private static function guessApp(object $db): string
	{
		if (isset($db->path) && is_string($db->path) && preg_match('#Application[/\\\\]([^/\\\\]+)#', $db->path, $m)) {
			return ucfirst($m[1]);
		}
		if (function_exists('C')) {
			$app = (string)(C('APPNAME') ?? 'Home');
			return $app !== '' ? ucfirst($app) : 'Home';
		}
		return 'Home';
	}

	/**
	 * @param object $db DB
	 * @return string
	 */
	private static function guessTable(object $db): string
	{
		$tab = (string)($db->tabName ?? '');
		$prefix = (string)($db->tablePrefix ?? (function_exists('C') ? (C('TABPREFIX') ?? '') : ''));
		if ($prefix !== '' && str_starts_with($tab, $prefix)) {
			$tab = substr($tab, strlen($prefix));
		}
		$tab = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', strtolower($tab))));
		return $tab;
	}
}
