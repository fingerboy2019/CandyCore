# JwtToken 使用指南

## 概述

`JwtToken` 是 Candy 框架的完整 JWT 认证类，集成了登录、验证、刷新、退出等所有认证功能。

### 核心特性

- ✅ Cookie 模式（HttpOnly Cookie 自动携带）
- ✅ Header 模式（Authorization: Bearer xxx）
- ✅ 单设备登录（后登录踢掉先登录）
- ✅ CSRF Token 防护
- ✅ Redis 黑名单（强制登出）
- ✅ 自动刷新 Access Token
- ✅ 非阻塞设计，验证失败返回 false
- ✅ 统一认证入口，一个类搞定所有认证需求

---

## 快速开始

### 1. 用户登录

```php
use Extend\JwtToken;

// 控制器中
function login() {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // 验证用户名密码
    $user = M('User')->where("username='$username'")->find();
    if (!$user || $user['password'] !== md5($password)) {
        $this->error('用户名或密码错误');
    }
    
    // 登录（生成 Token 并设置 Cookie）
    $result = JwtToken::login([
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role']
    ], 'WEB');
    
    $this->success($result['msg'], $result['data']);
}
```

### 2. 验证登录状态

```php
// 方式 1：公共控制器 init() 方法
class Common extends Base {
    function init() {
        $result = JwtToken::auth();
        if ($result['code'] !== 200) {
            // 未登录或登录失效
            if ($this->isAjax()) {
                $this->ajaxReturn(['code' => 401, 'msg' => $result['msg']]);
            } else {
                $this->redirect('Login/index');
            }
        }
        
        // 登录成功，可以获取用户信息
        $this->currentUser = $result['user'];
    }
}

// 方式 2：具体控制器中
function profile() {
    $result = JwtToken::auth(true); // true 表示必须登录
    
    if ($result['code'] === 401) {
        $this->error($result['msg']);
    }
    
    // 获取用户信息
    $userId = $result['user_id'];
    $user = $result['user'];
    
    $this->display();
}
```

### 3. 获取当前用户

```php
// 获取用户 ID
$userId = JwtToken::getCurrentId();

// 获取用户详细信息
$user = JwtToken::getUser();

// 获取 Token 扩展信息
$extend = JwtToken::getExtend();
$client = JwtToken::getExtendVal('client');
$role = JwtToken::getExtendVal('role');
```

### 4. 刷新 Token

```php
// API 接口：刷新 Access Token
function refreshToken() {
    $result = JwtToken::refresh();
    
    if ($result['code'] === 200) {
        $this->success($result['msg'], $result['data']);
    } else {
        $this->error($result['msg']);
    }
}
```

### 5. 退出登录

```php
function logout() {
    $result = JwtToken::logout();
    $this->success($result['msg']);
}
```

### 6. CSRF 验证

```php
// 表单提交时验证 CSRF Token
function save() {
    $csrfToken = $_POST['csrf_token'];
    
    if (!JwtToken::verifyCsrf($csrfToken)) {
        $this->error('CSRF 验证失败');
    }
    
    // 处理表单数据...
}
```

---

## 方法清单

### 公开方法

| 方法 | 说明 | 返回值 |
|------|------|--------|
| `login($user, $client, $accessExp, $refreshExp)` | 用户登录 | `array` |
| `auth($requireAuth)` | 验证认证状态 | `array` |
| `refresh()` | 刷新 Token | `array` |
| `logout()` | 退出登录 | `array` |
| `verifyCsrf($csrfToken)` | 验证 CSRF Token | `bool` |
| `getCurrentId()` | 获取当前用户 ID | `int` |
| `getUser()` | 获取当前用户信息 | `array` |
| `getExtend()` | 获取 Token 扩展信息 | `array` |
| `getExtendVal($key)` | 获取扩展字段值 | `mixed` |
| `getTokenExp($tokenType)` | 获取 Token 剩余时间 | `int` |
| `verify($tokenType, $token)` | 验证 JWT Token | `array\|bool` |
| `generateToken($extend)` | 生成 JWT Token | `array` |
| `refreshToken(&$extend)` | 刷新 JWT Token | `array` |
| `clear($client)` | 清除 Redis Token | `bool` |

---

## 配置说明

配置文件：`Source/Inc/Jwt.inc.php`

```php
return [
    'algorithms' => 'HS256',                    // 加密算法
    'access_secret_key' => 'your_secret_key',   // Access Token 密钥
    'access_exp' => 7200,                       // Access Token 过期时间（2小时）
    'refresh_secret_key' => 'your_refresh_key', // Refresh Token 密钥
    'refresh_exp' => 604800,                    // Refresh Token 过期时间（7天）
    'refresh_disable' => false,                 // 是否禁用 Refresh Token
    'iss' => 'www.example.com',                 // 签发者
    'nbf' => 0,                                 // 生效时间偏移
    'leeway' => 60,                             // 时间容差（秒）
    'is_single_device' => true,                 // 是否单设备登录
    'cache_token_pre' => 'JWT:TOKEN:',          // Redis Key 前缀
    'cache_refresh_token_pre' => 'JWT:REFRESH_TOKEN:',
    'token_mode' => 'cookie',                   // cookie 或 header
    'access_cookie_name' => 'access_token',     // Cookie 名称
    'refresh_cookie_name' => 'refresh_token',
    'user_model' => function ($uid) {           // 用户模型回调
        return D('User')->where("id=$uid")->find();
    },
];
```

---

## 单设备登录机制

### 工作原理

```
用户A在设备1登录 → Token_A 存入 Redis
  ↓
用户A在设备2登录 → Token_B 覆盖 Redis
  ↓
设备1访问 → 对比 Redis → Token_A != Token_B → 验证失败
  ↓
自动清除 Cookie → 跳转登录页
```

### 关闭单设备登录

```php
'is_single_device' => false,  // 允许同时登录多个设备
```

---

## 常见问题

### Q1: 如何区分"过期"和"被踢下线"？

```php
$result = JwtToken::auth();
if ($result['code'] === 401) {
    $msg = $result['msg']; 
    // '未登录' - 没有 Token
    // '登录已过期' - Token 过期
    // '账号已在其他设备登录' - 被踢下线
    // '令牌已被吊销，请重新登录' - 主动登出
}
```

### Q2: 如何在 API 中使用 Header 模式？

```php
// 配置文件中
'token_mode' => 'header',

// 客户端请求头
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

### Q3: 如何强制用户下线？

```php
// 加入黑名单
$cache = Cache::store();
$cache->set('auth:blacklist:' . md5($token), 1, 7200);

// 清除 Redis Token
JwtToken::clear('WEB');
```

---

## 使用示例

### 完整控制器示例

```php
namespace APP\Control\Home;

use Extend\JwtToken;

class Common extends Base {
    
    function init() {
        // 验证登录状态
        $auth = JwtToken::auth(true);
        if ($auth['code'] !== 200) {
            if ($this->isAjax()) {
                $this->ajaxReturn([
                    'code' => 401,
                    'msg' => $auth['msg'],
                    'need_login' => true
                ]);
            } else {
                $this->redirect('Login/index');
            }
        }
        
        // 设置当前用户信息
        $this->currentUser = $auth['user'];
        $this->userId = $auth['user_id'];
    }
}

class User extends Common {
    
    function index() {
        // 已验证登录，直接使用
        $userId = $this->userId;
        $user = $this->currentUser;
        
        $this->assign('user', $user);
        $this->display();
    }
    
    function profile() {
        if (IS_POST) {
            // 验证 CSRF
            if (!JwtToken::verifyCsrf($_POST['csrf_token'])) {
                $this->error('CSRF 验证失败');
            }
            
            // 更新用户信息
            $data = [
                'nickname' => $_POST['nickname'],
                'phone' => $_POST['phone']
            ];
            
            D('User')->where("id={$this->userId}")->save($data);
            $this->success('更新成功');
        }
        
        // 获取 CSRF Token
        $this->assign('csrf_token', JwtToken::getExtendVal('csrf_token'));
        $this->display();
    }

    function logout() {
        $result = JwtToken::logout();
        $this->success($result['msg'], U('Login/index'));
    }
}
```

---

## 迁移指南（从 HybridAuth 迁移到 JwtToken）

### 变更说明

自 v2.0 起，`HybridAuth` 类已合并到 `JwtToken` 类中，所有功能统一使用 `JwtToken` 类。

### 对照表

| 旧方法 (HybridAuth) | 新方法 (JwtToken) | 说明 |
|---------------------|------------------|------|
| `HybridAuth::login()` | `JwtToken::login()` | 登录 |
| `HybridAuth::verify()` | `JwtToken::auth()` | 验证认证状态 |
| `HybridAuth::refreshToken()` | `JwtToken::refresh()` | 刷新 Token |
| `HybridAuth::logout()` | `JwtToken::logout()` | 退出登录 |
| `HybridAuth::verifyCsrf()` | `JwtToken::verifyCsrf()` | 验证 CSRF |
| `HybridAuth::getUser()` | `JwtToken::getUser()` | 获取用户信息 |
| `HybridAuth::getCurrentId()` | `JwtToken::getCurrentId()` | 获取用户 ID |

### 代码迁移示例

#### 登录控制器

```php
// 旧代码
use APP\Extend\HybridAuth;
$result = HybridAuth::login($user, 'WEB', 7200, 604800);

// 新代码
use Extend\JwtToken;
$result = JwtToken::login($user, 'WEB', 7200, 604800);
```

#### 验证登录状态

```php
// 旧代码
$result = HybridAuth::verify(true);

// 新代码
$result = JwtToken::auth(true);
```

#### 刷新 Token

```php
// 旧代码
$result = HybridAuth::refreshToken();

// 新代码
$result = JwtToken::refresh();
```

#### 退出登录

```php
// 旧代码
$result = HybridAuth::logout();

// 新代码
$result = JwtToken::logout();
```

### 中间件变更

| 旧类 | 新类 |
|------|------|
| `HybridAuthMiddleware` | `JwtAuthMiddleware` |

中间件配置更新：

```php
// 旧配置
'Before' => [
    'APP\Extend\HybridAuthMiddleware',
],

// 新配置
'Before' => [
    'APP\Extend\JwtAuthMiddleware',
],
```

### 已删除的文件

- `Source/Class/HybridAuth.class.php` - 已删除，功能合并到 JwtToken
- `Source/Class/HybridAuthMiddleware.class.php` - 已重命名为 JwtAuthMiddleware.class.php
