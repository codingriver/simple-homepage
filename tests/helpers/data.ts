import fs from 'fs/promises';
import path from 'path';

const dataDir = path.resolve(__dirname, '../../data');

/**
 * 重置易变应用数据，使每个 E2E 测试从尽可能干净的状态开始。
 * 保留 config.json / users.json / .installed 等全局配置和账号信息。
 */
export async function resetVolatileAppData(): Promise<void> {
  // 1. 清空核心 JSON 数据文件（删除即可，PHP 会回退到默认值）
  const jsonFilesToRemove = [
    'sites.json',
    'api_tokens.json',
    'health_cache.json',
    'dns_config.json',
    'ddns_tasks.json',
    'scheduled_tasks.json',
    'notifications.json',
    'task_templates.json',
    'sessions.json',
    'ssh_hosts.json',
    'ssh_keys.json',
    'expiry_scan.json',
    'file_favorites.json',
    'file_recent.json',
    'ip_locks.json',
    'ip_locks.json.lock',
    'webdav_accounts.json',
    'host_agent.json',
  ];

  for (const f of jsonFilesToRemove) {
    try {
      await fs.rm(path.join(dataDir, f), { force: true });
    } catch {
      // ignore
    }
  }

  // 2. 清空日志目录（保留目录本身）
  const logsDir = path.join(dataDir, 'logs');
  try {
    const logEntries = await fs.readdir(logsDir);
    for (const entry of logEntries) {
      if (entry.endsWith('.log') || entry.endsWith('.lock') || entry.endsWith('.gz')) {
        try {
          await fs.rm(path.join(logsDir, entry), { force: true });
        } catch {
          // ignore
        }
      }
    }
  } catch {
    // ignore
  }

  // 3. 清空备份目录（测试会自己创建和验证备份）
  const backupsDir = path.join(dataDir, 'backups');
  try {
    const backupEntries = await fs.readdir(backupsDir);
    for (const entry of backupEntries) {
      if (entry.endsWith('.json')) {
        try {
          await fs.rm(path.join(backupsDir, entry), { force: true });
        } catch {
          // ignore
        }
      }
    }
  } catch {
    // ignore
  }

  // 4. 清空 tasks 目录中的动态生成文件（保留内置二进制和数据文件）
  const tasksDir = path.join(dataDir, 'tasks');
  const keepInTasks = new Set([
    '.DS_Store',
    'cfst-linux-arm64',
    'cfst-linux-arm64-upx',
    'cfst-linux-x64',
    'cfst-linux-x64-upx',
    'cfst-macos-arm64',
    'cfst-macos-x64',
    'ip.txt',
    'ipv6.txt',
    'result.csv',
  ]);
  try {
    const taskEntries = await fs.readdir(tasksDir);
    for (const entry of taskEntries) {
      if (keepInTasks.has(entry)) continue;
      try {
        await fs.rm(path.join(tasksDir, entry), { force: true, recursive: true });
      } catch {
        // ignore
      }
    }
  } catch {
    // ignore
  }
}
