<?php
/***
 * 向量库文件锁
 *
 * 业务说明：目录级 flock，共享读 / 排他写
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 07:59:38 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Lock
{
	/** @var resource|null */
	private $fp = null;

	public function __construct(private string $lockFile)
	{
	}

	/**
	 * 获取锁
	 *
	 * @param bool $exclusive true=写锁
	 * @return bool
	 */
	public function acquire(bool $exclusive = false): bool
	{
		$dir = dirname($this->lockFile);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return false;
		}
		$this->fp = @fopen($this->lockFile, 'c+');
		if ($this->fp === false) {
			$this->fp = null;
			return false;
		}
		return flock($this->fp, $exclusive ? LOCK_EX : LOCK_SH);
	}

	/**
	 * 释放锁
	 *
	 * @return void
	 */
	public function release(): void
	{
		if (is_resource($this->fp)) {
			flock($this->fp, LOCK_UN);
			fclose($this->fp);
		}
		$this->fp = null;
	}

	public function __destruct()
	{
		$this->release();
	}
}
