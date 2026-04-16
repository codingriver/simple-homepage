import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const onePixelPng = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9l9j0AAAAASUVORK5CYII=',
  'base64'
);

test('public resource guards cover favicon bg and logout flows', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 405 \(Method Not Allowed\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const tempTextFile = path.resolve(__dirname, '../../../data/bg/not-image-e2e.txt');

  try {
    await fs.writeFile(tempTextFile, 'not an image', 'utf8');

    await page.goto('/login.php');

    const faviconAnon = await page.evaluate(async () => {
      const res = await fetch('/favicon.php?url=https://example.com', { credentials: 'include' });
      return { status: res.status, text: await res.text() };
    });
    expect(faviconAnon.status).toBe(401);

    const bgMissing = await page.evaluate(async () => {
      const res = await fetch('/bg.php', { credentials: 'include' });
      return { status: res.status, text: await res.text() };
    });
    expect(bgMissing.status).toBe(404);

    const logoutGet = await page.goto('/logout.php');
    expect(logoutGet?.status()).toBe(405);
    await expect(page.locator('body')).toContainText('Method Not Allowed');

    await loginAsDevAdmin(page);
    await page.goto('/admin/settings.php');
    await page.locator('input[name="bg_image"]').setInputFiles({
      name: 'tiny.png',
      mimeType: 'image/png',
      buffer: onePixelPng,
    });
    await page.getByRole('button', { name: /保存设置/ }).click();
    await expect(page.locator('body')).toContainText('设置已保存');

    const bgUrl = await page.locator('input[name="clear_bg_image"]').evaluate((el) => {
      const input = el as HTMLInputElement;
      return input.checked;
    });
    expect(bgUrl).toBe(false);

    const bgOk = await page.evaluate(async () => {
      const currentLabel = document.body.innerText.includes('当前：');
      const bodyStyle = getComputedStyle(document.body).backgroundImage;
      const match = bodyStyle.match(/bg\.php\?file=([^"')]+)/);
      if (!match) return { found: currentLabel, status: 0, type: '' };
      const res = await fetch('/bg.php?file=' + match[1], { credentials: 'include' });
      return { found: true, status: res.status, type: res.headers.get('content-type') || '' };
    });
    expect(bgOk.found).toBe(true);
    if (bgOk.status !== 0) {
      expect(bgOk.status).toBe(200);
      expect(bgOk.type).toContain('image/');
    }

    const bgTraversal = await page.evaluate(async () => {
      const res = await fetch('/bg.php?file=../config.json', { credentials: 'include' });
      return { status: res.status, text: await res.text() };
    });
    expect(bgTraversal.status).toBe(404);

    const bgInvalidMime = await page.evaluate(async () => {
      const res = await fetch('/bg.php?file=not-image-e2e.txt', { credentials: 'include' });
      return { status: res.status, text: await res.text() };
    });
    expect(bgInvalidMime.status).toBe(404);

    const faviconBad = await page.evaluate(async () => {
      const res = await fetch('/favicon.php?url=http://127.0.0.1', { credentials: 'include' });
      return { status: res.status, text: await res.text() };
    });
    expect(faviconBad.status).toBe(403);

    const faviconMissingUrl = await page.evaluate(async () => {
      const res = await fetch('/favicon.php', { credentials: 'include' });
      return { status: res.status, text: await res.text() };
    });
    expect(faviconMissingUrl.status).toBe(400);

    const logoutNoCsrf = await page.evaluate(async () => {
      const res = await fetch('/logout.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'x=1',
      });
      return { status: res.status, text: await res.text() };
    });
    expect(logoutNoCsrf.status).toBeGreaterThanOrEqual(400);

    await page.goto('/index.php');
    await page.locator('form[action="logout.php"] input[name="_csrf"]').first().evaluate((el: HTMLInputElement) => {
      el.form?.requestSubmit();
    });
    await expect(page).toHaveURL(/login\.php/);

    await tracker.assertNoClientErrors();
  } finally {
    await fs.unlink(tempTextFile).catch(() => {});
  }
});
