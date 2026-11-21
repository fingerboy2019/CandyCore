if (window.innerWidth > 768) {
    const savedSidebarState = localStorage.getItem('sidebar_active');
    if (savedSidebarState !== null) {
        const isActive = savedSidebarState === 'true';
        // 在DOM渲染前将状态应用到侧边栏
        document.documentElement.className += isActive ? ' sidebar-active' : '';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    // 恢复侧栏状态
    const sidebar = document.querySelector('.sidebar');
    if (sidebar && window.innerWidth > 768) {
        const savedSidebarState = localStorage.getItem('sidebar_active');
        if (savedSidebarState !== null) {
            const isActive = savedSidebarState === 'true';
            if (isActive) {
                sidebar.classList.add('active');
            } else {
                sidebar.classList.remove('active');
            }
        }
    }
    // 深色模式切换功能
    const themeToggle = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');
    const html = document.documentElement;
    const body = document.body;
    const docTheme = body.getAttribute('data-doc-theme');
            
    // 优先级：theme.js 的 doc_dark（cookie/localStorage）> 旧 theme > Front Matter > 系统偏好
    const savedTheme = localStorage.getItem('theme');
    const savedDark = localStorage.getItem('doc_dark');
    const darkCookie = document.cookie.split('; ').some(c => c.indexOf('doc_dark=1') === 0);
    if (darkCookie || savedDark === '1') {
        enableDarkMode(false);
    } else if (savedTheme === 'dark') {
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
            
    function enableDarkMode(persist = true) {
        html.setAttribute('data-bs-theme', 'dark');
        body.classList.add('dark-mode');
        if (themeIcon) themeIcon.textContent = '☀️';
        if (persist) localStorage.setItem('theme', 'dark');
    }
            
    function disableDarkMode(persist = true) {
        html.setAttribute('data-bs-theme', 'light');
        body.classList.remove('dark-mode');
        if (themeIcon) themeIcon.textContent = '🌙';
        if (persist) localStorage.setItem('theme', 'light');
    }
            
    // 监听系统主题变化（仅无用户偏好且无 Front Matter 时）
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (!localStorage.getItem('theme') && !docTheme) {
            if (e.matches) {
                enableDarkMode(false);
            } else {
                disableDarkMode(false);
            }
        }
    });

    /**
     * 同步主题切换按钮图标（无刷新换页后 innerHTML 会重建按钮）
     */
    function syncThemeToggleIcon() {
        const themeIcon = document.getElementById('theme-icon');
        if (themeIcon) {
            themeIcon.textContent = html.getAttribute('data-bs-theme') === 'dark' ? '☀️' : '🌙';
        }
    }

    /**
     * 右下角操作按钮组：事件委托，换页时只更新 innerHTML 不必重复绑定
     */
    function setupDocActionButtons() {
        const container = document.querySelector('.doc-action-buttons');
        if (!container || container.dataset.delegationBound === '1') {
            return;
        }
        container.dataset.delegationBound = '1';
        container.addEventListener('click', (e) => {
            if (e.target.closest('#print-btn')) {
                e.preventDefault();
                window.print();
                return;
            }
            if (e.target.closest('#theme-toggle')) {
                e.preventDefault();
                if (html.getAttribute('data-bs-theme') === 'dark') {
                    disableDarkMode();
                } else {
                    enableDarkMode();
                }
            }
        });
    }

    /**
     * 根据当前页 Front Matter 更新打印/下载/主题按钮（无刷新导航）
     *
     * @param {string} actionsHtml 服务端渲染的按钮 HTML 片段
     */
    function updateDocActions(actionsHtml) {
        const container = document.querySelector('.doc-action-buttons');
        if (!container || typeof actionsHtml !== 'string') {
            return;
        }
        container.innerHTML = actionsHtml;
        syncThemeToggleIcon();
    }

    setupDocActionButtons();
    syncThemeToggleIcon();
            
    document.querySelectorAll('.sidebar li.has-children > a').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const parentLi = item.parentElement;
            parentLi.classList.toggle('active');
        });
    });
            
    // 文档搜索与高亮
    const searchInput = document.getElementById('doc-search-input') || document.querySelector('.search-form input');
    const searchIcon = document.querySelector('.search-form .icon.search');
    const searchResults = document.getElementById('search-results');
    let searchTimer = null;
    let lastSearchKeyword = '';

    function debounceSearch(fn, delay) {
        return function (...args) {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function filterNavByKeyword(keywordLower) {
        const navItems = document.querySelectorAll('.sidebar ul li a');
        if (!keywordLower) {
            navItems.forEach(item => {
                item.parentElement.style.display = 'block';
            });
            return;
        }

        navItems.forEach(item => {
            const text = item.textContent.trim().toLowerCase();
            const parentLi = item.parentElement;
            if (text.includes(keywordLower)) {
                parentLi.style.display = 'block';
                const parentUl = parentLi.closest('ul.submenu');
                if (parentUl) {
                    const parentDir = parentUl.closest('li.has-children');
                    if (parentDir) {
                        parentDir.classList.add('active');
                    }
                }
            } else {
                parentLi.style.display = 'none';
            }
        });
    }

    function clearSearchResults() {
        if (!searchResults) {
            return;
        }
        searchResults.innerHTML = '';
        searchResults.hidden = true;
        searchResults.classList.remove('is-empty');
    }

    function renderSearchResults(payload) {
        if (!searchResults) {
            return;
        }

        const results = payload.results || [];
        if (results.length === 0) {
            searchResults.innerHTML = '<div class="search-results-empty">未找到匹配文档</div>';
            searchResults.classList.add('is-empty');
            searchResults.hidden = false;
            return;
        }

        const html = results.map((item) => {
            const url = item.url + '?q=' + encodeURIComponent(payload.keyword);
            return '<a class="search-result-item" href="' + url + '" data-url="' + item.url + '">'
                + '<span class="search-result-title">' + escapeHtml(item.title) + '</span>'
                + '<span class="search-result-snippet">' + item.snippet + '</span>'
                + '</a>';
        }).join('');

        searchResults.innerHTML = html;
        searchResults.classList.remove('is-empty');
        searchResults.hidden = false;

        searchResults.querySelectorAll('.search-result-item').forEach((item) => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                loadPage(item.getAttribute('href'), payload.keyword);
            });
        });
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getSearchEndpoint() {
        const baseAttr = document.body.getAttribute('data-doc-base');
        if (baseAttr) {
            return baseAttr.endsWith('/') ? baseAttr : baseAttr + '/';
        }
        const path = window.location.pathname || '/Doc/';
        return path.endsWith('/') ? path : path + '/';
    }

    function fetchSearchResults(keyword) {
        if (keyword.length < 1) {
            clearSearchResults();
            return;
        }

        fetch(getSearchEndpoint(), {
            method: 'POST',
            headers: {
                'X-Requested-With': 'SearchRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'keyword=' + encodeURIComponent(keyword)
        })
        .then(response => response.json())
        .then(data => {
            if (searchInput.value.trim() !== keyword) {
                return;
            }
            renderSearchResults(data);
        })
        .catch(error => {
            console.error('搜索失败:', error);
        });
    }

    function handleSearchInput() {
        const keyword = searchInput.value.trim();
        const keywordLower = keyword.toLowerCase();
        lastSearchKeyword = keyword;
        filterNavByKeyword(keywordLower);

        if (!keyword) {
            clearSearchResults();
            return;
        }

        fetchSearchResults(keyword);
    }

    if (searchInput) {
        searchInput.addEventListener('input', debounceSearch(handleSearchInput, 300));
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = searchResults && searchResults.querySelector('.search-result-item');
                if (first) {
                    first.click();
                } else {
                    handleSearchInput();
                }
            }
            if (e.key === 'Escape') {
                searchInput.value = '';
                handleSearchInput();
            }
        });
    }

    if (searchIcon) {
        searchIcon.addEventListener('click', handleSearchInput);
        searchIcon.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                handleSearchInput();
            }
        });
    }

    function clearDocHighlight() {
        document.querySelectorAll('.markdown-body mark.search-hit').forEach((mark) => {
            const parent = mark.parentNode;
            if (!parent) {
                return;
            }
            parent.replaceChild(document.createTextNode(mark.textContent), mark);
            parent.normalize();
        });
    }

    function applyDocHighlight(keyword) {
        const root = document.querySelector('.markdown-body');
        if (!root || !keyword) {
            return;
        }

        clearDocHighlight();
        const kw = keyword.trim();
        if (!kw) {
            return;
        }

        const escaped = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const re = new RegExp('(' + escaped + ')', 'gi');
        const skipTags = new Set(['SCRIPT', 'STYLE', 'PRE', 'CODE', 'MARK']);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }

        nodes.forEach((textNode) => {
            const parent = textNode.parentElement;
            if (!parent || skipTags.has(parent.tagName) || parent.closest('pre, code')) {
                return;
            }
            const text = textNode.nodeValue;
            if (!text || !re.test(text)) {
                re.lastIndex = 0;
                return;
            }
            re.lastIndex = 0;
            const frag = document.createDocumentFragment();
            let lastIndex = 0;
            text.replace(re, (match, _g1, offset) => {
                if (offset > lastIndex) {
                    frag.appendChild(document.createTextNode(text.slice(lastIndex, offset)));
                }
                const mark = document.createElement('mark');
                mark.className = 'search-hit';
                mark.textContent = match;
                frag.appendChild(mark);
                lastIndex = offset + match.length;
                return match;
            });
            if (lastIndex < text.length) {
                frag.appendChild(document.createTextNode(text.slice(lastIndex)));
            }
            parent.replaceChild(frag, textNode);
        });

        const firstHit = root.querySelector('mark.search-hit');
        if (firstHit) {
            firstHit.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function stripUrlQuery(url) {
        return url.split('?')[0];
    }

    function getHighlightFromUrl(url) {
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.searchParams.get('q') || '';
        } catch (e) {
            return '';
        }
    }

    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) {
            return;
        }
        const isActive = sidebar.classList.toggle('active');
        if (window.innerWidth > 768) {
            localStorage.setItem('sidebar_active', isActive);
            if (isActive) {
                document.documentElement.classList.add('sidebar-active');
            } else {
                document.documentElement.classList.remove('sidebar-active');
            }
        }
    }
    const sidebarToggleBtn = document.querySelector('.sidebar-toggle-btn');
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', toggleSidebar);
    }
    
    // 选项卡功能实现
    function updateTab(container, index) {
        // 更新导航
        const navItems = container.querySelectorAll('.tab-nav-item');
        navItems.forEach((item, i) => {
            item.classList.remove('active');
            if (i === index) {
                item.classList.add('active');
            }
        });
        // 更新面板
        const panels = container.querySelectorAll('.tab-panel');
        panels.forEach((panel, i) => {
            panel.classList.remove('active');
            if (i === index) {
                panel.classList.add('active');
            }
        });
    }

    // 获取所有选项卡容器
    const allTabContainers = document.querySelectorAll('.tabs-container');

    // 为每个选项卡容器添加事件监听
    allTabContainers.forEach(container => {
        const navItems = container.querySelectorAll('.tab-nav-item');
        
        navItems.forEach((item, index) => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // 找到当前点击元素对应的选项卡容器
                const currentContainer = this.closest('.tabs-container');
                const tabId = currentContainer.getAttribute('data-tab-id');
                
                if (tabId) {
                    // 如果有ID，同步所有相同ID的容器
                    const sameIdContainers = document.querySelectorAll('[data-tab-id="' + tabId + '"]');
                    sameIdContainers.forEach(sameContainer => {
                        updateTab(sameContainer, index);
                    });
                } else {
                    // 否则只更新当前容器
                    updateTab(currentContainer, index);
                }
            });
        });
    });

    // 无刷新加载页面
    function loadPage(url, highlightKeyword) {
        const highlight = highlightKeyword || getHighlightFromUrl(url);
        const fetchUrl = stripUrlQuery(url);

        fetch(fetchUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            const oldHeader = document.querySelector('head');
            const charsetMeta = oldHeader.querySelector('meta[charset]');
            const viewportMeta = oldHeader.querySelector('meta[name="viewport"]');
            const compatMeta = oldHeader.querySelector('meta[http-equiv="X-UA-Compatible"]');
            let preservedHead = '';
            if (charsetMeta) {
                preservedHead += charsetMeta.outerHTML;
            }
            if (viewportMeta) {
                preservedHead += viewportMeta.outerHTML;
            }
            if (compatMeta) {
                preservedHead += compatMeta.outerHTML;
            }
            oldHeader.innerHTML = preservedHead + data.header;
            document.title = data.title || document.title;

            if (data.nav) {
                const oldNav = document.querySelector('.sidebar ul');
                if (oldNav) {
                    oldNav.innerHTML = data.nav;
                }
            }

            const oldBody = document.querySelector('.markdown-body');
            oldBody.innerHTML = data.body;

            if (typeof data.docActions === 'string') {
                updateDocActions(data.docActions);
            }

            updateNavActive(fetchUrl);
            bindSidebarEvents();
            initJSEffects();

            if (highlight) {
                applyDocHighlight(highlight);
            } else {
                clearDocHighlight();
            }

            history.pushState({}, '', highlight ? (fetchUrl + '?q=' + encodeURIComponent(highlight)) : fetchUrl);
        })
        .catch(error => {
            console.error('页面加载失败:', error);
        });
    }

    // 更新导航选中状态
    function updateNavActive(url) {
        const cleanUrl = stripUrlQuery(url);
        const navItems = document.querySelectorAll('.sidebar ul li a');
        navItems.forEach(item => {
            item.parentElement.classList.remove('active');
            if (stripUrlQuery(item.getAttribute('href')) === cleanUrl) {
                item.parentElement.classList.add('active');
            }
        });
    }

    // 重新绑定侧边栏事件
    function bindSidebarEvents() {
        document.querySelectorAll('.sidebar li.has-children > a').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const parentLi = item.parentElement;
                parentLi.classList.toggle('active');
            });
        });
        
        document.querySelector('.sidebar-toggle-btn')?.addEventListener('click', toggleSidebar);
        
        // 绑定导航链接无刷新事件
        document.querySelectorAll('.sidebar ul li a').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const url = item.getAttribute('href');
                loadPage(url);
                clearSearchResults();
            });
        });
    }

    // 重新初始化JS效果
    function initJSEffects() {
        // 重新初始化代码高亮
        if (typeof Prism !== 'undefined') {
            Prism.highlightAll();
        }
        
        // 重新初始化Mermaid
        if (typeof mermaid !== 'undefined') {
            mermaid.init();
        }
        
        // 重新初始化选项卡
        const allTabContainers = document.querySelectorAll('.tabs-container');
        allTabContainers.forEach(container => {
            const navItems = container.querySelectorAll('.tab-nav-item');
            navItems.forEach((item, index) => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const currentContainer = this.closest('.tabs-container');
                    const tabId = currentContainer.getAttribute('data-tab-id');
                    if (tabId) {
                        const sameIdContainers = document.querySelectorAll('[data-tab-id="' + tabId + '"]');
                        sameIdContainers.forEach(sameContainer => {
                            updateTab(sameContainer, index);
                        });
                    } else {
                        updateTab(currentContainer, index);
                    }
                });
            });
        });
    }
    window.addEventListener('popstate', () => {
        loadPage(window.location.href);
    });

    bindSidebarEvents();

    const initialHighlight = getHighlightFromUrl(window.location.href);
    if (initialHighlight) {
        applyDocHighlight(initialHighlight);
    }

    // ── 目录（TOC）当前章节滚动高亮 ──
    initTocScrollSpy();
    initTocToggle();
});

/**
 * 目录按钮：点击切换面板显隐
 *
 * hover 展开只能临时查看；点击后保持展开，方便移动鼠标到面板选择条目，
 * 再点按钮或点击页面其他区域收起。
 */
function initTocToggle() {
    var container = document.querySelector('.toc-container');
    var toggle = container ? container.querySelector('.toc-toggle') : null;
    if (!container || !toggle) {
        return;
    }
    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        container.classList.toggle('toc-active');
    });
    // 点击面板外区域收起
    document.addEventListener('click', function (e) {
        if (container.classList.contains('toc-active') && !container.contains(e.target)) {
            container.classList.remove('toc-active');
        }
    });
}

/**
 * 目录滚动监听：滚动时高亮当前章节对应条目
 *
 * 为每个 TOC 链接匹配正文锚点标题，用 IntersectionObserver 在滚动
 * 经过标题时切换 .active；兼容 AJAX 页面切换（重新初始化）。
 */
function initTocScrollSpy() {
    var container = document.querySelector('.toc-container');
    var toc = container ? container.querySelector('.toc') : null;
    if (!toc) {
        return;
    }
    var links = Array.prototype.slice.call(toc.querySelectorAll('a[href^="#"]'));
    if (links.length === 0) {
        return;
    }
    // 正文锚点标题（TOC 指向的 h1~h4）
    var headings = [];
    links.forEach(function (link) {
        var id = link.getAttribute('href').slice(1);
        var target = document.getElementById(id);
        if (target) {
            headings.push({ link: link, el: target });
            link.setAttribute('data-toc-id', id);
        }
    });
    if (headings.length === 0) {
        return;
    }
    var current = headings[0].link;
    var mark = function (link) {
        if (link === current) {
            return;
        }
        if (current) {
            current.classList.remove('active');
            var li = current.parentElement;
            if (li) {
                li.classList.remove('active');
            }
        }
        link.classList.add('active');
        var li = link.parentElement;
        if (li) {
            li.classList.add('active');
        }
        current = link;
    };
    var observer = null;
    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                var hit = headings.find(function (h) {
                    return h.el === entry.target;
                });
                if (hit) {
                    mark(hit.link);
                }
            });
        }, { rootMargin: '-20% 0px -65% 0px', threshold: 0 });
        headings.forEach(function (h) {
            observer.observe(h.el);
        });
    } else {
        // 降级：点击锚点跳转后高亮目标
        links.forEach(function (link) {
            link.addEventListener('click', function () {
                mark(link);
            });
        });
    }
    // AJAX 页面切换后由 loadPage 重新触发；此处不做重复绑定（DOM 已替换）
}