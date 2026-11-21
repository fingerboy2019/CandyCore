# Candy框架tags功能文档

## 概述

tags功能是Candy框架的钩子系统核心组件，提供了事件监听和行为扩展机制，允许开发者在不修改框架核心代码的情况下，通过注册钩子标签和行为来扩展系统功能。

## tags功能的实现机制

### 1. 标签配置文件

**文件位置**：`[项目根目录]/Application/tags.php`

**文件格式**：
```php
<?php
/**
 * 全局tags配置文件
 * 定义标签与行为的映射关系
 */
return [
    // 标签名 => 行为列表
    'app_init' => [
        'app\behavior\AppInit',  // 应用初始化行为
    ],
    'app_begin' => [
        'app\behavior\AppBegin',  // 应用开始行为
        'app\behavior\CheckAuth',  // 权限检查行为
    ],
    'app_end' => [
        'app\behavior\AppEnd',  // 应用结束行为
    ],
    // 自定义标签
    'my_tag' => [
        'app\behavior\MyBehavior',  // 自定义行为
        function($params) {  // 匿名函数行为
            // 行为逻辑
        },
    ],
];
```

### 2. 自动加载机制

tags配置文件在框架初始化时自动加载，相关代码位于`/Candy/Autoload.php`第77-82行：

```php
//引入全局tags
$tagsPath = C('CANDYROOT') . 'Application/tags.php';
$tagConfig = is_file($tagsPath) ? include($tagsPath) : [];
if(empty($tagConfig) === false){
    Hook::import($tagConfig);
}
```

### 3. Hook类核心方法

#### 导入标签配置

```php
/**
 * 批量导入标签与行为配置
 * 
 * @param array $tags 标签配置数组，格式为[标签名 => 行为列表]
 * @param bool $recursive 是否递归合并现有配置，true为合并，false为覆盖
 * @return void
 */
public static function import(array $tags, $recursive = true)
```

#### 注册行为别名

```php
/**
 * 注册行为别名
 * 
 * @param string|array $name 行为别名或包含多个别名的数组
 * @param mixed $behavior 实际行为类名或闭包（当$name为字符串时有效）
 * @return string 当前类名，支持链式调用
 */
public static function alias($name, $behavior = null)
```

#### 添加行为到标签

```php
/**
 * 动态添加行为到标签
 * 
 * @param string $tag 标签名称
 * @param mixed $behavior 行为名称、闭包或行为数组
 * @param bool $first 是否添加到队列开头（优先级高）
 * @return void
 */
public static function add($tag, $behavior, $first = false)
```

#### 触发标签事件

```php
/**
 * 监听并触发标签事件
 * 
 * @param string $tag 标签名称
 * @param mixed $params 传递给行为的参数
 * @param bool $once 是否只获取第一个有效返回值
 * @return mixed 如果$once为true则返回单个返回值，否则返回所有行为的返回值数组
 */
public static function listen($tag, $params = null, $once = false)
```

## 行为类的定义

### 1. 类形式的行为

```php

namespace app\behavior;

class AppInit {
    /**
     * 行为执行方法
     * 
     * @param mixed $params 传入的参数
     * @return mixed 行为执行结果
     */
    public function run($params) {
        // 行为逻辑
        echo '应用初始化行为执行';
        return true;
    }
}
```

### 2. 闭包形式的行为

```php
<?php
// 在tags.php中直接定义闭包行为
return [
    'app_begin' => [
        function($params) {
            // 行为逻辑
            echo '闭包行为执行';
            return true;
        },
    ],
];
```

## tags功能的应用场景

### 1. 应用生命周期事件监听

```php
// tags.php
return [
    'app_init'  => ['app\behavior\AppInit'],  // 应用初始化
    'app_begin' => ['app\behavior\AppBegin'],  // 应用开始
    'app_end'   => ['app\behavior\AppEnd'],    // 应用结束
    'action_begin' => ['app\behavior\ActionBegin'],  // 控制器开始
    'action_end'   => ['app\behavior\ActionEnd'],    // 控制器结束
    'model_before_insert' => ['app\behavior\ModelBeforeInsert'],  // 模型插入前
    'model_after_insert'  => ['app\behavior\ModelAfterInsert'],   // 模型插入后
];
```

### 2. 权限检查与认证

```php
// tags.php
return [
    'app_begin' => [
        'app\behavior\CheckAuth',  // 全局权限检查
    ],
    'admin_action_begin' => [
        'app\behavior\AdminAuth',  // 后台权限检查
    ],
];
```

### 3. 数据过滤与处理

```php
// tags.php
return [
    'model_before_insert' => ['app\behavior\DataFilter'],  // 数据插入前过滤
    'model_before_update' => ['app\behavior\DataFilter'],  // 数据更新前过滤
];
```

### 4. 日志记录

```php
// tags.php
return [
    'app_end' => ['app\behavior\LogRecord'],  // 应用结束时记录日志
    'action_end' => ['app\behavior\ActionLog'],  // 控制器结束时记录操作日志
];
```

## tags功能的高级用法

### 1. 行为别名

```php
// 注册行为别名
Hook::alias('auth', 'app\behavior\CheckAuth');

// 使用别名
return [
    'app_begin' => ['auth'],  // 等同于使用'app\behavior\CheckAuth'
];
```

### 2. 行为优先级

```php
// 将行为添加到队列开头（高优先级）
Hook::add('app_begin', 'app\behavior\HighPriorityBehavior', true);

// 将行为添加到队列末尾（低优先级）
Hook::add('app_begin', 'app\behavior\LowPriorityBehavior', false);
```

### 3. 单次执行模式

```php
// 只执行第一个返回非null值的行为
$result = Hook::listen('app_begin', $params, true);
```

### 4. 行为参数传递

```php
// 传递参数给行为
$params = ['user_id' => 1, 'action' => 'login'];
Hook::listen('user_login', $params);

// 在行为中接收参数
class UserLoginBehavior {
    public function run($params) {
        $user_id = $params['user_id'];
        $action = $params['action'];
        // 行为逻辑
    }
}
```

## tags功能与插件系统的关系

Candy框架的插件系统基于tags功能实现，插件可以通过注册钩子标签来扩展系统功能。插件的钩子配置通常在插件的配置文件中定义，然后通过插件管理系统注册到全局tags中。

## 注意事项

1. **tags.php文件是可选的**：如果不存在，框架会自动使用空数组，不会影响系统运行。

2. **行为执行顺序**：默认情况下，行为按照注册顺序执行，可以通过`Hook::add()`的第三个参数控制优先级。

3. **行为返回值**：行为可以返回任意值，`Hook::listen()`方法会收集所有行为的返回值并返回。

4. **性能考虑**：过多的钩子标签和行为可能会影响系统性能，建议只注册必要的钩子。

## 示例：完整的tags.php配置

```php
<?php
/**
 * 全局tags配置文件
 * 定义标签与行为的映射关系
 */
return [
    // 应用生命周期标签
    'app_init' => [
        'app\behavior\AppInit',
    ],
    'app_begin' => [
        'app\behavior\AppBegin',
        'app\behavior\CheckAuth',
        'app\behavior\LogRecord',
    ],
    'app_end' => [
        'app\behavior\AppEnd',
    ],
    
    // 控制器生命周期标签
    'action_begin' => [
        'app\behavior\ActionBegin',
    ],
    'action_end' => [
        'app\behavior\ActionEnd',
    ],
    
    // 模型生命周期标签
    'model_before_insert' => [
        'app\behavior\DataFilter',
    ],
    'model_after_insert' => [
        'app\behavior\DataLog',
    ],
    'model_before_update' => [
        'app\behavior\DataFilter',
    ],
    'model_after_update' => [
        'app\behavior\DataLog',
    ],
    'model_before_delete' => [
        'app\behavior\DataCheck',
    ],
    
    // 自定义标签
    'user_login' => [
        'app\behavior\UserLogin',
    ],
    'user_logout' => [
        'app\behavior\UserLogout',
    ],
];
```

## 总结

tags功能是Candy框架的核心扩展机制，提供了灵活的事件监听和行为扩展能力。通过合理使用tags功能，开发者可以实现业务逻辑的解耦，提高代码的可维护性和扩展性。