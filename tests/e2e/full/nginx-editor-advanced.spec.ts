import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const simpleParamsPath = path.resolve(__dirname, '../../../data/nginx/proxy-params-simple.conf');

function normalizeLineEndings(value: string) {
  return value.replace(/\r\n/g, '\n');
}

async function openEditorForTarget(page: Parameters<typeof loginAsDevAdmin>[0], target: string) {
  await page.locator(`[data-edit-target="${target}"]`).click();
  await expect(page.locator('#nav-ace-editor-modal')).toHaveClass(/open/);
  await page.waitForFunction(() => typeof (window as typeof window & { ace?: { edit: (id: string) => unknown } }).ace?.edit === 'function');
}

async function setAceValue(page: Parameters<typeof loginAsDevAdmin>[0], value: string) {
  await page.evaluate((nextValue) => {
    const aceEditor = (window as typeof window & { ace: { edit: (id: string) => { setValue: (value: string, cursor: number) => void } } }).ace.edit('nav-ace-editor');
    aceEditor.setValue(nextValue, -1);
  }, value);
}

test('nginx editor supports advanced controls plus real save syntax and reload submissions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
    ],
  });
  const originalContent = await fs.readFile(simpleParamsPath, 'utf8');
  const marker = `# playwright nginx e2e ${Date.now()}`;
  const updatedContent = `${originalContent.trimEnd()}\n${marker}\n`;

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/nginx.php');

    // 通过列表直接打开「反代参数模板 — 精简」编辑器
    await openEditorForTarget(page, 'proxy_params_simple');

    // 调整编辑器控件
    await page.locator('#nav-ace-fontsize').selectOption('18');
    await page.locator('#nav-ace-wrap').uncheck();
    await expect(page.locator('#nav-ace-fontsize')).toHaveValue('18');
    await expect(page.locator('#nav-ace-wrap')).not.toBeChecked();

    await setAceValue(page, updatedContent);
    await expect(page.locator('#nav-ace-dirty-status')).toContainText('有未保存修改');

    // 保存
    await page.locator('#nav-ace-toolbar-actions button[data-action="save"]').click();
    await expect.poll(async () => fs.readFile(simpleParamsPath, 'utf8')).toContain(marker);

    // 关闭弹窗
    await page.locator('#nav-ace-editor-modal .ngx-close-btn').click();
    await expect(page.locator('#nav-ace-editor-modal')).not.toHaveClass(/open/);

    // 重新打开并恢复内容
    await openEditorForTarget(page, 'proxy_params_simple');
    await setAceValue(page, originalContent);

    // 保存并 Reload（会弹出确认框）
    const reloadPostResponse = page.waitForResponse(
      (response) =>
        response.url().includes('/admin/nginx.php') &&
        response.request().method() === 'POST' &&
        (response.request().postData() || '').includes('action=save_and_reload')
    );

    await page.locator('#nav-ace-toolbar-actions button[data-action="save_reload"]').click();
    // 确认弹窗
    await page.locator('#nav-confirm-ok').click();

    const reloadPost = await reloadPostResponse;
    expect([200, 302]).toContain(reloadPost.status());

    await expect
      .poll(async () => normalizeLineEndings(await fs.readFile(simpleParamsPath, 'utf8')))
      .toBe(normalizeLineEndings(originalContent));

    await tracker.assertNoClientErrors();
  } finally {
    await fs.writeFile(simpleParamsPath, originalContent, 'utf8');
  }
});
