<?php
declare(strict_types=1);

namespace Workerman\Crontab;

/**
 * Crontab 扩展库配置
 *
 * 可移植：通过 Config::set() 注入，或在 Candy 环境自动加载 Source/Inc/CrontabScheduler.inc.php
 */
class Config
{
    private static ?array $config = null;

    /**
     * 默认配置
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'globalDataGateway' => '127.0.0.1:2207',
            'prefix' => 'workerman_crontab_',
            'engine' => [
                'queryInterval' => 0,
                'queryIntervalMax' => 5,
                'queryEmptyCountMax' => 2,
                'bootstrapDelay' => 5,
                'gdReadyMaxAttempts' => 30,
                'versionWatchInterval' => 2,
            ],
            'handlers' => [],
            'on_job_finished' => null,
            /**
             * 执行记录保留（GlobalData run_summary / run_logs，不落 MySQL）
             */
            'record' => [
                'retention_days' => 7,
            ],
        ];
    }

    /**
     * 读取合并后的配置
     *
     * @param array<string,mixed>|null $override 运行时覆盖
     * @return array<string,mixed>
     */
    public static function get(?array $override = null): array
    {
        if (self::$config === null) {
            self::$config = self::defaults();
            self::$config = array_replace_recursive(self::$config, self::loadProjectConfig());
        }
        if ($override !== null) {
            return array_replace_recursive(self::$config, $override);
        }
        return self::$config;
    }

    /**
     * 全量设置配置（移植到其他系统时推荐）
     *
     * @param array<string,mixed> $config
     * @return void
     */
    public static function set(array $config): void
    {
        self::$config = array_replace_recursive(self::defaults(), $config);
        Manager::resetInstance();
    }

    /**
     * 重新加载项目配置（Job 库变更后调用）
     *
     * @return void
     */
    public static function reload(): void
    {
        self::$config = null;
        Manager::resetInstance();
    }

    /**
     * 加载项目级配置文件（Candy）
     *
     * @return array<string,mixed>
     */
    private static function loadProjectConfig(): array
    {
        if (!defined('CANDY') || !function_exists('C')) {
            return [];
        }

        $merged = [];
        $path = function_exists('incResolve') ? incResolve('CrontabScheduler') : C('INCPATH') . 'CrontabScheduler.inc.php';
        if (is_file($path) && function_exists('loadConfig')) {
            $file = loadConfig('CrontabScheduler');
            if (is_array($file)) {
                $merged = array_replace_recursive($merged, $file);
            }
        }

        $apiPath = function_exists('incResolve') ? incResolve('Api') : C('INCPATH') . 'Api.inc.php';
        if (is_file($apiPath) && function_exists('loadConfig')) {
            $api = loadConfig('Api');
            $gw = trim((string) ($api['globalDataGateway'] ?? ''));
            if ($gw !== '') {
                $merged['globalDataGateway'] = $gw;
            }
        }

        // 框架内置 handler 优先合并：裸框架没有 Application/Admin/Services/Jobs 时也能跑任务
        $builtin = [];
        if (class_exists('\Candy\Extend\Tools\Cron\Config')) {
            try {
                $builtin = (array) \Candy\Extend\Tools\Cron\Config::builtinHandlers();
            } catch (\Throwable $e) {
                $builtin = [];
            }
        }
        $merged['handlers'] = array_merge($builtin, self::discoverCandyHandlers());

        return $merged;
    }

	/**
	 * Candy 项目：扫描 Application/Admin/Services/Jobs 下的处理器
	 *
	 * 须加载编译后的运行时类（*Service）；短名由 RuntimeClassLoader 建立 class_alias
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function discoverCandyHandlers(): array
	{
		if (!defined('CANDY') || !function_exists('C')) {
			return [];
		}

		// 优先运行时类名；短名别名在自动加载时由框架建立
		$candidates = [
			'\\APP\\Service\\Admin\\SchedulerJobLibraryService',
			'\\APP\\Service\\Admin\\SchedulerJobLibrary',
		];
		foreach ($candidates as $class) {
			if (class_exists($class, true) && method_exists($class, 'discoverHandlers')) {
				return $class::discoverHandlers();
			}
		}

		return [];
	}
}
