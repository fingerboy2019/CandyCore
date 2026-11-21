<?php
/***
 * 分类列表页布局
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

include dirname(__DIR__) . '/partials/topbar.tpl.php';
include dirname(__DIR__) . '/partials/masthead.tpl.php';
?>
<div class="blog-wrap blog-wrap--list">
    <?php include dirname(__DIR__) . '/partials/category-list.tpl.php'; ?>
</div>
<?php include dirname(__DIR__) . '/partials/footer.tpl.php'; ?>
