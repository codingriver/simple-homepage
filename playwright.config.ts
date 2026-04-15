import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BASE_URL || 'http://127.0.0.1:58080';
const reporter: any = process.env.PLAYWRIGHT_REPORTER === 'line'
  ? [['line']]
  : [
      ['list'],
      ['html', { open: 'never', outputFolder: 'test-results/playwright-report-html' }],
      ['./scripts/playwright-markdown-reporter.cjs', { outputFile: 'test-results/playwright-report.md' }],
    ];

export default defineConfig({
  testDir: './tests/e2e/full',
  outputDir: 'test-artifacts',
  timeout: 60_000,
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter,
  use: {
    baseURL,
    trace: 'retain-on-failure',
    video: 'off',
    screenshot: 'only-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 20_000,
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], channel: 'chrome' },
    },
    {
      name: 'mobile-chrome',
      use: { ...devices['Pixel 7'], channel: 'chrome' },
    },
  ],
  /* 默认执行全部 projects；可通过 --project=chromium 单独指定桌面端 */
});
