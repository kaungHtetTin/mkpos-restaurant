import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './tests', fullyParallel: false, workers: 1, timeout: 45000,
  use: { baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8001', channel: 'msedge', screenshot: 'only-on-failure', trace: 'retain-on-failure' },
  projects: [
    { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
    { name: 'phone', use: { viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true } },
  ],
});
