import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '@playwright/test';
import {
  restoreContainerFiles,
  restoreLocalFiles,
  runDockerPhp,
  runDockerShell,
  snapshotContainerFiles,
  snapshotLocalFiles,
} from '../../helpers/cli';

test.describe.configure({ mode: 'serial', timeout: 180000 });

const dataDir = path.resolve(__dirname, '../../../data');
const usersFile = path.join(dataDir, 'users.json');
const installedFlag = path.join(dataDir, '.installed');
const configFile = path.join(dataDir, 'config.json');
const ipLocksFile = path.join(dataDir, 'ip_locks.json');
const authSecretFile = path.join(dataDir, 'auth_secret.key');
const sitesFile = path.join(dataDir, 'sites.json');
const authLogFile = path.join(dataDir, 'logs/auth.log');
const dnsConfigFile = path.join(dataDir, 'dns_config.json');
const ddnsTasksFile = path.join(dataDir, 'ddns_tasks.json');
const scheduledTasksFile = path.join(dataDir, 'scheduled_tasks.json');
const ddnsLogDir = path.join(dataDir, 'logs');
const taskLogDir = path.join(dataDir, 'logs');
const taskWorkdirRoot = path.join(dataDir, 'tasks');

function nowId(prefix: string) {
  return `${prefix}_${Date.now()}`;
}

test('manage_users cli covers help validation lifecycle and delete flow', async () => {
  const snapshots = await snapshotLocalFiles([usersFile]);
  const username = nowId('cliuser');

  try {
    const help = runDockerPhp('/var/www/nav/manage_users.php');
    expect(help.code).toBe(0);
    expect(help.stdout).toContain('导航网站用户管理工具');

    const invalidName = runDockerPhp('/var/www/nav/manage_users.php', ['add', 'bad name', '12345678']);
    expect(invalidName.code).toBe(1);
    expect(invalidName.output).toContain('用户名只允许字母、数字、下划线、横杠');

    const shortPassword = runDockerPhp('/var/www/nav/manage_users.php', ['add', username, 'short']);
    expect(shortPassword.code).toBe(1);
    expect(shortPassword.output).toContain('密码至少 8 位');

    const add = runDockerPhp('/var/www/nav/manage_users.php', ['add', username, 'CliPass123']);
    expect(add.code).toBe(0);
    expect(add.stdout).toContain(`OK: 管理员账户 '${username}' 已创建/更新。`);

    const list = runDockerPhp('/var/www/nav/manage_users.php', ['list']);
    expect(list.code).toBe(0);
    expect(list.stdout).toContain(username);

    const info = runDockerPhp('/var/www/nav/manage_users.php', ['info', username]);
    expect(info.code).toBe(0);
    expect(info.stdout).toContain(`用户名   : ${username}`);
    expect(info.stdout).toContain('角色     : admin');

    const beforePasswd = JSON.parse(await fs.readFile(usersFile, 'utf8')) as Record<string, { password_hash: string }>;
    const oldHash = beforePasswd[username]?.password_hash;
    expect(oldHash).toBeTruthy();

    const passwd = runDockerPhp('/var/www/nav/manage_users.php', ['passwd', username, 'CliPass456']);
    expect(passwd.code).toBe(0);
    expect(passwd.stdout).toContain(`OK: '${username}' 的密码已修改。`);

    const afterPasswd = JSON.parse(await fs.readFile(usersFile, 'utf8')) as Record<string, { password_hash: string }>;
    expect(afterPasswd[username]?.password_hash).toBeTruthy();
    expect(afterPasswd[username]?.password_hash).not.toBe(oldHash);

    const missingInfo = runDockerPhp('/var/www/nav/manage_users.php', ['info', '__missing_cli_user__']);
    expect(missingInfo.code).toBe(1);
    expect(missingInfo.output).toContain("错误：用户 '__missing_cli_user__' 不存在。");

    const deleteUser = runDockerPhp('/var/www/nav/manage_users.php', ['del', username]);
    expect(deleteUser.code).toBe(0);
    expect(deleteUser.stdout).toContain(`OK: 用户 '${username}' 已删除。`);

    const afterDelete = JSON.parse(await fs.readFile(usersFile, 'utf8')) as Record<string, unknown>;
    expect(afterDelete[username]).toBeUndefined();

    const missingDelete = runDockerPhp('/var/www/nav/manage_users.php', ['del', '__missing_cli_user__']);
    expect(missingDelete.code).toBe(1);
    expect(missingDelete.output).toContain("错误：用户 '__missing_cli_user__' 不存在。");
  } finally {
    await restoreLocalFiles(snapshots);
  }
});

test('manage_users reset clears key data files and can be restored after verification', async () => {
  const localSnapshots = await snapshotLocalFiles([
    usersFile,
    installedFlag,
    configFile,
    ipLocksFile,
    authSecretFile,
    sitesFile,
    authLogFile,
  ]);
  const containerSnapshots = await snapshotContainerFiles([
    '/etc/nginx/conf.d/nav-proxy.conf',
    '/etc/nginx/http.d/nav-proxy-domains.conf',
    '/etc/nginx/proxy_params_full',
  ]);

  try {
    await fs.writeFile(usersFile, JSON.stringify({ reset_case_admin: { role: 'admin', password_hash: 'hash', created_at: 'now' } }), 'utf8');
    await fs.writeFile(installedFlag, 'installed', 'utf8');
    await fs.writeFile(
      configFile,
      JSON.stringify({ site_name: 'Reset Test', cookie_secure: 'on', cookie_domain: '.reset.test' }, null, 2),
      'utf8'
    );
    await fs.writeFile(ipLocksFile, JSON.stringify({ '127.0.0.1': { count: 5 } }, null, 2), 'utf8');
    await fs.writeFile(authSecretFile, 'old-secret-key', 'utf8');
    await fs.writeFile(sitesFile, JSON.stringify({ groups: [{ id: 'g1', name: 'G1', sites: [{ id: 's1', name: 'S1' }] }] }, null, 2), 'utf8');
    await fs.mkdir(path.dirname(authLogFile), { recursive: true });
    await fs.writeFile(authLogFile, 'old auth log\n', 'utf8');

    const reset = runDockerPhp('/var/www/nav/manage_users.php', ['reset']);
    expect(reset.code).toBe(0);
    expect(reset.stdout).toContain('完整重置完成');
    expect(reset.stdout).toContain('用户数据已清空');

    const users = JSON.parse(await fs.readFile(usersFile, 'utf8'));
    expect(users).toEqual({});
    await expect(fs.access(installedFlag)).rejects.toThrow();

    const config = JSON.parse(await fs.readFile(configFile, 'utf8')) as Record<string, string>;
    expect(config.cookie_secure).toBe('off');
    expect(config.cookie_domain).toBe('');

    const ipLocks = JSON.parse(await fs.readFile(ipLocksFile, 'utf8'));
    expect(ipLocks).toEqual({});

    const newSecret = await fs.readFile(authSecretFile, 'utf8');
    expect(newSecret.trim()).not.toBe('old-secret-key');

    const sites = JSON.parse(await fs.readFile(sitesFile, 'utf8')) as { groups: unknown[] };
    expect(sites.groups).toEqual([]);

    const authLog = await fs.readFile(authLogFile, 'utf8');
    expect(authLog).toBe('');
  } finally {
    await restoreLocalFiles(localSnapshots);
    await restoreContainerFiles(containerSnapshots);
    runDockerShell('nginx -t >/dev/null 2>&1 && nginx -s reload >/dev/null 2>&1 || true');
  }
});

test('cli/alidns_sync exits with explicit error when required config is missing', async () => {
  const snapshots = await snapshotLocalFiles([dnsConfigFile]);

  try {
    await fs.writeFile(dnsConfigFile, JSON.stringify({ version: 2, accounts: [] }, null, 2), 'utf8');
    const result = runDockerPhp('/var/www/nav/cli/alidns_sync.php');
    expect(result.code).toBe(1);
    expect(result.output).toContain('alidns_sync: 缺少 AccessKey 或域名配置');
  } finally {
    await restoreLocalFiles(snapshots);
  }
});

test('cli/ddns_sync covers missing task branch and due-task batch execution branch', async () => {
  const snapshots = await snapshotLocalFiles([ddnsTasksFile]);
  const taskId = nowId('cli_ddns');
  const taskLogFile = path.join(ddnsLogDir, `ddns_${taskId}.log`);

  try {
    const missing = runDockerPhp('/var/www/nav/cli/ddns_sync.php', ['__missing_cli_ddns__']);
    expect(missing.code).toBe(1);
    expect(missing.output).toContain('任务不存在');

    const dueTaskData = {
      version: 1,
      tasks: [
        {
          id: taskId,
          name: 'CLI DDNS Due Task',
          enabled: true,
          source: {
            type: 'local_ipv4',
            line: 'CT',
            pick_strategy: 'best_score',
            max_latency: 250,
            max_loss_rate: 5,
            fallback_type: '',
          },
          target: {
            domain: '',
            record_type: 'A',
            ttl: 120,
            skip_when_unchanged: true,
          },
          schedule: {
            cron: '* * * * *',
          },
          runtime: {
            running: false,
            last_run_at: '',
            last_status: '',
            last_message: '',
            last_value: '',
            started_at: '',
          },
        },
      ],
    };
    await fs.writeFile(ddnsTasksFile, JSON.stringify(dueTaskData, null, 2), 'utf8');

    const batch = runDockerPhp('/var/www/nav/cli/ddns_sync.php');
    expect(batch.code).toBe(1);
    expect(batch.output).toContain(`[${taskId}]`);

    const ddnsTasksAfter = JSON.parse(await fs.readFile(ddnsTasksFile, 'utf8')) as {
      tasks: Array<{ runtime?: { last_run_at?: string; last_message?: string } }>;
    };
    expect(ddnsTasksAfter.tasks[0]?.runtime?.last_run_at).toBeTruthy();
    expect(ddnsTasksAfter.tasks[0]?.runtime?.last_message).toBeTruthy();
    await expect(fs.access(taskLogFile)).resolves.toBeUndefined();
  } finally {
    await fs.rm(taskLogFile, { force: true }).catch(() => undefined);
    await restoreLocalFiles(snapshots);
  }
});

test('cli/run_scheduled_task validates task id and executes a seeded task', async () => {
  const snapshots = await snapshotLocalFiles([scheduledTasksFile]);
  const taskId = nowId('cli_task');
  const logFile = path.join(taskLogDir, `cron_${taskId}.log`);
  const taskScriptFile = path.join(taskWorkdirRoot, 'cli_scheduled_task.sh');

  try {
    const invalid = runDockerPhp('/var/www/nav/cli/run_scheduled_task.php');
    expect(invalid.code).toBe(1);
    expect(invalid.output).toContain('invalid task id');

    const missing = runDockerPhp('/var/www/nav/cli/run_scheduled_task.php', ['__missing_cli_task__']);
    expect(missing.code).toBe(1);
    expect(missing.output.trim()).toBe('');

    const seededTasks = {
      tasks: [
        {
          id: taskId,
          name: 'CLI Scheduled Task',
          script_filename: 'cli_scheduled_task.sh',
          enabled: true,
          schedule: '*/5 * * * *',
          command: 'pwd\necho cli-scheduled-ok',
        },
      ],
    };
    await fs.writeFile(scheduledTasksFile, JSON.stringify(seededTasks, null, 2), 'utf8');

    const success = runDockerPhp('/var/www/nav/cli/run_scheduled_task.php', [taskId]);
    expect(success.code).toBe(0);
    expect(success.stdout).toContain('/var/www/nav/data/tasks');
    expect(success.stdout).toContain('cli-scheduled-ok');
    expect(runDockerShell('test -d /var/www/nav/data/tasks').code).toBe(0);
    await expect(fs.readFile(taskScriptFile, 'utf8')).resolves.toContain('echo cli-scheduled-ok');

    const tasksAfter = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8')) as {
      tasks: Array<{ last_run?: string; last_code?: number; last_output?: string }>;
    };
    expect(tasksAfter.tasks[0]?.last_run).toBeTruthy();
    expect(tasksAfter.tasks[0]?.last_code).toBe(0);
    expect(tasksAfter.tasks[0]?.last_output).toContain('/var/www/nav/data/tasks');
    expect(tasksAfter.tasks[0]?.last_output).toContain('cli-scheduled-ok');
    await expect(fs.access(logFile)).resolves.toBeUndefined();
  } finally {
    await fs.rm(logFile, { force: true }).catch(() => undefined);
    await fs.rm(taskScriptFile, { force: true }).catch(() => undefined);
    await restoreLocalFiles(snapshots);
  }
});
