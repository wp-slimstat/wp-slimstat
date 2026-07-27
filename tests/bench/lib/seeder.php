<?php
/**
 * Benchmark data seeder, driven by measured distributions.
 *
 * Uniform synthetic data flatters every index: uniform URLs give perfect
 * selectivity, uniform visit_ids give a bounce rate of zero, and a table with
 * no bots is ~28% smaller than reality. This seeder samples from
 * tests/bench/seed-profile.json, which is extracted from a real production
 * dump (see lib/extract-seed-profile.py), so the shapes that actually decide
 * query plans are preserved:
 *
 *   - URL skew — a handful of paths carry most traffic
 *   - true cardinality — the tail is synthesised up to the measured distinct
 *     count, because selectivity is what indexes live or die on
 *   - 92.9% single-pageview visits — bounce, exit and pages-per-visit reports
 *     are meaningless without it
 *   - ~27.7% bots, ~77.5% NULL referers, ~14.3% NULL fingerprints
 *
 * @package wp-slimstat-tests
 */

declare(strict_types=1);

if (!class_exists('SlimStat_Bench_Seeder')) {
    /**
     * Samples a value from a weighted [value, count] list in O(log n).
     */
    final class SlimStat_Bench_Weighted
    {
        /** @var list<string> */
        private $values = [];

        /** @var list<int> cumulative weights */
        private $cumulative = [];

        /** @var int */
        private $total = 0;

        /**
         * @param list<array{0: string, 1: int}> $pairs
         * @param int                            $distinct  synthesise filler up to this cardinality
         * @param callable(int): string|null     $filler    builds the i-th synthetic value
         */
        public function __construct(array $pairs, int $distinct = 0, ?callable $filler = null)
        {
            foreach ($pairs as $pair) {
                $this->push((string) $pair[0], max(1, (int) $pair[1]));
            }

            // The weighted list is truncated to a head of N values, but real
            // selectivity comes from the full distinct count. Synthesise the
            // missing tail with low weight so it lengthens the tail without
            // distorting the head.
            $missing = $distinct - count($pairs);
            if ($missing > 0 && $filler !== null) {
                for ($i = 0; $i < $missing; $i++) {
                    $this->push($filler($i), 1);
                }
            }
        }

        private function push(string $value, int $weight): void
        {
            $this->total   += $weight;
            $this->values[] = $value;
            $this->cumulative[] = $this->total;
        }

        public function isEmpty(): bool
        {
            return $this->total === 0;
        }

        public function pick(): string
        {
            if ($this->total === 0) {
                return '';
            }
            $target = random_int(1, $this->total);
            $lo     = 0;
            $hi     = count($this->cumulative) - 1;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($this->cumulative[$mid] < $target) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            return $this->values[$lo];
        }
    }

    final class SlimStat_Bench_Seeder
    {
        /** @var wpdb */
        private $db;

        /** @var array<string, mixed> */
        private $profile;

        /** @var array<string, SlimStat_Bench_Weighted> */
        private $pickers = [];

        /** @var list<array{0: int, 1: float}> */
        private $pv_per_visit;

        /** @var int */
        private $batch;

        public function __construct(wpdb $db, ?string $profile_path = null, int $batch = 2000)
        {
            $this->db    = $db;
            $this->batch = max(100, $batch);

            $path = $profile_path ?? dirname(__DIR__) . '/seed-profile.json';
            $raw  = @file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException("seed profile not readable: {$path}");
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || empty($decoded['weighted'])) {
                throw new RuntimeException("seed profile is not usable: {$path}");
            }
            $this->profile = $decoded;

            $fillers = [
                'resource'        => static fn(int $i): string => '/generated/page-' . $i . '/',
                'referer'         => static fn(int $i): string => 'https://referrer-' . $i . '.example/',
                'user_agent'      => static fn(int $i): string => 'Mozilla/5.0 (compatible; SeedAgent/' . $i . ')',
                'resolution'      => static fn(int $i): string => (800 + ($i % 1200)) . 'x' . (600 + ($i % 800)),
                'browser_version' => static fn(int $i): string => (string) (50 + ($i % 80)) . '.0',
                'language'        => static fn(int $i): string => 'x' . str_pad((string) ($i % 99), 2, '0', STR_PAD_LEFT),
            ];

            foreach ($this->profile['weighted'] as $column => $pairs) {
                $this->pickers[$column] = new SlimStat_Bench_Weighted(
                    $pairs,
                    (int) ($this->profile['distinct'][$column] ?? 0),
                    $fillers[$column] ?? null
                );
            }

            $this->pv_per_visit = $this->profile['pageviews_per_visit'] ?? [[1, 1.0]];
        }

        private function pick(string $column, string $default = ''): string
        {
            $picker = $this->pickers[$column] ?? null;
            return ($picker === null || $picker->isEmpty()) ? $default : $picker->pick();
        }

        private function nullRate(string $column, float $default = 0.0): float
        {
            return (float) ($this->profile['null_rates'][$column] ?? $default);
        }

        /** True with probability $p, using integer randomness. */
        private function chance(float $p): bool
        {
            return $p > 0 && random_int(1, 1000000) <= (int) round($p * 1000000);
        }

        /**
         * Build one SQL literal.
         *
         * Fields are escaped individually rather than through a single
         * prepare() over the whole tuple, because prepare('%s', null) emits an
         * empty string — not NULL. The seeded referer and fingerprint NULL
         * rates came out 0.0000 against measured rates of 77.5% and 14.3%,
         * which would have made every "is_empty" filter and every referrer
         * report behave nothing like production.
         *
         * @param string|int|null $value
         */
        private function literal($value): string
        {
            if ($value === null) {
                return 'NULL';
            }
            if (is_int($value)) {
                return (string) $value;
            }
            return "'" . $this->db->_real_escape((string) $value) . "'";
        }

        /** @param list<string|int|null> $fields */
        private function tuple(array $fields): string
        {
            return '(' . implode(',', array_map([$this, 'literal'], $fields)) . ')';
        }

        /** Pageviews in this visit, sampled from the measured histogram. */
        private function visitLength(): int
        {
            $roll = random_int(1, 1000000) / 1000000;
            $acc  = 0.0;
            foreach ($this->pv_per_visit as [$pv, $share]) {
                $acc += (float) $share;
                if ($roll <= $acc) {
                    return max(1, (int) $pv);
                }
            }
            return 1;
        }

        /**
         * Seed until the table holds at least $target rows.
         *
         * @param int           $target rows the table should end up with
         * @param int           $days   spread activity over this many days back from now
         * @param callable|null $log    receives progress lines
         * @return int rows inserted
         */
        public function seedTo(int $target, int $days = 365, ?callable $log = null): int
        {
            $table   = $this->db->prefix . 'slim_stats';
            $current = (int) $this->db->get_var("SELECT COUNT(*) FROM `{$table}`");
            $need    = $target - $current;
            if ($need <= 0) {
                // Still refresh statistics: on a re-run, or against a site that
                // already holds real data, stale stats make EXPLAIN lie — which
                // is the one thing this harness must not do.
                $this->db->query("ANALYZE TABLE `{$table}`");
                return 0;
            }

            $log && $log(sprintf(
                'seeding %s rows into %s (have %s, want %s)',
                number_format($need), $table, number_format($current), number_format($target)
            ));

            $now       = time();
            $window    = max(1, $days) * DAY_IN_SECONDS;
            $visit_id  = (int) $this->db->get_var("SELECT COALESCE(MAX(visit_id), 0) FROM `{$table}`");
            $bot_mix   = $this->profile['browser_type_mix'] ?? [];
            $bot_share = (float) ($bot_mix['1'] ?? 0.0);
            $prev_share = (float) ($bot_mix['2'] ?? 0.0);

            $ref_null = $this->nullRate('referer', 0.775);
            $fp_null  = $this->nullRate('fingerprint', 0.143);
            // content_type is never NULL in the source data; category and author
            // are, at 67.8% and 19.8%. Wiring category's rate into content_type
            // seeded it 67.8% NULL against a measured 0%, and left category and
            // author 100% NULL by omitting them from the INSERT — three columns
            // whose optimiser statistics were nothing like production.
            $ct_null  = $this->nullRate('content_type', 0.0);
            $cat_null = $this->nullRate('category', 0.678);
            $auth_null = $this->nullRate('author', 0.198);
            $mean_pv  = max(1.0, (float) ($this->profile['mean_pageviews_per_visit'] ?? 1.0));

            // Autocommit off keeps each batch to one fsync instead of one per
            // row; on a 1.5M-row seed that is the difference between minutes
            // and hours.
            $this->db->query('SET autocommit = 0');
            $inserted = 0;

            while ($inserted < $need) {
                $rows = [];
                while (count($rows) < $this->batch && $inserted + count($rows) < $need) {
                    $visit_id++;
                    $length     = $this->visitLength();
                    $visit_start = $now - random_int(0, $window);

                    // Per-visit attributes stay constant across its pageviews —
                    // otherwise visitor/browser/country reports see one visit as
                    // many distinct visitors.
                    $ua       = $this->pick('user_agent', 'Mozilla/5.0');
                    $browser  = $this->pick('browser', 'Chrome');
                    $version  = $this->pick('browser_version', '120.0');
                    $platform = $this->pick('platform', 'Win32');
                    $country  = $this->pick('country', 'us');
                    $language = $this->pick('language', 'en-us');
                    $screen   = $this->pick('resolution', '1920x1080');
                    // A referer belongs to the visit's ENTRY pageview — later
                    // hits are internal navigation. The profile measures a
                    // row-level rate, so the per-visit probability is scaled up
                    // by the mean visit length to land on that rate:
                    //   P(row has referer) = P(row is entry) x P(visit has referer)
                    // Applying the row rate per visit instead overshot NULLs to
                    // 0.82 against a measured 0.7755, because long visits
                    // amplify whichever way a single coin landed.
                    $visit_has_referer = $this->chance(min(1.0, (1.0 - $ref_null) * $mean_pv));
                    $referer  = $visit_has_referer ? $this->pick('referer', '') : null;
                    // The fingerprint VALUE is stable per visit, but whether a
                    // given hit carries one is decided per row: the tracker only
                    // records it when JS runs, so a visit legitimately mixes
                    // server-side hits (none) with JS hits (one). Deciding it
                    // once per visit biased the row-level rate upward, because
                    // long visits amplify whichever way the coin landed.
                    $fp_value = substr(md5((string) $visit_id), 0, 32);

                    $roll = random_int(1, 1000000) / 1000000;
                    $type = $roll <= $bot_share ? 1 : ($roll <= $bot_share + $prev_share ? 2 : 0);

                    [$w, $h] = array_pad(explode('x', $screen, 2), 2, '0');

                    // Deliberately not bounded by $need: truncating the last visit
                    // mid-way biased pageviews/visit downward. Overshoot is at most
                    // one visit, which is irrelevant to a benchmark target.
                    for ($i = 0; $i < $length; $i++) {
                        $dt = $visit_start + ($i * random_int(20, 600));
                        if ($dt > $now) {
                            $dt = $now;
                        }
                        $rows[] = $this->tuple([
                            // Documented RFC 5737 test range — never a real host,
                            // and the marker purge() keys off.
                            '203.0.113.' . random_int(1, 254),
                            $this->pick('resource', '/'),
                            $i === 0 ? $referer : null,
                            $browser,
                            $version,
                            $platform,
                            $country,
                            $type,
                            $language,
                            $this->chance($fp_null) ? null : $fp_value,
                            $ua,
                            $visit_id,
                            $dt,
                            // Only the last pageview of a visit carries dt_out,
                            // matching how the tracker updates dwell time.
                            $i === $length - 1 ? $dt + random_int(5, 900) : 0,
                            (int) $w,
                            (int) $h,
                            $screen,
                            $this->chance($ct_null) ? null : $this->pick('content_type', 'post'),
                            $this->chance($cat_null) ? null : 'category-' . random_int(1, 40),
                            $this->chance($auth_null) ? null : 'author-' . random_int(1, 12),
                            random_int(1, 5000),
                        ]);
                    }
                }

                $ok = $this->db->query(
                    "INSERT INTO `{$table}`
                        (ip, resource, referer, browser, browser_version, platform, country,
                         browser_type, language, fingerprint, user_agent, visit_id, dt, dt_out,
                         screen_width, screen_height, resolution, content_type, category, author,
                         content_id)
                     VALUES " . implode(',', $rows)
                );
                if ($ok === false) {
                    $this->db->query('ROLLBACK');
                    $this->db->query('SET autocommit = 1');
                    throw new RuntimeException('seed INSERT failed: ' . $this->db->last_error);
                }

                $inserted += count($rows);
                $this->db->query('COMMIT');

                // Query Monitor's db.php dropin defines SAVEQUERIES unconditionally,
                // and wpdb::log_query() then retains every INSERT string — roughly
                // 8 GB by the 10M-row tier. Drop them as we go.
                $this->db->queries = [];

                if ($log && $inserted % ($this->batch * 10) < $this->batch) {
                    $log(sprintf('  %s / %s rows', number_format($inserted), number_format($need)));
                }
            }

            $this->db->query('SET autocommit = 1');
            // Stale statistics make EXPLAIN lie about a table this size.
            $this->db->query("ANALYZE TABLE `{$table}`");

            $log && $log(sprintf('seeded %s rows', number_format($inserted)));
            return $inserted;
        }

        /**
         * Remove only rows this seeder created; leaves real data untouched.
         *
         * Chunked because there is no index on `ip`, so this is a full scan —
         * as one statement over 10M rows it would build an undo log larger than
         * the delete itself and roll back for longer than it ran.
         */
        public function purge(int $chunk = 50000): int
        {
            $table   = $this->db->prefix . 'slim_stats';
            $removed = 0;
            do {
                $n = (int) $this->db->query(
                    "DELETE FROM `{$table}` WHERE ip LIKE '203.0.113.%' LIMIT {$chunk}"
                );
                $removed += $n;
                $this->db->queries = [];
            } while ($n > 0);

            return $removed;
        }
    }
}
