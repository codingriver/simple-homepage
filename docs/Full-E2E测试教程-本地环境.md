# 本地环境 Full-E2E 测试教程

适用场景：

- 被测站点跑在你本机的开发环境里
- 测试命令直接在宿主机终端执行
- 日常开发自测、调试失败用例

---

## 1. 首次准备

需要工具：

- `node`（推荐 18+）
- `npm`
- `docker`
- `docker compose`

建议先确认版本：

```bash
node -v
npm -v
docker --version
docker compose version
```

安装依赖：

```bash
npm install
```

安装 Playwright 浏览器：

```bash
npx playwright install
```

准备本地配置：

```bash
cp local/.env.example local/.env
```

启动开发站点：

```bash
bash local/docker-build.sh dev
```

默认访问地址：

- `http://127.0.0.1:58080`
- `http://127.0.0.1:58080/login.php`

常用测试账号：

- 用户名：`qatest`
- 密码：`qatest2026`

---

## 2. 日常执行

桌面端回归：

```bash
npm run test:e2e:full:chromium
```

双项目全量（chromium + mobile-chrome）：

```bash
npm run test:e2e:full
```

移动端回归：

```bash
npm run test:e2e:full:mobile
```

有界面调试：

```bash
npm run test:e2e:headed
npm run test:e2e:headed:chromium
npm run test:e2e:headed:mobile-chrome
```

精确执行单条或单文件用例：

```bash
# 单个 spec 文件
npm run test:e2e:headed:chromium -- tests/e2e/full/csrf-guards.spec.ts

# 指定行号附近的单条用例
npm run test:e2e:headed:chromium -- tests/e2e/full/csrf-guards.spec.ts:8

# 按用例标题过滤
npm run test:e2e:headed:chromium -- -g "csrf guards reject admin mutations without valid token"
```

说明：这些脚本不再在命令内部硬编码 `tests/e2e/full`，默认范围仍由 `playwright.config.ts` 的 `testDir` 控制，所以追加文件、行号或 `-g` 时只会筛选你指定的目标。

查看当前会跑哪些用例：

```bash
npm run test:e2e:full:chromium -- --list
```

打开测试 UI 界面：

```bash
npx playwright test --ui
```

切换被测地址：

```bash
BASE_URL=http://127.0.0.1:58081 npm run test:e2e:full:chromium
```

如确实要强制系统 Chrome：

```bash
PLAYWRIGHT_BROWSER_CHANNEL=chrome npm run test:e2e:full:chromium
```

---

## 3. 产物位置

- HTML 报告：`test-results/playwright-report-html/`
- Markdown 报告：`test-results/playwright-report.md`
- 截图、trace 等产物：`test-artifacts/`

---

## 4. 常见问题

### 4.1 浏览器启动即崩溃

如果看到下面这类报错：

- `bootstrap_check_in ... Permission denied (1100)`
- `crashpad.child_port_handshake`
- `SIGABRT`

先按下面顺序处理：

```bash
rm -rf .playwright-browsers .playwright-runtime-home
npx playwright install
npm run test:e2e:full:chromium
```

说明：

- 项目已默认避免强制使用系统 `Google Chrome`
- Playwright 会通过项目脚本隔离运行时 `HOME` 和浏览器缓存目录
- 如果你手动设置了 `PLAYWRIGHT_BROWSER_CHANNEL=chrome`，请先去掉再重试

### 4.2 浏览器未安装

如果报 `Executable doesn't exist`，执行：

```bash
npx playwright install
```

### 4.3 站点没起来

如果测试一开始就连不上页面，先确认：

```bash
curl -I http://127.0.0.1:58080
```

如果不通，重新启动：

```bash
bash local/docker-build.sh dev
```

---

## 5. 推荐顺序

日常开发建议这样跑：

1. 启动本地开发站点
2. 执行 `npm run test:e2e:full:chromium`
3. 如失败，用 `npm run test:e2e:headed` 调试
4. 如改动涉及移动端，再执行 `npm run test:e2e:full:mobile`
