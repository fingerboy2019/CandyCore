# Sms 短信发送服务使用文档

## 概述

Sms类是Candy框架中的短信发送服务类，提供基于多种服务提供商的短信发送功能，目前支持阿里云短信服务。
该类封装了短信发送的核心逻辑，包括签名生成、请求构建和结果处理，支持模板短信发送，可批量发送给多个手机号。

## 快速开始

### 引入命名空间

```php
use Extend\Sms;
```

### 发送单条短信

```php
// 发送单条短信
$result = Sms::aliYun([
    'number' => '13800138000',  // 接收短信的手机号
    'templateCode' => 'SMS_123456789',  // 短信模板ID
    'param' => ['code' => '123456']  // 模板参数
]);

if ($result) {
    echo '短信发送成功';
} else {
    echo '短信发送失败';
}
```

### 批量发送短信

```php
// 批量发送短信给多个手机号
$numbers = ['13800138000', '13900139000', '13700137000'];
$result = Sms::aliYun([
    'number' => $numbers,
    'templateCode' => 'SMS_123456789',
    'param' => ['code' => '123456']
]);
```

## 核心功能

### aliYun() - 阿里云短信发送

```php
/**
 * 阿里云云通信接口
 * 
 * 通过阿里云短信服务发送短信，支持单个或多个手机号，可配置模板参数
 * 该方法会处理请求签名生成、接口调用和结果解析等完整流程
 * 
 * @param array $data 短信发送参数，包含以下字段：
 *        - number: 手机号，可以是单个字符串或数组
 *        - templateCode: 短信模板ID
 *        - param: 模板参数，可选，关联数组格式
 * @return bool 发送成功返回true，失败返回false
 * @throws Exception 当配置不存在时会触发错误提示
 */
public static function aliYun(array $data = []): bool
```

**参数说明：**

| 参数名 | 类型 | 是否必填 | 说明 |
|--------|------|----------|------|
| number | string/array | 是 | 接收短信的手机号，可以是单个字符串或数组 |
| templateCode | string | 是 | 阿里云短信模板ID |
| param | array | 否 | 模板参数，关联数组格式 |

**返回值：**
- 发送成功返回 `true`
- 发送失败返回 `false`

**示例：**

```php
// 发送验证码短信
$verifyCode = rand(100000, 999999);
$result = Sms::aliYun([
    'number' => '13800138000',
    'templateCode' => 'SMS_987654321',  // 验证码模板
    'param' => ['code' => $verifyCode]
]);

// 发送通知短信
$result = Sms::aliYun([
    'number' => ['13800138000', '13900139000'],
    'templateCode' => 'SMS_112233445',  // 通知模板
    'param' => ['orderId' => 'ORD-20230615-001', 'amount' => '99.99']
]);
```

## 高级用法

### 配置管理

使用前需要确保系统配置中有以下阿里云短信相关配置：

```php
// 阿里云短信配置示例（通常在config目录下的配置文件中）
'sms' => [
    'aliYunAccessKeyID' => 'your_access_key_id',  // 阿里云AccessKey ID
    'aliYunAccessKeySecret' => 'your_access_key_secret',  // 阿里云AccessKey Secret
    'aliYunRegionId' => 'cn-hangzhou',  // 短信服务地域
    'aliYunSignName' => '您的短信签名'  // 短信签名
]
```

### 错误处理

```php
try {
    $result = Sms::aliYun([
        'number' => '13800138000',
        'templateCode' => 'SMS_123456789',
        'param' => ['code' => '123456']
    ]);
    
    if ($result) {
        echo '短信发送成功';
    } else {
        echo '短信发送失败，请稍后重试';
    }
} catch (Exception $e) {
    echo '发送失败：' . $e->getMessage();
}
```

## 注意事项

1. 使用前需确保已在阿里云平台申请并配置了有效的短信签名和短信模板
2. 批量发送短信时，建议合理控制单次发送的手机号数量，避免触发频率限制
3. 短信发送有频率限制，需注意阿里云短信服务的频率限制规则（通常是每秒10条）
4. 发送失败时，该方法返回false，可根据需要实现重试逻辑，建议使用指数退避策略
5. 请妥善保管阿里云AccessKey信息，避免泄露
6. 不同的短信模板可能需要不同的参数，使用前请确认模板参数的格式和要求
7. 建议记录短信发送日志，便于后期排查问题

## 版本信息

- 作者: 刘森 (fingerboy@qq.com)
- 创建日期: 2020-01-23 18:50:08
- 支持的服务提供商: 阿里云短信服务