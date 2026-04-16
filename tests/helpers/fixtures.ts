import { test as base, expect, Page, Browser, BrowserContext } from '@playwright/test';
import { resetVolatileAppData } from './data';

export const test = base.extend({
  page: async ({ page }, use) => {
    await resetVolatileAppData();
    await use(page);
  },
});

export { expect, Page, Browser, BrowserContext };
