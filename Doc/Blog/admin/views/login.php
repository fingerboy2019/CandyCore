<?php
/***
 * 博客管理登录页
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:22:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="blog-admin-login-wrap">
    <div class="blog-admin-login-card">
        <h1><?= htmlspecialchars((string) ($siteName ?? '博客'), ENT_QUOTES) ?></h1>
        <p class="blog-admin-login-sub">管理后台登录</p>
        <form class="layui-form" id="blogAdminLoginForm" autocomplete="off">
            <div class="layui-form-item">
                <input type="text" name="username" required placeholder="用户名" class="layui-input">
            </div>
            <div class="layui-form-item">
                <input type="password" name="password" required placeholder="密码" class="layui-input">
            </div>
            <?php if (!empty($captchaEnabled)) : ?>
            <div class="layui-form-item blog-admin-captcha-row">
                <input type="text" name="captcha" required placeholder="验证码" class="layui-input" maxlength="6">
                <img id="blogAdminCaptcha" src="<?= htmlspecialchars($__blogAdminBase, ENT_QUOTES) ?>/captcha" alt="验证码" title="点击刷新">
            </div>
            <?php endif; ?>
            <div class="layui-form-item">
                <input type="checkbox" name="remember" value="1" title="保持登录" lay-skin="primary">
            </div>
            <div class="layui-form-item">
                <button type="submit" class="layui-btn layui-btn-fluid layui-btn-normal">登录</button>
            </div>
        </form>
        <p class="blog-admin-login-tip">默认账号 admin / admin123（首次登录后请在「系统配置 → 后台账号」修改）</p>
    </div>
</div>
