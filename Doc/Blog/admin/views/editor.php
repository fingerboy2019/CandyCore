<?php
/***
 * 博客 Markdown 编辑器
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:48:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="layui-card blog-editor-card">
    <div class="layui-card-header">文章与目录</div>
    <div class="layui-card-body blog-editor-card-body">
        <div class="blog-editor-toolbar">
            <div class="blog-editor-toolbar-actions">
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="editorRefreshBtn">刷新</button>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="editorUpBtn">上级目录</button>
                <?php if (!empty($writable)) : ?>
                <button type="button" class="layui-btn layui-btn-sm" id="editorMkdirBtn">新建目录</button>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-normal" id="editorCreateBtn">新建文章</button>
                <?php endif; ?>
            </div>
            <div class="blog-editor-search">
                <input type="search" id="editorKeyword" class="layui-input" placeholder="搜索文件名…" autocomplete="off">
            </div>
        </div>
        <nav class="blog-editor-breadcrumb" id="editorBreadcrumb" aria-label="当前路径"></nav>
        <?php if (!empty($editorError)) : ?>
        <div class="blog-form-dialog-tip blog-config-warn-tip"><?= htmlspecialchars((string) $editorError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="blog-editor-list">
            <div class="blog-editor-list-head">
                <span class="col-name">名称</span>
                <span class="col-type">类型</span>
                <span class="col-size">大小</span>
                <span class="col-mtime">修改时间</span>
                <span class="col-op">操作</span>
            </div>
            <div class="blog-editor-list-body" id="editorFileBody"></div>
        </div>
    </div>
</div>

<script type="text/html" id="blogMkdirDialogTpl">
<div class="blog-form-dialog">
    <p class="blog-form-dialog-tip">在根目录下新建<strong>分类目录</strong>，用于归类文章。博客仅支持一级分类，不支持在分类内再建子目录。</p>
    <div class="blog-form-dialog-field">
        <label class="blog-form-dialog-label" for="blogMkdirNameInput">目录名称</label>
        <input type="text" class="layui-input" id="blogMkdirNameInput" placeholder="例如：技术笔记" maxlength="64" autocomplete="off">
        <p class="blog-form-dialog-hint">仅支持字母、数字、中文及常见符号，不可含路径分隔符</p>
    </div>
</div>
</script>

<script type="text/html" id="blogCreateFileDialogTpl">
<div class="blog-form-dialog">
    <p class="blog-form-dialog-tip">在分类目录下新建 Markdown 文章，文件名可不含 <code>.md</code> 后缀。</p>
    <div class="blog-form-dialog-meta">
        <span class="blog-form-dialog-meta-label">保存位置</span>
        <code class="blog-form-dialog-meta-value" id="blogCreateFilePath">/</code>
    </div>
    <div class="blog-form-dialog-field">
        <label class="blog-form-dialog-label" for="blogCreateFileNameInput">文章文件名</label>
        <input type="text" class="layui-input" id="blogCreateFileNameInput" placeholder="例如：Hook钩子系统" maxlength="128" autocomplete="off">
        <p class="blog-form-dialog-hint">创建后可在列表中点击文件名开始编辑</p>
    </div>
</div>
</script>

<div id="editorModalBackdrop" class="blog-admin-modal" hidden>
    <div class="blog-admin-modal-dialog blog-admin-modal-dialog--editor" role="dialog" aria-modal="true" aria-labelledby="editorModalTitle">
        <div class="blog-admin-modal-head">
            <div class="blog-admin-modal-title-wrap">
                <h3 id="editorModalTitle">编辑</h3>
                <p class="blog-admin-modal-path" id="editorModalPath"></p>
            </div>
            <div class="blog-admin-modal-head-actions">
                <button type="button" class="blog-admin-preview-toggle" id="editorPreviewToggle" aria-pressed="false">👁 预览</button>
                <button type="button" class="blog-admin-modal-close" id="editorCloseBtn" aria-label="关闭">&times;</button>
            </div>
        </div>
        <div class="blog-admin-modal-body blog-admin-modal-body--editor">
            <div class="editor-cm-wrap" id="editorCmWrap">
                <textarea id="editorTextarea" spellcheck="false"></textarea>
            </div>
            <div class="editor-preview-pane" id="editorPreviewPane" hidden>
                <div class="editor-preview-content markdown-body" id="editorPreviewContent"></div>
            </div>
            <input type="hidden" id="editorCurrentPath" value="">
        </div>
        <div class="blog-admin-modal-foot">
            <button type="button" class="layui-btn layui-btn-primary" id="editorCancelBtn">取消</button>
            <?php if (!empty($writable)) : ?>
            <button type="button" class="layui-btn layui-btn-normal" id="editorSaveBtn">保存</button>
            <?php endif; ?>
        </div>
    </div>
</div>
