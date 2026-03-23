# 远程开发场景 MCP 配置教程

## 场景描述

```
电脑A：安装了 Cursor IDE
服务器B：192.168.2.2（代码、Docker、nav-portal 容器都在这里）
Cursor 通过 SSH 连接服务器B 的 Remote SSH 模式开发
```

---

## 核心原则

**MCP Server 需要运行在能访问 Docker 的机器上，即服务器B。**
Cursor 的 Remote SSH 模式下，MCP Server 自动在远程服务器上启动。

---

## 方式一：Cursor Remote SSH（最推荐）

### 原理

```
电脑A Cursor  ──SSH──▶  服务器B
                         ├── Cursor Server（自动安装）
                         ├── MCP Server（在服务器B运行）
                         └── Docker（nav-portal 容器）
```

Cursor 的 Remote SSH 功能会在服务器B上启动一个 Cursor Server，
MCP Server 也在服务器B上运行，因此可以直接访问本地 Docker。

### 安装步骤

**第一步：电脑A安装 Remote SSH 扩展**

Cursor 已内置 Remote SSH，直接用即可：
- 按 `Ctrl+Shift+P`（或 `Cmd+Shift+P`）
- 输入 `Remote-SSH: Connect to Host`
- 输入 `user@192.168.2.2`
- 连接成功后，Cursor 窗口左下角显示 `SSH: 192.168.2.2`

**第二步：在服务器B上安装 Node.js**

```bash
# 服务器B上执行
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install --lts
node --version  # 确认安装成功
```

**第三步：在服务器B项目目录创建 MCP 配置**

```bash
# 服务器B上执行
mkdir -p /volume3/storage/docker/nav-portal/.cursor
```

创建 `/volume3/storage/docker/nav-portal/.cursor/mcp.json`：

```json
{
  "mcpServers": {
    "shell": {
      "command": "npx",
      "args": ["-y", "@wonderwhy-er/desktop-commander"]
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
        "/volume3/storage/docker/nav-portal"
      ]
    }
  }
}
```

**第四步：在 Cursor（Remote SSH 连接后）验证 MCP**

- 打开命令面板 → `MCP: List Servers`
- 确认三个 Server 状态为绿色
- 测试：在聊天框输入「列出 /volume3/storage/docker/nav-portal 目录」

### 效果

MCP Server 在服务器B运行，直接访问本地 Docker，无需任何额外配置。

---

## 方式二：不用 Remote SSH，电脑A本地 MCP 通过 SSH 连接服务器B

如果你不想用 Remote SSH 模式，只用本地 Cursor，需要让 MCP Server 通过 SSH 执行远程命令。

### 原理

```
电脑A Cursor
  └── 电脑A MCP Server
        └── ssh user@192.168.2.2 'docker exec nav-portal ...'
```

### 配置步骤

**第一步：电脑A配置 SSH 免密登录**

```bash
# 电脑A上执行
ssh-keygen -t ed25519 -C "cursor-mcp"
ssh-copy-id user@192.168.2.2
# 验证
ssh user@192.168.2.2 'echo ok'
```

**第二步：创建远程执行包装脚本**

在电脑A创建 `~/nav-mcp-wrapper.sh`：

```bash
#!/bin/bash
# 所有命令通过 SSH 在服务器B执行
SSH_HOST="user@192.168.2.2"
ssh -o StrictHostKeyChecking=no $SSH_HOST "$@"
```

```bash
chmod +x ~/nav-mcp-wrapper.sh
```

**第三步：电脑A的 `~/.cursor/mcp.json`**

```json
{
  "mcpServers": {
    "remote-shell": {
      "command": "npx",
      "args": ["-y", "@wonderwhy-er/desktop-commander"],
      "env": {
        "SHELL_COMMAND_PREFIX": "ssh user@192.168.2.2"
      }
    },
    "fetch": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch"]
    }
  }
}
```

> ⚠️ 注意：`desktop-commander` 的远程执行支持有限，推荐用方式一（Remote SSH）。

---

## 方式三：在服务器B启动 MCP HTTP Server，电脑A连接

最灵活的方案，适合团队共享。

### 原理

```
电脑A Cursor  ──HTTP──▶  服务器B:3000 MCP Server  ──▶  Docker
```

### 服务器B安装

```bash
# 服务器B上执行
npm install -g @modelcontextprotocol/server-everything

# 或安装支持 HTTP 模式的 MCP server
npm install -g mcp-server-commands
```

创建服务器B的启动脚本 `/opt/nav-mcp-server.js`：

```javascript
const { Server } = require('@modelcontextprotocol/sdk/server/index.js');
const { StdioServerTransport } = require('@modelcontextprotocol/sdk/server/stdio.js');
const { exec } = require('child_process');
const { promisify } = require('util');
const execAsync = promisify(exec);

const server = new Server(
  { name: 'nav-docker-server', version: '1.0.0' },
  { capabilities: { tools: {} } }
);

server.setRequestHandler('tools/list', async () => ({
  tools: [
    {
      name: 'docker_exec',
      description: '在 nav-portal 容器内执行命令',
      inputSchema: {
        type: 'object',
        properties: { command: { type: 'string' } },
        required: ['command']
      }
    },
    {
      name: 'sync_file',
      description: '同步宿主机文件到容器',
      inputSchema: {
        type: 'object',
        properties: {
          host_path: { type: 'string' },
          container_path: { type: 'string' }
        },
        required: ['host_path', 'container_path']
      }
    },
    {
      name: 'reset_nav',
      description: '重置 Nav Portal 安装状态',
      inputSchema: { type: 'object', properties: {} }
    }
  ]
}));

server.setRequestHandler('tools/call', async (request) => {
  const { name, arguments: args } = request.params;

  if (name === 'docker_exec') {
    const { stdout, stderr } = await execAsync(
      `docker exec nav-portal sh -c ${JSON.stringify(args.command)}`
    );
    return { content: [{ type: 'text', text: stdout || stderr }] };
  }

  if (name === 'sync_file') {
    const cmd = `base64 ${args.host_path} | docker exec -i nav-portal sh -c 'base64 -d > ${args.container_path}'`;
    await execAsync(cmd);
    const h1 = (await execAsync(`md5sum ${args.host_path}`)).stdout.split(' ')[0];
    const h2 = (await execAsync(`docker exec nav-portal md5sum ${args.container_path}`)).stdout.split(' ')[0];
    const ok = h1 === h2;
    return { content: [{ type: 'text', text: ok ? `✅ 同步成功 MD5=${h1}` : `❌ 同步失败 host=${h1} container=${h2}` }] };
  }

  if (name === 'reset_nav') {
    await execAsync('docker exec nav-portal rm -f /var/www/nav/data/.installed /var/www/nav/data/users.json /var/www/nav/data/config.json /var/www/nav/data/sites.json');
    return { content: [{ type: 'text', text: '✅ 重置完成' }] };
  }

  throw new Error(`Unknown tool: ${name}`);
});

const transport = new StdioServerTransport();
server.connect(transport);
console.error('Nav Docker MCP Server started');
```

```bash
# 服务器B启动（加入 systemd 或 pm2 保持运行）
node /opt/nav-mcp-server.js

# 用 pm2 保持运行
npm install -g pm2
pm2 start /opt/nav-mcp-server.js --name nav-mcp
pm2 save
pm2 startup
```

**电脑A通过 SSH 隧道连接**（`~/.cursor/mcp.json`）：

```json
{
  "mcpServers": {
    "nav-remote": {
      "command": "ssh",
      "args": [
        "-T",
        "user@192.168.2.2",
        "node /opt/nav-mcp-server.js"
      ]
    },
    "fetch": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch"]
    }
  }
}
```

---

## 三种方式对比

| 方式 | 配置难度 | 稳定性 | 适合场景 |
|------|---------|--------|----------|
| Remote SSH（方式一） | ⭐ 简单 | ⭐⭐⭐⭐⭐ | 个人开发，最推荐 |
| 本地 MCP+SSH（方式二） | ⭐⭐⭐ 中等 | ⭐⭐⭐ | 不想用 Remote SSH |
| 自定义 MCP Server（方式三） | ⭐⭐⭐⭐⭐ 复杂 | ⭐⭐⭐⭐ | 团队共享、定制化需求 |

**强烈推荐方式一（Remote SSH）**，Cursor 官方支持，配置最简单，效果最好。

---

## 给 AI 的指令模板（配置完成后）

连接 Remote SSH 后，在 Cursor 聊天框提供以下上下文：

```
项目环境：
- 容器名：nav-portal
- 源码路径：/volume3/storage/docker/nav-portal/nav-portal
- 容器内路径：/var/www/nav
- 访问地址：http://192.168.2.2:58080
- 测试账号：admin / Admin@2026!
- 数据目录（容器内）：/var/www/nav/data

可用 MCP 工具：
- shell MCP：执行 docker 命令、同步文件、查看日志
- fetch MCP：直接发起 HTTP 请求测试接口
- filesystem MCP：读写项目文件

规则：
1. 修改 PHP 文件后立即用 base64 方式同步并验证 MD5
2. HTTP 测试从宿主机发起，不在容器内 curl 自身
3. 重置数据用 docker exec rm
4. 测试脚本：python3 /volume3/storage/docker/nav-portal/http_test.py
```

---

## 快速开始（Remote SSH 方式）

```bash
# 1. 电脑A连接服务器B
# Cursor: Ctrl+Shift+P -> Remote-SSH: Connect to Host -> user@192.168.2.2

# 2. 服务器B安装 Node.js（首次）
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc && nvm install --lts

# 3. 创建 MCP 配置（服务器B上）
mkdir -p /volume3/storage/docker/nav-portal/.cursor
cat > /volume3/storage/docker/nav-portal/.cursor/mcp.json << 'MCPEOF'
{
  "mcpServers": {
    "shell": {
      "command": "npx",
      "args": ["-y", "@wonderwhy-er/desktop-commander"]
    },
    "fetch": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch"]
    },
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem",
        "/volume3/storage/docker/nav-portal"]
    }
  }
}
MCPEOF

# 4. 在 Cursor 中打开项目目录
# File -> Open Folder -> /volume3/storage/docker/nav-portal

# 5. 验证 MCP 工作
# Cursor 聊天框输入：用 shell MCP 执行 docker ps 列出运行中的容器
```
