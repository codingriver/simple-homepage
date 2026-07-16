import fs from 'fs/promises';
import path from 'path';
import { runDockerShell, writeContainerFile } from './cli';

const dataDir = path.resolve(__dirname, '../../data');

/**
 * 重置易变应用数据，使每个 E2E 测试从尽可能干净的状态开始。
 * 保留 config.json / users.json / .installed 等全局配置和账号信息。
 */
export async function resetVolatileAppData(): Promise<void> {
  // 1. 重置核心 JSON 数据文件（不删除，而是清空内容；Docker Desktop for Mac
  // 下删除后容器内可能无法重新创建同名文件，写入到 overlay 而非 bind mount）。
  const jsonFilesToReset: Record<string, string> = {
    'api_tokens.json': '[]',
    'dns_config.json': '{}',
    'ddns_tasks.json': '[]',
    'scheduled_tasks.json': '[]',
    'notifications.json': '[]',
    'sessions.json': '{}',
    'backup_webdav.json': JSON.stringify({
      version: 1,
      instance_id: 'e2e000000001',
      enabled: false,
      name: 'WebDAV',
      base_url: '',
      remote_dir: '/RiverOps',
      ssrf_protection: false,
      tls_enabled: false,
      tls_verify: true,
      auth_enabled: false,
      auth_mode: 'basic',
      username: '',
      password: '',
      connect_timeout: 10,
      request_timeout: 300,
      remote_retention: 10,
    }, null, 2),

    'ip_locks.json': '{}',
  };

  // 重置 config.json 中易被测试污染的字段，保留其他全局配置
  try {
    const configPath = path.join(dataDir, 'config.json');
    const configRaw = await fs.readFile(configPath, 'utf8').catch(() => '{}');
    const config = JSON.parse(configRaw);
    const configContent = JSON.stringify(config, null, 2);
    await fs.writeFile(configPath, configContent, { mode: 0o644 });
    writeContainerFile('/var/www/riverops/data/config.json', configContent);
  } catch {
    // ignore
  }

  // 优先在容器内写入关键 JSON / 日志文件，避免 Docker Desktop for Mac 的 bind-mount 同步延迟
  // 导致容器内 PHP 读取到旧内容或空内容。
  for (const [f, content] of Object.entries(jsonFilesToReset)) {
    try {
      writeContainerFile(`/var/www/riverops/data/${f}`, content);
    } catch {
      // fallback to host
      try {
        await fs.writeFile(path.join(dataDir, f), content, { mode: 0o644 });
      } catch {
        // ignore
      }
    }
  }

  // ip_locks.json.lock
  try {
    writeContainerFile('/var/www/riverops/data/ip_locks.json.lock', '');
  } catch {
    try {
      await fs.writeFile(path.join(dataDir, 'ip_locks.json.lock'), '', { mode: 0o644 });
    } catch {
      // ignore
    }
  }

  // 2. 清空日志目录（容器内执行更可靠）
  try {
    runDockerShell('rm -f /var/www/riverops/data/logs/*.log /var/www/riverops/data/logs/*.lock /var/www/riverops/data/logs/*.gz 2>/dev/null; mkdir -p /var/www/riverops/data/logs');
  } catch {
    // fallback to host
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
    try {
      await fs.mkdir(logsDir, { recursive: true });
    } catch {
      // ignore
    }
  }

  // 在容器内预先创建空日志文件，确保 PHP 追加时不会报 "No such file or directory"
  const ensureEmptyFiles = [
    { file: 'sessions.json', content: '{}' },
    { file: 'ip_locks.json', content: '{}' },
    { file: 'ip_locks.json.lock', content: '' },
    { file: 'logs/auth.log', content: '' },
    { file: 'logs/audit.log', content: '' },
    { file: 'logs/dns.log', content: '' },
    { file: 'logs/dns_python.log', content: '' },
    { file: 'logs/task_dispatch.log', content: '' },
  ];
  for (const { file, content } of ensureEmptyFiles) {
    try {
      writeContainerFile(`/var/www/riverops/data/${file}`, content);
    } catch {
      const p = path.join(dataDir, file);
      try {
        if (!await fs.stat(p).then(() => true).catch(() => false)) {
          await fs.mkdir(path.dirname(p), { recursive: true });
          await fs.writeFile(p, content, { mode: 0o644 });
        }
      } catch {
        // ignore
      }
    }
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
  try {
    runDockerShell('rm -f /var/www/riverops/data/backups/jobs/*.json /var/www/riverops/data/backups/jobs/*.log /var/www/riverops/data/backups/tmp/* /var/www/riverops/data/backups/.webdav_job_start.lock 2>/dev/null; mkdir -p /var/www/riverops/data/backups/jobs /var/www/riverops/data/backups/tmp');
  } catch {
    // ignore: unit-level cleanup remains covered by PHP tests
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
