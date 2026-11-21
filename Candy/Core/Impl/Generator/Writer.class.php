<?php
/***
 * 代码生成器文件写入
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Impl\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Impl\Generator;

use Candy\Core\Build;
use Candy\Core\Trait\Generator\OverwritePolicy;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Writer
{
    use OverwritePolicy;

    /**
     * 写入单个文件
     *
     * skip：已存在则跳过（Build::touch 行为）
     * force：覆盖写入
     *
     * @param string $path 目标绝对路径
     * @param string $content 文件内容
     * @param string $policy skip|force
     * @return array{status:string,path:string} status=created|skipped|failed
     */
    public static function write(string $path, string $content, string $policy = 'skip'): array
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $policy = self::normalizeOverwritePolicy($policy);

        if (is_file($path) && $policy === 'skip') {
            return ['status' => 'skipped', 'path' => $path];
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !\Candy\Extend\Dir::create($dir)) {
            return ['status' => 'failed', 'path' => $path, 'reason' => 'mkdir failed: ' . $dir];
        }
        if (!is_writable($dir)) {
            return ['status' => 'failed', 'path' => $path, 'reason' => 'directory not writable: ' . $dir];
        }

        if ($policy === 'skip' && !is_file($path)) {
            Build::touch($path, $content);
            return is_file($path)
                ? ['status' => 'created', 'path' => $path]
                : ['status' => 'failed', 'path' => $path, 'reason' => 'Build::touch failed'];
        }

        if (file_put_contents($path, $content) === false) {
            return ['status' => 'failed', 'path' => $path, 'reason' => 'file_put_contents failed'];
        }

        @chmod($path, 0644);
        return ['status' => 'created', 'path' => $path];
    }

    /**
     * 批量写入并汇总为 GenerateResult
     *
     * @param array<int, array{path:string,content:string}> $files
     * @param string $policy
     * @return GenerateResult
     */
    public static function writeMany(array $files, string $policy = 'skip'): GenerateResult
    {
        $created = $skipped = $errors = [];
        foreach ($files as $file) {
            $path = (string)($file['path'] ?? '');
            $content = (string)($file['content'] ?? '');
            if ($path === '') {
                continue;
            }

            $result = self::write($path, $content, $policy);
            match ($result['status']) {
                'created' => $created[] = $path,
                'skipped' => $skipped[] = $path,
                default => $errors[] = ($result['reason'] ?? 'write failed') . ': ' . $path,
            };
        }

        return new GenerateResult($created, $skipped, $errors);
    }
}
