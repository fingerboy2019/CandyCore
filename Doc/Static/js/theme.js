/**
 * 文档站主题切换器
 *
 * 12 套主题动态切换：换 <link> href、写 cookie（服务端首屏注入）+ localStorage
 */
(function () {
    'use strict';

    var THEMES = [
        { id: 'default', label: '默认' },
        { id: 'academic', label: '学术' },
        { id: 'bauhaus', label: '包豪斯' },
        { id: 'brutalism', label: '粗野' },
        { id: 'cyberpunk', label: '赛博' },
        { id: 'film', label: '胶片' },
        { id: 'glass', label: '玻璃' },
        { id: 'knowledge', label: '知识' },
        { id: 'luxury', label: '轻奢' },
        { id: 'morandi', label: '莫兰迪' },
        { id: 'shopping', label: '购物' },
        { id: 'wemd', label: '微信' }
    ];

    var COOKIE_NAME = 'doc_theme';
    var LS_KEY = 'doc_theme';

    function currentTheme() {
        var link = document.getElementById('doc-theme-css');
        if (link) {
            var m = (link.getAttribute('href') || '').match(/css\/([a-z0-9_-]+)\.css$/);
            if (m) return m[1];
        }
        return document.body.getAttribute('data-doc-theme') || 'default';
    }

    function setThemeCookie(theme) {
        document.cookie = COOKIE_NAME + '=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
        try { localStorage.setItem(LS_KEY, theme); } catch (e) { /* ignore */ }
    }

    function isValidTheme(theme) {
        if (theme === 'default') return true;
        return THEMES.some(function (t) { return t.id === theme; });
    }

    function applyTheme(theme) {
        if (!isValidTheme(theme)) {
            theme = 'default';
        }
        var link = document.getElementById('doc-theme-css');
        if (link) {
            link.setAttribute('href', '/Doc/Static/css/' + theme + '.css');
        }
        document.body.setAttribute('data-doc-theme', theme);
        setThemeCookie(theme);
    }

    function renderPanel() {
        var wrap = document.getElementById('doc-theme-switcher');
        if (!wrap) return;
        var current = currentTheme();

        wrap.innerHTML = '';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'doc-theme-trigger';
        btn.setAttribute('aria-expanded', 'false');
        var currentLabel = '默认';
        for (var i = 0; i < THEMES.length; i++) {
            if (THEMES[i].id === current) currentLabel = THEMES[i].label;
        }
        btn.innerHTML = '<span class="doc-theme-dot"></span><span class="doc-theme-label">主题：' + currentLabel + '</span><span class="doc-theme-caret">▾</span>';

        var panel = document.createElement('div');
        panel.className = 'doc-theme-panel';
        panel.hidden = true;

        var darkRow = document.createElement('label');
        darkRow.className = 'doc-theme-dark';
        var darkBox = document.createElement('input');
        darkBox.type = 'checkbox';
        darkBox.className = 'doc-theme-dark-input';
        darkBox.checked = document.body.classList.contains('dark-mode');
        var darkLabel = document.createElement('span');
        darkLabel.textContent = '深色模式';
        darkRow.appendChild(darkBox);
        darkRow.appendChild(darkLabel);
        panel.appendChild(darkRow);

        var grid = document.createElement('div');
        grid.className = 'doc-theme-grid';
        THEMES.forEach(function (t) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'doc-theme-item' + (t.id === current ? ' is-active' : '');
            item.setAttribute('data-theme', t.id);
            item.innerHTML = '<i class="doc-theme-swatch doc-theme-swatch--' + t.id + '"></i><span>' + t.label + '</span>';
            grid.appendChild(item);
        });
        panel.appendChild(grid);
        wrap.appendChild(btn);
        wrap.appendChild(panel);

        btn.addEventListener('click', function () {
            var open = !panel.hidden;
            panel.hidden = open;
            btn.setAttribute('aria-expanded', String(!open));
        });
        darkBox.addEventListener('change', function () {
            setDarkMode(darkBox.checked);
        });

        grid.addEventListener('click', function (e) {
            var item = e.target.closest('.doc-theme-item');
            if (!item) return;
            var theme = item.getAttribute('data-theme');
            applyTheme(theme);
            var label = '默认';
            for (var i = 0; i < THEMES.length; i++) {
                if (THEMES[i].id === theme) label = THEMES[i].label;
            }
            btn.querySelector('.doc-theme-label').textContent = '主题：' + label;
            grid.querySelectorAll('.doc-theme-item').forEach(function (el) {
                el.classList.toggle('is-active', el === item);
            });
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) {
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function setDarkMode(enabled) {
        document.body.classList.toggle('dark-mode', enabled);
        try { localStorage.setItem('doc_dark', enabled ? '1' : '0'); } catch (e) { /* ignore */ }
        document.cookie = 'doc_dark=' + (enabled ? '1' : '0') + '; path=/; max-age=31536000; SameSite=Lax';
    }

    function init() {
        // 深色模式：cookie 优先，localStorage 兜底
        var darkStored = null;
        try { darkStored = localStorage.getItem('doc_dark'); } catch (e) { /* ignore */ }
        var darkCookie = document.cookie.split('; ').some(function (c) { return c.indexOf('doc_dark=1') === 0; });
        if (darkCookie || (darkStored === '1' && !document.cookie.split('; ').some(function (c) { return c.indexOf('doc_dark=') === 0; }))) {
            document.body.classList.add('dark-mode');
        }

        // 首次访问无 cookie 时，若 localStorage 有记忆则应用（服务端已按 cookie 注入，此处兜底）
        var stored = null;
        try { stored = localStorage.getItem(LS_KEY); } catch (e) { /* ignore */ }
        if (stored && !document.cookie.split('; ').some(function (c) { return c.indexOf(COOKIE_NAME + '=') === 0; })) {
            applyTheme(stored);
        }
        renderPanel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
