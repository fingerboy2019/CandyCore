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
     * 支持的空间创建类型（对应 Build::bulidSpaces 的三种）
     */
    private const SPACES = [
        'app'   => '',
        'addon' => 'addon',
        'plug'  => 'plug',
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

        // ② 仅 DEBUG 模式：与 Bootstrap::route() 里 Build::start 的入口判定保持一致
        if (isDebug() === false) {
            return self::json(403, 'sys/init 仅在 DEBUG 模式可用（.env 的 APP_DEBUG=1 或 Sys.inc.php 的 runtime.debug=true）');
        }

        // ③ 参数
        $app  = ucfirst(trim((string) ($_GET['app'] ?? '')));
        $type = strtolower(trim((string) ($_GET['type'] ?? 'control')));
        $name = ucfirst(trim((string) ($_GET['name'] ?? '')));
        $dry  = (string) ($_GET['dry'] ?? '') === '1';
        $over = (string) ($_GET['overwrite'] ?? '') === '1';

        // ③-a 空间创建：主应用 / 扩展 / 插件（等价 make:app，复用 Build::bulidSpaces 的三种类型）
        if (isset(self::SPACES[$type])) {
            return self::space($type, $app !== '' ? $app : $name, $dry);
        }

        if (preg_match('/^[A-Z][A-Za-z0-9_]{0,39}$/', $app) !== 1) {
            return self::json(400, 'app 不合法：需以大写字母开头，仅字母/数字/下划线，最长 40');
        }
        if (isset(self::TYPES[$type]) === false) {
            return self::json(400, 'type 必须是：' . implode(' / ', array_merge(array_keys(self::SPACES), array_keys(self::TYPES))));
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
     * 创建空间：主应用 / 扩展 / 插件（复用 Build::bulidSpaces）
     *
     * 与 make:app 等价：
     *   app   → Application/{Name}/ + Plugins/{Name}/（默认分支会同时建插件目录）
     *   addon → Addons/{Name}/（需项目开启 $addons）
     *   plug  → Plugins/{Name}/
     *
     * @param string $type app|addon|plug
     * @param string $rawName 名称
     * @param bool $dry 只预览
     * @return string JSON
     */
    private static function space(string $type, string $rawName, bool $dry): string
    {
        $kind = self::SPACES[$type];
        // 与框架一致：ucfirst(strtolower())，避免用户以为生成 MyApp 实际得到 Myapp
        $name = ucfirst(strtolower(trim($rawName)));
        if (preg_match('/^[A-Z][A-Za-z0-9_]{0,39}$/', $name) !== 1) {
            return self::json(400, '名称不合法：需以字母开头，仅字母/数字/下划线，最长 40（框架会规范为「首字母大写+其余小写」）');
        }

        $root   = rtrim(C('CANDYROOT'), '/') . '/';
        $target = $kind === 'addon'
            ? $root . 'Addons/' . $name . '/'
            : ($kind === 'plug' ? $root . 'Plugins/' . $name . '/' : $root . 'Application/' . $name . '/');

        if (is_dir($target)) {
            return self::json(409, '已存在：' . self::rel($target) . '（换个名字，或先手动删除）');
        }

        if ($kind === 'addon') {
            include C('INCPATH') . 'System.inc.php';
            if (is_null(G('Addons'))) {
                return self::json(403, '项目未开启 Addon 功能：在 Source/Inc/System.inc.php 配置 $addons');
            }
        }

        if ($dry) {
            return self::json(0, 'ok（预览，未创建）', [
                'type' => $type,
                'name' => $name,
                'path' => self::rel($target),
                'plan' => $type === 'app'
                    ? '创建 Application/' . $name . '/ 与 Plugins/' . $name . '/（含模板、路由、语言库）'
                    : '创建 ' . self::rel($target),
            ]);
        }

        if (\Candy\Core\Build::bulidSpaces($name, $kind) !== true) {
            return self::json(500, '创建失败（框架返回 false：目录已存在、未开启 Addon 或模板缺失）');
        }

        $files = 0;
        if (is_dir($target)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files++;
                }
            }
        }

        return self::json(0, 'created', [
            'type'  => $type,
            'name'  => $name,
            'path'  => self::rel($target),
            'files' => $files,
            'note'  => $type === 'app' ? '默认分支同时创建了 Plugins/' . $name . '/' : '',
        ]);
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
