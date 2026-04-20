const fs = require('fs');
const path = require('path');

const testDir = path.resolve(__dirname, '../tests/e2e/full');
const files = fs.readdirSync(testDir).filter(f => f.endsWith('.spec.ts'));

const oldEnsure = `async function ensureInstalledHostAgent() {
  const result = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/host_agent_lib.php";',
      '$result = host_agent_install();',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(result.code).toBe(0);
  const payload = JSON.parse(result.stdout);
  expect(payload.ok).toBe(true);
}`;

const newEnsure = `async function ensureInstalledHostAgent() {
  let lastError = '';
  for (let attempt = 1; attempt <= 3; attempt++) {
    const result = runDockerPhpInline(
      [
        'require "/var/www/nav/admin/shared/host_agent_lib.php";',
        '$result = host_agent_install();',
        'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
      ].join(' ')
    );
    if (result.code === 0) {
      try {
        const payload = JSON.parse(result.stdout);
        if (payload.ok === true) {
          // 安装成功后短暂等待，让容器状态稳定
          await new Promise(r => setTimeout(r, 1000));
          return;
        }
        lastError = JSON.stringify(payload);
      } catch {
        lastError = 'JSON parse error: stdout=' + result.stdout + ', stderr=' + result.stderr;
      }
    } else {
      lastError = 'exit code ' + result.code + ': ' + result.output;
    }
    if (attempt < 3) {
      await new Promise(r => setTimeout(r, 2000));
    }
  }
  throw new Error('ensureInstalledHostAgent failed after 3 attempts: ' + lastError);
}`;

let changed = 0;
for (const file of files) {
  const fp = path.join(testDir, file);
  const content = fs.readFileSync(fp, 'utf8');
  if (!content.includes('ensureInstalledHostAgent')) continue;
  if (!content.includes(oldEnsure)) {
    // 尝试匹配变体（有些文件可能空格略有不同）
    const pattern = /async function ensureInstalledHostAgent\(\) \{\s*const result = runDockerPhpInline\(\s*\[\s*'require "\/var\/www\/nav\/admin\/shared\/host_agent_lib\.php";',\s*'\$result = host_agent_install\(\);',\s*'echo json_encode\(\$result, JSON_UNESCAPED_UNICODE\|JSON_UNESCAPED_SLASHES\);',\s*\]\.join\(' '\)\s*\);\s*expect\(result\.code\)\.toBe\(0\);\s*(?:const payload = JSON\.parse\(result\.stdout\);\s*expect\(payload\.ok\)\.toBe\(true\);|expect\(JSON\.parse\(result\.stdout\)\.ok\)\.toBe\(true\);)\s*\}/;
    if (!pattern.test(content)) {
      console.log('SKIP (pattern mismatch):', file);
      continue;
    }
    const newContent = content.replace(pattern, newEnsure);
    fs.writeFileSync(fp, newContent, 'utf8');
    changed++;
    console.log('FIXED:', file);
    continue;
  }
  const newContent = content.replace(oldEnsure, newEnsure);
  fs.writeFileSync(fp, newContent, 'utf8');
  changed++;
  console.log('FIXED:', file);
}

console.log('Total fixed:', changed);
