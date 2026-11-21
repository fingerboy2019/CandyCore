# Pay 使用文档

## 概述

Pay类是Candy框架中的支付工具类，提供统一的支付接口封装，支持支付宝和微信支付。主要功能包括生成支付链接、支付结果验证、错误处理和日志记录等。

**注意：** 此类属于核心补充类库，是Candy框架核心功能的扩展。

## 快速入门

### 基本使用流程

```php
// 生成支付宝支付链接
$payUrl = Pay::aliPay([
    'id' => 'ORDER123456',  // 商户订单号
    'title' => '测试订单',  // 订单标题
    'total' => 100,         // 订单金额，单位：分
]);

// 生成微信扫码支付二维码链接
$wxNativePay = Pay::wxPay([
    'id' => 'ORDER123456',
    'title' => '测试订单',
    'total' => 100,
    'mode' => 'NATIVE'
]);
```

## 核心功能详解

### 1. 支付宝支付

#### 普通支付
```php
/**
 * 生成支付宝支付链接
 *
 * @param array $data 支付参数数组
 * @return string 支付宝支付链接
 */
$payUrl = Pay::aliPay([
    'id' => 'ORDER123456',    // 商户订单号
    'title' => '测试订单',    // 订单标题
    'total' => 100,           // 订单金额（单位：分）
]);
```

#### 二维码支付
```php
/**
 * 生成支付宝二维码支付链接
 *
 * @param array $data 支付参数数组，包含二维码配置
 * @return string 支付宝二维码支付链接
 */
$qrPayUrl = Pay::aliPay([
    'id' => 'ORDER123456',
    'title' => '测试订单',
    'total' => 100,
    'qr' => [
        'width' => 200  // 二维码宽度（可选）
    ]
]);
```

### 2. 微信支付

#### 原生扫码支付（NATIVE）
```php
/**
 * 生成微信原生扫码支付二维码链接
 *
 * @param array $data 支付参数数组
 * @return string 微信支付二维码链接
 */
$wxNativePay = Pay::wxPay([
    'id' => 'ORDER123456',
    'title' => '测试订单',
    'total' => 100,
    'mode' => 'NATIVE'  // 原生扫码支付模式
]);
```

#### JSAPI支付
```php
/**
 * 生成微信JSAPI支付参数
 * 注意：JSAPI模式必须提供用户的openid
 *
 * @param array $data 支付参数数组，包含openid
 * @return string 微信JSAPI支付prepay_id
 */
$wxJsapiPay = Pay::wxPay([
    'id' => 'ORDER123456',
    'title' => '测试订单',
    'total' => 100,
    'mode' => 'JSAPI',  // JSAPI支付模式
    'openid' => 'USER_OPENID'  // 用户openid（必需）
]);
```

#### H5支付
```php
/**
 * 生成微信H5支付链接
 *
 * @param array $data 支付参数数组
 * @return string 微信H5支付链接
 */
$wxMwebPay = Pay::wxPay([
    'id' => 'ORDER123456',
    'title' => '测试订单',
    'total' => 100,
    'mode' => 'MWEB'  // H5支付模式
]);
```

### 3. 支付结果验证

#### 支付宝异步通知验签
```php
/**
 * 支付宝异步通知验签
 *
 * @param array $data 支付宝密钥配置
 * @return bool 验签结果
 */
$aliVerifyResult = Pay::aliPayVerify([
    'alikey' => '支付宝密钥'
]);

if ($aliVerifyResult) {
    // 验签成功，处理订单
    $orderId = I('post.out_trade_no');
    // 更新订单状态等操作
}
```

#### 微信异步通知验签
```php
/**
 * 微信异步通知验签
 *
 * @param string $xmlData 微信支付通知的XML数据
 * @return bool 验签结果
 */
$xmlData = file_get_contents('php://input');
$wxVerifyResult = Pay::wxPayVerify($xmlData);

if ($wxVerifyResult) {
    // 验签成功，处理订单
    $xmlArray = json_decode(json_encode(simplexml_load_string($xmlData,'SimpleXMLElement',LIBXML_NOCDATA)),TRUE);
    $orderId = $xmlArray['out_trade_no'];
    // 更新订单状态等操作
}
```

## 注意事项

1. **配置要求**：使用前需确保已正确配置支付参数，包括商户ID、密钥等信息
2. **金额单位**：所有金额参数的单位为分，请确保传递正确的金额格式
3. **微信JSAPI支付**：JSAPI模式必须提供用户的openid
4. **异步通知**：支付结果验证建议在异步通知接口中使用，确保支付结果的可靠性
5. **类特性**：该类为final类，不允许继承和重写

## 常见问题

1. **支付链接生成失败**
   - 检查支付配置是否正确
   - 确认订单号是否唯一
   - 验证金额格式是否为整数（单位：分）

2. **验签失败**
   - 检查密钥配置是否正确
   - 确认通知数据是否完整未被篡改
   - 验证支付平台配置的回调URL是否与实际使用的一致

## 代码示例

### 完整的支付流程示例

```php
// 1. 生成支付链接
$payMethod = 'alipay'; // 或 'wechat'

if ($payMethod == 'alipay') {
    $payUrl = Pay::aliPay([
        'id' => 'ORDER_' . date('YmdHis') . rand(1000, 9999),
        'title' => '会员充值',
        'total' => 5000, // 50元
    ]);
    // 跳转到支付宝支付页面
    header('Location: ' . $payUrl);
} else {
    $payResult = Pay::wxPay([
        'id' => 'ORDER_' . date('YmdHis') . rand(1000, 9999),
        'title' => '会员充值',
        'total' => 5000,
        'mode' => 'NATIVE',
    ]);
    // 显示微信支付二维码
    echo '<img src="' . $payResult . '" />';
}
```

### 完整的异步通知处理示例

```php
// 支付宝异步通知处理
if (isset($_POST['trade_status'])) {
    $verifyResult = Pay::aliPayVerify([
        'alikey' => '支付宝密钥'
    ]);
    
    if ($verifyResult) {
        $orderId = $_POST['out_trade_no'];
        $tradeNo = $_POST['trade_no'];
        $tradeStatus = $_POST['trade_status'];
        
        if ($tradeStatus == 'TRADE_SUCCESS') {
            // 支付成功，更新订单状态
            updateOrderStatus($orderId, 'paid', ['trade_no' => $tradeNo]);
            echo 'success';
        }
    } else {
        echo 'fail';
    }
    exit;
}

// 微信异步通知处理
$content = file_get_contents('php://input');
if (!empty($content)) {
    $verifyResult = Pay::wxPayVerify($content);
    
    if ($verifyResult) {
        $xmlArray = json_decode(json_encode(simplexml_load_string($content,'SimpleXMLElement',LIBXML_NOCDATA)),TRUE);
        $orderId = $xmlArray['out_trade_no'];
        $tradeNo = $xmlArray['transaction_id'];
        $resultCode = $xmlArray['result_code'];
        
        if ($resultCode == 'SUCCESS') {
            // 支付成功，更新订单状态
            updateOrderStatus($orderId, 'paid', ['trade_no' => $tradeNo]);
            echo '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        }
    } else {
        echo '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[验证失败]]></return_msg></xml>';
    }
    exit;
}
```
