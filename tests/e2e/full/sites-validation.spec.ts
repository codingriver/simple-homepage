import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin sees validation errors when creating invalid proxy site', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const groupId = `site-validate-group-${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  const groupCsrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const groupRes = await page.request.post('http://127.0.0.1:58080/admin/groups.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      _csrf: groupCsrf,
      action: 'save',
      old_id: '',
      id: groupId,
      name: `校验分组 ${ts}`,
      icon: '📁',
      desc: '',
      order: '0',
      auth_required: '0',
    },
  });
  expect(groupRes.ok()).toBeTruthy();

  await page.goto('/admin/sites.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const response = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      _csrf: csrf,
      action: 'save',
      old_gid: '',
      old_sid: '',
      gid: groupId,
      sid: `site-invalid-${ts}`,
      name: '非法代理站点',
      icon: '🔗',
      desc: '',
      order: '0',
      type: 'proxy',
      proxy_mode: 'path',
      proxy_target: 'https://example.com',
      slug: `site-invalid-${ts}`,
      proxy_domain: '',
      url: '',
    },
  });
  expect(await response.json()).toMatchObject({ ok: false, msg: '代理目标必须是 RFC1918 内网IPv4地址（防SSRF）' });
  await tracker.assertNoClientErrors();
});

test('admin sees boundary validation for invalid site id empty name and missing group', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/sites.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  const invalidId = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      _csrf: csrf,
      action: 'save',
      old_gid: '',
      old_sid: '',
      gid: '',
      sid: 'Bad Site',
      name: '非法ID站点',
      icon: '🔗',
      desc: '',
      order: '0',
      type: 'external',
      url: 'https://example.com',
    },
  });
  expect(await invalidId.json()).toMatchObject({ ok: false, msg: '站点ID只允许小写字母数字下划线横杠' });

  const emptyName = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      _csrf: csrf,
      action: 'save',
      old_gid: '',
      old_sid: '',
      gid: '',
      sid: `site-empty-name-${Date.now()}`,
      name: '',
      icon: '🔗',
      desc: '',
      order: '0',
      type: 'external',
      url: 'https://example.com',
    },
  });
  expect(await emptyName.json()).toMatchObject({ ok: false, msg: '名称不能为空' });

  const missingGroup = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      _csrf: csrf,
      action: 'save',
      old_gid: '',
      old_sid: '',
      gid: '',
      sid: `site-no-group-${Date.now()}`,
      name: '未选分组站点',
      icon: '🔗',
      desc: '',
      order: '0',
      type: 'external',
      url: 'https://example.com',
    },
  });
  expect(await missingGroup.json()).toMatchObject({ ok: false, msg: '请选择所属分组' });

  const emptySlug = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      _csrf: csrf,
      action: 'save',
      old_gid: '',
      old_sid: '',
      gid: '',
      sid: `site-slug-empty-${Date.now()}`,
      name: '空 slug 代理',
      icon: '🔗',
      desc: '',
      order: '0',
      type: 'proxy',
      proxy_mode: 'path',
      proxy_target: 'http://192.168.1.88:8080',
      slug: '',
      proxy_domain: '',
      url: '',
    },
  });
  expect(await emptySlug.json()).toMatchObject({ ok: false, msg: '请选择所属分组' });

  await tracker.assertNoClientErrors();
});
