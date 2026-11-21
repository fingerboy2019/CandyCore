<?php
/***
 * Markdown State
 *
 * 业务说明：解析器运行时状态与初始化
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait State
{
    private static function initializeData(): void
    {
        self::$mark = [
            'code'=>false,
            'mermaid'=>false,
            'math'=>false,
            'flow'=>false,
            'markmap'=>false,
            'chart'=>false,
            'echarts'=>false,
            'slideshow'=>false,
            'docloaded'=>false,
            'password'=>false,
            'include'=>''
        ];
        self::$toc = [];
        self::$allBlocks = []; 
        self::$index = 0;
        self::$footnotes = [];
        self::$links = [];
        self::$paragraphWrap = ['<div class="row"><div class="col-md-12">', '</div></div>', '<br />', true];

        // 当前页 URL 目录：优先使用 Core 传入的 pageUrlBase（根模式 / 与 /Doc/ 兼容模式）
        $server = I('server.', [], '');
        if (!empty(self::$config['pageUrlBase'])) {
            self::$pathUrl = (string) self::$config['pageUrlBase'];
        } elseif (!empty($server['DOCUMENT_URI']) && !empty($server['SCRIPT_NAME'])) {
            self::$pathUrl = '/' . dirname(trim(str_replace((string)$server['SCRIPT_NAME'], '', (string)$server['DOCUMENT_URI']), '/'));
        } else {
            // CLI 等无 Web 上下文时回退为 /
            self::$pathUrl = '/';
        }
    }
}
