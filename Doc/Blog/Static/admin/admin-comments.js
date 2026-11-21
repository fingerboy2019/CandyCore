/**
 * 博客后台 · 评论审核
 */
(function () {
    'use strict';

    function baseUrl() {
        return (window.BLOG_ADMIN && BLOG_ADMIN.baseUrl) || '';
    }

    function postJson(path, data) {
        var headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        // POST 写请求附带 CSRF 令牌（服务端校验，防跨站提交）
        if (window.BLOG_ADMIN && window.BLOG_ADMIN.csrfToken) {
            headers['X-CSRF-Token'] = window.BLOG_ADMIN.csrfToken;
        }
        return fetch(baseUrl() + path, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(data || {}),
        }).then(function (r) { return r.json(); });
    }

    /** @param {string} message @param {number} [icon] 1成功 2失败 0信息 3问号 */
    function tip(message, icon) {
        if (typeof layer === 'undefined') {
            window.alert(message);
            return;
        }
        layer.msg(message, {
            icon: typeof icon === 'number' ? icon : 1,
            time: 1800,
            shade: 0.15,
            shadeClose: true,
        });
    }

    function tipError(message) {
        tip(message || '操作失败', 2);
    }

    function currentFilter() {
        var params = new URLSearchParams(window.location.search);
        return {
            status: params.get('status') || 'pending',
            keyword: (document.getElementById('blogCommentKeyword') || {}).value || ''
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        // 导出按钮
        var exportBtns = document.querySelectorAll('[data-export]');
        Array.prototype.forEach.call(exportBtns, function (btn) {
            btn.addEventListener('click', function () {
                var fmt = btn.getAttribute('data-export') || 'csv';
                var f = currentFilter();
                var url = baseUrl() + '/comment/export?status=' + encodeURIComponent(f.status)
                    + '&format=' + fmt;
                if (f.keyword) url += '&keyword=' + encodeURIComponent(f.keyword);
                window.location.href = url;
            });
        });
        var root = document.getElementById('blogAdminComments');
        if (!root) {
            return;
        }

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-act]');
            if (!btn) {
                return;
            }
            var card = btn.closest('.blog-admin-comment-card');
            if (!card) {
                return;
            }
            var act = btn.getAttribute('data-act');
            var id = card.getAttribute('data-id') || '';
            var post = card.getAttribute('data-post') || '';
            var replyBox = card.querySelector('.blog-admin-comment-reply');

            if (act === 'reply') {
                if (replyBox) {
                    replyBox.hidden = !replyBox.hidden;
                    if (!replyBox.hidden) {
                        var taFocus = replyBox.querySelector('textarea');
                        if (taFocus) {
                            taFocus.focus();
                        }
                    }
                }
                return;
            }

            if (act === 'reply-send') {
                var ta = replyBox && replyBox.querySelector('textarea');
                var content = ta ? ta.value.trim() : '';
                if (!content) {
                    tip('请先填写回复内容', 0);
                    return;
                }
                btn.disabled = true;
                postJson('/comment/reply.json', { post: post, parent: id, content: content, tags: '博主' })
                    .then(function (res) {
                        if (!res || res.code !== 0) {
                            throw new Error((res && res.message) || '回复失败');
                        }
                        tip(res.message || '回复已发布到前台', 1);
                        // 就地收起回复框，避免整页刷新
                        if (ta) {
                            ta.value = '';
                        }
                        if (replyBox) {
                            replyBox.hidden = true;
                        }
                        btn.disabled = false;
                    })
                    .catch(function (err) {
                        tipError(err.message);
                        btn.disabled = false;
                    });
                return;
            }

            var map = {
                approve: { url: '/comment/approve.json', ok: '已通过审核' },
                pending: { url: '/comment/pending.json', ok: '已标为待审核' },
                spam: { url: '/comment/spam.json', ok: '已标为垃圾' },
                remove: { url: '/comment/remove.json', ok: '已删除' },
            };
            var conf = map[act];
            if (!conf) {
                return;
            }
            /**
             * 操作成功后从列表就地移除当前卡片（避免整页刷新丢失筛选/关键词上下文）
             */
            function removeCardFromList() {
                var list = root.querySelector('.blog-admin-comment-list');
                var container = (list && card.parentNode === list) ? list : (card.parentNode || null);
                if (container) {
                    container.removeChild(card);
                }
                if (list && list.querySelectorAll('.blog-admin-comment-card').length === 0) {
                    var empty = root.querySelector('.blog-admin-empty');
                    if (!empty) {
                        empty = document.createElement('div');
                        empty.className = 'blog-admin-empty';
                        empty.textContent = '当前筛选下暂无评论';
                        root.appendChild(empty);
                    } else {
                        empty.hidden = false;
                    }
                }
            }


            var run = function () {
                btn.disabled = true;
                postJson(conf.url, { post: post, id: id })
                    .then(function (res) {
                        if (!res || res.code !== 0) {
                            throw new Error((res && res.message) || '操作失败');
                        }
                        tip(res.message || conf.ok, 1);
                        // 就地更新，避免整页刷新丢失筛选上下文：
                        // 状态变更（approve/pending/spam）后卡片不再属于当前筛选，
                        // 删除/移除一律一并移除该卡片。
                        removeCardFromList();
                    })
                    .catch(function (err) {
                        tipError(err.message);
                        btn.disabled = false;
                    });
            };

            if (act === 'remove') {
                layer.confirm(
                    '删除后不可恢复，且会一并删除该评论下的全部回复。',
                    {
                        icon: 3,
                        title: '确认删除',
                        btn: ['删除', '取消'],
                    },
                    function (idx) {
                        layer.close(idx);
                        run();
                    }
                );
                return;
            }

            if (act === 'approve') {
                layer.confirm(
                    '通过后将立即在前台文章页显示。',
                    {
                        icon: 1,
                        title: '通过审核',
                        btn: ['通过', '取消'],
                    },
                    function (idx) {
                        layer.close(idx);
                        run();
                    }
                );
                return;
            }

            run();
        });
    });
})();

/* 评论搜索（防抖跳转） */
(function () {
    'use strict';
    function initCommentSearch() {
        var input = document.getElementById('blogCommentKeyword');
        if (!input) return;
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var kw = encodeURIComponent(input.value.trim());
                var status = new URLSearchParams(window.location.search).get('status') || 'pending';
                var url = window.location.pathname + '?status=' + status;
                if (kw) url += '&keyword=' + kw;
                window.location.href = url;
            }, 500);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                clearTimeout(timer);
                var kw = encodeURIComponent(input.value.trim());
                var status = new URLSearchParams(window.location.search).get('status') || 'pending';
                var url = window.location.pathname + '?status=' + status;
                if (kw) url += '&keyword=' + kw;
                window.location.href = url;
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCommentSearch);
    } else {
        initCommentSearch();
    }
})();
