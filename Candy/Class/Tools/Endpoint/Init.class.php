<?php
/***
 * 框架内置端点：代码生成（make:* 的 Web 入口）
 *
 * 业务说明：在开发环境里通过浏览器生成 Control / Model / Schema / Validate 空类，
 * 等价于 `php candy make:control|model|schema|validate`，但不需要开终端。
 *
 * 三重保险（缺一不可）：
 *   ① token 必须已配置（Registry::authorize 在 token 为空时会放行，不能只靠它）
 *   ② 仅开发环境可用（Source/Inc/Init.inc.php 里定义 DEV）
 *   ③ 写入范围由 app + type 推导并做 realpath 前缀校验，不会越界
 *
 * 用法：
 *   /sys/init?app=Admin&type=control&name=Article            生成
 *   /sys/init?app=Admin&type=control&name=Article&dry=1      只预览
 *   /sys/init?app=Admin&type=control&name=Article&overwrite=1  覆盖（先备份 .bak）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-21 20:40:00 $
 *
 * @package Candy\Extend\Tools\Endpoint
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Init
{
    /**
     * 支持的生成类型（与 Console\Command\Make 的命名空间/目录保持一致）
     */
    private const TYPES = [
        'control'  => ['dir' => 'Controls',  'ns' => 'APP\\Control\\%s'],
        'model'    => ['dir' => 'Models',    'ns' => 'APP\\Model\\%s'],
        'schema'   => ['dir' => 'Schemas',   'ns' => 'APP\\Schema\\%s'],
        'validate' => ['dir' => 'Validates', 'ns' => 'APP\\Validate\\%s'],
    ];

    /**
     * 执行生成
     *
     * @return string JSON
     */
    public static function handle(): string
    {
        // ① token 必须已配置且正确：authorize() 在 token 为空时直接放行，
        //    而且它失败时走 stop('forbidden') 输出纯文本；写文件的端点要统一返回 JSON 提示
        $expect = Registry::token('sys/init');
        if ($expect === '') {
            return self::json(403, 'sys/init 需要 token：请先在 Source/Inc/Endpoint.inc.php 配置 token 后再调用');
        }
        $got = self::token();
        if ($got === '' || hash_equals($expect, $got) === false) {
            return self::json(403, 'token 不正确：请带 ?token=… 或 Authorization: Bearer …，或先到 /sys/url 保存令牌');
        }

        // ② 仅开发环境
        if (defined('DEV') === false) {
            return self::json(403, 'sys/init 仅在开发环境可用（在 Source/Inc/Init.inc.php 定义 DEV）');
        }

        // ③ 参数
        $app  = ucfirst(trim((string) ($_GET['app'] ?? '')));
        $type = strtolower(trim((string) ($_GET['type'] ?? 'control')));
        $name = ucfirst(trim((string) ($_GET['name'] ?? '')));
        $dry  = (string) ($_GET['dry'] ?? '') === '1';
        $over = (string) ($_GET['overwrite'] ?? '') === '1';

        if (preg_match('/^[A-Z][A-Za-z0-9_]{0,39}$/', $app) !== 1) {
            return self::json(400, 'app 不合法：需以大写字母开头，仅字母/数字/下划线，最长 40');
        }
        if (isset(self::TYPES[$type]) === false) {
            return self::json(400, 'type 必须是：' . implode(' / ', array_keys(self::TYPES)));
        }
        if ($name === '') {
            $name = $app;
        }
        if (preg_match('/^[A-Z][A-Za-z0-9_]{0,39}$/', $name) !== 1) {
            return self::json(400, 'name 不合法：需以大写字母开头，仅字母/数字/下划线，最长 40');
        }

        $meta      = self::TYPES[$type];
        $namespace = sprintf($meta['ns'], $app);
        $base      = rtrim(C('CANDYROOT'), '/') . '/Application/';
        $dir       = $base . $app . '/' . $meta['dir'];
        $file      = $dir . '/' . $name . '.class.php';

        // ④ 越界校验：目标目录必须落在 Application/ 之下
        $rootReal = realpath($base) ?: $base;
        $dirReal  = realpath($dir);
        if ($dirReal !== false && $dirReal !== null) {
            if (strpos(rtrim($dirReal, '/') . '/', rtrim($rootReal, '/') . '/') !== 0) {
                return self::json(400, '目标路径越界');
            }
        }

        $code = self::build($type, $namespace, $name);
        $rel  = self::rel($file);

        if ($dry) {
            return self::json(0, 'ok（预览，未写入）', [
                'type'      => $type,
                'app'       => $app,
                'name'      => $name,
                'namespace' => $namespace,
                'file'      => $rel,
                'exists'    => is_file($file),
                'preview'   => $code,
            ]);
        }

        if (is_file($file) && $over === false) {
            return self::json(409, '文件已存在：' . $rel . '（加 overwrite=1 覆盖，覆盖前会备份 .bak）');
        }
        if (is_file($file) && $over) {
            @copy($file, $file . '.bak');
        }

        if (is_dir($dir) === false && @mkdir($dir, 0755, true) === false) {
            return self::json(500, '目录创建失败：' . $dir);
        }
        if (@file_put_contents($file, $code) === false) {
            return self::json(500, '写入失败：' . $rel);
        }

        // ⑤ 写后语法校验，失败回滚
        $lint = self::lint($file);
        if ($lint !== '') {
            @unlink($file);
            return self::json(500, '生成内容语法错误，已回滚：' . $lint);
        }

        $data = [
            'type'      => $type,
            'app'       => $app,
            'name'      => $name,
            'namespace' => $namespace,
            'file'      => $rel,
            'preview'   => $code,
        ];

        // control 类型顺带建语言库（目录用项目实际的 zh-cn）
        if ($type === 'control') {
            $langDir  = $base . $app . '/Languages/zh-cn';
            $langFile = $langDir . '/' . $name . '.lang.php';
            if (is_file($langFile) === false) {
                if (is_dir($langDir) === false) {
                    @mkdir($langDir, 0755, true);
                }
                @file_put_contents(
                    $langFile,
                    "<?php\ndefined('CANDY') OR die('You Are A Bad Guy. o_O???');\n\n//项目操作语言库\nreturn [];\n"
                );
                $data['lang'] = self::rel($langFile);
            }
        }

        return self::json(0, 'created', $data);
    }

    /**
     * 取请求里的令牌（?token= → Authorization: Bearer → cookie）
     *
     * @return string
     */
    private static function token(): string
    {
        $got = trim((string) ($_GET['token'] ?? ''));
        if ($got !== '') {
            return $got;
        }
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return trim((string) ($_COOKIE['candy_endpoint_token'] ?? ''));
    }

    /**
     * 按类型生成类骨架（与 Console\Command\Make 的结构保持一致）
     *
     * @param string $type 类型
     * @param string $namespace 命名空间
     * @param string $name 类名
     * @return string
     */
    private static function build(string $type, string $namespace, string $name): string
    {
        $date = date('Y-m-d H:i:s');

        if ($type === 'model') {
            return "<?php\ndefined('CANDY') OR die('You Are A Bad Guy. o_O???');\nnamespace {$namespace};\n\nClass {$name}\n{\n    //\n}\n";
        }

        if ($type === 'schema') {
            return <<<PHP
<?php
/***
 * {$name} 响应 Schema
 *
 * 业务说明：描述 API data 形状；运行时类名 {$name}Schema
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$date} \$
 *
 * @package {$namespace}
 */
namespace {$namespace};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class {$name}
{
    /**
     * 返回 data 结构定义
     *
     * @return array
     */
    public static function schema(): array
    {
        return [
            // 'id' => ['type' => 'int', 'desc' => '主键'],
        ];
    }
}

PHP;
        }

        if ($type === 'validate') {
            return <<<PHP
<?php
/***
 * {$name} 表单验证
 *
 * 业务说明：按 scene 校验写入字段；运行时类名 {$name}Validate
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$date} \$
 *
 * @package {$namespace}
 */
namespace {$namespace};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class {$name}
{
    /**
     * 规则定义
     *
     * @return array
     */
    public static function rules(): array
    {
        return [
            // 'title' => 'require|max:120',
        ];
    }
}

PHP;
        }

        // control（默认）
        return <<<PHP
<?php
/***
 * {$name} 控制器
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$date} \$
 *
 * @package {$namespace}
 */
namespace {$namespace};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class {$name}
{
    /**
     * 默认入口
     *
     * @return void
     */
    public function index(): void
    {
        // \$this->display();
    }
}

PHP;
    }

    /**
     * 用 CLI 做语法校验（FPM 下 PHP_BINARY 是 php-fpm，不能直接用）
     *
     * @param string $file 文件
     * @return string 空串=通过，否则为错误文本
     */
    private static function lint(string $file): string
    {
        $php = PHP_BINDIR . '/php';
        if (is_file($php) === false || function_exists('exec') === false) {
            return '';
        }
        $out = [];
        $rc  = 0;
        @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        if ($rc === 0) {
            return '';
        }
        return trim(implode(' | ', $out));
    }

    /**
     * 相对项目根的路径（返回给调用方看）
     *
     * @param string $file 绝对路径
     * @return string
     */
    private static function rel(string $file): string
    {
        return '/' . ltrim(str_replace('\\', '/', substr($file, strlen(rtrim(C('CANDYROOT'), '/')))), '/');
    }

    /**
     * 输出 JSON（框架派发器识别 JSON 即按 json 输出）
     *
     * @param int $code 业务码，0=成功
     * @param string $message 提示
     * @param array $data 数据
     * @return string
     */
    private static function json(int $code, string $message, array $data = []): string
    {
        return (string) json_encode([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
