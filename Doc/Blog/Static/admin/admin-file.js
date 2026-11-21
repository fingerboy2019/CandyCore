(function () {
    'use strict';

    var core = window.__blogAdminCore;
    var panel = document.getElementById('blogFilePanel');
    if (!core || !panel) {
        return;
    }

    var api = core.api;
    var toast = core.toast;
    var confirmBox = core.confirmBox;
    var formDialog = core.formDialog;
    var getLayer = core.getLayer;
    var copyText = core.copyText;
    var cfg = core.cfg || {};

    var bodyEl = document.getElementById('blogFileBody');
    var breadcrumbEl = document.getElementById('fileBreadcrumb');
    var storageType = panel.getAttribute('data-storage') || 'local';
    var currentPath = panel.getAttribute('data-path') || '';
    var page = 1;
    var limit = 20;
    var total = 0;
    var rowsCache = [];
    var selected = new Set();
    var clipboardKey = 'blogAdminFileClipboard';
    var clipboard = { mode: '', path: '', name: '' };

    function escHtml(text) {
        var node = document.createElement('span');
        node.textContent = text == null ? '' : String(text);
        return node.innerHTML;
    }

    function escAttr(text) {
        return escHtml(text).replace(/"/g, '&quot;');
    }

    function loadClipboard() {
        try {
            var raw = sessionStorage.getItem(clipboardKey);
            if (!raw) return;
            var data = JSON.parse(raw);
            if (data && data.mode && data.path) {
                clipboard = data;
            }
        } catch (err) { /* ignore */ }
    }

    function saveClipboard(mode, path, name) {
        if (storageType !== 'local') {
            toast('云存储不支持粘贴操作', 0);
            return;
        }
        clipboard = { mode: mode, path: path || '', name: name || '' };
        sessionStorage.setItem(clipboardKey, JSON.stringify(clipboard));
        syncPasteBtn();
    }

    function clearClipboard() {
        clipboard = { mode: '', path: '', name: '' };
        sessionStorage.removeItem(clipboardKey);
        syncPasteBtn();
    }

    function syncPasteBtn() {
        var btn = document.getElementById('filePasteBtn');
        if (!btn) return;
        if (clipboard.mode && clipboard.path) {
            var hint = clipboard.mode === 'cut' ? '剪切' : '复制';
            btn.hidden = false;
            btn.textContent = '粘贴 · ' + hint + (clipboard.name ? '：' + clipboard.name : '');
        } else {
            btn.hidden = true;
        }
    }

    function parentPath(path) {
        if (!path) return '';
        var parts = path.split('/');
        parts.pop();
        return parts.join('/');
    }

    function renderBreadcrumb() {
        if (!breadcrumbEl) return;
        var items = [{ label: '/', path: '' }];
        if (currentPath) {
            var acc = '';
            currentPath.split('/').forEach(function (part) {
                if (!part) return;
                acc = acc ? acc + '/' + part : part;
                items.push({ label: part, path: acc });
            });
        }
        var html = '';
        items.forEach(function (item, idx) {
            if (idx > 0) html += '<span class="blog-editor-crumb-sep">/</span>';
            html += '<button type="button" class="blog-editor-crumb" data-file-dir="' + escAttr(item.path) + '">' + escHtml(item.label) + '</button>';
        });
        breadcrumbEl.innerHTML = html;
        var backBtn = document.getElementById('fileBackBtn');
        if (backBtn) backBtn.disabled = !currentPath;
    }

    function getFilterParams() {
        var typeEl = document.getElementById('fileTypeFilter');
        var kwEl = document.getElementById('fileKeyword');
        return {
            type: typeEl ? typeEl.value : 'all',
            keyword: kwEl ? kwEl.value.trim() : ''
        };
    }

    function loadList() {
        if (!bodyEl) return;
        bodyEl.innerHTML = '<div class="blog-editor-empty">加载中…</div>';
        selected.clear();
        var filter = getFilterParams();
        var qs = 'file/list.json?path=' + encodeURIComponent(currentPath)
            + '&type=' + encodeURIComponent(filter.type)
            + '&keyword=' + encodeURIComponent(filter.keyword)
            + '&page=' + page
            + '&limit=' + limit;
        api(qs).then(function (res) {
            if (res.code !== 0) {
                bodyEl.innerHTML = '<div class="blog-editor-empty blog-editor-empty--error">' + escHtml(res.message || '加载失败') + '</div>';
                toast(res.message || '加载失败', 2);
                return;
            }
            rowsCache = res.data || [];
            total = res.count || rowsCache.length;
            if (res.storage) {
                storageType = res.storage;
                panel.setAttribute('data-storage', storageType);
            }
            renderBreadcrumb();
            renderRows();
            renderPager();
            var checkAll = document.getElementById('fileCheckAll');
            if (checkAll) checkAll.checked = false;
        }).catch(function (err) {
            var msg = (err && err.message) ? err.message : '列表加载失败';
            bodyEl.innerHTML = '<div class="blog-editor-empty blog-editor-empty--error">' + escHtml(msg) + '</div>';
            toast(msg, 2);
        });
    }

    function isImageRow(row) {
        var ext = String(row.ext || '').toLowerCase();
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'].indexOf(ext) >= 0;
    }

    function isZipRow(row) {
        var ext = String(row.ext || '').toLowerCase();
        return ext === 'zip' || /\.zip$/i.test(row.name || '');
    }

    function buildNameCell(row) {
        if (row.is_dir) {
            return '<button type="button" class="blog-editor-name blog-editor-name--dir" data-file-dir="' + escAttr(row.path) + '">' + escHtml(row.name) + '</button>';
        }
        var thumb = '';
        if (isImageRow(row) && row.url) {
            thumb = '<img class="blog-file-thumb" src="' + escAttr(row.url) + '" alt="" loading="lazy">';
        }
        var link = row.url
            ? '<a class="blog-editor-name blog-editor-name--file" href="' + escAttr(row.url) + '" target="_blank" rel="noopener">' + escHtml(row.name) + '</a>'
            : '<span class="blog-editor-name">' + escHtml(row.name) + '</span>';
        return thumb + link;
    }

    function buildRowOps(row) {
        var parts = [];
        if (row.is_dir) {
            parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-primary" data-file-dir="' + escAttr(row.path) + '">打开</button>');
        } else if (row.url) {
            parts.push('<button type="button" class="layui-btn layui-btn-xs blog-file-view-one" data-path="' + escAttr(row.path) + '">预览</button>');
            parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-primary blog-file-link-one" data-path="' + escAttr(row.path) + '">链接</button>');
            parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-primary blog-file-ref-one" data-path="' + escAttr(row.path) + '">引用</button>');
            parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-primary blog-file-dl-one" data-path="' + escAttr(row.path) + '">下载</button>');
        }
        if (storageType === 'local') {
            parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-primary blog-file-copy" data-path="' + escAttr(row.path) + '" data-name="' + escAttr(row.name) + '">复制</button>');
            parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-primary blog-file-cut" data-path="' + escAttr(row.path) + '" data-name="' + escAttr(row.name) + '">剪切</button>');
            if (isZipRow(row)) {
                parts.push('<button type="button" class="layui-btn layui-btn-xs blog-file-extract-one" data-path="' + escAttr(row.path) + '">解压</button>');
            } else {
                parts.push('<button type="button" class="layui-btn layui-btn-xs blog-file-zip-one" data-path="' + escAttr(row.path) + '">压缩</button>');
            }
        }
        parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-danger blog-file-rm-one" data-path="' + escAttr(row.path) + '">删除</button>');
        return parts.join('');
    }

    function formatFileSize(bytes) {
        if (!bytes && bytes !== 0) return '-';
        if (bytes < 1024) return bytes + ' B';
        var units = ['KB', 'MB', 'GB', 'TB'];
        var i = -1;
        var n = bytes;
        while (n >= 1024 && i < units.length - 1) {
            n /= 1024;
            i++;
        }
        return n.toFixed(1) + ' ' + units[i];
    }

    /**
     * 打开文件预览弹窗
     *
     * @param {string} path 文件相对路径
     */
    function openFilePreview(path) {
        if (!path) return;
        if (!window.layer) {
            window.open((window.BLOG_ADMIN && window.BLOG_ADMIN.baseUrl || '') + '/file/download?path=' + encodeURIComponent(path));
            return;
        }
        var base = (window.BLOG_ADMIN && window.BLOG_ADMIN.baseUrl) || '';
        layer.open({
            type: 1,
            title: '预览：' + escHtml(path.split('/').pop() || path),
            area: ['860px', '70vh'],
            shadeClose: true,
            content: '<div class="blog-file-preview-body"><div class="blog-file-preview-loading">加载中…</div></div>',
            success: function (layero) {
                var body = layero.find('.blog-file-preview-body');
                var viewHeaders = { 'Content-Type': 'application/json' };
                // POST 请求也附带 CSRF 令牌（服务端统一校验）
                if (cfg && cfg.csrfToken) {
                    viewHeaders['X-CSRF-Token'] = cfg.csrfToken;
                }
                fetch(base + '/file/view.json', {
                    method: 'POST',
                    headers: viewHeaders,
                    credentials: 'same-origin',
                    body: JSON.stringify({ path: path })
                }).then(function (r) { return r.json(); })
                  .then(function (res) {
                      if (!res || res.code !== 0 || !res.data) {
                          body.html('<div class="blog-file-preview-error">' + escHtml((res && res.message) || '预览失败') + '</div>');
                          return;
                      }
                      var d = res.data;
                      if (d.type === 'image') {
                          body.html('<div class="blog-file-preview-image"><img src="' + escAttr(d.url) + '" alt="' + escAttr(d.name) + '"></div>');
                      } else if (d.type === 'markdown') {
                          body.html('<div class="markdown-body">' + (d.html || escHtml(d.content || '')) + '</div>');
                      } else if (d.type === 'text') {
                          body.html('<pre class="blog-file-preview-text">' + escHtml(d.content || '') + '</pre>');
                      } else {
                          body.html('<div class="blog-file-preview-error">该文件类型暂不支持预览，请下载查看。<br>大小：' + formatFileSize(d.size || 0) + '</div>');
                      }
                  })
                  .catch(function () {
                      body.html('<div class="blog-file-preview-error">预览请求失败</div>');
                  });
            }
        });
    }

    function renderRows() {
        if (!rowsCache.length) {
            bodyEl.innerHTML = '<div class="blog-editor-empty">当前目录暂无文件</div>';
            return;
        }
        bodyEl.innerHTML = '';
        rowsCache.forEach(function (row) {
            var rowEl = document.createElement('div');
            rowEl.className = 'blog-editor-row blog-file-row' + (row.is_dir ? ' is-dir' : '');
            if (clipboard.path && clipboard.path === row.path) {
                rowEl.classList.add('blog-file-row--clipboard');
            }
            var checked = selected.has(row.path) ? ' checked' : '';
            rowEl.innerHTML =
                '<div class="blog-file-check"><input type="checkbox" class="file-row-check" data-path="' + escAttr(row.path) + '"' + checked + '></div>' +
                '<div class="blog-editor-name-wrap blog-file-name-wrap">' + buildNameCell(row) + '</div>' +
                '<div class="blog-file-owner">' + escHtml(row.perm_owner || '-') + '</div>' +
                '<div class="blog-editor-size">' + escHtml(row.size_text || '-') + '</div>' +
                '<div class="blog-editor-mtime">' + escHtml(row.mtime || '') + '</div>' +
                '<div class="blog-editor-op">' + buildRowOps(row) + '</div>';
            bodyEl.appendChild(rowEl);
        });
    }

    function renderPager() {
        var pager = document.getElementById('filePager');
        var info = document.getElementById('filePagerInfo');
        if (!pager || !info) return;
        var pages = Math.max(1, Math.ceil(total / limit));
        if (total <= limit) {
            pager.hidden = true;
            return;
        }
        pager.hidden = false;
        info.textContent = '共 ' + total + ' 项，第 ' + page + ' / ' + pages + ' 页';
        document.getElementById('filePrevBtn').disabled = page <= 1;
        document.getElementById('fileNextBtn').disabled = page >= pages;
    }

    function getSelectedRows() {
        return rowsCache.filter(function (row) {
            return selected.has(row.path);
        });
    }

    function getSelectedPaths() {
        return getSelectedRows().map(function (row) {
            return row.path;
        });
    }

    /**
     * 取单个已选文件行（目录不可用）
     *
     * @param {Array<object>|null} rows
     * @return {object|null}
     */
    function requireSingleFileRow(rows) {
        var list = rows || getSelectedRows().filter(function (row) {
            return !row.is_dir;
        });
        if (!list.length) {
            toast('请先选择一个文件', 0);
            return null;
        }
        if (list.length > 1) {
            toast('请只选择一个文件', 0);
            return null;
        }
        return list[0];
    }

    /**
     * 按博客模板规则生成引用（优先使用接口返回的 refs）
     *
     * @param {object} row
     * @return {object}
     */
    function buildRefs(row) {
        if (row.refs && typeof row.refs === 'object') {
            return row.refs;
        }
        var rel = String(row.path || '').replace(/\\/g, '/');
        var staticBase = String(cfg.staticBase || '/Doc/Blog/Static/').replace(/\/+$/, '') + '/';
        var path = row.url || staticBase + rel.split('/').map(function (seg) {
            return encodeURIComponent(seg);
        }).join('/');
        var base = rel.indexOf('img/') === 0 ? 'img_base' : 'static_base';
        var suffix = rel.indexOf('img/') === 0 ? rel.slice(4) : rel;
        var php = "blog('" + base + "') . '" + suffix.replace(/'/g, "\\'") + "'";
        return {
            base: base,
            suffix: suffix,
            path: path,
            php: php,
            echo: "<?= blog_esc(" + php + ") ?>",
            markdown: path,
            markdown_image: '![](' + path + ')'
        };
    }

    /**
     * 打开可复制内容的弹窗
     *
     * @param {string} title
     * @param {Array<{label:string,value:string,hint?:string}>} items
     */
    function openCopyDialog(title, items) {
        getLayer().then(function (layer) {
            if (!layer) {
                toast('需要 Layui 弹层支持', 2);
                return;
            }
            var isMobile = core.isMobileViewport ? core.isMobileViewport() : window.innerWidth <= 768;
            var mlc = core.mobileLayerConfig ? core.mobileLayerConfig(['560px', 'auto']) : {
                area: isMobile ? ['92%', 'auto'] : ['560px', 'auto'],
                offset: 'auto',
                skinExtra: isMobile ? ' blog-admin-layer--sheet' : ''
            };
            var html = '<div class="blog-file-ref-dialog">';
            items.forEach(function (item, idx) {
                html += '<div class="blog-file-ref-row">'
                    + '<div class="blog-file-ref-label">' + escHtml(item.label) + '</div>';
                if (item.hint) {
                    html += '<div class="blog-file-ref-hint">' + escHtml(item.hint) + '</div>';
                }
                html += '<div class="blog-file-ref-value-wrap">'
                    + '<input type="text" class="layui-input blog-file-ref-value" readonly value="' + escAttr(item.value) + '" data-ref-idx="' + idx + '">'
                    + '<button type="button" class="layui-btn layui-btn-sm layui-btn-normal blog-file-ref-copy" data-ref-idx="' + idx + '">复制</button>'
                    + '</div></div>';
            });
            html += '</div>';
            layer.open({
                type: 1,
                title: title,
                area: mlc.area,
                offset: mlc.offset,
                skin: 'blog-admin-layer blog-admin-layer--form blog-admin-layer--ref' + mlc.skinExtra,
                shade: [0.45, '#0f172a'],
                resize: false,
                btnAlign: 'c',
                content: html,
                btn: ['关闭'],
                success: function (layero) {
                    if (core.tuneMobileLayer) {
                        core.tuneMobileLayer(layero);
                    } else if (isMobile) {
                        layero.css({ maxWidth: 'calc(100vw - 16px)' });
                    }
                    layero.find('.blog-file-ref-copy').on('click', function () {
                        var idx = parseInt(this.getAttribute('data-ref-idx') || '0', 10);
                        var value = (items[idx] && items[idx].value) || '';
                        copyText(value).then(function () {
                            toast('已复制', 1);
                        }).catch(function () {
                            toast('复制失败，请手动选择复制', 2);
                        });
                    });
                    layero.find('.blog-file-ref-value').on('click', function () {
                        this.select();
                    });
                }
            });
        });
    }

    /**
     * 获取链接：完整 URL 与站点路径
     *
     * @param {object|null} row
     */
    function showLinkDialog(row) {
        if (!row) return;
        var refs = buildRefs(row);
        var path = refs.path || row.url || '';
        var origin = window.location.origin.replace(/\/$/, '');
        openCopyDialog('获取链接 · ' + (row.name || ''), [
            {
                label: '完整链接',
                hint: '用于外链、分享或第三方引用',
                value: origin + path
            },
            {
                label: '站点路径',
                hint: '以 /Doc/Blog/Static/ 开头，可直接写入配置或 HTML',
                value: path
            }
        ]);
    }

    /**
     * 获取相对地址：按模板 blog() 与 Markdown 规则生成
     *
     * @param {object|null} row
     */
    function showRefDialog(row) {
        if (!row) return;
        var refs = buildRefs(row);
        var templateHint = refs.base === 'img_base'
            ? "模板内与 blog('img_base') 拼接，适用于头像、封面、轮播图等"
            : "模板内与 blog('static_base') 拼接，适用于 CSS、JS 等资源";
        openCopyDialog('获取相对地址 · ' + (row.name || ''), [
            {
                label: '模板 PHP',
                hint: templateHint,
                value: refs.php || ''
            },
            {
                label: '模板输出',
                hint: '在 .tpl.php 中直接输出并转义',
                value: refs.echo || ''
            },
            {
                label: 'Markdown / Front Matter',
                hint: '文章 cover、图片等使用站点绝对路径',
                value: refs.markdown || ''
            },
            {
                label: 'Markdown 图片',
                hint: '正文插图语法',
                value: refs.markdown_image || ''
            }
        ]);
    }

    function postPaths(path, action, paths, extra) {
        var fd = new URLSearchParams();
        fd.append('path', path || '');
        paths.forEach(function (p, i) {
            fd.append('paths[' + i + ']', p);
        });
        if (extra) {
            Object.keys(extra).forEach(function (k) {
                fd.append(k, extra[k]);
            });
        }
        return api('file/' + action + '.json', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });
    }

    function navigateTo(path) {
        currentPath = path || '';
        panel.setAttribute('data-path', currentPath);
        page = 1;
        var url = new URL(window.location.href);
        if (currentPath) {
            url.searchParams.set('path', currentPath);
        } else {
            url.searchParams.delete('path');
        }
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', url.toString());
        }
        loadList();
    }

    function downloadRows(rows) {
        if (!rows.length) {
            toast('请先选择要下载的文件', 0);
            return;
        }
        var files = rows.filter(function (r) { return !r.is_dir; });
        if (!files.length) {
            toast('目录请进入后下载内部文件', 0);
            return;
        }
        if (storageType !== 'local') {
            files.forEach(function (row) {
                if (!row.url) return;
                var join = row.url.indexOf('?') > -1 ? '&' : '?';
                window.open(row.url + join + 'attname=' + encodeURIComponent(row.name || ''));
            });
            return;
        }
        var base = core.resolveAdminBase();
        var qs = files.map(function (row) {
            return 'paths[]=' + encodeURIComponent(row.path);
        }).join('&');
        window.open(base + '/file/download?' + qs);
    }

    function mkdirDialog() {
        return formDialog({
            title: '新建文件夹',
            tplId: 'blogFileMkdirTpl',
            input: '#blogFileMkdirInput',
            btn: ['创建', '取消'],
            emptyTip: '请输入文件夹名称'
        });
    }

    function compressDialog(defaultName) {
        return formDialog({
            title: '压缩为 ZIP',
            tplId: 'blogFileCompressTpl',
            input: '#blogFileZipNameInput',
            btn: ['创建', '取消'],
            emptyTip: '请输入压缩包名称',
            onOpen: function (layero) {
                var input = layero.find('#blogFileZipNameInput');
                if (input.length) input.val(defaultName || 'archive.zip');
            }
        });
    }

    function openUploadDialog() {
        getLayer().then(function (layer) {
            if (!layer) {
                toast('上传需要 Layui 弹层支持', 2);
                return;
            }
            var tpl = document.getElementById('blogFileUploadTpl');
            if (!tpl) return;
            var mlc = core.mobileLayerConfig ? core.mobileLayerConfig(['560px', '']) : {
                area: ['560px', ''],
                offset: 'auto',
                skinExtra: ''
            };
            layer.open({
                type: 1,
                title: '上传文件',
                area: mlc.area,
                offset: mlc.offset,
                skin: 'blog-admin-layer blog-admin-layer--form blog-admin-layer--upload' + mlc.skinExtra,
                shade: [0.45, '#0f172a'],
                resize: false,
                btnAlign: 'c',
                content: tpl.innerHTML,
                btn: ['关闭'],
                success: function (layero) {
                    if (core.tuneMobileLayer) {
                        core.tuneMobileLayer(layero);
                    }
                    initUploadPanel(layero);
                }
            });
        });
    }

    function initUploadPanel(layero) {
        var zone = layero.find('#blogFileUploadZone')[0];
        var picker = layero.find('#blogFileUploadPick')[0];
        var input = layero.find('#blogFileUploadInput')[0];
        var queueEl = layero.find('#blogFileUploadQueue')[0];
        if (!zone || !picker || !input || !queueEl) return;

        var queue = [];
        var uploading = false;
        var base = core.resolveAdminBase();

        function renderQueue() {
            if (!queue.length) {
                queueEl.innerHTML = '<li class="blog-file-upload-empty">暂无上传任务</li>';
                return;
            }
            queueEl.innerHTML = queue.map(function (item) {
                var retryBtn = item.failed
                    ? '<button type="button" class="layui-btn layui-btn-xs layui-btn-primary blog-file-upload-retry" data-idx="' + queue.indexOf(item) + '">重试</button>'
                    : '';
                return '<li class="blog-file-upload-item">'
                    + '<span class="blog-file-upload-item-name">' + escHtml(item.file.name) + '</span>'
                    + '<span class="blog-file-upload-item-status">' + escHtml(item.statusText) + '</span>'
                    + retryBtn
                    + '<div class="blog-file-upload-bar"><i style="width:' + item.percent + '%"></i></div>'
                    + '</li>';
            }).join('');
        }

        function uploadOne(item, idx, done) {
            var fd = new FormData();
            fd.append('path', currentPath);
            fd.append('file', item.file);
            item.statusText = '上传中…';
            item.failed = false;
            renderQueue();
            var xhr = new XMLHttpRequest();
            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable) {
                    item.percent = Math.round((e.loaded / e.total) * 100);
                    renderQueue();
                }
            };
            function fail(text) {
                item.statusText = text;
                item.percent = 100;
                item.failed = true;
                renderQueue();
                done();
            }
            xhr.onerror = function () { fail('网络错误，上传失败'); };
            xhr.ontimeout = function () { fail('上传超时，请重试'); };
            xhr.onabort = function () { fail('上传已取消'); };
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                var ret = null;
                try { ret = JSON.parse(xhr.responseText || '{}'); } catch (e) { ret = null; }
                if (xhr.status === 200 && ret && ret.code === 0) {
                    item.statusText = '完成';
                    item.percent = 100;
                    item.failed = false;
                } else {
                    item.statusText = (ret && ret.message) ? ret.message : '失败';
                    item.percent = 100;
                    item.failed = true;
                }
                renderQueue();
                done();
            };
            xhr.open('POST', base + '/file/upload.json', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            // 上传也需附带 CSRF 令牌
            if (cfg && cfg.csrfToken) {
                xhr.setRequestHeader('X-CSRF-Token', cfg.csrfToken);
            }
            xhr.timeout = 120000; // 120s
            xhr.send(fd);
        }

        function processQueue() {
            if (uploading) return;
            var pending = -1;
            for (var i = 0; i < queue.length; i++) {
                if (queue[i].status === 'pending') { pending = i; break; }
            }
            if (pending < 0) return;
            uploading = true;
            uploadOne(queue[pending], pending, function () {
                if (!queue[pending].failed) {
                    queue[pending].status = 'done';
                } else {
                    queue[pending].status = 'failed';
                }
                uploading = false;
                processQueue();
                if (!queue.some(function (q) { return q.status === 'pending'; })) {
                    loadList();
                    toast('上传任务已完成', 1);
                }
            });
        }

        // 队列内重试按钮：仅重新上传失败的项
        queueEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.blog-file-upload-retry');
            if (!btn) return;
            var idx = parseInt(btn.getAttribute('data-idx'), 10);
            if (isNaN(idx) || !queue[idx]) return;
            if (queue[idx].failed) {
                queue[idx].status = 'pending';
                queue[idx].failed = false;
                queue[idx].percent = 0;
                queue[idx].statusText = '等待中';
                renderQueue();
                processQueue();
            }
        });

        function addFiles(fileList) {
            if (!fileList || !fileList.length) return;
            for (var i = 0; i < fileList.length; i++) {
                queue.push({ file: fileList[i], percent: 0, status: 'pending', statusText: '等待中' });
            }
            renderQueue();
            processQueue();
        }

        picker.addEventListener('click', function (e) {
            e.stopPropagation();
            input.click();
        });
        input.addEventListener('change', function () {
            addFiles(input.files);
            input.value = '';
        });
        zone.addEventListener('click', function () { input.click(); });
        zone.addEventListener('dragover', function (e) {
            e.preventDefault();
            zone.classList.add('is-dragover');
        });
        zone.addEventListener('dragleave', function () { zone.classList.remove('is-dragover'); });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('is-dragover');
            if (e.dataTransfer && e.dataTransfer.files) {
                addFiles(e.dataTransfer.files);
            }
        });
        renderQueue();
    }

    function handleOpsAction(action) {
        var paths = getSelectedPaths();
        if (action === 'upload') {
            openUploadDialog();
            return;
        }
        if (action === 'download') {
            downloadRows(getSelectedRows());
            return;
        }
        if (action === 'copy-link') {
            showLinkDialog(requireSingleFileRow());
            return;
        }
        if (action === 'copy-ref') {
            showRefDialog(requireSingleFileRow());
            return;
        }
        if (action === 'mkdir') {
            mkdirDialog().then(function (name) {
                if (!name) return;
                postPaths(currentPath, 'mkdir', [], { name: name }).then(function (res) {
                    toast(res.message, res.code === 0 ? 1 : 2);
                    if (res.code === 0) loadList();
                });
            });
            return;
        }
        if (action === 'paste') {
            if (!clipboard.mode || !clipboard.path) return;
            postPaths(currentPath, 'paste', [], { from: clipboard.path, mode: clipboard.mode }).then(function (res) {
                toast(res.message, res.code === 0 ? 1 : 2);
                if (res.code === 0) {
                    clearClipboard();
                    loadList();
                }
            });
            return;
        }
        if (action === 'remove') {
            if (!paths.length) { toast('请先选择要删除的项', 0); return; }
            confirmBox('确定删除选中的 ' + paths.length + ' 项？', { title: '删除确认', danger: true, btn: ['删除', '取消'] }).then(function (ok) {
                if (!ok) return;
                postPaths(currentPath, 'remove', paths).then(function (res) {
                    toast(res.message, res.code === 0 ? 1 : 2);
                    if (res.code === 0) loadList();
                });
            });
            return;
        }
        if (action === 'compress') {
            if (storageType !== 'local') { toast('云存储不支持压缩', 0); return; }
            if (!paths.length) { toast('请先选择要压缩的项', 0); return; }
            compressDialog('archive_' + Date.now() + '.zip').then(function (name) {
                if (!name) return;
                if (!/\.zip$/i.test(name)) name += '.zip';
                postPaths(currentPath, 'compress', paths, { name: name }).then(function (res) {
                    toast(res.message, res.code === 0 ? 1 : 2);
                    if (res.code === 0) loadList();
                });
            });
            return;
        }
        if (action === 'extract') {
            if (storageType !== 'local') { toast('云存储不支持解压', 0); return; }
            var zips = getSelectedRows().filter(isZipRow);
            if (!zips.length) { toast('请选择 ZIP 文件', 0); return; }
            postPaths(currentPath, 'extract', zips.map(function (r) { return r.path; })).then(function (res) {
                toast(res.message, res.code === 0 ? 1 : 2);
                if (res.code === 0) loadList();
            });
        }
    }

    document.getElementById('fileBackBtn').addEventListener('click', function () {
        navigateTo(parentPath(currentPath));
    });
    document.getElementById('fileRefreshBtn').addEventListener('click', loadList);
    document.getElementById('fileFilterForm').addEventListener('submit', function () {
        page = 1;
        loadList();
    });
    document.getElementById('filePrevBtn').addEventListener('click', function () {
        if (page > 1) { page--; loadList(); }
    });
    document.getElementById('fileNextBtn').addEventListener('click', function () {
        if (page < Math.ceil(total / limit)) { page++; loadList(); }
    });

    var opsBtn = document.getElementById('fileOpsBtn');
    var opsPanel = document.getElementById('fileOpsPanel');
    if (opsBtn && opsPanel) {
        opsBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            opsPanel.hidden = !opsPanel.hidden;
        });
        document.addEventListener('click', function () { opsPanel.hidden = true; });
        opsPanel.addEventListener('click', function (e) { e.stopPropagation(); });
        opsPanel.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                opsPanel.hidden = true;
                handleOpsAction(btn.getAttribute('data-action'));
            });
        });
    }

    document.getElementById('fileCheckAll').addEventListener('change', function (e) {
        var checked = e.target.checked;
        selected.clear();
        if (checked) {
            rowsCache.forEach(function (row) { selected.add(row.path); });
        }
        bodyEl.querySelectorAll('.file-row-check').forEach(function (cb) {
            cb.checked = checked;
        });
    });

    bodyEl.addEventListener('change', function (e) {
        if (!e.target.classList.contains('file-row-check')) return;
        var path = e.target.getAttribute('data-path') || '';
        if (e.target.checked) selected.add(path);
        else selected.delete(path);
    });

    bodyEl.addEventListener('click', function (e) {
        var dirBtn = e.target.closest('[data-file-dir]');
        if (dirBtn) {
            navigateTo(dirBtn.getAttribute('data-file-dir') || '');
            return;
        }
        var copyBtn = e.target.closest('.blog-file-copy');
        if (copyBtn) {
            saveClipboard('copy', copyBtn.getAttribute('data-path'), copyBtn.getAttribute('data-name'));
            toast('已复制', 1);
            renderRows();
            return;
        }
        var cutBtn = e.target.closest('.blog-file-cut');
        if (cutBtn) {
            saveClipboard('cut', cutBtn.getAttribute('data-path'), cutBtn.getAttribute('data-name'));
            toast('已剪切', 1);
            renderRows();
            return;
        }
        var viewBtn = e.target.closest('.blog-file-view-one');
        if (viewBtn) {
            openFilePreview(viewBtn.getAttribute('data-path'));
            return;
        }
        var dlBtn = e.target.closest('.blog-file-dl-one');
        if (dlBtn) {
            var row = rowsCache.find(function (r) { return r.path === dlBtn.getAttribute('data-path'); });
            if (row) downloadRows([row]);
            return;
        }
        var linkBtn = e.target.closest('.blog-file-link-one');
        if (linkBtn) {
            var linkRow = rowsCache.find(function (r) { return r.path === linkBtn.getAttribute('data-path'); });
            if (linkRow) showLinkDialog(linkRow);
            return;
        }
        var refBtn = e.target.closest('.blog-file-ref-one');
        if (refBtn) {
            var refRow = rowsCache.find(function (r) { return r.path === refBtn.getAttribute('data-path'); });
            if (refRow) showRefDialog(refRow);
            return;
        }
        var rmBtn = e.target.closest('.blog-file-rm-one');
        if (rmBtn) {
            var p = rmBtn.getAttribute('data-path') || '';
            confirmBox('确定删除「' + p + '」？', { title: '删除确认', danger: true, btn: ['删除', '取消'] }).then(function (ok) {
                if (!ok) return;
                postPaths(currentPath, 'remove', [p]).then(function (res) {
                    toast(res.message, res.code === 0 ? 1 : 2);
                    if (res.code === 0) loadList();
                });
            });
            return;
        }
        var zipBtn = e.target.closest('.blog-file-zip-one');
        if (zipBtn) {
            var zp = zipBtn.getAttribute('data-path') || '';
            compressDialog('archive.zip').then(function (name) {
                if (!name) return;
                if (!/\.zip$/i.test(name)) name += '.zip';
                postPaths(currentPath, 'compress', [zp], { name: name }).then(function (res) {
                    toast(res.message, res.code === 0 ? 1 : 2);
                    if (res.code === 0) loadList();
                });
            });
            return;
        }
        var exBtn = e.target.closest('.blog-file-extract-one');
        if (exBtn) {
            postPaths(currentPath, 'extract', [exBtn.getAttribute('data-path') || '']).then(function (res) {
                toast(res.message, res.code === 0 ? 1 : 2);
                if (res.code === 0) loadList();
            });
        }
    });

    loadClipboard();
    syncPasteBtn();
    loadList();
})();
