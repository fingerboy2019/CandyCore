<?php
/***
 * Candy Markdown解析类
 *
 * 文件说明：文档/博客 Markdown 解析入口，实现委托至 Trait 子模块
 * 优化报告：backend/Doc/Candy/类库文档/Candy/Class/Doc/Markdown优化报告.md
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Engine
{
    use Trait\State;
    use Trait\Placeholder;
    use Trait\Util;
    use Trait\Inline;
    use Trait\Table;
    use Trait\BlockCollector;
    use Trait\Pipeline;
    use Trait\Meta;
    use Trait\Output;

    /** 解析器版本号，参与缓存键，类库升级后自动失效旧缓存 */
    private const PARSER_VERSION = '2.1.1';

    // 添加配置选项
    private static array $config = [
        'basePath' => '',
        'urlBasePath' => '/',
        'pageUrlBase' => '',
        'useCache' => false,
        'enableEmoji' => true,
        'enableChart' => true,
        'enableVideo' => true,
        'enableMath' => true,
        'enableTables' => true,
        'enableTabs' => true,
        'tocDepth' => 3,
        'enableToc' => true,
        'cacheTtl' => 0,
        'escapeHtml' => true,
    ];
    
    // 定义更丰富的 Emoji 映射表
    private static array $emojiMap = [
        ':smile:' => '😄', ':laughing:' => '🤣', ':blush:' => '😊', ':smiley:' => '😃', ':grin:' => '😀', ':wink:' => '😉', ':smirk:' => '😏', ':innocent:' => '😇', ':joy:' => '😂', ':rofl:' => '🤣', ':sad:' => '😞', ':relieved:' => '😌', ':heart_eyes:' => '😍', ':kissing:' => '😗', ':stuck_out_tongue:' => '😛', ':zany_face:' => '🤪', ':raised_eyebrow:' => '🤨', ':neutral_face:' => '😐', ':expressionless:' => '😑', ':grimacing:' => '😬', ':lying_face:' => '🤥', ':clown:' => '🤡', ':nauseated:' => '🤢', ':dizzy_face:' => '😵', ':cowboy:' => '🤠', ':sunglasses:' => '😎', ':nerd:' => '🤓', //笑脸与人物
        ':heart:' => '❤️', ':broken_heart:' => '💔', ':kiss:' => '😘', ':love_letter:' => '💌', ':sparkling_heart:' => '💖', ':blue_heart:' => '💙', ':green_heart:' => '💚', ':yellow_heart:' => '💛', ':purple_heart:' => '💜', ':anger:' => '💢', ':cry:' => '😭', ':sob:' => '😭', ':pensive:' => '😔', ':disappointed:' => '😞', ':angry:' => '😡', ':rage:' => '😠', ':fearful:' => '😨', ':scream:' => '😱', ':cold_sweat:' => '😰', ':black_heart:' => '🖤', ':orange_heart:' => '🧡', ':heart_exclamation:' => '❣️', ':revolving_hearts:' => '💞', ':heartbeat:' => '💓', ':cupid:' => '💘', //情感与心形
        ':thumbsup:' => '👍', ':thumbsdown:' => '👎', ':ok_hand:' => '👌', ':raised_hands:' => '🙌', ':clap:' => '👏', ':wave:' => '👋', ':fist:' => '✊', ':handshake:' => '🤝', ':pray:' => '🙏', ':v:' => '✌️', ':muscle:' => '💪', ':point_left:' => '👈', ':point_right:' => '👉', ':raised_hand:' => '✋', ':open_hands:' => '👐', ':writing_hand:' => '✍️', ':nail_care:' => '💅', ':call_me:' => '🤙', //手势与动作
		':dog:' => '🐶', ':cat:' => '🐱', ':bird:' => '🐦', ':rabbit:' => '🐰', ':lion:' => '🦁', ':tiger:' => '🐯', ':elephant:' => '🐘', ':monkey_face:' => '🐵', ':panda_face:' => '🐼', ':fox:' => '🦊', ':deer:' => '🦌', ':owl:' => '🦉', ':dragon:' => '🐉', ':unicorn:' => '🦄', ':whale:' => '🐋', ':dolphin:' => '🐬', ':turtle:' => '🐢', ':bee:' => '🐝', ':butterfly:' => '🦋', ':snake:' => '🐍', ':spider:' => '🕷️', //动物与自然
		':pizza:' => '🍕', ':hamburger:' => '🍔', ':fries:' => '🍟', ':ice_cream:' => '🍦', ':cake:' => '🍰', ':apple:' => '🍎', ':banana:' => '🍌', ':cherries:' => '🍒', ':watermelon:' => '🍉', ':taco:' => '🌮', ':burrito:' => '🌯', ':ramen:' => '🍜', ':doughnut:' => '🍩', ':coffee:' => '☕', ':tea:' => '🍵', ':sake:' => '🍶', ':beer:' => '🍺', ':wine:' => '🍷', //食物与饮品
		':sun:' => '☀️', ':moon:' => '🌙', ':star:' => '⭐', ':fire:' => '🔥', ':rainbow:' => '🌈', ':umbrella:' => '☔', ':gift:' => '🎁', ':party_popper:' => '🎉', ':confetti_ball:' => '🎊', ':rocket:' => '🚀', ':clock1:' => '🕐', ':clock2:' => '🕑', ':clock3:' => '🕒', ':clock4:' => '🕓', ':clock5:' => '🕔', ':clock6:' => '🕕', ':clock7:' => '🕖', ':clock8:' => '🕗', ':clock9:' => '🕘', ':clock10:' => '🕙', ':clock11:' => '🕚', ':clock12:' => '🕛', ':warning:' => '⚠️', ':danger:' => '🚨', ':info:' => 'ℹ️', ':success:' => '✅', ':error:' => '❌', ':bulb:' => '💡', ':lock:' => '🔒', ':key:' => '🔑', ':mag:' => '🔍', ':bomb:' => '💣', ':moneybag:' => '💰', ':credit_card:' => '💳', ':gem:' => '💎', ':package:' => '📦',  //物体与符号
		':car:' => '🚗', ':bus:' => '🚌', ':train:' => '🚆', ':airplane:' => '✈️', ':ship:' => '🚢', ':house:' => '🏠', ':hospital:' => '🏥', ':school:' => '🏫', ':hotel:' => '🏨', ':mountain:' => '⛰️', ':office:' => '🏢', ':factory:' => '🏭', ':statue:' => '🗽', ':bridge:' => '🌉', ':fountain:' => '⛲', //交通与地点
		':recycle:' => '♻️', ':radioactive:' => '☢️', ':biohazard:' => '☣️', ':peace:' => '☮️', ':yin_yang:' => '☯️', ':infinity:' => '♾️', ':check_mark:' => '✔️', ':cross_mark:' => '❌', ':question:' => '❓', ':exclamation:' => '❗', ':copyright:' => '©️', ':registered:' => '®️', //特殊符号
		':computer:' => '💻', ':keyboard:' => '⌨️', ':server:' => '🖥️', ':hard_drive:' => '💽', ':floppy_disk:' => '💾', ':cd:' => '💿', ':dvd:' => '📀', ':usb:' => '📱', ':electric_plug:' => '🔌', ':battery:' => '🔋', ':satellite:' => '🛰️', ':robot:' => '🤖',  // 硬件设备
		':file_folder:' => '📁', ':open_file_folder:' => '📂', ':page_with_curl:' => '📃', ':page_facing_up:' => '📄', ':book:' => '📖', ':books:' => '📚', ':notebook:' => '📓', ':notepad:' => '🗒️', ':clipboard:' => '📋', ':label:' => '🏷️', // 文件与文档
		':wrench:' => '🔧', ':hammer:' => '🔨', ':nut_and_bolt:' => '🔩', ':gear:' => '⚙️', ':compass:' => '🧭', ':puzzle:' => '🧩', ':magnifier:' => '🔍', ':microscope:' => '🔬', ':telescope:' => '🔭', ':crystal_ball:' => '🔮', ':magic_wand:' => '🪄', // 开发工具
		':bug:' => '🐛', ':alien:' => '👽', ':test_tube:' => '🧪', ':magnet:' => '🧲', ':fire_extinguisher:' => '🧯', ':collision:' => '💥', // 调试与问题
		':link:' => '🔗', ':broken_chain:' => '⛓️💔', ':shield:' => '🛡️', ':lock_with_key:' => '🔐', ':unlock:' => '🔓', // 网络与安全
        ':bar_chart:' => '📊', ':chart_up:' => '📈', ':chart_down:' => '📉', ':abacus:' => '🧮', ':database:' => '🗄️', // 数据与图表
        ':plus:' => '➕', ':minus:' => '➖', ':multiply:' => '✖️', ':divide:' => '➗', ':equals:' => '🟰', ':infinity:' => '♾️', ':cycle:' => '🔄', ':repeat:' => '🔁', ':repeat_one:' => '🔂', ':hash:' => '#️⃣', ':asterisk:' => '*️⃣', ':at:' => '@️⃣', // 操作符号
        ':branch:' => '🌿', ':merge:' => '🔄', ':commit:' => '💾', ':push:' => '⬆️', ':pull:' => '⬇️', ':fork:' => '🍴', ':conflict:' => '💥', ':revert:' => '⏪', ':tag:' => '🏷️', // 版本控制
        ':play:' => '▶️', ':pause:' => '⏸️', ':stop:' => '⏹️', ':record:' => '⏺️', ':forward:' => '⏩', ':rewind:' => '⏪', ':eject:' => '⏏️', ':shuffle:' => '🔀', ':loop:' => '🔁' // 媒体控制
    ];

    //缓存对象
    private static $cache;

    //一些初始值
    private static array $mark;
    private static array $toc;
    private static string $pathUrl;
    private static array $allBlocks;
    private static int $index;
    private static array $footnotes;
    private static array $links;
    private static array $paragraphWrap;

    /**
     * collectHtmlBlocks 跳过的标签
     *
     * 解析器输出的 scode/kbd，以及表格/列表等已编译块级标签不应再被提取为 block-html，
     * 否则会与后续 parseStyle、目录生成冲突并留下无法还原的占位符。
     */
    private static array $htmlExtractSkipTags = [
        'scode', 'kbd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'div', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'pre', 'code', 'details', 'summary',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'figure', 'figcaption', 'hr', 'p',
        'nav', 'section', 'article', 'main', 'header', 'footer',
    ];

    /**
     * 将 :emoji: 短码替换为 Unicode 表情（复用解析器映射表）
     *
     * @param string $text 原始文本
     * @return string
     * @static
     */
    public static function applyEmoji(string $text): string
    {
        if ($text === '' || !str_contains($text, ':')) {
            return $text;
        }

        return strtr($text, self::$emojiMap);
    }

    /**
     * 常用表情短码列表（评论表情栏）
     *
     * @return array<int, array{code:string,glyph:string}>
     * @static
     */
    public static function commonEmojis(): array
    {
        $codes = [
            ':smile:', ':laughing:', ':blush:', ':wink:', ':heart_eyes:', ':joy:',
            ':thumbsup:', ':thumbsdown:', ':clap:', ':pray:', ':fire:', ':tada:',
            ':heart:', ':thinking:', ':sunglasses:', ':rocket:', ':bug:', ':coffee:',
        ];
        // tada 可能不在 map 里，用 party_popper
        $alias = [':tada:' => ':party_popper:', ':thinking:' => ':raised_eyebrow:'];
        $out = [];
        foreach ($codes as $code) {
            $mapCode = $alias[$code] ?? $code;
            $glyph = self::$emojiMap[$mapCode] ?? self::$emojiMap[$code] ?? null;
            if ($glyph === null) {
                continue;
            }
            $out[] = ['code' => $code === ':tada:' ? ':party_popper:' : ($code === ':thinking:' ? ':raised_eyebrow:' : $code), 'glyph' => $glyph];
        }

        return $out;
    }
}
