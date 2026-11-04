import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.', testMatch: '*.spec.ts', workers: 1, fullyParallel: false, retries: 0,
  timeout: 45000,
  reporter: [['list'], ['json', { outputFile: 'test-results/forms-results.json' }]],
  use: { baseURL: process.env.FORMS_QA_URL || 'http://127.0.0.1:9434',
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
    screenshot: 'only-on-failure', trace: 'retain-on-failure' }
});
