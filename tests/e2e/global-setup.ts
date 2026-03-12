/**
 * Global setup: authenticates admin and author users, saves browser state.
 * Reuses cached auth files if they are less than 30 minutes old.
 */
import { chromium, FullConfig } from '@playwright/test';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { BASE_URL } from './helpers/env';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const AUTH_DIR = path.join(__dirname, '.auth');
const MAX_AGE_MS = 30 * 60 * 1000; // 30 minutes

function isAuthFresh(statePath: string): boolean {
  if (!fs.existsSync(statePath)) return false;
  const stat = fs.statSync(statePath);
  return Date.now() - stat.mtimeMs < MAX_AGE_MS;
}

async function loginAndSave(
  baseURL: string,
  username: string,
  password: string,
  statePath: string
): Promise<void> {
  if (isAuthFresh(statePath)) return; // reuse cached auth

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', username);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');
  await page.waitForURL('**/wp-admin/**', { timeout: 60_000 });

  await context.storageState({ path: statePath });
  await browser.close();
}

export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL = BASE_URL;

  fs.mkdirSync(AUTH_DIR, { recursive: true });

  // Login as admin (parhumm)
  await loginAndSave(
    baseURL,
    'parhumm',
    'testpass123',
    path.join(AUTH_DIR, 'admin.json')
  );

  // Login as author (dordane)
  await loginAndSave(
    baseURL,
    'dordane',
    'testpass123',
    path.join(AUTH_DIR, 'author.json')
  );
}
