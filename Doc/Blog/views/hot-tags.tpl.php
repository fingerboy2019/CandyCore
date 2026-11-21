<?php
/***
 * 侧栏 3D 标签云
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$sphereWords = blog_tags();
if ($sphereWords === []) {
    return;
}
$sphereJson = json_encode($sphereWords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="side-widget side-wordsphere" id="blog-wordsphere" data-word-sphere-root>
    <h4 class="side-widget-title">标签云</h4>
    <div class="blog-wordsphere-stage-wrap" aria-label="3D 标签云球">
        <div class="blog-wordsphere-stage"></div>
    </div>
    <script type="application/json" class="wordsphere-data"><?= $sphereJson ?></script>
</div>
