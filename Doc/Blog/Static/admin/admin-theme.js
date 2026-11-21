/**
 * Candy Blog 管理后台 — 深色模式切换与持久化
 *
 * 依赖：<html data-theme>（layout.php 已在 head 首帧脚本预置本地主题）
 * 机制：html.setAttribute('data-theme','dark'|'light') + localStorage('theme')
 *       配合 admin.css 中 html[data-theme="dark"] body.blog-admin 覆盖。
 * 说明：登录页（blog-admin--login）同样经 layout.php 加载本脚本，故登录态也生效。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-01
 */
(function () {
    'use strict';

    var html = document.documentElement;
    var btn = document.getElementById('blogAdminThemeToggle');
    var icon = document.getElementById('blogAdminThemeIcon');

    function currentTheme() {
        return html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function apply(theme, persist) {
        theme = theme === 'dark' ? 'dark' : 'light';
        html.setAttribute('data-theme', theme);
        if (icon) {
            icon.textContent = theme === 'dark' ? '☀' : '☾';
        }
        if (persist !== false) {
            try { localStorage.setItem('theme', theme); } catch (e) {}
        }
    }

    function toggle() {
        apply(currentTheme() === 'dark' ? 'light' : 'dark');
    }

    /**
     * 初始化：localStorage 优先 → 系统偏好兜底 → 默认 light
     * （head 首帧脚本已处理 localStorage 防闪烁；此处再处理无 localStorage 时系统偏好）
     */
    function init() {
        var saved = null;
        try { saved = localStorage.getItem('theme'); } catch (e) {}
        if (saved === 'dark') {
            apply('dark', false);
        } else if (saved === 'light') {
            apply('light', false);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            apply('dark', false);
        } else {
            apply('light', false);
        }
    }

    function bind() {
        if (btn && btn.dataset.themeBound !== '1') {
            btn.dataset.themeBound = '1';
            btn.addEventListener('click', toggle);
        }
        // 系统偏好变化：仅在用户未手动设置过主题时跟随
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
                var saved = null;
                try { saved = localStorage.getItem('theme'); } catch (err) {}
                if (!saved) {
                    apply(e.matches ? 'dark' : 'light', false);
                }
            });
        }
    }

    init();
    bind();
})();
