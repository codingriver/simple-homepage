import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BASE_URL || 'http://127.0.0.1:58080';

export default defineConfig({
  testDir: './tests/e2e/full',
  outputDir: 'test-artifacts',
  timeout: 60_000,
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'test-results/playwright-report-html' }],
    ['./scripts/playwright-markdown-reporter.cjs', { outputFile: 'test-results/playwright-report.md' }],
  ],
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
});
