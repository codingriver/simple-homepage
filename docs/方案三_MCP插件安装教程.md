# 方案三：Cursor MCP 插件具体方案与安装教程

> 通过配置 MCP Server，让 AI 直接操作 Docker 容器、发起 HTTP 请求、管理文件，
> 实现「修改→测试→修复」全自动闭环，无需人工干预。

---

## 核心思路

```
传统方式：AI 写代码 → 你手动同步 → 你手动测试 → 你把结果贴回 → AI 分析
MCP 方式：AI 写代码 → AI 自动同步 → AI 自动测试 → AI 自动分析 → AI 自动修复
```

MCP（Model Context Protocol）是 Cursor 的插件协议，允许 AI 调用外部工具。
配置完成后，AI 可以直接：
- 在 Docker 容器内执行命令
- 发起 HTTP 请求测试接口
- 读写文件系统
- 查看实时日志

---

## MCP 配置文件位置

Cursor 支持两个位置：

| 配置文件 | 作用范围 |
|---------|----------|
| `~/.cursor/mcp.json` | 全局，所有项目可用 |
| `.cursor/mcp.json` | 仅当前项目可用 |

**推荐**：Docker 工具放全局，项目特定工具放项目目录。

---

## 安装步骤

### 第一步：安装 Node.js（如果未安装）

```bash
# 检查是否已安装
node --version
npm --version

# 未安装则用 nvm 安装（推荐）
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install --lts
```

### 第二步：安装 Python 依赖（HTTP 测试用）

```bash
pip3 install requests
# 或
pip install requests
```

### 第三步：创建 MCP 配置文件

```bash
mkdir -p ~/.cursor
```

创建 `~/.cursor/mcp.json`：

```json
{
  "mcpServers": {
    "docker-nav": {
      "command": "npx",
      "args": ["-y", "@wonderwhy-er/desktop-commander"],
      "env": {}
    },
    "fetch": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch"]
    },
    "filesystem": {
      "command": "npx",
      "args": [
        "-y",
        "@modelcontextprotocol/server-filesystem",
        "/volume3/storage/docker/simple-homepage"
      ]
    }
  }
}
```

### 第四步：在 Cursor 中启用 MCP

1. 打开 Cursor 设置：`Cmd+Shift+J`（Mac）或 `Ctrl+Shift+J`（Windows/Linux）
2. 进入 `Features` → `Model Context Protocol`
3. 确认三个 Server 状态为绿色（Running）
4. 如果显示错误，点击 `View Logs` 查看原因

### 第五步：验证安装

在 Cursor 聊天框输入：
```
用 MCP 工具列出 /volume3/storage/docker/simple-homepage 目录的文件
```

如果 AI 能直接列出文件，说明 MCP 已正常工作。

---

## 三个 MCP Server 的作用

### 1. `desktop-commander`（最重要）

**作用**：让 AI 直接执行 Shell 命令，包括 docker 命令。

**AI 可以做的事**：
```bash
# 同步文件到容器（AI 自动执行）
base64 /path/to/file | docker exec -i simple-homepage sh -c 'base64 -d > /var/www/nav/file'

# 重置数据（AI 自动执行）
docker exec simple-homepage rm -f /var/www/nav/data/.installed

# 查看错误日志（AI 自动执行）
docker exec simple-homepage cat /var/log/nginx/error.log

# 验证 MD5（AI 自动执行）
docker exec simple-homepage md5sum /var/www/nav/public/login.php
```

**安装命令**：
```bash
npx -y @wonderwhy-er/desktop-commander
```

### 2. `server-fetch`（HTTP 测试）

**作用**：让 AI 直接发起 HTTP 请求，无需写 Python 脚本。

**AI 可以做的事**：
```
# AI 直接测试（无需脚本）
请用 fetch 工具 GET http://192.168.2.2:58080/login.php 并提取 CSRF token
请用 fetch 工具 POST 登录接口并验证 nav_session cookie 是否设置
```

**安装命令**：
```bash
npx -y @modelcontextprotocol/server-fetch
```

### 3. `server-filesystem`（文件管理）

**作用**：让 AI 直接读写宿主机文件，比 Cursor 内置工具更快。

**AI 可以做的事**：
- 直接读取项目所有 PHP 文件
- 批量修改文件
- 检查文件是否存在、获取文件大小

**安装命令**：
```bash
npx -y @modelcontextprotocol/server-filesystem /volume3/storage/docker/simple-homepage
```

---

## 项目级 MCP 配置（推荐）

在项目根目录创建 `.cursor/mcp.json`，包含项目特定变量：

```bash
mkdir -p /volume3/storage/docker/simple-homepage/.cursor
```

创建 `/volume3/storage/docker/simple-homepage/.cursor/mcp.json`：

```json
{
  "mcpServers": {
    "nav-shell": {
      "command": "npx",
      "args": ["-y", "@wonderwhy-er/desktop-commander"],
      "env": {
        "NAV_CONTAINER": "simple-homepage",
        "NAV_HOST_SRC": "/volume3/storage/docker/simple-homepage/simple-homepage",
        "NAV_CONTAINER_SRC": "/var/www/nav",
        "NAV_BASE_URL": "http://192.168.2.2:58080"
      }
    },
    "nav-fetch": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch"]
    },
    "nav-files": {
      "command": "npx",
      "args": [
        "-y",
        "@modelcontextprotocol/server-filesystem",
        "${workspaceFolder}"
      ]
    }
  }
}
```

---

## 配置 Cursor Rules（让 AI 知道如何使用 MCP）

创建 `.cursor/rules/nav-mcp.md`：

```bash
mkdir -p /volume3/storage/docker/simple-homepage/.cursor/rules
```

内容：

```markdown
# Nav Portal MCP 工具使用规范

## 环境变量
- 容器名：simple-homepage
- 宿主机源码：/volume3/storage/docker/simple-homepage/simple-homepage
- 容器内路径：/var/www/nav
- 访问地址：http://192.168.2.2:58080
- 测试账号：admin / Admin@2026!

## 文件同步（使用 nav-shell MCP）
修改 PHP 文件后，立即执行：
```bash
base64 {宿主机文件路径} | docker exec -i simple-homepage sh -c 'base64 -d > {容器内路径}'
# 验证
H1=$(md5sum {宿主机路径} | cut -d' ' -f1)
H2=$(docker exec simple-homepage md5sum {容器内路径} | cut -d' ' -f1)
[ "$H1" = "$H2" ] && echo "✅ 同步成功" || echo "❌ 同步失败"
```

## HTTP 测试（使用 nav-fetch MCP）
1. GET 登录页获取 CSRF token
2. POST 登录获取 nav_session cookie
3. 携带 cookie 测试各接口
注意：curl cookie jar 中 #HttpOnly_ 开头的 cookie 不会自动发送，需用 -H 'Cookie: ...' 显式传递

## 数据重置（使用 nav-shell MCP）
```bash
docker exec simple-homepage rm -f \
  /var/www/nav/data/.installed \
  /var/www/nav/data/users.json \
  /var/www/nav/data/config.json \
  /var/www/nav/data/sites.json
```

## 错误排查顺序
1. 检查文件是否同步（MD5 对比）
2. 检查 PHP 语法：`docker exec simple-homepage php -l /var/www/nav/{文件}`
3. 检查 nginx 日志：`docker exec simple-homepage cat /var/log/nginx/error.log`
4. 用 fetch MCP 直接请求接口看响应
5. 用 curl -v 查看完整请求响应头

## 禁止事项
- 禁止在容器内 curl 自身（导致 PHP-FPM worker 死锁）
- 禁止用 docker exec cat > 方式写文件（会产生空文件）
- 禁止用宿主机 rm 命令删除容器内数据文件
```

---

## 完整工作流示例

配置完成后，只需告诉 AI：

```
登录页 CSRF 验证失败，帮我修复
```

AI 会自动：
1. 用 filesystem MCP 读取 login.php
2. 分析代码找到问题
3. 修改文件
4. 用 shell MCP 执行 base64 同步到容器
5. 用 shell MCP 验证 MD5
6. 用 fetch MCP 发起 HTTP 请求测试
7. 报告测试结果
8. 如果仍有问题，继续修复循环

**全程无需人工干预。**

---

## 常见问题

### MCP Server 启动失败

```bash
# 检查 npx 是否可用
npx --version

# 手动测试 server
npx -y @modelcontextprotocol/server-fetch

# 查看 Cursor MCP 日志
# Cursor → Settings → MCP → View Logs
```

### desktop-commander 权限不足

```bash
# 确保当前用户在 docker 组
usermod -aG docker $USER
newgrp docker

# 测试 docker 命令是否可用
docker ps
```

### npx 下载慢

```bash
# 提前全局安装（避免每次下载）
npm install -g @wonderwhy-er/desktop-commander
npm install -g @modelcontextprotocol/server-fetch
npm install -g @modelcontextprotocol/server-filesystem

# 然后修改 mcp.json 用 node 直接运行
# "command": "desktop-commander"
# "args": []
```

---

## 本文档关联文件

| 文件 | 说明 |
|------|------|
| `PHP开发注意事项.md` | PHP 开发规范，11个主题 |
| `自动化测试与修复方案.md` | 三方案对比与 Makefile |
| `方案三_MCP插件安装教程.md` | 本文档 |
| `/volume3/storage/docker/simple-homepage/http_test.py` | 22个测试用例的 HTTP 测试脚本 |
