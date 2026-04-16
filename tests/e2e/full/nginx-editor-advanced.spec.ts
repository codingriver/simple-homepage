import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const simpleParamsPath = path.resolve(__dirname, '../../../data/nginx/proxy-params-simple.conf');

function normalizeLineEndings(value: string) {
  return value.replace(/\r\n/g, '\n');
}

async function openEditor(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.getByRole('button', { name: /打开文本编辑器/ }).click();
  await expect(page.locator('#nginx-editor-modal')).toHaveClass(/open/);
  await page.waitForFunction(() => typeof (window as typeof window & { ace?: { edit: (id: string) => unknown } }).ace?.edit === 'function');
}

async function setAceValue(page: Parameters<typeof loginAsDevAdmin>[0], value: string) {
  await page.evaluate((nextValue) => {
    const aceEditor = (window as typeof window & { ace: { edit: (id: string) => { setValue: (value: string, cursor: number) => void } } }).ace.edit('nginx-ace-editor');
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
    await page.goto('/admin/nginx.php?tab=proxy&target=proxy_params_simple');

    await page.getByRole('link', { name: /子域名模式/ }).click();
    await expect(page.locator('#editor-target-label')).toContainText('子域名模式');
    await page.getByRole('link', { name: /参数模板（精简）/ }).click();
    await expect(page.locator('#editor-target-label')).toContainText('参数模板（精简模式）');
    await page.getByRole('link', { name: /HTTP 模块/ }).click();
    await expect(page.locator('#editor-target-label')).toContainText('HTTP 模块');
    await page.goto('/admin/nginx.php?tab=proxy&target=proxy_params_simple');

    await openEditor(page);
    await page.locator('#editor-font-size').selectOption('18');
    await page.locator('#editor-wrap-toggle').uncheck();
    await page.locator('#editor-focus-toggle').check();
    await expect(page.locator('#editor-font-size')).toHaveValue('18');
    await expect(page.locator('#editor-wrap-toggle')).not.toBeChecked();
    await expect(page.locator('#editor-focus-toggle')).toBeChecked();

    await setAceValue(page, updatedContent);
    await expect(page.locator('#editor-dirty-hint')).toContainText('有未保存修改');

    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.getByRole('button', { name: /^保存$/ }).click(),
    ]);
    await expect.poll(async () => fs.readFile(simpleParamsPath, 'utf8')).toContain(marker);
    await page.goto('/admin/nginx.php?tab=proxy&target=proxy_params_simple');

    const syntaxPostResponse = page.waitForResponse(
      (response) =>
        response.url().includes('/admin/nginx.php') &&
        response.request().method() === 'POST' &&
        (response.request().postData() || '').includes('action=syntax_test')
    );
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.evaluate(() => {
        const form = document.getElementById('nginx-editor-form') as HTMLFormElement | null;
        const button = form?.querySelector('button[name="action"][value="syntax_test"]') as HTMLButtonElement | null;
        if (!form || !button) throw new Error('syntax_test button not found');
        form.requestSubmit(button);
      }),
    ]);
    const syntaxPost = await syntaxPostResponse;
    expect([200, 302]).toContain(syntaxPost.status());
    await page.waitForURL(/admin\/nginx\.php/);
    await page.waitForLoadState('networkidle');
    expect(page.url()).toContain('target=proxy_params_simple');

    await page.goto('/admin/nginx.php?tab=proxy&target=proxy_params_simple');
    await openEditor(page);
    await setAceValue(page, originalContent);
    const reloadPostResponse = page.waitForResponse(
      (response) =>
        response.url().includes('/admin/nginx.php') &&
        response.request().method() === 'POST' &&
        (response.request().postData() || '').includes('action=save_and_reload')
    );
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.evaluate(() => {
        const form = document.getElementById('nginx-editor-form') as HTMLFormElement | null;
        const button = form?.querySelector('button[name="action"][value="save_and_reload"]') as HTMLButtonElement | null;
        if (!form || !button) throw new Error('save_and_reload button not found');
        form.requestSubmit(button);
      }),
    ]);
    const reloadPost = await reloadPostResponse;
    expect([200, 302]).toContain(reloadPost.status());
    await page.waitForURL(/admin\/nginx\.php/);
    await page.waitForLoadState('networkidle');
    await expect
      .poll(async () => normalizeLineEndings(await fs.readFile(simpleParamsPath, 'utf8')))
      .toBe(normalizeLineEndings(originalContent));

    await tracker.assertNoClientErrors();
  } finally {
    await fs.writeFile(simpleParamsPath, originalContent, 'utf8');
  }
});
