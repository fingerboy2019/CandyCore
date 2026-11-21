<?php
/***
 * Candy框架 Check 初始化命令
 *
 * 发布 Sensitive 配置、灌 Cache 词库（不改 tags）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 12:31:43 $
 */

declare(strict_types=1);

namespace Console\Command\Check;

use Check\Installer;
use Console\Command;
use Console\Input;
use Console\Input\Option;
use Console\Output;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Init extends Command
{
	/**
	 * 配置指令
	 *
	 * @return void
	 */
	protected function configure(): void
	{
		$this->setName('check:init')
			->addOption('force', 'f', Option::VALUE_NONE, 'Overwrite Inc config and re-seed word cache')
			->setDescription('Init Check: publish Sensitive.inc.php, seed Cache from TXT (no tags hook)');
	}

	/**
	 * 执行初始化
	 *
	 * @param Input  $input  输入
	 * @param Output $output 输出
	 * @return void
	 */
	protected function execute(Input $input, Output $output): void
	{
		$force = (bool)$input->getOption('force');
		$result = Installer::install($force);

		if ((int)($result['code'] ?? 1) !== 0) {
			$output->writeln('<error>' . ($result['message'] ?? 'check:init failed') . '</error>');
			foreach (($result['data']['steps'] ?? []) as $name => $step) {
				if (is_array($step)) {
					$output->writeln('  [' . $name . '] ' . ($step['message'] ?? ''));
				}
			}
			return;
		}

		$output->writeln('<info>' . ($result['message'] ?? 'ok') . '</info>');
		foreach (($result['data']['steps'] ?? []) as $name => $step) {
			if (!is_array($step)) {
				continue;
			}
			$flag = !empty($step['skipped']) ? 'skip' : 'ok';
			$output->writeln('  <comment>[' . $flag . ']</comment> ' . $name . ': ' . ($step['message'] ?? ''));
		}

		if (!empty($result['data']['config'])) {
			$output->writeln('<comment>Config: ' . $result['data']['config'] . '</comment>');
		}
		if (isset($result['data']['words'])) {
			$output->writeln('<comment>Words: ' . $result['data']['words'] . ' / ver=' . ($result['data']['ver'] ?? '?') . '</comment>');
		}
		if (!empty($result['data']['hint'])) {
			$output->writeln('<comment>' . $result['data']['hint'] . '</comment>');
		}
	}
}
