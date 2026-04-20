import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { writeContainerFile, readContainerFile } from '../../helpers/cli';

const logsDir = path.resolve(__dirname, '../../../data/logs');
const containerLogsDir = '/var/www/nav/data/logs';

test('logs api supports clear_all and download', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();

  // seed log files (容器内写入避免 Docker Desktop bind-mount 同步问题)
  writeContainerFile(`${containerLogsDir}/dns.log`, `dns log entry ${ts}\n`);
  writeContainerFile(`${containerLogsDir}/ssh_manager_audit.log`, `ssh audit entry ${ts}\n`);
  writeContainerFile(`${containerLogsDir}/audit.log`, `audit entry ${ts}\n`);
  await fs.mkdir(logsDir, { recursive: true }).catch(() => undefined);
  await fs.writeFile(path.join(logsDir, 'dns.log'), `dns log entry ${ts}\n`, 'utf8').catch(() => undefined);
  await fs.writeFile(path.join(logsDir, 'ssh_manager_audit.log'), `ssh audit entry ${ts}\n`, 'utf8').catch(() => undefined);
  await fs.writeFile(path.join(logsDir, 'audit.log'), `audit entry ${ts}\n`, 'utf8').catch(() => undefined);

  await loginAsDevAdmin(page);
  await page.goto('/admin/logs.php', { waitUntil: 'domcontentloaded' });
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // clear_all
  const clearAllRes = await page.request.post('http://127.0.0.1:58080/admin/logs_api.php?action=clear_all', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { _csrf: csrf },
  });
  expect(clearAllRes.status()).toBe(200);
  // 如果 PHP display_errors 开启，可能返回 HTML；这里先尝试 text 再解析 JSON
  const clearAllText = await clearAllRes.text();
  let clearAllBody: any;
  try {
    clearAllBody = JSON.parse(clearAllText);
  } catch {
    // 如果返回的是 HTML，尝试提取 JSON 部分（通常是 HTML 前缀后面跟着 JSON）
    const jsonMatch = clearAllText.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      clearAllBody = JSON.parse(jsonMatch[0]);
    } else {
      throw new Error(`logs_api.php clear_all 返回非 JSON: ${clearAllText.slice(0, 200)}`);
    }
  }
  expect(clearAllBody.ok).toBe(true);

  // verify clearable logs are empty (通过容器内读取，避免宿主机同步延迟)
  const clearableKeys = ['request_timing', 'dns', 'dns_python', 'notifications', 'auth', 'audit'];
  for (const key of clearableKeys) {
    const content = readContainerFile(`${containerLogsDir}/${key}.log`);
    expect(content.trim()).toBe('');
  }

  // re-seed for download test
  writeContainerFile(`${containerLogsDir}/dns.log`, `download test ${ts}\n`);
  await fs.writeFile(path.join(logsDir, 'dns.log'), `download test ${ts}\n`, 'utf8').catch(() => undefined);

  // download
  const downloadRes = await page.request.get(
    `http://127.0.0.1:58080/admin/logs_api.php?action=download&type=dns`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(downloadRes.status()).toBe(200);
  const disp = downloadRes.headers()['content-disposition'] || '';
  expect(disp).toContain('attachment');
  const downloaded = await downloadRes.text();
  expect(downloaded).toContain(`download test ${ts}`);

  await tracker.assertNoClientErrors();
});
