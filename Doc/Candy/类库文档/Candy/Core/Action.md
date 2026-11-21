# Action 类

## 类简介

Action类是Candy框架中所有控制器的基类，提供了控制器的核心功能和通用方法。
主要负责处理HTTP请求、权限验证、页面渲染等控制器基本操作。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Action类 - 控制器基类
 * 
 * 继承自View类，提供了控制器的基本功能，包括请求处理、方法调用、权限检查等。
 * 所有用户自定义的控制器都应该继承此类。
 */
Class Action extends View {
    // 类成员
}
```

## 主要方法

### working()

```php
/**
 * 控制器工作方法
 * 
 * 此方法是控制器的核心，负责初始化控制器、检查权限、调用对应方法或渲染模板。
 * 处理流程：
 * 1. 初始化控制器
 * 2. 调用可能存在的init()方法进行权限控制
 * 3. 根据动作名调用对应方法或渲染模板
 * 4. 处理404情况
 * 5. 触发应用程序结束事件
 * 
 * @return void
 */
public function working(): void
```

### redirect()

```php
/** 
 * 页面重定向方法
 *
 * 用于在控制器中进行URL重定向，支持API模式和普通模式。
 * 在API模式下返回重定向信息，在普通模式下使用JavaScript实现页面跳转。
 *
 * @param string $path 重定向目标路径
 * @param string $args 传递的参数，格式为'param1/value1/param2/value2'
 * @return void
 * @example
 * $this->redirect("index")  // 重定向到 /当前模块/index
 * $this->redirect("user/index")  // 重定向到 /user/index
 * $this->redirect("user/index", 'page/5')  // 重定向到 /user/index/page/5
 */
protected function redirect(string $path, string $args = ''): void
```

### success()

```php
/**
 * 成功消息提示
 *
 * 显示操作成功的提示信息，并在指定时间后跳转。
 *
 * @param string $mess 提示消息内容
 * @param int $timeout 跳转等待时间，单位：秒
 * @param string $location 跳转目标地址
 * @param string $title 提示标题
 * @return bool 始终返回false
 */
protected function success(string $mess='操作成功', int $timeout=1, string $location='', string $title=''): bool
```

### error()

```php
/**
 * 失败消息提示
 *
 * 显示操作失败的提示信息，并在指定时间后跳转。
 *
 * @param string $mess 提示消息内容
 * @param int $timeout 跳转等待时间，单位：秒
 * @param string $location 跳转目标地址
 * @param string $title 提示标题
 * @return bool 始终返回false
 */
protected function error(string $mess='操作失败', int $timeout=3, string $location='', string $title=''): bool
```

### instance()

```php
/**
 * 获取对象实例
 *
 * 通过容器获取指定类的实例，支持依赖注入。
 *
 * @param string $className 类名
 * @return object 返回类的实例
 */
protected function instance(string $className): object
```

## 内部方法

以下是Action类中的内部方法（私有方法），仅供类内部使用：

### showMessage()

```php
/**
 * 显示提示消息（内部方法）
 *
 * 内部方法，用于统一处理成功和失败的消息提示逻辑。
 *
 * @param string $mess 提示消息内容
 * @param int $timeout 跳转等待时间，单位：秒
 * @param string $location 跳转目标地址
 * @param string $title 提示标题
 * @param bool $isSuccess 是否为成功消息
 * @return bool 始终返回false
 */
private function showMessage(string $mess, int $timeout, string $location, string $title, bool $isSuccess): bool
```

### pub()

```php
/**
 * 跳转公共方法（内部方法）
 *
 * 内部方法，用于设置消息提示的公共参数和处理逻辑。
 *
 * @param string $mess 提示消息内容
 * @param int $timeout 跳转等待时间，单位：秒
 * @param string $location 跳转目标地址
 * @param string $title 提示标题
 * @return void
 */
private function pub(string $mess, int $timeout, string $location, string $title): void
```

### display404()

```php
/**
 * 显示404页面（内部方法）
 *
 * 处理404错误页面的显示逻辑，在调试模式下直接显示错误信息，
 * 否则显示自定义404页面或标准404响应。
 *
 * @param string $msg 错误提示信息
 * @return void
 */
private function display404(string $msg): void
```

## 使用说明

### 基本用法

所有自定义控制器都应该继承Action类：

```php
namespace App\Controller;

use Candy\Core\Action;

class IndexController extends Action {
    public function index() {
        // 控制器逻辑
        $this->display();
    }
}
```

### 消息提示使用

```php
// 成功提示
$this->success('操作成功', 1, 'index');

// 错误提示
$this->error('操作失败，请重试', 3, '');
```

### 重定向使用

```php
// 重定向到指定URL
$this->redirect('user/login');

// 带参数的重定向
$this->redirect('article/detail', 'id/123');
```

## 注意事项

1. 控制器方法如果以下划线开头（如`_privateMethod`）将被禁止直接访问
2. 在调试模式下，404错误会直接显示错误信息而非自定义页面
3. API模式下，重定向和消息提示的行为会有所不同

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 23:38:42