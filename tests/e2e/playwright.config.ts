import { defineConfig } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  timeout: 30_000,
  retries: 0,
  fullyParallel: false, // Tests modify shared state (wp-config, DB options)
  workers: 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: path.join(__dirname, 'playwright-report') }]],
  use: {
    baseURL: 'http://localhost:10003',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  globalSetup: path.join(__dirname, 'global-setup.ts'),
  projects: [
    {
      name: 'admin',
      use: { storageState: path.join(__dirname, '.auth/admin.json') },
    },
    {
      name: 'author',
      use: { storageState: path.join(__dirname, '.auth/author.json') },
      testMatch: '**/*author*.spec.ts',
    },
  ],
});
