# API 接口文档（开发用）

> 本文档面向开发维护人员，描述 Simple Homepage 后端 HTTP API 接口的调用方式、参数及响应格式。不面向终端用户。
>
> 如需查阅前端 Ace 文本编辑器组件接口，请见同目录下的 `Ace编辑器接口说明（开发用）.md`。

---

## 目录

1. [API Token 使用说明](#第一章-api-token-使用说明)
2. [DNS 解析 API 接口说明](#第二章-dns-解析-api-接口说明)

---

## 第一章 API Token 使用说明

### 1.1 Token 格式

API Token 为不透明共享密钥，格式如下：

- 前缀：`np_`
- 主体：64 位十六进制字符（`bin2hex(random_bytes(32))` 生成）
- 示例：`np_a1b2c3d4e5f6...`（共 67 个字符）

Token 存储在 `data/api_tokens.json` 中，以明文形式保存，无过期时间，长期有效直至被显式删除。

### 1.2 Token 管理

管理页面位于 `admin/api_tokens.php`，仅 `admin` 角色可访问。

| 操作 | 方法 | 参数 | 说明 |
|------|------|------|------|
| 生成 Token | POST | `action=generate_api_token`、`token_name` | 创建新 Token，写入 `api_tokens.json` |
| 删除 Token | POST | `action=delete_api_token`、`token` | 从 `api_tokens.json` 移除指定 Token |

Token 数据格式（`data/api_tokens.json`）：

```json
{
  "np_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx": {
    "name": "home-assistant",
    "created_at": "2026-05-01T12:00:00+08:00"
  }
}
```

### 1.3 认证方式

目前仅 `public/api/sites.php` 支持 API Token 认证。支持两种传参方式：

**方式一：HTTP Authorization Header（推荐）**

```http
GET /api/sites.php HTTP/1.1
Authorization: Bearer np_xxxxxxxx...
```

**方式二：URL Query Parameter**

```http
GET /api/sites.php?token=np_xxxxxxxx...
```

> **注意**：Header 方式优先级高于 URL 参数。若同时存在，以 Header 中的 Token 为准。

### 1.4 验证逻辑

验证由 `api_token_verify($token)` 完成（位于 `admin/shared/functions.php`）：

1. 空 Token → 返回 `false`
2. 读取 `data/api_tokens.json`
3. `isset($tokens[$token])` 简单查找
4. **无签名验证**、**无过期检查**、**无权限分级** —— 所有有效 Token 权限等同

验证失败返回 HTTP 401：

```json
{
  "ok": false,
  "msg": "无效的 API Token"
}
```

### 1.5 请求与响应示例

**请求：**

```http
GET /api/sites.php HTTP/1.1
Host: 127.0.0.1:58080
Authorization: Bearer np_a1b2c3d4e5f6...
```

**成功响应（HTTP 200）：**

```json
{
  "ok": true,
  "site_name": "导航中心",
  "groups": [
    {
      "id": "default",
      "name": "我的应用",
      "icon": "🌐",
      "order": 0,
      "auth_required": true,
      "visible_to": "all",
      "sites": [
        {
          "id": "site_xxx",
          "name": "NAS",
          "url": "https://nas.local",
          "icon": "💾",
          "description": "家庭 NAS",
          "group_id": "default",
          "order": 0
        }
      ]
    }
  ]
}
```

> **数据权限说明**：`sites.php` 返回全部站点数据，不过滤权限。API 消费端需自行根据 `auth_required`、`visible_to` 等字段处理访问控制。

### 1.6 与其他认证体系的关系

| 认证方式 | 用途 | 存储位置 | 说明 |
|----------|------|----------|------|
| API Token | 程序化 API 访问 | `data/api_tokens.json` | 本文档所述；`sites.php` 和 `dns.php` 均支持 |
| Session JWT | Web 登录会话 | Cookie (`nav_session`) | `shared/auth.php` 管理，用于前后台页面 |
| Nginx auth_request | 反向代理子站鉴权 | Cookie | `public/auth/verify.php` 验证 Session JWT |

API Token 与 Session JWT 完全独立，API Token 不关联任何用户。DNS API 外网访问时仅认 API Token，不认 Session Cookie。

---

## 第二章 DNS 解析 API 接口说明

> **重要**：DNS API **默认仅限本机内部访问**，同时也支持通过 API Token 进行外网访问。部署时若需开放公网，请确保仅通过 HTTPS 暴露，并妥善保管 API Token。

### 2.1 访问控制

入口文件：`public/api/dns.php`

#### 方式一：本机免 Token 访问（向后兼容）

来自本机的请求无需 Token，直接放行。IP 白名单由 `dns_api_is_localhost()` 判定（位于 `admin/shared/dns_api_lib.php`），允许的源地址：

- `127.0.0.1`
- `::1`
- `192.168.65.1`（Docker Desktop 网关）

检查维度包括：`REMOTE_ADDR`、`SERVER_ADDR`、`HTTP_X_FORWARDED_FOR`。

#### 方式二：外网 Token 认证

非本机访问需提供有效的 API Token，认证方式与 `sites.php` 完全一致：

**HTTP Authorization Header（推荐）**

```http
GET /api/dns.php?action=query&domain=www.example.com&type=A HTTP/1.1
Authorization: Bearer np_xxxxxxxx...
```

**URL Query Parameter**

```http
GET /api/dns.php?action=query&domain=www.example.com&type=A&token=np_xxxxxxxx... HTTP/1.1
```

> Header 方式优先级高于 URL 参数。若同时存在，以 Header 中的 Token 为准。

Token 验证失败返回 HTTP 401：

```json
{
  "code": -1,
  "msg": "无效的 API Token"
}
```

### 2.2 请求方式

仅支持 `GET` 和 `POST`。

POST 支持两种 `Content-Type`：
- `application/x-www-form-urlencoded`
- `application/json`

GET 和 POST 参数通过 `dns_api_get_merged_input()` 合并，POST 字段覆盖 GET 字段。

### 2.3 接口概览

| Action | 方法 | 说明 | 核心参数 |
|--------|------|------|----------|
| `query` | GET/POST | 查询指定域名的 DNS 记录 | `domain`、`type`（可选） |
| `update` | GET/POST | 单条记录的增删改（Upsert） | `domain`、`value`、`type`（可选）、`ttl`（可选） |
| `batch_update` | GET/POST | 批量 Upsert 多条记录 | `domains`、`value`、`type`（可选）、`ttl`（可选） |

### 2.4 Action: query

查询当前 DNS 服务商上某个域名的解析记录。

**请求参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `action` | string | 是 | 固定为 `query` |
| `domain` | string | 是 | 待查询的域名，如 `www.example.com` |
| `type` | string | 否 | 记录类型：`A`、`AAAA`、`CNAME`。留空则查询所有类型 |

**请求示例：**

```http
GET /api/dns.php?action=query&domain=www.example.com&type=A HTTP/1.1
```

**响应示例（成功）：**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "fqdn": "www.example.com",
    "zone": "example.com",
    "record_name": "www",
    "matches": [
      {
        "id": "123456789",
        "type": "A",
        "value": "1.2.3.4",
        "ttl": 600,
        "proxied": false
      }
    ],
    "records": []
  }
}
```

- `matches`：与查询类型匹配的记录列表
- `records`：同 Zone 下其他记录的别名（当前实现为空数组，供扩展使用）
- `zone`：自动匹配到的 DNS Zone
- `record_name`：相对于 Zone 的子域名部分

**错误响应：**

```json
{
  "code": -1,
  "msg": "域名未匹配到任何已配置的 DNS 账号下的 Zone"
}
```

### 2.5 Action: update

单条记录的 Upsert 操作：
- 若记录已存在且值有变化 → **更新**
- 若记录不存在 → **创建**
- 若记录已存在且值无变化 → **跳过**

**请求参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `action` | string | 是 | 固定为 `update` |
| `domain` | string | 是 | 完整域名，如 `www.example.com` |
| `value` | string | 是 | 记录值，如 `1.2.3.4` 或 `alias.example.com` |
| `type` | string | 否 | `A` / `AAAA` / `CNAME`。留空则自动推断 |
| `ttl` | int | 否 | TTL 秒数。留空则使用服务商默认值 |

**类型自动推断规则**（`dns_api_infer_type()`）：

| 值特征 | 推断类型 |
|--------|----------|
| 符合 IPv4 格式 | `A` |
| 符合 IPv6 格式 | `AAAA` |
| 其他 | `CNAME` |

**TTL 规则：**

| 服务商 | 规则 |
|--------|------|
| Aliyun | 强制限制在 `600 ~ 86400` |
| Cloudflare | `1` 表示自动；否则限制在 `60 ~ 86400` |
| 默认 | `60 ~ 86400` |

**请求示例：**

```http
POST /api/dns.php HTTP/1.1
Content-Type: application/x-www-form-urlencoded

action=update&domain=www.example.com&value=1.2.3.4&type=A&ttl=600
```

**响应示例（创建）：**

```json
{
  "code": 0,
  "msg": "ok，已创建",
  "data": {
    "action": "create",
    "fqdn": "www.example.com",
    "type": "A",
    "record_id": "123456789"
  }
}
```

**响应示例（更新）：**

```json
{
  "code": 0,
  "msg": "ok，已更新",
  "data": {
    "action": "update",
    "fqdn": "www.example.com",
    "type": "A",
    "record_id": "123456789"
  }
}
```

**响应示例（跳过）：**

```json
{
  "code": 0,
  "msg": "ok，记录值未变化，跳过",
  "data": {
    "action": "skip",
    "fqdn": "www.example.com",
    "type": "A"
  }
}
```

### 2.6 Action: batch_update

批量对多个域名执行 Upsert，所有域名指向同一个值（或各自指定值）。

**请求参数：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `action` | string | 是 | 固定为 `batch_update` |
| `domains` | string/array | 是 | 域名列表。GET 方式用逗号分隔字符串；POST JSON 方式用数组 |
| `value` | string | 是 | 默认记录值 |
| `type` | string | 否 | 默认记录类型，留空自动推断 |
| `ttl` | int | 否 | 默认 TTL |

**最大批量数**：100 条（`DNS_API_BATCH_MAX`）。

**GET 请求示例：**

```http
GET /api/dns.php?action=batch_update&value=1.2.3.4&domains=example.com,www.example.com,blog.example.com
```

**POST JSON 请求示例：**

```http
POST /api/dns.php HTTP/1.1
Content-Type: application/json

{
  "action": "batch_update",
  "value": "1.2.3.4",
  "domains": [
    "example.com",
    "www.example.com",
    "blog.example.com"
  ],
  "type": "A",
  "ttl": 600
}
```

**单域名覆写示例**（每个域名可独立指定 `value`、`type`、`ttl`）：

```json
{
  "action": "batch_update",
  "domains": [
    { "domain": "example.com", "value": "1.2.3.4", "type": "A" },
    { "domain": "www.example.com", "value": "2.3.4.5", "type": "A", "ttl": 300 }
  ]
}
```

**响应示例：**

```json
{
  "code": 0,
  "msg": "批量完成：共 3 条成功",
  "results": [
    {
      "domain": "example.com",
      "code": 0,
      "msg": "ok，已更新",
      "data": { "action": "update", "fqdn": "example.com", "type": "A" }
    },
    {
      "domain": "www.example.com",
      "code": 0,
      "msg": "ok，已创建",
      "data": { "action": "create", "fqdn": "www.example.com", "type": "A" }
    },
    {
      "domain": "bad.example.com",
      "code": -1,
      "msg": "域名未匹配到任何已配置的 DNS 账号下的 Zone"
    }
  ]
}
```

- 整体 `code` 为 `-1` 表示至少有一条失败
- 每条结果独立包含 `code`、`msg`、`data`

### 2.7 域名自动匹配机制

DNS API 无需指定账号/Zone，通过以下逻辑自动匹配：

1. `dns_api_resolve_domain($domain)` 加载 `data/dns_zones_cache.json` 中的 Zone 列表
2. `dns_api_parse_fqdn_to_zone()` 按 **最长后缀匹配** 原则查找（Zone 按名称长度降序排列）
3. 优先使用 UI 中最近选中的账号

Zone 缓存有效期 10 分钟（`DNS_API_ZONES_CACHE_TTL`），首次调用或缓存过期时会自动重建。

### 2.8 支持的 DNS 服务商

| 服务商 | 凭据字段 | Proxied | 说明 |
|--------|----------|---------|------|
| `aliyun` | `access_key_id`、`access_key_secret` | 否 | 阿里云 DNS API v2015-01-09 |
| `cloudflare` | `api_token` | 是 | Cloudflare API v4，Token 不需要 `Bearer` 前缀 |

配置存储在 `data/dns_config.json`，通过 `admin/dns.php` 管理。

### 2.9 内部调用链

```
public/api/dns.php
  → dns_api_is_localhost()      [IP 白名单检查]
  → dns_api_get_merged_input()  [参数合并]
  → dns_api_query() / dns_api_upsert() / dns_api_batch_update()
    → dns_api_resolve_domain()  [Zone 匹配]
    → dns_cli_call()            [PHP → Python 子进程]
      → python/dns_core.py
        → AliyunProvider / CloudflareProvider
```

---

## 附录：相关源码文件速查

| 功能 | 文件路径 |
|------|----------|
| API Token 验证 | `admin/shared/functions.php` → `api_token_verify()` |
| API Token 管理页 | `admin/api_tokens.php` |
| 站点数据 API | `public/api/sites.php` |
| DNS 公共 API | `public/api/dns.php` |
| DNS API 库 | `admin/shared/dns_api_lib.php` |
| DNS 配置库 | `admin/shared/dns_lib.php` |
| DNS Python 核心 | `python/dns_core.py` |
