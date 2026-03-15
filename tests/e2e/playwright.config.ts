import { defineConfig } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';
import { BASE_URL } from './helpers/env';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  timeout: 45_000,
  retries: process.env.CI ? 1 : 0,
  fullyParallel: false, // Tests modify shared state (wp-config, DB options)
  workers: 1,
  maxFailures: process.env.CI ? 10 : 0, // Fail-fast in CI; run all locally
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: path.join(__dirname, 'playwright-report') }],
    ['blob', { outputDir: path.join(__dirname, 'run-artifacts', 'blob') }],
    ['json', { outputFile: path.join(__dirname, 'run-artifacts', 'results.json') }],
  ],
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
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
