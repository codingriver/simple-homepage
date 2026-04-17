import { test, expect } from '../../helpers/fixtures';
import { runDockerPhpInline } from '../../helpers/cli';

test('public webdav rejects unauthorized and unsupported methods', async ({ request }) => {
  // ensure webdav enabled but no valid accounts for this test
  runDockerPhpInline(
    [
      '$cfgPath = "/var/www/nav/data/config.json";',
      '$cfg = file_exists($cfgPath) ? (json_decode((string)file_get_contents($cfgPath), true) ?: []) : [];',
      '$cfg["webdav_enabled"] = "1";',
      'file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));',
      '$accounts = "/var/www/nav/data/webdav_accounts.json";',
      'file_put_contents($accounts, json_encode(["accounts" => []], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));',
    ].join(' ')
  );

  // no auth -> 401
  const noAuth = await request.fetch('/webdav/', { method: 'PROPFIND' });
  expect(noAuth.status()).toBe(401);

  // bad auth -> 401
  const badAuth = await request.fetch('/webdav/', {
    method: 'PROPFIND',
    headers: { Authorization: 'Basic ' + Buffer.from('bad:user').toString('base64') },
  });
  expect(badAuth.status()).toBe(401);

  // unsupported method even with auth (use a dummy auth header) -> 401 because account invalid
  const unsupported = await request.fetch('/webdav/random', {
    method: 'PATCH',
    headers: { Authorization: 'Basic ' + Buffer.from('nobody:nopass').toString('base64') },
  });
  expect(unsupported.status()).toBe(401);
});
