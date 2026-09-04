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
        /**
         * The RFC 5737 documentation range — never a real host, so a row carrying it is one this
         * seeder wrote and purge() may delete. One owner because three statements depend on the
         * literals agreeing (the row builder, the event attach, and the purge itself), and a
         * safety claim enforced by three separate spellings is true only by coincidence.
         */
        private const MARKER_PREFIX = '203.0.113.';
        private const MARKER_LIKE   = '203.0.113.%';

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

            // An OVERLAY declares `extends` and carries only the keys it deliberately changes.
            // The base profile is provenance — extracted from a real dump — so an overlay states
            // its deltas beside its reasons instead of editing a measurement in place, where
            // nothing downstream could tell the difference.
            //
            // The chain is followed to its END, not one link. An earlier version resolved a
            // single `extends` and stopped, which was correct while exactly one overlay existed
            // and silently wrong the moment a second one stacked on the first: verify extends
            // i8 extends the measured profile, and resolving one link yields i8's deltas WITHOUT
            // the measured distributions underneath. That surfaces as "seed profile is not
            // usable" if you are lucky, and as a corpus built from an overlay's fragments if the
            // missing key happens not to be the one checked — which is PITFALLS 26's shape, a
            // fixture wearing a name it did not earn.
            $chain = [];
            while (is_array($decoded) && !empty($decoded['extends'])) {
                $base_path = dirname($path) . '/' . basename((string) $decoded['extends']);

                // A cycle would otherwise spin here forever with no output to say why.
                if (isset($chain[$base_path])) {
                    throw new RuntimeException("seed profile extends cycle at: {$base_path}");
                }
                $chain[$base_path] = true;

                $base_raw = @file_get_contents($base_path);
                if ($base_raw === false) {
                    throw new RuntimeException("overlay extends unreadable base: {$base_path}");
                }
                $base = json_decode($base_raw, true);
                if (!is_array($base)) {
                    throw new RuntimeException("overlay base is not usable: {$base_path}");
                }

                // One level deep, which is all the schema has: scalars and maps of scalars.
                // The nearer overlay wins, so merging the far one UNDER it preserves precedence
                // however long the chain gets.
                foreach ($decoded as $key => $value) {
                    if ('extends' === $key) {
                        continue;
                    }
                    if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                        $base[$key] = array_merge($base[$key], $value);
                        continue;
                    }
                    $base[$key] = $value;
                }

                $decoded = $base;
            }

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

        /**
         * A `verify` block share, 0.0 when the profile does not ask for it.
         *
         * Its own function rather than a nullRate() with a flag because the two read DIFFERENT
         * top-level sections — `null_rates` and `verify` — so merging them would need a section
         * argument at all nine call sites to save one four-line body.
         */
        private function share(string $key): float
        {
            return (float) ($this->profile['verify'][$key] ?? 0.0);
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

                // Events are attached on THIS path too. seedTo() means "make the corpus hold N
                // rows", which is declarative and re-runnable — so a table already at target must
                // still end up with the events the profile asks for, or seeding an existing
                // corpus under the verify overlay would leave the two event surfaces empty and
                // reinstate the vacuity this profile exists to remove. The pass converges rather
                // than repeating (see its NOT EXISTS), so calling it from both exits is safe.
                $this->seedEvents($log);

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

            // ── verify-overlay knobs, every one defaulting to OFF ──────────────────────────
            //
            // WHY these columns are seeded at all: seed-profile-verify.json, which is the file
            // that turns them on and states what each rate has to discriminate.
            //
            // WHY the default is 0.0, which is the half that lives in code: at the defaults every
            // column below is written as NULL — exactly what the table held when the column was
            // absent from the INSERT — so a profile without a `verify` block seeds a corpus
            // byte-identical to the one every prior measurement used. A fixture that changed
            // under existing callers would invalidate the runs that already cited it.
            $ip_null     = $this->nullRate('ip');
            $outbound    = $this->share('outbound');
            $searchterms = $this->share('searchterms');
            $loggedin    = $this->share('loggedin');
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
                        // Only the ENTRY pageview carries searchterms, for the same reason the
                        // referer does: a search landing is how the visit began, not something
                        // that recurs on internal navigation.
                        $terms = ($i === 0 && $this->chance($searchterms))
                            ? 'query ' . random_int(1, 400)
                            : null;

                        // `loggedin:` is what UserOverview's last-login report greps for, and it
                        // needs a username on the same row to group by. Deriving the note FROM
                        // the username makes "both or neither" structural instead of two
                        // expressions that have to agree.
                        $user = $this->chance($loggedin) ? 'user-' . random_int(1, 40) : null;

                        // NULL at the overlay's rate: a pageview with no ip is what separates
                        // count(ip) from count(*), and without one the difference between them is
                        // unobservable — R16 could not fail.
                        //
                        // A NULL ip would also be INVISIBLE to purge(), because `NULL LIKE '…'`
                        // is NULL rather than true — so those rows would survive every purge,
                        // and seedTo()'s COUNT(*) would then count the residue toward the next
                        // run's target. The marker moves to other_ip on exactly those rows, so
                        // "only rows this seeder created" stays a fact rather than a hope. No
                        // captured surface reads other_ip.
                        $no_ip = $this->chance($ip_null);

                        $rows[] = $this->tuple([
                            $no_ip ? null : self::MARKER_PREFIX . random_int(1, 254),
                            $no_ip ? self::MARKER_PREFIX . random_int(1, 254) : null,
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
                            // Outbound links belong to any pageview, not just the entry: a
                            // visitor clicks away from whichever page they were on.
                            $this->chance($outbound) ? 'https://outbound-' . random_int(1, 60) . '.example/' : null,
                            $terms,
                            null === $user ? null : 'loggedin:' . $user,
                            $user,
                        ]);
                    }
                }

                $ok = $this->db->query(
                    "INSERT INTO `{$table}`
                        (ip, other_ip, resource, referer, browser, browser_version, platform, country,
                         browser_type, language, fingerprint, user_agent, visit_id, dt, dt_out,
                         screen_width, screen_height, resolution, content_type, category, author,
                         content_id, outbound_resource, searchterms, notes, username)
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

            $this->seedEvents($log);

            return $inserted;
        }

        /**
         * Attach events to already-seeded pageviews — the second half of the vacuity fix.
         *
         * `get_recent_events` and `get_top_events` returned EMPTY on both arms of every run,
         * which compares equal and proves nothing about either. They were not broken; the
         * corpus simply had no events to find, and the harness had no way to tell those two
         * states apart.
         *
         * A SEPARATE PASS, not a column on the pageview INSERT, because slim_events carries a
         * FOREIGN KEY onto slim_stats.id: a row can only be attached to a pageview that already
         * exists and whose id the database has assigned. It also runs INSERT..SELECT rather than
         * building tuples in PHP, so the ids come from the table instead of from an assumption
         * about auto-increment.
         *
         * The description index is `(id DIV 2) % 12`, NOT `id % 12`. With the plain modulus the
         * kind is decided by parity and the description by the same id, so every custom event
         * landed on an odd index and `get_top_events` — which groups by NOTES, not by
         * event_description — saw six distinct groups rather than twelve. Six is below the
         * report's row limit, so its LIMIT never bound and a truncation defect could not show.
         * DIV 2 first makes the two independent, which is what the sibling outbound knob already
         * reasons about explicitly ("60 targets against a LIMIT 20 puts the cut INSIDE the data")
         * and what this one claimed without delivering.
         *
         * TWO KINDS OF EVENT, and the split is the fixture's whole point. Both event reports
         * exclude notes beginning `type:click` — get_top_events says so twice, once per branch
         * (`notes NOT LIKE "type:click%"`, and `te.notes NOT LIKE "_ype:click%"` when a column
         * filter is active). A first version of this method seeded ONLY click events, so both
         * reports stayed empty on both arms and the vacuity it was written to fix survived it
         * intact — the fixture produced rows the reports are designed never to return.
         *
         * So: odd ids get a custom event (`event:…`), which is what those two reports display;
         * even ids get a click (`type:click,…`) carrying coordinates, which is what Pro's heat
         * map filters for with `position LIKE '%,%'`. Seeding both means the exclusion is
         * EXERCISED rather than merely satisfied — a report that stopped excluding clicks would
         * roughly double its count instead of staying green, which is the question PITFALLS 22
         * says to ask of any fixture: what number does it produce with the defect, and without.
         */
        private function seedEvents(?callable $log = null): void
        {
            $share = $this->share('events');
            if ($share <= 0) {
                return;
            }

            $stats  = $this->db->prefix . 'slim_stats';
            $events = $this->db->prefix . 'slim_events';

            if ((string) $this->db->get_var("SHOW TABLES LIKE '{$events}'") !== $events) {
                $log && $log('  events: no slim_events table on this install — skipped');
                return;
            }

            // Only ever attaches to rows this seeder wrote (the RFC 5737 marker purge() keys
            // off), so a bench run against an install holding real data cannot decorate it.
            $pct = max(1, (int) round($share * 100));
            $ok  = $this->db->query(
                "INSERT INTO `{$events}` (type, event_description, notes, position, id, dt)
                 SELECT CASE WHEN (s.id % 2) = 1 THEN 2 ELSE 1 END,
                        CONCAT('event-', ((s.id DIV 2) % 12)),
                        CASE WHEN (s.id % 2) = 1
                             THEN CONCAT('event:action-', ((s.id DIV 2) % 12))
                             ELSE CONCAT('type:click,event:', ((s.id DIV 2) % 12))
                        END,
                        CONCAT(10 + (s.id % 900), ',', 10 + (s.id % 600)),
                        s.id,
                        s.dt
                   FROM `{$stats}` s
                  WHERE (s.ip LIKE '" . self::MARKER_LIKE . "' OR s.other_ip LIKE '" . self::MARKER_LIKE . "')
                    AND (s.id % 100) < {$pct}
                    AND NOT EXISTS (SELECT 1 FROM `{$events}` e WHERE e.id = s.id)"
            );

            if ($ok === false) {
                throw new RuntimeException('event seed INSERT failed: ' . $this->db->last_error);
            }

            // Same reason seedTo() analyzes slim_stats, and the same cost: this table just went
            // from empty to tens of thousands of rows, and get_recent_events is a JOIN whose side
            // the optimiser picks from row estimates. innodb_stats_auto_recalc runs on a
            // background thread, so an EXPLAIN issued moments later can still hold the
            // empty-table estimate — which is a plan chosen from a fact that stopped being true.
            $this->db->query("ANALYZE TABLE `{$events}`");
            $this->db->queries = [];
            $log && $log(sprintf('  events: %s rows attached', number_format((int) $ok)));
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
                    // BOTH columns: the verify overlay nulls `ip` on a share of the seeder's own
                    // rows, and `NULL LIKE '…'` is NULL rather than true, so keying on ip alone
                    // would leave those rows behind for ever — and seedTo()'s COUNT(*) would then
                    // count the residue toward the next run's target. Those rows carry the marker
                    // in other_ip instead, which is why ownership is asked as a question about
                    // either column.
                    "DELETE FROM `{$table}`
                      WHERE (ip LIKE '" . self::MARKER_LIKE . "' OR other_ip LIKE '" . self::MARKER_LIKE . "')
                      LIMIT {$chunk}"
                );
                $removed += $n;
                $this->db->queries = [];
            } while ($n > 0);

            return $removed;
        }
    }
}
