import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin can configure auto health check and run manual check', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  await page.goto('/admin/settings.php#health');

  // 启用自动检测并设置间隔为 10 分钟
  const autoCheckbox = page.locator('input[name="health_auto_enabled"]');
  await autoCheckbox.check();
  await page.locator('input[name="health_auto_interval"]').fill('10');
  await page.locator('#health button[type="submit"]').click();

  // 应该看到成功提示
  await expect(page.locator('.alert-success')).toContainText('自动健康检测配置已保存');

  // 点击立即检测所有站点
  await page.getByRole('button', { name: /立即检测所有站点/ }).click();
  // 等待检测完成（结果显示或空提示变化）
  await page.waitForTimeout(1200);

  // 验证结果区域出现（至少表头可见）或空提示可见
  const resultsVisible = await page.locator('#health_results').isVisible().catch(() => false);
  const emptyVisible = await page.locator('#health_empty').isVisible().catch(() => false);
  expect(resultsVisible || emptyVisible).toBe(true);

  await tracker.assertNoClientErrors();
});
