<?php
/***
 * 博客系统配置页
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 19:05:39 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$config = is_array($config ?? null) ? $config : [];
$tabs = is_array($tabs ?? null) ? $tabs : [];
$nav = (array) ($config['nav'] ?? []);
$profile = (array) ($config['profile'] ?? []);
$links = (array) ($config['friend_links'] ?? []);
$limits = (array) ($config['feed_limits'] ?? []);
$vars = (array) ($config['vars'] ?? []);
$auth = (array) ($config['auth'] ?? []);
$commentSettings = \Candy\Extend\Doc\Blog\Comment::settings($config);
$spamFilter = is_array($spamFilter ?? null) ? $spamFilter : [];
$feedStatic = is_array($feedStatic ?? null) ? $feedStatic : [];
$varScopes = ['global' => '全站', 'index' => '首页', 'post' => '文章', 'list' => '列表'];
$activeTab = 'site';
$queryTab = trim((string) ($_GET['tab'] ?? ''));
if ($queryTab !== '' && isset($tabs[$queryTab])) {
    $activeTab = $queryTab;
}
$fmtMtime = static function (int $ts): string {
    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '尚未生成';
};
?>
<div class="layui-card blog-config-card">
    <div class="layui-card-header">博客系统配置</div>
    <div class="layui-card-body blog-config-card-body">
        <div class="blog-config-layout blog-config-tabs" data-active-tab="<?= htmlspecialchars($activeTab, ENT_QUOTES) ?>">
            <aside class="blog-config-sidebar">
                <nav class="blog-config-nav blog-scroll-x" aria-label="配置分类">
                    <button type="button" class="blog-config-nav-item" data-tab="backup">备份还原</button>
                    <?php foreach ($tabs as $id => $label) : ?>
                    <button type="button"
                            class="blog-config-nav-item<?= $id === $activeTab ? ' is-active' : '' ?>"
                            data-tab="<?= htmlspecialchars((string) $id, ENT_QUOTES) ?>">
                        <?= htmlspecialchars((string) $label, ENT_QUOTES) ?>
                    </button>
                    <?php endforeach; ?>
                </nav>
            </aside>
            <div class="blog-config-main">
                <section class="blog-config-section<?= $activeTab === 'site' ? ' is-active' : '' ?>" data-tab="site">
                    <header class="blog-config-section-head">
                        <h3>站点信息</h3>
                        <p>站点名称、描述与全局展示文案</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="site">
                        <div class="layui-form-item">
                            <label class="layui-form-label">站点名称</label>
                            <div class="layui-input-block"><input name="site_name" class="layui-input" value="<?= htmlspecialchars((string) ($config['site_name'] ?? ''), ENT_QUOTES) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">站点描述</label>
                            <div class="layui-input-block"><textarea name="site_description" class="layui-textarea" rows="3"><?= htmlspecialchars((string) ($config['site_description'] ?? ''), ENT_QUOTES) ?></textarea></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">默认作者</label>
                            <div class="layui-input-block"><input name="default_author" class="layui-input" value="<?= htmlspecialchars((string) ($config['default_author'] ?? ''), ENT_QUOTES) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">侧栏语录</label>
                            <div class="layui-input-block"><input name="quote" class="layui-input" value="<?= htmlspecialchars((string) ($config['quote'] ?? ''), ENT_QUOTES) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">搜索条数</label>
                            <div class="layui-input-block"><input name="search_limit" type="number" class="layui-input blog-input-number" value="<?= (int) ($config['search_limit'] ?? 20) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">绑定域名</label>
                            <div class="layui-input-block">
                                <input name="blog_domain" class="layui-input" placeholder="留空则使用 /Blog/ 路径访问，如 blog.example.com" value="<?= htmlspecialchars((string) ($config['blog_domain'] ?? ''), ENT_QUOTES) ?>">
                                <p class="blog-config-field-hint">独立博客域名。留空时保持现状（主站 <code>/Blog/</code>）；填写后须将 DNS / Nginx 指向本站，访问该域名根路径即为博客首页，后台为 <code>/<?= htmlspecialchars((string) ($config['content_dir'] ?? '{content_dir}'), ENT_QUOTES) ?>/admin</code>。</p>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">站点 URL</label>
                            <div class="layui-input-block">
                                <input name="site_url" class="layui-input" placeholder="如 https://blog.example.com ，留空则按绑定域名或当前请求自动推导" value="<?= htmlspecialchars((string) ($config['site_url'] ?? ''), ENT_QUOTES) ?>">
                                <p class="blog-config-field-hint">写入 RSS / Sitemap 的绝对链接前缀。留空时生成会兜底：优先绑定域名，否则取当前访问主机。</p>
                            </div>
                        </div>
                        <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存</button></div>
                    </form>
                </section>

                <section class="blog-config-section<?= $activeTab === 'nav' ? ' is-active' : '' ?>" data-tab="nav">
                    <header class="blog-config-section-head">
                        <h3>顶栏导航</h3>
                        <p>首页入口、分类数量与自定义链接</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="nav">
                        <div class="layui-form-item">
                            <div class="layui-input-block blog-checkbox-group">
                                <input type="checkbox" name="show_home" value="1" title="显示首页" lay-skin="primary" <?= !empty($nav['show_home']) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">首页文案</label>
                            <div class="layui-input-block"><input name="home_label" class="layui-input" value="<?= htmlspecialchars((string) ($nav['home_label'] ?? '首页'), ENT_QUOTES) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">分类上限</label>
                            <div class="layui-input-block"><input name="max_categories" type="number" class="layui-input blog-input-number" value="<?= (int) ($nav['max_categories'] ?? 4) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">更多文案</label>
                            <div class="layui-input-block"><input name="more_label" class="layui-input" value="<?= htmlspecialchars((string) ($nav['more_label'] ?? '更多'), ENT_QUOTES) ?>"></div>
                        </div>
                        <div class="blog-kv-block">
                            <div class="blog-kv-block-title">自定义导航项</div>
                            <div class="kv-table kv-table--nav" data-kv-type="nav">
                                <div class="kv-head">
                                    <span>标签</span><span>链接</span><span class="kv-col-check">外链</span><span class="kv-col-op"></span>
                                </div>
                                <div class="kv-rows" data-prefix="nav_items" data-fields="label,url,external">
                                    <?php foreach ((array) ($nav['items'] ?? []) as $i => $item) : ?>
                                    <div class="kv-row">
                                        <div class="kv-field"><input name="nav_items[<?= $i ?>][label]" class="layui-input" placeholder="标签" value="<?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES) ?>"></div>
                                        <div class="kv-field"><input name="nav_items[<?= $i ?>][url]" class="layui-input" placeholder="链接" value="<?= htmlspecialchars((string) ($item['url'] ?? ''), ENT_QUOTES) ?>"></div>
                                        <div class="kv-field kv-field--check"><input type="checkbox" name="nav_items[<?= $i ?>][external]" value="1" title="外链" lay-skin="primary" <?= !empty($item['external']) ? 'checked' : '' ?>></div>
                                        <div class="kv-field kv-field--op"><button type="button" class="layui-btn layui-btn-xs layui-btn-danger kv-remove">删除</button></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary kv-add" data-target="nav_items" data-fields="label,url,external" data-kv-type="nav">添加一项</button>
                        </div>
                        <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存</button></div>
                    </form>
                </section>

                <section class="blog-config-section<?= $activeTab === 'profile' ? ' is-active' : '' ?>" data-tab="profile">
                    <header class="blog-config-section-head">
                        <h3>博主资料</h3>
                        <p>侧栏个人卡片与社交展示</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="profile">
                        <?php foreach (['name' => '昵称', 'bio' => '简介', 'avatar' => '头像 URL', 'more_url' => '更多链接', 'wechat_qr' => '微信二维码', 'qq_qr' => 'QQ 二维码'] as $key => $label) : ?>
                        <div class="layui-form-item">
                            <label class="layui-form-label"><?= $label ?></label>
                            <div class="layui-input-block"><input name="<?= $key ?>" class="layui-input" value="<?= htmlspecialchars((string) ($profile[$key] ?? ''), ENT_QUOTES) ?>"></div>
                        </div>
                        <?php endforeach; ?>
                        <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存</button></div>
                    </form>
                </section>

                <section class="blog-config-section<?= $activeTab === 'links' ? ' is-active' : '' ?>" data-tab="links">
                    <header class="blog-config-section-head">
                        <h3>友情链接</h3>
                        <p>侧栏或页脚展示的友链列表</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="links">
                        <div class="layui-form-item">
                            <label class="layui-form-label">区块标题</label>
                            <div class="layui-input-block"><input name="title" class="layui-input" value="<?= htmlspecialchars((string) ($links['title'] ?? ''), ENT_QUOTES) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <div class="layui-input-block blog-checkbox-group">
                                <input type="checkbox" name="sidebar" value="1" title="侧栏展示" lay-skin="primary" <?= !empty($links['sidebar']) ? 'checked' : '' ?>>
                                <input type="checkbox" name="footer" value="1" title="页脚展示" lay-skin="primary" <?= !empty($links['footer']) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="blog-kv-block">
                            <div class="blog-kv-block-title">友链列表</div>
                            <div class="kv-table kv-table--links" data-kv-type="links">
                                <div class="kv-head">
                                    <span>名称</span><span>URL</span><span>描述</span><span class="kv-col-check">外链</span><span class="kv-col-op"></span>
                                </div>
                                <div class="kv-rows" data-prefix="items" data-fields="name,url,description,external">
                                    <?php foreach ((array) ($links['items'] ?? []) as $i => $item) : ?>
                                    <div class="kv-row">
                                        <div class="kv-field"><input name="items[<?= $i ?>][name]" class="layui-input" placeholder="名称" value="<?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES) ?>"></div>
                                        <div class="kv-field"><input name="items[<?= $i ?>][url]" class="layui-input" placeholder="URL" value="<?= htmlspecialchars((string) ($item['url'] ?? ''), ENT_QUOTES) ?>"></div>
                                        <div class="kv-field"><input name="items[<?= $i ?>][description]" class="layui-input" placeholder="描述" value="<?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES) ?>"></div>
                                        <div class="kv-field kv-field--check"><input type="checkbox" name="items[<?= $i ?>][external]" value="1" title="外链" lay-skin="primary" <?= !empty($item['external']) ? 'checked' : '' ?>></div>
                                        <div class="kv-field kv-field--op"><button type="button" class="layui-btn layui-btn-xs layui-btn-danger kv-remove">删除</button></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary kv-add" data-target="items" data-fields="name,url,description,external" data-kv-type="links">添加友链</button>
                        </div>
                        <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存</button></div>
                    </form>
                </section>

                <section class="blog-config-section<?= $activeTab === 'feed' ? ' is-active' : '' ?>" data-tab="feed">
                    <header class="blog-config-section-head">
                        <h3>列表数量</h3>
                        <p>各区块加载的文章条数上限</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="feed">
                        <div class="blog-feed-grid">
                            <?php foreach (['recent' => '最新', 'featured' => '推荐', 'sidebar' => '侧栏推荐', 'carousel' => '轮播', 'category_notes' => '分类笔记', 'tag_cloud' => '标签云', 'home_skip' => '首页跳过', 'home_stream' => '首页加载', 'list_page' => '分类分页', 'rss_items' => 'RSS 条数', 'sitemap_items' => '地图条数'] as $key => $label) : ?>
                            <div class="layui-form-item blog-feed-grid-item">
                                <label class="layui-form-label"><?= $label ?></label>
                                <div class="layui-input-block"><input name="<?= $key ?>" type="number" class="layui-input blog-input-number" value="<?= (int) ($limits[$key] ?? ($key === 'sitemap_items' ? 1000 : ($key === 'rss_items' ? 20 : 0))) ?>"></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存</button></div>
                    </form>
                </section>

                <section class="blog-config-section<?= $activeTab === 'comments' ? ' is-active' : '' ?>" data-tab="comments">
                    <header class="blog-config-section-head">
                        <h3>评论</h3>
                        <p>评论开关、审核策略与垃圾评论自动拦截</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="comments">
                        <div class="layui-form-item">
                            <label class="layui-form-label">评论功能</label>
                            <div class="layui-input-block blog-config-switch-row">
                                <label class="layui-form-label-slim">启用评论</label>
                                <input type="checkbox" name="enabled" lay-skin="switch" <?= !empty($commentSettings['enabled']) ? 'checked' : '' ?>>
                                <label class="layui-form-label-slim">需审核</label>
                                <input type="checkbox" name="require_approve" lay-skin="switch" <?= !empty($commentSettings['require_approve']) ? 'checked' : '' ?>>
                                <label class="layui-form-label-slim">验证码</label>
                                <input type="checkbox" name="captcha" lay-skin="switch" <?= !empty($commentSettings['captcha']) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">最大回复层级</label>
                            <div class="layui-input-block"><input name="max_depth" type="number" class="layui-input blog-input-number" min="1" max="6" value="<?= (int) ($commentSettings['max_depth'] ?? 4) ?>"></div>
                        </div>
                        <div class="blog-config-divider"></div>
                        <div class="blog-config-section-subhead">
                            <h4>垃圾评论自动拦截</h4>
                            <p>规则存于正文目录 <code>.comments/spam-filter.json</code>，命中直接标记 spam，不进入待审队列</p>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">启用拦截</label>
                            <div class="layui-input-block blog-config-switch-row">
                                <label class="layui-form-label-slim">启用</label>
                                <input type="checkbox" id="blogSpamEnabled" lay-skin="switch" <?= !empty($spamFilter['enabled']) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">链接数量阈值</label>
                            <div class="layui-input-block"><input id="blogSpamMaxLinks" type="number" class="layui-input blog-input-number" min="0" max="20" value="<?= (int) ($spamFilter['max_links'] ?? 3) ?>" placeholder="内容含 N 个以上链接即拦截，0 关闭"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">黑名单关键词</label>
                            <div class="layui-input-block">
                                <textarea id="blogSpamKeywords" class="layui-textarea" rows="5" placeholder="每行一个关键词，如：&#10;加微信&#10;代开发票"><?= htmlspecialchars(implode("
", (array) ($spamFilter['keywords'] ?? [])), ENT_QUOTES) ?></textarea>
                                <p class="blog-config-field-hint">昵称或内容包含任一关键词即拦截，每行一个，逗号分隔亦可</p>
                            </div>
                        </div>
                        <div class="blog-config-form-actions">
                            <button type="button" class="layui-btn layui-btn-normal" id="blogSpamFilterSave">保存拦截规则</button>
                        </div>
                    </form>
                </section>

                <section class="blog-config-section<?= $activeTab === 'syndication' ? ' is-active' : '' ?>" data-tab="syndication">
                    <header class="blog-config-section-head">
                        <h3>订阅与地图</h3>
                        <p>RSS / Sitemap 条数上限，以及静态文件一键生成（空配置自动兜底）</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="syndication">
                        <div class="layui-form-item">
                            <label class="layui-form-label">站点 URL</label>
                            <div class="layui-input-block">
                                <input name="site_url" class="layui-input" placeholder="https://example.com" value="<?= htmlspecialchars((string) ($config['site_url'] ?? ''), ENT_QUOTES) ?>">
                                <p class="blog-config-field-hint">与「站点信息」中的站点 URL 同源；留空生成时自动兜底。</p>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">RSS 最高条数</label>
                            <div class="layui-input-block">
                                <input name="rss_items" type="number" min="1" max="200" class="layui-input blog-input-number" value="<?= (int) ($limits['rss_items'] ?? 20) ?>">
                                <p class="blog-config-field-hint">默认 20，范围 1–200；填 0 或留空保存时回落默认值。</p>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">地图最高条数</label>
                            <div class="layui-input-block">
                                <input name="sitemap_items" type="number" min="1" max="5000" class="layui-input blog-input-number" value="<?= (int) ($limits['sitemap_items'] ?? 1000) ?>">
                                <p class="blog-config-field-hint">默认 1000，范围 1–5000；仅统计已发布文章（不含草稿）。</p>
                            </div>
                        </div>
                        <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存</button></div>
                    </form>

                    <div class="blog-syndication-actions">
                        <div class="blog-syndication-status">
                            <p>动态地址：
                                <a href="<?= htmlspecialchars((string) ($feedStatic['rss_live'] ?? '/Blog/rss.xml'), ENT_QUOTES) ?>" target="_blank" rel="noopener">RSS</a>
                                ·
                                <a href="<?= htmlspecialchars((string) ($feedStatic['sitemap_live'] ?? '/Blog/sitemap.xml'), ENT_QUOTES) ?>" target="_blank" rel="noopener">Sitemap</a>
                            </p>
                            <p>静态副本：
                                <code><?= htmlspecialchars((string) ($feedStatic['rss_url'] ?? '/Doc/Blog/Static/feed/rss.xml'), ENT_QUOTES) ?></code>
                                （<?= htmlspecialchars($fmtMtime((int) ($feedStatic['rss_mtime'] ?? 0)), ENT_QUOTES) ?>）
                                ·
                                <code><?= htmlspecialchars((string) ($feedStatic['sitemap_url'] ?? '/Doc/Blog/Static/feed/sitemap.xml'), ENT_QUOTES) ?></code>
                                （<?= htmlspecialchars($fmtMtime((int) ($feedStatic['sitemap_mtime'] ?? 0)), ENT_QUOTES) ?>）
                            </p>
                        </div>
                        <div class="blog-config-form-actions blog-syndication-btns">
                            <button type="button" class="layui-btn layui-btn-primary" data-feed-generate="rss">生成 RSS</button>
                            <button type="button" class="layui-btn layui-btn-primary" data-feed-generate="sitemap">生成站点地图</button>
                            <button type="button" class="layui-btn layui-btn-normal" data-feed-generate="all">全部生成</button>
                        </div>
                    </div>
                </section>

                <section class="blog-config-section<?= $activeTab === 'vars' ? ' is-active' : '' ?>" data-tab="vars">
                    <header class="blog-config-section-head">
                        <h3>模板变量</h3>
                        <p>按页面作用域注入模板，键名仅限字母数字下划线</p>
                    </header>
                    <div class="blog-vars-layout">
                        <aside class="blog-vars-sidebar">
                            <?php $firstScope = true; foreach ($varScopes as $scope => $scopeLabel) : ?>
                            <button type="button"
                                    class="blog-vars-nav-item<?= $firstScope ? ' is-active' : '' ?>"
                                    data-scope="<?= htmlspecialchars($scope, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($scopeLabel, ENT_QUOTES) ?>
                            </button>
                            <?php $firstScope = false; endforeach; ?>
                        </aside>
                        <div class="blog-vars-panels">
                            <?php $firstScope = true; foreach ($varScopes as $scope => $scopeLabel) : ?>
                            <div class="blog-vars-pane<?= $firstScope ? ' is-active' : '' ?>" data-scope="<?= htmlspecialchars($scope, ENT_QUOTES) ?>">
                                <form class="layui-form blog-config-form blog-config-form--styled" data-tab="vars" data-scope="<?= htmlspecialchars($scope, ENT_QUOTES) ?>">
                                    <div class="blog-kv-block">
                                        <div class="blog-kv-block-title"><?= htmlspecialchars($scopeLabel, ENT_QUOTES) ?>变量</div>
                                        <div class="kv-table kv-table--vars" data-kv-type="vars">
                                            <div class="kv-head">
                                                <span>键名</span><span>值</span><span class="kv-col-op"></span>
                                            </div>
                                            <div class="kv-rows kv-rows--vars" data-prefix="<?= $scope ?>" data-fields="key,value">
                                                <?php $scopeVars = (array) ($vars[$scope] ?? []); $vi = 0; foreach ($scopeVars as $k => $v) : ?>
                                                <div class="kv-row">
                                                    <div class="kv-field"><input name="<?= $scope ?>[<?= $vi ?>][key]" class="layui-input" placeholder="键名" value="<?= htmlspecialchars((string) $k, ENT_QUOTES) ?>"></div>
                                                    <div class="kv-field"><input name="<?= $scope ?>[<?= $vi ?>][value]" class="layui-input" placeholder="值" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>"></div>
                                                    <div class="kv-field kv-field--op"><button type="button" class="layui-btn layui-btn-xs layui-btn-danger kv-remove">删除</button></div>
                                                </div>
                                                <?php $vi++; endforeach; ?>
                                            </div>
                                        </div>
                                        <button type="button" class="layui-btn layui-btn-sm layui-btn-primary kv-add" data-target="<?= $scope ?>" data-fields="key,value" data-kv-type="vars">添加变量</button>
                                    </div>
                                    <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存本组</button></div>
                                </form>
                            </div>
                            <?php $firstScope = false; endforeach; ?>
                        </div>
                    </div>
                </section>

                <section class="blog-config-section<?= $activeTab === 'auth' ? ' is-active' : '' ?>" data-tab="auth">
                    <header class="blog-config-section-head">
                        <h3>后台账号</h3>
                        <p>管理后台登录凭据与安全选项</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="auth">
                        <div class="layui-form-item">
                            <label class="layui-form-label">用户名</label>
                            <div class="layui-input-block"><input name="username" class="layui-input" value="<?= htmlspecialchars((string) ($auth['username'] ?? 'admin'), ENT_QUOTES) ?>"></div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">新密码</label>
                            <div class="layui-input-block"><input name="password" type="password" class="layui-input" placeholder="留空则不修改" autocomplete="new-password"></div>
                        </div>
                        <div class="layui-form-item">
                            <div class="layui-input-block blog-checkbox-group">
                                <input type="checkbox" name="captcha" value="1" title="登录启用验证码" lay-skin="primary" <?= ($auth['captcha'] ?? true) !== false ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="blog-config-form-actions"><button type="submit" class="layui-btn layui-btn-normal">保存</button></div>
                    </form>
                </section>

                <section class="blog-config-section<?= $activeTab === 'content_dir' ? ' is-active' : '' ?>" data-tab="content_dir">
                    <header class="blog-config-section-head">
                        <h3>目录修改</h3>
                        <p>修改正文随机目录名（content_dir），用于隐藏管理入口与文章存储路径</p>
                    </header>
                    <form class="layui-form blog-config-form blog-config-form--styled" data-tab="content_dir" id="blogContentDirForm">
                        <div class="blog-form-dialog-tip blog-config-warn-tip">
                            修改后旧地址 <code>/Blog/<?= htmlspecialchars((string) ($config['content_dir'] ?? ''), ENT_QUOTES) ?>/admin</code> 将<strong>立即失效</strong>，系统会自动跳转到新后台。请同步更新书签。
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">当前目录</label>
                            <div class="layui-input-block">
                                <input type="text" class="layui-input" value="<?= htmlspecialchars((string) ($config['content_dir'] ?? ''), ENT_QUOTES) ?>" readonly>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">新目录名</label>
                            <div class="layui-input-block blog-content-dir-input">
                                <input type="text" name="content_dir" id="blogContentDirInput" class="layui-input" maxlength="8" pattern="[a-z0-9]{8}" placeholder="8 位小写字母或数字" autocomplete="off" value="">
                                <button type="button" class="layui-btn layui-btn-primary" id="blogContentDirGenerate">随机生成</button>
                            </div>
                        </div>
                        <p class="blog-form-dialog-hint blog-config-hint-block">仅允许 <code>a-z</code>、<code>0-9</code>，长度 8 位；会同步重命名 <code>Doc/Blog/</code> 下对应文件夹。</p>
                        <div class="blog-config-form-actions">
                            <button type="submit" class="layui-btn layui-btn-danger">确认修改并跳转</button>
                        </div>
                    </form>
                </section>

                <section class="blog-config-section" data-tab="backup">
                    <header class="blog-config-section-head">
                        <h3>配置备份与还原</h3>
                        <p>导出当前配置为 JSON 文件，或从备份文件恢复配置</p>
                    </header>
                    <div class="blog-backup-panel">
                        <div class="blog-backup-item">
                            <div class="blog-backup-item-info">
                                <strong>导出备份</strong>
                                <p>下载当前全部配置（站点、导航、账号、模板变量等）为 JSON 文件</p>
                            </div>
                            <button type="button" class="layui-btn" id="blogConfigBackupBtn">导出配置</button>
                        </div>
                        <div class="blog-backup-item">
                            <div class="blog-backup-item-info">
                                <strong>导入还原</strong>
                                <p>上传之前导出的备份文件恢复配置。注意：会覆盖当前全部配置</p>
                            </div>
                            <div class="blog-backup-restore-wrap">
                                <input type="file" id="blogConfigRestoreInput" accept=".json,application/json" hidden>
                                <button type="button" class="layui-btn layui-btn-normal" id="blogConfigRestorePick">选择备份文件</button>
                                <span class="blog-backup-restore-name" id="blogConfigRestoreName"></span>
                            </div>
                        </div>
                        <div class="blog-snapshot-block">
                            <div class="blog-snapshot-head">
                                <strong>自动快照（改坏可回滚）</strong>
                                <p>每次保存/还原前自动记录当前配置，保留最近 30 份。修改配置后如出问题，可在此一键回退。</p>
                            </div>
                            <div class="blog-snapshot-list" id="blogSnapshotList">
                                <?php
                                $__snapshots = is_array($snapshots ?? null) ? $snapshots : [];
                                if ($__snapshots === []) : ?>
                                <div class="blog-snapshot-empty" id="blogSnapshotEmpty">暂无快照，保存一次配置后自动生成</div>
                                <?php else : ?>
                                <?php foreach ($__snapshots as $__snap) : ?>
                                <div class="blog-snapshot-item">
                                    <span class="blog-snapshot-name"><?= htmlspecialchars((string) ($__snap['name'] ?? ''), ENT_QUOTES) ?></span>
                                    <span class="blog-snapshot-time"><?= htmlspecialchars((string) ($__snap['time'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($__snap['size'] ?? ''), ENT_QUOTES) ?></span>
                                    <button type="button" class="layui-btn layui-btn-xs layui-btn-danger layui-btn-primary blog-snapshot-restore" data-name="<?= htmlspecialchars((string) ($__snap['name'] ?? ''), ENT_QUOTES) ?>">还原</button>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
