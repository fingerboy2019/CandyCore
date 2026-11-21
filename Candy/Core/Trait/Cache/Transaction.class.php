<?php
/**
 * Transaction 服务 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait Transaction {
    //事务
    private bool $inTransaction = false; // 是否开启事务
    private array $transactionQueue = []; // 事务命令队列

    /**
     * 方法映射表（短方法名 => 原始方法名）
     * @var array<string, string>
     */
    protected array $methodMap = [];

    /**
     * 合法的事务方法列表
     * @var array<string>
     */
    protected array $validTransactionMethods = [];

    /**
     * 读操作结果处理函数映射
     * @var array<string, callable>
     */
    protected array $readResultHandlers = [];

    /**
     * 事务方法前缀处理
     * @var array<string, string>
     */
    protected array $prefixMethods = [];

    /**
     * 是否已初始化基础方法
     * @var bool
     */
    private bool $methodsInitialized = false;

    /**
     * 初始化基础事务方法
     */
    private function initBaseMethods(): void
    {
        if ($this->methodsInitialized) {
            return;
        }

        // 使用 registerMethods 批量注册所有基础事务方法
        // 格式: ['短方法名', '原始方法名', '结果处理器/null']
        $this->registerMethods([
            // 基础操作
            ['set', '__rawSet', null],
            ['delete', '__rawDelete', null],
            ['clear', '__clear', null],
            ['mSet', '__rawMSet', null],
            ['mDelete', '__rawMDelete', null],
            ['bit', '__rawBit', null],
            ['lPush', '__rawPush', null],
            ['rPush', '__rawPush', null],
            ['lpop', '__rawPop', null],
            ['rpop', '__rawPop', null],
            ['incr', '__rawIncr', null],
            ['decr', '__rawIncr', null],
            ['incrByFloat', '__rawIncrByFloat', null],
            ['expire', '__rawExpire', null],
            ['persist', '__rawPersist', null],
            ['rename', '__rawRename', null],
            ['ttl', '__ttl', [$this, 'handleTtlResult']],
            ['has', '__rawHas', fn($r) => $r !== null],
            ['xAdd', '__rawXAdd', null],
            ['xRead', '__rawXRead', fn($r) => is_array($r) ? $r : []],
            ['xGroup', '__rawXGroup', null],
            ['xReadGroup', '__rawXReadGroup', fn($r) => is_array($r) ? $r : []],
            ['xAck', '__rawXAck', null],
            ['xRange', '__rawXRange', fn($r) => is_array($r) ? $r : []],
            
            // 集合操作
            ['sAdd', '__rawSet', null],
            ['sAddBulk', '__rawSet', null],
            ['sRem', '__rawSet', null],
            ['sIsMember', '__rawSIsMember', [$this, 'handleSIsMemberResult']],
            ['sCard', '__rawGet', fn($r) => is_array($r) ? count($r) : 0],
            ['sMembers', '__rawGet', fn($r) => is_array($r) ? $r : []],
            
            // 哈希操作
            ['hSet', '__rawSet', null],
            ['hMSet', '__rawSet', null],
            ['hDel', '__rawSet', null],
            ['hGet', '__rawGet', [$this, 'handleHGetResult']],
            ['hKeys', '__rawGet', fn($r) => is_array($r) ? array_keys($r) : []],
            ['hVals', '__rawGet', fn($r) => is_array($r) ? array_values($r) : []],
            ['hLen', '__rawGet', fn($r) => is_array($r) ? count($r) : 0],
            ['hGetAll', '__rawGet', fn($r) => is_array($r) ? $r : []],
            
            // 有序集合操作
            ['zAdd', '__rawSet', null],
            ['zRem', '__rawZRem', null],
            ['zRange', '__rawGet', [$this, 'handleZRangeResult']],
            ['zRevRange', '__rawZRevRange', [$this, 'handleZRevRangeResult']],
            ['zScore', '__rawGet', [$this, 'handleZScoreResult']],
            ['zRank', '__rawGet', [$this, 'handleZRankResult']],
            ['zRevRank', '__rawGet', [$this, 'handleZRevRankResult']],
            ['zCard', '__rawGet', fn($r) => is_array($r) ? count($r) : 0],
            ['zCount', '__rawGet', [$this, 'handleZCountResult']],
            ['zIncrBy', '__rawSet', null],
            
            // 栈操作
            ['stackPush', '__rawSet', null],
            ['stackPop', '__rawSet', null],
            ['stackClear', '__rawSet', null],
            
            // 其他操作
            ['setWithTags', '__rawSet', null],
            ['clearByTag', '__rawDelete', null],
            ['get', '__rawGet', [$this, 'handleGetResult']],
        ]);

        $this->methodsInitialized = true;
    }

    /**
     * 获取读操作结果处理器
     * @param string $methodName 方法名
     * @return callable|null
     */
    protected function getReadResultHandler(string $methodName): ?callable
    {
        return $this->readResultHandlers[$methodName] ?? null;
    }

    /**
     * 初始化所有事务方法
     * 包括基础方法和其他 Trait 注册的方法
     * 使用反射自动检测类使用的所有 Trait 并调用其初始化方法
     */
    protected function initTransactionMethods(): void
    {
        // 初始化基础事务方法
        $this->initBaseMethods();
        
        // 使用反射自动检测当前类使用的所有 Trait
        $reflection = new \ReflectionClass($this);
        $traits = $reflection->getTraits();
        
        foreach ($traits as $trait) {
            $traitName = $trait->getShortName();
            $initMethod = 'init' . $traitName . 'TransactionMethods';
            
            // 如果 Trait 定义了初始化方法，则调用它
            if (method_exists($this, $initMethod)) {
                $this->$initMethod();
            }
        }
    }

    /**
     * 注册合法事务操作方法（批量注册）
     * 
     * @param array $methods 方法注册数组
     *        格式: [
     *            ['短方法名', '原始方法名', '结果处理器方法名/闭包/null'],
     *            ['hGet', '__rawGet', 'handleHGetResult'],
     *            ['vectorAnnSearch', '__rawGet', fn($r) => is_array($r) ? $r : []],
     *            ['vectorSet', '__rawSet', null],
     *        ]
     */
    public function registerMethods(array $methods = []): void
    {
        foreach ($methods as $minfo) {
            // 解构方法信息
            // $shortMethod: 短方法名，如 'get'
            // $rawMethod: 原始方法名，如 '__rawGet'
            // $resultHandler: 结果处理器（方法名/闭包/null）
            [$shortMethod, $rawMethod, $resultHandler] = $minfo;

            // 检查方法名是否已注册
            if (in_array($shortMethod, $this->validTransactionMethods)) {
                throw new \InvalidArgumentException("Transaction method $shortMethod is already registered");
            }

            // 注册方法
            $this->validTransactionMethods[] = $shortMethod;
            $this->methodMap[$shortMethod] = $rawMethod;
            
            // 如果提供了结果处理器，直接存储（节省函数调用开销）
            if ($resultHandler !== null) {
                $this->readResultHandlers[$shortMethod] = $resultHandler;
            }
        }
    }
    
    /**
     * 执行事务性操作（闭包方式）
     * 使用全局锁确保所有操作在锁保护下执行，确保原子性和隔离性
     * @param callable $callback 回调函数，接收缓存实例作为参数
     * @return mixed 回调函数的返回值
     * @throws \RuntimeException 无法获取事务锁时抛出
     * @throws \Throwable 回调函数中抛出的异常
     */
    public function transactional(callable $callback)
    {
        $lockName = 'transaction';
        $lockTTL = 30; // 锁的TTL为30秒
        
        // 尝试获取锁
        if (!$this->__acquireLock($lockName, $lockTTL)) {
            throw new \RuntimeException('Failed to acquire transaction lock');
        }
        
        try {
            // 执行回调，传入当前缓存实例
            $result = $callback($this);
            return $result;
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            // 释放锁
            $this->__releaseLock($lockName);
        }
    }

    /**
     * 开启事务（MULTI）
     * 后续写操作入队缓存，不立即执行
     */
    public function multi(): bool
    {
        if ($this->inTransaction) {
            return false;
        }
        $this->inTransaction = true;
        $this->transactionQueue = []; // 清空队列
        return true;
    }

    /**
     * 执行事务（EXEC）
     * 批量执行所有队列命令，使用回滚机制保证原子性
     */
    public function exec(): array
    {
        if (!$this->inTransaction || empty($this->transactionQueue)) {
            return [];
        }

        $results = [];
        $rollbackStack = []; // 记录已执行的命令，用于回滚
        $lockName = 'transaction';
        $lockTTL = 30; // 锁的TTL为30秒
        $leaseInterval = 10; // 每10秒续租一次
        $leaseTimer = 0;
        
        try {
            // 尝试获取锁
            if (!$this->__acquireLock($lockName, $lockTTL)) {
                return [];
            }
            
            // 批量执行所有命令
            foreach ($this->transactionQueue as $command) {
                $method = $command['method'];
                $params = $command['params'];
                $originalMethod = $command['original_method'] ?? $method;
                
                // 检查是否需要续租锁
                $leaseTimer++;
                if ($leaseTimer >= $leaseInterval) {
                    // 续租失败说明锁可能已被别的进程夺走，继续执行会破坏原子性 → 中止并回滚
                    if (!$this->__acquireLock($lockName, $lockTTL)) {
                        throw new \RuntimeException('缓存事务锁续租失败，已中止执行');
                    }
                    $leaseTimer = 0;
                }
                
                // 记录回滚操作
                if ($method === '__rawSet') {
                    $oldValue = $this->__rawGet($params[0]);
                    $rollbackStack[] = ['method' => '__rawSet', 'params' => [$params[0], $oldValue]];
                } elseif ($method === '__rawDelete') {
                    $oldValue = $this->__rawGet($params[0]);
                    $rollbackStack[] = ['method' => '__rawSet', 'params' => [$params[0], $oldValue]];
                }
                
                // 执行命令并获取结果
                $result = $this->$method(...$params);
                
                // 处理读操作的结果
                if (in_array($originalMethod, $this->validTransactionMethods)) {
                    $handler = $this->getReadResultHandler($originalMethod);
                    if ($handler !== null) {
                        $result = $handler($result, $params);
                    }
                }
                
                $results[] = $result;
            }
        } catch (\Throwable $e) {
            // 回滚已执行的命令
            foreach (array_reverse($rollbackStack) as $rollback) {
                $this->{$rollback['method']}(...$rollback['params']);
            }
            $results = ['error' => $e->getMessage()];
        } finally {
            // 释放锁
            $this->__releaseLock($lockName);
            // 关闭事务
            $this->discard();
        }

        return $results;
    }

    /**
     * 丢弃事务（DISCARD）
     * 清空队列，关闭事务
     */
    public function discard(): bool
    {
        $this->inTransaction = false;
        $this->transactionQueue = [];
        return true;
    }
    
    /**
     * 处理 hGet 操作的结果
     */
    private function handleHGetResult($result, array $params)
    {
        if (isset($params[1])) {
            $field = $params[1];
            $default = $params[2] ?? null;
            return is_array($result) ? ($result[$field] ?? $default) : $default;
        }
        return $result;
    }
    
    /**
     * 处理 zRange 操作的结果
     */
    private function handleZRangeResult($result, array $params)
    {
        if (is_array($result) && !empty($result)) {
            $start = $params[1] ?? 0;
            $end = $params[2] ?? -1;
            $withScore = $params[3] ?? false;
            
            // 按分数正序排序
            asort($result, SORT_NUMERIC);
            $list = $end === -1 ? array_slice($result, $start) : array_slice($result, $start, $end - $start + 1);
            return $withScore ? $list : array_keys($list);
        } else {
            return [];
        }
    }
    
    /**
     * 处理 zRevRange 操作的结果
     */
    private function handleZRevRangeResult($result, array $params)
    {
        if (is_array($result) && !empty($result)) {
            $start = $params[1] ?? 0;
            $end = $params[2] ?? -1;
            $withScore = $params[3] ?? false;
            
            // 按分数倒序排序
            arsort($result, SORT_NUMERIC);
            $list = $end === -1 ? array_slice($result, $start) : array_slice($result, $start, $end - $start + 1);
            return $withScore ? $list : array_keys($list);
        } else {
            return [];
        }
    }
    
    /**
     * 处理 zScore 操作的结果
     */
    private function handleZScoreResult($result, array $params)
    {
        $member = $params[1] ?? null;
        return is_array($result) && $member !== null ? ($result[(string)$member] ?? null) : null;
    }
    
    /**
     * 处理 zRank 操作的结果
     */
    private function handleZRankResult($result, array $params)
    {
        $member = $params[1] ?? null;
        if (is_array($result) && !empty($result) && $member !== null) {
            asort($result, SORT_NUMERIC);
            $rank = array_search((string)$member, array_keys($result));
            return $rank === false ? null : (int)$rank;
        } else {
            return null;
        }
    }
    
    /**
     * 处理 zRevRank 操作的结果
     */
    private function handleZRevRankResult($result, array $params)
    {
        $member = $params[1] ?? null;
        if (is_array($result) && !empty($result) && $member !== null) {
            arsort($result, SORT_NUMERIC);
            $rank = array_search((string)$member, array_keys($result));
            return $rank === false ? null : (int)$rank;
        } else {
            return null;
        }
    }
    
    /**
     * 处理 zCount 操作的结果
     */
    private function handleZCountResult($result, array $params)
    {
        if (is_array($result) && !empty($result)) {
            $min = $params[1] ?? '-inf';
            $max = $params[2] ?? '+inf';
            $count = 0;
            foreach ($result as $score) {
                if (($min === '-inf' || $score >= $min) && ($max === '+inf' || $score <= $max)) {
                    $count++;
                }
            }
            return $count;
        } else {
            return 0;
        }
    }
    
    /**
     * 处理 ttl 操作的结果
     */
    private function handleTtlResult($result)
    {
        if (is_array($result) && isset($result['expire'])) {
            $expire = $result['expire'];
            if ($expire === 0) {
                return -1; // 永不过期
            } else {
                return max(0, $expire - time());
            }
        } else {
            return -2; // 键不存在
        }
    }
    
    /**
     * 处理 sIsMember 操作的结果
     */
    private function handleSIsMemberResult($result, array $params)
    {
        $member = $params[1] ?? null;
        return is_array($result) && $member !== null ? in_array($member, $result, true) : false;
    }
    
    /**
     * 处理 hExists 操作的结果
     */
    private function handleHExistsResult($result, array $params)
    {
        $field = $params[1] ?? null;
        return is_array($result) && $field !== null ? array_key_exists($field, $result) : false;
    }
    
    /**
     * 处理 geoDist 操作的结果
     */
    private function handleGeoDistResult($result, array $params)
    {
        $member1 = $params[1] ?? null;
        $member2 = $params[2] ?? null;
        $unit = $params[3] ?? 'm';
        if (is_array($result) && $member1 !== null && $member2 !== null) {
            // 这里简化处理，实际应该从 memberData 中获取经纬度并计算距离
            return 0.0;
        } else {
            return null;
        }
    }
    
    /**
     * 处理 geoHash 操作的结果
     */
    private function handleGeoHashResult($result, array $params)
    {
        $members = $params[1] ?? [];
        if (is_array($result) && !empty($members)) {
            $hashes = [];
            foreach ($members as $member) {
                $hashes[] = $result[(string)$member] ?? null;
            }
            return $hashes;
        } else {
            return [];
        }
    }
    
    /**
     * 处理 geoPos 操作的结果
     */
    private function handleGeoPosResult($result, array $params)
    {
        $members = $params[1] ?? [];
        if (is_array($result) && !empty($members)) {
            $positions = [];
            foreach ($members as $member) {
                $positions[] = $result[(string)$member] ?? null;
            }
            return $positions;
        } else {
            return [];
        }
    }
    
    /**
     * 处理 get 操作的结果
     */
    private function handleGetResult($result, array $params)
    {
        $default = $params[1] ?? null;
        if ($result === null) {
            return $default;
        }
        return $result;
    }

    /**
     * 内部：事务入队（写操作自动调用）
     */
    private function queueTransaction(string $method, array $params): void
    {
        if (str_contains($method, '::')) {
            $shortMethod = substr($method, strrpos($method, '::') + 2);
        } else {
            $shortMethod = substr($method, strrpos($method, '\\') + 1);
        }
        $rawMethod = $this->methodMap[$shortMethod] ?? $shortMethod;
        
        if (in_array($shortMethod, $this->validTransactionMethods)) {
            // 为操作添加前缀
            $params[0] = $this->prefix($params[0]);

            $this->transactionQueue[] = [
                'method' => $rawMethod,
                'params' => $params,
                'original_method' => $shortMethod
            ];
        } else {
            //操作不合法
            throw new \InvalidArgumentException("Invalid transaction method: $shortMethod");
        }
    }
}
