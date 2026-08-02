<?php
declare(strict_types=1);

namespace SlimStat\Schema;

use wpdb;

/**
 * The analytics schema, declared once.
 *
 * Before this class there were six creators of the same objects — the inline `INDEX` clauses in
 * `init_tables()`'s `CREATE TABLE`, `init_environment()`'s four probe-and-create blocks,
 * `init_tables()`'s own `$index_defs`, the version-gated `ALTER` block for sites below 4.8.4,
 * the goals/funnels block, the eight `Create*Index` migration classes, and
 * `get_index_definitions()` behind the AJAX retry UI. None of them could see the others.
 *
 * The consequence was not untidiness. `init_tables()` emits raw `CREATE TABLE IF NOT EXISTS`,
 * which can only create and never reconcile, and the two paths are mutually exclusive *by
 * construction*: a fresh install stamps the current version before any admin page renders, so
 * the upgrade gate is false forever; an update never fires activation, so the create gate is
 * false forever. Counted on the reference tree, a fresh install got 11 secondary indexes on
 * `slim_stats` and an upgraded one got 13 — and the three goal/funnel/events indexes existed
 * only on the upgrade path while the five `$index_defs` indexes existed only on the create
 * path. That is at least four schema states, not two, and every measured index or row-read
 * number on this branch was baselined against whichever one the reference install happened to
 * be (C39).
 *
 * WHAT THIS CLASS IS, AND IS NOT
 *
 * It is a declaration plus an idempotent `ensure()`. It is deliberately NOT a migration
 * framework: `SlimStat\Migration\` already is one, `ensure()` is called from inside the callers
 * that already hold the schema lock, and adding a second runner is exactly the mistake C13
 * flagged.
 *
 * DEPENDENCY-FREE ON PURPOSE
 *
 * Nothing here calls a WordPress function at require time, and the declaration half calls none
 * at all. `uninstall.php` runs with the plugin unloaded and no autoloader — it `require_once`s
 * this file directly — and the PHP-only CI lanes have no vendor tree. The moment the inventory
 * needs a bootstrapped WordPress to read, the one file that most needs it can no longer see it,
 * and C16 comes straight back.
 *
 * ENGINE (C42)
 *
 * `ENGINE=InnoDB` is emitted unconditionally. The old code asked `SHOW VARIABLES LIKE
 * 'have_innodb'`, a variable MySQL removed in 5.6.1, so the answer was empty on every modern
 * server and the clause was dropped from all three `CREATE TABLE`s. Every install therefore
 * took the host's `default_storage_engine`, and on a MyISAM host the `slim_events` FOREIGN KEY
 * is silently ignored — while Phase G's own migration hard-refuses MyISAM. v6 would have
 * refused to migrate an install v6 itself created.
 *
 * CHARSET (C11b/C40)
 *
 * Fresh installs are born at utf8mb4, resolved from `wp_users.user_login` per ADR-5 — the same
 * resolution `ConvertTablesToUtf8mb4` uses, sharing this method so the two cannot disagree.
 * Matching the *database default* instead is what turns that migration into
 * ER_CANT_AGGREGATE_2COLLATIONS on Pro's user join. Creating at the target costs nothing (no
 * rows, no lock, no rebuild), and ADR-6 constrains *converting* an existing table, not creating
 * a new one. The 22 VARCHAR columns declare 13,913 characters — 55,652 bytes at 4 bytes/char,
 * inside the 65,535-byte row limit with ~9,800 bytes to spare, so no column needs right-sizing
 * to get there. `tests/schema-single-source-test.php` recomputes both margins from this
 * manifest rather than trusting the sentence you just read.
 */
final class Schema
{
    /**
     * Unconditional. See C42 in the class docblock — this is the whole fix.
     */
    public const ENGINE = 'InnoDB';

    /**
     * Used when `wp_users.user_login` cannot be inspected — an external-DB install where the
     * core tables are on another server, or a probe that errored. Shared with
     * ConvertTablesToUtf8mb4, which had its own copy of this constant.
     */
    public const FALLBACK_COLLATION = 'utf8mb4_unicode_ci';

    /**
     * Index names are stored with a literal `{prefix}` marker rather than being uniformly
     * prefixed, because the existing names are NOT uniform: six carry the table prefix and
     * seven do not. Normalising them would rename indexes on every install in the field — a
     * DROP and a rebuild of a 47.8 MB index, to fix nothing a user can see.
     */
    private const PREFIX_MARKER = '{prefix}';

    /**
     * The whole schema. Order is FK-safe for DROP: children before parents.
     *
     * `slim_stats_archive` is created `LIKE slim_stats` — which copies columns and indexes but
     * NOT foreign keys, exactly what an archive wants — so its columns are declared by
     * reference and cannot drift. `slim_events_archive` is spelled out instead of `LIKE
     * slim_events` for one reason: its dt index has a different NAME on every install in the
     * field, and `LIKE` would copy the source's name.
     *
     * @var array<string,array<string,mixed>>
     */
    private const TABLES = [
        'slim_events' => [
            'columns' => [
                'event_id'          => 'INT(10) NOT NULL AUTO_INCREMENT',
                'type'              => 'TINYINT UNSIGNED DEFAULT 0',
                'event_description' => 'VARCHAR(64) DEFAULT NULL',
                'notes'             => 'VARCHAR(256) DEFAULT NULL',
                'position'          => 'VARCHAR(32) DEFAULT NULL',
                'id'                => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'dt'                => 'INT(10) UNSIGNED DEFAULT 0',
            ],
            'primary'     => 'event_id',
            'foreign_key' => [
                'name'       => 'fk_{prefix}slim_events_id',
                'column'     => 'id',
                'references' => 'slim_stats',
                'on'         => 'id',
            ],
            'indexes' => [
                '{prefix}slim_stat_events_idx' => 'dt',
                'idx_events_notes_dt'          => 'dt, notes(64)',
            ],
        ],

        'slim_events_archive' => [
            'like_columns' => 'slim_events',
            'primary'      => 'event_id',
            'indexes'      => [
                '{prefix}slim_stat_events_archive_idx' => 'dt',
            ],
        ],

        'slim_stats' => [
            'columns' => [
                'id'                => 'INT UNSIGNED NOT NULL auto_increment',
                'ip'                => 'VARCHAR(39) DEFAULT NULL',
                'other_ip'          => 'VARCHAR(39) DEFAULT NULL',
                'username'          => 'VARCHAR(256) DEFAULT NULL',
                'email'             => 'VARCHAR(256) DEFAULT NULL',
                'country'           => 'VARCHAR(16) DEFAULT NULL',
                'location'          => 'VARCHAR(36) DEFAULT NULL',
                'city'              => 'VARCHAR(256) DEFAULT NULL',
                'referer'           => 'VARCHAR(2048) DEFAULT NULL',
                'resource'          => 'VARCHAR(2048) DEFAULT NULL',
                'searchterms'       => 'VARCHAR(2048) DEFAULT NULL',
                'notes'             => 'VARCHAR(2048) DEFAULT NULL',
                'visit_id'          => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'server_latency'    => 'INT(10) UNSIGNED DEFAULT 0',
                'page_performance'  => 'INT(10) UNSIGNED DEFAULT 0',
                'browser'           => 'VARCHAR(40) DEFAULT NULL',
                'browser_version'   => 'VARCHAR(15) DEFAULT NULL',
                'browser_type'      => 'TINYINT UNSIGNED DEFAULT 0',
                'platform'          => 'VARCHAR(15) DEFAULT NULL',
                'language'          => 'VARCHAR(5) DEFAULT NULL',
                'fingerprint'       => 'VARCHAR(256) DEFAULT NULL',
                'user_agent'        => 'VARCHAR(2048) DEFAULT NULL',
                'resolution'        => 'VARCHAR(12) DEFAULT NULL',
                'screen_width'      => 'SMALLINT UNSIGNED DEFAULT 0',
                'screen_height'     => 'SMALLINT UNSIGNED DEFAULT 0',
                'content_type'      => 'VARCHAR(64) DEFAULT NULL',
                'category'          => 'VARCHAR(256) DEFAULT NULL',
                'author'            => 'VARCHAR(64) DEFAULT NULL',
                'content_id'        => 'BIGINT(20) UNSIGNED DEFAULT 0',
                'outbound_resource' => 'VARCHAR(2048) DEFAULT NULL',
                'tz_offset'         => 'SMALLINT DEFAULT 0',
                'dt_out'            => 'INT(10) UNSIGNED DEFAULT 0',
                'dt'                => 'INT(10) UNSIGNED DEFAULT 0',
            ],
            'primary' => 'id',
            'indexes' => [
                // Present inline in the CREATE TABLE since 4.8.4, so on every install born
                // after it — and added by the version-gated ALTER block on every install born
                // before. Both paths now read these six from here.
                '{prefix}slim_stats_dt_idx'         => 'dt',
                '{prefix}stats_resource_idx'        => 'resource(20)',
                '{prefix}stats_browser_idx'         => 'browser(10)',
                '{prefix}stats_searchterms_idx'     => 'searchterms(15)',
                '{prefix}stats_fingerprint_idx'     => 'fingerprint(20)',
                '{prefix}stats_dt_visit_idx'        => 'dt, visit_id',
                // Added by $index_defs on the create path only, so absent from any install
                // born before 5.4.0 that has not since been reactivated.
                'idx_country_dt'                    => 'country, dt',
                'idx_dt_screen_width_screen_height' => 'dt, screen_width, screen_height',
                'idx_dt_browser_browser_version'    => 'dt, browser, browser_version',
                'idx_dt_platform'                   => 'dt, platform',
                'idx_dt_out'                        => 'dt_out',
                // Added on the upgrade path only, so absent from every fresh install — the
                // clearest single instance of the divergence C39 measured.
                //
                // `resource(191)` is 764 bytes at utf8mb4, three under the 767-byte COMPACT
                // prefix limit. The gate recomputes that margin; do not widen it by hand.
                'idx_goal_queries'                  => 'resource(191), dt, fingerprint(20)',
                'idx_funnel_queries'                => 'fingerprint(20), dt, resource(191)',
            ],
        ],

        'slim_stats_archive' => [
            'like'    => 'slim_stats',
            'primary' => 'id',
            // Index set INHERITED from slim_stats via `like` — see indexes(). Declared that way
            // rather than as an empty list because `CREATE TABLE ... LIKE` copies the source's
            // indexes, so an empty list would be the manifest asserting something false about
            // every archive table in existence.
            //
            // NOT reconciled, and that is a deliberate limitation rather than an oversight.
            // `LIKE` is a one-time copy: an archive created in 5.2 froze at whatever slim_stats
            // had then, and the several ALTERs since never reached it. So archives in the field
            // genuinely diverge. Repairing them means building up to thirteen indexes on a
            // cold-storage table during `admin_init` — which is not a refactor, it is an E-phase
            // decision, and E1 may well conclude the archive should carry FEWER indexes than
            // slim_stats rather than the same thirteen. Nothing reads the archive on a report
            // path. Recorded in STATE.json against E1 so it is decided, not inherited.
            'reconcile' => false,
        ],
    ];

    /**
     * Tables this plugin created in earlier versions and no longer creates, with the prefix each
     * is named under.
     *
     * They are not part of the live manifest — nothing reconciles them and nothing should
     * recreate them — but uninstall must still remove them from installs old enough to have
     * them, and that list has to live beside the live one or it is the thing nobody remembers.
     *
     * The scope is data, not a comment, because it is the whole reason there were two lists:
     * the pre-5.0 dimension tables were network-wide (`base_prefix`), while `slim_outbound` and
     * the 3.x-era `_3` pair are per-blog (`prefix`). Splitting on that difference is what left
     * three table names hardcoded in `uninstall.php` after the first pass at C16 — the file
     * C16 names as the one that cannot see the inventory still held half of it.
     *
     * @var array<string,string>
     */
    private const LEGACY_TABLES = [
        'slim_browsers'        => 'base_prefix',
        'slim_screenres'       => 'base_prefix',
        'slim_content_info'    => 'base_prefix',
        'slim_outbound'        => 'prefix',
        'slim_stats_3'         => 'prefix',
        'slim_stats_archive_3' => 'prefix',
    ];

    /**
     * Indexes the user can switch off, and the setting that switches them.
     *
     * Not a refinement — a correctness requirement discovered by the gate. Settings → Maintenance
     * → "Database Indexes" DROPs these four when toggled off, and it has done since 4.8.4. An
     * `ensure()` that reconciled every index unconditionally would silently rebuild them on the
     * next `admin_init`, turning a working toggle into a no-op that reverses itself — a product
     * regression introduced by a refactor whose entire purpose was to change no behaviour.
     *
     * Declaring the group here rather than leaving the DROP list in the settings page is what
     * makes the toggle and the reconciler agree by construction: both read this map, so an index
     * cannot be optional to one and mandatory to the other.
     *
     * @var array<string,string>
     */
    private const OPTIONAL_INDEXES = [
        '{prefix}stats_resource_idx'    => 'db_indexes',
        '{prefix}stats_browser_idx'     => 'db_indexes',
        '{prefix}stats_searchterms_idx' => 'db_indexes',
        '{prefix}stats_fingerprint_idx' => 'db_indexes',
    ];

    /**
     * Index name => the `slimstat_*_indexed` option the admin notice reads.
     *
     * Bookkeeping for the pre-migration-framework retry UI (`show_indexes_notice()` and its
     * AJAX handlers). Kept keyed here rather than beside each caller so that adding an index to
     * the manifest cannot leave a notice permanently offering to create something that already
     * exists.
     *
     * @var array<string,string>
     */
    private const INDEX_OPTIONS = [
        'idx_country_dt'                    => 'slimstat_country_dt_indexed',
        'idx_dt_screen_width_screen_height' => 'slimstat_dt_screen_indexed',
        'idx_dt_browser_browser_version'    => 'slimstat_dt_browser_indexed',
        'idx_dt_platform'                   => 'slimstat_dt_platform_indexed',
        'idx_dt_out'                        => 'slimstat_dt_out_indexed',
        '{prefix}stats_dt_visit_idx'        => 'slimstat_dt_visit_indexed',
    ];

    /**
     * Table suffixes in FK-safe DROP order: children first.
     *
     * @return string[]
     */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Creation order: every table after the tables it depends on.
     *
     * NOT the reverse of the drop order, which is what this method returned in its first draft.
     * The two orders answer different questions and genuinely disagree. Dropping is constrained
     * by the FOREIGN KEY — `slim_events` must go before `slim_stats`. Creating is constrained by
     * that same key AND by `slim_stats_archive` being created `LIKE slim_stats`, which needs
     * `slim_stats` to exist first. Reversing the drop order puts `slim_stats_archive` first,
     * where the `LIKE` names a table that does not exist yet and the statement fails.
     *
     * Derived from the declared dependencies rather than written out, so reordering the manifest
     * for readability cannot silently produce an order that only works on a database where the
     * tables already exist — which is every developer's, and no new user's.
     *
     * @return string[]
     */
    public static function creationOrder(): array
    {
        $ordered = [];

        $place = function (string $suffix) use (&$place, &$ordered): void {
            if (in_array($suffix, $ordered, true)) {
                return;
            }

            $def = self::TABLES[$suffix];

            foreach ([$def['like'] ?? null, $def['foreign_key']['references'] ?? null] as $needs) {
                if (null !== $needs) {
                    $place($needs);
                }
            }

            $ordered[] = $suffix;
        };

        foreach (array_keys(self::TABLES) as $suffix) {
            $place($suffix);
        }

        return $ordered;
    }

    /**
     * Retired table names for one prefix scope: 'prefix' (per-blog) or 'base_prefix' (network).
     *
     * @return string[]
     */
    public static function legacyTables(string $scope): array
    {
        return array_keys(array_filter(
            self::LEGACY_TABLES,
            static function ($declared) use ($scope) {
                return $declared === $scope;
            }
        ));
    }

    /**
     * Column name => DDL fragment, following `like_columns` and `like` references.
     *
     * @return array<string,string>
     */
    public static function columns(string $suffix): array
    {
        $def = self::table($suffix);

        if (isset($def['like_columns'])) {
            return self::columns($def['like_columns']);
        }

        if (isset($def['like'])) {
            return self::columns($def['like']);
        }

        return $def['columns'];
    }

    /**
     * Index name (with `{prefix}` unresolved) => comma-separated column list.
     *
     * Follows `like` the same way columns() does, because `CREATE TABLE ... LIKE` copies the
     * source's indexes — so a table declared with `like` and an empty index list would be the
     * manifest stating something false about every such table in existence. Index names are
     * per-table in MySQL, so the copy carries the source's names verbatim.
     *
     * Whether those indexes are RECONCILED is a separate question, answered by reconciles().
     *
     * @return array<string,string>
     */
    public static function indexes(string $suffix): array
    {
        $def = self::table($suffix);

        if (isset($def['like'])) {
            return self::indexes($def['like']);
        }

        return $def['indexes'];
    }

    /**
     * Does ensure() bring this table's indexes up to the manifest?
     *
     * False for the archive of slim_stats — see the note on its declaration. Separated from
     * "what indexes does it have" so that a table can be described truthfully without that
     * description becoming an instruction to build thirteen indexes on cold storage.
     */
    public static function reconciles(string $suffix): bool
    {
        return false !== (self::table($suffix)['reconcile'] ?? true);
    }

    /** The `slimstat_*_indexed` option an index stamps, or null if it has none. */
    public static function indexOption(string $index): ?string
    {
        return self::INDEX_OPTIONS[$index] ?? null;
    }

    /**
     * The manifest entries in one optional group, as [table suffix, index key] pairs.
     *
     * Table-QUALIFIED and key-bearing, both deliberately. Returning resolved names with the
     * table dropped forced the settings page to hardcode `slim_stats` and to hand-build its own
     * `ALTER TABLE … ADD INDEX` — so declaring an optional index on `slim_events` would have
     * silently ALTERed `slim_stats` instead, and a second index-DDL emitter would have survived
     * the seam that exists to remove them. With the key, the caller can use createIndexSql().
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function optionalGroup(string $group): array
    {
        $found = [];

        foreach (array_keys(self::TABLES) as $suffix) {
            foreach (array_keys(self::indexes($suffix)) as $name) {
                if ((self::OPTIONAL_INDEXES[$name] ?? null) === $group && self::reconciles($suffix)) {
                    $found[] = [$suffix, $name];
                }
            }
        }

        return $found;
    }

    /**
     * Manifest indexes for a table, minus any whose optional group is switched off.
     *
     * Shared by createTableSql() and ensure() so a fresh table and a reconciled one cannot end
     * up with different index sets — which is C39's defect reproduced one level down.
     *
     * @param  string[] $disabledGroups
     * @return array<string,string>
     */
    private static function wantedIndexes(string $suffix, array $disabledGroups): array
    {
        if ($disabledGroups === []) {
            return self::indexes($suffix);
        }

        $off    = array_flip($disabledGroups);
        $wanted = [];

        foreach (self::indexes($suffix) as $name => $columns) {
            $group = self::OPTIONAL_INDEXES[$name] ?? null;
            if (null === $group || !isset($off[$group])) {
                $wanted[$name] = $columns;
            }
        }

        return $wanted;
    }

    public static function engine(): string
    {
        return self::ENGINE;
    }

    /** Resolve `{prefix}` in a table or index name. */
    public static function resolve(string $name, string $prefix): string
    {
        return str_replace(self::PREFIX_MARKER, $prefix, $name);
    }

    /**
     * Total declared VARCHAR/CHAR characters for a table, in bytes at $bytesPerChar.
     *
     * Exists so the C40 headroom claim is recomputed from the manifest on every CI run instead
     * of being a number in a document. A column widened past the 65,535-byte row limit makes
     * `CREATE TABLE` fail outright on a real install, and the failure surfaces as "the plugin
     * does not work" rather than as anything naming a column.
     */
    public static function declaredCharBytes(string $suffix, int $bytesPerChar = 4): int
    {
        $bytes = 0;

        foreach (self::columns($suffix) as $definition) {
            if (preg_match('/^(?:VAR)?CHAR\((\d+)\)/i', $definition, $m)) {
                $bytes += (int) $m[1] * $bytesPerChar;
            }
        }

        return $bytes;
    }

    /**
     * The collation to create at: whatever `wp_users.user_login` uses.
     *
     * Asked on the CORE connection, and this is load-bearing rather than tidy. On an
     * external-DB install `wp_users` is not on the analytics server at all, so asking there
     * returns null, silently selects FALLBACK_COLLATION, and creates the analytics tables at a
     * collation that need not match the site's — which is ER_CANT_AGGREGATE_2COLLATIONS on
     * Pro's user join, the exact fatal ADR-5 exists to prevent. `DATABASE()` then correctly
     * resolves to the core schema because the query runs on the core handle.
     *
     * Anything that is not already utf8mb4_* falls back rather than being adopted: a site whose
     * users table is still utf8mb3 must not drag new analytics tables back to utf8mb3, or the
     * charset migration has to run on an install that was just created.
     */
    public static function targetCollation(wpdb $core): string
    {
        $collation = $core->get_var($core->prepare(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'user_login'",
            $core->users
        ));

        if (!is_string($collation) || 0 !== strpos($collation, 'utf8mb4_')) {
            return self::FALLBACK_COLLATION;
        }

        return $collation;
    }

    /**
     * The `CREATE TABLE IF NOT EXISTS` for one table, at the target charset and engine.
     *
     * Indexes are emitted inline rather than as separate `CREATE INDEX` statements: on an empty
     * table that is one statement instead of fourteen, and it is the shape every install in the
     * field already has, so a fresh install and a reconciled one end up byte-identical rather
     * than merely equivalent.
     */
    public static function createTableSql(string $suffix, string $prefix, string $collation, array $disabledGroups = []): string
    {
        $def   = self::table($suffix);
        $table = $prefix . $suffix;

        if (isset($def['like'])) {
            return sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` LIKE `%s`',
                $table,
                $prefix . $def['like']
            );
        }

        $lines = [];

        foreach (self::columns($suffix) as $column => $definition) {
            $lines[] = sprintf('%s %s', $column, $definition);
        }

        $lines[] = sprintf('CONSTRAINT PRIMARY KEY (%s)', $def['primary']);

        foreach (self::wantedIndexes($suffix, $disabledGroups) as $name => $columns) {
            $lines[] = sprintf('INDEX %s (%s)', self::resolve($name, $prefix), $columns);
        }

        if (isset($def['foreign_key'])) {
            $fk      = $def['foreign_key'];
            $lines[] = sprintf(
                'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s(%s) ON UPDATE CASCADE ON DELETE CASCADE',
                self::resolve($fk['name'], $prefix),
                $fk['column'],
                $prefix . $fk['references'],
                $fk['on']
            );
        }

        return sprintf(
            "CREATE TABLE IF NOT EXISTS `%s` (\n    %s\n) COLLATE %s ENGINE=%s",
            $table,
            implode(",\n    ", $lines),
            $collation,
            self::ENGINE
        );
    }

    /** The `CREATE INDEX` for one manifest entry. */
    public static function createIndexSql(string $suffix, string $index, string $prefix): string
    {
        return sprintf(
            'CREATE INDEX %s ON %s (%s)',
            self::resolve($index, $prefix),
            $prefix . $suffix,
            self::indexes($suffix)[$index]
        );
    }

    /**
     * Bring the database up to the manifest. Idempotent, and safe to call on every path.
     *
     * Additive only: it creates missing tables and missing indexes and does nothing else. It
     * does not drop, rename, widen or narrow anything — ADR-4 keeps destructive change on
     * `slim_stats` behind an explicit, version-gated decision, and an `ensure()` that could
     * silently rebuild a 443k-row table on `admin_init` is precisely what must not exist.
     *
     * One `SHOW INDEX` per table, not one per index. The old code issued fourteen probes across
     * six call sites; a single `SHOW INDEX FROM t` returns every key on the table, so the whole
     * reconciliation costs one query per table on a healthy install and no writes at all.
     *
     * A failure is reported, never thrown: this runs behind `admin_init` on the upgrade path
     * and inside the tracker's repair path, and both have callers that must keep going. The
     * caller decides what a partial result means — `run_schema_upgrade()` declines to stamp the
     * version, which is what makes the next request retry.
     *
     * `present` carries the manifest indexes CONFIRMED on the table afterwards, and it is not a
     * convenience. The old code called `update_option('slimstat_…_indexed', 'yes')`
     * unconditionally, immediately after a `CREATE INDEX` whose result it never looked at — so
     * an index build that timed out on a large table still stamped "done", and
     * `show_indexes_notice()`, which reads those stamps to decide whether to offer its retry
     * button, never offered one. The failure mode the notice exists for was the one it could not
     * see. Callers stamp from `present`, so an unconfirmed index leaves no stamp.
     *
     * `$collation` is a callable, not a string, and that is worth the small awkwardness: it is
     * only ever consumed when a table is actually being created, while resolving it costs an
     * `information_schema.COLUMNS` query — the heaviest statement on this path. Passed eagerly,
     * every healthy install paid it and threw the answer away, on each version-gated upgrade
     * pass, each new blog, and each missing-table repair.
     *
     * @param  callable():string $collation Resolved only if a table must be created.
     * @param  string[] $disabledGroups Optional-index groups the user has switched off.
     * @return array{tables:string[], indexes:string[], present:string[], failed:string[]}
     */
    public static function ensure(wpdb $db, string $prefix, callable $collation, array $disabledGroups = []): array
    {
        $report   = ['tables' => [], 'indexes' => [], 'present' => [], 'failed' => []];
        $existing = self::existingTables($db, $prefix);
        $resolved = null;

        foreach (self::creationOrder() as $suffix) {
            $table = $prefix . $suffix;

            if (!isset($existing[$table])) {
                $resolved = $resolved ?? (string) $collation();
                $db->query(self::createTableSql($suffix, $prefix, $resolved, $disabledGroups));

                // Verify rather than trust the return value. `CREATE TABLE IF NOT EXISTS`
                // answers 0 both for "already there" and for a statement the server refused
                // with a warning, and a missing table reported as created is how the tracker
                // ends up inserting into nothing. Re-probed individually because re-reading is
                // the entire point here, unlike the bulk probe above.
                if (!self::tableExists($db, $table)) {
                    $report['failed'][] = $table;
                    continue;
                }

                $report['tables'][] = $table;
            }

            if (!self::reconciles($suffix)) {
                continue;
            }

            $state             = self::indexState($db, $suffix, $prefix, $disabledGroups);
            $report['present'] = array_merge($report['present'], $state['present']);

            foreach ($state['missing'] as $index) {
                if (false === $db->query(self::createIndexSql($suffix, $index, $prefix))) {
                    $report['failed'][] = self::resolve($index, $prefix);
                    continue;
                }

                $report['indexes'][] = self::resolve($index, $prefix);
                $report['present'][] = self::resolve($index, $prefix);
            }
        }

        return $report;
    }

    /**
     * Every slim_ table on this connection, as a lookup set, in ONE statement.
     *
     * `ensure()` applied "one probe per object, not one per sub-object" to indexes and stopped a
     * level short of tables — four `SHOW TABLES LIKE` where the pattern answers all of them at
     * once. The `_` is escaped because it is a single-character wildcard in LIKE, so an
     * unescaped `slim_%` also matches `slimXstats` on a prefix that happens to contain one.
     *
     * @return array<string,true>
     */
    private static function existingTables(wpdb $db, string $prefix): array
    {
        $pattern = str_replace('_', '\\_', $prefix . 'slim_') . '%';

        $suppressed = $db->suppress_errors(true);
        $found      = $db->get_col($db->prepare('SHOW TABLES LIKE %s', $pattern));
        $db->suppress_errors($suppressed);

        $set = [];
        foreach ((array) $found as $table) {
            $set[(string) $table] = true;
        }

        return $set;
    }

    /**
     * Which manifest indexes are on the table, and which are not.
     *
     * Reports NEITHER when the table cannot be read. `SHOW INDEX` against a table this
     * connection cannot see is an ERROR, and `get_col()` answers `[]` for that exactly as it
     * does for a table with no keys — so treating the empty answer as "nothing is indexed" would
     * issue thirteen `CREATE INDEX` statements against a table that is not there. The same
     * conflation, in `AbstractIndexMigration`, is what made all eight index migrations claim
     * they had to run on an install with no tables at all (C41). An unreadable table must
     * produce no `missing` (nothing to build) and no `present` (nothing to stamp) — a probe that
     * could not look is not an answer in either direction.
     *
     * Public because the admin layer needs the same answer: `wp_slimstat_admin::init()` used to
     * ask `SHOW INDEX … WHERE Key_name = 'x'` once per index on every wp-admin request whose
     * `slimstat_*_indexed` option was not yet stamped — the seventh probe site, and the only one
     * on a per-request path. Five of its six queries were pure duplication, since each returns
     * the whole key list anyway.
     *
     * @param  string[] $disabledGroups
     * @return array{present:string[], missing:string[]}
     */
    public static function indexState(wpdb $db, string $suffix, string $prefix, array $disabledGroups = []): array
    {
        $state  = ['present' => [], 'missing' => []];
        $wanted = self::wantedIndexes($suffix, $disabledGroups);

        if ($wanted === []) {
            return $state;
        }

        $suppressed = $db->suppress_errors(true);
        $found      = $db->get_col(sprintf('SHOW INDEX FROM `%s`', $prefix . $suffix), 2);
        $error      = (string) $db->last_error;
        $db->suppress_errors($suppressed);

        if ('' !== $error) {
            return $state;
        }

        $have = array_flip(array_map('strval', (array) $found));

        foreach (array_keys($wanted) as $name) {
            if (isset($have[self::resolve($name, $prefix)])) {
                $state['present'][] = self::resolve($name, $prefix);
                continue;
            }

            $state['missing'][] = $name;
        }

        return $state;
    }

    private static function tableExists(wpdb $db, string $table): bool
    {
        $suppressed = $db->suppress_errors(true);
        $found      = $db->get_col($db->prepare('SHOW TABLES LIKE %s', $table));
        $db->suppress_errors($suppressed);

        return in_array($table, array_map('strval', (array) $found), true);
    }

    /**
     * @return array<string,mixed>
     */
    private static function table(string $suffix): array
    {
        if (!isset(self::TABLES[$suffix])) {
            // Thrown, not defaulted. A typo'd suffix silently answering "no columns, no
            // indexes" is how a consumer comes to reconcile nothing and report success —
            // the vacuity shape this whole seam exists to remove.
            throw new \InvalidArgumentException(sprintf('unknown SlimStat table "%s"', $suffix));
        }

        return self::TABLES[$suffix];
    }
}
