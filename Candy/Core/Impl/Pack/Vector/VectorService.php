<?php
/***
 * 向量纯计算工具
 *
 * 业务说明：校验、维度、余弦/欧氏/点积/L2 模长；不含 ANN/HNSW（检索见 DB\\Vector 旁路或 Cache 线性扫描）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 07:59:38 $
 *
 * @package Candy\Core\Impl\Pack\Vector
 */

namespace Candy\Core\Impl\Pack\Vector;

class VectorService
{
    /**
     * 校验向量是否均为数值且非空
     *
     * @param array $vector
     * @return bool
     */
    public static function isValidVector(array $vector): bool
    {
        if ($vector === []) {
            return false;
        }
        foreach ($vector as $v) {
            if (!is_numeric($v)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 向量维度
     *
     * @param array $vector
     * @return int
     */
    public static function getVectorDimension(array $vector): int
    {
        return count($vector);
    }

    /**
     * L2 模长
     *
     * @param array $vector
     * @return float
     */
    public static function l2Norm(array $vector): float
    {
        $sum = 0.0;
        foreach ($vector as $v) {
            $f = (float)$v;
            $sum += $f * $f;
        }
        return sqrt($sum);
    }

    /**
     * 余弦相似度（[-1,1]，零向量返回 0）
     *
     * @param array $vec1
     * @param array $vec2
     * @return float
     */
    public static function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dot = 0.0;
        $n1 = 0.0;
        $n2 = 0.0;
        $length = min(count($vec1), count($vec2));
        for ($i = 0; $i < $length; $i++) {
            $a = (float)$vec1[$i];
            $b = (float)$vec2[$i];
            $dot += $a * $b;
            $n1 += $a * $a;
            $n2 += $b * $b;
        }
        if ($n1 === 0.0 || $n2 === 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($n1) * sqrt($n2));
    }

    /**
     * 欧氏距离
     *
     * @param array $vec1
     * @param array $vec2
     * @return float
     */
    public static function euclideanDistance(array $vec1, array $vec2): float
    {
        $sum = 0.0;
        $length = min(count($vec1), count($vec2));
        for ($i = 0; $i < $length; $i++) {
            $d = (float)$vec1[$i] - (float)$vec2[$i];
            $sum += $d * $d;
        }
        return sqrt($sum);
    }

    /**
     * 点积
     *
     * @param array $vec1
     * @param array $vec2
     * @return float
     */
    public static function dotProduct(array $vec1, array $vec2): float
    {
        $dot = 0.0;
        $length = min(count($vec1), count($vec2));
        for ($i = 0; $i < $length; $i++) {
            $dot += (float)$vec1[$i] * (float)$vec2[$i];
        }
        return $dot;
    }
}
