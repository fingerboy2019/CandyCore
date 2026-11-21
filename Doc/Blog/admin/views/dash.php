<?php
/***
 * 博客管理概览
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 00:00:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="layui-card blog-dash-card">
    <div class="layui-card-header">管理概览</div>
    <div class="layui-card-body blog-dash-body" id="dashOverview">
        <div class="blog-dash-loading">加载中…</div>
    </div>
</div>

<script type="text/html" id="blogDashStatsTpl">
<div class="blog-dash-stats">
    <div class="blog-dash-stat">
        <div class="blog-dash-stat-icon blog-dash-stat-icon--article">📄</div>
        <div class="blog-dash-stat-meta">
            <div class="blog-dash-stat-num" id="dashArticleTotal">0</div>
            <div class="blog-dash-stat-label">文章总数</div>
        </div>
    </div>
    <div class="blog-dash-stat">
        <div class="blog-dash-stat-icon blog-dash-stat-icon--dir">📁</div>
        <div class="blog-dash-stat-meta">
            <div class="blog-dash-stat-num" id="dashCategoryTotal">0</div>
            <div class="blog-dash-stat-label">分类目录</div>
        </div>
    </div>
    <div class="blog-dash-stat">
        <div class="blog-dash-stat-icon blog-dash-stat-icon--comment">💬</div>
        <div class="blog-dash-stat-meta">
            <div class="blog-dash-stat-num" id="dashCommentTotal">0</div>
            <div class="blog-dash-stat-label">评论总数</div>
        </div>
    </div>
    <div class="blog-dash-stat">
        <div class="blog-dash-stat-icon blog-dash-stat-icon--pending">⏳</div>
        <div class="blog-dash-stat-meta">
            <div class="blog-dash-stat-num" id="dashCommentPending">0</div>
            <div class="blog-dash-stat-label">待审核评论</div>
        </div>
    </div>
</div>
<div class="blog-dash-row">
    <div class="blog-dash-panel">
        <h4 class="blog-dash-panel-title">最近更新</h4>
        <ul class="blog-dash-recent" id="dashRecentFiles"></ul>
    </div>
    <div class="blog-dash-panel">
        <h4 class="blog-dash-panel-title">存储占用</h4>
        <div class="blog-dash-size" id="dashStorageSize">0 B</div>
        <p class="blog-dash-size-hint">博客内容目录总体积</p>
        <div class="blog-dash-words">
            <strong id="dashWordsTotal">0</strong> 字
            <span class="blog-dash-words-sep">·</span>
            篇均 <strong id="dashWordsAvg">0</strong> 字
        </div>
    </div>
</div>
<div class="blog-dash-row">
    <div class="blog-dash-panel">
        <h4 class="blog-dash-panel-title">分类热度</h4>
        <div class="blog-dash-cats" id="dashCategoryStats">
            <div class="blog-dash-empty">暂无数据</div>
        </div>
    </div>
    <div class="blog-dash-panel">
        <h4 class="blog-dash-panel-title">评论趋势（近 14 天）</h4>
        <div class="blog-dash-trend" id="dashCommentTrend">
            <div class="blog-dash-empty">暂无数据</div>
        </div>
    </div>
</div>
</script>
