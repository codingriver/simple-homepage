import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const notificationsFile = path.resolve(__dirname, '../../../data/notifications.json');
const notifyLogFile = path.resolve(__dirname, '../../../data/logs/notifications.log');
const notifyProbeLogFile = path.resolve(__dirname, '../../../data/logs/notify_probe.log');

test('notifications center can save a channel and receive task failure events', async ({ page }) => {
  test.setTimeout(180000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const channelName = `任务失败通知 ${ts}`;
  const taskName = `notify_fail_${ts}`;

  await fs.rm(notifyLogFile, { force: true });
  await fs.rm(notifyProbeLogFile, { force: true });

  await loginAsDevAdmin(page);
  await page.goto('/admin/notifications.php');
  await expect(page.locator('body')).toContainText('通知中心');

  await page.locator('input[name="name"]').fill(channelName);
  await page.locator('select[name="type"]').selectOption('custom');
  await page.locator('input[name="cooldown_seconds"]').fill('0');
  await page.locator('input[name="webhook_url"]').fill('http://127.0.0.1:58080/notify_probe.php');
  await page.locator('input[name="events[]"][value="task_failed"]').check({ force: true });
  await page.locator('button[type="submit"]', { hasText: '保存通知渠道' }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存|保存成功/);
  await expect(page.locator(`tr:has-text("${channelName}")`).first()).toBeVisible();

  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(taskName);
  await page.locator('#fm-schedule').fill('*/17 * * * *');
  await page.locator('#fm-command').fill('echo notify-start\nexit 2');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator(`tr[data-task-row]:has-text("${taskName}")`).first();
  await row.getByRole('button', { name: /立即执行/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已开始后台执行|后台执行已在运行中/);

  await expect
    .poll(async () => {
      const raw = JSON.parse(await fs.readFile(notificationsFile, 'utf8')) as {
        channels?: Array<{ name?: string; runtime?: { last_status?: string; last_sent_at?: string } }>;
      };
      const channel = raw.channels?.find((item) => item.name === channelName);
      return channel?.runtime?.last_status || '';
    }, { timeout: 30000 })
    .toBe('success');

  await expect
    .poll(async () => {
      try {
        return await fs.readFile(notifyLogFile, 'utf8');
      } catch {
        return '';
      }
    }, { timeout: 30000 })
    .toContain('task_failed');
  await expect
    .poll(async () => {
      try {
        return await fs.readFile(notifyProbeLogFile, 'utf8');
      } catch {
        return '';
      }
    }, { timeout: 30000 })
    .toContain(taskName);

  await tracker.assertNoClientErrors();
});
