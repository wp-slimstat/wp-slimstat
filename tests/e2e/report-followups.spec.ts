import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { runWordPressFixture } from './helpers/chart';

for (const [name, path] of [
  ['WooCommerce referral classification preserves revenue @woocommerce', './helpers/attribution-referrers.php'],
  ['Backdated heatmap capture is counted once', '../fixtures/heatmap-history-overlap.php'],
]) {
  test(name, async () => {
    const answer = JSON.parse(runWordPressFixture(readFileSync(new URL(path, import.meta.url), 'utf8')));
    expect(answer.result).toBe('pass');
  });
}
