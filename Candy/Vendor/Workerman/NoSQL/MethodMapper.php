<?php
namespace NoSQL;

use Candy\Core\Cache;

/**
 * NoSQL 方法映射工具类
 * 
 * ================================
 * 📖 功能说明
 * ================================
 * 
 * 本类负责服务端和客户端之间的方法映射，包括：
 * - 方法发现：通过反射获取 Cache 驱动的所有公开方法
 * - 黑名单过滤：屏蔽内部/危险方法，防止客户端调用
 * - 参数映射：自动映射客户端参数到服务端方法参数
 * 
 * ================================
 * 🔒 安全机制
 * ================================
 * 
 * 通过方法黑名单机制，确保危险方法不会被客户端调用。
 * 黑名单支持两种匹配模式：
 * 1. 精确匹配：方法名完全匹配（如 'loadDump'）
 * 2. 前缀匹配：以 '*' 结尾表示前缀匹配（如 '__*' 匹配所有 __ 开头的方法）
 * 
 * ================================
 * 📋 参数映射规则
 * ================================
 * 
 * 客户端发送参数时，支持多种命名风格：
 * 1. 精确匹配：参数名完全一致
 * 2. 蛇形转换：field_values → fieldValues
 * 3. 别名映射：ttl 可以是 expire, timeout, expires_in
 * 
 * ================================
 * ⚙️ 使用方式
 * ================================
 * 
 * 本类主要由 Server 和 Client 自动使用，一般不需要直接调用。
 * 
 * 服务端使用：
 * ```php
 * // Server 启动时构建方法缓存
 * MethodMapper::buildMethodCache('Candy\Core\Impl\Cache\Memory');
 * 
 * // 检查方法是否存在
 * if (MethodMapper::hasMethod('set')) {
 *     // 方法存在
 * }
 * 
 * // 提取参数
 * $params = MethodMapper::extractParams('set', [
 *     'cmd'   => 'set',
 *     'key'   => 'mykey',
 *     'value' => 'myvalue',
 *     'ttl'   => 3600,
 * ]);
 * // 返回: ['mykey', 'myvalue', 3600]
 * ```
 * 
 * 客户端使用：
 * ```php
 * // 获取方法参数列表（用于构建请求）
 * $params = MethodMapper::getMethodParams('set');
 * // 返回: ['key', 'value', 'ttl']
 * 
 * // 获取所有可用方法
 * $methods = MethodMapper::getAvailableMethods();
 * ```
 * 
 * @package NoSQL
 * @version 2.0.0
 * @since   1.0.0
 * 
 * @see     Server 服务端类
 * @see     Client 客户端类
 */
class MethodMapper
{
    /**
     * 方法缓存（进程内共享）
     * @var array<string, array>|null
     */
    protected static ?array $methodCache = null;

    /**
     * 禁止暴露的内部/危险方法（安全黑名单）
     * 支持通配符：'*' 结尾表示前缀匹配
     * @var array
     */
    protected static array $blockMethods = [
        // 所有 __ 开头的方法均为私有/魔术方法，不对外暴露
        '__*',
        // 静态工厂方法
        'store', 'flushInstance', 'notUsedUsed', '__callStatic',
        // 事务控制
        'transactional', 'multi', 'exec', 'discard',
        // 内部辅助方法
        'getStats', 'calculateVectorNorm', 'cosineSimilarity',
        'euclideanDistance', 'dotProduct',
        'parseNestedKey', 'getNestedValue', 'setNestedValue',
        'isValidVector', 'isVectorLengthMatch', 'prefix',
        // 其他底层方法
        'queueTransaction',
        // 私有/受保护方法
        'hllHash', 'optimizedCrc32Hash',
        'bFilterCalcBitSize', 'bFilterCalcHashCount', 'bFilterHash',
        'geoEncode', 'geoDecode', 'geoHaversine', 'geoPosInternal',
        'handleHGetResult', 'handleZRangeResult', 'handleZRevRangeResult',
        'handleZScoreResult', 'handleZRankResult', 'handleZRevRankResult',
        'handleZCountResult', 'handleTtlResult', 'handleSIsMemberResult',
        'handleHExistsResult', 'handleGeoDistResult', 'handleGeoHashResult',
        'handleGeoPosResult', 'handleGetResult',
        'getDriverNameAndConfig', 'generateInstanceKey',
        // 落盘相关（文件系统访问）
        'loadDump', 'autoDump', 'forceDump',
        // 内部键生成方法
        'compileInternalKey', 'getSystemKey',
        // 内存管理方法
        'checkMemoryLimit', 'convertToBytes',
        // LRU 相关方法
        'extractDbPrefix', 'touchLru', 'removeFromList',
        'cleanupExpiredKeys', 'lruElimination',
        // 锁相关内部方法
        'acquireReadLock', 'acquireWriteLock',
        'releaseReadLock', 'releaseWriteLock',
        'acquireGlobalReadLock', 'acquireGlobalWriteLock',
        'releaseGlobalReadLock', 'releaseGlobalWriteLock',
        'getShardLockName', 'isLockExpired',
        // 向量搜索内部方法
        'vectorAnnSearch', 'hnswInsert', 'hnswDelete',
        'hnswSearchLayer', 'randomHnswLevel',
        // 位图操作内部方法
        'handleBitOperation',
        // 范围查询内部方法
        'handleRangeOperation',
        // 析构函数
        '__destruct', '__releaseAllLocksOnShutdown',
    ];

    /**
     * 构建方法缓存
     * 通过反射获取 Cache 接口方法，并可选合并具体驱动类的特有方法
     * @param string|null $driverClass 具体驱动类名（如 'Candy\Core\Impl\Cache\Memory'）
     * @return array<string, array> 方法名 => [参数名列表]
     */
    public static function buildMethodCache(?string $driverClass = null): array
    {
        // 如果已缓存且未指定新驱动，直接返回
        if (self::$methodCache !== null && $driverClass === null) {
            return self::$methodCache;
        }

        // 1. 获取基类接口方法 (确保标准 API 始终可用)
        $methodsMap = self::reflectMethods(Cache::class);

        // 2. 如果指定了具体驱动类，合并其特有方法
        if ($driverClass !== null && $driverClass !== Cache::class) {
            $driverMethods = self::reflectMethods($driverClass);
            // 驱动方法优先（同名覆盖，通常驱动会重写方法签名以优化）
            $methodsMap = array_merge($methodsMap, $driverMethods);
        }

        // 3. 过滤黑名单并缓存
        self::$methodCache = [];
        foreach ($methodsMap as $name => $info) {
            if (!self::isBlocked($name)) {
                self::$methodCache[$name] = $info;
            }
        }

        return self::$methodCache;
    }

    /**
     * 辅助：反射指定类的所有 public 方法
     */
    private static function reflectMethods(string $className): array
    {
        $cache = [];
        try {
            $reflection = new \ReflectionClass($className);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $name = $method->getName();
                // 跳过魔术方法
                if (str_starts_with($name, '__')) continue;

                $params = [];
                foreach ($method->getParameters() as $param) {
                    $params[] = $param->getName();
                }

                $cache[$name] = [
                    'params' => $params,
                    'doc'    => $method->getDocComment() ?: '',
                ];
            }
        } catch (\Throwable $e) {
            // 忽略反射错误（如类未定义），避免影响主流程
        }
        return $cache;
    }

    /**
     * 获取方法参数列表
     * @param string $methodName 方法名
     * @return array|null 参数名列表，方法不存在返回 null
     */
    public static function getMethodParams(string $methodName): ?array
    {
        $cache = self::buildMethodCache();
        return $cache[$methodName]['params'] ?? null;
    }

    /**
     * 检查方法是否存在（不在黑名单中且已反射）
     * @param string $methodName 方法名
     * @return bool
     */
    public static function hasMethod(string $methodName): bool
    {
        $cache = self::buildMethodCache();
        return isset($cache[$methodName]);
    }

    /**
     * 获取所有可用方法
     * @return array<string, array> 方法名 => 参数列表
     */
    public static function getAvailableMethods(): array
    {
        $cache = self::buildMethodCache();
        $result = [];
        foreach ($cache as $name => $info) {
            $result[$name] = $info['params'];
        }
        return $result;
    }

    /**
     * 从请求数据中提取参数
     * 根据方法参数名自动映射
     * @param string $methodName 方法名
     * @param array $requestData 客户端请求数据
     * @return array 提取后的参数数组
     */
    public static function extractParams(string $methodName, array $requestData): array
    {
        $paramNames = self::getMethodParams($methodName);
        if ($paramNames === null) {
            return [];
        }

        $params = [];
        foreach ($paramNames as $name) {
            // 优先精确匹配
            if (array_key_exists($name, $requestData)) {
                $params[] = $requestData[$name];
                continue;
            }

            // 兼容蛇形命名（field_values）
            $snakeName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
            if ($snakeName !== $name && array_key_exists($snakeName, $requestData)) {
                $params[] = $requestData[$snakeName];
                continue;
            }

            // 兼容常见别名
            $aliases = self::getParamAliases($name);
            $found = false;
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $requestData)) {
                    $params[] = $requestData[$alias];
                    $found = true;
                    break;
                }
            }
            if ($found) {
                continue;
            }

            // 缺失：停止收集（客户端只传连续前缀参数，缺失的尾部可选参数由方法默认值接管）
            break;
        }

        return $params;
    }

    /**
     * 参数别名映射（用于兼容客户端不同命名习惯）
     * @param string $paramName 标准参数名
     * @return array 别名列表
     */
    protected static function getParamAliases(string $paramName): array
    {
        return match ($paramName) {
            'members'   => ['member', 'values'],
            'elements'  => ['element', 'items'],
            'field'     => ['field_name', 'key'],
            'value'     => ['val', 'data'],
            'ttl'       => ['expire', 'timeout', 'expires_in'],
            'start'     => ['from', 'offset'],
            'end'       => ['to', 'stop'],
            'count'     => ['limit', 'num', 'size'],
            'default'   => ['fallback'],
            'keys'      => ['key_list', 'key_array'],
            default     => [],
        };
    }

    /**
     * 检查方法是否在黑名单中
     * 支持通配符：'*' 结尾表示前缀匹配
     * @param string $methodName 方法名
     * @return bool
     */
    public static function isBlocked(string $methodName): bool
    {
        foreach (self::$blockMethods as $blocked) {
            if ($blocked === $methodName) {
                return true;
            }
            if (str_ends_with($blocked, '*')) {
                $prefix = substr($blocked, 0, -1);
                if (str_starts_with($methodName, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 清空方法缓存（用于热更新或测试）
     */
    public static function flushCache(): void
    {
        self::$methodCache = null;
    }
}
