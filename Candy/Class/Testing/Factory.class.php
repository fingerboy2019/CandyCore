<?php
/***
 * 测试模型工厂（Laravel Model Factory 等价）
 *
 * 业务说明：为测试按蓝图批量构造/写入记录，避免手写 add() 时逐个填唯一字段。
 *
 * ```php
 * class UsersFactory extends Factory
 * {
 *     protected string $model = 'Users';
 *
 *     protected function definition(): array
 *     {
 *         return [
 *             'username'   => 'test_' . $this->unique(),
 *             'real_name'  => $this->pick(['张三', '李四', '王五']),
 *             'email'      => 'test_' . $this->unique() . '@example.com',
 *             'role'       => 'employee',
 *             'status'     => 1,
 *         ];
 *     }
 * }
 *
 * // 写库
 * $users = (new UsersFactory())->count(3)->create();
 *
 * // 只构造不写库
 * $rows = (new UsersFactory())->count(2)->make();
 *
 * // 覆盖字段
 * $admin = (new UsersFactory())->state(['role' => 'admin'])->create();
 *
 * // 单条
 * $one = (new UsersFactory())->createOne();
 * ```
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-13 01:05:00 $
 * @package Candy\Extend\Testing
 */

declare(strict_types=1);

namespace Candy\Extend\Testing;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class Factory
{
    /** @var string 目标 Model 名（M() 的驼峰名） */
    protected string $model = '';

    /** @var int 构造条数 */
    protected int $count = 1;

    /** @var array<string, mixed> 覆盖字段 */
    protected array $states = [];

    /** @var int 唯一值计数（配合 unique() 使用） */
    private static int $sequence = 0;

    /**
     * 子类实现：返回一条记录的默认字段蓝图
     *
     * @return array<string, mixed>
     */
    abstract protected function definition(): array;

    /**
     * 设置构造条数
     *
     * @param int $count 条数
     * @return static
     */
    public function count(int $count): static
    {
        $this->count = max(0, $count);

        return $this;
    }

    /**
     * 覆盖部分字段（可多次调用，后者覆盖前者）
     *
     * @param array<string, mixed> $state 字段覆盖
     * @return static
     */
    public function state(array $state): static
    {
        foreach ($state as $key => $value) {
            $this->states[(string) $key] = $value;
        }

        return $this;
    }

    /**
     * 只构造记录，不写库
     *
     * @return array<int, array<string, mixed>>
     */
    public function make(): array
    {
        $rows = [];
        for ($i = 0; $i < $this->count; $i++) {
            $row = array_merge($this->definition(), $this->states);
            // 闭包值在每行构造时才求值（可实现随机/唯一值）
            foreach ($row as $key => $value) {
                if ($value instanceof \Closure) {
                    $row[$key] = $value($i);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 构造并写入数据库
     *
     * @return array<int, array<string, mixed>> 写入后的记录（含自增主键）
     */
    public function create(): array
    {
        $rows = [];
        foreach ($this->make() as $row) {
            $id = M($this->modelName())->add($row);
            if ($id === false || $id === null) {
                continue;
            }
            // 回读一次，确保拿到默认值/时间戳
            $saved = M($this->modelName())->find((int) $id);
            $rows[] = is_array($saved) ? $saved : array_merge($row, ['id' => $id]);
        }

        return $rows;
    }

    /**
     * 构造并写入单条
     *
     * @return array<string, mixed> 空数组表示写入失败
     */
    public function createOne(): array
    {
        $rows = $this->count(1)->create();

        return $rows[0] ?? [];
    }

    /**
     * 生成唯一后缀（跨实例共享计数，保证同进程内不重复）
     *
     * @return string
     */
    protected function unique(): string
    {
        self::$sequence++;

        return (string) self::$sequence . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    }

    /**
     * 随机字符串
     *
     * @param int $length 长度
     * @return string
     */
    protected function randomString(int $length = 8): string
    {
        $length = max(1, $length);
        $bytes  = (int) ceil($length / 2);

        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }

    /**
     * 随机整数
     *
     * @param int $min 最小
     * @param int $max 最大
     * @return int
     */
    protected function randomInt(int $min = 1, int $max = 1000000): int
    {
        return random_int($min, $max);
    }

    /**
     * 从数组随机取一个元素
     *
     * @param array<int|string, mixed> $items 候选
     * @param mixed                    $default 空数组时的默认值
     * @return mixed
     */
    protected function pick(array $items, mixed $default = null): mixed
    {
        if ($items === []) {
            return $default;
        }
        $keys = array_keys($items);

        return $items[$keys[array_rand($keys)]];
    }

    /**
     * 按概率返回布尔
     *
     * @param float $trueProbability 为真的概率（0~1）
     * @return bool
     */
    protected function chance(float $trueProbability = 0.5): bool
    {
        return (random_int(0, 9999) / 10000) < $trueProbability;
    }

    /**
     * 目标 Model 名
     *
     * @return string
     */
    private function modelName(): string
    {
        if ($this->model === '') {
            // 未声明时按工厂类名推导：UsersFactory => Users
            $short = (new \ReflectionClass($this))->getShortName();
            $this->model = preg_replace('/Factory$/', '', $short) ?: $short;
        }

        return $this->model;
    }
}
