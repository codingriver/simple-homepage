import { test, expect } from '../../helpers/fixtures';
import { loginAsDevAdmin } from '../../helpers/auth';
import { runDockerShell } from '../../helpers/cli';

test('RiverOps runtime naming contract is consistent', async ({ page }) => {
  const runtime = runDockerShell([
    'set -eu',
    'id riverops >/dev/null',
    'test -d /var/www/riverops',
    'test -d /home/riverops',
    'test -f /etc/nginx/http.d/riverops.conf',
    'test -f /usr/local/etc/php-fpm.d/riverops.conf',
    'test -f /usr/local/etc/php/conf.d/99-riverops-custom.ini',
    'test -f /usr/local/bin/riverops-task-helper',
  ].join('; '));
  expect(runtime.code, runtime.output).toBe(0);

  await loginAsDevAdmin(page);
  const cookieNames = (await page.context().cookies()).map((cookie) => cookie.name);
  expect(cookieNames).toContain('riverops_session');
  expect(cookieNames).toContain('riverops_php_session');

  await page.goto('/admin/nginx.php');
  const editorType = await page.evaluate(() => typeof (window as Window & { RiverOpsAceEditor?: unknown }).RiverOpsAceEditor);
  expect(editorType).toBe('object');
});
