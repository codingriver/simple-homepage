# Full E2E 测试使用教程

本文只保留两大章，分别说明：

1. 本地测试环境教程
2. Docker 环境测试教程

两章都按同样结构组织：

- 第一次环境配置教程
- 快速使用教程
- 详细参数说明

---

## 第一章：本地测试环境教程

这里的“本地测试环境”指的是：

- 被测站点通过项目本地开发环境提供
- 测试命令直接在当前系统里执行
- 日常开发优先使用这条路径

### 1.1 第一次环境配置教程

#### 1.1.1 需要准备哪些工具

本地测试环境至少需要下面这些工具：

- `node`
- `npm`
- `Docker`
- `Docker Compose`

各自用途：

- `node` / `npm`
  - 用来安装并运行 Playwright 相关依赖
- `Docker` / `Docker Compose`
  - 用来启动本项目本地开发站点

#### 1.1.2 如何验证这些工具是否已安装成功

在项目根目录或任意终端执行：

```bash
node -v
npm -v
docker --version
docker compose version
```

判断标准：

- 如果每条命令都能返回版本号，说明工具已安装成功
- 如果提示 `command not found` 或类似错误，说明该工具尚未安装成功

#### 1.1.3 安装项目依赖

在项目根目录执行：

```bash
npm install
```

作用：

- 安装 `@playwright/test`
- 安装 `@lhci/cli`
- 安装 `package.json` 中定义的其他依赖

#### 1.1.4 如何验证 `npm install` 是否成功

可以用两种方式确认。

第一种：看命令是否正常结束。

如果 `npm install` 没有报错并正常返回，通常说明安装成功。

第二种：直接确认 Playwright 包是否能被调用：

```bash
npx playwright --version
```

如果能输出版本号，通常说明：

- `node_modules` 已安装完成
- Playwright CLI 已可正常使用

#### 1.1.5 安装 Playwright 浏览器

首次在一台新机器上跑 Playwright 时，建议执行：

```bash
npx playwright install chromium
```

说明：

- 本项目主要使用 Chromium 相关项目配置
- 安装 `chromium` 后，通常已能满足本项目 Full E2E 运行需求

#### 1.1.6 如何验证 `npx playwright install chromium` 是否成功

推荐按下面顺序确认。

1. 先看安装命令是否正常结束：

```bash
npx playwright install chromium && echo "playwright chromium 安装成功"
```

如果最后成功打印出提示，通常说明安装流程已经完成。

2. 再直接验证浏览器是否真的能被拉起：

```bash
npx playwright open --browser chromium http://127.0.0.1:58080
```

如果浏览器可以正常打开页面，说明：

- Playwright 浏览器已安装成功
- 当前系统能正常调用 Chromium

3. 也可以直接跑一条现有测试做实战验证：

```bash
npx playwright test tests/e2e/full/groups-boundary.spec.ts --project=chromium
```

如果测试能正常启动并进入执行过程，也可以视为安装成功。

4. 作为辅助检查，还可以查看浏览器缓存目录：

```bash
ls ~/Library/Caches/ms-playwright
```

如果里面能看到类似 `chromium-xxxx` 的目录，通常说明 Chromium 浏览器文件已经下载到本机。

常见失败信号包括：

- `Executable doesn't exist`
- `Failed to download Chromium`
- 权限错误
- 网络错误
- 磁盘空间不足

#### 1.1.7 准备本地开发环境配置

首次建议先复制本地环境配置：

```bash
cp local/.env.example local/.env
```

然后按需修改 `local/.env`，例如：

- 端口
- 数据目录
- 是否启用无人值守安装
- 默认站点名称
- 相关域名配置

如果只是本地开发测试，很多情况下保持默认值即可。

#### 1.1.8 启动本地开发站点

执行：

```bash
bash local/docker-build.sh dev
```

这一步会：

- 启动本地开发容器
- 挂载源码
- 打开开发模式
- 让被测站点通常可通过 `http://127.0.0.1:58080` 访问

#### 1.1.9 如何验证本地开发站点启动成功

浏览器打开：

- `http://127.0.0.1:58080`
- `http://127.0.0.1:58080/login.php`

如果页面能正常打开，就说明被测站点已准备好。

你也可以用命令检查：

```bash
curl -I http://127.0.0.1:58080
```

如果返回 `HTTP/1.1 200 OK` 或其他正常响应，通常说明服务已启动。

#### 1.1.10 开发环境默认测试账号

本项目开发环境常用测试管理员账号为：

- 用户名：`qatest`
- 密码：`qatest2026`

现有许多 E2E 用例默认依赖这个账号。

#### 1.1.11 首次安装后的最小自检流程

建议在第一次环境配置完成后，按下面顺序做一次最小自检：

##### 第 1 步：确认基础工具可用

```bash
node -v
npm -v
docker --version
docker compose version
```

##### 第 2 步：安装项目依赖

```bash
npm install
```

##### 第 3 步：安装并确认 Playwright 浏览器

```bash
npx playwright install chromium && echo "playwright chromium 安装成功"
```

如需进一步确认浏览器能启动：

```bash
npx playwright open --browser chromium http://127.0.0.1:58080
```

##### 第 4 步：准备本地环境配置

```bash
cp local/.env.example local/.env
```

##### 第 5 步：启动本地开发站点

```bash
bash local/docker-build.sh dev
```

##### 第 6 步：执行最小验证测试

推荐先跑：

```bash
npm run test:e2e:full:chromium
```

##### 通过标准

满足下面几点，就可以认为本地测试环境安装成功：

- 基础工具命令都能返回版本号
- `npm install` 正常完成
- `npx playwright install chromium` 正常完成
- 本地开发站点可以访问
- 至少一条 Playwright 测试命令能成功启动并执行
- 测试结束后可以看到：
  - HTML 报告目录：`test-results/playwright-report-html/`
  - Markdown 报告文件：`test-results/playwright-report.md`
  - 测试中间产物目录：`test-artifacts/`

### 1.2 快速使用教程（按照 Full 版本运行）

这里给出最推荐、最短的使用路径。

#### 1.2.1 启动本地开发环境

```bash
bash local/docker-build.sh dev
```

#### 1.2.2 运行桌面端 Full E2E

```bash
npm run test:e2e:full:chromium
```

这是日常开发最推荐的 Full 回归命令，因为：

- 只跑桌面端项目
- 比双项目全量更快
- 适合高频使用

#### 1.2.3 如果需要完整 Full（桌面端 + 移动端）

```bash
npm run test:e2e:full
```

这个命令会按 Playwright 配置里的默认项目一起运行，一般包括：

- `chromium`
- `mobile-chrome`

#### 1.2.4 如果改动涉及移动端，再单独补跑移动端

```bash
npm run test:e2e:full:mobile
```

#### 1.2.5 如果测试失败，先这样调试

```bash
npm run test:e2e:headed
```

这个命令会打开有界面浏览器，便于观察实际执行过程。

#### 1.2.6 最常见使用顺序

日常开发建议顺序：

1. 启动本地开发站点
2. 跑 `npm run test:e2e:full:chromium`
3. 如果有失败，再用 `npm run test:e2e:headed` 调试
4. 如果改动涉及移动端，再补 `npm run test:e2e:full:mobile`
5. 如果改动涉及下面这些高风险模块，优先关注对应新增覆盖是否通过：
   - 登录锁定 / Session / Cookie 策略
   - 备份恢复 / 导入导出回滚
   - 分组删除级联 / 站点边界校验
   - Proxy 模式切换 / Nginx 待生效提示
6. 发版前可再跑 `npm run test:e2e:full`

#### 1.2.7 测试报告输出位置

执行 Playwright 测试后，当前项目会同时输出两份报告：

- HTML 报告目录：`playwright-report/`
- Markdown 报告文件：`test-results/playwright-report.md`

其中 Markdown 报告会写明：

- 报告生成日期
- 启动时间
- 结束时间
- 总用例数
- 通过/失败/跳过/超时统计
- 失败或超时用例明细
- 全部用例结果表

如果你只需要快速查看整体情况，优先看 Markdown 报告；
如果你需要看截图、trace、页面执行细节，再打开 HTML 报告。

### 1.3 详细参数说明

这一部分说明本地 npm 版最常用命令的实际含义。

#### 1.3.1 `npm run test:e2e:full`

```bash
npm run test:e2e:full
```

实际执行的是：

```bash
playwright test tests/e2e/full
```

含义：

- `playwright test`
  - 调用 Playwright 测试运行器
- `tests/e2e/full`
  - 运行整个 `tests/e2e/full` 目录

特点：

- 使用 `playwright.config.ts` 的默认项目配置
- 当前项目默认项目通常包括：
  - `chromium`
  - `mobile-chrome`
- 因此通常会同时跑桌面端与移动端

适合：

- 发版前全量验证
- 想一次性验证两个项目

#### 1.3.2 `npm run test:e2e:full:chromium`

```bash
npm run test:e2e:full:chromium
```

实际执行的是：

```bash
playwright test tests/e2e/full --project=chromium
```

其中：

- `--project=chromium`
  - 指定只运行名为 `chromium` 的 Playwright 项目

特点：

- 只跑桌面端
- 比全量双项目更快
- 是本项目最推荐的日常 Full 回归命令

适合：

- 日常开发回归
- 认证、后台、设置、桌面端首页相关改动

#### 1.3.3 `npm run test:e2e:full:mobile`

```bash
npm run test:e2e:full:mobile
```

实际执行的是：

```bash
playwright test tests/e2e/full --project=mobile-chrome
```

其中：

- `--project=mobile-chrome`
  - 指定只运行移动端设备模拟项目

特点：

- 只跑移动端项目
- 适合验证响应式布局与移动端交互

适合：

- 改首页响应式样式
- 改移动端导航、卡片、侧边栏

#### 1.3.4 `npm run test:e2e:headed`

```bash
npm run test:e2e:headed
```

实际执行的是：

```bash
playwright test tests/e2e/full --headed
```

其中：

- `--headed`
  - 用有界面浏览器执行，而不是默认无头模式

特点：

- 更适合调试
- 能看到浏览器点击、跳转、输入过程
- 速度通常会比无头执行慢一些

适合：

- 复现失败用例
- 观察页面交互与弹窗行为

#### 1.3.5 `npm run test:e2e:core:chromium`

```bash
npm run test:e2e:core:chromium
```

实际执行的是：

```bash
playwright test setup-install.spec.ts auth-login.spec.ts groups-crud.spec.ts sites-basic.spec.ts settings-basic.spec.ts --project=chromium
```

特点：

- 只挑一组核心 spec
- 只跑桌面端 Chromium
- 速度比 Full 更快
- **不包含** 后续新增的高风险回归项，例如：
  - 登录锁定
  - 备份恢复
  - Cookie 策略
  - Proxy 待生效提示
  - 备份/恢复相关 dialog 取消路径

适合：

- 快速烟雾验证
- 改动不大时做一轮轻量自测
- 不能替代发版前的 `full:chromium` 或 `full`

#### 1.3.6 本地 npm 版会继承哪些默认配置

只要命令最终调用的是 `playwright test`，就还会继承 `playwright.config.ts` 中的公共配置，例如：

- `baseURL`
  - 默认是 `http://127.0.0.1:58080`
- `timeout: 60_000`
  - 单个测试默认超时 60 秒
- `workers: 1`
  - 串行执行，避免数据污染
- `retries: process.env.CI ? 1 : 0`
  - CI 环境自动重试 1 次
- `trace: 'retain-on-failure'`
  - 失败时保留 trace
- `screenshot: 'only-on-failure'`
  - 失败时保存截图

#### 1.3.7 如何临时覆盖本地 npm 版环境参数

例如想临时改测试地址，可以这样执行：

```bash
BASE_URL=http://127.0.0.1:58081 npm run test:e2e:full:chromium
```

含义：

- 临时把测试访问地址改成 `http://127.0.0.1:58081`
- 只对当前这次命令生效

---

## 第二章：Docker 环境测试教程

这里的“Docker 环境测试”指的是：

- 被测应用通过本地 Docker 开发容器提供
- Playwright 测试本身也通过测试容器执行
- 更适合环境统一、CI 对齐、环境复现

### 2.1 第一次环境配置教程

#### 2.1.1 需要准备哪些工具

Docker 测试环境至少需要：

- `Docker`
- `Docker Compose`

建议同时具备：

- `bash`
  - 用于执行项目里的脚本，例如 `local/docker-build.sh`

#### 2.1.2 如何验证这些工具是否已安装成功

执行：

```bash
docker --version
docker compose version
bash --version
```

判断标准：

- 如果能正常输出版本号，说明工具已安装成功
- 如果提示命令不存在或不可执行，需要先完成安装

#### 2.1.3 准备本地开发配置

首次建议复制：

```bash
cp local/.env.example local/.env
```

然后按需修改：

- 端口
- 数据目录
- 初始管理员信息
- 站点名称与域名

#### 2.1.4 如何验证本地开发配置已准备完成

最直接方式是确认文件已经存在：

```bash
ls local/.env
```

如果能看到 `local/.env`，说明配置文件已准备好。

#### 2.1.5 启动被测应用容器

Docker 版测试并不是单独就能运行，它依赖被测应用容器先启动。

执行：

```bash
bash local/docker-build.sh dev
```

#### 2.1.6 如何验证被测应用容器已启动成功

浏览器打开：

- `http://127.0.0.1:58080`
- `http://127.0.0.1:58080/login.php`

如果页面正常打开，说明宿主机侧开发环境已经就绪。

也可以用命令辅助确认：

```bash
curl -I http://127.0.0.1:58080
```

如果返回正常响应，通常说明站点已可访问。

#### 2.1.7 了解 Docker 测试由哪些 Compose 文件组成

Docker 测试通常会组合三份配置：

- `local/docker-compose.yml`
  - 基础服务配置
- `local/docker-compose.dev.yml`
  - 开发环境叠加配置
- `local/docker-compose.test.yml`
  - 测试专用服务配置

其中测试专用配置里定义了：

- `playwright-full`
- `playwright-mobile`
- `lighthouse`

#### 2.1.8 Docker 测试容器第一次运行会自动做什么

第一次运行测试服务时，通常会自动执行：

1. 检查容器卷里的 `node_modules`
2. 如缺少依赖，则自动执行 `npm install`
3. 如缺少浏览器缓存，则自动执行 `npx playwright install chromium`
4. 准备完成后再运行对应测试命令

因此第一次 Docker 测试通常会比本地 npm 更慢，这是正常现象。

#### 2.1.9 如何验证 Docker 测试环境已安装成功

推荐按下面顺序验证。

##### 第 1 步：确认 Docker 工具链可用

```bash
docker --version
docker compose version
```

##### 第 2 步：准备本地配置

```bash
cp local/.env.example local/.env
```

##### 第 3 步：启动被测应用容器

```bash
bash local/docker-build.sh dev
```

##### 第 4 步：确认站点可访问

浏览器打开：

- `http://127.0.0.1:58080`
- `http://127.0.0.1:58080/login.php`

##### 第 5 步：执行 Docker 版桌面端 Full 测试命令

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

##### 第 6 步：如需补跑移动端 Full，再执行

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```

这一步会覆盖更多已落地场景，包括：

- 登录锁定与 Session 失效
- 分组删除级联
- 站点校验边界
- Proxy 模式切换与待生效提示
- 备份恢复
- Cookie 策略
- UI confirm / modal 取消路径

##### 通过标准

满足下面几点，就可以认为 Docker 测试环境安装成功：

- `docker` 与 `docker compose` 可正常返回版本号
- `local/.env` 已准备好
- 本地开发容器能正常启动
- 宿主机能访问 `http://127.0.0.1:58080`
- Docker 测试命令能成功启动并进入执行流程
- 至少一条 Docker 版 Playwright 测试命令执行成功
- 测试结束后可以看到：
  - HTML 报告目录：`test-results/playwright-report-html/`
  - Markdown 报告文件：`test-results/playwright-report.md`
  - 测试中间产物目录：`test-artifacts/`

### 2.2 快速使用教程（按照 Full 版本运行）

#### 2.2.1 启动被测应用容器

```bash
bash local/docker-build.sh dev
```

#### 2.2.2 运行 Docker 版桌面端 Full E2E

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

这是 Docker 方案下最常用的 Full 回归命令。

#### 2.2.3 如果需要补跑移动端 Full

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```

#### 2.2.4 当前 Docker 版没有单条命令直接等价于 `npm run test:e2e:full`

也就是说，如果你想在 Docker 环境里完成“桌面端 + 移动端”的完整 Full，当前通常需要连续执行两条命令：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

再执行：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```

#### 2.2.5 最常见使用顺序

Docker 版建议顺序：

1. 启动开发容器
2. 跑 `playwright-full`
3. 如有需要，再跑 `playwright-mobile`
4. 如果改动涉及认证、备份恢复、Cookie、Proxy/Nginx、UI 弹窗这类高风险区域，优先确保 `playwright-full` 通过

#### 2.2.6 测试报告输出位置

执行 Docker 版 Playwright 测试后，当前项目同样会同时输出两份报告：

- HTML 报告目录：`playwright-report/`
- Markdown 报告文件：`test-results/playwright-report.md`

其中 Markdown 报告会包含：

- 报告生成日期
- 启动时间
- 结束时间
- 总用例数
- 各状态统计
- 失败或超时用例明细
- 全部用例结果表

### 2.3 详细参数说明

#### 2.3.1 Docker 命令通用结构说明

以这条命令为例：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

逐段说明：

- `docker compose`
  - 使用 Docker Compose 运行多容器配置
- `-f local/docker-compose.yml`
  - 加载基础 Compose 配置
- `-f local/docker-compose.dev.yml`
  - 叠加开发环境配置
- `-f local/docker-compose.test.yml`
  - 叠加测试专用配置
- `run`
  - 临时运行一个服务容器
- `--rm`
  - 执行结束后自动删除临时容器
- `playwright-full`
  - 服务名，决定容器里最终执行的测试命令

#### 2.3.2 `playwright-full`

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

对应的是：

- 服务名：`playwright-full`
- 容器内实际执行：`npm run test:e2e:full:chromium`

特点：

- 跑完整桌面端 Full E2E
- 是 Docker 方案下最常用的回归命令

#### 2.3.3 `playwright-mobile`

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```

对应的是：

- 服务名：`playwright-mobile`
- 容器内实际执行：`npm run test:e2e:full:mobile`

特点：

- 跑完整移动端 Full E2E
- 适合移动端展示与交互验证

#### 2.3.4 Docker 服务里内置的环境参数说明

测试服务里预置了这些重要环境变量：

- `BASE_URL=http://nav:58080`
  - 容器内访问被测站点的地址
  - `nav` 是 Compose 网络中的服务名
- `CI="1"`
  - 告诉 Playwright 当前按 CI 环境处理
  - 对应会启用 `retries: 1`
- `npm_config_registry=https://registry.npmmirror.com`
  - 容器内安装依赖时使用的 npm 镜像源

#### 2.3.6 Docker 版命令的前置逻辑说明

例如 `playwright-full` 在真正跑测试前，通常会先执行：

1. 检查 `node_modules/@playwright/test` 是否存在
2. 如不存在，则执行 `npm install`
3. 检查浏览器缓存是否存在
4. 如不存在，则执行 `npx playwright install chromium`
5. 准备完成后，再执行对应 npm script

这也是为什么 Docker 首次运行通常更慢。

#### 2.3.7 如何临时覆盖 Docker 版环境参数

例如临时覆盖 `BASE_URL`：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm -e BASE_URL=http://nav:58080 playwright-full
```

如果你明确知道目标地址可从容器访问，也可以改成其他地址。

#### 2.3.8 本地 npm 版与 Docker 版对应关系

| 目的 | 本地 npm 版 | Docker 版 |
|---|---|---|
| Full 桌面端回归 | `npm run test:e2e:full:chromium` | `docker compose ... run --rm playwright-full` |
| Full 移动端回归 | `npm run test:e2e:full:mobile` | `docker compose ... run --rm playwright-mobile` |
| 双项目全量 | `npm run test:e2e:full` | 当前无单条完全等价命令 |

---

## 总结

如果你只想记住最核心的使用方式，可以直接记下面几条：

### 本地测试环境

首次配置：

```bash
npm install
npx playwright install chromium
cp local/.env.example local/.env
bash local/docker-build.sh dev
```

最常用 Full 回归：

```bash
npm run test:e2e:full:chromium
```

完整全量：

```bash
npm run test:e2e:full
```

### Docker 测试环境

首次配置：

```bash
cp local/.env.example local/.env
bash local/docker-build.sh dev
```

最常用 Full 回归：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

如果还要补移动端：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```
