<?php
/***
 * 缓存层向量能力 Trait
 *
 * 业务说明：向量 KV 存取、余弦相似度、前缀线性扫描（非 ANN）；适合缓存内小集合
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 07:59:38 $
 *
 * @package Candy\Core\Cache\Trait
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache;

use Candy\Core\Impl\Pack\Vector\VectorService;

trait Vector
{
    /**
     * 注册向量相关事务包装（若驱动启用事务队列）
     *
     * @return void
     * @access protected
     */
    protected function initVectorTransactionMethods(): void
    {
        $passList = fn($r) => is_array($r) ? $r : [];
        $this->registerMethods([
            ['vectorSet', '__rawSet', null],
            ['vectorGet', '__rawGet', null],
            ['vectorScanSearch', '__rawGet', $passList],
            ['vectorAnnSearch', '__rawGet', $passList],
            ['vectorDelete', '__rawDelete', null],
        ]);
    }

    /**
     * 预计算向量 L2 模长
     *
     * @param array $vector 向量
     * @return float
     */
    public function calculateVectorNorm(array $vector): float
    {
        return VectorService::l2Norm($vector);
    }

    /**
     * 写入单条向量（结构化存储）
     *
     * 存盘结构：dimension / vector / norm / timestamp
     * 长度必须与 $dimension 一致
     *
     * @param string $key 逻辑键
     * @param array $vector 浮点向量
     * @param int $dimension 声明维度
     * @param int $expire 过期秒数，0 永不过期
     * @return bool
     */
    public function vectorSet(string $key, array $vector, int $dimension, int $expire = 0): bool
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }

        if ($dimension <= 0 || count($vector) !== $dimension || !VectorService::isValidVector($vector)) {
            return false;
        }

        $data = [
            'dimension' => $dimension,
            'vector' => array_map('floatval', $vector),
            'norm' => $this->calculateVectorNorm($vector),
            'timestamp' => time(),
        ];

        return $this->__rawSet($this->prefix($key), $data, $expire);
    }

    /**
     * 读取单条向量结构
     *
     * @param string $key 逻辑键
     * @return array|null {dimension,vector,norm,timestamp} 或 null
     */
    public function vectorGet(string $key): ?array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }

        $data = $this->__rawGet($this->prefix($key));
        if (!is_array($data) || !isset($data['vector']) || !is_array($data['vector'])) {
            return null;
        }

        return $data;
    }

    /**
     * 删除单条向量
     *
     * @param string $key 逻辑键
     * @return bool
     */
    public function vectorDelete(string $key): bool
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }

        return $this->__rawDelete($this->prefix($key));
    }

    /**
     * 批量写入向量
     *
     * @param array $vectors [[key, vector, dimension], ...]
     * @param int $expire 过期秒数
     * @return bool 全部成功为 true；任一条失败仍继续，返回是否全部成功
     */
    public function vectorMSet(array $vectors, int $expire = 0): bool
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }

        $ok = true;
        foreach ($vectors as $item) {
            if (!is_array($item) || count($item) < 3) {
                $ok = false;
                continue;
            }
            [$key, $vector, $dimension] = $item;
            if (!$this->vectorSet((string)$key, (array)$vector, (int)$dimension, $expire)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * 前缀线性扫描近邻（非 ANN）
     *
     * 默认实现无法枚举键，返回空数组；Redis / Memory 等驱动应覆盖
     * 返回统一格式：[['key'=>..., 'score'=>...], ...]，按 score 降序
     *
     * @param string $prefix 逻辑键前缀
     * @param array $queryVector 查询向量
     * @param int $topK 返回条数
     * @param float $threshold 余弦相似度下限（含）
     * @return array<int, array{key:string,score:float}>
     */
    public function vectorScanSearch(string $prefix, array $queryVector, int $topK = 10, float $threshold = 0.0): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }

        // 通用驱动无键枚举能力
        unset($prefix, $queryVector, $topK, $threshold);
        return [];
    }

    /**
     * @deprecated 使用 vectorScanSearch；保留为别名，非 ANN
     *
     * @param string $key 前缀（历史参数名）
     * @param array $queryVector 查询向量
     * @param int $topK topK
     * @param float $threshold 阈值
     * @return array<int, array{key:string,score:float}>
     */
    public function vectorAnnSearch(string $key, array $queryVector, int $topK = 10, float $threshold = 0.0): array
    {
        return $this->vectorScanSearch($key, $queryVector, $topK, $threshold);
    }

    /**
     * 计算两向量余弦相似度
     *
     * @param array $vector1
     * @param array $vector2
     * @return float
     */
    public function vectorCosineSimilarity(array $vector1, array $vector2): float
    {
        return VectorService::cosineSimilarity($vector1, $vector2);
    }

    /**
     * 将 [key=>score] 或混合结构规范为 [['key','score'],...]
     *
     * @param array $map 候选
     * @param int $topK
     * @param float $threshold
     * @return array<int, array{key:string,score:float}>
     * @access protected
     */
    protected function normalizeVectorSearchResults(array $map, int $topK, float $threshold): array
    {
        $list = [];
        foreach ($map as $key => $score) {
            if (is_array($score) && isset($score['key'], $score['score'])) {
                $k = (string)$score['key'];
                $s = (float)$score['score'];
            } else {
                $k = (string)$key;
                $s = (float)$score;
            }
            if ($s < $threshold) {
                continue;
            }
            $list[] = ['key' => $k, 'score' => $s];
        }

        usort($list, static fn($a, $b) => $b['score'] <=> $a['score']);
        $topK = max(1, $topK);

        return array_slice($list, 0, $topK);
    }

    /**
     * 从缓存条目中取出 float 向量
     *
     * 兼容历史「裸 float[]」与结构化 `{vector:[]}`
     *
     * @param mixed $data
     * @return array<int,float>|null
     * @access protected
     */
    protected function extractStoredVector(mixed $data): ?array
    {
        if (!is_array($data) || $data === []) {
            return null;
        }
        if (isset($data['vector']) && is_array($data['vector'])) {
            return VectorService::isValidVector($data['vector']) ? $data['vector'] : null;
        }
        // 历史：整包即向量
        if (array_is_list($data) && VectorService::isValidVector($data)) {
            return $data;
        }

        return null;
    }
}
