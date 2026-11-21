<?php
/***
 * 文章页脚版权
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<footer class="blog-footer copyright-footer">
    <p>&copy; <?= (int) blog('year') ?> <?= blog_esc(blog('name')) ?></p>
    <p>Built with <a href="https://github.com/fingerboy2019/Candy" target="_blank" rel="noopener noreferrer">Candy</a></p>
</footer>
