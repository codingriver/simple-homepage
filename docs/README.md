# Simple Homepage 项目文档索引

欢迎来到 Simple Homepage 的文档目录。这里汇集了项目的使用说明、部署指南、技术设计和开发规范。

---

## 快速导航

### 部署相关（新手必看）

| 文档 | 说明 | 适合人群 |
|------|------|----------|
| [../README.md](../README.md) | **小白部署文档 + 常见问题修复** | 所有人 |
| [Docker部署文档.md](./Docker部署文档.md) | Docker 深度部署指南 | 中高级用户 |
| [导航网站部署文档.md](./导航网站部署文档.md) | 纯 Nginx + PHP 部署 | 不使用 Docker 的用户 |
| [VPS-A网关部署文档.md](./VPS-A网关部署文档.md) | VPS-A 透明网关 + SSL 透传 | 有多台 VPS 的用户 |

### 技术设计与实现

| 文档 | 说明 |
|------|------|
| [技术架构与实现原理.md](./技术架构与实现原理.md) | **项目架构、数据流、安全模型、host-agent 设计** |
| [项目设计文档.md](./项目设计文档.md) | 按模块划分的详细设计文档 |
| [导航网站需求文档.md](./导航网站需求文档.md) | 功能需求清单与变更记录 |
| [项目问题分析与设计缺陷.md](./项目问题分析与设计缺陷.md) | 已知问题与改进建议 |

### 模块设计文档

| 文档 | 说明 |
|------|------|
| [宿主机运维模块需求与设计文档.md](./宿主机运维模块需求与设计文档.md) | 系统概览、进程、服务、网络、用户管理 |
| [Docker宿主管理模块需求与设计文档.md](./Docker宿主管理模块需求与设计文档.md) | 容器、镜像、卷、网络管理 |
| [文件系统模块需求与设计文档.md](./文件系统模块需求与设计文档.md) | 本机/远程文件浏览、编辑、上传、下载 |

### 开发与测试

| 文档 | 说明 |
|------|------|
| [PHP开发注意事项.md](./PHP开发注意事项.md) | PHP Web 开发踩坑总结 |
| [Full-E2E测试教程-本地环境.md](./Full-E2E测试教程-本地环境.md) | Playwright 本地测试指南 |
| [Full-E2E测试教程-Docker环境.md](./Full-E2E测试教程-Docker环境.md) | Playwright Docker 测试指南 |
| [项目测试规划.md](./项目测试规划.md) | 测试覆盖总览与进度 |
| [测试TODO.md](./测试TODO.md) | 待补充测试项 |
| [测试用例编写规范.md](./测试用例编写规范.md) | Playwright 测试编写规范 |

### 协作规范

| 文档 | 说明 |
|------|------|
| [协作RULES.md](./协作RULES.md) | 代码修复 / 文档更新 / 发布部署的协作规则 |

---

## 项目基本信息

- **项目名称**：Simple Homepage
- **GitHub**：https://github.com/codingriver/simple-homepage
- **Docker Hub**：https://hub.docker.com/r/codingriver/simple-homepage
- **技术栈**：PHP 8.2 + Nginx + JSON 文件存储 + Docker
- **测试**：Playwright (E2E) + PHPUnit (单元)
- **镜像架构**：linux/amd64、linux/arm64

---

*文档索引版本：v1.0 | 2026-04*
