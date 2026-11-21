<?php
/***
 * 记录未找到异常
 *
 * 业务说明：`findOrFail()` / `firstOrFail()` 找不到记录时抛出，
 * 让调用方用统一方式捕获「记录不存在」，不必每处手写 `if (!$row)`。
 *
 * ```php
 * try {
 *     $ticket = M('Tickets')->findOrFail($id);
 * } catch (\Candy\Extend\DB\Exception\RecordNotFoundException $e) {
 *     $this->assign('code', 404);
 *     $this->assign('message', '记录不存在');
 *     $this->display();
 * }
 * ```
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-13 01:25:00 $
 * @package Candy\Extend\DB\Exception
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Exception;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class RecordNotFoundException extends \RuntimeException
{
}
