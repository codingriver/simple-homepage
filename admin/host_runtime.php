<?php
declare(strict_types=1);

$page_permission = 'ssh.view';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';

$agent = host_agent_status_summary();
$canManage = auth_user_has_permission('ssh.manage', $current_admin) || auth_user_has_permission('ssh.service.manage', $current_admin);
$csrfValue = csrf_token();
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">宿主机运维</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    这里集中查看宿主机系统概览、进程、服务、网络、用户和用户组。当前页面所有读写能力都统一走 <code>host-agent</code>。
  </div>
  <?php if (auth_user_has_permission('ssh.audit', $current_admin)): ?>
  <div style="margin-top:12px"><a href="share_service_audit.php" class="btn btn-secondary">共享服务审计与历史</a></div>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">Host-Agent 状态</div>
  <div class="alert <?= !empty($agent['healthy']) ? 'alert-success' : 'alert-warn' ?>">
    <?= htmlspecialchars((string)($agent['message'] ?? '')) ?>
  </div>
  <div class="form-hint" style="margin-top:8px">当前模式：<?= htmlspecialchars((string)($agent['install_mode'] ?? '-')) ?>。`simulate` 模式下不会真的修改宿主机。</div>
</div>

<?php if (empty($agent['healthy'])): ?>
  <div class="card"><div class="form-hint">请先到 <a href="settings.php#host-agent">系统设置 / Host-Agent</a> 完成安装和健康检查。</div></div>
<?php else: ?>
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <div class="card-title" style="margin:0">系统概览</div>
    <button type="button" class="btn btn-secondary" onclick="loadSystemOverview()">刷新概览</button>
  </div>
  <div id="host-overview-status" style="display:none;margin-top:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
  <div id="host-overview-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px"></div>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <div class="card-title" style="margin:0">进程管理</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" id="process-keyword" placeholder="搜索 PID / 用户 / 命令" style="min-width:220px">
      <select id="process-sort"><option value="cpu">CPU 优先</option><option value="mem">内存优先</option></select>
      <button type="button" class="btn btn-secondary" onclick="loadProcesses()">刷新进程</button>
    </div>
  </div>
  <div id="host-process-status" style="display:none;margin-top:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
  <div class="table-wrap" style="margin-top:12px"><table>
    <thead><tr><th>PID</th><th>用户</th><th>CPU%</th><th>MEM%</th><th>运行秒数</th><th>状态</th><th>命令</th><th>操作</th></tr></thead>
    <tbody id="process-tbody"><tr><td colspan="8" style="color:var(--tm)">加载中…</td></tr></tbody>
  </table></div>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <div class="card-title" style="margin:0">服务管理</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" id="service-keyword" placeholder="搜索服务名 / 描述" style="min-width:220px">
      <button type="button" class="btn btn-secondary" onclick="loadServices()">刷新服务</button>
    </div>
  </div>
  <div id="host-service-status" style="display:none;margin-top:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
  <div class="table-wrap" style="margin-top:12px"><table>
    <thead><tr><th>服务</th><th>Active</th><th>Sub</th><th>Enabled</th><th>描述</th><th>操作</th></tr></thead>
    <tbody id="service-tbody"><tr><td colspan="6" style="color:var(--tm)">加载中…</td></tr></tbody>
  </table></div>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <div class="card-title" style="margin:0">网络分析</div>
    <button type="button" class="btn btn-secondary" onclick="loadNetworkOverview()">刷新网络</button>
  </div>
  <div id="host-network-status" style="display:none;margin-top:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
    <div>
      <div class="form-hint" style="margin-bottom:8px">监听端口</div>
      <pre id="network-listeners" style="min-height:180px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;overflow:auto;font-family:var(--mono);font-size:12px"></pre>
    </div>
    <div>
      <div class="form-hint" style="margin-bottom:8px">已建立连接</div>
      <pre id="network-connections" style="min-height:180px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;overflow:auto;font-family:var(--mono);font-size:12px"></pre>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <div class="card-title" style="margin:0">共享服务中心</div>
    <div class="form-hint">统一管理宿主机的 SFTP、SMB 和 FTP 服务、账号策略与共享目录。</div>
  </div>
  <div id="host-share-status" style="display:none;margin-top:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:12px">
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--bg)">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <strong>SFTP 策略</strong>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openShareServiceLogs('sftp')">日志</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="loadSftpStatus()">刷新</button>
        </div>
      </div>
      <div id="sftp-summary" class="form-hint" style="margin:10px 0 12px">加载中…</div>
      <?php if ($canManage): ?>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:10px">
        <input type="text" id="sftp-username" placeholder="用户名" list="host-user-datalist" oninput="syncSftpChrootByUsername()">
        <input type="text" id="sftp-chroot" placeholder="/srv/sftp/user">
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px">
        <label><input type="checkbox" id="sftp-enabled" checked> 启用</label>
        <label><input type="checkbox" id="sftp-force" checked> internal-sftp</label>
        <label><input type="checkbox" id="sftp-password" checked> 允许密码</label>
        <label><input type="checkbox" id="sftp-pubkey" checked> 允许公钥</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="saveSftpPolicy()">保存 SFTP 策略</button>
      <?php endif; ?>
      <div class="table-wrap" style="margin-top:12px"><table>
        <thead><tr><th>用户</th><th>Chroot</th><th>认证</th><th>操作</th></tr></thead>
        <tbody id="sftp-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--bg)">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <strong>SMB 共享</strong>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openShareServiceLogs('smb')">日志</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="loadSmbStatus()">刷新</button>
        </div>
      </div>
      <div id="smb-summary" class="form-hint" style="margin:10px 0 12px">加载中…</div>
      <?php if ($canManage): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="runSmbAction('start')">start</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runSmbAction('stop')">stop</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runSmbAction('restart')">restart</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runSmbAction('enable')">enable</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runSmbAction('disable')">disable</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="installSmbService()">安装 SMB</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="uninstallSmbService()">卸载 SMB</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:8px">
        <input type="text" id="smb-name" placeholder="共享名">
        <input type="text" id="smb-path" placeholder="/srv/share">
        <input type="text" id="smb-comment" placeholder="备注">
        <input type="text" id="smb-valid-users" placeholder="允许用户，逗号分隔" list="host-user-datalist">
        <input type="text" id="smb-write-users" placeholder="写入用户，逗号分隔" list="host-user-datalist" style="grid-column:1 / span 2">
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px">
        <label><input type="checkbox" id="smb-browseable" checked> 可浏览</label>
        <label><input type="checkbox" id="smb-read-only"> 只读</label>
        <label><input type="checkbox" id="smb-guest-ok"> 允许访客</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="saveSmbShare()">保存 SMB 共享</button>
      <?php endif; ?>
      <div class="table-wrap" style="margin-top:12px"><table>
        <thead><tr><th>共享名</th><th>目录</th><th>权限</th><th>操作</th></tr></thead>
        <tbody id="smb-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--bg)">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <strong>FTP 服务</strong>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openShareServiceLogs('ftp')">日志</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="loadFtpStatus()">刷新</button>
        </div>
      </div>
      <div id="ftp-summary" class="form-hint" style="margin:10px 0 12px">加载中…</div>
      <?php if ($canManage): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="runFtpAction('start')">start</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runFtpAction('stop')">stop</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runFtpAction('restart')">restart</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runFtpAction('enable')">enable</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runFtpAction('disable')">disable</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="installFtpService()">安装 FTP</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="uninstallFtpService()">卸载 FTP</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:8px">
        <input type="number" id="ftp-listen-port" placeholder="21" value="21">
        <input type="text" id="ftp-local-root" placeholder="/srv/ftp">
        <input type="number" id="ftp-pasv-min" placeholder="40000" value="40000">
        <input type="number" id="ftp-pasv-max" placeholder="40100" value="40100">
        <input type="text" id="ftp-allowed-users" placeholder="允许用户，逗号分隔" list="host-user-datalist" style="grid-column:1 / span 2">
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px">
        <label><input type="checkbox" id="ftp-anonymous-enable"> 匿名登录</label>
        <label><input type="checkbox" id="ftp-local-enable" checked> 本地用户</label>
        <label><input type="checkbox" id="ftp-write-enable" checked> 允许写入</label>
        <label><input type="checkbox" id="ftp-chroot-enable" checked> Chroot 用户</label>
        <label><input type="checkbox" id="ftp-pasv-enable" checked> 被动模式</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="saveFtpSettings()">保存 FTP 配置</button>
      <?php endif; ?>
      <div class="table-wrap" style="margin-top:12px"><table>
        <thead><tr><th>配置</th><th>值</th></tr></thead>
        <tbody id="ftp-tbody"><tr><td colspan="2" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--bg)">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <strong>NFS 服务</strong>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openShareServiceLogs('nfs')">日志</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="loadNfsStatus()">刷新</button>
        </div>
      </div>
      <div id="nfs-summary" class="form-hint" style="margin:10px 0 12px">加载中…</div>
      <?php if ($canManage): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="runNfsAction('start')">start</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runNfsAction('stop')">stop</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runNfsAction('restart')">restart</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runNfsAction('enable')">enable</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runNfsAction('disable')">disable</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="installNfsService()">安装 NFS</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="uninstallNfsService()">卸载 NFS</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:8px">
        <input type="text" id="nfs-path" placeholder="/srv/nfs">
        <input type="text" id="nfs-clients" placeholder="192.168.1.0/24">
        <input type="text" id="nfs-options" placeholder="rw,sync,no_subtree_check">
        <input type="number" id="nfs-mountd-port" placeholder="mountd 端口">
        <input type="number" id="nfs-statd-port" placeholder="statd 端口">
        <input type="number" id="nfs-lockd-port" placeholder="lockd 端口">
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px">
        <label><input type="checkbox" id="nfs-async-mode"> 使用 async 导出</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="saveNfsExport()">保存 NFS 导出</button>
      <?php endif; ?>
      <div class="table-wrap" style="margin-top:12px"><table>
        <thead><tr><th>目录</th><th>客户端</th><th>选项</th><th>操作</th></tr></thead>
        <tbody id="nfs-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--bg)">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <strong>AFP 服务</strong>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openShareServiceLogs('afp')">日志</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="loadAfpStatus()">刷新</button>
        </div>
      </div>
      <div id="afp-summary" class="form-hint" style="margin:10px 0 12px">加载中…</div>
      <?php if ($canManage): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAfpAction('start')">start</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAfpAction('stop')">stop</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAfpAction('restart')">restart</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAfpAction('enable')">enable</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAfpAction('disable')">disable</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="installAfpService()">安装 AFP</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="uninstallAfpService()">卸载 AFP</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:8px">
        <input type="text" id="afp-name" placeholder="共享名">
        <input type="text" id="afp-path" placeholder="/srv/afp">
        <input type="number" id="afp-port" placeholder="548">
        <input type="text" id="afp-valid-users" placeholder="允许用户，逗号分隔" list="host-user-datalist">
        <input type="text" id="afp-rwlist" placeholder="读写用户，逗号分隔" list="host-user-datalist" style="grid-column:1 / span 2">
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="saveAfpShare()">保存 AFP 共享</button>
      <?php endif; ?>
      <div class="table-wrap" style="margin-top:12px"><table>
        <thead><tr><th>共享</th><th>目录</th><th>权限</th><th>操作</th></tr></thead>
        <tbody id="afp-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--bg)">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <strong>Async / Rsync</strong>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openShareServiceLogs('async')">日志</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="loadAsyncStatus()">刷新</button>
        </div>
      </div>
      <div id="async-summary" class="form-hint" style="margin:10px 0 12px">加载中…</div>
      <?php if ($canManage): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAsyncAction('start')">start</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAsyncAction('stop')">stop</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAsyncAction('restart')">restart</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAsyncAction('enable')">enable</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="runAsyncAction('disable')">disable</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="installAsyncService()">安装 Async</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="uninstallAsyncService()">卸载 Async</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:8px">
        <input type="text" id="async-name" placeholder="模块名">
        <input type="text" id="async-path" placeholder="/srv/rsync">
        <input type="number" id="async-port" placeholder="873" value="873">
        <input type="text" id="async-auth-users" placeholder="认证用户，逗号分隔" list="host-user-datalist">
        <input type="text" id="async-comment" placeholder="备注" style="grid-column:1 / span 2">
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px">
        <label><input type="checkbox" id="async-read-only"> 只读</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="saveAsyncModule()">保存 Async 模块</button>
      <?php endif; ?>
      <div class="table-wrap" style="margin-top:12px"><table>
        <thead><tr><th>模块</th><th>目录</th><th>权限</th><th>操作</th></tr></thead>
        <tbody id="async-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--bg);grid-column:1 / -1">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <strong>共享目录权限联动</strong>
        <div class="form-hint">统一读取和应用共享目录 owner / group / chmod，支持递归。</div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0 8px">
        <input type="text" id="share-acl-path" placeholder="/srv/share" style="grid-column:1 / span 2">
        <input type="text" id="share-acl-owner" placeholder="属主用户名" list="host-user-datalist">
        <input type="text" id="share-acl-group" placeholder="属组名" list="host-group-datalist">
        <input type="text" id="share-acl-mode" placeholder="0755">
        <select id="share-acl-preset" onchange="applyShareAclPreset(this.value)">
          <option value="">权限预设</option>
          <option value="owner_only">仅属主 0700</option>
          <option value="owner_group">属主+属组 0770</option>
          <option value="public_read">公共只读 0755</option>
          <option value="public_rw">公共读写 0777</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px"><input type="checkbox" id="share-acl-recursive"> 递归应用到子目录</label>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="setShareAclPathFromInput('smb-path')">使用 SMB 路径</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setShareAclPathFromInput('ftp-local-root')">使用 FTP 根目录</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setShareAclPathFromInput('nfs-path')">使用 NFS 路径</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setShareAclPathFromInput('afp-path')">使用 AFP 路径</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setShareAclPathFromInput('async-path')">使用 Async 路径</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setShareAclPathFromInput('sftp-chroot')">使用 SFTP Chroot</button>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="loadSharePathStat()">读取当前权限</button>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="applySharePathAcl()">应用目录权限</button>
        <?php endif; ?>
      </div>
      <div id="share-acl-stat" class="form-hint">尚未读取目录权限。</div>
      <datalist id="host-user-datalist"></datalist>
      <datalist id="host-group-datalist"></datalist>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div>
      <div class="card-title">用户管理</div>
      <?php if ($canManage): ?>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:12px">
        <input type="text" id="user-username" placeholder="用户名">
        <input type="text" id="user-shell" placeholder="/bin/sh" value="/bin/sh">
        <input type="text" id="user-home" placeholder="/home/username">
        <input type="text" id="user-groups" placeholder="附加组，逗号分隔">
        <input type="text" id="user-gecos" placeholder="备注 / GECOS">
        <input type="password" id="user-password" placeholder="密码，留空不修改">
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button type="button" class="btn btn-primary" onclick="saveHostUser()">保存用户</button>
        <input type="text" id="user-keyword" placeholder="搜索用户" style="min-width:180px">
        <button type="button" class="btn btn-secondary" onclick="loadUsers()">刷新用户</button>
      </div>
      <?php else: ?>
      <div class="form-hint" style="margin-bottom:12px">当前角色没有用户管理写权限，仅可查看。</div>
      <?php endif; ?>
      <div id="host-user-status" style="display:none;margin-bottom:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
      <div class="table-wrap"><table>
        <thead><tr><th>用户名</th><th>UID/GID</th><th>Home</th><th>Shell</th><th>附加信息</th><th>操作</th></tr></thead>
        <tbody id="user-tbody"><tr><td colspan="6" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
    <div>
      <div class="card-title">用户组管理</div>
      <?php if ($canManage): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
        <input type="text" id="group-name" placeholder="用户组名">
        <input type="text" id="group-members" placeholder="成员，逗号分隔">
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button type="button" class="btn btn-primary" onclick="saveHostGroup()">保存用户组</button>
        <input type="text" id="group-keyword" placeholder="搜索用户组" style="min-width:180px">
        <button type="button" class="btn btn-secondary" onclick="loadGroups()">刷新用户组</button>
      </div>
      <?php else: ?>
      <div class="form-hint" style="margin-bottom:12px">当前角色没有用户组管理写权限，仅可查看。</div>
      <?php endif; ?>
      <div id="host-group-status" style="display:none;margin-bottom:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
      <div class="table-wrap"><table>
        <thead><tr><th>用户组</th><th>GID</th><th>成员</th><th>操作</th></tr></thead>
        <tbody id="group-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
      </table></div>
    </div>
  </div>
</div>

<div id="service-log-modal" style="display:none;position:fixed;inset:0;z-index:900;background:rgba(0,0,0,.7);align-items:center;justify-content:center" onclick="if(event.target===this)closeServiceLogs()">
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(960px,96vw);max-height:90vh;display:flex;flex-direction:column">
    <div style="padding:14px 18px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center">
      <strong id="service-log-title">服务日志</strong>
      <button type="button" class="btn btn-secondary" onclick="closeServiceLogs()">关闭</button>
    </div>
    <div id="service-log-status" style="display:none;padding:12px 16px;border-bottom:1px solid var(--bd);background:rgba(255,255,255,.02)"></div>
    <pre id="service-log-body" style="margin:0;padding:16px;overflow:auto;min-height:300px;background:var(--bg);font-family:var(--mono);font-size:12px;line-height:1.55"></pre>
  </div>
</div>

<script>
var HOST_RUNTIME_CSRF = <?= json_encode($csrfValue) ?>;
var HOST_RUNTIME_CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
var HOST_RUNTIME_USERS = [];
var HOST_RUNTIME_GROUPS = [];
var HOST_RUNTIME_STATUS = navCreateAsyncStatus({
  progressTexts: {
    connecting: '正在连接 host-agent…',
    loading: '正在获取数据…',
    processing: '数据较多，继续处理中…'
  },
  getRefs: function(scope) {
    return {
      wrap: document.getElementById(scope + '-status')
    };
  }
});

function hostRuntimeStatusRefs(scope) {
  return {
    wrap: document.getElementById(scope + '-status')
  };
}

function hostRuntimeSetStatus(scope, title, detail, percent, tone) {
  HOST_RUNTIME_STATUS.set(scope, title, detail, percent, tone);
}

function hostRuntimeHideStatus(scope) {
  HOST_RUNTIME_STATUS.hide(scope);
}

function hostRuntimeStartTask(scope, title, detail) {
  return HOST_RUNTIME_STATUS.start(scope, title, detail);
}

function hostRuntimeFinishTask(id, ok, detail) {
  HOST_RUNTIME_STATUS.finish(id, ok, detail);
}

async function hostRuntimeRun(scope, title, detail, runner) {
  return HOST_RUNTIME_STATUS.run(scope, title, detail, runner, { successText: '数据已刷新完成' });
}

function hostRuntimeApi(action, params, method) {
  method = method || 'GET';
  if (method === 'POST') {
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('_csrf', HOST_RUNTIME_CSRF);
    Object.keys(params || {}).forEach(function(key) {
      if (params[key] === undefined || params[key] === null) return;
      body.append(key, String(params[key]));
    });
    return fetch('host_api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function(r) { return r.json(); });
  }
  var query = new URLSearchParams({ action: action });
  Object.keys(params || {}).forEach(function(key) {
    if (params[key] === undefined || params[key] === null) return;
    query.set(key, String(params[key]));
  });
  return fetch('host_api.php?' + query.toString(), {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  }).then(function(r) { return r.json(); });
}

function fmtMb(kb) {
  var num = Number(kb || 0);
  return (num / 1024).toFixed(1) + ' MB';
}

function renderOverviewCards(data) {
  var cards = [
    ['CPU 使用率', (data.cpu_percent || '0.00') + '%'],
    ['负载', [data.load1 || '0', data.load5 || '0', data.load15 || '0'].join(' / ')],
    ['内存', fmtMb(data.mem_available_kb || 0) + ' 可用 / ' + fmtMb(data.mem_total_kb || 0)],
    ['Swap', fmtMb(data.swap_free_kb || 0) + ' 可用 / ' + fmtMb(data.swap_total_kb || 0)],
    ['磁盘 /', fmtMb(data.disk_used_kb || 0) + ' 已用 / ' + fmtMb(data.disk_total_kb || 0)],
    ['系统', (data.os || '-') + ' / ' + (data.kernel || '-')],
    ['CPU', (data.cpu_model || '-') + ' / ' + (data.cpu_cores || '-') + ' 核'],
    ['主机名', data.hostname || '-']
  ];
  document.getElementById('host-overview-cards').innerHTML = cards.map(function(item) {
    return '<div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">'
      + '<div style="font-size:11px;color:var(--tm);margin-bottom:6px">' + item[0] + '</div>'
      + '<div style="font-weight:700;line-height:1.6">' + item[1].replace(/</g, '&lt;') + '</div></div>';
  }).join('');
}

async function loadSystemOverview() {
  var data = await hostRuntimeRun('host-overview', '获取系统概览', '正在读取宿主机系统指标…', function() {
    return hostRuntimeApi('system_overview');
  });
  if (!data.ok) {
    showToast(data.msg || '系统概览读取失败', 'error');
    return;
  }
  renderOverviewCards(data.data || data);
}

function processActionsHtml(item) {
  if (!HOST_RUNTIME_CAN_MANAGE) return '—';
  return '<button type="button" class="btn btn-sm btn-secondary" onclick="killProcess(' + Number(item.pid || 0) + ', \'TERM\')">结束</button>'
    + ' <button type="button" class="btn btn-sm btn-danger" onclick="killProcess(' + Number(item.pid || 0) + ', \'KILL\')">强杀</button>';
}

async function loadProcesses() {
  var data = await hostRuntimeRun('host-process', '获取进程列表', '进程较多时会需要更久，请稍候…', function() {
    return hostRuntimeApi('process_list', {
      keyword: document.getElementById('process-keyword').value || '',
      sort: document.getElementById('process-sort').value || 'cpu',
      limit: 80
    });
  });
  var tbody = document.getElementById('process-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="8" style="color:var(--red)">' + (data.msg || '进程列表读取失败') + '</td></tr>';
    return;
  }
  var items = data.items || [];
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + item.pid + '</td>'
      + '<td>' + (item.user || '') + '</td>'
      + '<td>' + (item.cpu || '0') + '</td>'
      + '<td>' + (item.mem || '0') + '</td>'
      + '<td>' + (item.etimes || '0') + '</td>'
      + '<td>' + (item.stat || '') + '</td>'
      + '<td style="font-family:var(--mono);max-width:420px;word-break:break-all">' + ((item.args || item.comm || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')) + '</td>'
      + '<td>' + processActionsHtml(item) + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="8" style="color:var(--tm)">暂无进程数据</td></tr>';
}

async function killProcess(pid, signal) {
  if (!confirm('确认对 PID ' + pid + ' 发送 ' + signal + ' 信号？')) return;
  var data = await hostRuntimeRun('host-process', '执行进程操作', '正在发送 ' + signal + ' 信号到 PID ' + pid + ' …', function() {
    return hostRuntimeApi('process_kill', { pid: pid, signal: signal }, 'POST');
  });
  showToast(data.msg || (data.ok ? '操作完成' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadProcesses();
}

function serviceActionsHtml(item) {
  var name = JSON.stringify(item.name || '');
  var html = '<button type="button" class="btn btn-sm btn-secondary" onclick="openServiceLogs(' + name + ')">日志</button>';
  if (!HOST_RUNTIME_CAN_MANAGE) return html;
  ['start','stop','restart','reload'].forEach(function(action) {
    html += ' <button type="button" class="btn btn-sm btn-secondary" onclick="runServiceAction(' + name + ', ' + JSON.stringify(action) + ')">' + action + '</button>';
  });
  if ((item.enabled || '') !== '') {
    html += ' <button type="button" class="btn btn-sm btn-secondary" onclick="runServiceAction(' + name + ', ' + JSON.stringify(item.enabled === 'enabled' ? 'disable' : 'enable') + ')">' + (item.enabled === 'enabled' ? 'disable' : 'enable') + '</button>';
  }
  return html;
}

async function loadServices() {
  var data = await hostRuntimeRun('host-service', '获取服务列表', '正在读取系统服务状态…', function() {
    return hostRuntimeApi('service_list', {
      keyword: document.getElementById('service-keyword').value || '',
      limit: 80
    });
  });
  var tbody = document.getElementById('service-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:var(--red)">' + (data.msg || '服务列表读取失败') + '</td></tr>';
    return;
  }
  var items = data.items || [];
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + (item.name || '') + '</td>'
      + '<td>' + (item.active || '') + '</td>'
      + '<td>' + (item.sub || '') + '</td>'
      + '<td>' + (item.enabled || '') + '</td>'
      + '<td>' + ((item.description || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')) + '</td>'
      + '<td>' + serviceActionsHtml(item) + '</td></tr>';
  }).join('') : '<tr><td colspan="6" style="color:var(--tm)">暂无服务数据</td></tr>';
}

async function runServiceAction(service, action) {
  if (!confirm('确认执行服务操作：' + service + ' -> ' + action + ' ?')) return;
  var data = await hostRuntimeRun('host-service', '执行服务操作', '正在对 ' + service + ' 执行 ' + action + ' …', function() {
    return hostRuntimeApi('service_action_generic', { service: service, service_action: action }, 'POST');
  });
  showToast(data.msg || (data.ok ? '操作完成' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadServices();
}

async function openServiceLogs(service) {
  document.getElementById('service-log-title').textContent = '服务日志 - ' + service;
  document.getElementById('service-log-body').textContent = '加载中...';
  document.getElementById('service-log-modal').style.display = 'flex';
  var data = await hostRuntimeRun('service-log', '获取服务日志', '正在读取 ' + service + ' 的最近日志…', function() {
    return hostRuntimeApi('service_logs', { service: service, limit: 120 });
  });
  document.getElementById('service-log-body').textContent = (data.lines || []).join('\n');
}

function closeServiceLogs() {
  document.getElementById('service-log-modal').style.display = 'none';
  hostRuntimeHideStatus('service-log');
}

async function loadNetworkOverview() {
  var data = await hostRuntimeRun('host-network', '获取网络分析', '正在读取监听端口和连接状态…', function() {
    return hostRuntimeApi('network_overview', { limit: 120 });
  });
  if (!data.ok) {
    showToast(data.msg || '网络信息读取失败', 'error');
    return;
  }
  document.getElementById('network-listeners').textContent = (data.listeners || []).join('\n');
  document.getElementById('network-connections').textContent = (data.connections || []).join('\n');
}

function setShareSummary(id, text) {
  var node = document.getElementById(id);
  if (node) node.textContent = text;
}

function shareServiceLabel(service) {
  return {
    sftp: 'SFTP / SSH',
    smb: 'SMB',
    ftp: 'FTP',
    nfs: 'NFS',
    afp: 'AFP',
    async: 'Async / Rsync'
  }[service] || service;
}

function shareServiceLogService(service) {
  return service === 'sftp' ? 'ssh' : service;
}

function syncSftpChrootByUsername() {
  var userInput = document.getElementById('sftp-username');
  var chrootInput = document.getElementById('sftp-chroot');
  if (!userInput || !chrootInput) return;
  var username = String(userInput.value || '').trim();
  var current = String(chrootInput.value || '').trim();
  if (username === '') return;
  if (current === '' || /^\/srv\/sftp\/[^/]+$/.test(current)) {
    chrootInput.value = '/srv/sftp/' + username;
  }
}

async function openShareServiceLogs(service) {
  var logService = shareServiceLogService(service);
  document.getElementById('service-log-title').textContent = shareServiceLabel(service) + ' 日志';
  document.getElementById('service-log-body').textContent = '加载中...';
  document.getElementById('service-log-modal').style.display = 'flex';
  var data = await hostRuntimeRun('service-log', '读取共享服务日志', '正在读取 ' + shareServiceLabel(service) + ' 的最近日志…', function() {
    return hostRuntimeApi('service_logs', { service: logService, limit: 120 });
  });
  if (!data.ok) {
    document.getElementById('service-log-body').textContent = data.msg || '日志读取失败';
    showToast(data.msg || '日志读取失败', 'error');
    return;
  }
  document.getElementById('service-log-body').textContent = (data.lines || []).join('\n') || '暂无日志';
}

function updateShareAclDatalists() {
  var userList = document.getElementById('host-user-datalist');
  var groupList = document.getElementById('host-group-datalist');
  if (userList) {
    userList.innerHTML = (HOST_RUNTIME_USERS || []).map(function(item) {
      return '<option value="' + String(item.username || '').replace(/"/g, '&quot;') + '"></option>';
    }).join('');
  }
  if (groupList) {
    groupList.innerHTML = (HOST_RUNTIME_GROUPS || []).map(function(item) {
      return '<option value="' + String(item.groupname || '').replace(/"/g, '&quot;') + '"></option>';
    }).join('');
  }
}

function setShareAclStatText(text, tone) {
  var node = document.getElementById('share-acl-stat');
  if (!node) return;
  node.textContent = text;
  node.style.color = tone === 'error' ? 'var(--red)' : 'var(--tm)';
}

function renderShareAclStat(data) {
  if (!data || !data.ok) {
    setShareAclStatText((data && data.msg) || '目录权限读取失败', 'error');
    return;
  }
  var parts = [
    '路径: ' + (data.path || '-'),
    'owner: ' + (data.owner || '-'),
    'group: ' + (data.group || '-'),
    'mode: ' + (data.mode || '-')
  ];
  setShareAclStatText(parts.join(' / '));
  if (document.getElementById('share-acl-path')) document.getElementById('share-acl-path').value = data.path || '';
  if (document.getElementById('share-acl-owner')) document.getElementById('share-acl-owner').value = data.owner || '';
  if (document.getElementById('share-acl-group')) document.getElementById('share-acl-group').value = data.group || '';
  if (document.getElementById('share-acl-mode')) document.getElementById('share-acl-mode').value = data.mode || '';
}

function setShareAclPathFromInput(inputId) {
  var source = document.getElementById(inputId);
  var target = document.getElementById('share-acl-path');
  if (!source || !target) return;
  target.value = source.value || '';
  if (target.value) {
    loadSharePathStat();
  }
}

function focusShareAclPath(path) {
  var target = document.getElementById('share-acl-path');
  if (!target) return;
  target.value = String(path || '').trim();
  if (!target.value) {
    showToast('共享目录路径为空', 'error');
    return;
  }
  target.scrollIntoView({ behavior: 'smooth', block: 'center' });
  loadSharePathStat();
}

function scrollFieldIntoView(id) {
  var node = document.getElementById(id);
  if (!node) return;
  node.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function fillShareField(id, value) {
  var node = document.getElementById(id);
  if (!node) return;
  if (node.type === 'checkbox') {
    node.checked = !!value;
  } else {
    node.value = value == null ? '' : String(value);
  }
}

function editSftpPolicy(item) {
  fillShareField('sftp-username', item.username || '');
  fillShareField('sftp-chroot', item.chroot_directory || '');
  fillShareField('sftp-enabled', !!item.enabled);
  fillShareField('sftp-force', !!item.force_internal_sftp);
  fillShareField('sftp-password', !!item.allow_password);
  fillShareField('sftp-pubkey', !!item.allow_pubkey);
  scrollFieldIntoView('sftp-username');
}

function editSmbShare(item) {
  fillShareField('smb-name', item.name || '');
  fillShareField('smb-path', item.path || '');
  fillShareField('smb-comment', item.comment || '');
  fillShareField('smb-valid-users', (item.valid_users || []).join(','));
  fillShareField('smb-write-users', (item.write_users || []).join(','));
  fillShareField('smb-browseable', !!item.browseable);
  fillShareField('smb-read-only', !!item.read_only);
  fillShareField('smb-guest-ok', !!item.guest_ok);
  scrollFieldIntoView('smb-name');
}

function editNfsExport(item) {
  fillShareField('nfs-path', item.path || '');
  fillShareField('nfs-clients', item.clients || '');
  fillShareField('nfs-options', (item.options || []).join(','));
  fillShareField('nfs-async-mode', (item.options || []).indexOf('async') !== -1);
  scrollFieldIntoView('nfs-path');
}

function editAfpShare(item, port) {
  fillShareField('afp-name', item.name || '');
  fillShareField('afp-path', item.path || '');
  fillShareField('afp-port', port || '');
  fillShareField('afp-valid-users', (item.valid_users || []).join(','));
  fillShareField('afp-rwlist', (item.rwlist || []).join(','));
  scrollFieldIntoView('afp-name');
}

function editAsyncModule(item, port) {
  fillShareField('async-name', item.name || '');
  fillShareField('async-path', item.path || '');
  fillShareField('async-port', port || '873');
  fillShareField('async-auth-users', (item.auth_users || []).join(','));
  fillShareField('async-comment', item.comment || '');
  fillShareField('async-read-only', !!item.read_only);
  scrollFieldIntoView('async-name');
}

function applyShareAclPreset(preset) {
  var modeInput = document.getElementById('share-acl-mode');
  if (!modeInput) return;
  if (preset === 'owner_only') modeInput.value = '0700';
  else if (preset === 'owner_group') modeInput.value = '0770';
  else if (preset === 'public_read') modeInput.value = '0755';
  else if (preset === 'public_rw') modeInput.value = '0777';
}

async function loadSharePathStat() {
  var pathInput = document.getElementById('share-acl-path');
  var path = (pathInput && pathInput.value || '').trim();
  if (!path) {
    showToast('请先填写共享目录路径', 'error');
    return;
  }
  var data = await hostRuntimeRun('host-share', '读取共享目录权限', '正在读取共享目录 owner / group / chmod…', function() {
    return hostRuntimeApi('share_path_stat', { path: path });
  });
  renderShareAclStat(data);
  if (!data.ok) showToast(data.msg || '目录权限读取失败', 'error');
}

async function applySharePathAcl() {
  var path = (document.getElementById('share-acl-path').value || '').trim();
  if (!path) {
    showToast('请先填写共享目录路径', 'error');
    return;
  }
  var data = await hostRuntimeRun('host-share', '应用共享目录权限', '正在更新共享目录权限…', function() {
    return hostRuntimeApi('share_path_apply_acl', {
      path: path,
      owner: document.getElementById('share-acl-owner').value || '',
      group: document.getElementById('share-acl-group').value || '',
      mode: document.getElementById('share-acl-mode').value || '',
      recursive: document.getElementById('share-acl-recursive').checked ? '1' : '0'
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? '共享目录权限已更新' : '共享目录权限更新失败'), data.ok ? 'success' : 'error');
  if (data.stat) {
    renderShareAclStat(data.stat);
  } else if (data.ok) {
    await loadSharePathStat();
  }
}

function fmtServiceState(data) {
  if (!data) return '未知状态';
  var parts = [
    data.installed ? '已安装' : '未安装',
    data.running ? '运行中' : '未运行',
  ];
  if (data.enabled === true) parts.push('已启用自启');
  if (data.enabled === false) parts.push('未启用自启');
  if (data.service_name) parts.push('服务: ' + data.service_name);
  return parts.join(' / ');
}

async function loadSftpStatus() {
  var data = await hostRuntimeRun('host-share', '获取 SFTP 状态', '正在读取 SFTP 策略和 SSH 关联状态…', function() {
    return hostRuntimeApi('sftp_status');
  });
  var tbody = document.getElementById('sftp-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + (data.msg || 'SFTP 状态读取失败') + '</td></tr>';
    return;
  }
  setShareSummary('sftp-summary', fmtServiceState(data) + ' / 策略数: ' + (data.policy_count || 0) + ' / 配置: ' + (data.config_path || '-'));
  var items = data.policies || [];
  tbody.innerHTML = items.length ? items.map(function(item) {
    var auth = [];
    if (item.allow_password) auth.push('密码');
    if (item.allow_pubkey) auth.push('公钥');
    var actions = [];
    if (item.chroot_directory) {
      actions.push('<button type="button" class="btn btn-sm btn-secondary" onclick="focusShareAclPath(' + JSON.stringify(item.chroot_directory || '') + ')">权限</button>');
    }
    if (HOST_RUNTIME_CAN_MANAGE) {
      actions.push('<button type="button" class="btn btn-sm btn-secondary" onclick="editSftpPolicy(' + JSON.stringify(item).replace(/"/g, '&quot;').replace(/</g, '\\u003c') + ')">编辑</button>');
      actions.push('<button type="button" class="btn btn-sm btn-danger" onclick="deleteSftpPolicy(' + JSON.stringify(item.username || '') + ')">删除</button>');
    }
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + (item.username || '') + '</td>'
      + '<td style="font-family:var(--mono)">' + ((item.chroot_directory || '—').replace(/&/g,'&amp;').replace(/</g,'&lt;')) + '</td>'
      + '<td>' + (auth.join(' / ') || '禁止登录') + '</td>'
      + '<td>' + (actions.join(' ') || '—') + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无 SFTP 策略</td></tr>';
}

async function saveSftpPolicy() {
  var data = await hostRuntimeRun('host-share', '保存 SFTP 策略', '正在写入 SSH Match User 策略…', function() {
    return hostRuntimeApi('sftp_policy_save', {
      username: document.getElementById('sftp-username').value || '',
      chroot_directory: document.getElementById('sftp-chroot').value || '',
      enabled: document.getElementById('sftp-enabled').checked ? '1' : '0',
      sftp_only: '1',
      force_internal_sftp: document.getElementById('sftp-force').checked ? '1' : '0',
      allow_password: document.getElementById('sftp-password').checked ? '1' : '0',
      allow_pubkey: document.getElementById('sftp-pubkey').checked ? '1' : '0'
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'SFTP 策略已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadSftpStatus();
}

async function deleteSftpPolicy(username) {
  if (!confirm('确认删除用户 ' + username + ' 的 SFTP 策略？')) return;
  var data = await hostRuntimeRun('host-share', '删除 SFTP 策略', '正在更新 SSH 配置…', function() {
    return hostRuntimeApi('sftp_policy_delete', { username: username }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'SFTP 策略已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadSftpStatus();
}

async function loadSmbStatus() {
  var data = await hostRuntimeRun('host-share', '获取 SMB 状态', '正在读取 Samba 服务状态和共享配置…', function() {
    return hostRuntimeApi('smb_status');
  });
  var tbody = document.getElementById('smb-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + (data.msg || 'SMB 状态读取失败') + '</td></tr>';
    return;
  }
  setShareSummary('smb-summary', fmtServiceState(data) + ' / 共享数: ' + (data.share_count || 0) + ' / 配置: ' + (data.config_path || '-'));
  var items = data.shares || [];
  tbody.innerHTML = items.length ? items.map(function(item) {
    var perms = [];
    perms.push(item.read_only ? '只读' : '读写');
    if (item.guest_ok) perms.push('访客');
    if (item.valid_users && item.valid_users.length) perms.push('用户:' + item.valid_users.join(','));
    var actions = [
      '<button type="button" class="btn btn-sm btn-secondary" onclick="focusShareAclPath(' + JSON.stringify(item.path || '') + ')">权限</button>'
    ];
    if (HOST_RUNTIME_CAN_MANAGE) {
      actions.push('<button type="button" class="btn btn-sm btn-secondary" onclick="editSmbShare(' + JSON.stringify(item).replace(/"/g, '&quot;').replace(/</g, '\\u003c') + ')">编辑</button>');
      actions.push('<button type="button" class="btn btn-sm btn-danger" onclick="deleteSmbShare(' + JSON.stringify(item.name || '') + ')">删除</button>');
    }
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + (item.name || '') + '</td>'
      + '<td style="font-family:var(--mono)">' + ((item.path || '').replace(/&/g,'&amp;').replace(/</g,'&lt;')) + '</td>'
      + '<td>' + perms.join(' / ') + '</td>'
      + '<td>' + actions.join(' ') + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无 SMB 共享</td></tr>';
}

async function saveSmbShare() {
  var data = await hostRuntimeRun('host-share', '保存 SMB 共享', '正在写入 Samba 共享配置…', function() {
    return hostRuntimeApi('smb_share_save', {
      name: document.getElementById('smb-name').value || '',
      path: document.getElementById('smb-path').value || '',
      comment: document.getElementById('smb-comment').value || '',
      valid_users: document.getElementById('smb-valid-users').value || '',
      write_users: document.getElementById('smb-write-users').value || '',
      browseable: document.getElementById('smb-browseable').checked ? '1' : '0',
      read_only: document.getElementById('smb-read-only').checked ? '1' : '0',
      guest_ok: document.getElementById('smb-guest-ok').checked ? '1' : '0'
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'SMB 共享已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadSmbStatus();
}

async function deleteSmbShare(name) {
  if (!confirm('确认删除 SMB 共享 ' + name + ' ?')) return;
  var data = await hostRuntimeRun('host-share', '删除 SMB 共享', '正在更新 Samba 配置…', function() {
    return hostRuntimeApi('smb_share_delete', { name: name }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'SMB 共享已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadSmbStatus();
}

async function installSmbService() {
  var data = await hostRuntimeRun('host-share', '安装 SMB', '正在安装 Samba 服务…', function() {
    return hostRuntimeApi('smb_install', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'SMB 已安装' : '安装失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadSmbStatus();
}

async function uninstallSmbService() {
  if (!confirm('确认卸载 SMB 服务？')) return;
  var data = await hostRuntimeRun('host-share', '卸载 SMB', '正在卸载 Samba 服务…', function() {
    return hostRuntimeApi('smb_uninstall', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'SMB 已卸载' : '卸载失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadSmbStatus();
}

async function runSmbAction(action) {
  var data = await hostRuntimeRun('host-share', '执行 SMB 服务操作', '正在对 Samba 执行 ' + action + ' …', function() {
    return hostRuntimeApi('smb_action', { service_action: action }, 'POST');
  });
  showToast(data.msg || (data.ok ? '操作完成' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadSmbStatus();
}

async function loadFtpStatus() {
  var data = await hostRuntimeRun('host-share', '获取 FTP 状态', '正在读取 FTP 服务配置和用户白名单…', function() {
    return hostRuntimeApi('ftp_status');
  });
  var tbody = document.getElementById('ftp-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="2" style="color:var(--red)">' + (data.msg || 'FTP 状态读取失败') + '</td></tr>';
    return;
  }
  setShareSummary('ftp-summary', fmtServiceState(data) + ' / 配置: ' + (data.config_path || '-') + ' / 用户列表: ' + (data.userlist_path || '-'));
  var settings = data.settings || {};
  if (document.getElementById('ftp-listen-port')) document.getElementById('ftp-listen-port').value = settings.listen_port || '21';
  if (document.getElementById('ftp-local-root')) document.getElementById('ftp-local-root').value = settings.local_root || '';
  if (document.getElementById('ftp-pasv-min')) document.getElementById('ftp-pasv-min').value = settings.pasv_min_port || '40000';
  if (document.getElementById('ftp-pasv-max')) document.getElementById('ftp-pasv-max').value = settings.pasv_max_port || '40100';
  if (document.getElementById('ftp-allowed-users')) document.getElementById('ftp-allowed-users').value = (data.allowed_users || []).join(',');
  if (document.getElementById('ftp-anonymous-enable')) document.getElementById('ftp-anonymous-enable').checked = (settings.anonymous_enable || 'NO') === 'YES';
  if (document.getElementById('ftp-local-enable')) document.getElementById('ftp-local-enable').checked = (settings.local_enable || 'YES') === 'YES';
  if (document.getElementById('ftp-write-enable')) document.getElementById('ftp-write-enable').checked = (settings.write_enable || 'YES') === 'YES';
  if (document.getElementById('ftp-chroot-enable')) document.getElementById('ftp-chroot-enable').checked = (settings.chroot_local_user || 'YES') === 'YES';
  if (document.getElementById('ftp-pasv-enable')) document.getElementById('ftp-pasv-enable').checked = (settings.pasv_enable || 'YES') === 'YES';
  var rows = [
    ['监听端口', settings.listen_port || '21'],
    ['本地根目录', settings.local_root || '—'],
    ['被动端口', (settings.pasv_min_port || '-') + ' ~ ' + (settings.pasv_max_port || '-')],
    ['认证模式', ((settings.anonymous_enable || 'NO') === 'YES' ? '匿名' : '禁用匿名') + ' / ' + ((settings.local_enable || 'YES') === 'YES' ? '本地用户' : '禁用本地用户')],
    ['允许用户', (data.allowed_users || []).join(',') || '—']
  ];
  tbody.innerHTML = rows.map(function(row) {
    return '<tr><td>' + row[0] + '</td><td style="font-family:var(--mono)">' + String(row[1]).replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td></tr>';
  }).join('');
}

async function saveFtpSettings() {
  var data = await hostRuntimeRun('host-share', '保存 FTP 配置', '正在写入 FTP 服务配置…', function() {
    return hostRuntimeApi('ftp_settings_save', {
      listen_port: document.getElementById('ftp-listen-port').value || '21',
      local_root: document.getElementById('ftp-local-root').value || '',
      pasv_min_port: document.getElementById('ftp-pasv-min').value || '40000',
      pasv_max_port: document.getElementById('ftp-pasv-max').value || '40100',
      allowed_users: document.getElementById('ftp-allowed-users').value || '',
      anonymous_enable: document.getElementById('ftp-anonymous-enable').checked ? '1' : '0',
      local_enable: document.getElementById('ftp-local-enable').checked ? '1' : '0',
      write_enable: document.getElementById('ftp-write-enable').checked ? '1' : '0',
      chroot_local_user: document.getElementById('ftp-chroot-enable').checked ? '1' : '0',
      pasv_enable: document.getElementById('ftp-pasv-enable').checked ? '1' : '0'
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'FTP 配置已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadFtpStatus();
}

async function installFtpService() {
  var data = await hostRuntimeRun('host-share', '安装 FTP', '正在安装 vsftpd 服务…', function() {
    return hostRuntimeApi('ftp_install', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'FTP 已安装' : '安装失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadFtpStatus();
}

async function uninstallFtpService() {
  if (!confirm('确认卸载 FTP 服务？')) return;
  var data = await hostRuntimeRun('host-share', '卸载 FTP', '正在卸载 vsftpd 服务…', function() {
    return hostRuntimeApi('ftp_uninstall', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'FTP 已卸载' : '卸载失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadFtpStatus();
}

async function runFtpAction(action) {
  var data = await hostRuntimeRun('host-share', '执行 FTP 服务操作', '正在对 FTP 执行 ' + action + ' …', function() {
    return hostRuntimeApi('ftp_action', { service_action: action }, 'POST');
  });
  showToast(data.msg || (data.ok ? '操作完成' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadFtpStatus();
}

async function loadNfsStatus() {
  var data = await hostRuntimeRun('host-share', '获取 NFS 状态', '正在读取 NFS 导出和端口配置…', function() {
    return hostRuntimeApi('nfs_status');
  });
  var tbody = document.getElementById('nfs-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + (data.msg || 'NFS 状态读取失败') + '</td></tr>';
    return;
  }
  setShareSummary('nfs-summary', fmtServiceState(data) + ' / exports: ' + ((data.exports || []).length) + ' / exports: ' + (data.exports_path || '-'));
  var ports = data.ports || {};
  if (document.getElementById('nfs-mountd-port')) document.getElementById('nfs-mountd-port').value = ports.mountd_port || '';
  if (document.getElementById('nfs-statd-port')) document.getElementById('nfs-statd-port').value = ports.statd_port || '';
  if (document.getElementById('nfs-lockd-port')) document.getElementById('nfs-lockd-port').value = ports.lockd_port || '';
  var items = data.exports || [];
  tbody.innerHTML = items.length ? items.map(function(item) {
    var actions = [
      '<button type="button" class="btn btn-sm btn-secondary" onclick="focusShareAclPath(' + JSON.stringify(item.path || '') + ')">权限</button>'
    ];
    if (HOST_RUNTIME_CAN_MANAGE) {
      actions.push('<button type="button" class="btn btn-sm btn-secondary" onclick="editNfsExport(' + JSON.stringify(item).replace(/"/g, '&quot;').replace(/</g, '\\u003c') + ')">编辑</button>');
      actions.push('<button type="button" class="btn btn-sm btn-danger" onclick="deleteNfsExport(' + JSON.stringify(item.path || '') + ')">删除</button>');
    }
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + String(item.path || '').replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td>'
      + '<td style="font-family:var(--mono)">' + String(item.clients || '').replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td>'
      + '<td>' + ((item.options || []).join(',') || '—') + '</td>'
      + '<td>' + actions.join(' ') + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无 NFS 导出</td></tr>';
}

async function saveNfsExport() {
  var data = await hostRuntimeRun('host-share', '保存 NFS 导出', '正在写入 exports 和 NFS 端口配置…', function() {
    return hostRuntimeApi('nfs_export_save', {
      path: document.getElementById('nfs-path').value || '',
      clients: document.getElementById('nfs-clients').value || '',
      options: document.getElementById('nfs-options').value || '',
      async_mode: document.getElementById('nfs-async-mode').checked ? '1' : '0',
      mountd_port: document.getElementById('nfs-mountd-port').value || '',
      statd_port: document.getElementById('nfs-statd-port').value || '',
      lockd_port: document.getElementById('nfs-lockd-port').value || ''
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'NFS 导出已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadNfsStatus();
}

async function deleteNfsExport(path) {
  if (!confirm('确认删除 NFS 导出 ' + path + ' ?')) return;
  var data = await hostRuntimeRun('host-share', '删除 NFS 导出', '正在更新 exports 配置…', function() {
    return hostRuntimeApi('nfs_export_delete', { path: path }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'NFS 导出已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadNfsStatus();
}

async function installNfsService() {
  var data = await hostRuntimeRun('host-share', '安装 NFS', '正在安装 NFS 服务…', function() {
    return hostRuntimeApi('nfs_install', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'NFS 已安装' : '安装失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadNfsStatus();
}

async function uninstallNfsService() {
  if (!confirm('确认卸载 NFS 服务？')) return;
  var data = await hostRuntimeRun('host-share', '卸载 NFS', '正在卸载 NFS 服务…', function() {
    return hostRuntimeApi('nfs_uninstall', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'NFS 已卸载' : '卸载失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadNfsStatus();
}

async function runNfsAction(action) {
  var data = await hostRuntimeRun('host-share', '执行 NFS 服务操作', '正在对 NFS 执行 ' + action + ' …', function() {
    return hostRuntimeApi('nfs_action', { service_action: action }, 'POST');
  });
  showToast(data.msg || (data.ok ? '操作完成' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadNfsStatus();
}

async function loadAfpStatus() {
  var data = await hostRuntimeRun('host-share', '获取 AFP 状态', '正在读取 AFP 配置和服务状态…', function() {
    return hostRuntimeApi('afp_status');
  });
  var tbody = document.getElementById('afp-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + (data.msg || 'AFP 状态读取失败') + '</td></tr>';
    return;
  }
  setShareSummary('afp-summary', fmtServiceState(data) + ' / port: ' + (data.port || '默认') + ' / 配置: ' + (data.config_path || '-'));
  if (document.getElementById('afp-port')) document.getElementById('afp-port').value = data.port || '';
  var items = data.shares || [];
  tbody.innerHTML = items.length ? items.map(function(item) {
    var actions = [
      '<button type="button" class="btn btn-sm btn-secondary" onclick="focusShareAclPath(' + JSON.stringify(item.path || '') + ')">权限</button>'
    ];
    if (HOST_RUNTIME_CAN_MANAGE) {
      actions.push('<button type="button" class="btn btn-sm btn-secondary" onclick="editAfpShare(' + JSON.stringify(item).replace(/"/g, '&quot;').replace(/</g, '\\u003c') + ', ' + JSON.stringify(data.port || '').replace(/"/g, '&quot;') + ')">编辑</button>');
      actions.push('<button type="button" class="btn btn-sm btn-danger" onclick="deleteAfpShare(' + JSON.stringify(item.name || '') + ')">删除</button>');
    }
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + String(item.name || '').replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td>'
      + '<td style="font-family:var(--mono)">' + String(item.path || '').replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td>'
      + '<td>' + ('valid: ' + ((item.valid_users || []).join(',') || '—') + ' / rw: ' + ((item.rwlist || []).join(',') || '—')) + '</td>'
      + '<td>' + actions.join(' ') + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无 AFP 共享</td></tr>';
}

async function saveAfpShare() {
  var data = await hostRuntimeRun('host-share', '保存 AFP 共享', '正在写入 netatalk 配置…', function() {
    return hostRuntimeApi('afp_share_save', {
      name: document.getElementById('afp-name').value || '',
      path: document.getElementById('afp-path').value || '',
      port: document.getElementById('afp-port').value || '',
      valid_users: document.getElementById('afp-valid-users').value || '',
      rwlist: document.getElementById('afp-rwlist').value || ''
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'AFP 共享已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAfpStatus();
}

async function deleteAfpShare(name) {
  if (!confirm('确认删除 AFP 共享 ' + name + ' ?')) return;
  var data = await hostRuntimeRun('host-share', '删除 AFP 共享', '正在更新 AFP 配置…', function() {
    return hostRuntimeApi('afp_share_delete', { name: name }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'AFP 共享已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAfpStatus();
}

async function installAfpService() {
  var data = await hostRuntimeRun('host-share', '安装 AFP', '正在安装 netatalk 服务…', function() {
    return hostRuntimeApi('afp_install', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'AFP 已安装' : '安装失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAfpStatus();
}

async function uninstallAfpService() {
  if (!confirm('确认卸载 AFP 服务？')) return;
  var data = await hostRuntimeRun('host-share', '卸载 AFP', '正在卸载 netatalk 服务…', function() {
    return hostRuntimeApi('afp_uninstall', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'AFP 已卸载' : '卸载失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAfpStatus();
}

async function runAfpAction(action) {
  var data = await hostRuntimeRun('host-share', '执行 AFP 服务操作', '正在对 AFP 执行 ' + action + ' …', function() {
    return hostRuntimeApi('afp_action', { service_action: action }, 'POST');
  });
  showToast(data.msg || (data.ok ? '操作完成' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAfpStatus();
}

async function loadAsyncStatus() {
  var data = await hostRuntimeRun('host-share', '获取 Async 状态', '正在读取 Async / Rsync 配置…', function() {
    return hostRuntimeApi('async_status');
  });
  var tbody = document.getElementById('async-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + (data.msg || 'Async 状态读取失败') + '</td></tr>';
    return;
  }
  setShareSummary('async-summary', fmtServiceState(data) + ' / port: ' + (data.port || '873') + ' / 配置: ' + (data.config_path || '-'));
  if (document.getElementById('async-port')) document.getElementById('async-port').value = data.port || '873';
  var items = data.modules || [];
  tbody.innerHTML = items.length ? items.map(function(item) {
    var actions = [
      '<button type="button" class="btn btn-sm btn-secondary" onclick="focusShareAclPath(' + JSON.stringify(item.path || '') + ')">权限</button>'
    ];
    if (HOST_RUNTIME_CAN_MANAGE) {
      actions.push('<button type="button" class="btn btn-sm btn-secondary" onclick="editAsyncModule(' + JSON.stringify(item).replace(/"/g, '&quot;').replace(/</g, '\\u003c') + ', ' + JSON.stringify(data.port || '873').replace(/"/g, '&quot;') + ')">编辑</button>');
      actions.push('<button type="button" class="btn btn-sm btn-danger" onclick="deleteAsyncModule(' + JSON.stringify(item.name || '') + ')">删除</button>');
    }
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + String(item.name || '').replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td>'
      + '<td style="font-family:var(--mono)">' + String(item.path || '').replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</td>'
      + '<td>' + ((item.read_only ? '只读' : '读写') + ' / users: ' + ((item.auth_users || []).join(',') || '匿名')) + '</td>'
      + '<td>' + actions.join(' ') + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无 Async / Rsync 模块</td></tr>';
}

async function saveAsyncModule() {
  var data = await hostRuntimeRun('host-share', '保存 Async 模块', '正在写入 Async / Rsync 配置…', function() {
    return hostRuntimeApi('async_module_save', {
      name: document.getElementById('async-name').value || '',
      path: document.getElementById('async-path').value || '',
      port: document.getElementById('async-port').value || '873',
      comment: document.getElementById('async-comment').value || '',
      auth_users: document.getElementById('async-auth-users').value || '',
      read_only: document.getElementById('async-read-only').checked ? '1' : '0'
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'Async 模块已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAsyncStatus();
}

async function deleteAsyncModule(name) {
  if (!confirm('确认删除 Async / Rsync 模块 ' + name + ' ?')) return;
  var data = await hostRuntimeRun('host-share', '删除 Async 模块', '正在更新 Async / Rsync 配置…', function() {
    return hostRuntimeApi('async_module_delete', { name: name }, 'POST');
  });
  showToast(data.msg || (data.ok ? 'Async 模块已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAsyncStatus();
}

async function installAsyncService() {
  var data = await hostRuntimeRun('host-share', '安装 Async', '正在安装 Rsync 服务…', function() {
    return hostRuntimeApi('async_install', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'Async 已安装' : '安装失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAsyncStatus();
}

async function uninstallAsyncService() {
  if (!confirm('确认卸载 Async / Rsync 服务？')) return;
  var data = await hostRuntimeRun('host-share', '卸载 Async', '正在卸载 Rsync 服务…', function() {
    return hostRuntimeApi('async_uninstall', {}, 'POST');
  });
  showToast(data.msg || (data.ok ? 'Async 已卸载' : '卸载失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAsyncStatus();
}

async function runAsyncAction(action) {
  var data = await hostRuntimeRun('host-share', '执行 Async 服务操作', '正在对 Async / Rsync 执行 ' + action + ' …', function() {
    return hostRuntimeApi('async_action', { service_action: action }, 'POST');
  });
  showToast(data.msg || (data.ok ? '操作完成' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadAsyncStatus();
}

function userActionsHtml(item) {
  if (!HOST_RUNTIME_CAN_MANAGE) return '—';
  var name = JSON.stringify(item.username || '');
  var lockLabel = item.locked ? '解锁' : '锁定';
  return '<button type="button" class="btn btn-sm btn-secondary" onclick="changeUserPassword(' + name + ')">密码</button>'
    + ' <button type="button" class="btn btn-sm btn-secondary" onclick="toggleUserLock(' + name + ', ' + JSON.stringify(item.locked ? '0' : '1') + ')">' + lockLabel + '</button>'
    + ' <button type="button" class="btn btn-sm btn-danger" onclick="deleteUser(' + name + ')">删除</button>';
}

async function loadUsers() {
  var data = await hostRuntimeRun('host-user', '获取用户列表', '正在读取系统用户信息…', function() {
    return hostRuntimeApi('user_list', { keyword: (document.getElementById('user-keyword') || {}).value || '' });
  });
  var tbody = document.getElementById('user-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:var(--red)">' + (data.msg || '用户列表读取失败') + '</td></tr>';
    return;
  }
  var items = data.items || [];
  HOST_RUNTIME_USERS = items.slice();
  updateShareAclDatalists();
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + (item.username || '') + '</td>'
      + '<td>' + (item.uid || '-') + ' / ' + (item.gid || '-') + '</td>'
      + '<td style="font-family:var(--mono)">' + (item.home || '') + '</td>'
      + '<td style="font-family:var(--mono)">' + (item.shell || '') + '</td>'
      + '<td>' + ((item.gecos || '') + ((item.groups && item.groups.length) ? (' / 组: ' + item.groups.join(',')) : '') + (item.locked ? ' / 已锁定' : '')).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</td>'
      + '<td>' + userActionsHtml(item) + '</td></tr>';
  }).join('') : '<tr><td colspan="6" style="color:var(--tm)">暂无用户数据</td></tr>';
}

async function saveHostUser() {
  var data = await hostRuntimeRun('host-user', '保存用户', '正在写入用户配置…', function() {
    return hostRuntimeApi('user_save', {
      username: document.getElementById('user-username').value || '',
      shell: document.getElementById('user-shell').value || '/bin/sh',
      home: document.getElementById('user-home').value || '',
      groups: document.getElementById('user-groups').value || '',
      gecos: document.getElementById('user-gecos').value || '',
      password: document.getElementById('user-password').value || ''
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? '用户已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadUsers();
}

async function changeUserPassword(username) {
  var password = prompt('请输入新的密码', '');
  if (!password) return;
  var data = await hostRuntimeRun('host-user', '更新用户密码', '正在为 ' + username + ' 更新密码…', function() {
    return hostRuntimeApi('user_password', { username: username, password: password }, 'POST');
  });
  showToast(data.msg || (data.ok ? '密码已更新' : '更新失败'), data.ok ? 'success' : 'error');
}

async function toggleUserLock(username, locked) {
  var data = await hostRuntimeRun('host-user', '更新用户状态', '正在' + (locked === '1' ? '锁定' : '解锁') + '用户 ' + username + ' …', function() {
    return hostRuntimeApi('user_lock', { username: username, locked: locked }, 'POST');
  });
  showToast(data.msg || (data.ok ? '用户状态已更新' : '更新失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadUsers();
}

async function deleteUser(username) {
  if (!confirm('确认删除用户 ' + username + ' ?')) return;
  var data = await hostRuntimeRun('host-user', '删除用户', '正在删除用户 ' + username + ' …', function() {
    return hostRuntimeApi('user_delete', { username: username, remove_home: '0' }, 'POST');
  });
  showToast(data.msg || (data.ok ? '用户已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadUsers();
}

function groupActionsHtml(item) {
  if (!HOST_RUNTIME_CAN_MANAGE) return '—';
  return '<button type="button" class="btn btn-sm btn-danger" onclick="deleteGroup(' + JSON.stringify(item.groupname || '') + ')">删除</button>';
}

async function loadGroups() {
  var data = await hostRuntimeRun('host-group', '获取用户组列表', '正在读取用户组信息…', function() {
    return hostRuntimeApi('group_list', { keyword: (document.getElementById('group-keyword') || {}).value || '' });
  });
  var tbody = document.getElementById('group-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + (data.msg || '用户组列表读取失败') + '</td></tr>';
    return;
  }
  var items = data.items || [];
  HOST_RUNTIME_GROUPS = items.slice();
  updateShareAclDatalists();
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr><td style="font-family:var(--mono)">' + (item.groupname || '') + '</td><td>' + (item.gid || '-') + '</td><td>' + ((item.members || []).join(',') || '—') + '</td><td>' + groupActionsHtml(item) + '</td></tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无用户组数据</td></tr>';
}

async function saveHostGroup() {
  var data = await hostRuntimeRun('host-group', '保存用户组', '正在写入用户组配置…', function() {
    return hostRuntimeApi('group_save', {
      groupname: document.getElementById('group-name').value || '',
      members: document.getElementById('group-members').value || ''
    }, 'POST');
  });
  showToast(data.msg || (data.ok ? '用户组已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadGroups();
}

async function deleteGroup(groupname) {
  if (!confirm('确认删除用户组 ' + groupname + ' ?')) return;
  var data = await hostRuntimeRun('host-group', '删除用户组', '正在删除用户组 ' + groupname + ' …', function() {
    return hostRuntimeApi('group_delete', { groupname: groupname }, 'POST');
  });
  showToast(data.msg || (data.ok ? '用户组已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadGroups();
}

document.addEventListener('DOMContentLoaded', function() {
  loadSystemOverview();
  loadProcesses();
  loadServices();
  loadNetworkOverview();
  loadSftpStatus();
  loadSmbStatus();
  loadFtpStatus();
  loadNfsStatus();
  loadAfpStatus();
  loadAsyncStatus();
  loadUsers();
  loadGroups();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
