<?php
/**
 * 自定义字段全局函数
 *
 * 与 sa_custom_entity / sa_custom_field / sa_custom_field_value 配合
 */

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 获取自定义字段业务服务
 *
 * @return object
 */
function customFieldService(): object
{
    return S('CustomField');
}

/**
 * 构建表单上下文（薄封装）
 *
 * @param string $app 应用名
 * @param string $control 控制器名
 * @param int $recordId 业务主键
 * @param int|null $moldId 内容模型 ID
 * @param bool $includeAllMolds 是否返回全部 mold 字段供前端显隐
 * @return array<string, mixed>
 */
function customFieldFormContext(
    string $app,
    string $control,
    int $recordId = 0,
    ?int $moldId = null,
    bool $includeAllMolds = false
): array {
    return customFieldService()->buildFormContext($app, $control, $recordId, $moldId, $includeAllMolds);
}

/**
 * 保存 POST 中的自定义字段
 *
 * @param string $app 应用名
 * @param string $control 控制器名
 * @param int $recordId 业务主键
 * @param array<string, mixed> $post POST 数据
 * @param int|null $moldId 内容模型；null 时从 post.mold_id 读取
 * @return array{code:int,message:string}
 */
function customFieldSaveFromPost(
    string $app,
    string $control,
    int $recordId,
    array $post,
    ?int $moldId = null
): array {
    return customFieldService()->saveValuesFromPost($app, $control, $recordId, $post, $moldId);
}
