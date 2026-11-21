# Candy框架 Console类库使用文档

## 1. 类库概述

Console类库是Candy框架提供的命令行接口(CLI)工具集，用于简化命令行应用的开发和管理。它提供了命令注册、输入解析、输出格式化等功能，使开发者能够快速创建和管理命令行工具。

### 1.1 主要功能

- 命令注册与管理
- 输入参数解析与验证
- 输出格式化与样式
- 默认命令支持
- 自定义命令创建
- 命令行帮助系统

## 2. 核心组件

### 2.1 Initiator（控制台入口）

`Console\Initiator`是整个Console类库的核心入口点，负责命令注册、输入解析和命令执行。

**主要功能**：
- 注册默认命令
- 解析命令行输入
- 执行指定命令
- 处理命令异常

### 2.2 Command（命令基类）

`Console\Command`是所有命令的基类，提供了命令的基本结构和功能。

**主要功能**：
- 命令配置（名称、描述、参数等）
- 命令执行流程
- 输入输出处理
- 禁用函数检查

### 2.3 Input（输入处理）

输入处理组件用于解析命令行参数和选项。

**主要类**：
- `Input\Argument`：命令参数定义
- `Input\Option`：命令选项定义
- `Input\Definition`：参数和选项的集合

### 2.4 Output（输出处理）

输出处理组件用于格式化和显示命令行输出。

**主要类**：
- `Output\Formatter`：输出格式化
- `Output\Question`：交互式问题
- `Output\Ask`：用户输入获取
- `Output\Descriptor`：帮助信息描述

## 3. 基本使用方法

### 3.1 运行命令

通过PHP CLI运行命令：

```bash
php Candy [command] [arguments] [options]
```

### 3.2 查看帮助

```bash
# 查看所有命令
php Candy list

# 查看特定命令帮助
php Candy help [command]

# 查看版本信息
php Candy version
```

## 4. 默认命令列表

### 4.1 基础命令

| 命令 | 描述 | 示例 |
|------|------|------|
| `help` | 显示命令帮助 | `php Candy help list` |
| `list` | 列出所有可用命令 | `php Candy list` |
| `clear` | 清除控制台 | `php Candy clear` |
| `version` | 显示框架版本 | `php Candy version` |

### 4.2 构建命令

| 命令 | 描述 | 示例 |
|------|------|------|
| `build:bin` | 构建二进制文件 | `php Candy build:bin` |
| `build:phar` | 构建PHAR文件 | `php Candy build:phar` |

### 4.3 生成命令

| 命令 | 描述 | 示例 |
|------|------|------|
| `make:app` | 创建新应用 | `php Candy make:app MyApp` |
| `make:control` | 创建控制器 | `php Candy make:control User` |
| `make:command` | 创建自定义命令 | `php Candy make:command MyCommand` |
| `make:model` | 创建模型 | `php Candy make:model User` |
| `make:validate` | 创建类验证器 | `php candy make:validate Admin Group` |
| `make:schema` | 创建响应 Schema | `php candy make:schema Admin Group` |

### 4.4 服务器命令

| 命令 | 描述 | 示例 |
|------|------|------|
| `run` | 启动内置服务器 | `php Candy run` |
| `worker` | 启动Worker进程 | `php Candy worker` |
| `process` | 启动Process进程 | `php Candy process` |
| `swoole` | 启动Swoole服务器 | `php Candy swoole` |
| `webdav` | 启动WebDAV服务器 | `php Candy webdav` |
| `ws` | 启动WebSocket服务器 | `php Candy ws` |
| `ftp` | 启动FTP服务器 | `php Candy ftp` |

### 4.5 其他命令

| 命令 | 描述 | 示例 |
|------|------|------|
| `vendor:publish` | 发布vendor资源 | `php Candy vendor:publish` |
| `composer` | 运行Composer命令 | `php Candy composer install` |

## 5. 创建自定义命令

### 5.1 使用make:command命令

```bash
php Candy make:command MyCommand
```

### 5.2 手动创建命令类

```php
<?php
declare(strict_types=1);
namespace Console\Command;

use \Console\{Command,Input,Output};
use \Console\Input\Argument;
use \Console\Input\Option;

class MyCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('my:command')
            ->addArgument('name', Argument::OPTIONAL, 'Your name')
            ->addOption('verbose', 'v', Option::VALUE_NONE, 'Verbose output')
            ->setDescription('My custom command');
    }

    protected function execute(Input $input, Output $output)
    {
        $name = $input->getArgument('name') ?: 'World';
        $output->writeln('<info>Hello, ' . $name . '!</info>');
        
        if ($input->getOption('verbose')) {
            $output->writeln('<comment>Verbose mode enabled</comment>');
        }
    }
}
```

### 5.3 注册自定义命令

将命令添加到配置文件中：

```php
// Config/Command.inc.php
return [
    'my:command' => 'Console\Command\MyCommand',
];
```

## 6. 输入处理

### 6.1 定义参数

```php
$this->addArgument('name', Argument::REQUIRED, 'Your name');
```

### 6.2 定义选项

```php
$this->addOption('verbose', 'v', Option::VALUE_NONE, 'Verbose output');
```

### 6.3 获取输入值

```php
// 获取参数值
$name = $input->getArgument('name');

// 获取选项值
$verbose = $input->getOption('verbose');
```

## 7. 输出处理

### 7.1 基本输出

```php
$output->writeln('Hello, World!');
```

### 7.2 格式化输出

```php
// 信息样式
$output->writeln('<info>Information message</info>');

// 注释样式
$output->writeln('<comment>Comment message</comment>');

// 错误样式
$output->writeln('<error>Error message</error>');
```

### 7.3 交互式输入

```php
$question = new Output\Question('Please enter your name: ');
$name = $output->ask($input, $question);
```

## 8. 命令执行流程

1. **配置阶段**：在`configure()`方法中配置命令信息
2. **初始化阶段**：在`initialize()`方法中进行初始化操作
3. **交互阶段**：在`interact()`方法中处理用户交互
4. **执行阶段**：在`execute()`方法中执行命令逻辑

## 9. 最佳实践

### 9.1 命令命名规范

- 使用冒号分隔命名空间和命令名（如：`make:command`）
- 命令名使用小写字母和下划线
- 保持命令名简洁明了

### 9.2 参数和选项设计

- 为所有参数和选项提供描述
- 合理设置参数的必需性
- 使用有意义的默认值

### 9.3 输出样式

- 使用适当的样式区分不同类型的输出
- 保持输出简洁明了
- 提供足够的信息帮助用户理解命令执行结果

## 10. 示例命令

### 10.1 简单命令示例

```php
<?php
declare(strict_types=1);
namespace Console\Command;

use \Console\{Command,Input,Output};

class HelloWorld extends Command
{
    protected function configure(): void
    {
        $this->setName('hello')
            ->setDescription('Say hello to the world');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('<info>Hello, World!</info>');
    }
}
```

### 10.2 带参数和选项的命令示例

```php
<?php
declare(strict_types=1);
namespace Console\Command;

use \Console\{Command,Input,Output};
use \Console\Input\Argument;
use \Console\Input\Option;

class Greet extends Command
{
    protected function configure(): void
    {
        $this->setName('greet')
            ->addArgument('name', Argument::REQUIRED, 'The name to greet')
            ->addOption('formal', 'f', Option::VALUE_NONE, 'Use formal greeting')
            ->setDescription('Greet someone');
    }

    protected function execute(Input $input, Output $output)
    {
        $name = $input->getArgument('name');
        $formal = $input->getOption('formal');
        
        if ($formal) {
            $output->writeln('<info>Dear ' . $name . ', how do you do?</info>');
        } else {
            $output->writeln('<info>Hello, ' . $name . '!</info>');
        }
    }
}
```

## 11. 常见问题

### 11.1 禁用函数检查失败

如果命令执行失败并显示禁用函数错误，需要确保以下函数在PHP配置中可用：

```
stream_socket_server, stream_socket_client, pcntl_signal_dispatch, pcntl_signal, pcntl_alarm, pcntl_fork,
posix_getuid, posix_getpwuid, posix_kill, posix_setsid, posix_getpid, posix_getpwnam, posix_getgrnam, posix_getgid,
posix_setgid, posix_initgroups, posix_setuid, posix_isatty
```

### 11.2 命令未找到

如果出现"Command not found"错误，可能是以下原因：
- 命令名称拼写错误
- 命令未正确注册
- 命名空间或类名错误

## 12. 总结

Console类库是Candy框架中功能强大的命令行工具集，它提供了丰富的功能和灵活的扩展性，使开发者能够轻松创建和管理命令行应用。通过本文档的介绍，您应该能够了解Console类库的基本使用方法和高级特性，并能够开始创建自己的命令行工具。
