import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const containerNotifyProbeLog = '/var/www/nav/data/logs/notify_probe.log';

test('notify probe endpoint returns ok and appends to log for POST and GET', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  // Clean existing log
  runDockerPhpInline(`$p = '${containerNotifyProbeLog}'; if (file_exists($p)) unlink($p);`);

  // 1. POST with JSON body
  const postBody = { event: 'test_probe', id: `probe-${Date.now()}` };
  const postRes = await page.request.post('http://127.0.0.1:58080/notify_probe.php', {
    headers: { 'Content-Type': 'application/json' },
    data: postBody,
  });
  expect(postRes.status()).toBe(200);
  const postJson = (await postRes.json()) as { ok: boolean; message: string; time: string };
  expect(postJson.ok).toBe(true);
  expect(postJson.message).toContain('notify probe received');
  expect(postJson.time).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

  // Verify log file got the entry
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        `$p = '${containerNotifyProbeLog}'; echo file_exists($p) ? file_get_contents($p) : '';`
      );
      expect(result.code).toBe(0);
      return result.stdout;
    })
    .toContain('test_probe');

  const logContentAfterPost = runDockerPhpInline(
    `$p = '${containerNotifyProbeLog}'; echo file_exists($p) ? file_get_contents($p) : '';`
  ).stdout;
  expect(logContentAfterPost).toContain(`"event":"test_probe"`);
  expect(logContentAfterPost).toContain('"method":"POST"');

  // 2. GET request also works
  const getRes = await page.request.get('http://127.0.0.1:58080/notify_probe.php?check=1');
  expect(getRes.status()).toBe(200);
  const getJson = (await getRes.json()) as { ok: boolean; message: string; time: string };
  expect(getJson.ok).toBe(true);
  expect(getJson.time).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

  // Verify GET entry appended
  const logContentAfterGet = runDockerPhpInline(
    `$p = '${containerNotifyProbeLog}'; echo file_exists($p) ? file_get_contents($p) : '';`
  ).stdout;
  expect(logContentAfterGet).toContain('"method":"GET"');
  expect(logContentAfterGet).toContain('"check":"1"');

  await tracker.assertNoClientErrors();
});
