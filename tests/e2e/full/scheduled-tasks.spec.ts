import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const taskScriptsRoot = path.resolve(__dirname, '../../../data/tasks');

async function disableNativeTaskFormValidation(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.evaluate(() => {
    const form = document.getElementById('task-form') as HTMLFormElement | null;
    if (form) form.noValidate = true;
  });
}

async function saveTaskModal(page: Parameters<typeof loginAsDevAdmin>[0]) {
  const navigationPromise = page.waitForURL(/\/admin\/scheduled_tasks\.php/, { timeout: 15000 }).catch(() => null);
  await page.locator('#task-modal button[form="task-form"]').click({ force: true });
  await navigationPromise;
}

async function aceEditorValue(page: Parameters<typeof loginAsDevAdmin>[0]) {
  return page.evaluate(() => {
    const editor = (window as Window & { RiverOpsAceEditor?: { getValue(): string } }).RiverOpsAceEditor;
    return editor?.getValue() || '';
  });
}

async function openTaskModalByName(page: Parameters<typeof loginAsDevAdmin>[0], name: string) {
  await page.evaluate((taskName) => {
    const rows = (window as Window & { TASK_ROWS?: Array<Record<string, any>> }).TASK_ROWS || [];
    const task = rows.find((item) => item && item.name === taskName);
    const fn = (window as Window & { openTaskModal?: (task: Record<string, any>) => void }).openTaskModal;
    if (!task || typeof fn !== 'function') throw new Error('task modal helpers not found');
    fn(task);
  }, name);
  await expect(page.locator('#task-modal')).toBeVisible();
}

async function waitForTaskLogLines(page: Parameters<typeof loginAsDevAdmin>[0], taskId: string, timeoutMs = 90000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const res = await page.request.get(`/admin/api/task_log.php?id=${encodeURIComponent(taskId)}&page=1`);
    if (res.ok()) {
      const json = await res.json();
      if (Array.isArray(json.lines) && json.lines.length > 0) {
        return json;
      }
    }
    await page.waitForTimeout(500);
  }
  throw new Error('task log did not receive lines in time');
}

test('scheduled tasks support create edit toggle run log clear and delete', async ({ page }) => {
  test.setTimeout(150000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const name = `任务 ${ts}`;
  const editedName = `任务已编辑 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();

  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-schedule').fill('*/5 * * * *');
  await expect(page.locator('#fm-workdir-preview')).toHaveCount(0);
  await expect(page.locator('#fm-script-filename')).toHaveCount(0);
  await expect(page.locator('#fm-script-path')).toHaveCount(0);
  await expect(page.locator('#fm-log-filename')).toHaveCount(0);
  await expect(page.locator('#fm-log-path')).toHaveCount(0);
  await page.locator('#fm-command').fill('echo from-task');
  await saveTaskModal(page);
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|crontab/);
  const row = page.locator(`tr:has-text("${name}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('启用');

  await openTaskModalByName(page, name);
  await page.locator('#fm-name').fill(editedName);
  await page.locator('#fm-command').fill('echo edited-task');
  await saveTaskModal(page);
  await expect(page.locator(`tr:has-text("${editedName}")`).first()).toBeVisible();

  const editedRow = page.locator(`tr:has-text("${editedName}")`).first();
  const taskId = await editedRow.locator('form input[name="id"]').first().inputValue();
  expect(taskId).not.toBe('');
  const resolvedTaskScriptPath = path.join(taskScriptsRoot, `task_${taskId}`, 'run.sh');
  const resolvedTaskLogPath = path.join(taskScriptsRoot, `task_${taskId}`, 'run.log');
  await expect
    .poll(async () => {
      try {
        return await fs.readFile(resolvedTaskScriptPath, 'utf8');
      } catch {
        return '';
      }
    }, { timeout: 10000 })
    .toContain('echo edited-task');

  await editedRow.getByRole('button', { name: /禁用/ }).click({ force: true });
  await expect(page.locator('body')).toContainText('已禁用');
  await editedRow.getByRole('button', { name: /启用/ }).click({ force: true });
  await expect(page.locator('body')).toContainText('已启用');

  await editedRow.getByRole('button', { name: /立即执行/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已开始后台执行|后台执行已在运行中/);
  await page.evaluate(
    ({ id, name }) => {
      const fn = (window as Window & { openLogModal?: (taskId: string, taskName: string) => void }).openLogModal;
      if (typeof fn !== 'function') throw new Error('openLogModal not found');
      fn(id, name);
    },
    { id: taskId, name: editedName }
  );
  await expect(page.locator('#riverops-ace-editor-modal')).toBeVisible();
  await waitForTaskLogLines(page, taskId);
  await expect
    .poll(async () => {
      try {
        return await fs.readFile(resolvedTaskLogPath, 'utf8');
      } catch {
        return '';
      }
    }, { timeout: 10000 })
    .toContain('edited-task');
  await page.waitForTimeout(2500);
  await expect.poll(() => aceEditorValue(page)).not.toContain('加载中…');
  await page.evaluate(() => {
    const fn = (window as Window & { clearCurrentLog?: () => void }).clearCurrentLog;
    if (typeof fn !== 'function') throw new Error('clearCurrentLog not found');
    fn();
  });
  await expect(page.locator('#riverops-confirm-modal')).toBeVisible();
  await page.locator('#riverops-confirm-ok').click({ force: true });
  await expect
    .poll(async () => {
      try {
        return await fs.readFile(resolvedTaskLogPath, 'utf8');
      } catch {
        return '';
      }
    })
    .toBe('');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const deleteResponse = await page.request.post('http://127.0.0.1:58080/admin/scheduled_tasks.php', {
    form: { _csrf: csrf, action: 'task_delete', id: taskId },
    maxRedirects: 0,
  });
  expect(deleteResponse.status()).toBe(302);
  await page.reload();
  await expect(page.locator(`tr:has-text("${editedName}")`)).toHaveCount(0);
  await expect(fs.access(resolvedTaskScriptPath).then(() => true).catch(() => false)).resolves.toBe(false);

  await tracker.assertNoClientErrors();
});

test('scheduled tasks default bash template starts with shebang and multiline script keeps line count after reopen', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const name = `多行脚本 ${ts}`;
  const script = [
    '#!/bin/bash',
    'echo start',
    '',
    'echo middle',
    'echo end',
  ].join('\n');

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await expect(page.locator('#fm-command')).toHaveValue(/^#!\/bin\/bash/);

  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-schedule').fill('*/7 * * * *');
  await page.locator('#fm-command').fill(script);
  await saveTaskModal(page);
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator(`tr:has-text("${name}")`).first();
  const taskId = await row.locator('form input[name="id"]').first().inputValue();
  const scriptPath = path.join(taskScriptsRoot, `task_${taskId}`, 'run.sh');
  await expect
    .poll(async () => {
      try {
        return await fs.readFile(scriptPath, 'utf8');
      } catch {
        return '';
      }
    }, { timeout: 10000 })
    .toBe(script + '\n');

  await page.evaluate((id) => {
    const rows = (window as Window & { TASK_ROWS?: Array<Record<string, any>> }).TASK_ROWS || [];
    const task = rows.find((item) => item && item.id === id);
    const fn = (window as Window & { openTaskModal?: (task: Record<string, any>) => void }).openTaskModal;
    if (!task || typeof fn !== 'function') throw new Error('task modal helpers not found');
    fn(task);
  }, taskId);
  await expect(page.locator('#fm-command')).toHaveValue(script + '\n');

  await saveTaskModal(page);
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  await expect
    .poll(async () => {
      try {
        return await fs.readFile(scriptPath, 'utf8');
      } catch {
        return '';
      }
    }, { timeout: 10000 })
    .toBe(script + '\n');

  await page.evaluate((id) => {
    const rows = (window as Window & { TASK_ROWS?: Array<Record<string, any>> }).TASK_ROWS || [];
    const task = rows.find((item) => item && item.id === id);
    const fn = (window as Window & { openTaskModal?: (task: Record<string, any>) => void }).openTaskModal;
    if (!task || typeof fn !== 'function') throw new Error('task modal helpers not found');
    fn(task);
  }, taskId);
  await expect(page.locator('#fm-command')).toHaveValue(script + '\n');

  await tracker.assertNoClientErrors();
});

test('scheduled tasks validate invalid fields and support crontab reload', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await disableNativeTaskFormValidation(page);

  await page.evaluate(() => {
    const input = document.getElementById('fm-id') as HTMLInputElement | null;
    if (input) input.value = 'bad id';
  });
  await page.locator('#fm-name').fill(`非法任务 ${ts}`);
  await page.locator('#fm-schedule').fill('*/5 * * * *');
  await page.locator('#fm-command').fill('echo invalid-id');
  await saveTaskModal(page);
  await expect(page.locator('body')).toContainText('任务 ID 仅允许字母数字、下划线、短横线');

  const emptyNameResponse = await page.request.post('http://127.0.0.1:58080/admin/scheduled_tasks.php', {
    form: {
      _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
      action: 'task_save',
      id: '',
      name: '',
      schedule: '*/5 * * * *',
      command: 'echo empty-name',
      enabled: '1',
    },
    timeout: 60000,
  });
  expect(emptyNameResponse.status()).toBe(200);
  expect(await emptyNameResponse.text()).toContain('请填写任务名称');

  const invalidCronResponse = await page.request.post('http://127.0.0.1:58080/admin/scheduled_tasks.php', {
    form: {
      _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
      action: 'task_save',
      id: '',
      name: `非法 cron ${ts}`,
      schedule: '* * *',
      command: 'echo invalid-cron',
      enabled: '1',
    },
    timeout: 60000,
  });
  expect(invalidCronResponse.status()).toBe(200);
  expect(await invalidCronResponse.text()).toContain('Cron 表达式无效');

  await page.getByRole('button', { name: /新建任务/ }).click();
  await expect(page.locator('#fm-workdir-preview')).toHaveCount(0);
  await expect(page.locator('#fm-script-filename')).toHaveCount(0);
  await expect(page.locator('#fm-script-path')).toHaveCount(0);
  await expect(page.locator('#fm-log-filename')).toHaveCount(0);
  await expect(page.locator('#fm-log-path')).toHaveCount(0);
  await expect(page.locator('#fm-working-dir-mode')).toHaveCount(0);
  await expect(page.locator('#fm-working-dir')).toHaveCount(0);
  await page.locator('#task-modal button').filter({ hasText: '✕' }).click({ force: true });

  await expect(page.locator('body')).toContainText('计划任务');

  await tracker.assertNoClientErrors();
});
