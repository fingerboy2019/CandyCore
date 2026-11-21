<?php
/***
 * 全站页脚
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$profile = (array) blog('profile');
$qqQr = (string) ($profile['qq_qr'] ?? blog('img_base') . 'qr-placeholder.svg');
$wechatQr = (string) ($profile['wechat_qr'] ?? blog('img_base') . 'qr-placeholder.svg');
?>
<footer class="blog-bottom">
    <div class="blog-bottom-inner">
        <div class="blog-bottom-qr">
            <figure><img src="<?= blog_esc($qqQr) ?>" alt="QQ"><figcaption>我的 QQ</figcaption></figure>
            <figure><img src="<?= blog_esc($wechatQr) ?>" alt="微信"><figcaption>我的微信</figcaption></figure>
        </div>
        <div class="blog-bottom-state">
            <h4>站点声明</h4>
            <ol>
                <li>本站文章仅供学习交流，转载请注明出处。</li>
                <li>部分内容来自互联网，如有侵权请联系站长删除。</li>
                <li>本站由 Candy Markdown 博客模式驱动，首页内容从正文 Front Matter 动态聚合。</li>
            </ol>
            <?php if (blog_friend_links('footer') !== []) :
                $friendLinksVariant = 'footer';
                include __DIR__ . '/friend-links.tpl.php';
            endif; ?>
            <?php if (blog_echo('footer_extra') !== '') : ?>
            <p class="blog-bottom-extra"><?= blog_echo('footer_extra') ?></p>
            <?php endif; ?>
            <p class="blog-bottom-copy">Copyright &copy; <?= (int) blog('year') ?> <?= blog_esc(blog('name')) ?> 保留所有权利</p>
        </div>
    </div>
</footer>
