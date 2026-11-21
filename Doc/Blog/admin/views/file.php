<?php
/***
 * 博客后台附件管理页
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 07:28:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="layui-card blog-editor-card blog-file-card" id="blogFilePanel"
     data-storage="<?= htmlspecialchars((string) ($storageType ?? 'local'), ENT_QUOTES) ?>"
     data-path="<?= htmlspecialchars((string) ($currentPath ?? ''), ENT_QUOTES) ?>">
    <div class="layui-card-header">
        附件文件管理
    </div>
    <div class="layui-card-body blog-editor-card-body">
        <div class="blog-editor-toolbar blog-file-toolbar">
            <div class="blog-editor-toolbar-actions blog-file-toolbar-actions">
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="fileBackBtn">上级目录</button>
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="fileRefreshBtn">刷新</button>
                <div class="blog-file-ops-drop" id="fileOpsDrop">
                    <button type="button" class="layui-btn layui-btn-sm layui-btn-normal" id="fileOpsBtn">操作 <i class="layui-icon layui-icon-down"></i></button>
                    <div class="blog-file-ops-panel" id="fileOpsPanel" hidden>
                        <button type="button" class="blog-file-ops-item" data-action="upload">上传文件</button>
                        <button type="button" class="blog-file-ops-item" data-action="download">下载选中</button>
                        <button type="button" class="blog-file-ops-item" data-action="copy-link">获取链接</button>
                        <button type="button" class="blog-file-ops-item" data-action="copy-ref">获取相对地址</button>
                        <button type="button" class="blog-file-ops-item" data-action="mkdir">新建文件夹</button>
                        <button type="button" class="blog-file-ops-item" data-action="paste" id="filePasteBtn" hidden>粘贴</button>
                        <hr class="blog-file-ops-divider">
                        <button type="button" class="blog-file-ops-item blog-file-ops-item--danger" data-action="remove">删除选中</button>
                        <button type="button" class="blog-file-ops-item" data-action="compress">压缩为 ZIP</button>
                        <button type="button" class="blog-file-ops-item" data-action="extract">解压 ZIP</button>
                    </div>
                </div>
            </div>
            <form class="blog-file-filter" id="fileFilterForm" autocomplete="off" onsubmit="return false;">
                <select name="type" id="fileTypeFilter" class="layui-input">
                    <option value="all">全部类型</option>
                    <option value="image">图片</option>
                    <option value="video">视频</option>
                    <option value="audio">音频</option>
                    <option value="doc">文档</option>
                    <option value="archive">压缩包</option>
                </select>
                <input type="search" name="keyword" id="fileKeyword" class="layui-input" placeholder="搜索文件名…">
                <button type="submit" class="layui-btn layui-btn-sm layui-btn-primary">搜索</button>
            </form>
        </div>
        <nav class="blog-editor-breadcrumb" id="fileBreadcrumb" aria-label="当前路径"></nav>
        <div class="blog-editor-list blog-file-list">
            <div class="blog-editor-list-head blog-file-list-head">
                <span class="col-check"><input type="checkbox" id="fileCheckAll" title="全选"></span>
                <span class="col-name">名称</span>
                <span class="col-owner">权限/所有者</span>
                <span class="col-size">大小</span>
                <span class="col-mtime">修改时间</span>
                <span class="col-op">操作</span>
            </div>
            <div class="blog-editor-list-body" id="blogFileBody"></div>
        </div>
        <div class="blog-file-pager" id="filePager" hidden>
            <span class="blog-file-pager-info" id="filePagerInfo"></span>
            <div class="blog-file-pager-actions">
                <button type="button" class="blog-file-pager-btn blog-file-pager-btn--prev" id="filePrevBtn">
                    <span class="blog-file-pager-btn__icon" aria-hidden="true">‹</span>上一页
                </button>
                <button type="button" class="blog-file-pager-btn blog-file-pager-btn--next" id="fileNextBtn">
                    下一页<span class="blog-file-pager-btn__icon" aria-hidden="true">›</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script type="text/html" id="blogFileMkdirTpl">
<div class="blog-form-dialog">
    <p class="blog-form-dialog-tip">在当前浏览目录下新建文件夹，用于归类图片与附件。</p>
    <div class="blog-form-dialog-field">
        <label class="blog-form-dialog-label" for="blogFileMkdirInput">文件夹名称</label>
        <input type="text" class="layui-input" id="blogFileMkdirInput" placeholder="例如：images" maxlength="64" autocomplete="off">
    </div>
</div>
</script>

<script type="text/html" id="blogFileCompressTpl">
<div class="blog-form-dialog">
    <p class="blog-form-dialog-tip">将选中的文件或目录压缩为 ZIP，保存到当前目录。</p>
    <div class="blog-form-dialog-field">
        <label class="blog-form-dialog-label" for="blogFileZipNameInput">压缩包名称</label>
        <input type="text" class="layui-input" id="blogFileZipNameInput" placeholder="archive.zip" maxlength="128" autocomplete="off">
    </div>
</div>
</script>

<script type="text/html" id="blogFileUploadTpl">
<div class="blog-file-upload">
    <div class="blog-file-upload-zone" id="blogFileUploadZone">
        <p class="blog-file-upload-title">拖拽文件到此处，或点击选择</p>
        <p class="blog-file-upload-hint">支持多文件上传，将保存到当前目录</p>
        <button type="button" class="layui-btn layui-btn-sm layui-btn-normal" id="blogFileUploadPick">选择文件</button>
        <input type="file" id="blogFileUploadInput" multiple hidden>
    </div>
    <ul class="blog-file-upload-queue" id="blogFileUploadQueue"></ul>
</div>
</script>
