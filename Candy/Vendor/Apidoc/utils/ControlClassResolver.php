<?php
/***
 * Candy 控制器类解析（Apidoc）
 *
 * 业务说明：优先通过 Runtime 编译加载 *Action，避免 require 源码时 Base 未定义
 * 对外展示与 menuKey/URL 使用源码短名（去掉 Action 后缀）
 * 控制器标题对齐 Node：优先文件头 @name
 * 无 @Method 时按方法体 C('METHOD')/I('post.') 推断 HTTP 方法
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 20:41:41 $
 *
 * @package Apidoc\utils
 */

declare(strict_types=1);

namespace Apidoc\utils;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * ControlClassResolver
 */
final class ControlClassResolver
{
	/**
	 * 去掉控制器短名末尾的 Action 后缀
	 *
	 * Runtime 编译类为 XxxAction，Apidoc 列表/URL/menuKey 应对齐源码短名 Xxx
	 *
	 * @param string $short 短类名，如 AuthAction / Auth
	 * @return string 源码短名
	 * @access public
	 * @static
	 */
	public static function stripActionSuffix(string $short): string
	{
		return str_ends_with($short, 'Action') ? substr($short, 0, -6) : $short;
	}

	/**
	 * 将 FQCN 转为源码形态（末段去掉 Action）
	 *
	 * 例：APP\Control\Admin\AuthAction → APP\Control\Admin\Auth
	 *
	 * @param string $class 完整类名（可含 Action）
	 * @return string 源码 FQCN
	 * @access public
	 * @static
	 */
	public static function toSourceFqcn(string $class): string
	{
		$class = ltrim($class, '\\');
		if ($class === '' || !str_contains($class, '\\')) {
			return self::stripActionSuffix($class);
		}
		$pos = strrpos($class, '\\');
		$ns = substr($class, 0, $pos);
		$short = substr($class, $pos + 1);
		return $ns . '\\' . self::stripActionSuffix($short);
	}

	/**
	 * 对外展示用的控制器短名（无 Action）
	 *
	 * @param string $class 完整类名或短名
	 * @return string 如 Auth
	 * @access public
	 * @static
	 */
	public static function displayName(string $class): string
	{
		$class = ltrim($class, '\\');
		$short = str_contains($class, '\\') ? substr($class, (int)strrpos($class, '\\') + 1) : $class;
		return self::stripActionSuffix($short);
	}

	/**
	 * 解析控制器文档标题（对齐 Node Tool::getClassList）
	 *
	 * 优先级：类级 #[Annotation(name|title)] → 类 DocComment 中 @name
	 * → 源文件/Runtime 文件头 @name → 源码短名
	 *
	 * 说明：Candy 文件头 /*** 在 namespace 之前，Reflection::getDocComment() 读不到，
	 * 必须像 Node 一样扫文件内容提取 @name
	 *
	 * @param \ReflectionClass $ref 已加载的控制器反射（通常为 *Action）
	 * @return string 如「角色管理」
	 * @access public
	 * @static
	 */
	public static function resolveDocTitle(\ReflectionClass $ref): string
	{
		// 1. 类级 PHP 8 Attribute（少见，与 Node 一致）
		foreach ($ref->getAttributes() as $attr) {
			$attrName = $attr->getName();
			if ($attrName !== 'Annotation' && stripos($attrName, 'Annotation') === false) {
				continue;
			}
			$args = $attr->getArguments();
			if (!empty($args['name']) && is_string($args['name'])) {
				return trim($args['name']);
			}
			if (!empty($args['title']) && is_string($args['title'])) {
				return trim($args['title']);
			}
		}

		// 2. 紧贴 class 的 DocComment 中的 @name
		$doc = (string)$ref->getDocComment();
		if ($doc !== '' && preg_match('/@name\s+([^\r\n\*]+)/i', $doc, $m)) {
			$title = trim($m[1]);
			if ($title !== '') {
				return $title;
			}
		}

		// 3. 读文件内容（文件头 /*** @name …），与 Node 相同
		$file = $ref->getFileName();
		if (is_string($file) && $file !== '' && is_file($file)) {
			$contents = class_exists(\Candy\Core\Stream::class)
				? (string)\Candy\Core\Stream::getInstance()->fileGetContents($file)
				: (string)@file_get_contents($file);
			if ($contents !== '' && preg_match('/@name\s+([^\r\n\*]+)/i', $contents, $m)) {
				$title = trim($m[1]);
				if ($title !== '') {
					return $title;
				}
			}
		}

		// 4. 回退短名
		return self::displayName($ref->getName());
	}

	/**
	 * 从方法源码推断 HTTP 方法（无 @Method 时）
	 *
	 * Candy 控制器习惯用 C('METHOD')=='POST' 区分表单 GET 与提交 POST，
	 * 或直接 I('post.xxx') / I('get.xxx') 读写参数；项目内几乎不写 @Method，
	 * 若不推断则全部落成 default_method=GET。
	 *
	 * @param \ReflectionMethod $refMethod 方法反射
	 * @return string|list<string>|null 如 POST、['GET','POST']；无法判断时 null
	 * @access public
	 * @static
	 */
	public static function inferHttpMethod(\ReflectionMethod $refMethod): string|array|null
	{
		$code = self::readMethodSource($refMethod);
		if ($code === '') {
			return null;
		}

		// if (C('METHOD') == 'POST') … else … → 双端
		$hasMethodPostBranch = (bool)preg_match(
			'/C\s*\(\s*[\'"]METHOD[\'"]\s*\)\s*(?:===?|!==?)\s*[\'"]POST[\'"]/i',
			$code
		);
		$usesPost = (bool)preg_match('/I\s*\(\s*[\'"]post[\.\']/i', $code)
			|| str_contains($code, '$_POST');
		$usesGet = (bool)preg_match('/I\s*\(\s*[\'"]get[\.\']/i', $code)
			|| str_contains($code, '$_GET');

		if ($hasMethodPostBranch) {
			return ['GET', 'POST'];
		}
		if ($usesPost && $usesGet) {
			return ['GET', 'POST'];
		}
		if ($usesPost) {
			return 'POST';
		}
		if ($usesGet) {
			return 'GET';
		}

		return null;
	}

	/**
	 * 读取方法源码片段（含签名到结束行）
	 *
	 * @param \ReflectionMethod $refMethod 方法反射
	 * @return string 源码；失败为空串
	 * @access private
	 * @static
	 */
	private static function readMethodSource(\ReflectionMethod $refMethod): string
	{
		$file = $refMethod->getFileName();
		$start = $refMethod->getStartLine();
		$end = $refMethod->getEndLine();
		if (!is_string($file) || $file === '' || $start < 1 || $end < $start || !is_file($file)) {
			return '';
		}

		$lines = @file($file, FILE_IGNORE_NEW_LINES);
		if (!is_array($lines)) {
			return '';
		}

		$slice = array_slice($lines, $start - 1, $end - $start + 1);
		return implode("\n", $slice);
	}

	/**
	 * 解析为可反射的控制器类名（通常为 *Action）
	 *
	 * @param string $class 如 APP\Control\Admin\Login 或 LoginAction
	 * @param array<string,mixed> $currentApp 当前 Apidoc app 配置（含 path）
	 * @return string 实际已加载的类名
	 * @access public
	 * @static
	 */
	public static function resolve(string $class, array $currentApp = []): string
	{
		$class = ltrim($class, '\\');
		if ($class === '' || str_contains($class, 'Common')) {
			return $class;
		}

		if (preg_match('#^APP\\\\(Control|Addon)\\\\([^\\\\]+)\\\\([^\\\\]+)$#', $class, $m)) {
			$isAddon = $m[1] === 'Addon';
			$app = $m[2];
			$short = $m[3];
			$loadShort = self::stripActionSuffix($short);
			$actionClass = str_ends_with($short, 'Action')
				? $class
				: ($class . 'Action');

			if (!class_exists($actionClass, false)) {
				$prevApp = C('APPNAME');
				$prevPath = C('APP_PATH');
				C('APPNAME', $app);
				$appPath = rtrim((string)C('CANDYROOT'), '/\\')
					. ($isAddon ? '/Addons/' : '/Application/')
					. $app
					. '/';
				C('APP_PATH', $appPath);
				try {
					\Candy\Core\App::controler($loadShort, $app, $isAddon);
				} catch (\Throwable $e) {
					// 编译失败则走源码回退
				} finally {
					if ($prevApp !== null && $prevApp !== '') {
						C('APPNAME', $prevApp);
					}
					if ($prevPath !== null && $prevPath !== '') {
						C('APP_PATH', $prevPath);
					}
				}
			}

			if (class_exists($actionClass, false)) {
				return $actionClass;
			}
		}

		// 已加载则直接用（含非标准命名空间）
		if (class_exists($class, false)) {
			return $class;
		}

		// 回退：直接引入源码（无 extends Base 的短名类仍可用）
		if (!empty($currentApp['path']) && defined('APIDOC_ROOT_PATH')) {
			$parts = explode('\\', $class);
			$file = end($parts);
			if (is_string($file) && $file !== '') {
				$file = self::stripActionSuffix($file);
				$path = APIDOC_ROOT_PATH . $currentApp['path'] . DIRECTORY_SEPARATOR . $file . '.class.php';
				if (is_file($path)) {
					require_once $path;
				}
			}
		}

		$source = self::toSourceFqcn($class);
		if (class_exists($source, false)) {
			return $source;
		}

		return $class;
	}
}
