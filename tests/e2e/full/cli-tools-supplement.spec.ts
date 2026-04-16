import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { runDockerPhp, runDockerShell, snapshotLocalFiles, restoreLocalFiles } from '../../helpers/cli';

const dataDir = path.resolve(__dirname, '../../../data');
const sitesFile = path.join(dataDir, 'sites.json');
const configFile = path.join(dataDir, 'config.json');
const expiryScanFile = path.join(dataDir, 'expiry_scan.json');

test.describe.configure({ mode: 'serial' });

test('cli/check_expiry scans sites and outputs expiry summary', async () => {
  const snapshots = await snapshotLocalFiles([sitesFile, configFile, expiryScanFile]);
  try {
    const sites = {
      groups: [
        {
          id: 'g1',
          name: 'TestGroup',
          sites: [
            {
              id: 's1',
              name: 'TestSite',
              url: 'http://127.0.0.1:58080/',
              type: 'link',
              domain_expire_at: '2030-12-31',
              ssl_expire_at: '',
            },
          ],
        },
      ],
    };
    await fs.mkdir(path.dirname(sitesFile), { recursive: true });
    await fs.writeFile(sitesFile, JSON.stringify(sites, null, 2), 'utf8');

    const result = runDockerPhp('/var/www/nav/cli/check_expiry.php');
    expect(result.code).toBe(0);
    expect(result.stdout).toContain('expiry scan finished');
    expect(result.stdout).toContain('TestSite');
    expect(result.stdout).toContain('domain_days=');
  } finally {
    await restoreLocalFiles(snapshots);
  }
});

test('cli/health_check_cron respects enabled flag and runs with force', async () => {
  const snapshots = await snapshotLocalFiles([configFile]);
  try {
    const config = JSON.parse(await fs.readFile(configFile, 'utf8').catch(() => '{}'));
    config.health_auto_enabled = '0';
    await fs.writeFile(configFile, JSON.stringify(config, null, 2), 'utf8');

    const disabled = runDockerPhp('/var/www/nav/cli/health_check_cron.php');
    expect(disabled.code).toBe(0);
    expect(disabled.stdout).toContain('auto health check is disabled');

    const forced = runDockerPhp('/var/www/nav/cli/health_check_cron.php', ['--force']);
    expect(forced.code).toBe(0);
    expect(forced.stdout).toContain('health check finished');
  } finally {
    await restoreLocalFiles(snapshots);
  }
});

test('cli/host_agent prints usage for invalid command', async () => {
  const result = runDockerPhp('/var/www/nav/cli/host_agent.php', ['help']);
  expect(result.code).toBe(1);
  expect(result.output).toContain('usage:');
  expect(result.output).toContain('host_agent.php serve');
});

test('cli/host_agent_docker_proxy responds to ping and docker socket', async () => {
  const ping = runDockerPhp('/var/www/nav/cli/host_agent_docker_proxy.php', ['PING']);
  expect(ping.code).toBe(0);
  expect(ping.stdout.trim()).toBe('pong');

  const socket = '/var/run/docker.sock';
  const socketExists = runDockerShell(`test -S ${socket} && echo yes || echo no`).stdout.trim() === 'yes';
  if (socketExists) {
    const proxy = runDockerPhp('/var/www/nav/cli/host_agent_docker_proxy.php', ['GET', '/_ping']);
    expect(proxy.code).toBe(0);
    const body = JSON.parse(proxy.stdout);
    expect(body.ok).toBe(true);
    expect(body.status).toBe(200);
  }
});
