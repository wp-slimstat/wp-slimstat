<?php
/**
 * Records every query SlimStat issues, for the CI EXPLAIN gate.
 *
 * A pass-through *wrapper* around wpdb (it does not extend wpdb) that logs
 * SELECTs against SlimStat's tables before delegating. Installed by
 * explain-run.php by reassigning wp_slimstat::$wpdb — the single choke point
 * every SlimStat query passes through:
 *
 *     wp-slimstat.php:363   self::$wpdb = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);
 *     src/Utils/Query.php:60  $this->db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
 *
 * Capturing here — rather than at `slimstat_get_results_sql` — is deliberate.
 * That filter only fires inside wp_slimstat_db::get_results()/get_var(); every
 * query built through the Query builder (count_records, get_top, get_recent,
 * get_group_by, get_top_aggr, charts, goals, funnels) bypasses it entirely.
 * Wrapping the wpdb instance is the only way to see all of them.
 *
 * NOT SUITABLE FOR TIMING WORK. Attribute access goes through __get/__set and
 * non-overridden methods through __call, which is several times slower than
 * direct access. That is irrelevant here because this gate asserts query
 * *plans*, which are invariant to wrapper overhead — but anyone reusing this
 * class to measure durations would be measuring the wrapper.
 *
 * @package wp-slimstat-tests
 */

declare(strict_types=1);

if (!class_exists('SlimStat_Explain_Capture_WPDB')) {
    /**
     * Delegating wpdb wrapper that records SlimStat SELECTs.
     */
    class SlimStat_Explain_Capture_WPDB
    {
        /** @var wpdb */
        private $inner;

        /** @var array<string, array{sql: string, context: string}> keyed by query shape */
        private static $captured = [];

        public function __construct(wpdb $inner)
        {
            $this->inner = $inner;
        }

        /**
         * Distinct captured queries, one per shape.
         *
         * @return list<array{sql: string, context: string}>
         */
        public static function captured(): array
        {
            return array_values(self::$captured);
        }

        public static function reset(): void
        {
            self::$captured = [];
        }

        private function record(string $sql): void
        {
            // Only SELECTs have a plan worth checking, and only SlimStat's own
            // tables are in scope — core's queries are not ours to gate.
            if (stripos(ltrim($sql), 'SELECT') !== 0) {
                return;
            }
            if (stripos($sql, 'slim_stats') === false && stripos($sql, 'slim_events') === false) {
                return;
            }

            // Dedup on the literal SQL, not a normalised shape. Normalising
            // digits to '?' would collapse the 1/30/90-day renders into one
            // entry and silently discard two thirds of the coverage — the date
            // literals are exactly what distinguishes those plans.
            if (isset(self::$captured[$sql])) {
                return;
            }

            self::$captured[$sql] = [
                'sql'     => $sql,
                'context' => (string) ($GLOBALS['slimstat_explain_context'] ?? 'unknown'),
            ];
        }

        // wpdb's get_var/get_row/get_col/get_results all funnel through
        // $this->query() — but on the *inner* instance, so delegation alone
        // would never reach this class. Each needs an explicit override.
        public function query($query)
        {
            $this->record((string) $query);
            return $this->inner->query($query);
        }

        public function get_results($query = null, $output = OBJECT)
        {
            if ($query !== null) {
                $this->record((string) $query);
            }
            return $this->inner->get_results($query, $output);
        }

        public function get_row($query = null, $output = OBJECT, $y = 0)
        {
            if ($query !== null) {
                $this->record((string) $query);
            }
            return $this->inner->get_row($query, $output, $y);
        }

        public function get_col($query = null, $x = 0)
        {
            if ($query !== null) {
                $this->record((string) $query);
            }
            return $this->inner->get_col($query, $x);
        }

        public function get_var($query = null, $x = 0, $y = 0)
        {
            if ($query !== null) {
                $this->record((string) $query);
            }
            return $this->inner->get_var($query, $x, $y);
        }

        // Everything else delegates untouched, so the wrapper stays transparent
        // to prepare(), insert(), esc_like(), ->prefix, ->last_error, etc.
        public function __call($name, $args)
        {
            return $this->inner->{$name}(...$args);
        }

        public function __get($name)
        {
            return $this->inner->{$name};
        }

        public function __set($name, $value)
        {
            $this->inner->{$name} = $value;
        }

        public function __isset($name)
        {
            return isset($this->inner->{$name});
        }
    }
}
