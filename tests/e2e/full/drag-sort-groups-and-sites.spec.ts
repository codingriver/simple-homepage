import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

async function csrfFromPage(page: any): Promise<string> {
  return page.evaluate(() => {
    const el = document.querySelector('input[name="_csrf"]') as HTMLInputElement | null;
    return el ? el.value : '';
  });
}

test('admin can reorder groups and sites via drag-and-drop backend', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const g1 = `drag-g1-${ts}`;
  const g2 = `drag-g2-${ts}`;
  const g1name = `分组A ${ts}`;
  const g2name = `分组B ${ts}`;

  await loginAsDevAdmin(page);

  // ── 创建两个分组 ──
  await page.goto('/admin/groups.php');
  for (const [gid, name] of [[g1, g1name], [g2, g2name]]) {
    await page.getByRole('button', { name: /添加分组/ }).click();
    await page.locator('#fi_id').fill(gid);
    await page.locator('#fi_name').fill(name);
    await page.locator('#fi_auth').selectOption('0');
    await submitVisibleModal(page);
    await expect(page.locator(`tr[data-id="${gid}"]`)).toBeVisible();
  }

  // 验证 SortableJS 已加载且存在拖拽手柄
  const sortableOk = await page.evaluate(() => typeof Sortable !== 'undefined');
  expect(sortableOk).toBe(true);
  await expect(page.locator('tr[data-id] .drag-handle').first()).toBeVisible();

  // ── 分组排序：直接调用 reorder 接口验证后端持久化 ──
  let csrf = await csrfFromPage(page);
  await page.evaluate(async ({ g1, g2, csrf }) => {
    const form = new FormData();
    form.append('action', 'reorder_groups');
    form.append('_csrf', csrf);
    form.append('orders[]', `${g2}:0`);
    form.append('orders[]', `${g1}:1`);
    const res = await fetch('/admin/groups.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: form,
    });
    if (!res.ok) throw new Error('reorder groups failed: ' + res.status);
    const data = await res.json();
    if (!data.ok) throw new Error(data.msg || 'reorder groups error');
  }, { g1, g2, csrf });

  await page.reload();
  const g2BeforeG1 = await page.evaluate(({ g1, g2 }) => {
    const a = document.querySelector(`tr[data-id="${g1}"]`);
    const b = document.querySelector(`tr[data-id="${g2}"]`);
    if (!a || !b) return false;
    return !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_PRECEDING);
  }, { g1, g2 });
  expect(g2BeforeG1).toBe(true);

  // ── 创建两个站点 ──
  const s1 = `drag-s1-${ts}`;
  const s2 = `drag-s2-${ts}`;
  await page.goto('/admin/sites.php');
  for (const [sid, name] of [[s1, '站点A'], [s2, '站点B']]) {
    await page.getByRole('button', { name: /添加站点/ }).click();
    await page.locator('#fi_sid').fill(sid);
    await page.locator('#fi_name').fill(name);
    await page.locator('#fi_gid').selectOption(g1);
    await page.locator('#fi_type').selectOption('external');
    await page.locator('#fi_url').fill('https://example.com');
    await submitVisibleModal(page);
    await expect(page.locator(`tr[data-sid="${sid}"]`)).toBeVisible();
  }

  // 验证站点表格有拖拽手柄
  await expect(page.locator('.sites-table tr[data-sid] td:first-child').first()).toBeVisible();

  // ── 站点排序：直接调用 reorder 接口验证后端持久化 ──
  csrf = await csrfFromPage(page);
  await page.evaluate(async ({ g1, s1, s2, csrf }) => {
    const form = new FormData();
    form.append('action', 'reorder');
    form.append('gid', g1);
    form.append('_csrf', csrf);
    form.append('orders[]', `${s2}:0`);
    form.append('orders[]', `${s1}:1`);
    const res = await fetch('/admin/sites.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: form,
    });
    if (!res.ok) throw new Error('reorder sites failed: ' + res.status);
    const data = await res.json();
    if (!data.ok) throw new Error(data.msg || 'reorder sites error');
  }, { g1, s1, s2, csrf });

  await page.reload();
  const s2BeforeS1 = await page.evaluate(({ g1, s1, s2 }) => {
    const table = document.querySelector(`.sites-table[data-gid="${g1}"]`);
    if (!table) return false;
    const a = table.querySelector(`tr[data-sid="${s1}"]`);
    const b = table.querySelector(`tr[data-sid="${s2}"]`);
    if (!a || !b) return false;
    return !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_PRECEDING);
  }, { g1, s1, s2 });
  expect(s2BeforeS1).toBe(true);

  await tracker.assertNoClientErrors();
});
