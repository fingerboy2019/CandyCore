<?php
/**
 * 模拟结构层聚合 Trait（Memcached / GlobalData 共用）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

trait SimulatedDriver
{
    use SimulatedStructures;
    use SimulatedStructureApi;
    use SimulatedStream;
}
