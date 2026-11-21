<?php
/***
 * 文档侧栏搜索框
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="search-form">
    <div class="ui small fluid icon input">
        <input type="search" id="doc-search-input" placeholder="搜索文档标题与正文..." autocomplete="off">
        <i class="icon search" role="button" tabindex="0" aria-label="搜索"></i>
    </div>
    <div class="search-results" id="search-results" hidden></div>
</div>
