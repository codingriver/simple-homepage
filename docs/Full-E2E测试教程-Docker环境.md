# Docker 环境 Full-E2E 测试教程

适用场景：

- 希望测试运行环境更稳定
- 想减少宿主机环境差异
- 需要通过项目提供的测试容器执行回归

---

## 1. 首次准备

需要工具：

- `docker`
- `docker compose`

准备配置：

```bash
cp local/.env.example local/.env
```

启动开发环境：

```bash
bash local/docker-build.sh dev
```

说明：

- 被测站点由 `nav` 服务提供
- Playwright 测试容器定义在 `local/docker-compose.test.yml`
- 测试容器会自动安装依赖并执行 `npx playwright install`

---

## 2. 日常执行

桌面端 Full 回归：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

移动端 Full 回归：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```

性能测试：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm lighthouse
```

如需切换被测地址，可额外传入环境变量：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm -e BASE_URL=http://nav:58080 playwright-full
```

---

## 3. 容器内实际行为

`playwright-full` 会执行：

```bash
npx playwright install
npm run test:e2e:full:chromium
```

`playwright-mobile` 会执行：

```bash
npx playwright install
npm run test:e2e:full:mobile
```

---

## 4. 产物位置

测试结果仍输出到项目目录：

- HTML 报告：`test-results/playwright-report-html/`
- Markdown 报告：`test-results/playwright-report.md`
- 截图、trace 等产物：`test-artifacts/`

---

## 5. 常见问题

### 5.1 容器没启动起来

先检查开发环境是否已经起来：

```bash
bash local/docker-build.sh dev
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml ps
```

### 5.2 依赖或浏览器未安装

测试容器已内置自动安装逻辑。若首次执行较慢，通常是正常现象。

如果仍失败，可重试一次：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

### 5.3 什么时候优先用 Docker

优先使用 Docker 的情况：

- 宿主机浏览器环境异常
- 本地 Node/Playwright 依赖污染较重
- 需要更接近 CI 的可重复执行环境

---

## 6. 推荐顺序

1. 执行 `bash local/docker-build.sh dev`
2. 执行 `docker compose ... run --rm playwright-full`
3. 如改动涉及移动端，再执行 `docker compose ... run --rm playwright-mobile`
