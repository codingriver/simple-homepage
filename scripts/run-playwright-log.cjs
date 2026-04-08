const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const defaultLogPath = '/tmp/playwright.log';
const providedArgs = process.argv.slice(2);
const firstArg = providedArgs[0] || '';
const logPath = firstArg && !firstArg.startsWith('-') ? firstArg : defaultLogPath;
const forwardedArgs = firstArg && !firstArg.startsWith('-') ? providedArgs.slice(1) : providedArgs;
const positionalArgs = forwardedArgs.filter((arg) => !arg.startsWith('-'));
const optionArgs = forwardedArgs.filter((arg) => arg.startsWith('-'));
const targetArgs = positionalArgs.length ? positionalArgs : ['tests/e2e/full'];

fs.mkdirSync(path.dirname(logPath), { recursive: true });
const logStream = fs.createWriteStream(logPath, { flags: 'w' });

const child = spawn(
  process.platform === 'win32' ? 'npx.cmd' : 'npx',
  [
    'playwright',
    'test',
    ...targetArgs,
    '--project=chromium',
    '--reporter=line',
    ...optionArgs,
  ],
  {
    env: {
      ...process.env,
      CI: '1',
      FORCE_COLOR: '0',
      PLAYWRIGHT_REPORTER: 'line',
    },
    stdio: ['inherit', 'pipe', 'pipe'],
  }
);

const writeChunk = (chunk, target) => {
  target.write(chunk);
  logStream.write(chunk);
};

child.stdout.on('data', (chunk) => writeChunk(chunk, process.stdout));
child.stderr.on('data', (chunk) => writeChunk(chunk, process.stderr));

const closeLogAndExit = (exitCode) => {
  logStream.write(`\nEXIT:${exitCode}\n`);
  logStream.end(() => process.exit(exitCode));
};

child.on('error', (error) => {
  const message = error instanceof Error ? error.stack || error.message : String(error);
  process.stderr.write(`${message}\n`);
  logStream.write(`${message}\n`);
  closeLogAndExit(1);
});

child.on('close', (code, signal) => {
  if (signal) {
    const signalMessage = `\nTERMINATED_BY_SIGNAL:${signal}\n`;
    process.stderr.write(signalMessage);
    logStream.write(signalMessage);
    closeLogAndExit(1);
    return;
  }
  closeLogAndExit(code ?? 1);
});
