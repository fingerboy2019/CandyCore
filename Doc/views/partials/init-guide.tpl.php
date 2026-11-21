<?php
/***
 * 初始化引导（仅未初始化时显示）
 *
 * 框架在「未初始化」时以文档模式运行。本区块给使用者一个一键入口：
 * 点击「开始初始化」访问 /?start —— query 形式不依赖伪静态，任何环境都能点。
 * 初始化完成后才需要配置伪静态，这里一并给出 nginx / apache 示例。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-21 20:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

// 已初始化（存在 init.lock）就不再显示
if (is_file(C('RUNTIME') . 'init.lock')) {
    return;
}

// 只在首页显示，避免干扰其它文档页阅读
$__path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$__isHome = in_array(rtrim($__path, '/'), ['', '/Doc', '/Doc/index.html'], true);
if (!$__isHome) {
    return;
}

$__host = htmlspecialchars((string) ($_SERVER['HTTP_HOST'] ?? 'your-domain.com'), ENT_QUOTES, 'UTF-8');
?>
<div class="candy-init-guide" style="margin:28px 0 8px;padding:22px 24px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px">
    <div style="font-size:18px;font-weight:600;color:#0f172a;margin-bottom:8px">开始使用 Candy</div>
    <div style="font-size:14px;color:#334155;line-height:1.9;margin-bottom:16px">
        当前项目<strong>尚未初始化</strong>。点击下面的按钮完成初始化（会创建 <code>Application/</code>、<code>Source/Inc/</code> 等骨架并写运行时目录）。
        <br>这一步用 <code>/?start</code>，<strong>不需要伪静态</strong>；初始化完成后再按下方示例配置伪静态即可。
    </div>
    <p style="margin:0 0 18px">
        <a href="/?start" style="display:inline-block;padding:11px 26px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:15px;font-weight:600">开始初始化</a>
        <span style="margin-left:12px;font-size:13px;color:#64748b">或手动访问 <code>/?start</code></span>
    </p>

    <details style="font-size:13px;color:#334155">
        <summary style="cursor:pointer;font-weight:600;color:#1d4ed8;margin-bottom:10px">初始化完成后：配置伪静态（nginx / apache）</summary>
        <div style="margin:10px 0 4px;font-weight:600">nginx</div>
        <pre style="margin:0 0 14px;padding:12px 14px;background:#0f172a;color:#e2e8f0;border-radius:8px;overflow-x:auto;font-size:12px;line-height:1.7">server {
    listen 80;
    server_name <?= $__host ?>;
    root /path/to/project;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ [^/]\.php(/|$) {
        fastcgi_pass unix:/tmp/php-cgi.sock;
        fastcgi_index index.php;
        include fastcgi.conf;
    }

    # 框架与源码目录不对外
    location ~ ^/(Candy|Source|Runtime|Application|Data)/ {
        return 404;
    }
}</pre>
        <div style="margin:0 0 4px;font-weight:600">apache（.htaccess 放项目根）</div>
        <pre style="margin:0;padding:12px 14px;background:#0f172a;color:#e2e8f0;border-radius:8px;overflow-x:auto;font-size:12px;line-height:1.7">Options +FollowSymLinks
RewriteEngine On

# 已存在的文件/目录直接返回
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
# 其余全部交给入口
RewriteRule ^(.*)$ index.php/$1 [L,QSA]

# 框架与源码目录不对外
RewriteRule ^(Candy|Source|Runtime|Application|Data)/ - [F,L]</pre>
        <div style="margin:12px 0 0;color:#64748b">提示：可以在浏览器直接访问 <code>/sys/version</code>，能返回 JSON 说明伪静态已生效。</div>
    </details>
</div>
