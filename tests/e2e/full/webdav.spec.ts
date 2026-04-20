import { expect, test } from '../../helpers/fixtures';
import { loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

test('webdav can be configured and serves basic dav operations', async ({ page, request, isMobile }) => {
  test.skip(!!isMobile, 'WebDAV admin form is too long for mobile viewport; desktop coverage is sufficient.');
  test.setTimeout(120000);
  const ts = Date.now();
  const userMain = `webdavtest_${ts}`;
  const userReadonly = `webdavro_${ts}`;
  const userClone = `${userReadonly}_copy`;
  const webdavRoot = `/var/www/nav/data/webdav-e2e-${ts}`;
  const webdavReadonlyRoot = `/var/www/nav/data/webdav-e2e-readonly-${ts}`;

  const nginxReload = runDockerCommand([
    'exec',
    'simple-homepage',
    'sh',
    '-lc',
    'export NAV_PORT=${NAV_PORT:-58080}; envsubst \'${NAV_PORT}\' < /var/www/nav/docker/nginx-site.conf > /etc/nginx/http.d/nav.conf && nginx -s reload',
  ]);
  expect(nginxReload.code).toBe(0);

  runDockerPhpInline(
    [
      '$cfgPath = "/var/www/nav/data/config.json";',
      '$cfg = file_exists($cfgPath) ? (json_decode((string)file_get_contents($cfgPath), true) ?: []) : [];',
      '$cfg["webdav_enabled"] = "0";',
      '$cfg["webdav_username"] = "";',
      '$cfg["webdav_password_hash"] = "";',
      '$cfg["webdav_root"] = "/var/www/nav/data";',
      '$cfg["webdav_readonly"] = "0";',
      'file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));',
      '$accounts = "/var/www/nav/data/webdav_accounts.json";',
      'if (file_exists($accounts)) unlink($accounts);',
      'foreach (["' + webdavRoot + '","' + webdavReadonlyRoot + '"] as $dir) {',
      '  if (!is_dir($dir)) mkdir($dir, 0777, true);',
      '  foreach (glob($dir . "/*") ?: [] as $item) {',
      '    if (is_file($item)) unlink($item);',
      '    if (is_dir($item)) {',
      '      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($item, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);',
      '      foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }',
      '      rmdir($item);',
      '    }',
      '  }',
      '}',
    ].join(' ')
  );

  await loginAsDevAdmin(page);
  await page.goto('/admin/webdav.php');
  await page.locator('select[name="enabled"]').selectOption('1');
  await page.getByRole('button', { name: '保存服务状态' }).click({ force: true });
  await expect(page.locator('body')).toContainText('WebDAV 已启用');

  await page.locator('input[name="username"]').fill(userMain);
  await page.locator('input[name="password"]').fill('Webdav@test2026');
  await page.locator('input[name="root"]').fill(webdavRoot);
  await page.locator('input[name="max_upload_mb"]').fill('1');
  await page.locator('input[name="quota_mb"]').fill('1');
  await page.locator('textarea[name="ip_whitelist"]').fill('');
  await page.locator('input[name="readonly"]').uncheck({ force: true }).catch(() => undefined);
  await page.getByRole('button', { name: '保存 WebDAV 账号' }).click({ force: true });
  await expect(page.locator('body')).toContainText('WebDAV 账号已保存');
  await expect(page.locator('body')).toContainText('/webdav/');
  await expect(page.locator('body')).toContainText('访问统计');
  await expect(page.locator('body')).toContainText('总账号数');

  await page.locator('input[name="username"]').fill(userReadonly);
  await page.locator('input[name="password"]').fill('WebdavReadonly@test2026');
  await page.locator('input[name="root"]').fill(webdavReadonlyRoot);
  await page.locator('input[name="max_upload_mb"]').fill('0');
  await page.locator('input[name="quota_mb"]').fill('0');
  await page.locator('textarea[name="ip_whitelist"]').fill('');
  await page.locator('input[name="notes"]').fill('readonly-account');
  await page.locator('input[name="readonly"]').check({ force: true });
  await page.getByRole('button', { name: '保存 WebDAV 账号' }).click({ force: true });
  await expect(page.locator('body')).toContainText('WebDAV 账号已保存');
  await expect(page.locator('body')).toContainText(userReadonly);

  const auth = 'Basic ' + Buffer.from(`${userMain}:Webdav@test2026`).toString('base64');
  const readonlyAuth = 'Basic ' + Buffer.from(`${userReadonly}:WebdavReadonly@test2026`).toString('base64');

  let res = await request.fetch('/webdav/', {
    method: 'OPTIONS',
    headers: { Authorization: auth },
  });
  expect(res.status()).toBe(204);
  expect(res.headers()['dav']).toContain('1');

  res = await request.fetch('/webdav/docs/', {
    method: 'MKCOL',
    headers: { Authorization: auth },
  });
  expect(res.status()).toBe(201);

  res = await request.fetch('/webdav/docs/hello.txt', {
    method: 'PUT',
    headers: {
      Authorization: auth,
      'Content-Type': 'text/plain',
    },
    data: 'hello-webdav',
  });
  expect(res.status()).toBe(201);

  res = await request.fetch('/webdav/docs/', {
    method: 'PROPFIND',
    headers: {
      Authorization: auth,
      Depth: '1',
    },
  });
  expect(res.status()).toBe(207);
  const propfindText = await res.text();
  expect(propfindText).toContain('/webdav/docs/');
  expect(propfindText).toContain('hello.txt');

  res = await request.fetch('/webdav/docs/hello.txt', {
    method: 'GET',
    headers: { Authorization: auth },
  });
  expect(res.status()).toBe(200);
  expect(await res.text()).toBe('hello-webdav');

  res = await request.fetch('/webdav/docs/hello.txt', {
    method: 'COPY',
    headers: {
      Authorization: auth,
      Destination: (new URL('/webdav/docs/hello-copy.txt', page.url())).toString(),
    },
  });
  expect(res.status()).toBe(201);

  res = await request.fetch('/webdav/docs/hello-copy.txt', {
    method: 'MOVE',
    headers: {
      Authorization: auth,
      Destination: (new URL('/webdav/docs/hello-moved.txt', page.url())).toString(),
    },
  });
  expect(res.status()).toBe(201);

  res = await request.fetch('/webdav/docs/hello-moved.txt', {
    method: 'DELETE',
    headers: { Authorization: auth },
  });
  expect(res.status()).toBe(204);

  res = await request.fetch('/webdav/', {
    method: 'PROPFIND',
    headers: {
      Authorization: readonlyAuth,
      Depth: '1',
    },
  });
  expect(res.status()).toBe(207);
  const readonlyList = await res.text();
  expect(readonlyList).not.toContain('hello.txt');

  res = await request.fetch('/webdav/readonly.txt', {
    method: 'PUT',
    headers: {
      Authorization: readonlyAuth,
      'Content-Type': 'text/plain',
    },
    data: 'should-fail',
  });
  expect(res.status()).toBe(403);

  const largePayload = 'a'.repeat(1024 * 1024 + 16);
  res = await request.fetch('/webdav/too-large.bin', {
    method: 'PUT',
    headers: {
      Authorization: auth,
      'Content-Type': 'application/octet-stream',
    },
    data: largePayload,
  });
  expect(res.status()).toBe(413);

  res = await request.fetch('/webdav/fill.bin', {
    method: 'PUT',
    headers: {
      Authorization: auth,
      'Content-Type': 'application/octet-stream',
    },
    data: 'b'.repeat(850 * 1024),
  });
  expect(res.status()).toBe(201);

  res = await request.fetch('/webdav/fill-2.bin', {
    method: 'PUT',
    headers: {
      Authorization: auth,
      'Content-Type': 'application/octet-stream',
    },
    data: 'c'.repeat(300 * 1024),
  });
  expect(res.status()).toBe(507);

  const fileCheck = runDockerPhpInline(
    [
      '$base = "' + webdavRoot + '"; $ro = "' + webdavReadonlyRoot + '";',
      'echo json_encode([',
      '  "hello" => file_exists($base . "/docs/hello.txt"),',
      '  "copy" => file_exists($base . "/docs/hello-copy.txt"),',
      '  "moved" => file_exists($base . "/docs/hello-moved.txt"),',
      '  "fill" => file_exists($base . "/fill.bin"),',
      '  "fill2" => file_exists($base . "/fill-2.bin"),',
      '  "tooLarge" => file_exists($base . "/too-large.bin"),',
      '  "readonlyWrite" => file_exists($ro . "/readonly.txt"),',
      '], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(fileCheck.code).toBe(0);
  expect(JSON.parse(fileCheck.stdout)).toEqual({
    hello: true,
    copy: false,
    moved: false,
    fill: true,
    fill2: false,
    tooLarge: false,
    readonlyWrite: false,
  });

  const exportPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: '导出 JSON' }).click();
  const exportDownload = await exportPromise;
  expect(await exportDownload.path()).not.toBeNull();

  await page.locator('input[name="log_user"]').fill(userMain);
  await page.getByRole('button', { name: '筛选' }).click();
  await expect(page.locator('body')).toContainText(userMain);
  // Docker Desktop for Mac 下 osxfs 同步可能有延迟，审计日志不会立即出现在页面上
  await expect.poll(() => page.locator('body').textContent().then(t => t?.includes('put') ?? false), { timeout: 10000 }).toBe(true);

  await page.getByRole('link', { name: '打开独立审计页' }).click();
  await expect(page).toHaveURL(/webdav_audit\.php/);
  await expect(page.locator('body')).toContainText('WebDAV 审计');
  await expect(page.locator('body')).toContainText(userMain);
  await expect(page.locator('body')).toContainText('put');
  await page.getByRole('link', { name: '返回 WebDAV' }).click();
  await expect(page).toHaveURL(/webdav\.php/);

  await page.getByRole('link', { name: '共享总览' }).click();
  await expect(page).toHaveURL(/webdav_shares\.php/);
  await expect(page.locator('body')).toContainText('WebDAV 共享总览');
  await expect(page.locator('body')).toContainText(userMain);
  await expect(page.locator('body')).toContainText(webdavRoot);
  await page.getByRole('link', { name: '返回 WebDAV' }).click();
  await expect(page).toHaveURL(/webdav\.php/);

  await page.locator('tr', { hasText: userReadonly }).getByRole('button', { name: '克隆' }).click();
  await expect(page.locator('body')).toContainText(`WebDAV 账号已克隆为 ${userClone}`);
  await expect(page.locator('body')).toContainText(userClone);

  page.once('dialog', (dialog) => dialog.accept('WebdavReset@test2026'));
  await page.locator('tr', { hasText: userClone }).getByRole('button', { name: '重置密码' }).click();
  await expect(page.locator('body')).toContainText('WebDAV 账号已保存');

  await page.locator('tr', { hasText: userMain }).getByRole('link', { name: '编辑' }).click();
  await expect(page.locator('body')).toContainText('当前账号目录');
  await expect(page.locator('body')).toContainText('该目录相关共享账号');
  await expect(page.getByRole('link', { name: '打开文件系统目录' })).toBeVisible();
  await page.locator('textarea[name="ip_whitelist"]').fill('127.0.0.1');
  await page.getByRole('button', { name: '保存 WebDAV 账号' }).click();
  await expect(page.locator('body')).toContainText('WebDAV 账号已保存');

  await page.locator('tr', { hasText: userMain }).getByRole('link', { name: '打开目录' }).click();
  await expect(page).toHaveURL(/files\.php\?host_id=local&path=/);
  await expect(page.locator('#fm-path')).toHaveValue(webdavRoot);
  await page.goto('/admin/webdav.php');

  res = await request.fetch('/webdav/', {
    method: 'PROPFIND',
    headers: {
      Authorization: 'Basic ' + Buffer.from(`${userClone}:WebdavReset@test2026`).toString('base64'),
      Depth: '0',
    },
  });
  expect(res.status()).toBe(207);

  res = await request.fetch('/webdav/', {
    method: 'OPTIONS',
    headers: {
      Authorization: auth,
    },
  });
  expect(res.status()).toBe(403);

  const auditCheck = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/logs/webdav.log";',
      'echo file_exists($path) ? file_get_contents($path) : "";',
    ].join(' ')
  );
  expect(auditCheck.code).toBe(0);
  expect(auditCheck.stdout).toContain('service_toggle');
  expect(auditCheck.stdout).toContain('account_upsert');
  expect(auditCheck.stdout).toContain('propfind');
  expect(auditCheck.stdout).toContain('put');
  expect(auditCheck.stdout).toContain('put_rejected_max_upload');
  expect(auditCheck.stdout).toContain('put_rejected_quota');
});
