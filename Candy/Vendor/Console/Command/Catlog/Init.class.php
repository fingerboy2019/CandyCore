<?php
/***
 * Candy框架 Catlog 初始化命令
 *
 * 发布配置、复制静态资源到 Public、注册 Hook
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:43:17 $
 */

declare(strict_types=1);

namespace Console\Command\Catlog;

use Catlog\Installer;
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
		$this->setName('catlog:init')
			->addOption('force', 'f', Option::VALUE_NONE, 'Overwrite existing Inc config / Public static / tags')
			->setDescription('Init Catlog: publish config, register hooks (UI under Vendor/static)');
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
			$output->writeln('<error>' . ($result['message'] ?? 'catlog:init failed') . '</error>');
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
		if (!empty($result['data']['static'])) {
			$output->writeln('<comment>Static: ' . $result['data']['static'] . '</comment>');
		}
		$output->writeln('<comment>Requires: xhprof extension + Cache; change auth.password in Inc</comment>');
		if (!empty($result['data']['report_hint'])) {
			$output->writeln('<comment>' . $result['data']['report_hint'] . '</comment>');
		}
	}
}
