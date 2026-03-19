/**
 * Global setup: authenticates admin and author users, saves browser state,
 * and installs all MU-plugins needed by the test suite.
 * Reuses cached auth files if they are less than 30 minutes old.
 */
import { chromium, FullConfig } from '@playwright/test';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { BASE_URL } from './helpers/env';
import { installAllTestMuPlugins, installCptMuPlugin } from './helpers/setup';

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

  // Install all MU-plugins once so individual specs don't need to manage them.
  // This prevents state contamination when one spec's afterAll removes a plugin
  // that the next spec expects to be present.
  installAllTestMuPlugins();
  installCptMuPlugin();

  fs.mkdirSync(AUTH_DIR, { recursive: true });

  // Login as admin — override via WP_ADMIN_USER / WP_ADMIN_PASS env vars.
  // CI default: admin / password (wp-env). Local default: parhumm / testpass123.
  const adminUser = process.env.WP_ADMIN_USER ?? 'parhumm';
  const adminPass = process.env.WP_ADMIN_PASS ?? 'testpass123';
  await loginAndSave(
    baseURL,
    adminUser,
    adminPass,
    path.join(AUTH_DIR, 'admin.json')
  );

  // Login as author — override via WP_AUTHOR_USER / WP_AUTHOR_PASS env vars.
  // Non-fatal; some test environments lack this user.
  const authorUser = process.env.WP_AUTHOR_USER ?? 'dordane';
  const authorPass = process.env.WP_AUTHOR_PASS ?? 'testpass123';
  try {
    await loginAndSave(
      baseURL,
      authorUser,
      authorPass,
      path.join(AUTH_DIR, 'author.json')
    );
  } catch (e) {
    console.warn('Author login failed, using admin fallback:', (e as Error).message);
    const adminPath = path.join(AUTH_DIR, 'admin.json');
    const authorPath = path.join(AUTH_DIR, 'author.json');
    if (fs.existsSync(adminPath) && !fs.existsSync(authorPath)) {
      fs.copyFileSync(adminPath, authorPath);
    }
  }
}
