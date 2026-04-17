# PHP Web 开发注意事项

> 基于 Simple Homepage 项目实战踩坑总结，适用于所有 PHP Web 项目。
> 每一条都是真实 Bug 的教训。

---

## 一、HTTP Header 输出顺序（最高频 Bug）

### 核心原则：任何 `header()`、`setcookie()`、`session_start()` 调用都必须在任何 HTML/文本输出之前执行

### 典型错误模式

```php
// ❌ 错误：先输出 HTML，再试图设置 header
require_once 'header.php'; // 已经输出了 <html><body>...

if ($_POST['action'] === 'delete') {
    do_delete();
    header('Location: list.php'); // 无效！浏览器已收到 HTML
    exit;
}
```

```php
// ✅ 正确：所有 header 操作在任何输出之前
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'functions.php'; // 只加载函数，不输出
    do_delete();
    header('Location: list.php'); // 有效
    exit;
}

$page_title = '列表';
require_once 'header.php'; // 现在才输出 HTML
```

### 受影响的操作

| 操作 | 必须在 HTML 输出之前 |
|------|--------------------|
| `header('Location: ...')` 重定向 | ✅ |
| `header('Content-Disposition: attachment')` 文件下载 | ✅ |
| `header('Content-Type: application/json')` | ✅ |
| `setcookie()` | ✅ |
| `session_start()` | ✅ |
| `http_response_code()` | ✅ |

### 受影响的典型场景

- **表单提交后重定向**（PRG 模式：Post-Redirect-Get）
- **文件下载**（导出 JSON、下载配置文件、下载备份）
- **AJAX JSON 响应**（Content-Type 必须在输出前设置）
- **登录/注册**（setcookie 设置 session）

---

## 二、Session 初始化时机

### 问题

`session_start()` 必须在 HTML 输出之前，且必须**早于任何读写 `$_SESSION` 的操作**。

```php
// ❌ 错误：session_start() 在 HTML 中间调用
?>
<html><head>...</head><body>
<?php
session_start(); // Warning: session_start() after headers sent
$token = $_SESSION['csrf_token']; // 读不到！
```

```php
// ✅ 正确：文件第一行就启动 session
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'auth.php';
// ...
```

### 使用 `session_status()` 的原因

```php
// ❌ 直接调用可能报 Warning（已经启动过）
session_start();

// ✅ 幂等调用，安全
if (session_status() === PHP_SESSION_NONE) session_start();
```

### 实际案例

`login.php` 和 `setup.php` 最初没有在顶部 `session_start()`，导致 `csrf_field()` 在 HTML 模板中调用时才触发 `session_start()`，此时 `Set-Cookie: PHPSESSID=...` 头无法发送，浏览器没有 session cookie，下次提交时 CSRF 验证失败（403）。

---

## 三、CSRF 防护

### 所有修改数据的操作都必须验证 CSRF Token

```php
// ✅ 标准 CSRF 保护模式
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_check(): void {
    $token    = $_POST['_csrf'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$expected || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('CSRF验证失败');
    }
}
```

### 需要 CSRF 保护的页面

- ✅ 所有后台管理页面（增删改操作）
- ✅ **login.php**（防止 CSRF 钓鱼登录攻击）
- ✅ **setup.php**（防止攻击者抢先完成安装）
- ✅ **logout.php**（防止 CSRF 强制退出）
- ⚠️ AJAX 请求也需要（通过 `X-Requested-With` 或 token 验证）

### AJAX 场景的 CSRF

```php
// AJAX 鉴权失败应返回 JSON 而非 302 重定向
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '未登录']);
    exit;
}
// 普通请求才重定向
header('Location: /login.php');
exit;
```

---

## 四、密钥与敏感配置

### 永远不要使用默认密钥

```php
// ❌ 危险：默认密钥写死在代码里
define('AUTH_SECRET_KEY', 'CHANGE_THIS_TO_A_RANDOM_64_CHAR_STRING_' . md5('your-secret'));

// ✅ 正确：部署时生成随机密钥
define('AUTH_SECRET_KEY', 'a7f3b2c9d1e4f8a2b6c0d5e3f7a1b4c8d2e6f0a3b7c1d5e9f2a6b0c4d8e2f6a0');
```

### 生成安全随机密钥

```bash
# 方法1：PHP
php -r "echo bin2hex(random_bytes(64));"

# 方法2：Python
python3 -c "import secrets; print(secrets.token_hex(64))"

# 方法3：OpenSSL
openssl rand -hex 64
```

### 密钥存储原则

- 不要提交到 Git（加入 `.gitignore`）
- 使用 `.env` 文件或环境变量
- Docker 使用 `secrets` 或环境变量注入

---

## 五、SSRF 防护（Server-Side Request Forgery）

### 当 PHP 代码会根据用户输入发起 HTTP 请求时，必须校验目标地址

```php
// ❌ 危险：直接用用户输入的 URL 发请求
$data = file_get_contents($_POST['url']);

// ✅ 正确：校验目标地址
function is_safe_request_target(string $url): bool {
    $parsed = parse_url($url);
    $host   = $parsed['host'] ?? '';
    if (!$host) return false;
    // 禁止内网 IP
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    // 明确禁止 loopback 和链路本地
    if (preg_match('/^(127\.|169\.254\.|::1|localhost)/i', $host)) return false;
    return true;
}
```

### 注意

`FILTER_FLAG_NO_RES_RANGE` 不覆盖 loopback，需要额外用正则明确拒绝 `127.x.x.x`。

---

## 六、XSS 防护

### 所有输出到 HTML 的用户数据都必须转义

```php
// ❌ 危险
echo $_GET['name'];
echo "<div>" . $user['bio'] . "</div>";

// ✅ 正确
echo htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');
echo "<div>" . htmlspecialchars($user['bio'], ENT_QUOTES, 'UTF-8') . "</div>";

// PHP 短标签也要转义
?><p><?= htmlspecialchars($name) ?></p><?php
```

### JSON 输出到 JavaScript 变量

```php
// ❌ 危险
echo "<script>var data = " . json_encode($data) . ";</script>";

// ✅ 正确：使用 JSON_HEX_TAG 防止 </script> 注入
echo "<script>var data = " . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . ";</script>";
```

---

## 七、文件路径安全

### 防止路径遍历攻击（Path Traversal）

```php
// ❌ 危险：直接用用户输入构造路径
$file = $_GET['filename'];
readfile('/var/www/data/' . $file); // 攻击者传 ../../etc/passwd

// ✅ 方法1：basename() 去掉路径分隔符
$file = basename($_GET['filename']);

// ✅ 方法2：正则白名单
if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $file)) {
    http_response_code(400); exit('Invalid filename');
}

// ✅ 方法3：realpath 验证在允许目录内
$base = realpath('/var/www/data/');
$path = realpath($base . '/' . $file);
if (!$path || strpos($path, $base) !== 0) {
    http_response_code(403); exit('Forbidden');
}
```

### 文件上传安全

```php
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) die('不允许的文件类型');
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) die('MIME不符');
$new_name = 'img_' . bin2hex(random_bytes(8)) . '.' . $ext;
move_uploaded_file($file['tmp_name'], '/var/www/uploads/' . $new_name);
```

---

## 八、Nginx + PHP-FPM 并发死锁

**现象**：POST 请求 pending，最终 504 超时。

**原因**：`auth_request` 使 Nginx 为每个请求发起子请求，主请求和子请求都需要 PHP-FPM worker，worker 耗尽时死锁。

**修复**：在 PHP location 内加 `auth_request off`，由 PHP 自行鉴权：

```nginx
location ^~ /admin/ {
    auth_request /auth/verify.php;
    location ~ \.php$ {
        auth_request off;  # 关键：PHP 自己处理鉴权
        fastcgi_pass unix:/run/php-fpm.sock;
        include fastcgi_params;
    }
}
```

**PHP 端**：AJAX 鉴权失败返回 JSON，不能 302 重定向：

```php
if (!auth_get_current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '未登录']);
    exit;
}
```

---

## 九、JSON 数据存储

```php
// 写入：保留 emoji，使用文件锁
file_put_contents('data.json',
    json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

// 读取：防空文件崩溃
$data = json_decode(file_exists('f.json') ? file_get_contents('f.json') : '{}', true) ?? [];
```

---

## 十、密码与 Token 安全

```php
// 密码：bcrypt
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
password_verify($input, $hash); // 验证

// Token 比较：防时序攻击
hash_equals($expected_token, $user_token); // 不用 ===
```

---

## 十一、PRG 模式（Post/Redirect/Get）

```php
// 所有 POST 处理在 require 'header.php' 之前
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    save_data($_POST);
    flash_set('success', '保存成功');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit; // 必须 exit
}
require_once 'header.php'; // 之后才输出 HTML
```

---

## 十二、开发环境常用检查清单

- [ ] `session_start()` 在文件最顶部
- [ ] `header()`/`setcookie()` 在任何 HTML 输出之前
- [ ] POST 处理在 `require 'header.php'` 之前
- [ ] 表单有 `csrf_field()`，POST 处理有 `csrf_check()`
- [ ] 输出变量用 `htmlspecialchars()` 转义
- [ ] 文件路径参数用 `basename()` 或正则白名单
- [ ] 密码用 `password_hash()`，比较用 `password_verify()`
- [ ] Token 比较用 `hash_equals()`
- [ ] JSON 写入用 `LOCK_EX`，读取有 `?? []` 默认值
- [ ] `AUTH_SECRET_KEY` 已替换为随机值
- [ ] nginx PHP location 有 `auth_request off`
- [ ] Docker volume 只挂载数据目录，不挂载源码目录
- [ ] 文件下载响应头在任何 HTML 输出之前

---

*文档版本：v2.0 | 整理自项目真实踩坑记录*
