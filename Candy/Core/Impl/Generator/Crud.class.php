<?php
/***
 * 按表 CRUD 代码生成编排
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 15:40:21 $
 *
 * @package Candy\Core\Impl\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Impl\Generator;

use Candy\Core\Trait\Generator\FieldMapper;
use Candy\Core\Trait\Generator\Naming;
use Candy\Core\Trait\Generator\PathLayout;
use Candy\Core\Trait\Generator\TemplateResolver;
use Candy\Extend\Migration\Manager;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Crud
{
    use Naming;
    use PathLayout;
    use TemplateResolver;
    use FieldMapper;

    /**
     * 按表生成代码
     *
     * 选项：
     * - app, table, model, control, service, style, layers, overwrite
     * - classGroup, classGroupOrder, classOrder, menuTitle
     * - columnLabels（可选：field => 展示标签，优先于库 comment）
     * - fieldOptions（可选：选项源）
     * - pageLayout（可选：设计器表单栅栏/顺序、搜索顺序、列表列序）
     * - exportSql, exportMigration（可选导出建表 SQL / migrate 文件）
     *
     * layers: model|service|control|view|lang
     *
     * @param array<string, mixed> $options
     * @return GenerateResult
     */
    public static function run(array $options): GenerateResult
    {
        $app = self::normalizeAppName((string)($options['app'] ?? ''));
        $table = trim((string)($options['table'] ?? ''));
        $style = trim((string)($options['style'] ?? ''));
        if ($style === '') {
            $style = self::resolveProjectDefaultStyle();
        }
        $overwrite = (string)($options['overwrite'] ?? 'skip');
        $layers = $options['layers'] ?? ['model', 'control', 'view', 'lang'];
        if (!is_array($layers)) {
            $layers = ['model', 'control', 'view', 'lang'];
        }

        if ($app === '') {
            return new GenerateResult(errors: ['app is required']);
        }
        if ($table === '' || !Schema::tableExists($table)) {
            return new GenerateResult(errors: ['table not found: ' . $table]);
        }
        if (!is_dir(self::appRoot($app))) {
            return new GenerateResult(errors: ['application not found: ' . $app]);
        }

        $styles = self::listGeneratorStyles();
        if ($style !== self::$generatorDefaultStyle && !isset($styles[$style])) {
            return new GenerateResult(errors: ['unknown style: ' . $style]);
        }

        $modelClass = (string)($options['model'] ?? '');
        if ($modelClass === '') {
            $modelClass = self::tableToClassName($table);
        }
        $controlClass = (string)($options['control'] ?? $modelClass);
        $serviceClass = trim((string)($options['service'] ?? ''));
        if ($serviceClass === '') {
            $serviceClass = $controlClass;
        }

        $columns = self::applyColumnLabelOverrides(
            Schema::listColumns($table),
            $options['columnLabels'] ?? []
        );
        $pk = 'id';
        $tableComment = '';
        foreach (Schema::listTables($table) as $meta) {
            if (($meta['name'] ?? '') === $table) {
                $tableComment = (string)($meta['comment'] ?? '');
                break;
            }
        }
        foreach ($columns as $column) {
            if (($column['key'] ?? '') === 'PRI') {
                $pk = (string)($column['field'] ?? 'id');
                break;
            }
        }

        $menuTitle = trim((string)($options['menuTitle'] ?? ''));
        if ($menuTitle === '') {
            $menuTitle = $tableComment !== '' ? $tableComment : $modelClass;
        }

        $useService = in_array('service', $layers, true);
        $dataAccessor = $useService ? 'S' : 'M';
        $dataTarget = $useService ? $serviceClass : $modelClass;

        $softDeleteField = self::detectSoftDeleteField($columns);
        $fieldOptions = is_array($options['fieldOptions'] ?? null) ? $options['fieldOptions'] : [];
        $pageLayout = is_array($options['pageLayout'] ?? null) ? $options['pageLayout'] : [];

        $baseContext = [
            'app' => $app,
            'table' => $table,
            'modelClass' => $modelClass,
            'controlClass' => $controlClass,
            'serviceClass' => $serviceClass,
            'dataAccessor' => $dataAccessor,
            'dataTarget' => $dataTarget,
            'pk' => $pk,
            'date' => date('Y-m-d H:i:s'),
            'classGroup' => (string)($options['classGroup'] ?? 'Content'),
            'classGroupOrder' => (string)self::resolveClassGroupOrder($options),
            'classOrder' => (string)($options['classOrder'] ?? '50'),
            'menuTitle' => $menuTitle,
            'layuiIcon' => (string)($options['layuiIcon'] ?? 'layui-icon-template-1'),
            'tableId' => $controlClass . 'Table',
            'listColsJs' => self::buildListColsJs($columns, $pk, $fieldOptions, $pageLayout),
            'formFieldsHtml' => self::buildFormFieldsHtml($columns, $pk, $fieldOptions, $pageLayout),
            'searchFieldsHtml' => self::buildSearchFieldsHtml($columns, $pk, $fieldOptions, $pageLayout),
            'listFilterPhp' => self::buildListFilterPhp($columns, $pk),
            'listParamsDefaults' => self::buildListParamsDefaultsPhp($columns, $pk),
            'sortFieldsConst' => self::buildSortFieldsConst($columns, $pk),
            'softDeleteProperty' => self::buildSoftDeletePropertyPhp($columns) . self::buildAutoTimestampPropertyPhp($columns),
            'restoreRowMethod' => self::buildRestoreRowMethodPhp($columns, $pk),
            'normalizePayloadPhp' => self::buildNormalizePayloadPhp($columns, $pk, $fieldOptions),
            'softDeleteListHint' => $softDeleteField !== null ? '；type=recycle 查看回收站' : '',
            'controlClassLower' => lcfirst($controlClass),
            'optionDisplayPhp' => self::buildOptionDisplayPhp($fieldOptions),
            'assignOptionsPhp' => self::buildAssignOptionsPhp($fieldOptions),
            'listStatusTplHtml' => self::buildListStatusTplHtml($fieldOptions),
            'dragSortAttrs' => self::isTruthyOption($options['drag_sort'] ?? $options['dragSort'] ?? false)
                ? ' data-drag-sort data-sort-url="<{:U(\'sort.json\')}>" data-sort-field="sort" data-pk="{{pk}}"'
                : '',
            'sortMethodPhp' => self::isTruthyOption($options['drag_sort'] ?? $options['dragSort'] ?? false)
                ? self::buildSortMethodPhpStub()
                : '',
            'formActionHintHtml' => "\n            <!-- Form Action 示例：data-form-action data-action-url=\"<{:U('action.json')}>\" 见 raw/doc/FormActionConvention.md -->\n",
        ];

        $files = [];
        $layerMap = [
            'model' => ['model.stub', fn () => self::modelPath($app, $modelClass)],
            'service' => ['service.stub', fn () => self::servicePath($app, $serviceClass)],
            'control' => ['control.crud.stub', fn () => self::controlPath($app, $controlClass)],
            'view' => [
                ['view.index.stub', fn () => self::viewDir($app, $controlClass) . 'index.html'],
                ['view.search.stub', fn () => self::viewDir($app, $controlClass) . 'search.html'],
                ['view.form.stub', fn () => self::viewDir($app, $controlClass) . 'form.html'],
            ],
            'lang' => ['lang.zh-cn.stub', fn () => self::langPath($app, $controlClass)],
        ];

        foreach ($layers as $layer) {
            if (!isset($layerMap[$layer])) {
                continue;
            }
            $definition = $layerMap[$layer];
            if ($layer === 'view') {
                foreach ($definition as $item) {
                    [$stub, $pathFn] = $item;
                    if (self::resolveGeneratorStub($style, $stub) === null) {
                        return new GenerateResult(errors: [
                            '模板缺失：' . $stub . '（风格 ' . $style . ' 不支持该层，请选用 admin-layui）',
                        ]);
                    }
                    $content = Renderer::render($style, $stub, $baseContext);
                    if ($content === null) {
                        return new GenerateResult(errors: ['stub not found: ' . $stub]);
                    }
                    $files[] = ['path' => $pathFn(), 'content' => $content];
                }
                continue;
            }

            [$stub, $pathFn] = $definition;
            if (self::resolveGeneratorStub($style, $stub) === null) {
                return new GenerateResult(errors: [
                    '模板缺失：' . $stub . '（风格 ' . $style . ' 不支持该层，请选用 admin-layui）',
                ]);
            }
            $content = Renderer::render($style, $stub, $baseContext);
            if ($content === null) {
                return new GenerateResult(errors: ['stub not found: ' . $stub]);
            }
            $files[] = ['path' => is_callable($pathFn) ? $pathFn() : '', 'content' => $content];
        }

        if ($files === []) {
            return new GenerateResult(errors: ['no layers selected']);
        }

        $result = Writer::writeMany($files, $overwrite);

        return GenerateResult::merge($result, self::exportSchemaArtifacts($table, $options, $overwrite));
    }

    /**
     * 可选导出建表 SQL 或迁移文件
     *
     * @param string $table 物理表名
     * @param array<string, mixed> $options
     * @param string $overwrite skip|force
     * @return GenerateResult
     */
    private static function exportSchemaArtifacts(string $table, array $options, string $overwrite): GenerateResult
    {
        $exportSql = self::isTruthyOption($options['exportSql'] ?? false);
        $exportMigration = self::isTruthyOption($options['exportMigration'] ?? false);
        if (!$exportSql && !$exportMigration) {
            return new GenerateResult();
        }

        $createSql = Schema::getCreateTableSql($table);
        if ($createSql === '') {
            return new GenerateResult(errors: ['无法读取建表 SQL：' . $table]);
        }

        $created = $skipped = $errors = [];

        if ($exportSql) {
            $config = function_exists('loadConfig') ? loadConfig('Generator') : [];
            $dir = trim((string)($config['sql_export_dir'] ?? 'raw/doc/sql'), '/\\');
            $root = rtrim((string)C('CANDYROOT'), '/\\');
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir)
                . DIRECTORY_SEPARATOR . $table . '.sql';
            $content = '-- Generated by Candy Generator at ' . date('Y-m-d H:i:s') . PHP_EOL . $createSql;
            $write = Writer::write($path, $content, $overwrite);
            match ($write['status']) {
                'created' => $created[] = $path,
                'skipped' => $skipped[] = $path,
                default => $errors[] = ($write['reason'] ?? 'export sql failed') . ': ' . $path,
            };
        }

        if ($exportMigration) {
            $migrationName = self::tableToMigrationName($table);
            $downSql = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`;';
            try {
                $path = (new Manager())->makeWithSql($migrationName, $createSql, $downSql);
                $created[] = $path;
            } catch (\Throwable $e) {
                $errors[] = 'migrate:make 失败：' . $e->getMessage();
            }
        }

        return new GenerateResult($created, $skipped, $errors);
    }

    /**
     * 表名转迁移名称
     *
     * @param string $table 物理表名
     * @return string
     */
    private static function tableToMigrationName(string $table): string
    {
        $prefix = self::resolveTablePrefix();
        $name = $table;
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            $name = substr($table, strlen($prefix));
        }
        $name = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name) ?? $name);
        $name = trim($name, '_');

        return 'create_' . ($name !== '' ? $name : 'table') . '_table';
    }

    /**
     * 解析布尔型生成选项
     *
     * @param mixed $value
     * @return bool
     */
    private static function isTruthyOption(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 解析侧栏分组排序常量 classGroupOrder
     *
     * 优先使用调用方显式传入；否则按 classGroup 映射 Admin 默认分组顺序
     *
     * @param array<string, mixed> $options
     * @return int
     */
    private static function resolveClassGroupOrder(array $options): int
    {
        if (isset($options['classGroupOrder']) && $options['classGroupOrder'] !== '') {
            return (int)$options['classGroupOrder'];
        }

        $group = (string)($options['classGroup'] ?? 'Content');

        return match ($group) {
            'Center' => 5,
            'Content' => 10,
            'CodeTools' => 30,
            'File' => 40,
            'Ops' => 50,
            'User' => 60,
            'System' => 900,
            default => 500,
        };
    }

    /**
     * 拖拽排序 Control 方法骨架
     *
     * @return string
     * @access private
     * @static
     */
    private static function buildSortMethodPhpStub(): string
    {
        return <<<'PHP'

    /**
     * 列表拖拽排序
     *
     * @Title("排序")
     * @Method("POST")
     */
    #[Annotation(title: '排序', auth: true)]
    function sort(){
        if (C('METHOD') !== 'POST') {
            $this->assign(['code' => 1, 'message' => L('requestMethodError')]);
            $this->display();
            return;
        }
        // TODO: 在 Model/Service 实现 reorder(ids, field=sort)，字段须白名单
        $this->assign(['code' => 1, 'message' => '请实现 sort / reorder 后删除本提示']);
        $this->display();
    }
PHP;
    }
}
