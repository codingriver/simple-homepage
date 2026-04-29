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
<div id="nav-ace-editor-modal" class="ngx-modal">
  <div class="ngx-modal-card" id="nav-ace-modal-card">
    <div class="ngx-modal-head">
      <div class="ngx-modal-title-wrap">
        <span class="ngx-modal-title" id="nav-ace-title">文本编辑器</span>
        <span class="ngx-modal-dirty-status" id="nav-ace-dirty-status">未修改</span>
      </div>
      <div class="ngx-modal-head-actions">
        <button type="button" class="btn btn-secondary ngx-fullscreen-btn" id="nav-ace-fullscreen" title="放大">⛶</button>
        <button type="button" class="btn btn-secondary ngx-close-btn" onclick="NavAceEditor.close()">×</button>
      </div>
    </div>
    <div class="ngx-modal-body">
      <div class="ngx-editor-toolbar" id="nav-ace-toolbar">
        <div class="ngx-editor-toolbar-actions" id="nav-ace-toolbar-actions"></div>
        <button type="button" class="btn btn-secondary" id="nav-ace-btn-find">查找 (Ctrl+F)</button>
        <button type="button" class="btn btn-secondary" id="nav-ace-btn-goto">跳转行号 (Ctrl+G)</button>
        <span class="toolbar-sep"></span>
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
        <span class="toolbar-sep"></span>
        <label><input type="checkbox" id="nav-ace-wrap" checked> 自动换行</label>
      </div>
      <div class="ngx-editor-goto-bar" id="nav-ace-goto-bar">
        <span>跳转到行号</span>
        <input type="number" id="nav-ace-goto-input" placeholder="行号" min="1" autocomplete="off">
        <button type="button" class="btn btn-secondary" id="nav-ace-goto-confirm">跳转</button>
        <button type="button" class="btn btn-secondary" id="nav-ace-goto-cancel">取消</button>
      </div>
      <div id="nav-ace-editor" class="ngx-editor-main"></div>
      <div class="ngx-editor-footer" id="nav-ace-footer" style="display:none"></div>
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
    els.actionsLeft = document.getElementById('nav-ace-actions-left');
    els.actionsRight = document.getElementById('nav-ace-actions-right');
    els.footer = document.getElementById('nav-ace-footer');
    els.toolbarActions = document.getElementById('nav-ace-toolbar-actions');
    els.fullscreenBtn = document.getElementById('nav-ace-fullscreen');
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
      exec: function() { openGotoBar(); }
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

    // 弹窗手势拦截（防止滑动误关闭）
    var modalTouchStartX = 0;
    var modalTouchStartY = 0;
    var modalTouchMoved = false;

    function onModalClick(e) {
      if (e.target === els.modal) {
        // 触摸滑动后触发的 click 不关闭
        if (modalTouchMoved) {
          e.preventDefault();
          e.stopPropagation();
          modalTouchMoved = false;
          return false;
        }
        NavAceEditor.close();
      }
    }
    function onModalTouchStart(e) {
      if (e.touches && e.touches.length === 1) {
        modalTouchStartX = e.touches[0].clientX;
        modalTouchStartY = e.touches[0].clientY;
        modalTouchMoved = false;
      }
    }
    function onModalTouchMove(e) {
      if (!e.touches || e.touches.length !== 1) return;
      var touch = e.touches[0];
      var dx = touch.clientX - modalTouchStartX;
      var dy = touch.clientY - modalTouchStartY;
      // 水平滑动超过阈值时阻止默认行为（防止边缘滑动返回/关闭）
      if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) {
        e.preventDefault();
      }
      if (Math.abs(dx) > 5 || Math.abs(dy) > 5) {
        modalTouchMoved = true;
      }
    }
    function onModalTouchEnd() {
      modalTouchMoved = false;
    }

    if (els.modal) {
      els.modal.addEventListener('click', onModalClick);
      els.modal.addEventListener('touchstart', onModalTouchStart, { passive: true });
      els.modal.addEventListener('touchmove', onModalTouchMove, { passive: false });
      els.modal.addEventListener('touchend', onModalTouchEnd, { passive: true });
      // 禁用鼠标滚轮的水平回退手势
      els.modal.addEventListener('wheel', function(e) {
        if (Math.abs(e.deltaX) > Math.abs(e.deltaY) && Math.abs(e.deltaX) > 10) {
          e.preventDefault();
        }
      }, { passive: false });
    }

    // 全屏切换
    var isFullscreen = false;
    function toggleFullscreen() {
      if (!els.modalCard) return;
      isFullscreen = !isFullscreen;
      els.modalCard.classList.toggle('fullscreen', isFullscreen);
      if (els.fullscreenBtn) els.fullscreenBtn.textContent = isFullscreen ? '⛶' : '⛶';
      setTimeout(function() { if (editor) editor.resize(); }, 20);
    }
    if (els.fullscreenBtn) {
      els.fullscreenBtn.addEventListener('click', toggleFullscreen);
    }

    // Esc 关闭（全屏时先退出全屏）
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && els.modal && els.modal.classList.contains('open')) {
        if (isFullscreen) {
          toggleFullscreen();
          return;
        }
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

  // ── 跳转行号栏 ──
  function openGotoBar() {
    var bar = document.getElementById('nav-ace-goto-bar');
    var input = document.getElementById('nav-ace-goto-input');
    if (!bar || !input) return;
    bar.classList.add('open');
    input.value = '';
    input.focus();
  }
  function closeGotoBar() {
    var bar = document.getElementById('nav-ace-goto-bar');
    if (bar) bar.classList.remove('open');
  }
  function doGotoLine() {
    var input = document.getElementById('nav-ace-goto-input');
    if (!input || !editor) return;
    var line = parseInt(input.value, 10);
    if (line && line > 0) {
      editor.gotoLine(line, 0, true);
      editor.focus();
    }
    closeGotoBar();
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
    document.getElementById('nav-ace-btn-find').addEventListener('click', function() {
      if (editor) editor.execCommand('find');
    });
    document.getElementById('nav-ace-btn-goto').addEventListener('click', function() {
      openGotoBar();
    });
    document.getElementById('nav-ace-goto-confirm').addEventListener('click', function() {
      doGotoLine();
    });
    document.getElementById('nav-ace-goto-cancel').addEventListener('click', function() {
      closeGotoBar();
    });
    document.getElementById('nav-ace-goto-input').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { doGotoLine(); }
      if (e.key === 'Escape') { closeGotoBar(); editor && editor.focus(); }
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
    var status = document.getElementById('nav-ace-dirty-status');
    if (status) {
      status.textContent = dirty ? '· 有未保存修改' : '· 未修改';
      status.classList.toggle('dirty', dirty);
    }
  }

  // ── 按钮辅助 ──
  function findButtonByAction(action) {
    if (els.toolbarActions) {
      var all = els.toolbarActions.querySelectorAll('button[data-action]');
      for (var i = 0; i < all.length; i++) {
        if (all[i].getAttribute('data-action') === action) return all[i];
      }
    }
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
    if (els.toolbarActions) {
      els.toolbarActions.innerHTML = '';
    }
    if (!els.actionsLeft || !els.actionsRight) return;
    els.actionsLeft.innerHTML = '';
    els.actionsRight.innerHTML = '';

    var btns = config.buttons || {};
    var left = btns.left || [];
    var right = btns.right || [];

    // 过滤掉脏标记（已移到标题栏）和关闭按钮（统一使用标题栏 × 或蒙层/Esc 关闭）
    left = left.filter(function(b) { return b.type !== 'dirty' && b.action !== 'close'; });
    right = right.filter(function(b) { return b.action !== 'close'; });

    // 只读模式：隐藏保存按钮
    if (config.readOnly) {
      right = right.filter(function(b) { return b.action !== 'save'; });
    }

    // 所有按钮统一渲染到工具栏左侧
    if (els.toolbarActions) {
      left.forEach(function(btnCfg) {
        var el = createButtonEl(btnCfg);
        if (el) els.toolbarActions.appendChild(el);
      });
      right.forEach(function(btnCfg) {
        var el = createButtonEl(btnCfg);
        if (el) els.toolbarActions.appendChild(el);
      });
    } else {
      // fallback：渲染到底部操作区
      left.forEach(function(btnCfg) {
        var el = createButtonEl(btnCfg);
        if (el) els.actionsLeft.appendChild(el);
      });
      right.forEach(function(btnCfg) {
        var el = createButtonEl(btnCfg);
        if (el) els.actionsRight.appendChild(el);
      });
    }
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

    // 脏标记已移到标题栏，不再通过按钮渲染
    if (btnCfg.type === 'dirty') {
      return null;
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = btnCfg.text || '';
    btn.setAttribute('data-action', btnCfg.action || '');

    // 工具栏按钮统一样式，仅背景色可自定义
    btn.className = 'btn btn-sm nav-ace-toolbar-btn';
    if (btnCfg.bgColor) {
      btn.style.backgroundColor = btnCfg.bgColor;
      btn.style.borderColor = btnCfg.bgColor;
    } else if (btnCfg.class) {
      // 向后兼容：解析 class 中的语义颜色类
      if (btnCfg.class.indexOf('btn-primary') !== -1) {
        btn.classList.add('btn-primary');
      } else if (btnCfg.class.indexOf('btn-danger') !== -1) {
        btn.classList.add('btn-danger');
      } else if (btnCfg.class.indexOf('btn-success') !== -1) {
        btn.classList.add('btn-success');
      } else if (btnCfg.class.indexOf('btn-warning') !== -1) {
        btn.classList.add('btn-warning');
      } else {
        btn.classList.add('btn-secondary');
      }
    } else {
      btn.classList.add('btn-secondary');
    }

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

      // 底部栏
      if (els.footer) {
        if (options.footerHtml) {
          els.footer.innerHTML = options.footerHtml;
          els.footer.style.display = '';
        } else {
          els.footer.innerHTML = '';
          els.footer.style.display = 'none';
        }
      }

      // 关闭可能残留的行号跳转栏
      closeGotoBar();

      // 显示弹窗
      els.modal.classList.add('open');
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
      closeGotoBar();
      if (isFullscreen) toggleFullscreen();
      if (els.footer) {
        els.footer.innerHTML = '';
        els.footer.style.display = 'none';
      }
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
