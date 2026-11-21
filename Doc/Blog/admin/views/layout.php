<?php
/***
 * 博客管理后台布局
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:22:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$publicBase = rtrim((string) ($publicBase ?? '/Public'), '/');
$staticBase = '/Doc/Blog/Static/admin';
$isLoginPage = str_ends_with((string) ($contentView ?? ''), 'login.php');
$__navPath = explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0];
$__navPath = rtrim($__navPath, '/');
$__navActive = 'dash';
if (preg_match('#/(editor|file|config|comment)#i', $__navPath, $__nm)) {
    $__navActive = strtolower($__nm[1]);
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string) ($title ?? '博客管理'), ENT_QUOTES, 'UTF-8') ?></title>
    <script>
        // 首帧应用保存的主题，避免深色下白色闪烁（须在 admin.css 之前执行）
        (function () {
            try {
                if (localStorage.getItem('theme') === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($publicBase, ENT_QUOTES) ?>/static/plugs/layui/css/layui.css?v=2.13.8">
    <?php if (!empty($needCodeEditor)) : ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($publicBase, ENT_QUOTES) ?>/static/plugs/codemirror/lib/codemirror.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($publicBase, ENT_QUOTES) ?>/static/plugs/codemirror/theme/material-darker.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($publicBase, ENT_QUOTES) ?>/static/theme/css/code-editor.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($staticBase, ENT_QUOTES) ?>/admin.css?v=20260801a">
</head>
<body class="blog-admin<?= $isLoginPage ? ' blog-admin--login' : '' ?>"<?php
$__blogAdminBase = trim((string) ($baseUrl ?? ''));
if ($__blogAdminBase === '') {
    $reqPath = explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0];
    if (preg_match('#^(/blog/[a-z0-9]{8}/admin)#i', $reqPath, $__bm)) {
        $__blogAdminBase = $__bm[1];
    } elseif (preg_match('#^/([a-z0-9]{8}/admin)#i', $reqPath, $__bm)) {
        $__blogAdminBase = '/' . $__bm[1];
    }
}
echo $__blogAdminBase !== '' ? ' data-blog-admin-base="' . htmlspecialchars($__blogAdminBase, ENT_QUOTES, 'UTF-8') . '"' : '';
?>>
<?php if (!$isLoginPage) : ?>
<header class="blog-admin-header" id="blogAdminHeader">
    <div class="blog-admin-brand"><?= htmlspecialchars((string) ($siteName ?? '博客'), ENT_QUOTES) ?> · 管理</div>
    <button type="button" class="blog-admin-nav-btn" id="blogAdminNavBtn" aria-expanded="false" aria-controls="blogAdminNav" aria-label="打开导航菜单">
        <span class="blog-admin-nav-btn-bar"></span>
        <span class="blog-admin-nav-btn-bar"></span>
        <span class="blog-admin-nav-btn-bar"></span>
    </button>
    <nav class="blog-admin-nav" id="blogAdminNav">
        <a href="<?= htmlspecialchars($__blogAdminBase, ENT_QUOTES) ?>/"<?= $__navActive === 'dash' ? ' class="is-active"' : '' ?>>管理概览</a>
        <a href="<?= htmlspecialchars($__blogAdminBase, ENT_QUOTES) ?>/editor"<?= $__navActive === 'editor' ? ' class="is-active"' : '' ?>>文章编辑</a>
        <a href="<?= htmlspecialchars($__blogAdminBase, ENT_QUOTES) ?>/file"<?= $__navActive === 'file' ? ' class="is-active"' : '' ?>>文件管理</a>
        <a href="<?= htmlspecialchars($__blogAdminBase, ENT_QUOTES) ?>/comment"<?= $__navActive === 'comment' ? ' class="is-active"' : '' ?>>评论管理</a>
        <a href="<?= htmlspecialchars($__blogAdminBase, ENT_QUOTES) ?>/config"<?= $__navActive === 'config' ? ' class="is-active"' : '' ?>>系统配置</a>
        <a href="<?= htmlspecialchars((string) ($portalUrl ?? '/Blog/'), ENT_QUOTES) ?>" target="_blank" rel="noopener">查看站点</a>
        <a href="<?= htmlspecialchars($__blogAdminBase, ENT_QUOTES) ?>/logout">退出</a>
    </nav>
    <button type="button" class="blog-admin-theme-btn" id="blogAdminThemeToggle" title="切换主题" aria-label="切换主题"><span id="blogAdminThemeIcon">☾</span></button>
    <div class="blog-admin-user"><?= htmlspecialchars((string) ($username ?? ''), ENT_QUOTES) ?></div>
</header>
<main class="blog-admin-main">
<?php endif; ?>
<?php
if (!empty($contentView) && is_file($contentView)) {
    include $contentView;
}
?>
<?php if (!$isLoginPage) : ?>
</main>
<?php endif; ?>
<script src="<?= htmlspecialchars($publicBase, ENT_QUOTES) ?>/static/plugs/layui/layui.js" charset="utf-8"></script>
<script>
layui.use(['layer', 'form', 'element'], function () {});
window.BLOG_ADMIN = {
    baseUrl: <?= json_encode($__blogAdminBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    publicBase: <?= json_encode($publicBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    portalUrl: <?= json_encode((string) ($portalUrl ?? '/Blog/'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    staticBase: '/Doc/Blog/Static/',
    imgBase: '/Doc/Blog/Static/img/',
    csrfToken: <?= json_encode((string) ($csrfToken ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    captchaEnabled: <?= !empty($captchaEnabled) ? 'true' : 'false' ?>,
    writable: <?= !empty($writable) ? 'true' : 'false' ?>
};
</script>
<?php if (!empty($needCodeEditor)) : ?>
<script src="<?= htmlspecialchars($publicBase, ENT_QUOTES) ?>/static/theme/js/code-editor.js?v=20260716c"></script>
<?php endif; ?>
<script src="<?= htmlspecialchars($staticBase, ENT_QUOTES) ?>/admin-theme.js?v=20260801a"></script>
<script src="<?= htmlspecialchars($staticBase, ENT_QUOTES) ?>/admin.js?v=20260801a"></script>
<?php if (!empty($needFileManager)) : ?>
<script src="<?= htmlspecialchars($staticBase, ENT_QUOTES) ?>/admin-file.js?v=20260731a"></script>
<?php endif; ?>
<?php if (!empty($needCommentsAdmin)) : ?>
<script src="<?= htmlspecialchars($staticBase, ENT_QUOTES) ?>/admin-comments.js?v=20260731b"></script>
<?php endif; ?>
<?php if (!empty($needDashboard)) : ?>
<script src="<?= htmlspecialchars($staticBase, ENT_QUOTES) ?>/admin-dash.js?v=20260731d"></script>
<?php endif; ?>
</body>
</html>
