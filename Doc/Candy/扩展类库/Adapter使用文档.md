# Adapter 使用文档

## 文档概述

Adapter类是Candy框架的系统函数重置适配器，**属于核心类库的补充类库**。该类用于管理和重置PHP内置系统函数，通过将原始PHP函数替换为框架自定义实现，实现对HTTP响应、Cookie、Session等核心功能的统一管理和扩展。

## 快速入门

Adapter类在框架初始化时自动调用，无需手动使用：

```php
// 框架内部自动调用，无需开发者手动执行
Adapter::init();
```

## 核心功能详解

### 系统函数重置机制

Adapter类的核心功能是将PHP内置的关键系统函数替换为框架自定义实现，确保框架能够统一管理和扩展这些功能。

### 主要方法

#### `init()`

```php
/**
 * 初始化适配器，检查函数禁用状态并加载自定义函数实现
 * 
 * @return void
 */
public static function init(): void
```

**功能说明**：
- 检查必须禁用的PHP原生函数是否已正确禁用
- 加载自定义系统函数实现（从Source目录）
- 加载框架提供的函数实现（从Candy目录）
- 初始化HTTP相关功能

**使用场景**：框架内部自动调用，无需开发者手动使用。

### 内部工作机制

Adapter类通过以下内部方法实现其核心功能：

#### `checkFunctionsDisabled()`

**功能说明**：
- 检查PHP内置函数是否已被正确禁用
- 从CANDYPATH/.reload文件中读取自定义函数列表（若文件存在）
- 将自定义函数与内置必须禁用函数合并
- 检查所有必要的函数是否已被禁用
- 若有函数未被禁用，收集并显示详细的配置错误信息

#### `showConfiguration()`

**功能说明**：
- 生成并返回适配器配置状态的详细信息
- 收集并格式化框架和PHP环境信息
- 提供必要的配置建议和修复方法

### 必须禁用的函数列表

Adapter类定义了以下必须被禁用并由框架重写的PHP内置函数：

```php
private const FUNCTIONS = [
    // HTTP相关函数
    'header', 'header_remove', 'headers_sent', 'http_response_code',
    // Cookie相关函数
    'setcookie',
    // Session相关函数
    'session_create_id', 'session_id', 'session_name', 'session_save_path', 
    'session_status', 'session_start', 'session_write_close', 'session_regenerate_id',
    // 执行时间控制
    'set_time_limit'
];
```

## 配置与依赖

### 配置要求

使用Adapter类前必须满足以下配置要求：

1. 在php.ini中禁用相关原始函数：

```ini
; 编辑php.ini文件，添加以下配置
; 需要禁用的函数列表
```

2. 确保框架有足够权限读取和写入相关配置文件

3. 自定义函数可以通过CANDYPATH/.reload文件添加（以逗号分隔）

### 依赖关系

- 框架核心配置
- PHP环境配置（php.ini）

## 注意事项

1. **权限要求**：必须确保框架有足够权限读取和写入相关配置文件

2. **配置错误风险**：配置错误可能导致框架功能异常或安全风险

3. **禁止继承**：本类为最终实现（final class），禁止继承

4. **函数禁用要求**：使用前必须在php.ini中禁用相关原始函数，通过disable_functions配置项

5. **自定义函数配置**：自定义函数列表必须通过CANDYPATH/.reload文件正确配置

## 最佳实践

1. **不要手动调用**：Adapter类的init()方法由框架自动调用，开发者不应手动调用

2. **正确配置php.ini**：确保在php.ini中正确配置disable_functions，禁用框架需要重写的函数

3. **定期检查配置**：定期检查Adapter类的配置状态，确保框架功能正常运行

4. **谨慎添加自定义函数**：添加自定义函数时要谨慎，确保它们不会与框架的功能冲突

## 常见问题

### Q: 为什么需要禁用这些PHP内置函数？

A: 禁用这些内置函数是为了让框架能够提供统一的管理和扩展机制，确保HTTP响应、Cookie、Session等核心功能的一致性和安全性。

### Q: 如何检查Adapter类的配置状态？

A: Adapter类会在框架初始化时自动检查配置状态，并在发现问题时显示详细的错误信息和修复建议。

### Q: 可以手动添加需要禁用的函数吗？

A: 可以通过在CANDYPATH/.reload文件中添加自定义函数列表（以逗号分隔）来扩展需要禁用的函数。

### Q: 禁用这些函数会影响其他PHP应用吗？

A: 如果您在同一服务器上运行多个PHP应用，禁用这些函数可能会影响其他不使用Candy框架的应用。建议为Candy框架应用单独配置PHP环境（如使用PHP-FPM池）。