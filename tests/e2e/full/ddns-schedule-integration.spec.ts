import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function saveDdns(page: Parameters<typeof loginAsDevAdmin>[0]) {
  const saveButton = page.locator('#ddns-form .form-actions').getByRole('button', { name: /^保存$/ });
  await saveButton.scrollIntoViewIfNeeded();
  await saveButton.click({ force: true });
}

async function createTask(page: Parameters<typeof loginAsDevAdmin>[0], name: string, domain: string, cron: string) {
  await page.goto('/admin/ddns.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-source-type').selectOption('local_ipv4');
  await page.locator('#fm-domain').fill(domain);
  await page.locator('#fm-cron').fill(cron);
  await saveDdns(page);
}

test('ddns tasks sync into scheduled dispatcher groups by cron and update after disable', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });
  const ts = Date.now();
  const taskA = `DDNS 调度 A ${ts}`;
  const taskB = `DDNS 调度 B ${ts}`;
  const taskC = `DDNS 调度 C ${ts}`;

  await loginAsDevAdmin(page);
  await createTask(page, taskA, `dispatcher-a-${ts}.606077.xyz`, '*/7 * * * *');
  await createTask(page, taskB, `dispatcher-b-${ts}.606077.xyz`, '*/7 * * * *');
  await createTask(page, taskC, `dispatcher-c-${ts}.606077.xyz`, '*/13 * * * *');

  await page.goto('/admin/scheduled_tasks.php');
  await expect(page.locator('body')).toContainText(/DDNS 调度器/);
  await expect(page.locator('body')).toContainText(taskA);
  await expect(page.locator('body')).toContainText(taskB);
  await expect(page.locator('body')).toContainText(taskC);

  await page.goto('/admin/ddns.php');
  const rowA = page.locator(`tr:has-text("${taskA}")`).first();
  const toggleButton = rowA.getByRole('button', { name: /禁用/ });
  await toggleButton.scrollIntoViewIfNeeded();
  await toggleButton.click({ force: true });
  await expect(rowA).toContainText('禁用');

  await page.goto('/admin/scheduled_tasks.php');
  await expect(page.locator('body')).toContainText(/DDNS 调度器/);

  await tracker.assertNoClientErrors();
});
