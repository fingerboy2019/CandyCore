# Mail 邮件接收类

## 类库介绍

`Mail` 类是一个功能强大的邮件接收工具，支持 POP3 和 IMAP 两种邮件接收协议，为应用程序提供完整的邮件接收能力。

### 主要功能

- ✅ 支持 POP3 和 IMAP 两种邮件接收协议
- ✅ 支持 SSL/TLS 加密连接
- ✅ 支持邮件列表获取和邮件内容读取
- ✅ 支持邮件删除和标记操作
- ✅ 提供完整的错误处理和日志记录
- ✅ 支持自定义超时设置

## 安装和配置

### 基本使用

```php
use Candy\Extend\Network\Mail\Client;

// 创建邮件接收实例（POP3协议）
$mailbox = new Mail(
    'pop.example.com',  // 邮件服务器地址
    995,                // 端口（POP3 SSL）
    'pop3',             // 协议类型
    true,               // 使用SSL
    'user@example.com', // 用户名
    'password'          // 密码
);

// 创建邮件接收实例（IMAP协议）
$mailbox = new Mail(
    'imap.example.com', // 邮件服务器地址
    993,                // 端口（IMAP SSL）
    'imap',             // 协议类型
    true,               // 使用SSL
    'user@example.com', // 用户名
    'password'          // 密码
);
```

## 主要方法

### 连接和认证

#### `connect()`

**功能**：连接到邮件服务器

**返回值**：`bool` - 连接成功返回 `true`，失败返回 `false`

**使用示例**：

```php
if ($mailbox->connect()) {
    echo "成功连接到邮件服务器\n";
} else {
    echo "连接失败: " . $mailbox->getError() . "\n";
}
```

#### `login()`

**功能**：使用用户名和密码进行邮件服务器认证

**返回值**：`bool` - 认证成功返回 `true`，失败返回 `false`

**使用示例**：

```php
if ($mailbox->login()) {
    echo "认证成功\n";
} else {
    echo "认证失败: " . $mailbox->getError() . "\n";
}
```

### 邮件操作

#### `getMailList()`

**功能**：获取邮箱中的邮件列表

**参数**：
- `$limit` (int) - 限制返回的邮件数量，默认为 0（返回所有）

**返回值**：`array` - 邮件列表数组，每个元素包含邮件的基本信息

**使用示例**：

```php
$mails = $mailbox->getMailList(10); // 获取最近10封邮件
echo "共找到 " . count($mails) . " 封邮件\n";

foreach ($mails as $mail) {
    echo "邮件ID: " . $mail['id'] . ", 大小: " . $mail['size'] . " bytes\n";
}
```

#### `getMail()`

**功能**：根据邮件ID获取邮件的详细内容

**参数**：
- `$mailId` (int) - 邮件ID

**返回值**：`array` - 邮件内容数组，包含邮件的详细信息

**使用示例**：

```php
$mail = $mailbox->getMail(1); // 获取第一封邮件

if ($mail) {
    echo "主题: " . $mail['subject'] . "\n";
    echo "发件人: " . $mail['from'] . "\n";
    echo "收件人: " . $mail['to'] . "\n";
    echo "日期: " . $mail['date'] . "\n";
    echo "内容: " . $mail['body'] . "\n";
}
```

#### `deleteMail()`

**功能**：根据邮件ID删除邮件

**参数**：
- `$mailId` (int) - 邮件ID

**返回值**：`bool` - 删除成功返回 `true`，失败返回 `false`

**使用示例**：

```php
if ($mailbox->deleteMail(1)) {
    echo "邮件删除成功\n";
} else {
    echo "邮件删除失败: " . $mailbox->getError() . "\n";
}
```

#### `close()`

**功能**：关闭与邮件服务器的连接

**返回值**：`bool` - 关闭成功返回 `true`，失败返回 `false`

**使用示例**：

```php
$mailbox->close();
echo "连接已关闭\n";
```

## 完整使用示例

### POP3邮件接收示例

```php
use Candy\Extend\Network\Mail\Client;

// 配置
$config = [
    'host' => 'pop.qq.com',
    'port' => 995,
    'protocol' => 'pop3',
    'ssl' => true,
    'username' => 'your_email@qq.com',
    'password' => 'your_password' // QQ邮箱需要使用授权码
];

// 创建实例
$mailbox = new Mail(
    $config['host'],
    $config['port'],
    $config['protocol'],
    $config['ssl'],
    $config['username'],
    $config['password']
);

// 开启调试
$mailbox->debug = true;

// 连接服务器
if (!$mailbox->connect()) {
    die("连接失败: " . $mailbox->getError());
}

// 认证
if (!$mailbox->login()) {
    die("认证失败: " . $mailbox->getError());
}

// 获取邮件列表
$mails = $mailbox->getMailList(5);
echo "共找到 " . count($mails) . " 封邮件\n\n";

// 读取每封邮件
foreach ($mails as $mailInfo) {
    echo "====================================\n";
    echo "邮件ID: " . $mailInfo['id'] . "\n";
    
    // 获取邮件详情
    $mail = $mailbox->getMail($mailInfo['id']);
    if ($mail) {
        echo "主题: " . $mail['subject'] . "\n";
        echo "发件人: " . $mail['from'] . "\n";
        echo "日期: " . $mail['date'] . "\n";
        echo "内容预览: " . substr($mail['body'], 0, 100) . "...\n";
    }
    echo "====================================\n\n";
}

// 关闭连接
$mailbox->close();
echo "操作完成\n";
```

### IMAP邮件接收示例

```php
use Candy\Extend\Network\Mail\Client;

// 配置
$config = [
    'host' => 'imap.163.com',
    'port' => 993,
    'protocol' => 'imap',
    'ssl' => true,
    'username' => 'your_email@163.com',
    'password' => 'your_password' // 163邮箱需要使用授权码
];

// 创建实例
$mailbox = new Mail(
    $config['host'],
    $config['port'],
    $config['protocol'],
    $config['ssl'],
    $config['username'],
    $config['password']
);

// 连接服务器
if (!$mailbox->connect()) {
    die("连接失败: " . $mailbox->getError());
}

// 认证
if (!$mailbox->login()) {
    die("认证失败: " . $mailbox->getError());
}

// 获取邮件列表
$mails = $mailbox->getMailList(3);
echo "共找到 " . count($mails) . " 封邮件\n\n";

// 读取最新邮件
if (!empty($mails)) {
    $latestMail = end($mails);
    $mail = $mailbox->getMail($latestMail['id']);
    
    if ($mail) {
        echo "最新邮件信息:\n";
        echo "主题: " . $mail['subject'] . "\n";
        echo "发件人: " . $mail['from'] . "\n";
        echo "收件人: " . $mail['to'] . "\n";
        echo "日期: " . $mail['date'] . "\n";
        echo "内容: " . $mail['body'] . "\n";
    }
}

// 关闭连接
$mailbox->close();
echo "操作完成\n";
```

## 注意事项

1. **安全注意事项**：
   - 密码存储：避免在代码中硬编码密码，建议使用配置文件或环境变量
   - 授权码：许多邮件服务提供商（如QQ、163邮箱）需要使用授权码而非登录密码
   - SSL连接：建议始终使用SSL连接以保护数据传输安全

2. **性能和可靠性**：
   - 超时设置：根据网络环境调整超时时间，避免连接超时
   - 错误处理：实现完善的错误处理机制，应对网络波动等情况
   - 批量操作：对于大量邮件，建议分批处理，避免内存占用过高

3. **邮件服务器兼容性**：
   - 不同邮件服务器的POP3/IMAP实现可能存在细微差异
   - 部分服务器可能限制并发连接数或请求频率
   - 建议在正式环境前进行充分测试

4. **常见问题**：
   - 连接失败：检查服务器地址、端口、SSL设置是否正确
   - 认证失败：确认用户名和密码（授权码）是否正确
   - 邮件获取失败：检查服务器空间是否充足，权限是否正确

## 故障排除

### 常见错误及解决方案

| 错误信息 | 可能原因 | 解决方案 |
|---------|---------|--------|
| 连接失败: Connection refused | 服务器地址或端口错误 | 检查邮件服务器地址和端口设置 |
| 认证失败: Invalid credentials | 用户名或密码错误 | 确认用户名和密码（授权码）是否正确 |
| 认证失败: Authentication failed | 邮箱未开启POP3/IMAP服务 | 在邮箱设置中开启POP3/IMAP服务 |
| 获取邮件列表失败: -ERR | 服务器错误或权限不足 | 检查邮箱状态和权限设置 |
| 连接超时 | 网络问题或服务器响应慢 | 增加超时时间，检查网络连接 |

### 调试技巧

1. **开启调试模式**：
   ```php
   $mailbox->debug = true;
   ```

2. **设置日志文件**：
   ```php
   $mailbox->logFile = '/path/to/mailbox.log';
   ```

3. **检查错误信息**：
   ```php
   if (!$result) {
       echo "错误: " . $mailbox->getError();
   }
   ```

4. **网络测试**：
   ```bash
   # 测试服务器连接
   telnet pop.example.com 995
   
   # 测试SSL连接
   openssl s_client -connect pop.example.com:995
   ```

## 总结

`Mail` 类为应用程序提供了完整的邮件接收功能，支持 POP3 和 IMAP 两种协议，满足各种邮件接收场景的需求。通过简单的 API 调用，开发者可以轻松实现邮件的获取、读取和管理操作。

使用时请注意配置正确的服务器参数，实现适当的错误处理，并遵循邮件服务提供商的使用规范，以确保邮件接收功能的稳定运行。