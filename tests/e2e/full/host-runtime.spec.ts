import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

const hostAgentStatePath = path.resolve(__dirname, '../../../data/host_agent.json');
const simulateRootPath = path.resolve(__dirname, '../../../data/host-agent-sim-root');
const shareHistoryPath = path.resolve(__dirname, '../../../data/share_service_history');
const shareAuditLogPath = path.resolve(__dirname, '../../../data/logs/share_service_audit.log');
const hostAgentContainer = process.env.APP_CONTAINER ? `${process.env.APP_CONTAINER}-host-agent` : 'simple-homepage-host-agent';

async function cleanupHostAgent() {
  runDockerCommand(['rm', '-f', hostAgentContainer]);
  await fs.rm(hostAgentStatePath, { force: true }).catch(() => undefined);
  await fs.rm(simulateRootPath, { recursive: true, force: true }).catch(() => undefined);
  await fs.rm(shareHistoryPath, { recursive: true, force: true }).catch(() => undefined);
  await fs.rm(shareAuditLogPath, { force: true }).catch(() => undefined);
}

async function ensureInstalledHostAgent() {
  const result = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/host_agent_lib.php";',
      '$result = host_agent_install();',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(result.code).toBe(0);
  const payload = JSON.parse(result.stdout);
  expect(payload.ok).toBe(true);
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('host runtime page covers overview processes services network users and groups', async ({ page }) => {
  test.setTimeout(180000);
  await ensureInstalledHostAgent();

  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  const ts = Date.now();
  const username = `hostrt${ts}`;
  const groupname = `hostgrp${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/host_runtime.php');

  await expect(page.locator('body')).toContainText('宿主机运维');
  await expect(page.locator('body')).toContainText('Host-Agent 状态');
  await expect(page.locator('#host-overview-cards')).toContainText('CPU 使用率');
  await expect(page.locator('#host-overview-cards')).toContainText('主机名');

  const networkPayload = await page.evaluate(async () => {
    const res = await fetch('/admin/host_api.php?action=network_overview', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  });
  expect(networkPayload.ok).toBe(true);
  expect(Array.isArray(networkPayload.listeners)).toBe(true);
  expect(Array.isArray(networkPayload.connections)).toBe(true);

  await expect(page.locator('#service-tbody')).toContainText('ssh');
  await page.evaluate(() => {
    const fn = (window as Window & { openServiceLogs?: (service: string) => void }).openServiceLogs;
    if (typeof fn !== 'function') throw new Error('openServiceLogs not found');
    fn('ssh');
  });
  await expect(page.locator('#service-log-modal')).toBeVisible();
  await expect(page.locator('#service-log-body')).toContainText('simulate');
  await page.evaluate(() => {
    const fn = (window as Window & { closeServiceLogs?: () => void }).closeServiceLogs;
    if (typeof fn !== 'function') throw new Error('closeServiceLogs not found');
    fn();
  });

  const spawn = runDockerCommand([
    'exec',
    hostAgentContainer,
    'sh',
    '-lc',
    'sleep 60 >/tmp/host-runtime-sleep.log 2>&1 & echo $!',
  ]);
  expect(spawn.code).toBe(0);
  const pid = Number(spawn.stdout.trim());
  expect(pid).toBeGreaterThan(1);

  await page.locator('#process-keyword').fill(String(pid));
  await page.getByRole('button', { name: '刷新进程' }).click({ force: true });
  await expect(page.locator('#process-tbody')).toContainText(String(pid));
  const csrfToken = await page.evaluate(() => (window as Window & { HOST_RUNTIME_CSRF?: string }).HOST_RUNTIME_CSRF || '');
  expect(csrfToken).not.toBe('');
  const killResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'process_kill',
      _csrf: csrfToken,
      pid: String(pid),
      signal: 'KILL',
    },
  });
  expect(killResponse.status()).toBe(200);
  const killPayload = await killResponse.json();
  expect(killPayload.ok).toBe(true);
  await expect
    .poll(() => {
      const stat = runDockerCommand(['exec', hostAgentContainer, 'sh', '-lc', `ps -o stat= -p ${pid} 2>/dev/null | tr -d " \\t\\r\\n"`]).stdout.trim();
      return stat === '' || stat.startsWith('Z');
    })
    .toBe(true);

  await page.locator('#user-username').fill(username);
  await page.locator('#user-shell').fill('/bin/sh');
  await page.locator('#user-home').fill(`/home/${username}`);
  await page.locator('#user-groups').fill(groupname);
  await page.locator('#user-password').fill('HostRuntime@test2026');
  await page.getByRole('button', { name: '保存用户', exact: true }).click({ force: true });
  await expect(page.locator('#user-tbody')).toContainText(username);

  await page.locator('#group-name').fill(groupname);
  await page.locator('#group-members').fill(username);
  await page.getByRole('button', { name: '保存用户组', exact: true }).click({ force: true });
  await expect(page.locator('#group-tbody')).toContainText(groupname);
  await expect(page.locator('#group-tbody')).toContainText(username);

  await expect(page.locator('#sftp-username')).toHaveAttribute('list', 'host-user-datalist');
  await expect(page.locator('#smb-valid-users')).toHaveAttribute('list', 'host-user-datalist');
  await expect(page.locator('#ftp-allowed-users')).toHaveAttribute('list', 'host-user-datalist');
  await expect(page.locator('#afp-valid-users')).toHaveAttribute('list', 'host-user-datalist');
  await expect(page.locator('#async-auth-users')).toHaveAttribute('list', 'host-user-datalist');

  await page.locator('#sftp-username').fill(username);
  await expect(page.locator('#sftp-chroot')).toHaveValue(`/srv/sftp/${username}`);
  await page.locator('#sftp-chroot').fill(`/srv/sftp/${username}`);
  await page.getByRole('button', { name: '保存 SFTP 策略' }).click({ force: true });
  await expect(page.locator('#sftp-tbody')).toContainText(username);
  await expect(page.locator('#sftp-tbody')).toContainText(`/srv/sftp/${username}`);
  await expect(page.locator('#sftp-tbody')).toContainText('编辑');
  await expect(page.locator('#sftp-tbody')).toContainText('权限');
  await page.evaluate((targetPath) => {
    const fn = (window as Window & { focusShareAclPath?: (path: string) => void }).focusShareAclPath;
    if (typeof fn !== 'function') throw new Error('focusShareAclPath not found');
    fn(targetPath);
  }, `/srv/sftp/${username}`);
  await expect(page.locator('#share-acl-path')).toHaveValue(`/srv/sftp/${username}`);
  await page.evaluate((targetUser) => {
    const row = Array.from(document.querySelectorAll('#sftp-tbody tr')).find((node) => node.textContent?.includes(targetUser));
    const button = row?.querySelector('button[onclick*="editSftpPolicy("]') as HTMLButtonElement | null;
    if (!button) throw new Error('editSftpPolicy button not found');
    button.click();
  }, username);
  await expect(page.locator('#sftp-username')).toHaveValue(username);
  await expect(page.locator('#sftp-chroot')).toHaveValue(`/srv/sftp/${username}`);
  await page.locator("button[onclick=\"openShareServiceLogs('sftp')\"]").click({ force: true });
  await expect(page.locator('#service-log-title')).toContainText('SFTP / SSH 日志');
  await expect(page.locator('#service-log-body')).toContainText('[simulate] service=ssh');
  await page.evaluate(() => {
    const fn = (window as Window & { closeServiceLogs?: () => void }).closeServiceLogs;
    if (typeof fn !== 'function') throw new Error('closeServiceLogs not found');
    fn();
  });

  await fs.mkdir(path.join(simulateRootPath, 'srv', `share-${ts}`, 'nested'), { recursive: true });
  await fs.writeFile(path.join(simulateRootPath, 'srv', `share-${ts}`, 'nested', 'demo.txt'), 'hello host runtime\n', 'utf8');

  await page.locator('#smb-name').fill(`share${ts}`);
  await page.locator('#smb-path').fill(`/srv/share-${ts}`);
  await page.locator('#smb-valid-users').fill(username);
  await page.locator('#smb-write-users').fill(username);
  await page.getByRole('button', { name: '安装 SMB' }).click({ force: true });
  await page.getByRole('button', { name: '保存 SMB 共享' }).click({ force: true });
  await expect(page.locator('#smb-tbody')).toContainText(`share${ts}`);
  await expect(page.locator('#smb-tbody')).toContainText(`/srv/share-${ts}`);
  await expect(page.locator('#smb-tbody')).toContainText('编辑');
  await page.evaluate((shareName) => {
    const row = Array.from(document.querySelectorAll('#smb-tbody tr')).find((node) => node.textContent?.includes(shareName));
    const button = row?.querySelector('button[onclick*="editSmbShare("]') as HTMLButtonElement | null;
    if (!button) throw new Error('editSmbShare button not found');
    button.click();
  }, `share${ts}`);
  await expect(page.locator('#smb-name')).toHaveValue(`share${ts}`);
  await expect(page.locator('#smb-path')).toHaveValue(`/srv/share-${ts}`);
  await expect(page.locator('#smb-valid-users')).toHaveValue(username);
  await expect(page.locator('#smb-write-users')).toHaveValue(username);
  await page.locator("button[onclick=\"openShareServiceLogs('smb')\"]").click({ force: true });
  await expect(page.locator('#service-log-title')).toContainText('SMB 日志');
  await expect(page.locator('#service-log-body')).toContainText('[simulate] service=smb');
  await expect(page.locator('#service-log-body')).toContainText('files=');
  await page.evaluate(() => {
    const fn = (window as Window & { closeServiceLogs?: () => void }).closeServiceLogs;
    if (typeof fn !== 'function') throw new Error('closeServiceLogs not found');
    fn();
  });

  await expect(page.locator('#smb-tbody')).toContainText('权限');
  await page.evaluate((targetPath) => {
    const fn = (window as Window & { focusShareAclPath?: (path: string) => void }).focusShareAclPath;
    if (typeof fn !== 'function') throw new Error('focusShareAclPath not found');
    fn(targetPath);
  }, `/srv/share-${ts}`);
  await expect(page.locator('#share-acl-path')).toHaveValue(`/srv/share-${ts}`);
  await expect(page.locator('#share-acl-stat')).toContainText(`/srv/share-${ts}`);
  const applyAclResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'share_path_apply_acl',
      _csrf: csrfToken,
      path: `/srv/share-${ts}`,
      owner: username,
      group: groupname,
      mode: '0770',
      recursive: '1',
    },
  });
  expect(applyAclResponse.status()).toBe(200);
  expect((await applyAclResponse.json()).ok).toBe(true);
  await expect
    .poll(async () => {
      const res = await page.evaluate(async (targetPath) => {
        const r = await fetch('/admin/host_api.php?action=share_path_stat&path=' + encodeURIComponent(targetPath), {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        return r.json();
      }, `/srv/share-${ts}`);
      return `${res.owner}|${res.group}|${res.mode}`;
    })
    .toBe(`${username}|${groupname}|0770`);

  const nestedStat = await page.evaluate(async (targetPath) => {
    const res = await fetch('/admin/host_api.php?action=share_path_stat&path=' + encodeURIComponent(targetPath), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  }, `/srv/share-${ts}/nested/demo.txt`);
  expect(nestedStat.ok).toBe(true);
  expect(nestedStat.owner).toBe(username);
  expect(nestedStat.group).toBe(groupname);
  expect(nestedStat.mode).toBe('0770');

  await page.getByRole('button', { name: '安装 FTP' }).click({ force: true });
  await page.locator('#ftp-local-root').fill(`/srv/ftp-${ts}`);
  await page.locator('#ftp-allowed-users').fill(username);
  await page.getByRole('button', { name: '保存 FTP 配置' }).click({ force: true });
  await expect
    .poll(() => runDockerPhpInline([
      '$path = "/var/www/nav/data/host-agent-sim-root/etc/vsftpd.conf";',
      'echo is_file($path) ? file_get_contents($path) : "";',
    ].join(' ')).stdout)
    .toContain(`local_root=/srv/ftp-${ts}`);
  await page.locator("button[onclick=\"openShareServiceLogs('ftp')\"]").click({ force: true });
  await expect(page.locator('#service-log-title')).toContainText('FTP 日志');
  await expect(page.locator('#service-log-body')).toContainText('[simulate] service=ftp');
  await page.evaluate(() => {
    const fn = (window as Window & { closeServiceLogs?: () => void }).closeServiceLogs;
    if (typeof fn !== 'function') throw new Error('closeServiceLogs not found');
    fn();
  });

  await page.getByRole('button', { name: '安装 NFS' }).click({ force: true });
  await page.locator('#nfs-path').fill(`/srv/nfs-${ts}`);
  await page.locator('#nfs-clients').fill('192.168.1.0/24');
  await page.locator('#nfs-options').fill('rw,sync,no_subtree_check');
  await page.locator('#nfs-mountd-port').fill('20048');
  await page.locator('#nfs-statd-port').fill('32765');
  await page.locator('#nfs-lockd-port').fill('32768');
  const saveNfsResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'nfs_export_save',
      _csrf: csrfToken,
      path: `/srv/nfs-${ts}`,
      clients: '192.168.1.0/24',
      options: 'rw,sync,no_subtree_check',
      async_mode: '1',
      mountd_port: '20048',
      statd_port: '32765',
      lockd_port: '32768',
    },
  });
  expect(saveNfsResponse.status()).toBe(200);
  expect((await saveNfsResponse.json()).ok).toBe(true);
  await expect
    .poll(() => runDockerPhpInline([
      '$path = "/var/www/nav/data/host-agent-sim-root/etc/exports";',
      'echo is_file($path) ? file_get_contents($path) : "";',
    ].join(' ')).stdout)
    .toContain(`/srv/nfs-${ts} 192.168.1.0/24`);
  await expect(page.locator('#nfs-tbody')).toContainText('编辑');
  await expect(page.locator('#nfs-tbody')).toContainText('权限');
  await page.evaluate((targetPath) => {
    const fn = (window as Window & { focusShareAclPath?: (path: string) => void }).focusShareAclPath;
    if (typeof fn !== 'function') throw new Error('focusShareAclPath not found');
    fn(targetPath);
  }, `/srv/nfs-${ts}`);
  await expect(page.locator('#share-acl-path')).toHaveValue(`/srv/nfs-${ts}`);
  await page.evaluate((targetPath) => {
    const row = Array.from(document.querySelectorAll('#nfs-tbody tr')).find((node) => node.textContent?.includes(targetPath));
    const button = row?.querySelector('button[onclick*="editNfsExport("]') as HTMLButtonElement | null;
    if (!button) throw new Error('editNfsExport button not found');
    button.click();
  }, `/srv/nfs-${ts}`);
  await expect(page.locator('#nfs-path')).toHaveValue(`/srv/nfs-${ts}`);
  await expect(page.locator('#nfs-clients')).toHaveValue('192.168.1.0/24');
  await expect(page.locator('#nfs-options')).toHaveValue('rw,no_subtree_check,async');
  await page.locator("button[onclick=\"openShareServiceLogs('nfs')\"]").click({ force: true });
  await expect(page.locator('#service-log-title')).toContainText('NFS 日志');
  await expect(page.locator('#service-log-body')).toContainText('[simulate] service=nfs');
  await page.evaluate(() => {
    const fn = (window as Window & { closeServiceLogs?: () => void }).closeServiceLogs;
    if (typeof fn !== 'function') throw new Error('closeServiceLogs not found');
    fn();
  });

  await page.getByRole('button', { name: '安装 AFP' }).click({ force: true });
  await page.locator('#afp-name').fill(`afp${ts}`);
  await page.locator('#afp-path').fill(`/srv/afp-${ts}`);
  await page.locator('#afp-port').fill('548');
  await page.locator('#afp-valid-users').fill(username);
  await page.locator('#afp-rwlist').fill(username);
  await page.getByRole('button', { name: '保存 AFP 共享' }).click({ force: true });
  await expect
    .poll(() => runDockerPhpInline([
      '$path = "/var/www/nav/data/host-agent-sim-root/etc/netatalk/afp.conf";',
      'echo is_file($path) ? file_get_contents($path) : "";',
    ].join(' ')).stdout)
    .toContain(`[afp${ts}]`);
  await expect(page.locator('#afp-tbody')).toContainText('编辑');
  await expect(page.locator('#afp-tbody')).toContainText('权限');
  await page.evaluate((targetPath) => {
    const fn = (window as Window & { focusShareAclPath?: (path: string) => void }).focusShareAclPath;
    if (typeof fn !== 'function') throw new Error('focusShareAclPath not found');
    fn(targetPath);
  }, `/srv/afp-${ts}`);
  await expect(page.locator('#share-acl-path')).toHaveValue(`/srv/afp-${ts}`);
  await page.evaluate((shareName) => {
    const row = Array.from(document.querySelectorAll('#afp-tbody tr')).find((node) => node.textContent?.includes(shareName));
    const button = row?.querySelector('button[onclick*="editAfpShare("]') as HTMLButtonElement | null;
    if (!button) throw new Error('editAfpShare button not found');
    button.click();
  }, `afp${ts}`);
  await expect(page.locator('#afp-name')).toHaveValue(`afp${ts}`);
  await expect(page.locator('#afp-path')).toHaveValue(`/srv/afp-${ts}`);
  await expect(page.locator('#afp-valid-users')).toHaveValue(username);
  await expect(page.locator('#afp-rwlist')).toHaveValue(username);
  await page.locator("button[onclick=\"openShareServiceLogs('afp')\"]").click({ force: true });
  await expect(page.locator('#service-log-title')).toContainText('AFP 日志');
  await expect(page.locator('#service-log-body')).toContainText('[simulate] service=afp');
  await page.evaluate(() => {
    const fn = (window as Window & { closeServiceLogs?: () => void }).closeServiceLogs;
    if (typeof fn !== 'function') throw new Error('closeServiceLogs not found');
    fn();
  });

  await page.getByRole('button', { name: '安装 Async' }).click({ force: true });
  await page.locator('#async-name').fill(`async${ts}`);
  await page.locator('#async-path').fill(`/srv/async-${ts}`);
  await page.locator('#async-port').fill('873');
  await page.locator('#async-auth-users').fill(username);
  await page.locator('#async-comment').fill('sync module');
  const saveAsyncResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'async_module_save',
      _csrf: csrfToken,
      name: `async${ts}`,
      path: `/srv/async-${ts}`,
      port: '873',
      auth_users: username,
      comment: 'sync module',
      read_only: '0',
    },
  });
  expect(saveAsyncResponse.status()).toBe(200);
  expect((await saveAsyncResponse.json()).ok).toBe(true);
  await expect
    .poll(() => runDockerPhpInline([
      '$path = "/var/www/nav/data/host-agent-sim-root/etc/rsyncd.conf";',
      'echo is_file($path) ? file_get_contents($path) : "";',
    ].join(' ')).stdout)
    .toContain(`[async${ts}]`);
  await expect(page.locator('#async-tbody')).toContainText('编辑');
  await expect(page.locator('#async-tbody')).toContainText('权限');
  await page.evaluate((targetPath) => {
    const fn = (window as Window & { focusShareAclPath?: (path: string) => void }).focusShareAclPath;
    if (typeof fn !== 'function') throw new Error('focusShareAclPath not found');
    fn(targetPath);
  }, `/srv/async-${ts}`);
  await expect(page.locator('#share-acl-path')).toHaveValue(`/srv/async-${ts}`);
  await page.evaluate((moduleName) => {
    const row = Array.from(document.querySelectorAll('#async-tbody tr')).find((node) => node.textContent?.includes(moduleName));
    const button = row?.querySelector('button[onclick*="editAsyncModule("]') as HTMLButtonElement | null;
    if (!button) throw new Error('editAsyncModule button not found');
    button.click();
  }, `async${ts}`);
  await expect(page.locator('#async-name')).toHaveValue(`async${ts}`);
  await expect(page.locator('#async-path')).toHaveValue(`/srv/async-${ts}`);
  await expect(page.locator('#async-auth-users')).toHaveValue(username);
  await expect(page.locator('#async-comment')).toHaveValue('sync module');
  await page.locator("button[onclick=\"openShareServiceLogs('async')\"]").click({ force: true });
  await expect(page.locator('#service-log-title')).toContainText('Async / Rsync 日志');
  await expect(page.locator('#service-log-body')).toContainText('[simulate] service=async');
  await page.evaluate(() => {
    const fn = (window as Window & { closeServiceLogs?: () => void }).closeServiceLogs;
    if (typeof fn !== 'function') throw new Error('closeServiceLogs not found');
    fn();
  });

  const userList = await page.evaluate(async () => {
    const res = await fetch('/admin/host_api.php?action=user_list', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  });
  expect(userList.ok).toBe(true);
  expect((userList.items || []).some((item: any) => item.username === username)).toBe(true);

  const groupList = await page.evaluate(async () => {
    const res = await fetch('/admin/host_api.php?action=group_list', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  });
  expect(groupList.ok).toBe(true);
  expect((groupList.items || []).some((item: any) => item.groupname === groupname)).toBe(true);

  const sftpPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/ssh/sshd_config";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(sftpPayload.stdout).toContain(`Match User ${username}`);
  expect(sftpPayload.stdout).toContain(`ChrootDirectory /srv/sftp/${username}`);

  const smbPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/samba/smb.conf";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(smbPayload.stdout).toContain(`[share${ts}]`);
  expect(smbPayload.stdout).toContain(`path = /srv/share-${ts}`);

  const ftpConfigPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/vsftpd.conf";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(ftpConfigPayload.stdout).toContain(`local_root=/srv/ftp-${ts}`);
  const ftpUserlistPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/vsftpd.userlist";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(ftpUserlistPayload.stdout).toContain(username);

  const nfsExportsPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/exports";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(nfsExportsPayload.stdout).toContain(`/srv/nfs-${ts} 192.168.1.0/24`);
  expect(nfsExportsPayload.stdout).toContain('async');
  const nfsConfPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/nfs.conf";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(nfsConfPayload.stdout).toContain('mountd.port=20048');

  const afpPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/netatalk/afp.conf";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(afpPayload.stdout).toContain(`[afp${ts}]`);
  expect(afpPayload.stdout).toContain(`path = /srv/afp-${ts}`);

  const asyncPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/rsyncd.conf";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(asyncPayload.stdout).toContain(`[async${ts}]`);
  expect(asyncPayload.stdout).toContain(`path = /srv/async-${ts}`);

  await page.getByRole('link', { name: '共享服务审计与历史' }).click();
  await expect(page.locator('body')).toContainText('共享服务审计');
  await expect(page.locator('body')).toContainText('async_module_save');
  await expect(page.locator('body')).toContainText('nfs_export_save');
  await expect(page.locator('body')).toContainText('配置历史');

  const historyList = await page.evaluate(async () => {
    const res = await fetch('/admin/host_api.php?action=share_history_list&service_name=async&limit=20', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  });
  expect(historyList.ok).toBe(true);
  const asyncHistory = (historyList.items || []).find((item: any) => item.service === 'async' && item.action === 'save_module');
  expect(asyncHistory).toBeTruthy();

  const deleteAsyncResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'async_module_delete',
      _csrf: csrfToken,
      name: `async${ts}`,
    },
  });
  expect(deleteAsyncResponse.status()).toBe(200);
  expect((await deleteAsyncResponse.json()).ok).toBe(true);
  const asyncDeletedPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/rsyncd.conf";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(asyncDeletedPayload.stdout).not.toContain(`[async${ts}]`);

  const restoreAsyncResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'share_history_restore',
      _csrf: csrfToken,
      history_id: asyncHistory.id,
    },
  });
  expect(restoreAsyncResponse.status()).toBe(200);
  expect((await restoreAsyncResponse.json()).ok).toBe(true);
  const asyncRestoredPayload = runDockerPhpInline([
    '$path = "/var/www/nav/data/host-agent-sim-root/etc/rsyncd.conf";',
    'echo is_file($path) ? file_get_contents($path) : "";',
  ].join(' '));
  expect(asyncRestoredPayload.stdout).toContain(`[async${ts}]`);

  const deleteGroupResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'group_delete',
      _csrf: csrfToken,
      groupname,
    },
  });
  expect(deleteGroupResponse.status()).toBe(200);
  expect((await deleteGroupResponse.json()).ok).toBe(true);
  await expect
    .poll(async () => {
      const payload = await page.evaluate(async () => {
        const res = await fetch('/admin/host_api.php?action=group_list', {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        return res.json();
      });
      return (payload.items || []).some((item: any) => item.groupname === groupname);
    })
    .toBe(false);

  const deleteUserResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'user_delete',
      _csrf: csrfToken,
      username,
      remove_home: '0',
    },
  });
  expect(deleteUserResponse.status()).toBe(200);
  expect((await deleteUserResponse.json()).ok).toBe(true);
  await expect
    .poll(async () => {
      const payload = await page.evaluate(async () => {
        const res = await fetch('/admin/host_api.php?action=user_list', {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        return res.json();
      });
      return (payload.items || []).some((item: any) => item.username === username);
    })
    .toBe(false);

  await tracker.assertNoClientErrors();
});
