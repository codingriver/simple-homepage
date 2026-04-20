import fs from 'fs/promises';
import path from 'path';
import { spawnSync } from 'child_process';

const containerName = process.env.APP_CONTAINER || 'simple-homepage';

type LocalSnapshot = Record<string, Buffer | null>;
type ContainerSnapshot = Record<string, { exists: boolean; contentBase64: string }>;

export function runDockerCommand(args: string[]) {
  const result = spawnSync('docker', args, {
    encoding: 'utf8',
    cwd: path.resolve(__dirname, '../..'),
  });
  return {
    code: result.status ?? 1,
    stdout: result.stdout ?? '',
    stderr: result.stderr ?? '',
    output: `${result.stdout ?? ''}${result.stderr ?? ''}`,
  };
}

export function runDockerPhp(scriptPath: string, args: string[] = []) {
  return runDockerCommand(['exec', containerName, 'php', scriptPath, ...args]);
}

export function runDockerPhpInline(code: string, args: string[] = []) {
  return runDockerCommand(['exec', containerName, 'php', '-r', code, ...args]);
}

export function runDockerShell(command: string) {
  return runDockerCommand(['exec', containerName, 'sh', '-lc', command]);
}

export async function snapshotLocalFiles(paths: string[]): Promise<LocalSnapshot> {
  const snapshot: LocalSnapshot = {};
  for (const file of paths) {
    snapshot[file] = await fs.readFile(file).catch(() => null);
  }
  return snapshot;
}

export async function restoreLocalFiles(snapshot: LocalSnapshot) {
  for (const [file, content] of Object.entries(snapshot)) {
    if (content === null) {
      await fs.rm(file, { force: true }).catch(() => undefined);
      continue;
    }
    await fs.mkdir(path.dirname(file), { recursive: true });
    await fs.writeFile(file, content);
  }
}

export async function snapshotContainerFiles(paths: string[]): Promise<ContainerSnapshot> {
  const snapshot: ContainerSnapshot = {};
  for (const file of paths) {
    const result = runDockerPhpInline(
      [
        '$p = $argv[1];',
        'if (!file_exists($p)) { echo "MISSING"; exit(0); }',
        'echo "EXISTS\\n";',
        'echo base64_encode((string)file_get_contents($p));',
      ].join(' '),
      [file]
    );
    if (result.code !== 0) {
      throw new Error(`无法读取容器文件 ${file}: ${result.output}`);
    }
    const output = result.stdout.trim();
    if (output === 'MISSING') {
      snapshot[file] = { exists: false, contentBase64: '' };
      continue;
    }
    const [marker, ...rest] = output.split('\n');
    if (marker !== 'EXISTS') {
      throw new Error(`未知容器文件快照格式 ${file}: ${output}`);
    }
    snapshot[file] = { exists: true, contentBase64: rest.join('') };
  }
  return snapshot;
}

export async function restoreContainerFiles(snapshot: ContainerSnapshot) {
  for (const [file, state] of Object.entries(snapshot)) {
    if (!state.exists) {
      const result = runDockerPhpInline('$p = $argv[1]; if (file_exists($p)) { unlink($p); }', [file]);
      if (result.code !== 0) {
        throw new Error(`无法删除容器文件 ${file}: ${result.output}`);
      }
      continue;
    }
    const result = runDockerPhpInline(
      [
        '$p = $argv[1];',
        '$data = base64_decode($argv[2], true);',
        'if ($data === false) { fwrite(STDERR, "decode failed\\n"); exit(1); }',
        '$dir = dirname($p);',
        'if (!is_dir($dir)) { mkdir($dir, 0777, true); }',
        'file_put_contents($p, $data);',
      ].join(' '),
      [file, state.contentBase64]
    );
    if (result.code !== 0) {
      throw new Error(`无法恢复容器文件 ${file}: ${result.output}`);
    }
  }
}

export function writeContainerFile(containerPath: string, content: string) {
  const base64 = Buffer.from(content).toString('base64');
  const result = runDockerPhpInline(
    [
      '$p = $argv[1];',
      '$data = base64_decode($argv[2], true);',
      'if ($data === false) { fwrite(STDERR, "decode failed\\n"); exit(1); }',
      '$dir = dirname($p);',
      'if (!is_dir($dir)) { mkdir($dir, 0777, true); }',
      'file_put_contents($p, $data, LOCK_EX);',
    ].join(' '),
    [containerPath, base64]
  );
  if (result.code !== 0) {
    throw new Error(`无法写入容器文件 ${containerPath}: ${result.output}`);
  }
  // Docker Desktop for Mac 下 osxfs 同步可能有延迟，执行 sync 帮助刷新
  runDockerCommand(['exec', containerName, 'sh', '-lc', 'sync']);
}

export function readContainerFile(containerPath: string): string {
  const result = runDockerPhpInline(
    [
      '$p = $argv[1];',
      'if (!file_exists($p)) { echo ""; exit(0); }',
      'echo file_get_contents($p);',
    ].join(' '),
    [containerPath]
  );
  if (result.code !== 0) {
    throw new Error(`无法读取容器文件 ${containerPath}: ${result.output}`);
  }
  return result.stdout;
}
