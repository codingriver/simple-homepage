import { test, expect, Page } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

type WebhookState = {
  enabled: boolean;
  type: string;
  url: string;
  tgChat: string;
  events: string[];
};

async function readWebhookState(page: Page): Promise<WebhookState> {
  return {
    enabled: await page.locator('input[name="webhook_enabled"]').isChecked(),
    type: await page.locator('select[name="webhook_type"]').inputValue(),
    url: await page.locator('input[name="webhook_url"]').inputValue(),
    tgChat: await page.locator('input[name="webhook_tg_chat"]').inputValue(),
    events: await page.locator('input[name="webhook_events[]"]:checked').evaluateAll((nodes) =>
      nodes
        .map((node) => (node as HTMLInputElement).value)
        .filter((value): value is string => typeof value === 'string' && value.length > 0)
    ),
  };
}

async function setWebhookEvents(page: Page, values: string[]) {
  const checkboxes = page.locator('input[name="webhook_events[]"]');
  const total = await checkboxes.count();

  for (let i = 0; i < total; i += 1) {
    const checkbox = checkboxes.nth(i);
    const value = await checkbox.inputValue();
    await checkbox.setChecked(values.includes(value));
  }
}

async function saveWebhook(page: Page) {
  await page.getByRole('button', { name: /保存 Webhook 设置/ }).click();
  await expect(page.locator('body')).toContainText('Webhook 设置已保存');
}

async function restoreWebhookState(page: Page, state: WebhookState) {
  await page.goto('/admin/settings.php#webhook');

  const enabled = page.locator('input[name="webhook_enabled"]');
  if (state.enabled) await enabled.check();
  else await enabled.uncheck();

  await page.locator('select[name="webhook_type"]').selectOption(state.type);
  await page.locator('input[name="webhook_url"]').fill(state.url);
  await page.locator('input[name="webhook_tg_chat"]').evaluate(
    (el, value) => {
      (el as HTMLInputElement).value = value;
    },
    state.tgChat
  );
  await setWebhookEvents(page, state.events);
  await saveWebhook(page);
}

test('settings webhook section toggles type-specific field and persists fallback event defaults', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/settings_ajax\.php\?action=nginx_sudo/],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#webhook');
  const original = await readWebhookState(page);

  try {
    await page.locator('select[name="webhook_type"]').selectOption('custom');
    await expect(page.locator('#wh_tg_chat')).toBeHidden();

    await page.locator('select[name="webhook_type"]').selectOption('telegram');
    await expect(page.locator('#wh_tg_chat')).toBeVisible();

    await page.locator('input[name="webhook_enabled"]').check();
    await page.locator('input[name="webhook_url"]').fill('http://127.0.0.1:9/webhook');
    await page.locator('input[name="webhook_tg_chat"]').fill('-1001234567890');
    await setWebhookEvents(page, []);
    await saveWebhook(page);

    await page.reload();
    await expect(page.locator('input[name="webhook_enabled"]')).toBeChecked();
    await expect(page.locator('select[name="webhook_type"]')).toHaveValue('telegram');
    await expect(page.locator('#wh_tg_chat')).toBeVisible();
    await expect(page.locator('input[name="webhook_url"]')).toHaveValue('http://127.0.0.1:9/webhook');
    await expect(page.locator('input[name="webhook_tg_chat"]')).toHaveValue('-1001234567890');
    await expect(page.locator('input[name="webhook_events[]"][value="FAIL"]')).toBeChecked();
    await expect(page.locator('input[name="webhook_events[]"][value="IP_LOCKED"]')).toBeChecked();
    await expect(page.locator('input[name="webhook_events[]"][value="SUCCESS"]')).not.toBeChecked();
    await expect(page.locator('input[name="webhook_events[]"][value="LOGOUT"]')).not.toBeChecked();
    await expect(page.locator('input[name="webhook_events[]"][value="SETUP"]')).not.toBeChecked();
  } finally {
    await restoreWebhookState(page, original);
  }

  await tracker.assertNoClientErrors();
});

test('settings webhook test action reports missing url and invalid custom url clearly', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/settings_ajax\.php\?action=nginx_sudo/],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#webhook');
  const original = await readWebhookState(page);

  try {
    await page.locator('input[name="webhook_enabled"]').check();
    await page.locator('select[name="webhook_type"]').selectOption('custom');
    await expect(page.locator('#wh_tg_chat')).toBeHidden();
    await page.locator('input[name="webhook_url"]').fill('');
    await setWebhookEvents(page, ['FAIL']);
    await saveWebhook(page);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /发送测试消息/ }).click();
    await expect(page.locator('body')).toContainText('未配置 Webhook URL');

    await page.goto('/admin/settings.php#webhook');
    await page.locator('input[name="webhook_enabled"]').check();
    await page.locator('select[name="webhook_type"]').selectOption('custom');
    await page.locator('input[name="webhook_url"]').fill('http://127.0.0.1:9/webhook');
    await saveWebhook(page);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /发送测试消息/ }).click();
    await expect(page.locator('body')).toContainText(/发送失败：|发送失败，HTTP 状态码：/);
    await expect(page).toHaveURL(/settings\.php#webhook/);
  } finally {
    await restoreWebhookState(page, original);
  }

  await tracker.assertNoClientErrors();
});
