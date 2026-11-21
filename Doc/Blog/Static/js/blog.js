/**
 * Candy Blog 交互脚本
 */
(function () {
    'use strict';

if (window.innerWidth > 768) {
        const saved = localStorage.getItem('blog_aside_collapsed');
        if (saved === '1') {
            document.documentElement.classList.add('blog-aside-collapsed-init');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const body = document.body;
        const html = document.documentElement;
    const themeToggle = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');
    const docTheme = body.getAttribute('data-doc-theme');
        const aside = document.querySelector('.blog-aside');
        const overlay = document.querySelector('.blog-overlay');
        const portalOverlay = document.querySelector('.blog-overlay--portal');
        const navToggle = document.querySelector('.blog-nav-toggle');
        const asideToggle = document.querySelector('.blog-aside-toggle');

        if (html.classList.contains('blog-aside-collapsed-init')) {
            body.classList.add('blog-aside-collapsed');
        }

        function enableDarkMode(persist) {
            html.setAttribute('data-theme', 'dark');
            body.classList.add('blog-dark');
            if (themeIcon) themeIcon.textContent = '☀';
            if (persist !== false) localStorage.setItem('theme', 'dark');
        }

        function disableDarkMode(persist) {
            html.setAttribute('data-theme', 'light');
            body.classList.remove('blog-dark');
            if (themeIcon) themeIcon.textContent = '☾';
            if (persist !== false) localStorage.setItem('theme', 'light');
        }

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        enableDarkMode();
    } else if (savedTheme === 'light') {
        disableDarkMode();
    } else if (docTheme === 'dark') {
        enableDarkMode(false);
    } else if (docTheme === 'light') {
        disableDarkMode(false);
    } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        enableDarkMode(false);
    } else {
        disableDarkMode(false);
    }
            
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            if (!localStorage.getItem('theme') && !docTheme) {
                e.matches ? enableDarkMode(false) : disableDarkMode(false);
            }
        });

        function syncThemeIcon() {
            const icon = document.getElementById('theme-icon');
            if (icon) {
                icon.textContent = body.classList.contains('blog-dark') ? '☀' : '☾';
            }
        }

        function setupToolbar() {
            const bar = document.querySelector('.blog-toolbar') || document.querySelector('.blog-masthead-tools');
            if (!bar || bar.dataset.bound === '1') return;
            bar.dataset.bound = '1';
            bar.addEventListener('click', function (e) {
                if (e.target.closest('#print-btn')) {
                    e.preventDefault();
                    window.print();
                }
                if (e.target.closest('#theme-toggle')) {
                    e.preventDefault();
                    body.classList.contains('blog-dark') ? disableDarkMode() : enableDarkMode();
                }
            });
        }

        function updateToolbar(htmlFragment) {
            const bar = document.querySelector('.blog-toolbar') || document.querySelector('.blog-masthead-tools');
            if (!bar || typeof htmlFragment !== 'string') return;
            const tools = bar.querySelector('.blog-toolbar-tools') || bar;
            if (bar.classList.contains('blog-masthead-tools')) {
                tools.innerHTML = htmlFragment;
            } else if (tools) {
                tools.innerHTML = htmlFragment;
            }
            syncThemeIcon();
        }

        function isBlogHome(url) {
            try {
                const path = new URL(url, window.location.origin).pathname.replace(/\/+$/, '') || '/';
                const base = (body.getAttribute('data-doc-base') || '/Blog/').replace(/\/+$/, '');
                return path === base;
            } catch (e) {
                return false;
            }
        }

        function setupFeedTabs() {
            document.querySelectorAll('[data-feed-tabs]').forEach(function (root) {
                if (root.dataset.bound === '1') return;
                root.dataset.bound = '1';
                root.querySelectorAll('.feed-tab').forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        const key = tab.getAttribute('data-tab');
                        root.querySelectorAll('.feed-tab').forEach(function (t) {
                            t.classList.toggle('is-active', t === tab);
                        });
                        root.querySelectorAll('.feed-tab-panel').forEach(function (panel) {
                            panel.classList.toggle('is-active', panel.getAttribute('data-panel') === key);
                        });
                    });
                });
            });
        }

        function setupLoadMore() {
            document.querySelectorAll('[data-load-more]').forEach(function (btn) {
                if (btn.dataset.bound === '1') {
                    return;
                }
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                    if (btn.dataset.exhausted === '1') {
                        showBlogToast('没有更多了');
                        return;
                    }
                    loadBlogFeed(btn);
                });
            });
        }

        /**
         * 轻量 Toast（无更多数据等提示）
         *
         * @param {string} message
         * @param {string} [type] ok|err|info
         */
        function showBlogToast(message, type) {
            if (!message) {
                return;
            }
            let root = document.getElementById('blog-toast-root');
            if (!root) {
                root = document.createElement('div');
                root.id = 'blog-toast-root';
                root.className = 'blog-toast-root';
                root.setAttribute('aria-live', 'polite');
                document.body.appendChild(root);
            }
            const el = document.createElement('div');
            el.className = 'blog-toast' + (type ? ' blog-toast--' + type : '');
            el.textContent = message;
            root.appendChild(el);
            requestAnimationFrame(function () {
                el.classList.add('is-visible');
            });
            setTimeout(function () {
                el.classList.remove('is-visible');
                setTimeout(function () {
                    el.remove();
                }, 320);
            }, 2400);
        }

        /**
         * 评论结果弹层（审核 / 发布 / 失败）
         *
         * @param {{type?:string,title?:string,message?:string,detail?:string,okText?:string}} opts
         */
        function showBlogNotice(opts) {
            opts = opts || {};
            const type = opts.type || 'info';
            const title = opts.title || '提示';
            const message = opts.message || '';
            const detail = opts.detail || '';
            const okText = opts.okText || '知道了';

            const existing = document.getElementById('blog-notice-overlay');
            if (existing) {
                existing.remove();
            }

            const overlay = document.createElement('div');
            overlay.id = 'blog-notice-overlay';
            overlay.className = 'blog-notice-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.innerHTML =
                '<div class="blog-notice-card blog-notice-card--' + type + '">' +
                '<div class="blog-notice-icon" aria-hidden="true"></div>' +
                '<h3 class="blog-notice-title"></h3>' +
                '<p class="blog-notice-msg"></p>' +
                (detail ? '<p class="blog-notice-detail"></p>' : '') +
                '<button type="button" class="blog-notice-ok" data-notice-close></button>' +
                '</div>';

            overlay.querySelector('.blog-notice-title').textContent = title;
            overlay.querySelector('.blog-notice-msg').textContent = message;
            if (detail) {
                overlay.querySelector('.blog-notice-detail').textContent = detail;
            }
            overlay.querySelector('[data-notice-close]').textContent = okText;
            document.body.appendChild(overlay);

            requestAnimationFrame(function () {
                overlay.classList.add('is-visible');
            });

            function close() {
                overlay.classList.remove('is-visible');
                setTimeout(function () {
                    overlay.remove();
                    document.removeEventListener('keydown', onKey);
                }, 220);
            }

            function onKey(e) {
                if (e.key === 'Escape') {
                    close();
                }
            }

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay || e.target.closest('[data-notice-close]')) {
                    close();
                }
            });
            document.addEventListener('keydown', onKey);
            overlay.querySelector('[data-notice-close]').focus();
        }

        /**
         * 根据评论接口结果弹出反馈
         */
        function showCommentResultNotice(res, isReply) {
            const status = (res && res.data && res.data.status) || '';
            const apiMsg = (res && res.message) || '';
            if (status === 'pending') {
                showBlogNotice({
                    type: 'pending',
                    title: isReply ? '回复已提交' : '评论已提交',
                    message: apiMsg || (isReply
                        ? '回复已进入审核队列，通过后会出现在楼中。'
                        : '感谢留言！内容需管理员审核后才会公开显示。'),
                    detail: '一般会在审核通过后出现在本页评论区。',
                    okText: '好的',
                });
                return;
            }
            showBlogNotice({
                type: 'success',
                title: isReply ? '回复已发布' : '发表成功',
                message: apiMsg || (isReply ? '你的回复已显示在对应楼层下。' : '你的评论已出现在本页。'),
                okText: '太好了',
            });
        }

        /**
         * 请求门户 feed 分页 API
         */
        function fetchBlogFeed(params) {
            const base = (body.getAttribute('data-doc-base') || '/Blog/').replace(/\/?$/, '/');
            const url = new URL(base, window.location.origin);
            url.searchParams.set('blog_feed', '1');
            Object.keys(params).forEach(function (key) {
                if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                    url.searchParams.set(key, String(params[key]));
                }
            });
            return fetch(url.toString(), {
                headers: { 'X-Requested-With': 'BlogFeedRequest' },
            }).then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            });
        }

        /**
         * 加载 feed 下一页（首页按钮 / 分类无限滚动）
         */
        function loadBlogFeed(trigger) {
            if (!trigger || trigger.dataset.loading === '1' || trigger.dataset.exhausted === '1') {
                return Promise.resolve();
            }

            const scope = trigger.getAttribute('data-scope') || 'home';
            const offset = parseInt(trigger.getAttribute('data-offset') || '0', 10);
            const limit = parseInt(trigger.getAttribute('data-limit') || '6', 10);
            const category = trigger.getAttribute('data-category') || '';
            const tag = trigger.getAttribute('data-tag') || '';
            const isButton = trigger.hasAttribute('data-load-more');

            trigger.dataset.loading = '1';
            if (isButton) {
                trigger.disabled = true;
                trigger.textContent = '加载中…';
            }

            // 骨架屏：非按钮触发时在目标容器插入骨架
            let skeleton = null;
            if (!isButton) {
                const stream = document.querySelector('[data-feed-stream]');
                if (stream) skeleton = showSkeleton(stream);
            }

            // sessionStorage 缓存：返回同一页面不重复请求
            const cacheKey = 'blog_feed:' + scope + ':' + offset + ':' + limit + ':' + category + ':' + tag;
            let cached = null;
            try {
                cached = sessionStorage.getItem(cacheKey);
            } catch (e) {}

            function renderFeed(res) {
                if (!res || res.code !== 0 || !res.data) {
                    throw new Error((res && res.message) || '加载失败');
                }
                const data = res.data;
                const count = parseInt(data.count, 10) || 0;
                const hasMore = !!data.has_more;
                const html = typeof data.html === 'string' ? data.html : '';

                if (html) {
                    if (scope === 'home') {
                        const stream = document.querySelector('[data-feed-stream]');
                        if (stream) stream.insertAdjacentHTML('beforeend', html);
                    } else if (scope === 'category' || scope === 'tag') {
                        if (trigger.classList.contains('blog-list-grid')) {
                            trigger.insertAdjacentHTML('beforeend', html);
                        }
                    }
                }

                if (typeof data.next_offset !== 'undefined') {
                    trigger.setAttribute('data-offset', String(data.next_offset));
                } else {
                    trigger.setAttribute('data-offset', String(offset + count));
                }

                if (!hasMore || count === 0) {
                    trigger.dataset.exhausted = '1';
                    showBlogToast('没有更多了');
                    if (isButton) {
                        trigger.textContent = '没有更多了';
                        trigger.disabled = true;
                    }
                } else if (isButton) {
                    trigger.disabled = false;
                    trigger.textContent = '加载更多';
                }
            }

            if (cached) {
                try {
                    renderFeed(JSON.parse(cached));
                    if (skeleton) removeSkeleton(skeleton);
                    trigger.dataset.loading = '0';
                    return Promise.resolve();
                } catch (e) {
                    // 缓存解析失败，走网络
                }
            }

            return fetchBlogFeed({
                scope: scope,
                offset: offset,
                limit: limit,
                category: category,
                tag: tag,
            })
                .then(function (res) {
                    renderFeed(res);
                    try {
                        sessionStorage.setItem(cacheKey, JSON.stringify(res));
                    } catch (e) {}
                    if (skeleton) removeSkeleton(skeleton);
                })
                .catch(function (err) {
                    console.error('Feed 加载失败:', err);
                    if (skeleton) removeSkeleton(skeleton);
                    showBlogToast('加载失败，请稍后重试');
                    if (isButton) {
                        trigger.disabled = false;
                        trigger.textContent = '加载更多';
                    }
                })
                .finally(function () {
                    trigger.dataset.loading = '0';
        });
    }

    /**
         * 分类列表页：滚动至底部自动加载
     */
        function setupListInfiniteScroll() {
            if (!body.classList.contains('blog-list-page')) {
            return;
        }

            const grid = document.querySelector('[data-infinite-scroll]');
            if (!grid || grid.dataset.bound === '1') {
                return;
            }

            const total = parseInt(grid.getAttribute('data-total') || '0', 10);
            const offset = parseInt(grid.getAttribute('data-offset') || '0', 10);

            let scrollTimer = null;
            let endToastShown = false;

            function isNearPageBottom() {
                const doc = document.documentElement;
                return (window.innerHeight + window.scrollY) >= (doc.scrollHeight - 140);
            }

            function bindListScrollEndToast(target) {
                window.addEventListener('scroll', function () {
                    if (target.dataset.exhausted !== '1' || endToastShown) {
                        return;
                    }
                    if (!isNearPageBottom()) {
                        return;
                    }
                    endToastShown = true;
                    showBlogToast('没有更多了');
                }, { passive: true });
            }

            if (total <= offset) {
                grid.dataset.exhausted = '1';
                bindListScrollEndToast(grid);
                return;
            }

            grid.dataset.bound = '1';

            function tryLoadMore() {
                if (grid.dataset.exhausted === '1' || grid.dataset.loading === '1') {
                    return;
                }
                if (!isNearPageBottom()) {
                    return;
                }
                loadBlogFeed(grid);
            }

            const sentinel = document.querySelector('[data-infinite-sentinel]');
            if (sentinel && typeof IntersectionObserver !== 'undefined') {
                const observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) {
                            return;
                        }
                        tryLoadMore();
                    });
                }, { root: null, rootMargin: '200px 0px', threshold: 0 });
                observer.observe(sentinel);
            }

            window.addEventListener('scroll', function () {
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(tryLoadMore, 80);
            }, { passive: true });

            // 首屏内容不足一屏时，哨兵已在视口内，需主动尝试加载
            setTimeout(tryLoadMore, 120);
        }

        /**
         * 门户首页轮播：自动播放、箭头与圆点切换
         */
        function setupCarousel() {
            document.querySelectorAll('[data-carousel]').forEach(function (root) {
                if (root.dataset.bound === '1') return;
                root.dataset.bound = '1';

                const slides = root.querySelectorAll('.blog-carousel-slide');
                const dots = root.querySelectorAll('.blog-carousel-dot');
                const prevBtn = root.querySelector('.blog-carousel-prev');
                const nextBtn = root.querySelector('.blog-carousel-next');
                if (!slides.length) return;

                let index = 0;
                let timer = null;
                const interval = 5000;

                function goTo(i) {
                    index = (i + slides.length) % slides.length;
                    slides.forEach(function (slide, si) {
                        const active = si === index;
                        slide.classList.toggle('is-active', active);
                        slide.hidden = !active;
                    });
                    dots.forEach(function (dot, di) {
                        const active = di === index;
                        dot.classList.toggle('is-active', active);
                        dot.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                }

                function next() { goTo(index + 1); }
                function prev() { goTo(index - 1); }

                function startAuto() {
                    stopAuto();
                    timer = setInterval(next, interval);
                }

                function stopAuto() {
                    if (timer) {
                        clearInterval(timer);
                        timer = null;
                    }
                }

                if (prevBtn) prevBtn.addEventListener('click', function () { prev(); startAuto(); });
                if (nextBtn) nextBtn.addEventListener('click', function () { next(); startAuto(); });
                dots.forEach(function (dot) {
                    dot.addEventListener('click', function () {
                        goTo(parseInt(dot.getAttribute('data-index'), 10) || 0);
                        startAuto();
                    });
                });

                root.addEventListener('mouseenter', stopAuto);
                root.addEventListener('mouseleave', startAuto);
                startAuto();
            });
        }

        /**
         * 侧栏 3D 球体文字云（CSS3DRenderer，见 wordsphere.js）
         */
        function setupWordSphere() {
            const root = document.querySelector('[data-word-sphere-root]');
            if (!root || root.dataset.bound === '1') return;
            if (window.BlogWordSphere && typeof window.BlogWordSphere.init === 'function') {
                window.BlogWordSphere.init(root);
            }
        }

        /**
         * 移动端主导航抽屉
         */
        function setupMastnavDrawer() {
            const toggle = document.querySelector('.blog-mastnav-toggle');
            const drawer = document.querySelector('.blog-mastnav-drawer');
            if (!toggle || !drawer) return;

            toggle.addEventListener('click', function () {
                const open = document.body.classList.toggle('blog-mastnav-open');
                drawer.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        function openAside() {
            body.classList.add('blog-aside-open');
        }

        function closeAside() {
            body.classList.remove('blog-aside-open');
        }

        function toggleAsideDesktop() {
            body.classList.toggle('blog-aside-collapsed');
            if (window.innerWidth > 768) {
                localStorage.setItem('blog_aside_collapsed', body.classList.contains('blog-aside-collapsed') ? '1' : '0');
            }
        }

        if (navToggle) {
            navToggle.addEventListener('click', function () {
                if (window.innerWidth <= 768) {
                    body.classList.contains('blog-aside-open') ? closeAside() : openAside();
                } else {
                    toggleAsideDesktop();
                }
            });
        }

        if (asideToggle) {
            asideToggle.addEventListener('click', function () {
                const open = body.classList.toggle('blog-aside-open');
                asideToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeAside);
        }

        if (portalOverlay) {
            portalOverlay.addEventListener('click', closeAside);
        }

        function escapeHtml(text) {
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        let clearSearchResults = function () {};

        function setupDocSearch() {
            const searchInput = document.getElementById('doc-search-input');
            const searchBtn = document.querySelector('.blog-search-btn');
            const searchBox = searchInput ? searchInput.closest('.blog-search') : null;
    const searchResults = document.getElementById('search-results');
            if (!searchInput || !searchResults) {
                return;
            }

    let searchTimer = null;
            let searchSeq = 0;

    function debounceSearch(fn, delay) {
                return function () {
                    const args = arguments;
                    const ctx = this;
            clearTimeout(searchTimer);
                    searchTimer = setTimeout(function () { fn.apply(ctx, args); }, delay);
                };
            }

            function filterSidebarNav(keywordLower) {
                const navRoot = document.querySelector('.blog-aside--toc .blog-nav, aside.blog-aside .blog-nav');
                if (!navRoot) {
                    return;
                }
                navRoot.querySelectorAll('ul li a').forEach(function (item) {
                    const li = item.parentElement;
                    if (!li) {
                        return;
                    }
        if (!keywordLower) {
                        li.style.display = '';
            return;
        }
                    if (item.textContent.trim().toLowerCase().includes(keywordLower)) {
                        li.style.display = '';
                        const submenu = li.closest('ul.submenu');
                        if (submenu) {
                            const parent = submenu.closest('li.has-children');
                            if (parent) {
                                parent.classList.add('active');
                    }
                }
            } else {
                        li.style.display = 'none';
            }
        });
    }

            function resetSearchResultsPanel() {
        searchResults.innerHTML = '';
        searchResults.hidden = true;
                searchResults.classList.remove('is-empty', 'is-loading');
            }

            clearSearchResults = resetSearchResultsPanel;

            function getSearchEndpoint() {
                const base = body.getAttribute('data-doc-base');
                if (base) {
                    return base.endsWith('/') ? base : base + '/';
                }
                const path = window.location.pathname || '/Blog/';
                return path.endsWith('/') ? path : path + '/';
            }

            function renderSearchResults(payload) {
        const results = payload.results || [];
                const total = typeof payload.total === 'number' ? payload.total : results.length;
                const keyword = payload.keyword || '';

                if (!results.length) {
                    searchResults.innerHTML = '<div class="search-results-empty">未找到与「'
                        + escapeHtml(keyword) + '」匹配的文章</div>';
            searchResults.classList.add('is-empty');
                    searchResults.classList.remove('is-loading');
            searchResults.hidden = false;
            return;
        }

                const headHtml = '<div class="search-results-head">找到 <strong>' + total
                    + '</strong> 篇相关文章</div>';
                const listHtml = results.map(function (item) {
                    const url = item.url + '?q=' + encodeURIComponent(keyword);
                    const category = item.category
                        ? '<span class="search-result-category">' + escapeHtml(item.category) + '</span>'
                        : '';
                    return '<a class="search-result-item" href="' + escapeHtml(url) + '" data-url="'
                        + escapeHtml(item.url) + '">'
                        + '<span class="search-result-title">' + escapeHtml(item.title) + category + '</span>'
                        + '<span class="search-result-snippet">' + item.snippet + '</span></a>';
        }).join('');

                var viewAllUrl = (getSearchEndpoint().replace(/\/+$/, '') || '') + '/search?q=' + encodeURIComponent(keyword);
                var viewAllHtml = '<a class="search-results-all" href="' + viewAllUrl + '">查看全部 ' + total + ' 篇结果 →</a>';
                searchResults.innerHTML = headHtml + listHtml + viewAllHtml;
                searchResults.classList.remove('is-empty', 'is-loading');
        searchResults.hidden = false;

                searchResults.querySelectorAll('.search-result-item').forEach(function (el) {
                    el.addEventListener('click', function (e) {
                e.preventDefault();
                        const targetUrl = el.getAttribute('data-url') || el.getAttribute('href');
                        clearSearchResults();
                        searchInput.value = '';
                        filterSidebarNav('');
                        if (isBlogArticleUrl(targetUrl)) {
                            loadPage(el.getAttribute('href'), keyword);
                        } else if (isBlogHome(targetUrl) || isBlogCategoryListUrl(targetUrl)) {
                            window.location.href = targetUrl.split('?')[0];
                        } else {
                            window.location.href = targetUrl;
                        }
                        closeAside();
            });
        });
    }

    function fetchSearchResults(keyword) {
        if (keyword.length < 1) {
            clearSearchResults();
            return;
        }

                const seq = ++searchSeq;
                searchResults.hidden = false;
                searchResults.classList.add('is-loading');
                searchResults.classList.remove('is-empty');
                searchResults.innerHTML = '<div class="search-results-loading">搜索中…</div>';

        fetch(getSearchEndpoint(), {
            method: 'POST',
            headers: {
                'X-Requested-With': 'SearchRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'keyword=' + encodeURIComponent(keyword),
                })
                    .then(function (r) {
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status);
                        }
                        return r.json();
                    })
                    .then(function (data) {
                        if (seq !== searchSeq) {
                            return;
                        }
            if (searchInput.value.trim() !== keyword) {
                return;
            }
                        renderSearchResults(data || {});
                    })
                    .catch(function (err) {
                        console.error('搜索失败:', err);
                        if (seq !== searchSeq) {
                            return;
                        }
                        searchResults.innerHTML = '<div class="search-results-empty">搜索失败，请稍后重试</div>';
                        searchResults.classList.add('is-empty');
                        searchResults.classList.remove('is-loading');
                        searchResults.hidden = false;
                    });
            }

            function handleSearch() {
        const keyword = searchInput.value.trim();
                filterSidebarNav(keyword.toLowerCase());
        if (!keyword) {
            clearSearchResults();
            return;
        }
        fetchSearchResults(keyword);
    }

            searchInput.addEventListener('input', debounceSearch(handleSearch, 280));
            searchInput.addEventListener('focus', function () {
                if (searchInput.value.trim() && searchResults.innerHTML !== '') {
                    searchResults.hidden = false;
                }
            });
            searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                    const first = searchResults.querySelector('.search-result-item');
                if (first) {
                    first.click();
                } else {
                        handleSearch();
                }
            }
            if (e.key === 'Escape') {
                searchInput.value = '';
                    handleSearch();
                    searchInput.blur();
                }
            });

            if (searchBtn) {
                searchBtn.addEventListener('click', handleSearch);
            }

            document.addEventListener('click', function (e) {
                if (!searchBox || searchBox.contains(e.target)) {
                    return;
                }
                clearSearchResults();
            });
        }

        function clearHighlight() {
            document.querySelectorAll('.blog-article mark.search-hit').forEach(function (mark) {
            const parent = mark.parentNode;
                if (!parent) return;
            parent.replaceChild(document.createTextNode(mark.textContent), mark);
            parent.normalize();
        });
    }

        function applyHighlight(keyword) {
            const root = document.querySelector('.blog-article');
            if (!root || !keyword) return;
            clearHighlight();
        const kw = keyword.trim();
            if (!kw) return;
        const escaped = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const re = new RegExp('(' + escaped + ')', 'gi');
            const skip = new Set(['SCRIPT', 'STYLE', 'PRE', 'CODE', 'MARK']);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            nodes.forEach(function (textNode) {
            const parent = textNode.parentElement;
                if (!parent || skip.has(parent.tagName) || parent.closest('pre, code')) return;
            const text = textNode.nodeValue;
            if (!text || !re.test(text)) {
                re.lastIndex = 0;
                return;
            }
            re.lastIndex = 0;
            const frag = document.createDocumentFragment();
                let last = 0;
                text.replace(re, function (match, _g, offset) {
                    if (offset > last) frag.appendChild(document.createTextNode(text.slice(last, offset)));
                const mark = document.createElement('mark');
                mark.className = 'search-hit';
                mark.textContent = match;
                frag.appendChild(mark);
                    last = offset + match.length;
                return match;
            });
                if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
            parent.replaceChild(frag, textNode);
        });
            const first = root.querySelector('mark.search-hit');
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function stripQuery(url) {
        return url.split('?')[0];
    }

        /**
         * 侧栏导航路径规范化：统一为可比较的 pathname（解码、去尾斜杠）
         */
        function normalizeBlogPath(url) {
            if (!url) {
                return '';
            }
            try {
                let path;
                if (/^https?:\/\//i.test(url)) {
                    path = new URL(url).pathname;
                } else {
                    path = url.split('?')[0].split('#')[0];
                    if (path && path.charAt(0) !== '/') {
                        path = '/' + path;
                    }
                }
                path = path.replace(/\/+$/, '') || '/';
                try {
                    return decodeURIComponent(path);
                } catch (e) {
                    return path;
                }
            } catch (e) {
                return stripQuery(url).replace(/\/+$/, '') || '/';
            }
        }

        function queryHighlight(url) {
            try {
                return new URL(url, window.location.origin).searchParams.get('q') || '';
        } catch (e) {
            return '';
        }
    }

        function updateTab(container, index) {
            container.querySelectorAll('.tab-nav-item').forEach(function (item, i) {
                item.classList.toggle('active', i === index);
            });
            container.querySelectorAll('.tab-panel').forEach(function (panel, i) {
                panel.classList.toggle('active', i === index);
            });
        }

        function bindTabs() {
            document.querySelectorAll('.tabs-container').forEach(function (container) {
                container.querySelectorAll('.tab-nav-item').forEach(function (item, index) {
                    item.addEventListener('click', function (e) {
                        e.preventDefault();
                        const current = this.closest('.tabs-container');
                        const tabId = current.getAttribute('data-tab-id');
                        if (tabId) {
                            document.querySelectorAll('[data-tab-id="' + tabId + '"]').forEach(function (c) {
                                updateTab(c, index);
                            });
            } else {
                            updateTab(current, index);
                        }
                    });
                });
            });
        }

        function initEffects() {
            if (typeof Prism !== 'undefined') Prism.highlightAll();
            if (typeof mermaid !== 'undefined') mermaid.init();
            bindTabs();
        }

        /**
         * 同步侧栏：当前文章 checked，所属分类保持展开（active）
         */
        function syncSidebarNav(url) {
            const clean = normalizeBlogPath(url);
            document.querySelectorAll('.blog-nav a.checked').forEach(function (a) {
                a.classList.remove('checked');
            });
            document.querySelectorAll('.blog-nav li.has-children.active').forEach(function (li) {
                li.classList.remove('active');
            });
            document.querySelectorAll('.blog-nav ul li a').forEach(function (item) {
                if (item.parentElement.classList.contains('has-children')) {
                    return;
                }
                const href = item.getAttribute('href');
                if (!href || href === '#') {
                    return;
                }
                if (normalizeBlogPath(href) !== clean) {
                    return;
                }
                item.classList.add('checked');
                let ancestor = item.parentElement && item.parentElement.parentElement
                    ? item.parentElement.parentElement.closest('li.has-children')
                    : null;
                while (ancestor) {
                    ancestor.classList.add('active');
                    ancestor = ancestor.parentElement
                        ? ancestor.parentElement.closest('li.has-children')
                        : null;
                }
            });
        }

        /**
         * 侧栏导航：事件委托（分类仅展开，文章走 AJAX）
         */
        function setupSidebarNavDelegation() {
            const navRoot = document.querySelector('.blog-aside--toc .blog-nav, aside.blog-aside .blog-nav');
            if (!navRoot || navRoot.dataset.delegateBound === '1') {
                return;
            }
            navRoot.dataset.delegateBound = '1';
            navRoot.addEventListener('click', function (e) {
                const link = e.target.closest('a');
                if (!link || !navRoot.contains(link)) {
                    return;
                }
                if (link.parentElement.classList.contains('has-children')) {
                    e.preventDefault();
                    link.parentElement.classList.toggle('active');
                    return;
                }
                const href = link.getAttribute('href');
                if (!href || href === '#' || body.classList.contains('blog-portal')) {
                    return;
                }
                if (isBlogHome(href)) {
                    e.preventDefault();
                    window.location.href = href;
                    return;
                }
                if (isBlogCategoryListUrl(href)) {
                    e.preventDefault();
                    window.location.href = normalizeCategoryListHref(href);
                    return;
                }
                if (!isBlogArticleUrl(href)) {
                    return;
                }
                e.preventDefault();
                loadPage(href);
                clearSearchResults();
                closeAside();
            });
        }

        /**
         * 正文区：上一篇/下一篇等同站文章链接触发 AJAX
         */
        function setupArticleLinkDelegation() {
            const shell = document.querySelector('.blog-shell');
            if (!shell || shell.dataset.articleDelegateBound === '1') {
                return;
            }
            shell.dataset.articleDelegateBound = '1';
            shell.addEventListener('click', function (e) {
                const link = e.target.closest('a');
                if (!link) {
                    return;
                }
                if (link.closest('.blog-mastnav, .blog-mastnav-drawer, .blog-mastnav-more-menu')) {
                    return;
                }
                if (!link.closest('.blog-article-panel, .blog-pager, .article-navigation')) {
                    return;
                }
                const href = link.getAttribute('href');
                if (!href || href === '#' || /^https?:/i.test(href)) {
                    return;
                }
                if (isBlogHome(href) || isBlogCategoryListUrl(href) || !isBlogArticleUrl(href)) {
                    return;
                }
                e.preventDefault();
                loadPage(href);
            });
        }

        function bindNavEvents() {
            setupSidebarNavDelegation();
        }

        function isBlogArticleUrl(url) {
            try {
                const base = (body.getAttribute('data-doc-base') || '/Blog/').replace(/\/+$/, '');
                const path = new URL(url, window.location.origin).pathname.replace(/\/+$/, '');
                if (path === base) {
                    return false;
                }
                if (!path.startsWith(base + '/')) {
                    return false;
                }
                const rest = path.slice(base.length + 1);
                return rest !== '' && rest.includes('/');
            } catch (e) {
                return false;
            }
        }

        function isBlogCategoryListUrl(url) {
            try {
                const base = (body.getAttribute('data-doc-base') || '/Blog/').replace(/\/+$/, '');
                const path = new URL(url, window.location.origin).pathname.replace(/\/+$/, '');
                if (path === base) {
                    return false;
                }
                if (!path.startsWith(base + '/')) {
                    return false;
                }
                const rest = path.slice(base.length + 1);
                return rest !== '' && !rest.includes('/');
            } catch (e) {
                return false;
            }
        }

        /**
         * 分类列表须整页门户布局；若误落入文章窄栏则强制刷新
         */
        function guardListPageLayout() {
            if (!isBlogCategoryListUrl(window.location.href)) {
                return;
            }
            const nestedList = document.querySelector('.blog-article .blog-list-page-inner, .blog-article-panel .blog-list-page-inner');
            const wrongBody = document.body.classList.contains('blog-article-page');
            if (!nestedList && !wrongBody) {
                return;
            }
            let path = new URL(window.location.href).pathname;
            if (!path.endsWith('/')) {
                path += '/';
            }
            window.location.replace(path + window.location.search);
        }

        function normalizeCategoryListHref(href) {
            let target = stripQuery(href);
            if (isBlogCategoryListUrl(target) && !target.endsWith('/')) {
                target += '/';
            }
            return target;
        }

        function bindMastnavLinks() {
            document.querySelectorAll('.blog-mastnav a[href], .blog-mastnav-drawer a[href], .blog-mastnav-more-link[href]').forEach(function (link) {
                if (link.dataset.mastBound === '1') {
                    return;
                }
                link.dataset.mastBound = '1';
                link.addEventListener('click', function (e) {
                    const href = link.getAttribute('href');
                    if (!href || href === '#' || /^https?:/i.test(href)) {
                        return;
                    }
                    if (isBlogHome(href) || isBlogCategoryListUrl(href)) {
                        e.preventDefault();
                        window.location.href = normalizeCategoryListHref(href);
                }
            });
        });
        }

    function loadPage(url, highlightKeyword) {
            const highlight = highlightKeyword || queryHighlight(url);
            const fetchUrl = stripQuery(url);
            if (isBlogHome(fetchUrl) || isBlogCategoryListUrl(fetchUrl)) {
                window.location.href = highlight
                    ? normalizeCategoryListHref(fetchUrl) + '?q=' + encodeURIComponent(highlight)
                    : normalizeCategoryListHref(fetchUrl);
                return;
            }
            fetch(fetchUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }
                    const ct = r.headers.get('Content-Type') || '';
                    if (ct.indexOf('application/json') === -1) {
                        throw new Error('非 JSON 响应');
                    }
                    return r.json();
                })
                .then(function (data) {
                    if (data && data.reload) {
                        window.location.href = data.url || fetchUrl;
                        return;
                    }
                    const head = document.querySelector('head');
                    const keep = ['meta[charset]', 'meta[name="viewport"]', 'meta[http-equiv="X-UA-Compatible"]'];
                    let preserved = '';
                    keep.forEach(function (sel) {
                        const el = head.querySelector(sel);
                        if (el) preserved += el.outerHTML;
                    });
                    head.innerHTML = preserved + data.header;
            document.title = data.title || document.title;

            if (data.nav) {
                        const navUl = document.querySelector('.blog-nav ul');
                        if (navUl) navUl.innerHTML = data.nav;
                    }

                    const panel = document.querySelector('.blog-article-panel');
                    if (panel && typeof data.panel === 'string') {
                        panel.innerHTML = data.panel;
                    } else {
                        const article = document.querySelector('.blog-article');
                        if (article) article.innerHTML = data.body;
                    }

            if (typeof data.docActions === 'string') {
                        updateToolbar(data.docActions);
                    }

                    syncSidebarNav(fetchUrl);
                    initEffects();
                    setupBlogComments();
                    highlight ? applyHighlight(highlight) : clearHighlight();
                    history.pushState({}, '', highlight ? fetchUrl + '?q=' + encodeURIComponent(highlight) : fetchUrl);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                })
                .catch(function (err) { console.error('页面加载失败:', err); });
        }

        /**
         * 文章评论：提交、回复、表情、验证码
         */
        function setupBlogComments() {
            const root = document.getElementById('blogComments');
            if (!root || root.dataset.inited === '1') {
                return;
            }
            root.dataset.inited = '1';

            const form = root.querySelector('[data-comment-form]');
            const thread = root.querySelector('[data-comment-thread]');
            const parentInput = root.querySelector('[data-comment-parent]');
            const replyBar = root.querySelector('[data-reply-bar]');
            const replyLabel = root.querySelector('[data-reply-label]');
            const msgEl = root.querySelector('[data-comment-msg]');
            const submitBtn = root.querySelector('[data-comment-submit]');
            const contentEl = root.querySelector('#blogCommentContent');
            const captchaImg = root.querySelector('[data-captcha-img]');
            const needCaptcha = root.getAttribute('data-captcha') === '1';
            const base = (body.getAttribute('data-doc-base') || '/Blog/').replace(/\/?$/, '/');

            function showMsg(text, ok) {
                if (!msgEl) return;
                msgEl.hidden = !text;
                msgEl.textContent = text || '';
                msgEl.classList.toggle('is-ok', !!ok);
                msgEl.classList.toggle('is-err', !ok && !!text);
            }

            function refreshCaptcha() {
                if (!captchaImg) return;
                const url = new URL(base, window.location.origin);
                url.searchParams.set('blog_comment', '1');
                url.searchParams.set('action', 'captcha');
                url.searchParams.set('_', String(Date.now()));
                captchaImg.src = url.toString();
            }

            function clearReply() {
                if (parentInput) parentInput.value = '';
                if (replyBar) replyBar.hidden = true;
                if (replyLabel) replyLabel.textContent = '';
            }

            function appendApprovedComment(html) {
                if (!html || !thread) {
                    return;
                }
                const empty = thread.querySelector('.blog-comment-empty');
                if (empty) {
                    empty.remove();
                }
                let list = thread.querySelector('.blog-comment-list');
                if (!list) {
                    list = document.createElement('ul');
                    list.className = 'blog-comment-list';
                    thread.appendChild(list);
                }
                const parentId = parentInput ? (parentInput.value || '') : '';
                if (parentId) {
                    const parentLi = document.getElementById('comment-' + parentId);
                    if (parentLi) {
                        let children = null;
                        for (let i = 0; i < parentLi.children.length; i++) {
                            if (parentLi.children[i].classList.contains('blog-comment-children')) {
                                children = parentLi.children[i];
                                break;
                            }
                        }
                        if (!children) {
                            children = document.createElement('ul');
                            children.className = 'blog-comment-children';
                            parentLi.appendChild(children);
                        }
                        children.insertAdjacentHTML('beforeend', html);
                        const countEl = root.querySelector('.blog-comments-count');
                        if (countEl) {
                            countEl.textContent = String((parseInt(countEl.textContent, 10) || 0) + 1);
                        }
                        return;
                    }
                }
                list.insertAdjacentHTML('beforeend', html);
                const countEl = root.querySelector('.blog-comments-count');
                if (countEl) {
                    countEl.textContent = String((parseInt(countEl.textContent, 10) || 0) + 1);
                }
            }

            root.addEventListener('click', function (e) {
                const emojiBtn = e.target.closest('[data-emoji]');
                if (emojiBtn && contentEl) {
                e.preventDefault();
                    const code = emojiBtn.getAttribute('data-emoji') || '';
                    const start = contentEl.selectionStart || contentEl.value.length;
                    const end = contentEl.selectionEnd || start;
                    const v = contentEl.value;
                    contentEl.value = v.slice(0, start) + code + v.slice(end);
                    contentEl.focus();
                    const pos = start + code.length;
                    contentEl.setSelectionRange(pos, pos);
                    return;
                }

                const replyBtn = e.target.closest('[data-reply-to]');
                if (replyBtn) {
                    e.preventDefault();
                    const id = replyBtn.getAttribute('data-reply-to') || '';
                    const name = replyBtn.getAttribute('data-reply-name') || '';
                    if (parentInput) parentInput.value = id;
                    if (replyBar) replyBar.hidden = false;
                    if (replyLabel) replyLabel.textContent = name;
                    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (contentEl) contentEl.focus();
                    return;
                }

                if (e.target.closest('[data-cancel-reply]')) {
                e.preventDefault();
                    clearReply();
                    return;
                }

                if (e.target.closest('[data-captcha-img]')) {
                    e.preventDefault();
                    refreshCaptcha();
                }
            });

            if (!form) {
                return;
            }

            form.addEventListener('submit', function (e) {
                    e.preventDefault();
                if (submitBtn && submitBtn.disabled) {
                    return;
                }

                const fd = new FormData(form);
                const isReply = !!(fd.get('parent') || '');
                const payload = {
                    action: 'submit',
                    post: fd.get('post') || root.getAttribute('data-post') || '',
                    parent: fd.get('parent') || '',
                    name: fd.get('name') || '',
                    content: fd.get('content') || '',
                    tags: fd.get('tags') || '',
                    captcha: fd.get('captcha') || '',
                };

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '提交中…';
                }
                showMsg('');

                const url = new URL(base, window.location.origin);
                url.searchParams.set('blog_comment', '1');

                fetch(url.toString(), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'BlogCommentRequest',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || res.code !== 0) {
                            throw new Error((res && res.message) || '提交失败');
                        }
                        const replyFlag = !!(res.data && res.data.is_reply) || isReply;
                        if (res.data && res.data.html && res.data.status === 'approved') {
                            appendApprovedComment(res.data.html);
                        }
                        form.reset();
                        clearReply();
                        if (needCaptcha) {
                            refreshCaptcha();
                        }
                        showCommentResultNotice(res, replyFlag);
                    })
                    .catch(function (err) {
                        showBlogNotice({
                            type: 'error',
                            title: isReply ? '回复失败' : '提交失败',
                            message: err.message || '请检查内容后重试',
                            detail: needCaptcha ? '若开启了验证码，请点击图片刷新后再试。' : '',
                            okText: '再试试',
                        });
                        if (needCaptcha) {
                            refreshCaptcha();
                        }
                    })
                    .finally(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = '发表评论';
                        }
            });
        });
    }

        window.addEventListener('popstate', function () {
            if (isBlogHome(window.location.href)) {
                window.location.reload();
                return;
            }
        loadPage(window.location.href);
    });

        guardListPageLayout();
        setupToolbar();
        setupDocSearch();
        syncThemeIcon();
        setupSidebarNavDelegation();
        setupArticleLinkDelegation();
        bindNavEvents();
        bindMastnavLinks();
        if (body.classList.contains('blog-article-page')) {
            syncSidebarNav(window.location.href);
        }
        bindTabs();
        setupFeedTabs();
        setupLoadMore();
        setupListInfiniteScroll();
        setupCarousel();
        setupWordSphere();
        setupMastnavDrawer();
        setupBlogComments();
        initEffects();

        const initialQ = queryHighlight(window.location.href);
        if (initialQ) applyHighlight(initialQ);
    });
})();

    (function() {
/* ═══════════════════════════════════════════════════════════
     * 功能优化增强
     * ═══════════════════════════════════════════════════════════ */

    // ── 滚动进入动画：IntersectionObserver ──
    function setupScrollReveal() {
        var targets = document.querySelectorAll('.feed-card, .feed-block, .blog-carousel-slide');
        if (!('IntersectionObserver' in window)) {
            document.body.classList.add('no-js');
            targets.forEach(function (el) { el.classList.add('is-revealed'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -40px 0px', threshold: 0.1 });
        targets.forEach(function (el) { io.observe(el); });
    }

    // ── 骨架屏：feed 加载中显示 ──
    function showSkeleton(container) {
        var sk = document.createElement('div');
        sk.className = 'blog-skeleton';
        sk.innerHTML =
            '<div class="skeleton-line"></div>' +
            '<div class="skeleton-line w-80"></div>' +
            '<div class="skeleton-line w-60"></div>' +
            '<div class="skeleton-line w-40"></div>';
        container.appendChild(sk);
        return sk;
    }

    function removeSkeleton(sk) {
        if (sk && sk.parentNode) sk.parentNode.removeChild(sk);
    }

    // ── 搜索关键词高亮 ──
    function highlightKeyword(text, keyword) {
        if (!keyword || keyword.trim() === '') return text;
        var escaped = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var rx = new RegExp('(' + escaped + ')', 'ig');
        return String(text).replace(rx, '<mark class="search-hit">$1</mark>');
    }

    // ── 加载失败重试 ──
    function showLoadError(container, retryFn) {
        var el = document.createElement('div');
        el.className = 'feed-load-error';
        el.innerHTML = '<div>加载失败，请检查网络</div>' +
            '<button type="button" class="retry-btn">重试</button>';
        el.querySelector('.retry-btn').addEventListener('click', function () {
            el.parentNode.removeChild(el);
            retryFn();
        });
        container.appendChild(el);
    }

    // ── 无限滚动改用 IntersectionObserver ──
    function upgradeInfiniteScroll() {
        var grid = document.querySelector('[data-infinite-scroll]');
        if (!grid || grid.dataset.ob === '1') return;
        grid.dataset.ob = '1';
        var sentinel = document.createElement('div');
        sentinel.style.height = '1px';
        sentinel.style.width = '100%';
        if (grid.parentNode) grid.parentNode.insertBefore(sentinel, grid.nextSibling);
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    if (grid.dataset.exhausted === '1') return;
                    var loadBtn = grid.querySelector('[data-load-more]');
                    if (loadBtn && !loadBtn.disabled) loadBtn.click();
                });
            }, { rootMargin: '0px 0px 200px 0px', threshold: 0 });
            io.observe(sentinel);
        }
    }

    // ── 回到顶部 ──
    function setupBackTop() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'blog-back-top';
        btn.setAttribute('aria-label', '回到顶部');
        btn.innerHTML = '\u2191';
        btn.style.display = 'none';
        document.body.appendChild(btn);

        function toggle() {
            var show = window.scrollY > 400;
            btn.style.display = show ? 'flex' : 'none';
            requestAnimationFrame(function () {
                btn.classList.toggle('is-visible', show);
            });
        }
        window.addEventListener('scroll', toggle, { passive: true });
        toggle();

        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ── 阅读进度条（文章页） ──
    function setupReadingProgress() {
        if (!document.querySelector('.blog-article, .blog-post-content')) return;
        var bar = document.createElement('div');
        bar.className = 'blog-progress';
        document.body.appendChild(bar);

        function update() {
            var doc = document.documentElement;
            var total = doc.scrollHeight - window.innerHeight;
            var pct = total > 0 ? (window.scrollY / total) * 100 : 0;
            bar.style.width = pct + '%';
        }
        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    // ── 文章目录滚动高亮 ──
    function setupTocScrollSpy() {
        var toc = document.querySelector('.blog-aside--toc .blog-nav');
        if (!toc) return;
        var links = toc.querySelectorAll('a[href^="#"]');
        if (!links.length) return;
        var headings = [];
        links.forEach(function (link) {
            var id = link.getAttribute('href').slice(1);
            var h = document.getElementById(id);
            if (h) headings.push({ link: link, el: h });
        });
        if (!headings.length) return;

        var current = '';
        function onScroll() {
            var pos = window.scrollY + 80;
            var active = '';
            for (var i = 0; i < headings.length; i++) {
                if (headings[i].el.offsetTop <= pos) active = headings[i].link.getAttribute('href');
            }
            if (!active && headings.length) active = headings[0].link.getAttribute('href');
            if (active !== current) {
                current = active;
                links.forEach(function (link) {
                    link.classList.toggle('is-active', link.getAttribute('href') === active);
                });
            }
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // ── 代码块复制按钮 ──
    function setupCodeCopy() {
        var pres = document.querySelectorAll('.markdown-body pre');
        pres.forEach(function (pre) {
            if (pre.dataset.copyBound === '1') return;
            pre.dataset.copyBound = '1';
            pre.style.position = 'relative';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'code-copy-btn';
            btn.textContent = '复制';
            btn.setAttribute('aria-label', '复制代码');
            pre.appendChild(btn);
            btn.addEventListener('click', function () {
                var code = pre.querySelector('code');
                var text = code ? code.innerText : pre.innerText;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        btn.textContent = '已复制';
                        setTimeout(function () { btn.textContent = '复制'; }, 1600);
                    }, function () { fallback(); });
                } else { fallback(); }
                function fallback() {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); btn.textContent = '已复制'; }
                    catch (e) {}
                    setTimeout(function () { document.body.removeChild(ta); btn.textContent = '复制'; }, 1600);
                }
            });
        });
    }

    // ── 搜索框清除按钮 ──
    function setupSearchClear() {
        var wrap = document.querySelector('.blog-search-input-wrap');
        if (!wrap) return;
        var input = wrap.querySelector('input[type="text"], input:not([type])');
        if (!input) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'search-clear';
        btn.innerHTML = '\u00d7';
        btn.setAttribute('aria-label', '清空搜索');
        wrap.appendChild(btn);

        function sync() {
            var has = input.value.trim() !== '';
            wrap.classList.toggle('has-value', has);
        }
        input.addEventListener('input', sync);
        sync();

        btn.addEventListener('click', function () {
            input.value = '';
            wrap.classList.remove('has-value');
            input.focus();
            input.dispatchEvent(new Event('input'));
        });
    }

    // ── 统一初始化 ──
    function initEnhancements() {
        setupScrollReveal();
        upgradeInfiniteScroll();
        setupBackTop();
        setupReadingProgress();
        setupTocScrollSpy();
        setupCodeCopy();
        setupSearchClear();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEnhancements);
    } else {
        initEnhancements();
    }

})();
