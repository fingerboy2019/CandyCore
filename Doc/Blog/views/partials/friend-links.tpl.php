<?php
/***
 * 友情链接列表
 *
 * 依赖 $blogFriendLinksConf（normalizeFriendLinks 结果）或 $friendLinksItems + $friendLinksTitle
 * 可选 $friendLinksVariant：sidebar（默认）| footer
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:40:00 $
 *
 * @package APP\View\Blog
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$variant = (string) ($friendLinksVariant ?? 'sidebar');
$conf = is_array($blogFriendLinksConf ?? null) ? $blogFriendLinksConf : blog_friend_links();
$title = (string) ($friendLinksTitle ?? ($conf['title'] ?? '友情链接'));
$items = $friendLinksItems ?? blog_friend_links($variant === 'footer' ? 'footer' : 'sidebar');

if ($items === []) {
    return;
}

$listClass = $variant === 'footer' ? 'blog-bottom-links' : 'side-links';
$wrapClass = $variant === 'footer' ? 'blog-bottom-friends' : 'side-widget side-friends';
$titleTag = $variant === 'footer' ? 'h4' : 'h4';
$titleClass = $variant === 'footer' ? 'blog-bottom-friends-title' : 'side-widget-title';
?>
<div class="<?= htmlspecialchars($wrapClass, ENT_QUOTES, 'UTF-8') ?>">
    <<?= $titleTag ?> class="<?= htmlspecialchars($titleClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></<?= $titleTag ?>>
    <ul class="<?= htmlspecialchars($listClass, ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($items as $link) :
            $name = htmlspecialchars((string) ($link['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars((string) ($link['url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $desc = trim((string) ($link['description'] ?? ''));
            $external = !empty($link['external']);
            if ($name === '' || $url === '') {
                continue;
            }
            ?>
        <li>
            <a href="<?= $url ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?><?= $desc !== '' ? ' title="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= $name ?></a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
