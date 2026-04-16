import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

const hostAgentStatePath = path.resolve(__dirname, '../../../data/host_agent.json');
const simulateRootPath = path.resolve(__dirname, '../../../data/host-agent-sim-root');
const hostAgentContainer = process.env.APP_CONTAINER ? `${process.env.APP_CONTAINER}-host-agent` : 'simple-homepage-host-agent';

async function cleanupHostAgent() {
  runDockerCommand(['rm', '-f', hostAgentContainer]);
  await fs.rm(hostAgentStatePath, { force: true }).catch(() => undefined);
  await fs.rm(simulateRootPath, { recursive: true, force: true }).catch(() => undefined);
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

function generateTestPrivateKey() {
  const result = runDockerCommand([
    'exec',
    'simple-homepage',
    'sh',
    '-lc',
    'tmp=$(mktemp -u); ssh-keygen -t ed25519 -N "" -f "$tmp" >/dev/null 2>&1 && cat "$tmp" && rm -f "$tmp" "$tmp.pub"',
  ]);
  expect(result.code).toBe(0);
  expect(result.stdout).toContain('BEGIN OPENSSH PRIVATE KEY');
  return result.stdout.trim();
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('host management page can manage local ssh config through host-agent simulate mode', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();

  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php');

  await expect(page.locator('body')).toContainText('主机管理');
  await expect(page.locator('body')).toContainText('本机 SSH 服务');
  await expect(page.locator('body')).toContainText('simulate');
  await expect(page.locator('body')).toContainText('运行中');
  await expect(page.locator('textarea[name="ssh_config"]')).toContainText('Managed by host-agent');

  const genericStatus = await page.evaluate(async () => {
    const res = await fetch('/admin/host_api.php?action=ssh_target_status&host_id=local', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  });
  expect(genericStatus.ok).toBe(true);
  expect(genericStatus.mode).toBe('simulate');

  const genericConfig = await page.evaluate(async () => {
    const res = await fetch('/admin/host_api.php?action=ssh_target_config_read&host_id=local', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  });
  expect(genericConfig.ok).toBe(true);
  expect(genericConfig.content).toContain('Managed by host-agent');

  const newConfig = [
    '# Managed by host-agent',
    'Port 2222',
    'PermitRootLogin no',
    'PasswordAuthentication no',
    'PubkeyAuthentication yes',
    '',
  ].join('\n');
  const csrfToken = await page.evaluate(() => (window as Window & { HOST_CSRF?: string }).HOST_CSRF || '');
  const saveConfigResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'ssh_target_config_save',
      _csrf: csrfToken,
      host_id: 'local',
      content: newConfig,
    },
  });
  expect(saveConfigResponse.status()).toBe(200);
  expect((await saveConfigResponse.json()).ok).toBe(true);
  await page.goto('/admin/hosts.php');
  await expect(page.locator('textarea[name="ssh_config"]')).toHaveValue(newConfig);

  const configResult = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root/etc/ssh/sshd_config";',
      'echo file_exists($path) ? file_get_contents($path) : "";',
    ].join(' ')
  );
  expect(configResult.code).toBe(0);
  expect(configResult.stdout).toContain('Port 2222');

  const stopResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'ssh_target_service_action',
      _csrf: csrfToken,
      host_id: 'local',
      service_action: 'stop',
    },
  });
  expect(stopResponse.status()).toBe(200);
  expect((await stopResponse.json()).ok).toBe(true);

  const stopState = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root/var/lib/host-agent/ssh_service_state.json";',
      'echo file_exists($path) ? file_get_contents($path) : "{}";',
    ].join(' ')
  );
  expect(stopState.code).toBe(0);
  expect(JSON.parse(stopState.stdout).running).toBe(false);

  const startResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'ssh_target_service_action',
      _csrf: csrfToken,
      host_id: 'local',
      service_action: 'start',
    },
  });
  expect(startResponse.status()).toBe(200);
  expect((await startResponse.json()).ok).toBe(true);

  const startState = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root/var/lib/host-agent/ssh_service_state.json";',
      'echo file_exists($path) ? file_get_contents($path) : "{}";',
    ].join(' ')
  );
  expect(startState.code).toBe(0);
  expect(JSON.parse(startState.stdout).running).toBe(true);

  await tracker.assertNoClientErrors();
});

test('host management page covers ssh keys remote hosts file manager terminal and ssh-specific roles', async ({ page }) => {
  test.setTimeout(180000);
  await ensureInstalledHostAgent();
  const privateKey = generateTestPrivateKey();
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/host_api\.php.* :: net::ERR_ABORTED/,
      /POST .*\/admin\/host_api\.php.* :: net::ERR_ABORTED/,
    ],
  });

  const ts = Date.now();
  const keyName = `测试密钥${ts}`;
  const importKeyName = `导入密钥${ts}`;
  const privateKeyBase64 = Buffer.from(privateKey, 'utf8').toString('base64');
  const hostName = `测试主机${ts}`;
  const hostAdmin = `hadmin${ts}`;
  const hostViewer = `hview${ts}`;
  const password = 'HostRole@test2026';
  const filePath = `/workspace-${ts}.txt`;
  const fileContent = `hello-host-file-${ts}`;
  const binaryFileName = `binary-${ts}.bin`;
  const binaryFilePath = `/${binaryFileName}`;
  const binaryContent = Buffer.from([0, 1, 2, 3, 255, 100, 10, 88]);
  const authorizedContent = `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestKey${ts} root@test`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php');
  const seedKey = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$privateKey = base64_decode("' + privateKeyBase64 + '");',
      '$result = ssh_manager_upsert_key(["name" => "' + keyName + '", "username" => "", "private_key" => $privateKey, "passphrase" => ""], null);',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(seedKey.code).toBe(0);
  expect(JSON.parse(seedKey.stdout).ok).toBe(true);

  const seedHost = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$keys = ssh_manager_list_keys();',
      '$keyId = "";',
      'foreach ($keys as $key) { if (($key["name"] ?? "") === "' + keyName + '") { $keyId = (string)($key["id"] ?? ""); break; } }',
      '$result = ssh_manager_upsert_host(["name" => "' + hostName + '", "hostname" => "127.0.0.1", "port" => 1, "username" => "root", "auth_type" => "key", "key_id" => $keyId, "password" => "", "group_name" => "", "tags" => "", "favorite" => false, "notes" => ""], null);',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(seedHost.code).toBe(0);
  expect(JSON.parse(seedHost.stdout).ok).toBe(true);

  await page.goto('/admin/hosts.php');
  await expect(page.locator('body')).toContainText(keyName);
  await expect(page.locator('body')).toContainText(hostName);

  const importKeySeed = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$privateKey = base64_decode("' + privateKeyBase64 + '");',
      '$result = ssh_manager_upsert_key(["name" => "' + importKeyName + '", "username" => "", "private_key" => $privateKey, "passphrase" => ""], null);',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(importKeySeed.code).toBe(0);
  expect(JSON.parse(importKeySeed.stdout).ok).toBe(true);
  await page.goto('/admin/hosts.php');

  await page.locator('#file-host-select').selectOption('local');
  await page.locator('#file-edit-path').fill(filePath);
  await page.locator('#file-editor').fill(fileContent);
  await page.getByRole('button', { name: /保存文件/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/文件已保存|保存成功/);

  await expect
    .poll(() => {
      const localFileResult = runDockerPhpInline(
        [
          '$path = "/var/www/nav/data/host-agent-sim-root' + filePath + '";',
          'echo file_exists($path) ? file_get_contents($path) : "";',
        ].join(' ')
      );
      expect(localFileResult.code).toBe(0);
      return localFileResult.stdout;
    })
    .toContain(fileContent);

  await page.locator('#file-upload-input').setInputFiles({
    name: binaryFileName,
    mimeType: 'application/octet-stream',
    buffer: binaryContent,
  });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        [
          '$path = "/var/www/nav/data/host-agent-sim-root' + binaryFilePath + '";',
          'echo file_exists($path) ? base64_encode((string)file_get_contents($path)) : "";',
        ].join(' ')
      );
      return result.stdout.trim();
    })
    .toBe(binaryContent.toString('base64'));

  await page.locator('#file-edit-path').fill(binaryFilePath);
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: /下载当前文件/ }).click({ force: true });
  const download = await downloadPromise;
  const downloadPath = await download.path();
  expect(downloadPath).not.toBeNull();
  const downloadBytes = await fs.readFile(downloadPath!);
  expect(downloadBytes.equals(binaryContent)).toBe(true);

  await page.locator('#authorized-user').fill('root');
  await page.locator('#authorized-editor').fill(authorizedContent);
  await page.getByRole('button', { name: /保存 authorized_keys/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/文件已保存|保存成功/);

  await expect(page.locator('#authorized-key-select')).toContainText(importKeyName);
  await page.locator('#authorized-key-select').selectOption({ label: importKeyName });
  await page.getByRole('button', { name: /导入已保存公钥/ }).click({ force: true });
  await expect(page.locator('#authorized-table tbody')).toContainText('ssh-ed25519');

  const authorizedResult = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root/root/.ssh/authorized_keys";',
      'echo file_exists($path) ? file_get_contents($path) : "";',
    ].join(' ')
  );
  expect(authorizedResult.code).toBe(0);
  expect(authorizedResult.stdout).toContain(authorizedContent);
  expect(authorizedResult.stdout).toContain('ssh-ed25519');

  await page.getByRole('button', { name: /打开终端/ }).click({ force: true });
  await page.waitForTimeout(800);
  await page.locator('#terminal-input').fill(`echo terminal-${ts}`);
  await page.getByRole('button', { name: '发送' }).click({ force: true });
  await expect(page.locator('#terminal-output')).toContainText(`terminal-${ts}`, { timeout: 15000 });
  await page.getByRole('button', { name: /关闭终端/ }).click({ force: true });

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(hostAdmin);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('host_admin');
  await page.getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(hostAdmin);

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(hostViewer);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('host_viewer');
  await page.getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(hostViewer);

  await logout(page);

  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(hostAdmin);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);
  await page.goto('/admin/hosts.php');
  await expect(page.locator('body')).toContainText('主机管理');
  await expect(page.locator('body')).toContainText('文件管理');
  await page.goto('/admin/users.php');
  await expect(page).not.toHaveURL(/\/admin\/users\.php/);
  await page.goto('/admin/hosts.php');

  await logout(page);

  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(hostViewer);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);
  await page.goto('/admin/hosts.php');
  await expect(page.locator('body')).toContainText('主机管理');
  await expect(page.getByRole('button', { name: /保存 SSH 配置/ })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /打开终端/ })).toHaveCount(0);

  await tracker.assertNoClientErrors();
});

test('web terminal sessions can keep running in background and be restored', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();

  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php');

  await page.locator('#terminal-persist').check();
  await page.locator('#terminal-idle-minutes').fill('30');
  await page.getByRole('button', { name: '打开终端' }).click({ force: true });
  await expect(page.locator('#terminal-tabs')).toContainText('本机');

  await page.locator('#terminal-input').fill('printf "persist-ok\\n"');
  await page.getByRole('button', { name: '发送' }).click({ force: true });
  await expect(page.locator('#terminal-output')).toContainText('persist-ok');

  await page.getByRole('button', { name: '脱离终端' }).click({ force: true });
  await expect(page.locator('#terminal-output')).toHaveText('');

  const listBeforeReload = await page.evaluate(async () => {
    const res = await fetch('/admin/host_api.php?action=terminal_list', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  });
  expect(listBeforeReload.ok).toBe(true);
  expect(Array.isArray(listBeforeReload.sessions)).toBe(true);
  expect(listBeforeReload.sessions.some((item: any) => item.title === '本机')).toBe(true);

  const stateFileResult = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root/var/lib/host-agent/terminal_sessions.json";',
      'echo file_exists($path) ? file_get_contents($path) : "";',
    ].join(' ')
  );
  expect(stateFileResult.code).toBe(0);
  expect(stateFileResult.stdout).toContain('本机');

  await page.goto('/admin/hosts.php');
  await page.evaluate(() => {
    const fn = (window as Window & { refreshTerminalSessions?: (showNotice?: boolean) => Promise<void> }).refreshTerminalSessions;
    if (typeof fn !== 'function') throw new Error('refreshTerminalSessions not found');
    return fn(true);
  });
  await expect(page.locator('#terminal-tabs')).toContainText('本机');
  const restoredSessionId = await page.evaluate(() => {
    const sessions = (window as Window & { TERMINAL_SESSIONS?: Record<string, unknown> }).TERMINAL_SESSIONS || {};
    return Object.keys(sessions)[0] || '';
  });
  expect(restoredSessionId).not.toBe('');
  const hostCsrf = await page.evaluate(() => (window as Window & { HOST_CSRF?: string }).HOST_CSRF || '');
  expect(hostCsrf).not.toBe('');
  const writeResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'terminal_write',
      _csrf: hostCsrf,
      id: restoredSessionId,
      data: 'printf "restored-ok\\n"\n',
    },
  });
  expect(writeResponse.status()).toBe(200);
  expect((await writeResponse.json()).ok).toBe(true);
  await page.evaluate(async (sessionId) => {
    const fn = (window as Window & { pollTerminal?: (id: string) => Promise<void>; TERMINAL_SESSION_ID?: string }).pollTerminal;
    if (typeof fn !== 'function' || !sessionId) return;
    (window as Window & { TERMINAL_SESSION_ID?: string }).TERMINAL_SESSION_ID = sessionId;
    try {
      await fn(sessionId);
    } catch {
      // ignore terminal read timing races in browser automation
    }
  }, restoredSessionId);

  await page.getByRole('button', { name: '关闭终端' }).click({ force: true });
  await logout(page);
});

test('host management advanced tools cover structured ssh fields file ops known_hosts filters batches and terminal tabs', async ({ page }) => {
  test.setTimeout(180000);
  await ensureInstalledHostAgent();

  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  const ts = Date.now();
  const groupName = `group-${ts}`;
  const hostNameA = `batch-a-${ts}`;
  const hostNameB = `batch-b-${ts}`;
  const filePath = `/advanced-${ts}.txt`;
  const archivePath = `/advanced-${ts}.tar.gz`;
  const extractDir = `/advanced-extract-${ts}`;
  const knownHostLine = `example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIknown${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php');

  await page.locator('input[name="ssh_port"]').fill('2201');
  await page.locator('input[name="listen_address"]').fill('0.0.0.0');
  await page.locator('select[name="permit_root_login"]').selectOption('no');
  await page.locator('select[name="password_auth"]').selectOption('0');
  await page.locator('select[name="pubkey_auth"]').selectOption('1');
  await page.locator('input[name="allow_users"]').fill('root deploy');
  await page.locator('input[name="allow_groups"]').fill('wheel sudo');
  await page.locator('select[name="x11_forwarding"]').selectOption('1');
  await page.locator('input[name="max_auth_tries"]').fill('4');
  await page.locator('input[name="client_alive_interval"]').fill('30');
  await page.locator('input[name="client_alive_count_max"]').fill('5');
  await page
    .locator('form')
    .filter({ has: page.locator('input[name="action"][value="save_ssh_structured"]') })
    .getByRole('button', { name: /^保存结构化配置$/ })
    .click({ force: true });
  await expect(page.locator('body')).toContainText('结构化 SSH 配置已保存');

  const structuredConfig = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root/etc/ssh/sshd_config";',
      'echo file_exists($path) ? file_get_contents($path) : "";',
    ].join(' ')
  );
  expect(structuredConfig.code).toBe(0);
  expect(structuredConfig.stdout).toContain('Port 2201');
  expect(structuredConfig.stdout).toContain('ListenAddress 0.0.0.0');
  expect(structuredConfig.stdout).toContain('AllowUsers root deploy');
  expect(structuredConfig.stdout).toContain('AllowGroups wheel sudo');
  expect(structuredConfig.stdout).toContain('X11Forwarding yes');
  expect(structuredConfig.stdout).toContain('MaxAuthTries 4');
  expect(structuredConfig.stdout).toContain('ClientAliveInterval 30');
  expect(structuredConfig.stdout).toContain('ClientAliveCountMax 5');

  await page.locator('#file-host-select').selectOption('local');
  await page.locator('#file-edit-path').fill(filePath);
  await page.locator('#file-editor').fill(`advanced-${ts}`);
  const hostCsrf = await page.evaluate(() => (window as Window & { HOST_CSRF?: string }).HOST_CSRF || '');
  const saveFileResponse = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'file_write',
      _csrf: hostCsrf,
      host_id: 'local',
      path: filePath,
      content: `advanced-${ts}`,
    },
  });
  expect(saveFileResponse.status()).toBe(200);
  expect((await saveFileResponse.json()).ok).toBe(true);
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        [
          '$path = "/var/www/nav/data/host-agent-sim-root' + filePath + '";',
          'echo file_exists($path) ? file_get_contents($path) : "";',
        ].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout;
    })
    .toContain(`advanced-${ts}`);

  page.once('dialog', (dialog) => dialog.accept('600'));
  await page.getByRole('button', { name: /^chmod$/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/权限已更新|操作成功/);
  await expect(page.locator('#file-stat-meta')).toContainText('权限 0600');

  page.once('dialog', (dialog) => dialog.accept(archivePath));
  await page.locator('#files').getByRole('button', { name: /^压缩$/ }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        [
          '$path = "/var/www/nav/data/host-agent-sim-root' + archivePath + '";',
          'echo file_exists($path) ? "1" : "0";',
        ].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  await page.locator('#file-edit-path').fill(archivePath);
  page.once('dialog', (dialog) => dialog.accept(extractDir));
  await page.locator('#files').getByRole('button', { name: /^解压$/ }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        [
          '$path = "/var/www/nav/data/host-agent-sim-root' + extractDir + '";',
          'echo is_dir($path) ? "1" : "0";',
        ].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  await page.locator('#known-hosts-editor').fill(knownHostLine);
  await page.getByRole('button', { name: /保存 known_hosts/ }).click({ force: true });

  const knownHostsResult = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root/root/.ssh/known_hosts";',
      'echo file_exists($path) ? file_get_contents($path) : "";',
    ].join(' ')
  );
  expect(knownHostsResult.code).toBe(0);
  expect(knownHostsResult.stdout).toContain(knownHostLine);

  const seedHosts = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$r1 = ssh_manager_upsert_host(["name" => "' + hostNameA + '", "hostname" => "127.0.0.1", "port" => 1, "username" => "root", "auth_type" => "password", "key_id" => "", "password" => "x", "group_name" => "' + groupName + '", "tags" => "blue,linux", "favorite" => true, "notes" => ""], null);',
      '$r2 = ssh_manager_upsert_host(["name" => "' + hostNameB + '", "hostname" => "127.0.0.1", "port" => 2, "username" => "root", "auth_type" => "password", "key_id" => "", "password" => "x", "group_name" => "other", "tags" => "red", "favorite" => false, "notes" => ""], null);',
      'echo json_encode([$r1, $r2], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(seedHosts.code).toBe(0);
  expect(JSON.parse(seedHosts.stdout)[0].ok).toBe(true);
  expect(JSON.parse(seedHosts.stdout)[1].ok).toBe(true);
  await page.goto('/admin/hosts.php');

  const remoteHostIdA = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$hosts = ssh_manager_list_hosts();',
      'foreach ($hosts as $host) { if (($host["name"] ?? "") === "' + hostNameA + '") { echo $host["id"] ?? ""; break; } }',
    ].join(' ')
  );
  expect(remoteHostIdA.code).toBe(0);
  expect(remoteHostIdA.stdout.trim()).not.toBe('');

  await page.locator('#remote-host-search').fill(hostNameA);
  await expect(page.locator('.remote-host-row', { hasText: hostNameA })).toBeVisible();
  await expect(page.locator('.remote-host-row', { hasText: hostNameB })).toBeHidden();
  await page.locator('#remote-host-search').fill('');
  await page.locator('#remote-host-group-filter').selectOption(groupName);
  await expect(page.locator('.remote-host-row', { hasText: hostNameA })).toBeVisible();
  await expect(page.locator('.remote-host-row', { hasText: hostNameB })).toBeHidden();
  await page.locator('#remote-host-group-filter').selectOption('');
  await page.evaluate(() => {
    const input = document.getElementById('remote-host-favorite-only') as HTMLInputElement | null;
    if (!input) throw new Error('remote-host-favorite-only not found');
    input.checked = true;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await expect(page.locator('.remote-host-row', { hasText: hostNameA })).toBeVisible();
  await expect(page.locator('.remote-host-row', { hasText: hostNameB })).toBeHidden();
  await page.evaluate(() => {
    const input = document.getElementById('remote-host-favorite-only') as HTMLInputElement | null;
    if (!input) throw new Error('remote-host-favorite-only not found');
    input.checked = false;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  await page.evaluate((name) => {
    const rows = Array.from(document.querySelectorAll('.remote-host-row'));
    const row = rows.find((item) => (item.textContent || '').includes(name)) as HTMLElement | undefined;
    if (!row) throw new Error('remote host row not found');
    const checkbox = row.querySelector('.remote-host-check') as HTMLInputElement | null;
    if (!checkbox) throw new Error('remote host checkbox not found');
    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
  }, hostNameA);
  await page.getByRole('button', { name: /批量测试连接/ }).click({ force: true });
  await expect(page.locator('#batch-results')).toContainText(remoteHostIdA.stdout.trim());

  await page.getByRole('button', { name: /打开终端/ }).click({ force: true });
  await page.getByRole('button', { name: /打开终端/ }).click({ force: true });
  await expect(page.locator('#terminal-tabs button')).toHaveCount(2);
  await page.locator('#terminal-tabs button').first().click({ force: true });
  await page.locator('#terminal-input').fill(`echo multi-${ts}`);
  await page.locator('#terminal-input').press('Enter');
  await expect(page.locator('#terminal-output')).toContainText(`multi-${ts}`, { timeout: 15000 });

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: /导出日志/ }).click({ force: true });
  const download = await downloadPromise;
  const downloadPath = await download.path();
  expect(downloadPath).not.toBeNull();
  const auditExport = await fs.readFile(downloadPath!, 'utf8');
  expect(auditExport).toContain('file_chmod');
  expect(auditExport).toContain('batch_test_hosts');

  await page.getByRole('link', { name: '打开独立审计页' }).click({ force: true });
  await expect(page).toHaveURL(/ssh_audit\.php/);
  await expect(page.locator('body')).toContainText('SSH 审计');
  await expect(page.locator('body')).toContainText('batch_test_hosts');
  await expect(page.locator('body')).toContainText('file_chmod');

  await tracker.assertNoClientErrors();
});
