<?php
/***
 * 代码生成结果值对象
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Impl\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Impl\Generator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class GenerateResult
{
    /**
     * @param array<int, string> $created 已创建文件路径
     * @param array<int, string> $skipped 已跳过文件路径
     * @param array<int, string> $errors 错误信息
     */
    public function __construct(
        public readonly array $created = [],
        public readonly array $skipped = [],
        public readonly array $errors = [],
    ) {
    }

    /**
     * 是否全部成功（无错误）
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->errors === [];
    }

    /**
     * 转为业务数组（CLI / Web 共用）
     *
     * @return array{code:int,message:string,created:array,skipped:array,errors:array}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->ok() ? 0 : 1,
            'message' => $this->ok() ? 'ok' : ($this->errors[0] ?? 'generate failed'),
            'created' => $this->created,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }

    /**
     * 合并多个子结果
     *
     * @param self ...$parts
     * @return self
     */
    public static function merge(self ...$parts): self
    {
        $created = $skipped = $errors = [];
        foreach ($parts as $part) {
            $created = array_merge($created, $part->created);
            $skipped = array_merge($skipped, $part->skipped);
            $errors = array_merge($errors, $part->errors);
        }

        return new self(
            array_values(array_unique($created)),
            array_values(array_unique($skipped)),
            array_values($errors),
        );
    }
}
