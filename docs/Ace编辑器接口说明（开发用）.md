# Ace 编辑器接口说明（开发用）

> 本文档面向前端开发维护人员，描述 Simple Homepage 中 NavAceEditor 统一弹窗组件的完整接口规范、配置项及使用示例。不面向终端用户。

---

## 目录

1. [前置依赖](#一前置依赖)
2. [NavAceEditor 全局对象](#二navaceeditor-全局对象)
3. [`open()` 完整配置](#三open-完整配置)
4. [按钮配置](#四按钮配置)
5. [内置自动行为](#五内置自动行为)
6. [使用模式：文本编辑器](#六使用模式文本编辑器)
7. [使用模式：日志中心](#七使用模式日志中心)
8. [生命周期时序](#八生命周期时序)

---

## 一、前置依赖

页面需先加载以下资源：

```html
<!-- Ace Editor 核心（admin/assets/ace/ 目录已包含） -->
<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>

<!-- 统一弹窗封装 -->
<?php require __DIR__ . '/shared/ace_editor_modal.php'; ?>
```

> **规范约束**：禁止各页面自行编写 Ace 初始化代码、弹窗 HTML、按钮 HTML。全部通过 `NavAceEditor` 全局对象调用统一接口完成。

---

## 二、NavAceEditor 全局对象

`NavAceEditor` 是全局单例，所有页面共用同一套 Ace 实例和弹窗 DOM。

### 方法清单

| 方法 | 返回值 | 说明 |
|------|--------|------|
| `NavAceEditor.init(options?)` | `this` | 懒加载初始化。首次调用创建 Ace 实例和弹窗 DOM；重复调用无操作。也可由 `open()` 自动触发。 |
| `NavAceEditor.open(options)` | `void` | 打开弹窗，根据配置渲染标题、按钮、工具栏，显示弹窗并聚焦编辑器。 |
| `NavAceEditor.close()` | `void` | 关闭弹窗。若内容已修改且 `confirmOnClose !== false`，先弹出确认框。 |
| `NavAceEditor.getValue()` | `string` | 获取当前编辑器内容。 |
| `NavAceEditor.setValue(text, mode?)` | `void` | 设置编辑器内容，可选同时切换语言模式。自动重置脏标记为「未修改」。 |
| `NavAceEditor.isDirty()` | `boolean` | 判断当前内容是否与打开时的初始内容不同。 |
| `NavAceEditor.markClean()` | `void` | 将当前内容设为新的「基准内容」，脏标记重置。通常在保存成功后调用。 |
| `NavAceEditor.setMode(mode)` | `void` | 动态切换语言模式。 |
| `NavAceEditor.setTheme(theme)` | `void` | 动态切换主题，并持久化到 `localStorage`。 |
| `NavAceEditor.setFontSize(px)` | `void` | 动态切换字号，并持久化到 `localStorage`。 |
| `NavAceEditor.setWrapMode(on)` | `void` | 动态开关自动换行，并持久化到 `localStorage`。 |
| `NavAceEditor.setTitle(title)` | `void` | 动态修改弹窗标题。 |
| `NavAceEditor.focus()` | `void` | 将焦点移入编辑器。 |
| `NavAceEditor.gotoLine(line, column?, animate?)` | `void` | 跳转到指定行号。 |
| `NavAceEditor.resize()` | `void` | 触发编辑器重新计算尺寸。 |
| `NavAceEditor.setButtonDisabled(action, disabled)` | `void` | 启用/禁用指定 `action` 的按钮。 |
| `NavAceEditor.setButtonVisible(action, visible)` | `void` | 显示/隐藏指定 `action` 的按钮。 |

---

## 三、`open()` 完整配置

```javascript
NavAceEditor.open({
  // ━━ 弹窗基础 ━━
  title: '文本编辑器',           // 弹窗标题
  value: '',                    // 编辑器初始内容（字符串）
  placeholder: '',              // 占位提示文本
  readOnly: false,              // 是否只读。true 时隐藏工具栏、禁用编辑、不显示脏标记
  confirmOnClose: true,         // 关闭时若内容有未保存修改，是否弹出确认提示

  // ━━ 编辑器配置 ━━
  mode: 'text',                 // 语言模式（见下表）
  theme: 'tomorrow_night',      // 主题（见下表）
  fontSize: 14,                 // 字号：12 ~ 20
  wrapMode: true,               // 是否自动换行
  tabSize: 2,                   // Tab 宽度（通常固定为 2）
  useSoftTabs: true,            // 是否使用空格代替 Tab（通常固定为 true）
  showPrintMargin: false,       // 是否显示打印边距线（通常固定为 false）
  useWorker: false,             // 是否启用 Ace Worker（通常固定为 false）

  // ━━ 底部自定义 HTML ━━
  footerHtml: '',               // 渲染在编辑器下方、按钮上方的自定义 HTML
                                // 常用于日志查看器的分页工具栏

  // ━━ 按钮配置 ━━
  buttons: {
    left:  [],                  // 工具栏按钮数组（脏标记 + 辅助操作）
    right: []                   // 工具栏按钮数组（主操作按钮）
                                 // 注意：所有按钮实际都渲染在工具栏左侧区域
  },

  // ━━ 回调函数 ━━
  onAction: function(action, value) {},   // 按钮点击/快捷键统一回调
  onChange: function(value, dirty) {},    // 内容变化回调（每次输入触发）
  onClose: function() {},                 // 弹窗关闭回调（无论是否保存都触发）
  onInit: function(editor) {}             // 编辑器初始化完成回调（首次 init 时触发一次）
});
```

### 支持的语言模式

| mode 值 | 语言 |
|---------|------|
| `text` | Plain Text |
| `php` | PHP |
| `javascript` | JavaScript |
| `html` | HTML |
| `css` | CSS |
| `json` | JSON |
| `yaml` | YAML |
| `sh` | Shell |
| `nginx` | Nginx |
| `ini` | INI |
| `xml` | XML |
| `sql` | SQL |
| `markdown` | Markdown |
| `python` | Python |
| `golang` | Go |
| `rust` | Rust |
| `c_cpp` | C/C++ |

### 支持的主题

| theme 值 | 名称 |
|----------|------|
| `tomorrow_night` | Tomorrow Night（默认） |
| `monokai` | Monokai |
| `github_dark` | GitHub Dark |
| `dracula` | Dracula |

---

## 四、按钮配置

按钮配置项说明：

```javascript
{
  // 方式一：特殊类型按钮
  type: 'dirty',                // 脏标记，自动监听内容变化，显示「未修改 / 有未保存修改」

  // 方式二：普通操作按钮
  text: '保存',                 // 按钮显示文本
  action: 'save',               // 按钮动作标识符，点击后触发 onAction(action, value)
  bgColor: '#4a9eff',           // 自定义背景色（推荐方式）
  class: 'btn-primary',         // 向后兼容：语义样式类（btn-primary / btn-secondary / btn-danger / btn-success / btn-warning）
  visible: true,                // 是否渲染。支持 boolean 或返回 boolean 的函数
  disabled: false               // 是否禁用。支持 boolean 或返回 boolean 的函数
}
```

### 保留 action 关键字

| action | 自动行为 |
|--------|----------|
| `save` | 自动绑定 `Ctrl+S` / `Cmd+S` 快捷键 |
| `close` | 自动绑定 `Esc` 键和弹窗关闭事件；不在工具栏渲染独立按钮（由标题栏 × 按钮替代） |

### 样式规则

- 所有按钮统一使用 `.nav-ace-toolbar-btn` 基础样式
- **只允许通过 `bgColor` 属性改变背景色**，禁止通过 `class` 传入自定义样式类改变按钮外观
- `{ type: 'dirty' }` 不渲染为按钮，而是显示在弹窗标题栏的脏状态区域

---

## 五、内置自动行为

页面无需手动处理以下行为：

| 行为 | 触发条件 | 说明 |
|------|---------|------|
| **Ctrl-S 保存** | `Ctrl+S` / `Cmd+S` | 若存在 `action: 'save'` 且可见，自动触发 `onAction('save', value)` |
| **Esc 关闭** | `Escape` 键 | 全屏时先退出全屏；否则若存在 `action: 'close'` 触发 `onAction('close')`，否则直接关闭 |
| **Ctrl-F 查找** | `Ctrl+F` | Ace 内置查找框（需加载 `ext-searchbox.js`） |
| **Ctrl-G 跳转行号** | `Ctrl+G` | 打开行号跳转输入栏 |
| **脏标记自动更新** | 内容变化时 | 若配置了 `{ type: 'dirty' }`，自动对比当前值与 `initialValue`，在标题栏显示状态 |
| **关闭前确认** | 点击关闭 / 按 Esc / 点击蒙层 | 若 `confirmOnClose !== false` 且 `isDirty()` 为 true，弹出确认对话框 |
| **localStorage 持久化** | 用户切换主题/字号/换行 | 自动保存用户偏好，下次打开时恢复 |
| **弹窗打开自动聚焦** | `open()` 后 | 10ms 延迟后自动聚焦编辑器 |
| **窗口大小变化自适应** | 浏览器 resize | 自动调用 `editor.resize()` |
| **bfcache 清理** | 浏览器后退恢复 | 自动关闭残留的弹窗 |
| **触摸手势拦截** | 触摸滑动 | 防止边缘滑动返回/关闭 |

---

## 六、使用模式：文本编辑器

适用于配置文件编辑、脚本编辑等需要修改保存的场景。

### 典型配置（Nginx 配置编辑）

```javascript
NavAceEditor.open({
  title: '编辑 Nginx 配置',
  mode: 'nginx',
  value: document.getElementById('nginx-content').value,
  wrapMode: true,
  buttons: {
    left: [
      { type: 'dirty' },
      { text: '检查语法', action: 'syntax', bgColor: '#5a6c7d' }
    ],
    right: [
      { text: '关闭', action: 'close' },
      { text: '保存', action: 'save', bgColor: '#4a9eff' },
      { text: '保存并 Reload', action: 'save_reload', bgColor: '#3dba6a' }
    ]
  },
  onAction: function(action, value) {
    if (action === 'close') {
      NavAceEditor.close();
      return;
    }
    if (action === 'save_reload') {
      NavConfirm.open({
        title: '保存并 Reload',
        message: '确认保存并重新加载 Nginx？',
        confirmText: '确认',
        onConfirm: function() {
          doSave(value, action);
        }
      });
      return;
    }
    doSave(value, action);
  }
});

function doSave(value, action) {
  // 禁用保存按钮防止重复提交
  NavAceEditor.setButtonDisabled('save', true);
  NavAceEditor.setButtonDisabled('save_reload', true);

  fetch('/admin/nginx_ajax.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams({
      action: 'save',
      content: value,
      reload: action === 'save_reload' ? '1' : '0',
      _csrf: window._csrf
    })
  })
  .then(r => r.json())
  .then(data => {
    NavAceEditor.setButtonDisabled('save', false);
    NavAceEditor.setButtonDisabled('save_reload', false);
    if (data.ok) {
      NavAceEditor.markClean();
      showToast('保存成功', 'success');
    } else {
      showToast(data.msg || '保存失败', 'error');
    }
  });
}
```

### 典型配置（计划任务脚本编辑）

```javascript
NavAceEditor.open({
  title: '编辑计划任务脚本 · ' + taskName,
  mode: 'sh',
  value: scriptContent,
  wrapMode: true,
  buttons: {
    left: [{ type: 'dirty' }],
    right: [
      { text: '关闭', action: 'close' },
      { text: '保存', action: 'save', bgColor: '#4a9eff' }
    ]
  },
  onAction: function(action, value) {
    if (action === 'save') {
      NavAceEditor.setButtonDisabled('save', true);
      fetch('/admin/scheduled_tasks_ajax.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({
          action: 'save_script',
          id: taskId,
          command: value,
          _csrf: window._csrf
        })
      })
      .then(r => r.json())
      .then(data => {
        NavAceEditor.setButtonDisabled('save', false);
        if (data.ok) {
          document.getElementById('task-command').value = value;
          NavAceEditor.markClean();
          showToast('脚本已保存', 'success');
        } else {
          showToast(data.msg || '保存失败', 'error');
        }
      });
    }
    if (action === 'close') {
      NavAceEditor.close();
    }
  }
});
```

---

## 七、使用模式：日志中心

日志查看使用 `readOnly: true`，隐藏工具栏，通过 `footerHtml` 构建底部分页控制栏。

### 基础日志查看器

```javascript
NavAceEditor.open({
  title: '日志查看 · ' + logName,
  mode: 'text',
  value: '加载中…',
  readOnly: true,
  wrapMode: true,
  buttons: {
    left: [],
    right: [{ text: '关闭', action: 'close' }]
  },
  onAction: function(action) {
    if (action === 'close') NavAceEditor.close();
  }
});

// 加载日志内容
fetch('/admin/logs_api.php?file=' + encodeURIComponent(logName))
  .then(r => r.json())
  .then(data => {
    NavAceEditor.setValue(data.content || '（空日志）');
  });
```

### 带分页工具栏的日志查看器（参考 logs.php）

```javascript
var aceLogState = { page: 1, pages: 1, total_lines: 0 };

function openLogViewer(logName) {
  var footerHtml = '<div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;width:100%">'
    + '<span id="ace-log-info" style="font-size:12px;color:var(--tm);font-family:var(--mono)">加载中…</span>'
    + '<button class="btn btn-sm btn-secondary" id="ace-log-prev" onclick="aceLogGoPage(aceLogState.page - 1)">◀ 上一页</button>'
    + '<span id="ace-log-page-label" style="font-size:12px;font-family:var(--mono);color:var(--tx2)">第 1 / 1 页</span>'
    + '<button class="btn btn-sm btn-secondary" id="ace-log-next" onclick="aceLogGoPage(aceLogState.page + 1)">下一页 ▶</button>'
    + '<button class="btn btn-sm btn-secondary" onclick="aceLogGoPage(1)" title="第一页">⏮</button>'
    + '<button class="btn btn-sm btn-secondary" id="ace-log-last-btn" onclick="aceLogGoPage(aceLogState.pages)" title="最后一页">⏭</button>'
    + '</div>';

  NavAceEditor.open({
    title: '日志查看 · ' + logName,
    mode: 'text',
    value: '加载中…',
    readOnly: true,
    wrapMode: true,
    footerHtml: footerHtml,
    buttons: { left: [], right: [] },
    onAction: function(action) {}
  });

  // 加载第一页
  aceLogLoadPage(1);
}

function aceLogGoPage(page) {
  if (page < 1 || page > aceLogState.pages) return;
  aceLogLoadPage(page);
}

function aceLogLoadPage(page) {
  fetch('/admin/logs_api.php?file=' + encodeURIComponent(logName) + '&page=' + page)
    .then(r => r.json())
    .then(d => {
      aceLogState.page = d.page;
      aceLogState.pages = d.pages;
      aceLogState.total_lines = d.total_lines;

      NavAceEditor.setValue(d.content || '（空）');

      // 更新 footer 控件状态
      var infoEl = document.getElementById('ace-log-info');
      var labelEl = document.getElementById('ace-log-page-label');
      var prevBtn = document.getElementById('ace-log-prev');
      var nextBtn = document.getElementById('ace-log-next');

      if (infoEl) infoEl.textContent = '共 ' + d.total_lines + ' 行，每页 ' + d.per_page + ' 行';
      if (labelEl) labelEl.textContent = '第 ' + d.page + ' / ' + d.pages + ' 页';
      if (prevBtn) prevBtn.disabled = d.page <= 1;
      if (nextBtn) nextBtn.disabled = d.page >= d.pages;
    });
}
```

### 带自动轮询和清空的日志查看器（参考 scheduled_tasks.php 运行日志）

```javascript
var logPollTimer = 0;
var aceLogState = { page: 1, pages: 1 };

function openTaskLogViewer(taskId, taskName) {
  var footerHtml = '<div style="display:flex;align-items:center;justify-content:space-between;width:100%">'
    + '<span id="ace-log-info" style="font-size:12px;color:var(--tm);font-family:var(--mono)">加载中…</span>'
    + '<div style="display:flex;align-items:center;gap:6px">'
    + '<button class="btn btn-sm btn-secondary" id="ace-log-prev" onclick="aceLogGoPage(aceLogState.page - 1)">◀ 上一页</button>'
    + '<span id="ace-log-page-label" style="font-size:12px;font-family:var(--mono);color:var(--tx2)">第 1 / 1 页</span>'
    + '<button class="btn btn-sm btn-secondary" id="ace-log-next" onclick="aceLogGoPage(aceLogState.page + 1)">下一页 ▶</button>'
    + '<button class="btn btn-sm btn-secondary" onclick="aceLogGoPage(1)">⏮</button>'
    + '<button class="btn btn-sm btn-secondary" id="ace-log-last-btn" onclick="aceLogGoPage(aceLogState.pages)">⏭</button>'
    + '</div></div>';

  NavAceEditor.open({
    title: '运行日志 · ' + taskName,
    mode: 'text',
    value: '加载中…',
    readOnly: true,
    wrapMode: true,
    footerHtml: footerHtml,
    buttons: {
      left: [{ text: '🗑 清空日志', action: 'clear', bgColor: '#e74c3c' }],
      right: [{ text: '关闭', action: 'close' }]
    },
    onAction: function(action) {
      if (action === 'close') {
        NavAceEditor.close();
        return;
      }
      if (action === 'clear') {
        NavConfirm.open({
          title: '清空日志',
          message: '确认清空该任务的运行日志？此操作不可恢复。',
          confirmText: '清空',
          danger: true,
          onConfirm: function() {
            fetch('/admin/api/task_log.php', {
              method: 'POST',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              body: new URLSearchParams({ action: 'clear', task_id: taskId, _csrf: window._csrf })
            })
            .then(r => r.json())
            .then(data => {
              if (data.ok) {
                aceLogState.page = 1;
                aceLogLoadPage(1);
                showToast('日志已清空', 'success');
              }
            });
          }
        });
      }
    },
    onClose: function() {
      if (logPollTimer) {
        clearInterval(logPollTimer);
        logPollTimer = 0;
      }
    }
  });

  // 加载第一页，然后跳转到最后一页
  aceLogLoadPage(1, function() {
    if (aceLogState.pages > 1) {
      aceLogGoPage(aceLogState.pages);
    }
  });

  // 每 2 秒自动轮询最后一页
  logPollTimer = setInterval(function() {
    if (aceLogState.page >= aceLogState.pages) {
      aceLogLoadPage(aceLogState.pages);
    }
  }, 2000);
}

function aceLogLoadPage(page, callback) {
  fetch('/admin/api/task_log.php?task_id=' + taskId + '&page=' + page)
    .then(r => r.json())
    .then(d => {
      aceLogState.page = d.page;
      aceLogState.pages = d.pages;

      NavAceEditor.setValue(d.content || '（暂无日志）');

      // 更新 footer
      var infoEl = document.getElementById('ace-log-info');
      var labelEl = document.getElementById('ace-log-page-label');
      if (infoEl) infoEl.textContent = '共 ' + d.total_lines + ' 行';
      if (labelEl) labelEl.textContent = '第 ' + d.page + ' / ' + d.pages + ' 页';

      // 跳转到最后一行
      if (d.content) {
        var lines = d.content.split('\n').length;
        NavAceEditor.gotoLine(lines, 0, false);
      }

      if (callback) callback();
    });
}
```

### 文本编辑器 vs 日志中心对比

| 特性 | 文本编辑器模式 | 日志中心模式 |
|------|---------------|-------------|
| `readOnly` | `false` | `true` |
| 工具栏显示 | ✅ 显示（语言、主题、字号、换行等） | ❌ 隐藏 |
| 脏标记 | ✅ `{ type: 'dirty' }` | ❌ 无需 |
| 保存按钮 | ✅ 有 | ❌ 自动过滤 |
| `footerHtml` | 可选（如只读警告信息） | **必须**（分页控制栏） |
| 关闭确认 | ✅ 默认开启 | 可保留默认 |
| 自动轮询 | 否 | 是（日志实时刷新） |
| 典型按钮 | `[dirty, 语法检查, 关闭, 保存, 保存并Reload]` | `[清空日志, 关闭]` 或 `[关闭]` |

---

## 八、生命周期时序

```
页面加载
  → NavAceEditor.init()          // 可选预初始化
       → 创建 Ace 实例
       → 渲染弹窗 DOM
       → 绑定事件、快捷键、resize 监听
       → 从 localStorage 恢复用户偏好
       → 触发 onInit(editor)

用户触发打开
  → NavAceEditor.open(options)
       → 设置标题、编辑器配置
       → 写入初始内容，记录 initialValue
       → 渲染按钮到工具栏
       → 写入 footerHtml
       → 显示弹窗、聚焦编辑器

用户编辑
  → Ace 'change' 事件
       → 自动更新 dirty 状态（标题栏显示）
       → 触发 onChange(value, dirty)

用户点击按钮 / 快捷键
  → 识别 action
       → 触发 onAction(action, value)
       → 页面处理业务逻辑

用户关闭弹窗
  → NavAceEditor.close()
       → 若 dirty 且 confirmOnClose → 弹出确认
       → 隐藏弹窗、退出全屏、清空 footer
       → 触发 onClose()
```

---

## 附录：相关源码文件速查

| 功能 | 文件路径 |
|------|----------|
| Ace Editor 弹窗封装 | `admin/shared/ace_editor_modal.php` |
| Nginx 配置编辑器示例 | `admin/nginx.php` |
| 日志查看器示例 | `admin/logs.php` |
| 计划任务脚本/日志示例 | `admin/scheduled_tasks.php` |
