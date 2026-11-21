<?php
/**
 * ZIP 归档解压与打包
 *
 * @package Candy\Core\Trait\Func
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Func;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ArchiveService
{
	/**
	 * 解压 ZIP 文件到指定目录
	 *
	 * @param string $path ZIP 文件路径
	 * @param string $root 目标目录，留空时解压到 ZIP 同名目录
	 * @return bool 成功返回 true
	 */
	public function extract(string $path, string $root = ''): bool
	{
		if (empty($path)) {
			return false;
		}

		$zip = N('ZipArchive');

		if (empty($root)) {
			$root = dirname($path) . '/' . str_replace('.zip', '', basename($path));
		}
		\Candy\Extend\Dir::create(dirname($root));

		if ($zip->open($path) === true) {
			$zip->extractTo($root);
			return $zip->close();
		}

		return false;
	}

	/**
	 * 打包文件为 ZIP
	 *
	 * @param string $path 目标 ZIP 文件路径
	 * @param array<string> $files 要打包的文件列表
	 * @param string $root 文件根目录，缩短包内路径
	 * @return bool 成功返回 true
	 */
	public function make(string $path, array $files, string $root = ''): bool
	{
		$zip = N('ZipArchive');

		if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return false;
		}

		foreach ($files as $file) {
			$local = $root !== '' ? str_replace($root . '/', '', $file) : basename($file);
			$zip->addFile($file, $local);
		}

		return $zip->close();
	}
}
