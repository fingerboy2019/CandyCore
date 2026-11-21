/**
 * 博客管理概览脚本
 */
(function () {
    'use strict';

    function init() {
        var box = document.getElementById('dashOverview');
        if (!box) return;
        var tpl = document.getElementById('blogDashStatsTpl');
        if (!tpl) return;

        var baseUrl = (window.BLOG_ADMIN && window.BLOG_ADMIN.baseUrl) || '';
        fetch(baseUrl + '/dash/overview.json', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.code !== 0 || !res.data) {
                    box.innerHTML = '<div class="blog-admin-empty">加载失败</div>';
                    return;
                }
                var d = res.data;
                box.innerHTML = tpl.innerHTML;

                document.getElementById('dashArticleTotal').textContent = d.article_total || 0;
                document.getElementById('dashCategoryTotal').textContent = d.category_total || 0;
                document.getElementById('dashCommentTotal').textContent = d.comment_total || 0;
                document.getElementById('dashCommentPending').textContent = d.comment_pending || 0;

                // 最近更新
                var recent = document.getElementById('dashRecentFiles');
                var files = d.recent_files || [];
                if (!files.length) {
                    recent.innerHTML = '<li class="blog-dash-recent-empty">暂无文章</li>';
                } else {
                    var base = (window.BLOG_ADMIN && window.BLOG_ADMIN.portalUrl) || '/Blog/';
                    recent.innerHTML = files.map(function (f) {
                        var rel = String(f.path || '');
                        var url = base + encodePathSegments(rel.replace(/\.md$/i, ''));
                        return '<li class="blog-dash-recent-item">' +
                            '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(rel) + '</a>' +
                            '<span class="blog-dash-recent-time">' + escapeHtml(f.mtime || '') + '</span>' +
                            '</li>';
                    }).join('');
                }

                // 存储大小 + 字数
                document.getElementById('dashStorageSize').textContent = formatSize(d.storage_size || 0);
                document.getElementById('dashWordsTotal').textContent = formatNum(d.words_total || 0);
                document.getElementById('dashWordsAvg').textContent = formatNum(d.words_avg || 0);

                // 分类热度
                renderCategoryStats(d.category_stats || []);

                // 评论趋势
                renderCommentTrend(d.comment_trend || []);
            })
            .catch(function (err) {
                box.innerHTML = '<div class="blog-admin-empty">概览加载失败，请刷新页面重试</div>';
                console.error('Dashboard 加载失败:', err);
            });
    }

    function encodePathSegments(path) {
        return String(path).split('/').map(function (seg) {
            return encodeURIComponent(seg);
        }).join('/');
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function formatNum(n) {
        n = parseInt(n || 0, 10);
        return n.toLocaleString('zh-CN');
    }

    function renderCategoryStats(list) {
        var box = document.getElementById('dashCategoryStats');
        if (!box) return;
        if (!list.length) {
            box.innerHTML = '<div class="blog-dash-empty">暂无数据</div>';
            return;
        }
        var max = Math.max.apply(null, list.map(function (c) { return c.posts; })) || 1;
        box.innerHTML = list.map(function (c) {
            var pct = Math.round((c.posts / max) * 100);
            return '<div class="blog-dash-cat">' +
                '<div class="blog-dash-cat-head">' +
                '<span class="blog-dash-cat-name">' + escapeHtml(c.name) + '</span>' +
                '<span class="blog-dash-cat-nums">' + c.posts + ' 篇' +
                (c.comments ? ' · ' + c.comments + ' 评' : '') + '</span>' +
                '</div>' +
                '<div class="blog-dash-cat-bar"><i style="width:' + pct + '%"></i></div>' +
                '</div>';
        }).join('');
    }

    function renderCommentTrend(list) {
        var box = document.getElementById('dashCommentTrend');
        if (!box) return;
        if (!list.length) {
            box.innerHTML = '<div class="blog-dash-empty">暂无数据</div>';
            return;
        }
        var max = Math.max.apply(null, list.map(function (t) { return t.count; })) || 1;
        var total = list.reduce(function (s, t) { return s + t.count; }, 0);
        box.innerHTML = '<div class="blog-dash-trend-total">近 14 天共 <strong>' + total + '</strong> 条评论</div>' +
            '<div class="blog-dash-trend-chart">' + list.map(function (t) {
                var pct = t.count > 0 ? Math.max(8, Math.round((t.count / max) * 100)) : 2;
                return '<div class="blog-dash-trend-col" title="' + t.date + '：' + t.count + ' 条">' +
                    '<i style="height:' + pct + '%"></i>' +
                    '<span>' + t.date.slice(5) + '</span>' +
                    '</div>';
            }).join('') + '</div>';
    }

    function formatSize(bytes) {
        bytes = parseInt(bytes || 0, 10);
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
