<?php
/***
 * 门户首页布局
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

include dirname(__DIR__) . '/partials/topbar.tpl.php';
include dirname(__DIR__) . '/partials/masthead.tpl.php';
include dirname(__DIR__) . '/hero-carousel.tpl.php';
?>
<div class="blog-wrap">
    <div class="blog-grid">
        <?php include dirname(__DIR__) . '/home-static.tpl.php'; ?>
    </div>
</div>
<?php include dirname(__DIR__) . '/partials/footer.tpl.php'; ?>
