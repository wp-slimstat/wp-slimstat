/**
 * Launcher for @wp-playground/cli that fixes IPv6 DNS timeout on macOS.
 * Node's undici (fetch) tries IPv6 first and times out on networks without IPv6.
 * This sets IPv4-first before importing the CLI.
 *
 * Usage: node tests/e2e/playground-start.mjs [--blueprint] [extra args...]
 */
import dns from 'node:dns';
dns.setDefaultResultOrder('ipv4first');

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');
const cli = path.join(root, 'node_modules', '.bin', 'wp-playground-cli');

const args = ['start', '--skip-browser', '--port=9400'];
if (process.argv.includes('--blueprint')) {
  args.push('--blueprint=tests/e2e/blueprint.json');
}

// Pass through any extra args (e.g. --wp=6.7 --php=8.1 --reset)
const extra = process.argv.slice(2).filter(a => a !== '--blueprint');
args.push(...extra);

// Merge the DNS flag into any existing NODE_OPTIONS instead of replacing it.
const dnsFlag = '--dns-result-order=ipv4first';
const existingNodeOpts = process.env.NODE_OPTIONS || '';
const mergedNodeOpts = existingNodeOpts.includes(dnsFlag)
  ? existingNodeOpts
  : `${dnsFlag} ${existingNodeOpts}`.trim();

execFileSync(cli, args, {
  cwd: root,
  stdio: 'inherit',
  env: { ...process.env, NODE_OPTIONS: mergedNodeOpts },
});
