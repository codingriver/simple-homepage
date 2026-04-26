<?php
/**
 * NavAceEditor 统一弹窗封装
 *
 * 使用方式：
 *   1. 页面加载 ace.js 和 ext-searchbox.js（<script src="assets/ace/ace.js"></script>）
 *   2. 在合适位置 require __DIR__ . '/shared/ace_editor_modal.php'
 *   3. 调用 NavAceEditor.open({...}) / NavAceEditor.close()
 *
 * 禁止各页面自行编写 Ace 初始化代码、弹窗 HTML、按钮 HTML。
 */
?>

<!-- ========== NavAceEditor 统一弹窗 ========== -->
<div id="nav-ace-editor-modal" class="ngx-modal" onclick="if(event.target===this)NavAceEditor.close()">
  <div class="ngx-modal-card" id="nav-ace-modal-card">
    <div class="ngx-modal-head">
      <div class="ngx-modal-title" id="nav-ace-title">文本编辑器</div>
      <button type="button" class="btn btn-secondary ngx-close-btn" onclick="NavAceEditor.close()">×</button>
    </div>
    <div class="ngx-modal-body">
      <div class="ngx-editor-toolbar" id="nav-ace-toolbar">
        <button type="button" class="btn btn-secondary" id="nav-ace-btn-find">查找 (Ctrl+F)</button>
        <button type="button" class="btn btn-secondary" id="nav-ace-btn-goto">跳转行号 (Ctrl+G)</button>
        <label>语言
          <select id="nav-ace-lang">
            <option value="text">Plain Text</option>
            <option value="php">PHP</option>
            <option value="javascript">JavaScript</option>
            <option value="html">HTML</option>
            <option value="css">CSS</option>
            <option value="json">JSON</option>
            <option value="yaml">YAML</option>
            <option value="sh">Shell</option>
            <option value="nginx">Nginx</option>
            <option value="ini">INI</option>
            <option value="xml">XML</option>
            <option value="sql">SQL</option>
            <option value="markdown">Markdown</option>
            <option value="python">Python</option>
            <option value="golang">Go</option>
            <option value="rust">Rust</option>
            <option value="c_cpp">C/C++</option>
          </select>
        </label>
        <label>主题
          <select id="nav-ace-theme">
            <option value="tomorrow_night">Tomorrow Night</option>
            <option value="monokai">Monokai</option>
            <option value="github_dark">GitHub Dark</option>
            <option value="dracula">Dracula</option>
          </select>
        </label>
        <label>字号
          <select id="nav-ace-fontsize">
            <option value="12">12px</option>
            <option value="13">13px</option>
            <option value="14" selected>14px</option>
            <option value="15">15px</option>
            <option value="16">16px</option>
            <option value="18">18px</option>
            <option value="20">20px</option>
          </select>
        </label>
        <label><input type="checkbox" id="nav-ace-wrap" checked> 自动换行</label>
        <label><input type="checkbox" id="nav-ace-focus"> 沉浸模式</label>
      </div>
      <div id="nav-ace-editor" class="ngx-editor-main"></div>
      <div class="ngx-editor-actions">
        <div class="ngx-editor-actions-left" id="nav-ace-actions-left"></div>
        <div class="ngx-editor-actions-right" id="nav-ace-actions-right"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  'use strict';

  // ── 内部状态 ──
  var editor = null;
  var modal = null;
  var modalCard = null;
  var config = {};
  var initialValue = '';
  var dirty = false;
  var inited = false;

  // ── DOM 缓存 ──
  var els = {};

  function cacheElements() {
    els.modal = document.getElementById('nav-ace-editor-modal');
    els.modalCard = document.getElementById('nav-ace-modal-card');
    els.title = document.getElementById('nav-ace-title');
    els.toolbar = document.getElementById('nav-ace-toolbar');
    els.lang = document.getElementById('nav-ace-lang');
    els.theme = document.getElementById('nav-ace-theme');
    els.fontsize = document.getElementById('nav-ace-fontsize');
    els.wrap = document.getElementById('nav-ace-wrap');
    els.focus = document.getElementById('nav-ace-focus');
    els.actionsLeft = document.getElementById('nav-ace-actions-left');
    els.actionsRight = document.getElementById('nav-ace-actions-right');
  }

  // ── 初始化 ──
  function init(options) {
    if (inited) return;
    options = options || {};
    cacheElements();
    if (!els.modal) return;

    // 等待 ace 就绪
    if (typeof ace === 'undefined') {
      setTimeout(function() { init(options); }, 100);
      return;
    }

    editor = ace.edit('nav-ace-editor');
    editor.setTheme('ace/theme/' + (options.theme || 'tomorrow_night'));
    editor.session.setUseWrapMode(options.wrapMode !== false);
    editor.session.setTabSize(options.tabSize || 2);
    editor.session.setUseSoftTabs(options.useSoftTabs !== false);
    editor.setOptions({
      fontSize: (options.fontSize || 14) + 'px',
      showPrintMargin: false,
      useWorker: false,
      enableBasicAutocompletion: false,
      enableLiveAutocompletion: false,
      enableSnippets: false
    });

    // 快捷键
    editor.commands.addCommand({
      name: 'navAceSave',
      bindKey: { win: 'Ctrl-S', mac: 'Command-S' },
      exec: function() {
        var btn = findButtonByAction('save');
        if (btn && !btn.disabled && isButtonVisible(btn)) {
          handleAction('save');
        }
      }
    });
    editor.commands.addCommand({
      name: 'navAceGoto',
      bindKey: { win: 'Ctrl-G', mac: 'Command-G' },
      exec: function() { editor.execCommand('gotoline'); }
    });

    // 内容变化监听
    editor.session.on('change', function() {
      updateDirty();
      if (typeof config.onChange === 'function') {
        try { config.onChange(editor.getValue(), dirty); } catch(e) {}
      }
    });

    // 工具栏事件
    bindToolbarEvents();

    // 窗口 resize
    window.addEventListener('resize', function() {
      if (editor && els.modal && els.modal.classList.contains('open')) {
        editor.resize();
      }
    });

    // Esc 关闭
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && els.modal && els.modal.classList.contains('open')) {
        var btn = findButtonByAction('close');
        if (btn && isButtonVisible(btn)) {
          handleAction('close');
        } else {
          NavAceEditor.close();
        }
      }
    });

    inited = true;

    if (typeof config.onInit === 'function') {
      try { config.onInit(editor); } catch(e) {}
    }
  }

  function bindToolbarEvents() {
    if (els.lang) {
      els.lang.addEventListener('change', function() {
        var mode = this.value || 'text';
        editor.session.setMode('ace/mode/' + mode);
      });
    }
    if (els.theme) {
      els.theme.addEventListener('change', function() {
        var t = this.value || 'tomorrow_night';
        editor.setTheme('ace/theme/' + t);
        try { localStorage.setItem('nav-ace-theme', t); } catch(e) {}
      });
    }
    if (els.fontsize) {
      els.fontsize.addEventListener('change', function() {
        var s = this.value || '14';
        editor.setFontSize(s + 'px');
        try { localStorage.setItem('nav-ace-fontsize', s); } catch(e) {}
      });
    }
    if (els.wrap) {
      els.wrap.addEventListener('change', function() {
        editor.session.setUseWrapMode(!!this.checked);
        try { localStorage.setItem('nav-ace-wrap', !!this.checked ? '1' : '0'); } catch(e) {}
      });
    }
    if (els.focus) {
      els.focus.addEventListener('change', function() {
        applyFocusMode(!!this.checked);
      });
    }

    document.getElementById('nav-ace-btn-find').addEventListener('click', function() {
      if (editor) editor.execCommand('find');
    });
    document.getElementById('nav-ace-btn-goto').addEventListener('click', function() {
      if (editor) editor.execCommand('gotoline');
    });

    // 恢复 localStorage 偏好
    var savedTheme = '';
    try { savedTheme = localStorage.getItem('nav-ace-theme') || ''; } catch(e) {}
    if (savedTheme && els.theme) { editor.setTheme('ace/theme/' + savedTheme); els.theme.value = savedTheme; }

    var savedSize = '';
    try { savedSize = localStorage.getItem('nav-ace-fontsize') || ''; } catch(e) {}
    if (savedSize && els.fontsize) { editor.setFontSize(savedSize + 'px'); els.fontsize.value = savedSize; }

    var savedWrap = '';
    try { savedWrap = localStorage.getItem('nav-ace-wrap') || ''; } catch(e) {}
    if (savedWrap && els.wrap) {
      var wrapOn = savedWrap === '1';
      editor.session.setUseWrapMode(wrapOn);
      els.wrap.checked = wrapOn;
    }
  }

  // ── 脏标记 ──
  function updateDirty() {
    if (!editor) return;
    dirty = editor.getValue() !== initialValue;
    var hint = document.getElementById('nav-ace-dirty-hint');
    if (hint) {
      hint.textContent = dirty ? '有未保存修改' : '未修改';
      hint.style.color = dirty ? 'var(--yellow)' : 'var(--tm)';
    }
  }

  // ── 按钮辅助 ──
  function findButtonByAction(action) {
    if (!els.actionsLeft || !els.actionsRight) return null;
    var all = els.actionsLeft.querySelectorAll('button[data-action]');
    for (var i = 0; i < all.length; i++) {
      if (all[i].getAttribute('data-action') === action) return all[i];
    }
    all = els.actionsRight.querySelectorAll('button[data-action]');
    for (var i = 0; i < all.length; i++) {
      if (all[i].getAttribute('data-action') === action) return all[i];
    }
    return null;
  }

  function isButtonVisible(btn) {
    return btn && btn.style.display !== 'none';
  }

  function renderButtons() {
    if (!els.actionsLeft || !els.actionsRight) return;
    els.actionsLeft.innerHTML = '';
    els.actionsRight.innerHTML = '';

    var btns = config.buttons || {};
    var left = btns.left || [];
    var right = btns.right || [];

    // 只读模式：隐藏保存按钮，不显示脏标记
    if (config.readOnly) {
      left = left.filter(function(b) { return b.type === 'dirty' ? false : true; });
      right = right.filter(function(b) { return b.action !== 'save'; });
    }

    left.forEach(function(btnCfg) {
      var el = createButtonEl(btnCfg);
      if (el) els.actionsLeft.appendChild(el);
    });
    right.forEach(function(btnCfg) {
      var el = createButtonEl(btnCfg);
      if (el) els.actionsRight.appendChild(el);
    });
  }

  function createButtonEl(btnCfg) {
    // 可见性判断
    var visible = true;
    if (typeof btnCfg.visible === 'function') {
      try { visible = !!btnCfg.visible(); } catch(e) { visible = true; }
    } else if (typeof btnCfg.visible === 'boolean') {
      visible = btnCfg.visible;
    }
    if (!visible) return null;

    // 脏标记特殊处理
    if (btnCfg.type === 'dirty') {
      var span = document.createElement('span');
      span.id = 'nav-ace-dirty-hint';
      span.style.fontSize = '12px';
      span.style.color = 'var(--tm)';
      span.textContent = '未修改';
      return span;
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = btnCfg.class || 'btn btn-secondary';
    btn.textContent = btnCfg.text || '';
    btn.setAttribute('data-action', btnCfg.action || '');

    // 禁用态
    var disabled = false;
    if (typeof btnCfg.disabled === 'function') {
      try { disabled = !!btnCfg.disabled(); } catch(e) { disabled = false; }
    } else if (typeof btnCfg.disabled === 'boolean') {
      disabled = btnCfg.disabled;
    }
    btn.disabled = disabled;

    btn.addEventListener('click', function() {
      if (btnCfg.action) handleAction(btnCfg.action);
    });

    return btn;
  }

  function handleAction(action) {
    var value = editor ? editor.getValue() : '';
    if (action === 'close') {
      NavAceEditor.close();
      return;
    }
    if (typeof config.onAction === 'function') {
      try { config.onAction(action, value); } catch(e) { console.error('NavAceEditor onAction error', e); }
    }
  }

  // ── 沉浸模式 ──
  function applyFocusMode(enabled) {
    if (!els.modalCard) return;
    els.modalCard.classList.toggle('focus-mode', !!enabled);
    if (els.focus) els.focus.checked = !!enabled;
    setTimeout(function() { if (editor) editor.resize(); }, 20);
  }

  // ── 全局暴露 ──
  window.NavAceEditor = {
    init: function(options) {
      init(options);
      return this;
    },

    open: function(options) {
      options = options || {};
      config = options;
      init(options);
      if (!inited || !editor || !els.modal) return;

      // 标题
      if (els.title) els.title.textContent = options.title || '文本编辑器';

      // 工具栏：只读模式隐藏部分控件
      if (els.toolbar) {
        els.toolbar.style.display = options.readOnly ? 'none' : '';
      }

      // 编辑器设置
      editor.setReadOnly(!!options.readOnly);
      if (options.mode) {
        editor.session.setMode('ace/mode/' + options.mode);
        if (els.lang) els.lang.value = options.mode;
      } else {
        editor.session.setMode('ace/mode/text');
        if (els.lang) els.lang.value = 'text';
      }
      if (options.theme) {
        editor.setTheme('ace/theme/' + options.theme);
        if (els.theme) els.theme.value = options.theme;
      }
      if (options.fontSize) {
        editor.setFontSize(options.fontSize + 'px');
        if (els.fontsize) els.fontsize.value = String(options.fontSize);
      }
      if (typeof options.wrapMode === 'boolean') {
        editor.session.setUseWrapMode(options.wrapMode);
        if (els.wrap) els.wrap.checked = options.wrapMode;
      }

      // 内容
      var val = options.value !== undefined ? String(options.value) : '';
      editor.setValue(val, -1);
      initialValue = val;
      dirty = false;
      updateDirty();

      // 渲染按钮
      renderButtons();

      // 显示弹窗
      els.modal.classList.add('open');
      applyFocusMode(false);
      setTimeout(function() {
        if (editor) { editor.resize(); editor.focus(); }
      }, 10);
    },

    close: function() {
      if (!els.modal || !els.modal.classList.contains('open')) return;

      // 未保存确认
      if (config.confirmOnClose !== false && dirty) {
        if (!confirm('编辑器中有未保存的修改，确认关闭？')) return;
      }

      els.modal.classList.remove('open');
      applyFocusMode(false);
      dirty = false;
      initialValue = '';

      if (typeof config.onClose === 'function') {
        try { config.onClose(); } catch(e) {}
      }
      config = {};
    },

    getValue: function() {
      return editor ? editor.getValue() : '';
    },

    setValue: function(text, mode) {
      if (!editor) return;
      editor.setValue(text !== undefined ? String(text) : '', -1);
      if (mode) {
        editor.session.setMode('ace/mode/' + mode);
        if (els.lang) els.lang.value = mode;
      }
      initialValue = editor.getValue();
      dirty = false;
      updateDirty();
    },

    isDirty: function() {
      return dirty;
    },

    markClean: function() {
      if (!editor) return;
      initialValue = editor.getValue();
      dirty = false;
      updateDirty();
    },

    setMode: function(mode) {
      if (!editor) return;
      editor.session.setMode('ace/mode/' + mode);
      if (els.lang) els.lang.value = mode;
    },

    setTheme: function(theme) {
      if (!editor) return;
      editor.setTheme('ace/theme/' + theme);
      if (els.theme) els.theme.value = theme;
      try { localStorage.setItem('nav-ace-theme', theme); } catch(e) {}
    },

    setFontSize: function(px) {
      if (!editor) return;
      editor.setFontSize(px + 'px');
      if (els.fontsize) els.fontsize.value = String(px);
      try { localStorage.setItem('nav-ace-fontsize', String(px)); } catch(e) {}
    },

    setWrapMode: function(on) {
      if (!editor) return;
      editor.session.setUseWrapMode(!!on);
      if (els.wrap) els.wrap.checked = !!on;
      try { localStorage.setItem('nav-ace-wrap', !!on ? '1' : '0'); } catch(e) {}
    },

    setTitle: function(title) {
      if (els.title) els.title.textContent = title || '文本编辑器';
    },

    focus: function() {
      if (editor) editor.focus();
    },

    gotoLine: function(line, column, animate) {
      if (editor) editor.gotoLine(line, column || 0, animate !== false);
    },

    resize: function() {
      if (editor) editor.resize();
    },

    setButtonDisabled: function(action, disabled) {
      var btn = findButtonByAction(action);
      if (btn) btn.disabled = !!disabled;
    },

    setButtonVisible: function(action, visible) {
      var btn = findButtonByAction(action);
      if (btn) btn.style.display = visible ? '' : 'none';
    }
  };
})();
</script>
