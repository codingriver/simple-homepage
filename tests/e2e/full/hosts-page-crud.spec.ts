import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

test('hosts page supports remote host and ssh key crud via form posts', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const hostName = `e2e-remote-host-${ts}`;
  const keyName = `e2e-key-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // save_remote_host
  const saveHost = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: {
      action: 'save_remote_host',
      _csrf: csrf,
      host_name: hostName,
      hostname: '192.168.1.100',
      port: '22',
      username: 'root',
      auth_type: 'password',
      password: 'secret',
      group_name: 'e2e-group',
      tags: 'e2e,test',
      notes: 'e2e notes',
    },
    maxRedirects: 0,
  });
  expect(saveHost.status()).toBe(302);
  expect(saveHost.headers()['location'] || '').toContain('hosts.php#remote');

  // verify host persisted via ssh_manager_lib
  const hostQuery = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$hosts = ssh_manager_list_hosts();',
      'foreach ($hosts as $host) {',
      '  if (($host["name"] ?? "") === "' + hostName + '") {',
      '    echo json_encode($host, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
      '    break;',
      '  }',
      '}',
    ].join(' ')
  );
  expect(hostQuery.code).toBe(0);
  expect(hostQuery.stdout).toContain(hostName);

  // save_ssh_key
  const keyPrivate = '-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key-data\n-----END OPENSSH PRIVATE KEY-----';
  const saveKey = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: {
      action: 'save_ssh_key',
      _csrf: csrf,
      key_name: keyName,
      key_username: 'root',
      private_key: keyPrivate,
      passphrase: '',
    },
    maxRedirects: 0,
  });
  expect(saveKey.status()).toBe(302);
  expect(saveKey.headers()['location'] || '').toContain('hosts.php#keys');

  const keyQuery = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$keys = ssh_manager_list_keys();',
      'foreach ($keys as $key) {',
      '  if (($key["name"] ?? "") === "' + keyName + '") {',
      '    echo $key["id"] ?? "";',
      '    break;',
      '  }',
      '}',
    ].join(' ')
  );
  expect(keyQuery.code).toBe(0);
  const keyId = keyQuery.stdout.trim();
  expect(keyId).not.toBe('');

  // delete_ssh_key
  const deleteKey = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'delete_ssh_key', _csrf: csrf, key_id: keyId },
    maxRedirects: 0,
  });
  expect(deleteKey.status()).toBe(302);

  // delete_remote_host
  const hostId = JSON.parse(hostQuery.stdout).id || '';
  const deleteHost = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'delete_remote_host', _csrf: csrf, host_id: hostId },
    maxRedirects: 0,
  });
  expect(deleteHost.status()).toBe(302);

  await tracker.assertNoClientErrors();
});
