(function () {
    'use strict';

    var cfg = window.BLOG_ADMIN || {};

    /**
     * 解析博客管理后台 API 前缀（须为 /Blog/{8位目录码}/admin）
     *
     * 优先 BLOG_ADMIN.baseUrl，其次 body data 属性，最后从当前 pathname 推断
     *
     * @return {string}
     */
    function resolveAdminBase() {
        var fromCfg = (cfg.baseUrl || '').replace(/\/$/, '');
        if (fromCfg) {
            return fromCfg;
        }
        var body = document.body && document.body.getAttribute('data-blog-admin-base');
        if (body) {
            return body.replace(/\/$/, '');
        }
        var m = (window.location.pathname || '').match(/^(\/blog\/[a-z0-9]{8}\/admin)/i)
            || (window.location.pathname || '').match(/^(\/[a-z0-9]{8}\/admin)/i);
        return m ? m[1] : '';
    }

    function api(path, options) {
        options = options || {};
        var base = resolveAdminBase();
        if (!base) {
            return Promise.reject(new Error('无法识别博客管理后台地址，请从 /Blog/{目录码}/admin 进入'));
        }
        var url = base + '/' + path.replace(/^\//, '');
        var method = String((options.method || 'GET')).toUpperCase();
        var headers = Object.assign({}, options.headers || {});
        // POST 写请求自动附带 CSRF 令牌（服务端校验，防跨站提交）
        if (method === 'POST' && cfg && cfg.csrfToken && !headers['X-CSRF-Token']) {
            headers['X-CSRF-Token'] = cfg.csrfToken;
        }
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: Object.assign({
                'X-Requested-With': 'XMLHttpRequest'
            }, headers),
            method: method
        }, options)).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (err) {
                    throw new Error('接口返回异常（' + res.status + '）');
                }
                if (!res.ok && data && data.message) {
                    throw new Error(data.message);
                }
                if (!res.ok) {
                    throw new Error('请求失败（' + res.status + '）');
                }
                return data || { code: 1, message: '空响应' };
            });
        });
    }

    function getLayer() {
        if (!window.layui) {
            return Promise.resolve(null);
        }
        if (!window.__blogAdminLayerReady) {
            window.__blogAdminLayerReady = new Promise(function (resolve) {
                layui.use(['layer', 'form', 'element'], function () {
                    resolve(layui.layer);
                });
            });
        }
        return window.__blogAdminLayerReady;
    }

    /**
     * 操作结果提示（统一 Layui 风格）
     *
     * @param {string} msg 文案
     * @param {number} icon 0 提示 1 成功 2 失败
     */
    function toast(msg, icon) {
        // 幂等保护：350ms 内相同文案只提示一次，避免 api().catch 与调用方 catch 重复 toast
        var now = Date.now();
        var last = (window.__blogAdminToastLast && window.__blogAdminToastLast.msg === msg
            && now - window.__blogAdminToastLast.at < 350);
        if (!last) {
            window.__blogAdminToastLast = { msg: msg, at: now };
        } else {
            return;
        }
        getLayer().then(function (layer) {
            if (!layer) {
                alert(msg);
                return;
            }
            var skin = 'blog-admin-toast';
            var iconSvg = '';
            if (icon === 1) {
                skin += ' blog-admin-toast--success';
                iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            } else if (icon === 2) {
                skin += ' blog-admin-toast--error';
                iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            } else {
                skin += ' blog-admin-toast--info';
                iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
            }
            layer.msg('<span class="blog-admin-toast-icon">' + iconSvg + '</span><span class="blog-admin-toast-text">' + (msg || '') + '</span>', {
                icon: -1,
                time: 2600,
                offset: 'auto',
                skin: skin,
                shade: 0,
                anim: 0
            });
        });
    }

    /**
     * 捕获未处理的 Promise 拒绝，统一提示，避免网络/接口错误静默失败
     *
     * 仅当调用方未自行 .catch 时才触发（已处理的不受影响，不会重复提示）。
     * 幂等由 toast() 的 350ms 同文案去重保证。
     */
    window.addEventListener('unhandledrejection', function (e) {
        var reason = e && e.reason;
        var msg = '请求失败，请稍后重试';
        if (reason instanceof Error && reason.message) {
            msg = reason.message;
        } else if (reason && typeof reason === 'object' && reason.message) {
            msg = reason.message;
        }
        toast(msg, 2);
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
        }
    });

    /**
     * 美化确认框（替代原生 confirm）
     *
     * @param {string} message 提示文案
     * @param {object} options title、btn、danger
     * @return {Promise<boolean>}
     */
    function handleRename(path, currentName) {
        if (!cfg.writable) {
            toast('当前目录不可写', 2);
            return;
        }
        getLayer().then(function (layer) {
            if (!layer) {
                var name = prompt('重命名为：', currentName || '');
                if (name && name !== currentName) {
                    api('editor/rename.json', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'path=' + encodeURIComponent(path) + '&name=' + encodeURIComponent(name)
                    }).then(function (res) {
                        toast(res.message, res.code === 0 ? 1 : 2);
                        if (res.code === 0) loadList();
                    });
                }
                return;
            }
            layer.prompt({
                title: '重命名',
                value: currentName || '',
                maxlength: 128,
                formType: 0,
                area: ['420px', 'auto'],
                btn: ['确定', '取消'],
                skin: 'blog-admin-layer blog-admin-layer--form'
            }, function (value, index) {
                var name = String(value || '').trim();
                if (!name) { toast('名称不能为空', 2); return; }
                if (name === currentName) { layer.close(index); return; }
                api('editor/rename.json', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'path=' + encodeURIComponent(path) + '&name=' + encodeURIComponent(name)
                }).then(function (res) {
                    if (res && res.code === 0) {
                        layer.close(index);
                        toast('重命名成功', 1);
                        loadList();
                    } else {
                        toast((res && res.message) || '重命名失败', 2);
                    }
                });
            });
        });
    }

    function confirmBox(message, options) {
        options = options || {};
        return getLayer().then(function (layer) {
            if (!layer) {
                return window.confirm(message);
            }
            return new Promise(function (resolve) {
                var skin = 'blog-admin-layer';
                if (options.danger) {
                    skin += ' blog-admin-layer--danger';
                }
                layer.confirm(message, {
                    title: options.title || '请确认',
                    icon: options.icon != null ? options.icon : 3,
                    skin: skin,
                    shade: [0.45, '#0f172a'],
                    btnAlign: 'c',
                    btn: options.btn || ['确定', '取消']
                }, function (index) {
                    layer.close(index);
                    resolve(true);
                }, function () {
                    resolve(false);
                });
            });
        });
    }

    /**
     * 是否为小屏视口（与样式断点一致）
     *
     * @return {boolean}
     */
    function isMobileViewport() {
        return window.innerWidth <= 768;
    }

    /**
     * 小屏居中弹层参数
     *
     * @param {string|Array} desktopArea 桌面端 area
     * @return {{area: Array, offset: string, skinExtra: string}}
     */
    function mobileLayerConfig(desktopArea) {
        var area = desktopArea || ['480px', ''];
        if (!Array.isArray(area)) {
            area = [String(area), ''];
        }
        if (isMobileViewport()) {
            return {
                area: ['92%', 'auto'],
                offset: 'auto',
                skinExtra: ' blog-admin-layer--sheet'
            };
        }
        return {
            area: area,
            offset: 'auto',
            skinExtra: ''
        };
    }

    /**
     * 小屏弹层尺寸与底栏按钮校正
     *
     * @param {object} layero jQuery 包装层节点
     * @return {void}
     */
    function tuneMobileLayer(layero) {
        if (!isMobileViewport()) {
            return;
        }
        layero.css({ maxWidth: 'calc(100vw - 16px)' });
        var btnCount = layero.find('.layui-layer-btn a').length;
        layero.toggleClass('blog-admin-layer--single', btnCount <= 1);
    }

    /**
     * 通用表单弹窗（新建目录/文章等）
     *
     * @param {object} options 弹窗配置
     * @return {Promise<string|null>}
     */
    function formDialog(options) {
        return getLayer().then(function (layer) {
            if (!layer) {
                var val = window.prompt(options.fallbackPrompt || options.title, '');
                if (val === null) return null;
                val = String(val).trim();
                return val || null;
            }
            var tpl = document.getElementById(options.tplId);
            if (!tpl) {
                return promptBox(options.title, options.prompt || {});
            }
            return new Promise(function (resolve) {
                var mlc = mobileLayerConfig(options.area || ['480px', '']);
                layer.open({
                    type: 1,
                    title: options.title,
                    // 第二项须为空字符串，layui 才会按内容自适应高度；'auto' 会触发错误的高度计算并出现滚动条
                    area: mlc.area,
                    offset: mlc.offset,
                    skin: 'blog-admin-layer blog-admin-layer--form' + mlc.skinExtra,
                    shade: [0.45, '#0f172a'],
                    resize: false,
                    btnAlign: 'c',
                    content: tpl.innerHTML,
                    btn: options.btn || ['确定', '取消'],
                    success: function (layero) {
                        tuneMobileLayer(layero);
                        if (!isMobileViewport()) {
                            layero.find('.layui-layer-content').css({ height: 'auto', overflow: 'visible' });
                        }
                        layero.css('height', 'auto');
                        if (typeof options.onOpen === 'function') {
                            options.onOpen(layero);
                        }
                        var input = layero.find(options.input || 'input[type="text"]').first();
                        input.on('keydown', function (e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                layero.find('.layui-layer-btn0').trigger('click');
                            }
                        });
                        setTimeout(function () { input.focus(); }, 80);
                    },
                    yes: function (index, layero) {
                        var input = layero.find(options.input || 'input[type="text"]').first();
                        var val = String(input.val() || '').trim();
                        if (typeof options.validate === 'function') {
                            var err = options.validate(val, layero);
                            if (err) {
                                toast(err, 0);
                                return;
                            }
                        } else if (!val) {
                            toast(options.emptyTip || '请输入内容', 0);
                            return;
                        }
                        layer.close(index);
                        resolve(val);
                    },
                    btn2: function () {
                        resolve(null);
                    },
                    cancel: function () {
                        resolve(null);
                    }
                });
            });
        });
    }

    /**
     * 美化输入框（Layui prompt，备用）
     *
     * @param {string} title 标题
     * @param {object} options placeholder、maxlength、value
     * @return {Promise<string|null>}
     */
    function promptBox(title, options) {
        options = options || {};
        return getLayer().then(function (layer) {
            if (!layer) {
                var val = window.prompt(title, options.value || '');
                return val === null ? null : String(val).trim() || null;
            }
            return new Promise(function (resolve) {
                layer.prompt({
                    title: title,
                    formType: 0,
                    maxlength: options.maxlength || 128,
                    value: options.value || '',
                    skin: 'blog-admin-layer blog-admin-layer--prompt',
                    shade: [0.45, '#0f172a'],
                    placeholder: options.placeholder || ''
                }, function (value, index) {
                    value = String(value || '').trim();
                    if (!value && options.required !== false) {
                        toast(options.emptyTip || '请输入内容', 0);
                        return;
                    }
                    layer.close(index);
                    resolve(value || null);
                }, function () {
                    resolve(null);
                });
            });
        });
    }

    /**
     * 新建分类目录弹窗
     *
     * @return {Promise<string|null>}
     */
    function mkdirDialog() {
        return formDialog({
            title: '新建分类目录',
            tplId: 'blogMkdirDialogTpl',
            input: '#blogMkdirNameInput',
            btn: ['创建', '取消'],
            emptyTip: '请输入目录名称',
            fallbackPrompt: '新建分类目录'
        });
    }

    /**
     * 新建文章弹窗
     *
     * @param {string} dirPath 当前目录相对路径
     * @return {Promise<string|null>}
     */
    function createFileDialog(dirPath) {
        return formDialog({
            title: '新建文章',
            tplId: 'blogCreateFileDialogTpl',
            input: '#blogCreateFileNameInput',
            btn: ['创建', '取消'],
            emptyTip: '请输入文章文件名',
            fallbackPrompt: '新建文章',
            onOpen: function (layero) {
                var pathEl = layero.find('#blogCreateFilePath');
                if (pathEl.length) {
                    pathEl.text(dirPath ? '/' + dirPath : '/（根目录）');
                }
            }
        });
    }

    // 登录
    var loginForm = document.getElementById('blogAdminLoginForm');
    if (loginForm) {
        var captchaImg = document.getElementById('blogAdminCaptcha');
        if (captchaImg) {
            captchaImg.addEventListener('click', function () {
                var cbase = (window.BLOG_ADMIN && window.BLOG_ADMIN.baseUrl) || '';
                captchaImg.src = cbase + '/captcha?_=' + Date.now();
            });
        }
        loginForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(loginForm);
            var body = new URLSearchParams(fd);
            if (fd.get('remember')) {
                body.set('remember', '1');
            }
            api('login/do', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (res) {
                if (res.code === 0) {
                    location.href = res.url || base + '/';
                } else {
                    toast(res.message || '登录失败', 2);
                    if (captchaImg) captchaImg.click();
                }
            });
        });
    }

    // 配置 Tab 保存
    document.querySelectorAll('.blog-config-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var tab = form.getAttribute('data-tab');
            var fd = new FormData(form);
            fd.append('tab', tab);

            function doSave() {
                api('config/save', { method: 'POST', body: fd }).then(function (res) {
                    toast(res.message || (res.code === 0 ? '保存成功' : '失败'), res.code === 0 ? 1 : 2);
                    if (res.code === 0 && res.url) {
                        setTimeout(function () {
                            location.href = res.url;
                        }, 900);
                    }
                });
            }

            if (tab === 'content_dir') {
                var newDir = String(fd.get('content_dir') || '').trim().toLowerCase();
                if (!/^[a-z0-9]{8}$/.test(newDir)) {
                    toast('目录名须为 8 位小写字母或数字', 0);
                    return;
                }
                confirmBox(
                    '修改后旧管理地址将失效，磁盘目录将重命名为「' + newDir + '」。确定继续吗？',
                    { title: '修改正文目录', danger: true, btn: ['确认修改', '取消'] }
                ).then(function (ok) {
                    if (ok) doSave();
                });
                return;
            }

            doSave();
        });
    });

    var contentDirGenerateBtn = document.getElementById('blogContentDirGenerate');
    if (contentDirGenerateBtn) {
        contentDirGenerateBtn.addEventListener('click', function () {
            var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            var code = '';
            for (var i = 0; i < 8; i++) {
                code += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            var input = document.getElementById('blogContentDirInput');
            if (input) input.value = code;
        });
    }

    // RSS / Sitemap 静态生成
    document.querySelectorAll('[data-feed-generate]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var which = btn.getAttribute('data-feed-generate') || 'all';
            var endpoint = 'config/generateFeed';
            if (which === 'rss') endpoint = 'config/generateRss';
            if (which === 'sitemap') endpoint = 'config/generateSitemap';
            btn.disabled = true;
            api(endpoint, { method: 'POST' }).then(function (res) {
                toast(res.message || (res.code === 0 ? '已生成' : '生成失败'), res.code === 0 ? 1 : 2);
                if (res.code === 0) {
                    setTimeout(function () {
                        location.reload();
                    }, 700);
                }
            }).finally(function () {
                btn.disabled = false;
            });
        });
    });

    // 配置备份 / 还原
    var backupBtn = document.getElementById('blogConfigBackupBtn');
    if (backupBtn) {
        backupBtn.addEventListener('click', function () {
            backupBtn.disabled = true;
            api('config/backup', { method: 'POST' }).then(function (res) {
                if (res && res.code === 0 && res.data) {
                    var blob = new Blob([res.data.content || ''], { type: 'application/json;charset=utf-8' });
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = res.data.filename || 'blog-config-backup.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
                    toast('配置已导出', 1);
                } else {
                    toast((res && res.message) || '导出失败', 2);
                }
            }).catch(function () {
                toast('导出失败，请重试', 2);
            }).finally(function () {
                backupBtn.disabled = false;
            });
        });
    }
    var restorePick = document.getElementById('blogConfigRestorePick');
    var restoreInput = document.getElementById('blogConfigRestoreInput');
    var restoreName = document.getElementById('blogConfigRestoreName');
    if (restorePick && restoreInput) {
        restorePick.addEventListener('click', function () {
            restoreInput.click();
        });
        restoreInput.addEventListener('change', function () {
            var file = restoreInput.files && restoreInput.files[0];
            if (!file) return;
            if (restoreName) restoreName.textContent = file.name;
            if (file.size > 2 * 1024 * 1024) {
                toast('备份文件不能超过 2MB', 2);
                return;
            }
            layer.confirm('导入后将覆盖当前全部配置，确定继续？', {
                title: '还原配置',
                btn: ['确定', '取消'],
                icon: 3
            }, function (index) {
                layer.close(index);
                var reader = new FileReader();
                reader.onload = function (ev) {
                    api('config/restore', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ content: ev.target.result })
                    }).then(function (res) {
                        toast(res.message || (res.code === 0 ? '配置已还原' : '还原失败'), res.code === 0 ? 1 : 2);
                        if (res.code === 0) {
                            setTimeout(function () { location.reload(); }, 700);
                        }
                    });
                };
                reader.readAsText(file, 'utf-8');
            });
        });
    }

    // 历史快照列表 / 还原
    var snapshotList = document.getElementById('blogSnapshotList');
    if (snapshotList) {
        snapshotList.addEventListener('click', function (e) {
            var btn = e.target.closest('.blog-snapshot-restore');
            if (!btn) return;
            var name = btn.getAttribute('data-name') || '';
            if (!name) return;
            layer.confirm('确认还原该快照？将覆盖当前全部配置。', {
                title: '还原快照',
                btn: ['还原', '取消'],
                icon: 3
            }, function (index) {
                layer.close(index);
                btn.disabled = true;
                api('config/restoreSnapshot', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: name })
                }).then(function (res) {
                    toast(res.message || (res.code === 0 ? '已还原' : '还原失败'), res.code === 0 ? 1 : 2);
                    if (res.code === 0) {
                        setTimeout(function () { location.reload(); }, 700);
                    }
                }).finally(function () {
                    btn.disabled = false;
                });
            });
        });
    }

    // 垃圾评论拦截规则（JSON 文件）
    var spamSaveBtn = document.getElementById('blogSpamFilterSave');
    if (spamSaveBtn) {
        spamSaveBtn.addEventListener('click', function () {
            var enabled = document.getElementById('blogSpamEnabled');
            var maxLinks = document.getElementById('blogSpamMaxLinks');
            var keywords = document.getElementById('blogSpamKeywords');
            var kwRaw = keywords ? keywords.value : '';
            var kwList = kwRaw.split(/[\r\n,，]+/).map(function (s) { return s.trim(); }).filter(Boolean);
            spamSaveBtn.disabled = true;
            api('comment/spamFilter', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    save: 1,
                    enabled: enabled ? enabled.checked : true,
                    max_links: parseInt(maxLinks ? maxLinks.value : '3', 10) || 0,
                    keywords: kwList
                })
            }).then(function (res) {
                toast(res.message || (res.code === 0 ? '拦截规则已保存' : '保存失败'), res.code === 0 ? 1 : 2);
            }).catch(function () {
                toast('保存失败，请重试', 2);
            }).finally(function () {
                spamSaveBtn.disabled = false;
            });
        });
    }

    // 键值对增删
    var kvPlaceholders = {
        label: '标签', url: '链接', name: '名称', description: '描述', key: '键名', value: '值'
    };

    /**
     * 生成美化后的键值行 HTML
     *
     * @param {string} prefix 字段前缀
     * @param {number} idx 行索引
     * @param {string[]} fields 字段列表
     * @param {string} kvType 表格类型 nav|links|vars
     * @return {string}
     */
    function buildKvRowHtml(prefix, idx, fields, kvType) {
        var html = '';
        fields.forEach(function (f) {
            if (f === 'external') {
                html += '<div class="kv-field kv-field--check"><input type="checkbox" name="' + prefix + '[' + idx + '][external]" value="1" title="外链" lay-skin="primary"></div>';
            } else {
                var ph = kvPlaceholders[f] || f;
                html += '<div class="kv-field"><input name="' + prefix + '[' + idx + '][' + f + ']" class="layui-input" placeholder="' + ph + '"></div>';
            }
        });
        html += '<div class="kv-field kv-field--op"><button type="button" class="layui-btn layui-btn-xs layui-btn-danger kv-remove">删除</button></div>';
        return html;
    }

    document.body.addEventListener('click', function (e) {
        var addBtn = e.target.closest('.kv-add');
        if (addBtn) {
            var prefix = addBtn.getAttribute('data-target');
            var fields = (addBtn.getAttribute('data-fields') || '').split(',');
            var kvType = addBtn.getAttribute('data-kv-type') || 'nav';
            var box = addBtn.parentElement.querySelector('.kv-rows');
            if (!box) return;
            var idx = box.querySelectorAll('.kv-row').length;
            var row = document.createElement('div');
            row.className = 'kv-row';
            row.innerHTML = buildKvRowHtml(prefix, idx, fields, kvType);
            box.appendChild(row);
            if (window.layui && layui.form) {
                layui.form.render('checkbox');
            }
        }
        if (e.target.closest('.kv-remove')) {
            e.target.closest('.kv-row').remove();
        }
    });

    // 编辑器
    var editorBody = document.getElementById('editorFileBody');
    if (editorBody) {
        var currentPath = '';
        var breadcrumbEl = document.getElementById('editorBreadcrumb');
        var editorCm = null;
        var cmAssetsReady = null;
        var editorCurrentName = '';

        function encodePathSegments(path) {
            if (!path) {
                return '';
            }
            return path.split('/').map(function (seg) {
                return encodeURIComponent(seg);
            }).join('/');
        }

        /**
         * 根据磁盘相对路径生成博客前台 URL
         *
         * @param {object} row 列表行
         * @return {string|null}
         */
        function buildFrontViewUrl(row) {
            var portal = (cfg.portalUrl || '/Blog/').replace(/\/?$/, '/');
            var relPath = String(row.path || '');
            var name = String(row.name || '');
            if (row.is_dir) {
                return portal + encodePathSegments(relPath) + (relPath ? '/' : '');
            }
            if (/\.md$/i.test(name)) {
                if (/^readme\.md$/i.test(name)) {
                    var readmeParent = relPath.replace(/\/?README\.md$/i, '');
                    return portal + encodePathSegments(readmeParent) + (readmeParent ? '/' : '');
                }
                return portal + encodePathSegments(relPath.replace(/\.md$/i, ''));
            }
            // 非 Markdown 文件（如 .order）跳转至所在分类或博客首页
            var parentDir = relPath.indexOf('/') >= 0 ? relPath.replace(/\/[^/]+$/, '') : '';
            return portal + encodePathSegments(parentDir) + (parentDir ? '/' : '');
        }

        function buildRowOps(row) {
            var parts = [];
            var viewUrl = buildFrontViewUrl(row);
            parts.push('<a class="blog-editor-view-link" href="' + escAttr(viewUrl) + '" target="_blank" rel="noopener" title="在新标签页查看前台效果">查看</a>');
            if (cfg.writable && row.path !== 'README.md') {
                parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-primary" data-rename="' + escAttr(row.path) + '" title="重命名">重命名</button>');
                parts.push('<button type="button" class="layui-btn layui-btn-xs layui-btn-danger" data-remove="' + escAttr(row.path) + '">删除</button>');
            }
            return parts.join('');
        }

        function escHtml(text) {
            var node = document.createElement('span');
            node.textContent = text == null ? '' : String(text);
            return node.innerHTML;
        }

        function escAttr(text) {
            return escHtml(text).replace(/"/g, '&quot;');
        }

        function getEditorContent() {
            if (editorCm && window.CandyCodeEditor) {
                return CandyCodeEditor.getValue(editorCm);
            }
            var ta = document.getElementById('editorTextarea');
            return ta ? ta.value : '';
        }

        function ensureCodeMirror() {
            if (!window.CandyCodeEditor) {
                return Promise.reject(new Error('CandyCodeEditor 未加载'));
            }
            if (!cmAssetsReady) {
                var staticBase = (cfg.publicBase || '/Public') + '/static';
                cmAssetsReady = CandyCodeEditor.ensureAssets(staticBase);
            }
            return cmAssetsReady;
        }

        function initCodeMirror(filename) {
            var textarea = document.getElementById('editorTextarea');
            if (!textarea || !window.CandyCodeEditor) {
                return;
            }
            var wrap = document.getElementById('editorCmWrap');
            var height = Math.max(320, Math.floor((window.innerHeight || 720) * 0.52));
            if (wrap) {
                wrap.style.minHeight = height + 'px';
            }
            var mode = CandyCodeEditor.detectMode(filename || editorCurrentName);
            if (!editorCm) {
                editorCm = CandyCodeEditor.create(textarea, {
                    mode: mode,
                    height: height
                });
            } else {
                editorCm.setOption('mode', mode);
                editorCm.setSize('100%', height);
            }
            CandyCodeEditor.refresh(editorCm);
            if (window.CandyCodeEditor && editorCm && typeof editorCm.on === 'function') {
                editorCm.on('change', schedulePreviewRefresh);
            } else if (textarea) {
                textarea.addEventListener('input', schedulePreviewRefresh);
            }
        }

        function openEditorModal(title, path, filename) {
            var backdrop = document.getElementById('editorModalBackdrop');
            var titleEl = document.getElementById('editorModalTitle');
            var pathEl = document.getElementById('editorModalPath');
            editorCurrentName = filename || title || '';
            if (titleEl) titleEl.textContent = title || '编辑';
            if (pathEl) pathEl.textContent = path ? '/' + path : '/';
            if (backdrop) backdrop.hidden = false;
            document.body.classList.add('blog-admin-modal-open');
            ensureCodeMirror().then(function () {
                initCodeMirror(editorCurrentName);
            }).catch(function () {
                toast('代码编辑器加载失败，已回退为纯文本模式', 0);
            });
        }

        var previewEnabled = false;
        var previewTimer = null;

        function initPreview() {
            var toggle = document.getElementById('editorPreviewToggle');
            var pane = document.getElementById('editorPreviewPane');
            if (!toggle || !pane) return;

            toggle.addEventListener('click', function () {
                previewEnabled = !previewEnabled;
                toggle.classList.toggle('is-active', previewEnabled);
                toggle.setAttribute('aria-pressed', previewEnabled ? 'true' : 'false');
                pane.hidden = !previewEnabled;
                var wrap = document.getElementById('editorCmWrap');
                if (wrap) wrap.classList.toggle('is-preview', previewEnabled);
                if (previewEnabled) refreshPreview();
            });

            // 编辑器内容变化时防抖刷新预览
            var ta = document.getElementById('editorTextarea');
            if (ta && window.CandyCodeEditor && editorCm) {
                editorCm.on('change', schedulePreviewRefresh);
            } else if (ta) {
                ta.addEventListener('input', schedulePreviewRefresh);
            }
        }

        function schedulePreviewRefresh() {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(refreshPreview, 400);
        }

        function refreshPreview() {
            if (!previewEnabled) return;
            var content = editorCm ? editorCm.getValue() : getEditorTextareaValue();
            var pane = document.getElementById('editorPreviewPane');
            var box = document.getElementById('editorPreviewContent');
            if (!pane || !box) return;
            box.innerHTML = '<div class="editor-preview-loading">渲染中…</div>';
            var base = (window.BLOG_ADMIN && window.BLOG_ADMIN.baseUrl) || '';
            fetch(base + '/editor/preview.json', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ content: content })
            }).then(function (r) { return r.json(); })
              .then(function (res) {
                  if (res && res.code === 0 && res.data) {
                      box.innerHTML = res.data.html || '';
                  } else {
                      box.innerHTML = '<div class="editor-preview-error">渲染失败：' + ((res && res.message) || '未知错误') + '</div>';
                  }
              })
              .catch(function () {
                  box.innerHTML = '<div class="editor-preview-error">预览请求失败</div>';
              });
        }

        function closeEditorModal() {
            var backdrop = document.getElementById('editorModalBackdrop');
            if (backdrop) backdrop.hidden = true;
            document.body.classList.remove('blog-admin-modal-open');
        }

        function openDir(path) {
            currentPath = path || '';
            loadList();
        }

        function openFile(path) {
            api('editor/read.json?path=' + encodeURIComponent(path)).then(function (res) {
                if (res.code !== 0) {
                    toast(res.message, 2);
                    return;
                }
                var content = res.data.content || '';
                document.getElementById('editorCurrentPath').value = res.data.path || '';
                var textarea = document.getElementById('editorTextarea');
                if (textarea) textarea.value = content;
                if (editorCm) {
                    editorCm.setValue(content);
                }
                openEditorModal(res.data.name || '编辑', res.data.path || '', res.data.name || '');
            });
        }

        function renderBreadcrumb() {
            if (!breadcrumbEl) return;
            var parts = currentPath ? currentPath.split('/') : [];
            var html = '<button type="button" class="blog-editor-crumb" data-open-dir="">根目录</button>';
            var acc = '';
            parts.forEach(function (part) {
                acc = acc ? acc + '/' + part : part;
                html += '<span class="blog-editor-crumb-sep">/</span>';
                html += '<button type="button" class="blog-editor-crumb" data-open-dir="' + escAttr(acc) + '">' + escHtml(part) + '</button>';
            });
            breadcrumbEl.innerHTML = html;
        }

        function updateMkdirButton() {
            var btn = document.getElementById('editorMkdirBtn');
            if (!btn) return;
            var atRoot = !currentPath;
            btn.disabled = !atRoot;
            btn.classList.toggle('layui-btn-disabled', !atRoot);
            btn.title = atRoot ? '' : '仅可在根目录新建分类目录（不支持嵌套子目录）';
        }

        function loadList() {
            var keyword = (document.getElementById('editorKeyword') || {}).value || '';
            editorBody.innerHTML = '<div class="blog-editor-empty">加载中…</div>';
            api('editor/list.json?path=' + encodeURIComponent(currentPath) + '&keyword=' + encodeURIComponent(keyword))
                .then(function (res) {
                    if (res.code !== 0) {
                        editorBody.innerHTML = '<div class="blog-editor-empty blog-editor-empty--error">' + escHtml(res.message || '加载失败') + '</div>';
                        toast(res.message || '加载失败', 2);
                        return;
                    }
                    renderBreadcrumb();
                    updateMkdirButton();
                    editorBody.innerHTML = '';
                    var rows = res.data || [];
                    if (!rows.length) {
                        var emptyMsg = keyword
                            ? '没有匹配「' + keyword + '」的文件'
                            : '当前目录暂无文件';
                        editorBody.innerHTML = '<div class="blog-editor-empty">' + escHtml(emptyMsg) + '</div>';
                        return;
                    }
                    rows.forEach(function (row) {
                        var isDir = !!row.is_dir;
                        var rowEl = document.createElement('div');
                        rowEl.className = 'blog-editor-row' + (isDir ? ' is-dir' : '');
                        var iconClass = isDir ? 'blog-editor-icon--dir' : (row.editable ? 'blog-editor-icon--file' : 'blog-editor-icon--readonly');
                        var iconChar = isDir ? '▦' : (row.editable ? '◧' : '◇');
                        var nameHtml;
                        if (isDir) {
                            nameHtml = '<button type="button" class="blog-editor-name blog-editor-name--dir" data-open-dir="' + escAttr(row.path) + '" title="打开目录">' + escHtml(row.name) + '</button>';
                        } else if (row.editable) {
                            nameHtml = '<button type="button" class="blog-editor-name blog-editor-name--file" data-edit="' + escAttr(row.path) + '" title="编辑文件">' + escHtml(row.name) + '</button>';
                        } else {
                            nameHtml = '<span class="blog-editor-name blog-editor-name--readonly">' + escHtml(row.name) + '</span>';
                        }
                        var ops = buildRowOps(row);
                        rowEl.innerHTML =
                            '<div class="blog-editor-name-wrap"><span class="blog-editor-icon ' + iconClass + '">' + iconChar + '</span>' + nameHtml + '</div>' +
                            '<div class="blog-editor-type' + (isDir ? ' blog-editor-type--dir' : '') + '">' + (isDir ? '目录' : '文件') + '</div>' +
                            '<div class="blog-editor-size">' + escHtml(row.size_text || '-') + '</div>' +
                            '<div class="blog-editor-mtime">' + escHtml(row.mtime || '') + '</div>' +
                            '<div class="blog-editor-op">' + ops + '</div>';
                        editorBody.appendChild(rowEl);
                    });
                }).catch(function (err) {
                    var msg = (err && err.message) ? err.message : '列表加载失败';
                    editorBody.innerHTML = '<div class="blog-editor-empty blog-editor-empty--error">' + escHtml(msg) + '</div>';
                    toast(msg, 2);
                });
        }

        initPreview();
        document.getElementById('editorRefreshBtn').addEventListener('click', loadList);
        document.getElementById('editorUpBtn').addEventListener('click', function () {
            if (!currentPath) return;
            var parts = currentPath.split('/');
            parts.pop();
            currentPath = parts.join('/');
            loadList();
        });
        document.getElementById('editorKeyword').addEventListener('input', function () {
            clearTimeout(window.__blogEditorSearchTimer);
            window.__blogEditorSearchTimer = setTimeout(loadList, 250);
        });

        if (breadcrumbEl) {
            breadcrumbEl.addEventListener('click', function (e) {
                var crumb = e.target.closest('[data-open-dir]');
                if (!crumb) return;
                openDir(crumb.getAttribute('data-open-dir') || '');
            });
        }

        editorBody.addEventListener('click', function (e) {
            var dirBtn = e.target.closest('[data-open-dir]');
            if (dirBtn) {
                openDir(dirBtn.getAttribute('data-open-dir') || '');
                return;
            }
            var editBtn = e.target.closest('[data-edit]');
            if (editBtn) {
                openFile(editBtn.getAttribute('data-edit') || '');
                return;
            }
            var rnBtn = e.target.closest('[data-rename]');
            if (rnBtn) {
                handleRename(rnBtn.getAttribute('data-rename') || '', rnBtn.textContent.trim());
                return;
            }
            var rmBtn = e.target.closest('[data-remove]');
            if (rmBtn) {
                var rm = rmBtn.getAttribute('data-remove') || '';
                if (!rm) return;
                confirmBox('删除后无法恢复，确定要删除该条目吗？', {
                    title: '删除确认',
                    btn: ['删除', '取消'],
                    danger: true
                }).then(function (ok) {
                    if (!ok) return;
                    api('editor/remove.json', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'path=' + encodeURIComponent(rm)
                    }).then(function (res) {
                        toast(res.message, res.code === 0 ? 1 : 2);
                        if (res.code === 0) loadList();
                    });
                });
            }
        });

        var saveBtn = document.getElementById('editorSaveBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                // 防重复提交：请求期间禁用按钮 + loading 态
                if (saveBtn.disabled) return;
                saveBtn.disabled = true;
                var origHtml = saveBtn.innerHTML;
                saveBtn.innerHTML = '保存中…';
                var path = document.getElementById('editorCurrentPath').value;
                var content = getEditorContent();
                api('editor/save.json', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        path: path,
                        content_encoding: 'base64',
                        content: btoa(unescape(encodeURIComponent(content)))
                    })
                }).then(function (res) {
                    toast(res.message, res.code === 0 ? 1 : 2);
                }).catch(function (err) {
                    toast((err && err.message) || '保存失败，请重试', 2);
                }).finally(function () {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                });
            });
        }

        document.getElementById('editorCloseBtn').addEventListener('click', closeEditorModal);
        var cancelBtn = document.getElementById('editorCancelBtn');
        if (cancelBtn) cancelBtn.addEventListener('click', closeEditorModal);
        var modalBackdrop = document.getElementById('editorModalBackdrop');
        if (modalBackdrop) {
            modalBackdrop.addEventListener('click', function (e) {
                if (e.target === modalBackdrop) closeEditorModal();
            });
        }

        var mkdirBtn = document.getElementById('editorMkdirBtn');
        if (mkdirBtn) {
            mkdirBtn.addEventListener('click', function () {
                if (currentPath) {
                    toast('仅可在根目录新建分类目录，不支持嵌套子目录', 0);
                    return;
                }
                mkdirDialog().then(function (name) {
                    if (!name) return;
                    api('editor/mkdir.json', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'path=' + encodeURIComponent(currentPath) + '&name=' + encodeURIComponent(name)
                    }).then(function (res) {
                        toast(res.message, res.code === 0 ? 1 : 2);
                        if (res.code === 0) loadList();
                    });
                });
            });
        }

        var createBtn = document.getElementById('editorCreateBtn');
        if (createBtn) {
            createBtn.addEventListener('click', function () {
                if (!currentPath) {
                    toast('请进入分类目录后再新建文章', 0);
                    return;
                }
                createFileDialog(currentPath).then(function (name) {
                    if (!name) return;
                    api('editor/create.json', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'path=' + encodeURIComponent(currentPath) + '&name=' + encodeURIComponent(name)
                    }).then(function (res) {
                        toast(res.message, res.code === 0 ? 1 : 2);
                        if (res.code === 0) loadList();
                    });
                });
            });
        }

        window.addEventListener('resize', function () {
            if (!editorCm || document.getElementById('editorModalBackdrop').hidden) return;
            var height = Math.max(320, Math.floor((window.innerHeight || 720) * 0.52));
            var wrap = document.getElementById('editorCmWrap');
            if (wrap) wrap.style.minHeight = height + 'px';
            editorCm.setSize('100%', height);
            CandyCodeEditor.refresh(editorCm);
        });

        ensureCodeMirror().catch(function () { /* 首屏预加载，失败时打开编辑再提示 */ });

        loadList();
    }

    // 配置 Tab 切换（左侧导航 + 变量子 Tab）
    function initBlogConfigTabs() {
        var root = document.querySelector('.blog-config-tabs');
        if (!root) return;

        var navItems = root.querySelectorAll('.blog-config-nav-item');
        var sections = root.querySelectorAll('.blog-config-section');

        function switchMainTab(tabId, pushUrl) {
            navItems.forEach(function (el) {
                el.classList.toggle('is-active', el.getAttribute('data-tab') === tabId);
            });
            sections.forEach(function (el) {
                el.classList.toggle('is-active', el.getAttribute('data-tab') === tabId);
            });
            if (pushUrl !== false && window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', tabId);
                window.history.replaceState({}, '', url.toString());
            }
            if (window.layui && layui.form) {
                layui.form.render();
            }
        }

        navItems.forEach(function (el) {
            el.addEventListener('click', function () {
                switchMainTab(el.getAttribute('data-tab') || 'site');
            });
        });

        var activeTab = root.getAttribute('data-active-tab') || '';
        if (activeTab) {
            switchMainTab(activeTab, false);
        }

        root.querySelectorAll('.blog-vars-nav-item').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var scope = btn.getAttribute('data-scope') || '';
                var layout = btn.closest('.blog-vars-layout');
                if (!layout) return;
                layout.querySelectorAll('.blog-vars-nav-item').forEach(function (el) {
                    el.classList.toggle('is-active', el === btn);
                });
                layout.querySelectorAll('.blog-vars-pane').forEach(function (pane) {
                    pane.classList.toggle('is-active', pane.getAttribute('data-scope') === scope);
                });
                if (window.layui && layui.form) {
                    layui.form.render();
                }
            });
        });
    }

    initBlogConfigTabs();
    initBlogAdminNav();

    getLayer().then(function () {
        if (window.layui && layui.form) {
            layui.form.render();
        }
    });

    /**
     * 复制文本到剪贴板
     *
     * @param {string} text
     * @return {Promise<void>}
     */
    function copyText(text) {
        var value = text == null ? '' : String(text);
        if (value === '') {
            return Promise.reject(new Error('内容为空'));
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(value);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = value;
            ta.setAttribute('readonly', 'readonly');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject(new Error('复制失败'));
                }
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(ta);
            }
        });
    }

    /**
     * 小屏顶栏折叠导航
     */
    function initBlogAdminNav() {
        var header = document.getElementById('blogAdminHeader');
        var btn = document.getElementById('blogAdminNavBtn');
        var nav = document.getElementById('blogAdminNav');
        if (!header || !btn || !nav) {
            return;
        }

        function setOpen(open) {
            header.classList.toggle('is-nav-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', open ? '关闭导航菜单' : '打开导航菜单');
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(!header.classList.contains('is-nav-open'));
        });

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setOpen(false);
            });
        });

        document.addEventListener('click', function (e) {
            if (!header.classList.contains('is-nav-open')) {
                return;
            }
            if (!header.contains(e.target)) {
                setOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) {
                setOpen(false);
            }
        });
    }

    window.__blogAdminCore = {
        api: api,
        toast: toast,
        confirmBox: confirmBox,
        formDialog: formDialog,
        getLayer: getLayer,
        copyText: copyText,
        cfg: cfg,
        resolveAdminBase: resolveAdminBase,
        isMobileViewport: isMobileViewport,
        mobileLayerConfig: mobileLayerConfig,
        tuneMobileLayer: tuneMobileLayer
    };
})();
