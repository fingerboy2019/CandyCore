<?php
/***
 * Candy框架 Apidoc 初始化命令
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:43:17 $
 */

declare(strict_types=1);

namespace Console\Command\Apidoc;

use Apidoc\Installer;
use Console\Command;
use Console\Input;
use Console\Input\Option;
use Console\Output;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Init extends Command
{
	protected function configure(): void
	{
		$this->setName('apidoc:init')
			->addOption('force', 'f', Option::VALUE_NONE, 'Overwrite existing files')
			->setDescription('Init Apidoc: publish config, extract frontend zip, register CandyService');
	}

	protected function execute(Input $input, Output $output): void
	{
		$result = Installer::install((bool)$input->getOption('force'));
		if ((int)($result['code'] ?? 1) !== 0) {
			$output->writeln('<error>' . ($result['message'] ?? 'failed') . '</error>');
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
	}
}
