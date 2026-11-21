<?php
/***
 * 博客评论审核页
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 17:45:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$statusFilter = (string) ($statusFilter ?? 'pending');
$keyword = (string) ($keyword ?? '');
$rows = is_array($rows ?? null) ? $rows : [];
$pendingCount = (int) ($pendingCount ?? 0);
$base = rtrim((string) ($baseUrl ?? ''), '/');
$portal = rtrim((string) ($portalUrl ?? '/Blog/'), '/');

$statusLabels = [
    'pending' => '待审核',
    'approved' => '已通过',
    'spam' => '垃圾',
    'all' => '全部',
];
?>
<div class="blog-admin-comments" id="blogAdminComments" data-status="<?= htmlspecialchars($statusFilter, ENT_QUOTES) ?>">
    <header class="blog-admin-page-head">
        <div>
            <h2>评论管理</h2>
            <p>存储于正文目录 <code>.comments/{crc32}.json</code>；前台支持嵌套回复与 <code>:emoji:</code></p>
        </div>
        <div class="blog-admin-comments-tabs">
            <?php foreach ($statusLabels as $key => $label) : ?>
            <a class="blog-admin-chip<?= $statusFilter === $key ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars($base . '/comment?status=' . $key, ENT_QUOTES) ?>">
                <?= htmlspecialchars($label, ENT_QUOTES) ?>
                <?php if ($key === 'pending' && $pendingCount > 0) : ?>
                <em><?= $pendingCount ?></em>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="blog-admin-comment-toolbar">
            <div class="blog-admin-comment-search">
                <input type="search" id="blogCommentKeyword" class="layui-input"
                       placeholder="搜索昵称 / 邮箱 / 内容 / 文章…"
                       value="<?= htmlspecialchars((string) ($keyword ?? ''), ENT_QUOTES) ?>"
                       autocomplete="off">
            </div>
            <div class="blog-admin-comment-export">
                <button type="button" class="layui-btn layui-btn-xs layui-btn-primary" data-export="csv">导出 CSV</button>
                <button type="button" class="layui-btn layui-btn-xs layui-btn-primary" data-export="json">导出 JSON</button>
            </div>
        </div>
    </header>

    <?php if ($rows === []) : ?>
    <div class="blog-admin-empty">当前筛选下暂无评论</div>
    <?php else : ?>
    <div class="blog-admin-comment-list">
        <?php foreach ($rows as $row) :
            $id = (string) ($row['id'] ?? '');
            $post = (string) ($row['post'] ?? '');
            $name = (string) ($row['name'] ?? '');
            $content = (string) ($row['content'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $created = (int) ($row['created_at'] ?? 0);
            $tags = (array) ($row['tags'] ?? []);
            $postUrl = $portal . '/' . preg_replace('/\.md$/i', '', $post);
            $bodyHtml = \Candy\Extend\Doc\Blog\Comment::formatContentHtml($content);
            ?>
        <article class="blog-admin-comment-card" data-id="<?= htmlspecialchars($id, ENT_QUOTES) ?>" data-post="<?= htmlspecialchars($post, ENT_QUOTES) ?>">
            <header class="blog-admin-comment-meta">
                <strong><?= htmlspecialchars($name, ENT_QUOTES) ?></strong>
                <span class="blog-admin-status blog-admin-status--<?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES) ?></span>
                <time><?= $created > 0 ? date('Y-m-d H:i', $created) : '' ?></time>
                <a href="<?= htmlspecialchars($postUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener" class="blog-admin-comment-post"><?= htmlspecialchars($post, ENT_QUOTES) ?></a>
            </header>
            <?php if ($tags !== []) : ?>
            <div class="blog-admin-comment-tags">
                <?php foreach ($tags as $tag) : ?>
                <span><?= htmlspecialchars((string) $tag, ENT_QUOTES) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="blog-admin-comment-body"><?= $bodyHtml ?></div>
            <footer class="blog-admin-comment-actions">
                <?php if ($status !== 'approved') : ?>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-normal" data-act="approve">通过</button>
                <?php endif; ?>
                <?php if ($status !== 'pending') : ?>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-act="pending">待审</button>
                <?php endif; ?>
                <?php if ($status !== 'spam') : ?>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-warm" data-act="spam">标垃圾</button>
                <?php endif; ?>
                <button type="button" class="layui-btn layui-btn-sm" data-act="reply">回复</button>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-danger" data-act="remove">删除</button>
            </footer>
            <div class="blog-admin-comment-reply" hidden>
                <textarea rows="3" placeholder="以博主身份回复…"></textarea>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-normal" data-act="reply-send">发送回复</button>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
