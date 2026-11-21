<?php
/***
 * 文章评论区（嵌套 + 标签 + 表情）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 17:40:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

if (!blog('comments.enabled')) {
    return;
}

$postRel = (string) blog('article.post_rel');
$total = (int) blog('comments.total');
$needCaptcha = (bool) blog('comments.captcha');
$emojis = (array) blog('comments.emojis');
$listHtml = (string) blog('comments.html');
$captchaUrl = rtrim((string) blog('home_url'), '/') . '/?blog_comment=1&action=captcha';
?>
<section class="blog-comments" id="blogComments" data-post="<?= blog_esc($postRel) ?>" data-captcha="<?= $needCaptcha ? '1' : '0' ?>">
    <header class="blog-comments-head">
        <h2 class="blog-comments-title">评论 <span class="blog-comments-count"><?= $total ?></span></h2>
        <p class="blog-comments-hint">支持 <code>:smile:</code> 表情短码与标签；回复可嵌套</p>
    </header>

    <div class="blog-comments-thread" data-comment-thread>
        <?= $listHtml !== '' ? $listHtml : '<p class="blog-comment-empty">还没有评论，来抢沙发吧 😄</p>' ?>
    </div>

    <form class="blog-comment-form" data-comment-form novalidate>
        <input type="hidden" name="post" value="<?= blog_esc($postRel) ?>">
        <input type="hidden" name="parent" value="" data-comment-parent>
        <div class="blog-comment-reply-bar" data-reply-bar hidden>
            <span>回复 <strong data-reply-label></strong></span>
            <button type="button" class="blog-comment-cancel-reply" data-cancel-reply>取消</button>
        </div>
        <div class="blog-comment-form-row">
            <label class="blog-comment-label" for="blogCommentName">昵称</label>
            <input class="blog-comment-input" id="blogCommentName" name="name" type="text" maxlength="32" required placeholder="怎么称呼你" autocomplete="nickname">
        </div>
        <div class="blog-comment-form-row">
            <label class="blog-comment-label" for="blogCommentTags">标签 <span class="blog-comment-optional">可选，逗号分隔</span></label>
            <input class="blog-comment-input" id="blogCommentTags" name="tags" type="text" maxlength="80" placeholder="如：赞,疑问">
        </div>
        <div class="blog-comment-form-row">
            <label class="blog-comment-label" for="blogCommentContent">内容</label>
            <?php if ($emojis !== []) : ?>
            <div class="blog-comment-emojis" data-emoji-bar aria-label="插入表情">
                <?php foreach ($emojis as $emo) :
                    $code = (string) ($emo['code'] ?? '');
                    $glyph = (string) ($emo['glyph'] ?? '');
                    if ($code === '' || $glyph === '') {
                        continue;
                    }
                    ?>
                <button type="button" class="blog-comment-emoji" data-emoji="<?= blog_esc($code) ?>" title="<?= blog_esc($code) ?>"><?= blog_esc($glyph) ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <textarea class="blog-comment-textarea" id="blogCommentContent" name="content" rows="4" maxlength="2000" required placeholder="说点什么… 可用 :smile: :thumbsup: :fire:"></textarea>
        </div>
        <?php if ($needCaptcha) : ?>
        <div class="blog-comment-form-row blog-comment-form-row--captcha">
            <label class="blog-comment-label" for="blogCommentCaptcha">验证码</label>
            <div class="blog-comment-captcha-wrap">
                <input class="blog-comment-input" id="blogCommentCaptcha" name="captcha" type="text" maxlength="8" required autocomplete="off">
                <img class="blog-comment-captcha-img" data-captcha-img src="<?= blog_esc($captchaUrl) ?>" alt="验证码" width="100" height="38" title="点击刷新">
            </div>
        </div>
        <?php endif; ?>
        <div class="blog-comment-form-actions">
            <button type="submit" class="blog-comment-submit" data-comment-submit>发表评论</button>
            <span class="blog-comment-form-msg" data-comment-msg hidden></span>
        </div>
    </form>
</section>
