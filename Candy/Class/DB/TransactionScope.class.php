<?php
/**
 * 请求/协程级数据库事务作用域状态。
 *
 * @package Candy\Extend\DB
 */
declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class TransactionScope
{
    public int $depth = 1;
    /** @var array<int,DBServer> */
    public array $participants = [];
    /** @var array<int,callable> */
    public array $afterCommit = [];

    public function __construct(public readonly DBServer $owner)
    {
        $this->participants[spl_object_id($owner)] = $owner;
    }

    /** @param DBServer $connection 参与连接 @return void */
    public function enlist(DBServer $connection): void
    {
        $this->participants[spl_object_id($connection)] = $connection;
        $connection->useWrite();
    }
}
