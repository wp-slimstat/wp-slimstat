/**
 * Back up the analytics tables before the suite truncates them.
 *
 * setup.ts:521 and chart.ts:151 TRUNCATE wp_slim_stats and wp_slim_events, and 75 spec
 * files reach that code. assertSafeTestDatabase() refuses to let them near a real
 * database — unless ALLOW_LIVE_DB=1, which is the one door to the parity dataset the
 * release gate is measured against.
 *
 * That door had a written instruction behind it ("back up wp_slim_stats before any E2E
 * run") and nothing else. A written instruction is not a mechanism: it only works while
 * somebody remembers, and the whole point of the flag is that it is used when someone is
 * in a hurry. So the backup happens here, at the choke point, on the one path where
 * there is anything to lose.
 *
 * Refuses rather than proceeds when the backup cannot be taken. Destroying 443,535 rows
 * because a helper script moved is not a trade worth making silently.
 */
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/** jaan-to/bin/slimstat-db.sh, relative to this file inside the submodule. */
const DUMP_SCRIPT = path.resolve(__dirname, '../../../../jaan-to/bin/slimstat-db.sh');

export function backupAnalyticsTables(): void {
  // CI runs against an ephemeral wp-env database that is destroyed with the job, and
  // the guard already exempts it. Nothing to protect.
  if (process.env.CI === 'true') return;

  // Every other path is already refused by assertSafeTestDatabase(), so there is no
  // real dataset in reach and a dump would only be noise.
  if (process.env.ALLOW_LIVE_DB !== '1') return;

  if (process.env.E2E_SKIP_BACKUP === '1') {
    console.warn(
      '⚠  E2E_SKIP_BACKUP=1 — running against a live database with NO backup. ' +
        'wp_slim_stats and wp_slim_events will be truncated.'
    );
    return;
  }

  if (!fs.existsSync(DUMP_SCRIPT)) {
    throw new Error(
      `E2E backup guard: ALLOW_LIVE_DB=1 is set, so this run will TRUNCATE wp_slim_stats and ` +
        `wp_slim_events — but the dump helper is missing at ${DUMP_SCRIPT}, so no backup can be ` +
        `taken. Restore it, or set E2E_SKIP_BACKUP=1 if you genuinely do not need this data.`
    );
  }

  try {
    const out = execFileSync('bash', [DUMP_SCRIPT, 'dump'], { encoding: 'utf8' });
    console.log(`✓ analytics tables backed up before truncation — ${out.trim()}`);
  } catch (e) {
    throw new Error(
      'E2E backup guard: the pre-run dump failed, so this run was stopped before it could ' +
        'truncate the analytics tables. ' +
        `Fix the dump (${DUMP_SCRIPT} dump) or set E2E_SKIP_BACKUP=1 to proceed without one.\n` +
        (e as Error).message
    );
  }
}
