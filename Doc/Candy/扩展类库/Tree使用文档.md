# Tree 无限级分类树处理使用文档

## 概述

Tree类是Candy框架中的无限级分类树处理类，提供全面的树形结构数据处理功能，支持无限级分类的构建、遍历、查询等操作。本类可以用于处理菜单、分类、组织结构等树形数据的展示和操作。

支持的主要功能：
- 自定义树形结构字段配置
- 生成HTML下拉菜单选项（select标签）
- 获取树形结构数据
- 查询指定节点的子节点
- 查询指定节点的父节点
- 支持模型树的生成

## 快速开始

### 实例化与配置

```php
// 实例化Tree类
$tree = new \Extend\Tree();

// 自定义字段映射（可选）
$tree->config(['parent_id' => 'pid', 'id' => 'cat_id', 'name' => 'cat_name']);
```

### 基本数据准备

```php
// 假设这是从数据库获取的分类数据
$categories = [
    ['id' => 1, 'parent_id' => 0, 'name' => '一级分类1'],
    ['id' => 2, 'parent_id' => 0, 'name' => '一级分类2'],
    ['id' => 3, 'parent_id' => 1, 'name' => '二级分类1-1'],
    ['id' => 4, 'parent_id' => 1, 'name' => '二级分类1-2'],
    ['id' => 5, 'parent_id' => 3, 'name' => '三级分类1-1-1']
];
```

### 生成下拉菜单

```php
// 生成HTML下拉菜单选项
$selectHtml = $tree->getTree($categories);

// 在模板中使用
<select name="category">
    <option value="0">请选择分类</option>
    {$selectHtml}
</select>
```

### 获取树形结构数据

```php
// 获取树形结构数组
$treeData = $tree->getTreeData($categories);

// 输出结果示例
/*
[  
    ['id' => 1, 'parent_id' => 0, 'name' => '一级分类1'],
    ['id' => 3, 'parent_id' => 1, 'name' => '|-二级分类1-1'],
    ['id' => 5, 'parent_id' => 3, 'name' => '|--三级分类1-1-1'],
    ['id' => 4, 'parent_id' => 1, 'name' => '|-二级分类1-2'],
    ['id' => 2, 'parent_id' => 0, 'name' => '一级分类2']
]
*/
```

## 核心功能

### config() - 初始化配置

```php
/**
 * 无限级分类树-初始化配置
 * 
 * 设置分类树的字段映射关系，可自定义父ID、主键和名称字段
 * 
 * @param array $params 配置分类的键名数组
 * @param string $params['parent_id'] 父ID字段名（可选，默认为'parent_id'）
 * @param string $params['id'] 主键字段名（可选，默认为'id'）
 * @param string $params['name'] 名称字段名（可选，默认为'name'）
 * @return Tree|false 成功返回Tree实例，失败返回false
 */
public function config(array $params): Tree|false
```

**示例：**

```php
// 使用默认字段配置
$tree->config();

// 自定义字段映射
$tree->config([
    'parent_id' => 'pid',   // 父ID字段名
    'id' => 'cat_id',       // 主键字段名
    'name' => 'cat_name'    // 名称字段名
]);
```

### getTree() - 获取树状结构

```php
/**
 * 无限级分类树-获取树
 * 
 * @param array $data 树的数组
 * @param int|string $parent_id 初始化树时候，代表ID下的所有子集
 * @param int|string|null $select_id 选中的ID值
 * @param string $pre_fix 前缀
 * @param bool $child 是否禁用父栏目
 * @param int|string|null $modelid 模型ID
 * @param int|string|null $groupid 分组ID
 * @param bool $guest 是否支持游客
 * @param int|string|null $roleid 后台角色组ID
 * @return string|array 生成的树状结构HTML选项或数组
 */
public function getTree($data, $parent_id = 0, $select_id = null, $pre_fix = '|-', $child = false, $modelid = null, $groupid = null, $guest = false, $roleid = null): string|array
```

**示例：**

```php
// 生成下拉菜单HTML，选中ID为3的选项
$selectHtml = $tree->getTree($categories, 0, 3);

// 生成特定父节点下的树形结构
$childTree = $tree->getTree($categories, 1);

// 使用自定义前缀
$customPrefixTree = $tree->getTree($categories, 0, null, '→');

// 禁用父栏目
$disabledParentTree = $tree->getTree($categories, 0, null, '|-', true);
```

### getTreeData() - 获取树形结构数组

```php
/**
 * 无限级分类树-获取树
 * 
 * 将一维数组转换为带有前缀的层级数据，方便在列表中展示层级关系
 * 
 * @param  array        $data      树的数组数据
 * @param  int|string   $parent_id 初始化树时，代表ID下的所有子集，默认为0
 * @param  string       $pre_fix   用于表示层级的前缀，默认为"|-"
 * @return string|array            返回带有层级前缀的数据数组或空字符串
 */
public function getTreeData(array $data, $parent_id = 0, string $pre_fix='|-'): string|array
```

**示例：**

```php
// 获取所有分类的树形结构数据
$treeData = $tree->getTreeData($categories);

// 获取特定父节点下的树形结构数据
$childTreeData = $tree->getTreeData($categories, 1);

// 使用自定义前缀
$customPrefixData = $tree->getTreeData($categories, 0, '→');
```

### getChild() - 获取子节点

```php
/**
 * 无限级分类树-获取子类
 * 
 * 获取指定节点的直接子节点
 * 
 * @param  array        $data 树的数组数据
 * @param  int|string   $id   父类ID
 * @return string|array       子节点数据数组或空数组
 */
public function getChild(array $data, $id): string|array
```

**示例：**

```php
// 获取ID为1的节点的直接子节点
$children = $tree->getChild($categories, 1);

// 输出结果示例
/*
[
    ['id' => 3, 'parent_id' => 1, 'name' => '二级分类1-1'],
    ['id' => 4, 'parent_id' => 1, 'name' => '二级分类1-2']
]
*/
```

### getParent() - 获取父节点

```php
/**
 * 无限级分类树-获取父类
 * 
 * 获取指定节点的父节点信息
 * 
 * @param  array        $data 树的数组数据
 * @param  int|string   $id   子类ID
 * @return string|array       父节点数据数组或空数组
 */
public function getParent(array $data, $id): string|array
```

**示例：**

```php
// 获取ID为3的节点的父节点
$parent = $tree->getParent($categories, 3);

// 输出结果示例
/*
[
    ['id' => 1, 'parent_id' => 0, 'name' => '一级分类1']
]
*/
```

### getModelTree() - 获取模型树

```php
/**
 * 无限级分类树-获取模型树
 * 
 * 专为模型分类设计的树结构生成方法，支持模型类型筛选和选中状态
 * 
 * @param  array          $data       树的数组数据
 * @param  int|string     $parent_id  初始化树时候，代表ID下的所有子集
 * @param  int|string|null $select_id  选中的ID值
 * @param  string         $pre_fix    前缀
 * @param  int|string     $modelid    模型的ID值
 * @param  int|string|null $_modelid   辅助模型ID
 * @param  int|string|null $_groupid   分组ID
 * @param  int|string|null $_roleid    角色ID
 * @return string|array               生成的模型树HTML选项或数组
 */
public function getModelTree(array $data, $parent_id = 0, $select_id = null, $pre_fix = '|-', $modelid, $_modelid = null, $_groupid = null, $_roleid = null): string|array
```

**示例：**

```php
// 生成特定模型的树形结构
$modelTreeHtml = $tree->getModelTree($categories, 0, null, '|-', 1);

// 生成模型树并选中特定选项
$selectedModelTree = $tree->getModelTree($categories, 0, 3, '|-', 1);
```

## 高级用法

### 多层嵌套分类的完整处理

```php
// 实例化并配置Tree类
$tree = new \Extend\Tree();
$tree->config(['parent_id' => 'pid', 'id' => 'id', 'name' => 'title']);

// 从数据库获取完整分类数据
$categories = Category::all()->toArray();

// 1. 生成分类下拉选择器
$categorySelect = $tree->getTree($categories, 0, $currentCategoryId);

// 2. 获取当前分类的所有子分类
$childCategories = $tree->getChild($categories, $currentCategoryId);

// 3. 获取当前分类的父分类路径
$parentCategories = $tree->getParent($categories, $currentCategoryId);

// 4. 获取完整的树形结构数组
$fullTreeData = $tree->getTreeData($categories);
```

### 模型分类管理

```php
// 获取特定模型的分类树
$modelId = 2; // 产品模型ID
$productCategories = $tree->getModelTree($categories, 0, null, '|-', $modelId);

// 在产品添加表单中使用
<select name="product_category">
    <option value="0">请选择产品分类</option>
    {$productCategories}
</select>
```

### 递归获取所有子分类ID

```php
/**
 * 递归获取所有子分类ID
 * 
 * @param array $data 分类数据
 * @param int $parentId 父分类ID
 * @param array $result 结果数组
 * @return array 所有子分类ID的数组
 */
function getAllChildIds($data, $parentId, &$result = []) {
    $children = $tree->getChild($data, $parentId);
    foreach ($children as $child) {
        $result[] = $child['id'];
        getAllChildIds($data, $child['id'], $result);
    }
    return $result;
}

// 获取分类1下的所有子分类ID
$allChildIds = getAllChildIds($categories, 1);
```

### 构建面包屑导航

```php
/**
 * 构建面包屑导航
 * 
 * @param array $data 分类数据
 * @param int $currentId 当前分类ID
 * @param string $separator 分隔符
 * @return string 面包屑导航HTML
 */
function buildBreadcrumb($data, $currentId, $separator = ' > ') {
    $tree = new \Extend\Tree();
    $parentCategories = $tree->getParent($data, $currentId);
    
    // 反转数组顺序，从顶级到当前分类
    $parentCategories = array_reverse($parentCategories);
    
    $breadcrumb = '';
    foreach ($parentCategories as $category) {
        $breadcrumb .= '<a href="?cat_id=' . $category['id'] . '">' . $category['name'] . '</a>' . $separator;
    }
    
    // 获取当前分类名称
    $currentCategory = array_filter($data, function($item) use ($currentId) {
        return $item['id'] == $currentId;
    });
    $currentCategory = reset($currentCategory);
    
    $breadcrumb .= '<span class="current">' . $currentCategory['name'] . '</span>';
    
    return $breadcrumb;
}

// 使用示例
$breadcrumbHtml = buildBreadcrumb($categories, 5);
// 输出：<a href="?cat_id=1">一级分类1</a> > <a href="?cat_id=3">二级分类1-1</a> > <span class="current">三级分类1-1-1</span>
```

## 注意事项

1. 在使用`getTree()`和`getModelTree()`方法时，数据结构中应包含`typeid`和`child`字段，以支持类型过滤和父节点禁用功能

2. `getModelTree()`方法需要数据中包含`arrmodelid`字段来进行模型过滤

3. 权限相关功能依赖于数据中的`setting`数组，包含`memberpost`、`modelpost`、`grouppost`和`adminpost`等配置

4. 在使用`getParent()`方法前，确保数据中包含完整的父子关系信息

5. 当处理大量分类数据时，建议先从数据库获取完整数据，再一次性生成树形结构，以提高性能

6. 生成HTML下拉菜单时，可以根据需要在模板中添加额外的样式和属性

## 版本信息

- 作者: 刘森 (fingerboy@qq.com)
- 创建日期: 2020-05-24 00:28:25
- 适用版本: Candy框架所有版本