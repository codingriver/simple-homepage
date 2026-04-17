# Docker 宿主管理模块需求与设计文档

## 1. 目标

在现有 `host-agent` 体系上补齐 Docker 宿主管理能力，让后台可以直接管理宿主机上的：

- 容器
- 镜像
- 卷
- 网络
- 容器日志
- 容器资源占用

这个模块定位不是做完整 PaaS，而是做"轻量 1Panel / 宝塔"的 Docker 运维台。

## 2. 参考开源项目

本模块不建议完全闭门手搓交互模型，优先参考成熟开源项目的能力边界和信息结构。

### 2.1 1Panel

参考价值：功能覆盖完整、面板式运维路径成熟、Docker 资源的组织方式清晰。

### 2.2 Portainer CE

参考价值：Docker / Kubernetes 管理经验成熟、容器/镜像/卷/网络四大基础对象模型稳定。

### 2.3 Dockge

参考价值：非常适合参考 Compose 栈管理、面向 `compose.yaml` 的思路清晰、轻量。

### 2.4 Yacht

参考价值：轻量、容器管理思路直观、新手友好。

## 3. 推荐路线

推荐路线不是"直接集成某个现成面板"，而是：

- 继续使用 `host-agent` 作为宿主机执行层
- 后台自己做页面、权限、审计
- 信息结构参考 1Panel / Portainer
- Compose 栈管理重点参考 Dockge

结论：

- 容器 / 镜像 / 卷 / 网络：参考 Portainer 和 1Panel
- Compose 栈：重点参考 Dockge
- 页面布局和运维入口：参考 1Panel

## 4. 架构原则

调用链继续保持统一：

```text
admin/docker_hosts.php
  -> admin/docker_api.php
  -> admin/shared/host_agent_lib.php
  -> host-agent API
  -> 宿主机 Docker API / docker CLI
```

设计原则：

- 页面层不直接访问 `docker.sock`
- Docker API 或 `docker` 命令统一由 `host-agent` 执行
- 所有写操作都进入审计
- 所有高风险操作都需要确认

## 5. 为什么继续走 host-agent

原因：

- 当前项目已经有 `host-agent` 安装、鉴权、健康检查、宿主机模式、模拟模式
- 再单独引入一个 Docker agent 会让体系分裂
- `host-agent` 后续还能统一承接：宿主机运维、Docker 宿主管理、资源监控、计划任务与宿主机联动

## 6. 第一阶段功能范围（已实现）

### 6.1 容器

- [x] 容器列表
- [x] 运行状态
- [x] 容器名 / 镜像 / 端口 / 创建时间
- [x] 启动 / 停止 / 重启 / 删除
- [x] 查看日志
- [x] 查看环境变量
- [x] 查看挂载
- [x] 查看容器资源占用

### 6.2 镜像

- [x] 镜像列表
- [x] 标签
- [x] 大小
- [x] 创建时间
- [x] 删除镜像

### 6.3 卷

- [x] 卷列表
- [x] 挂载点
- [x] 被哪些容器使用
- [x] 删除未使用卷

### 6.4 网络

- [x] 网络列表
- [x] 驱动
- [x] 已连接容器
- [x] 删除自定义网络

## 7. 第二阶段功能范围（规划中）

### 7.1 Compose 栈管理

建议重点参考 Dockge：

- 栈列表
- `compose.yaml` 查看 / 编辑
- 一键 `up/down/pull/restart`
- 栈日志
- 栈目录管理

### 7.2 容器详情增强

- 实时日志 tail
- 文件挂载视图
- 端口映射详情
- Inspect JSON

### 7.3 风险控制

- 删除前依赖检查
- 高风险操作确认
- 只读角色限制

## 8. 技术实现

### 8.1 第一阶段：优先走 Docker API

优点：

- 结构化返回更稳定
- 比解析 CLI 文本更可靠
- 更适合列表、详情、状态判断

适合的对象：

- 容器
- 镜像
- 卷
- 网络

### 8.2 第二阶段：Compose 栈管理可混合使用

因为 Compose 栈本身经常与文件目录绑定，建议：

- 栈列表和状态：可由目录约定 + Docker API 组合实现
- `up/down/pull`：可以调用 `docker compose`
- 栈文件编辑：继续走现有文件系统模块

## 9. 页面拆分

推荐新增独立页面：

- `admin/docker_hosts.php`

页面内部分区：

- 概览
- 容器
- 镜像
- 卷
- 网络
- 栈（第二阶段）

## 10. 权限

第一阶段复用：

- 读：`ssh.view`
- 写：`ssh.manage`

第二阶段建议拆出：

- `docker.view`
- `docker.manage`
- `docker.logs`
- `docker.compose`

## 11. 审计

建议动作名：

- `docker_container_start`
- `docker_container_stop`
- `docker_container_restart`
- `docker_container_delete`
- `docker_image_delete`
- `docker_volume_delete`
- `docker_network_delete`
- `docker_compose_up`
- `docker_compose_down`
- `docker_compose_pull`

## 12. 当前落地文件

- `admin/docker_hosts.php`
- `admin/docker_api.php`
- `admin/shared/host_agent_lib.php`
- `cli/host_agent.php`
- `tests/e2e/full/docker-hosts.spec.ts`
- `tests/e2e/full/docker-api.spec.ts`

## 13. 当前推荐结论

推荐方案：

- 不直接集成 Portainer / 1Panel / Dockge 的完整系统
- 参考它们的对象模型和交互方式
- 继续用 `host-agent` 做执行层
- 第一阶段先做 Docker 资源基础管理（**已实现**）
- 第二阶段再做 Compose 栈管理

这是当前项目里成本、可控性、维护性最平衡的路线。

---

*文档版本：v1.1 | 第一阶段已实现*
