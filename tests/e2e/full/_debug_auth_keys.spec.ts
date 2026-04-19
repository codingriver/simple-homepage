import { expect, test } from '../../helpers/fixtures';
import { loginAsDevAdmin } from '../../helpers/auth';

test('debug authorized_keys_list', async ({ page }) => {
  await loginAsDevAdmin(page);
  const listRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=authorized_keys_list&host_id=local&user=root`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  console.log('status:', listRes.status());
  const text = await listRes.text();
  console.log('response text:', text);
});
