import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('card size custom input shows when custom is selected', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  const customInput = page.locator('#card_size_custom');
  await expect(customInput).toBeHidden();

  await page.locator('#card_size_sel').selectOption('custom');
  await expect(customInput).toBeVisible();

  await page.locator('#card_size_sel').selectOption('140');
  await expect(customInput).toBeHidden();

  await tracker.assertNoClientErrors();
});

test('webhook type shows telegram chat field only for telegram', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  const chatField = page.locator('#wh_tg_chat');

  // Default may not be telegram
  const currentType = await page.locator('#wh_type').inputValue();
  if (currentType === 'telegram') {
    await expect(chatField).toBeVisible();
  } else {
    await expect(chatField).toBeHidden();
  }

  await page.locator('#wh_type').selectOption('telegram');
  await expect(chatField).toBeVisible();

  await page.locator('#wh_type').selectOption('custom');
  await expect(chatField).toBeHidden();

  await tracker.assertNoClientErrors();
});

test('proxy params mode cards highlight on selection', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  // Find all ppm cards
  const cards = page.locator('[data-ppm-card]');
  const count = await cards.count();
  expect(count).toBeGreaterThan(0);

  // Click the last card and verify it gets selected
  const lastCard = cards.last();
  const lastRadio = lastCard.locator('input[name="proxy_params_mode"]');
  await lastCard.click();
  await expect(lastRadio).toBeChecked();

  // Click the first card and verify it gets selected instead
  const firstCard = cards.first();
  const firstRadio = firstCard.locator('input[name="proxy_params_mode"]');
  await firstCard.click();
  await expect(firstRadio).toBeChecked();
  await expect(lastRadio).not.toBeChecked();

  await tracker.assertNoClientErrors();
});
