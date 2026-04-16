import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import {
  restoreLocalFiles,
  runDockerPhpInline,
  snapshotLocalFiles,
} from '../../helpers/cli';

const publicDir = path.resolve(__dirname, '../../../public');

test('subsite middleware redirects anonymous to login with redirect param', async ({ page }) => {
  const testFile = path.join(publicDir, 'test-middleware-redirect.php');
  await fs.writeFile(
    testFile,
    `<?php
$_SERVER['REQUEST_URI'] = '/protected/page.php?foo=bar';
$_COOKIE = [];
$_GET = [];
require_once '/var/www/nav/subsite-middleware/auth_check.php';
`,
    'utf8'
  );

  try {
    const response = await page.request.get('/test-middleware-redirect.php', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    const location = response.headers()['location'] || '';
    expect(location).toContain('login.php');
    expect(location).toContain(encodeURIComponent('/protected/page.php?foo=bar'));
  } finally {
    await fs.rm(testFile, { force: true });
  }
});

test('subsite middleware accepts valid cookie token and sets nav_user', async ({ page }) => {
  const tokenResult = runDockerPhpInline(`
    require_once '/var/www/nav/shared/auth.php';
    auth_ensure_secret_key();
    echo auth_generate_token('admin', 'admin');
  `);
  expect(tokenResult.code).toBe(0);
  const token = tokenResult.stdout.trim();

  const testFile = path.join(publicDir, 'test-middleware-valid.php');
  await runDockerPhpInline(`
    file_put_contents('/var/www/nav/public/test-middleware-valid.php', '<?php
\$_SERVER["REQUEST_URI"] = "/protected/page.php";
\$_COOKIE["nav_session"] = "' . addcslashes('${token}', '"\\\\') . '";
\$_GET = [];
require_once "/var/www/nav/subsite-middleware/auth_check.php";
if (!isset(\$GLOBALS["nav_user"]) || (\$GLOBALS["nav_user"]["username"] ?? "") !== "admin") {
    http_response_code(500);
    echo "FAIL";
    exit;
}
echo "OK";
');
  `);

  try {
    const response = await page.request.get('/test-middleware-valid.php');
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('OK');
  } finally {
    await fs.rm(testFile, { force: true });
  }
});

test('subsite middleware sets cookie from _nav_token and cleans url', async ({ page }) => {
  const tokenResult = runDockerPhpInline(`
    require_once '/var/www/nav/shared/auth.php';
    auth_ensure_secret_key();
    echo auth_generate_token('admin', 'admin');
  `);
  expect(tokenResult.code).toBe(0);
  const token = tokenResult.stdout.trim();

  const testFile = path.join(publicDir, 'test-middleware-token.php');
  await runDockerPhpInline(`
    file_put_contents('/var/www/nav/public/test-middleware-token.php', '<?php
\$_SERVER["REQUEST_URI"] = "/protected/page.php?_nav_token=' . urlencode('${token}') . '&foo=bar";
\$_COOKIE = [];
\$_GET["_nav_token"] = "' . addcslashes('${token}', '"\\\\') . '";
\$_GET["foo"] = "bar";
require_once "/var/www/nav/subsite-middleware/auth_check.php";
');
  `);

  try {
    // When _nav_token is valid, auth_get_current_user() returns the user,
    // so the middleware proceeds to set the cookie and redirect to the clean URL.
    const response = await page.request.get('/test-middleware-token.php', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    const location = response.headers()['location'] || '';
    expect(location).toBe('/protected/page.php?foo=bar');
    const setCookie = response.headers()['set-cookie'] || '';
    expect(setCookie).toContain('nav_session=');
  } finally {
    await fs.rm(testFile, { force: true });
  }
});

test('subsite middleware rejects invalid cookie token', async ({ page }) => {
  const testFile = path.join(publicDir, 'test-middleware-invalid.php');
  await fs.writeFile(
    testFile,
    `<?php
$_SERVER['REQUEST_URI'] = '/protected/page.php';
$_COOKIE['nav_session'] = 'bad.token.value';
$_GET = [];
require_once '/var/www/nav/subsite-middleware/auth_check.php';
`,
    'utf8'
  );

  try {
    const response = await page.request.get('/test-middleware-invalid.php', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    const location = response.headers()['location'] || '';
    expect(location).toContain('login.php');
  } finally {
    await fs.rm(testFile, { force: true });
  }
});
