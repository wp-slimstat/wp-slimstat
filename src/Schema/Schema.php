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

        /**
         * ADR-9 Layer 1 — the first dimension (F10 / G3).
         *
         * Holds the PARSED user-agent dimensions only. The raw `user_agent` string stays
         * denormalised on the fact row per ratified decision P4, which is what keeps Art. 17
         * satisfiable: delete the fact row and the identity goes with it. A dimension row
         * describes a browser, not a person, so it survives erasure without carrying anything
         * personal.
         *
         * PRIMARY KEY IS DERIVED, NOT AUTO_INCREMENT — see SurrogateKey. That is the difference
         * between the tracker computing a key locally and asking the database for one on every
         * hit; the second costs a SELECT, a conditional INSERT and a race, per dimension, per
         * pageview, and would make Layer 1 cost more than the whole programme saves.
         *
         * BINARY(8) and SurrogateKey::WIDTH must agree. A mismatch truncates silently and every
         * key sharing its last bytes collapses into one row — asserted in SurrogateKeyTest and
         * in the manifest gate rather than trusted.
         *
         * Not archived and not reconciled into slim_stats_archive: a dimension is small,
         * append-only and shared by live and archived facts alike.
         */
        'slim_user_agents' => [
            'columns' => [
                'ua_id'           => 'BINARY(8) NOT NULL',
                'browser'         => 'VARCHAR(40) DEFAULT NULL',
                'browser_version' => 'VARCHAR(15) DEFAULT NULL',
                'browser_type'    => 'TINYINT UNSIGNED DEFAULT 0',
                'platform'        => 'VARCHAR(15) DEFAULT NULL',
                'first_seen'      => 'INT(10) UNSIGNED DEFAULT 0',
            ],
            'primary' => 'ua_id',
            'indexes' => [
                // Reports group by browser, and by browser+version for the "Top Browsers"
                // report. Both read the dimension directly rather than joining every fact row.
                'idx_ua_browser' => 'browser, browser_version',
                'idx_ua_platform' => 'platform',
            ],
        ],

        // C48 — the install's identity and leases, ON THE CONNECTION THEY DESCRIBE. When
        // the custom-DB add-on points analytics at another server, this table travels with
        // the data: `install_uuid` and `owner_site_url` are what a dump carries and
        // credentials do not, so P5's topology-F refusal compares owner_site_url against
        // home_url() instead of fingerprinting dbhost/prefix (ADR-14's superseded and
        // inverted failure). Key-value on purpose: identity rows and lease rows share the
        // atomic-claim primitive (the PRIMARY KEY is what rejects a concurrent writer).
        'slim_meta' => [
            'columns' => [
                // 191, not 256: the PRIMARY KEY must fit 767 index bytes under utf8mb4.
                'meta_key'   => 'VARCHAR(191) NOT NULL',
                'meta_value' => 'VARCHAR(2048) DEFAULT NULL',
                // For a lease row this is the expiry epoch; for identity rows it is unused.
                'dt'         => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            ],
            'primary' => 'meta_key',
            'indexes' => [],
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
                // ADR-9 Layer 1's join key to slim_user_agents (F10/G3). DECLARED HERE, not only
                // added by AddUserAgentDimension, and the distinction is the whole of C39: a
                // migration that adds a column the manifest does not know about means a FRESH
                // install is born without it and pays a fact-table ALTER to catch up, while an
                // upgraded one already has it — the two schema states F2 exists to collapse.
                //
                // Declaring it also silences C41 on a new site: the migration's
                // factColumnExists() is true immediately, so shouldRun() is false and the
                // notice does not offer to rebuild an empty table.
                //
                // NULL-able on purpose. An un-backfilled row is a real state (M6 has the
                // dimension built by migration and cron, never by the tracker), and reports
                // LEFT JOIN so a NULL key still counts from the fact row's own columns.
                'ua_id'             => 'BINARY(8) DEFAULT NULL',
                // The cookieless visitor's identity — the FULL 128-bit HMAC, per ratified
                // P2 (D68). Declared here so fresh installs are born with it (C39); the
                // required AddVisitIdentity migration adds it on upgrades. NULL-able on
                // purpose: rows written before it existed, and rows from consenting
                // (cookie-carrying) visitors, legitimately have no anonymous identity —
                // their inputs are gone or never applied. Never backfilled.
                //
                // 16 bytes, not 4: the predecessor stored 32 bits of the same HMAC in
                // visit_id, which is ~50% collision odds at ~77k cookieless visitors —
                // and after G4 a collision is a subject-access export returning another
                // person's browsing history (SurrogateKey's note carries the arithmetic).
                'vid_hash'          => 'BINARY(16) DEFAULT NULL',
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
                // Serves the anonymous reuse probe (Session::findExistingAnonymousVisitId):
                // an equality seek on the identity plus the session's dt range. Without it
                // the probe is the same once-per-anonymous-pageview 30-minute range scan
                // it replaced. On an upgraded install this index is declared before the
                // AddVisitIdentity migration has added its column — ensure() skips an
                // index whose columns are missing (reported, not errored) until then.
                'idx_vid_hash_dt'                   => 'vid_hash, dt',
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
     * Fully-qualified table name for one declared suffix under one prefix.
     *
     * The name-resolution half of the per-blog table API (`wp_slimstat::table()`), kept here
     * because the manifest is the only authority on which suffixes exist — a hand-written list
     * at the call site would be a tenth creator of the thing C11 counted nine of.
     *
     * Throws on an unknown suffix rather than returning a guessed name: a caller that
     * interpolates `$prefix . $name` itself fails at query time with an SQL error naming a
     * table; a caller that asks the manifest fails at CALL time naming the typo.
     *
     * @throws \InvalidArgumentException when the suffix is not in the manifest.
     */
    public static function tableName(string $suffix, string $prefix): string
    {
        if (!isset(self::TABLES[$suffix])) {
            throw new \InvalidArgumentException(sprintf(
                "unknown table suffix '%s' — the manifest declares: %s",
                $suffix,
                implode(', ', self::tables())
            ));
        }

        return $prefix . $suffix;
    }

    /**
     * Suffix => fully-qualified name for every declared table under one prefix.
     *
     * @return array<string,string>
     */
    public static function tableNames(string $prefix): array
    {
        $names = [];

        foreach (self::tables() as $suffix) {
            $names[$suffix] = $prefix . $suffix;
        }

        return $names;
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
     * Is this manifest-declared column actually on the table?
     *
     * A projection of columnState()'s read model, so every caller asks the SAME
     * question the drift report answers — three hand-rolled information_schema probes
     * (two migrations and the GDPR eraser) had already grown, each free to decide
     * failure semantics differently.
     *
     * IT FAILS OPEN, and this docblock said the opposite until 2026-09-01. columnState()
     * returns `missing => []` for a table it cannot read — it cannot distinguish "no such
     * table" from "could not look" — so `!in_array($column, [], true)` is TRUE and an
     * unreadable table reports every column PRESENT. The old text read "an UNREADABLE table
     * reports every declared column missing, so the answer is false", which is exactly
     * backwards, and anyone writing a guard from it gets fail-open where they reasoned
     * fail-closed.
     *
     * Callers needing the distinction must layer their own error check on top, as
     * AbstractMigration::columnExists() does via probeFailed(). Callers on a path that runs
     * per-request should not ask this at all — ask the real question and catch its failure
     * (Query::probeVar()), which costs nothing when the schema is whole.
     *
     * @throws \InvalidArgumentException when the column is not in the manifest — asking
     *                                   about an undeclared column is a typo, not a
     *                                   schema state.
     */
    public static function hasColumn(wpdb $db, string $suffix, string $prefix, string $column): bool
    {
        if (!isset(self::columns($suffix)[$column])) {
            throw new \InvalidArgumentException(sprintf(
                "column '%s' is not declared on '%s' in the manifest",
                $column,
                $suffix
            ));
        }

        return !in_array($column, self::columnState($db, $suffix, $prefix)['missing'], true);
    }

    /**
     * The bare column names one declared index is built from.
     *
     * Parses the manifest's own spelling — `'resource(191), dt, fingerprint(20)'` —
     * so ensure() can ask "does the table have what this index needs" without a second
     * list that could drift from the first (the C11 shape, at one remove).
     *
     * @return string[]
     */
    public static function indexColumnNames(string $suffix, string $index): array
    {
        $spec = self::indexes($suffix)[$index] ?? '';

        $names = [];
        foreach (explode(',', $spec) as $part) {
            $name = trim(preg_replace('/\(.*$/', '', trim($part)));
            if ('' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
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
     * The optional GROUP an index belongs to, or null when it is mandatory.
     *
     * Not the same question as indexOption(). That one names the `slimstat_*_indexed` stamp an
     * index writes when it is confirmed present, and mandatory indexes carry stamps too —
     * `idx_country_dt` has one and is built unconditionally. THIS one names the group the
     * Maintenance screen can switch off, which is the only reason to decline to build an index.
     * Conflating them was the first draft of indexesForColumn(), and it would have silently
     * skipped four mandatory indexes.
     */
    private static function optionalIndexGroup(string $index): ?string
    {
        return self::OPTIONAL_INDEXES[$index] ?? null;
    }

    /**
     * Mandatory manifest indexes on $suffix whose column list contains $column.
     *
     * DERIVED FROM THE MANIFEST, never a list. An index declared over a column that a migration
     * adds becomes this method's answer the moment it is declared — which is what stops the
     * fresh/upgraded divergence reopening the way C39 did. A hand-written list would have to be
     * edited every time, and the edit that forgets is precisely the defect.
     *
     * Optional-group indexes are excluded: those are the ones the site owner can switch off, and
     * a migration that rebuilt them would override a choice they made deliberately.
     *
     * @return string[] manifest index KEYS, unresolved — pass to createIndexSql()
     */
    public static function indexesForColumn(string $suffix, string $column): array
    {
        $found = [];

        foreach (array_keys(self::indexes($suffix)) as $name) {
            if (null !== self::optionalIndexGroup($name)) {
                continue;
            }
            if (in_array($column, self::indexColumnNames($suffix, $name), true)) {
                $found[] = $name;
            }
        }

        return $found;
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
                if (self::optionalIndexGroup($name) === $group && self::reconciles($suffix)) {
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
            $group = self::optionalIndexGroup($name);
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
            $lines[] = self::columnSql($column, $definition);
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
     * The `ALTER TABLE … ADD COLUMN` for one manifest column.
     *
     * C39's other half, and the half F2 missed. `ensure()` reconciles tables and indexes; it has
     * never reconciled COLUMNS, so a column arrives only by an `ALTER` written somewhere else —
     * and the manifest never had to agree with it. That is not hypothetical drift:
     *
     *   - `ua_id` was added by AddUserAgentDimension and declared nowhere, so a fresh install was
     *     born without it and paid a fact-table ALTER to catch up (fixed in 65c405ec, PITFALLS 30).
     *   - `email` is `VARCHAR(256)` in the manifest and was added as `VARCHAR(255)` by the 4.8.2
     *     block — so a fresh install and an install upgraded from below 4.8.2 have differently
     *     shaped `slim_stats`, which is the exact divergence C39 measured for indexes.
     *   - `fingerprint` was added as `VARCHAR(256)` to slim_stats and `VARCHAR(255)` to its own
     *     archive, in adjacent lines of the same block.
     *
     * None of the three was reachable by the gate, because the gate looked for CREATE TABLE,
     * CREATE INDEX, ADD INDEX and DROP INDEX — every DDL keyword except the one that adds a
     * column. Rendering the fragment from here is what lets the gate say "no ADD COLUMN outside
     * this file", the same rule the other four keywords have had since F2.
     *
     * POSITION IS DELIBERATELY NOT A MANIFEST PROPERTY. `$after` stays a caller's argument
     * because it is a fact about one historical upgrade step, not about the target schema:
     * `tz_offset` is declared after `ua_id` here and must be added `AFTER outbound_resource` on a
     * 4.8.4.1 upgrade, where `ua_id` does not exist yet. Deriving it from the declaration order
     * would emit `AFTER ua_id` and fail. Physical order already differs between fresh and
     * upgraded installs and nothing reads it — wpdb returns associative rows and every INSERT
     * names its columns.
     *
     * @param string      $after Column to add after; empty for "append". Unvalidated on purpose:
     *                           the target may legitimately not be in the manifest any more
     *                           (`plugins` was dropped in 4.8.4.1).
     */
    public static function addColumnSql(string $suffix, string $column, string $prefix, string $after = ''): string
    {
        $columns = self::columns($suffix);

        if (!isset($columns[$column])) {
            throw new \InvalidArgumentException(sprintf(
                'Schema: no column "%s" declared on %s. A migration that adds a column the '
                    . 'manifest does not know about is C39 reopened — the fresh install is born '
                    . 'without it and the upgraded one has it.',
                $column,
                $suffix
            ));
        }

        return sprintf(
            'ALTER TABLE `%s` ADD COLUMN %s%s',
            $prefix . $suffix,
            self::columnSql($column, $columns[$column]),
            '' === $after ? '' : ' AFTER ' . $after
        );
    }

    /**
     * The `ALTER TABLE … DROP COLUMN` for a column the manifest has deliberately forgotten.
     *
     * The inverse of addColumnSql(), and it refuses the inverse mistake. Dropping a column the
     * manifest still declares gives an upgraded install a table missing something every fresh
     * install is created with — C39 reached from the other side, and just as silent.
     *
     * Rendered here rather than written at the call site for one blunt reason: it is what lets
     * the gate key on `ALTER TABLE` instead of on a list of keywords. MySQL's grammar is
     * `ADD [COLUMN]`, `DROP [COLUMN]`, `MODIFY [COLUMN]`, `CHANGE [COLUMN]` — the word is
     * OPTIONAL in all four, so a scan for `DROP COLUMN` is blind to `ALTER TABLE t DROP plugins`,
     * which is valid, shorter, and reopens exactly the defect the scan exists to close. Keyword
     * enumeration is the mechanism that failed the first time; `ALTER TABLE` has no optional
     * spelling.
     *
     * The refusal is also worth more here than in the gate: a CI check runs on this repository,
     * and this throws on the install.
     */
    public static function dropColumnSql(string $suffix, string $column, string $prefix): string
    {
        if (isset(self::columns($suffix)[$column])) {
            throw new \InvalidArgumentException(sprintf(
                'Schema: refusing to drop "%s" from %s — the manifest still declares it, so an '
                    . 'upgraded install would lose a column every fresh install is born with.',
                $column,
                $suffix
            ));
        }

        return sprintf('ALTER TABLE `%s` DROP COLUMN %s', $prefix . $suffix, $column);
    }

    /**
     * One column, rendered once, for both the CREATE and the ALTER.
     *
     * A two-token `sprintf` hardly needs a method — except that these are the two paths a fresh
     * install and an upgraded one take to the same column, and this is the single fragment where
     * they could disagree. A trailing `COMMENT` or a charset clause added to the CREATE side
     * alone would be C39 in one line, reachable by no gate: the manifest would still hold exactly
     * one declaration, and the two renderings of it would differ.
     */
    private static function columnSql(string $column, string $definition): string
    {
        return sprintf('%s %s', $column, $definition);
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
     * @return array{tables:string[], indexes:string[], present:string[], failed:string[], columns_missing:string[], columns_narrow:array<string,string>}
     */
    public static function ensure(wpdb $db, string $prefix, callable $collation, array $disabledGroups = []): array
    {
        $report   = [
            'tables'          => [],
            'indexes'         => [],
            'present'         => [],
            'failed'          => [],
            // F4 — drift this run OBSERVED and deliberately did not act on.
            'columns_missing' => [],
            'columns_narrow'  => [],
            // Indexes whose columns the table does not have YET — the window between a
            // release declaring the index and the column migration running. Skipped, not
            // failed: a CREATE INDEX here loses on every admin_init until the migration
            // lands, stamping failures for a state that is expected and self-healing.
            'indexes_skipped_missing_column' => [],
        ];
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

            // COLUMN DRIFT IS REPORTED, NEVER REPAIRED (F4). An `ALTER` here runs on
            // `admin_init`, and rebuilding a 443k-row fact table there is precisely the hazard
            // S7 removed. What was missing was never the repair — it was that column drift had
            // no read model at all, so `email VARCHAR(255)` on every install upgraded from below
            // 4.8.2 was invisible to everything, permanently.
            $columns = self::columnState($db, $suffix, $prefix);
            $drift   = self::qualifyDrift($columns, $suffix);

            $report['columns_missing'] = array_merge($report['columns_missing'], $drift['missing']);
            $report['columns_narrow']  = $report['columns_narrow'] + $drift['narrow'];

            $state             = self::indexState($db, $suffix, $prefix, $disabledGroups);
            $report['present'] = array_merge($report['present'], $state['present']);

            $columns_absent = array_flip($columns['missing']);

            foreach ($state['missing'] as $index) {
                // An index can only be built from columns the table has. The manifest may
                // declare both together while a migration delivers the column later, so
                // "column not there yet" is a skip with a name, never an attempt.
                $unbuildable = '';
                foreach (self::indexColumnNames($suffix, $index) as $column) {
                    if (isset($columns_absent[$column])) {
                        $unbuildable = $column;
                        break;
                    }
                }
                if ('' !== $unbuildable) {
                    $report['indexes_skipped_missing_column'][$prefix . $suffix . '.' . $index] = $unbuildable;
                    continue;
                }

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
     * Which manifest COLUMNS are on the table, and which have drifted — the sibling `indexState()`
     * never had.
     *
     * F4. `ensure()` reconciles tables and indexes and has never reconciled columns, so a column
     * reaches a FRESH install through `createTableSql()` and an UPGRADED one only if somebody
     * hand-wrote a version-gated `ALTER`. Forget the second and the two diverge — which is C39,
     * and it is how `ua_id` shipped for one commit (PITFALLS 30).
     *
     * REPORTING, NOT REPAIRING, and that is the whole design. `ensure()` runs on `admin_init`;
     * an ALTER that rebuilds a 443k-row fact table there is the "long table rebuild reachable
     * from an anonymous pageview" hazard S7 already had to remove once. What was missing is not
     * the repair — it is that column drift was **reportable by nothing**. Two bespoke probes
     * (`Storage::presentColumns()` on the write path, `AddUserAgentDimension::factColumnExists()`
     * for one hardcoded name) and a write-path column-dropper stood in for a read model that did
     * not exist.
     *
     * WHAT IS COMPARED, AND WHAT DELIBERATELY IS NOT.
     *
     * Presence, always. And for `VARCHAR`/`CHAR`, the declared LENGTH — but only when the table's
     * is SMALLER than the manifest's. Narrower is the direction that truncates data; wider is
     * somebody else's ALTER and not a hazard. This is the `email VARCHAR(255)` case: the 4.8.2
     * upgrade block declared 255 while the manifest declares 256, so every install upgraded from
     * below 4.8.2 is one character short and always will be — the repaired block never runs again
     * on them.
     *
     * INTEGER TYPES ARE NOT COMPARED AT ALL. MySQL 8.0.19 removed the display width from integer
     * types, so a column declared `INT(10) UNSIGNED` reports as `int unsigned` on 8.x and
     * `int(10) unsigned` on ADR-2's 5.6 floor. Comparing them would report every integer column
     * on every 8.x install as drifted — a normaliser that is right on one server and wrong on the
     * other, which is a second parser disagreeing with the first, which is this programme's most
     * repeated defect. So the answer to "has this INT changed" is "not measured here", said out
     * loud, rather than a number that is wrong half the time.
     *
     * Reports NOTHING when the table cannot be read, exactly as `indexState()` does: `SHOW
     * COLUMNS` against a table this connection cannot see is an ERROR, and `get_results()`
     * answers `[]` for that as it does for a table with no columns. An unreadable table must
     * produce no `missing` — or a fresh external-DB install reports its entire schema as absent.
     *
     * @return array{present:string[], missing:string[], undeclared:string[], narrow:array<string,string>}
     */
    /**
     * Column drift across every reconciling table, READ ONLY.
     *
     * The observation half of what `ensure()` does, without the DDL half. `ensure()` creates
     * tables and builds indexes; a caller that only wants to know whether the schema matches the
     * manifest — the admin notice re-deriving itself on `admin_init` — must not be able to change
     * it. F4's standing rule, and S7's: an `ALTER` on `admin_init` rebuilding a 443k-row fact
     * table is the hazard that seam removed.
     *
     * The WALK is deliberately not shared with `ensure()`, which interleaves the same iteration
     * with table creation and index reconciliation and needs the per-table `columnState()` for
     * its own `columns_absent` logic. What IS shared is `qualifyDrift()` — the `<table>.<column>`
     * naming rule, which is the part two owners could disagree about silently.
     *
     * @return array{missing:string[], narrow:array<string,string>}
     */
    public static function columnDrift(wpdb $db, string $prefix): array
    {
        $drift = ['missing' => [], 'narrow' => []];

        foreach (self::tables() as $suffix) {
            // NO reconciles() GUARD, deliberately. `reconcile => false` is a DDL policy — it says
            // ensure() must not build indexes on this table — and it has no business suppressing
            // OBSERVATION, which is read-only by construction here. Excluding non-reconciling
            // tables meant `slim_stats_archive` was the one table whose drift nothing could ever
            // report, which is precisely the table that freezes: it is created once with
            // `CREATE TABLE ... LIKE` and never gains a column again. `slim_events_archive`
            // reconciles and so was already covered; the asymmetry was invisible and arbitrary.
            $qualified        = self::qualifyDrift(self::columnState($db, $suffix, $prefix), $suffix);
            $drift['missing'] = array_merge($drift['missing'], $qualified['missing']);
            $drift['narrow']  = $drift['narrow'] + $qualified['narrow'];
        }

        return $drift;
    }

    /**
     * Qualify one table's column state as `<table>.<column>` drift entries.
     *
     * One owner for the naming rule. Two callers construct drift lists and a third compares
     * their products for equality, so a disagreement about whether the table prefix is included
     * would show up as a permanent option rewrite rather than as an error.
     *
     * @param array{missing:string[], narrow:array<string,string>} $columns
     *
     * @return array{missing:string[], narrow:array<string,string>}
     */
    private static function qualifyDrift(array $columns, string $suffix): array
    {
        $drift = ['missing' => [], 'narrow' => []];

        foreach ($columns['missing'] as $column) {
            $drift['missing'][] = $suffix . '.' . $column;
        }

        foreach ($columns['narrow'] as $column => $widths) {
            $drift['narrow'][$suffix . '.' . $column] = $widths;
        }

        return $drift;
    }

    public static function columnState(wpdb $db, string $suffix, string $prefix): array
    {
        $state   = ['present' => [], 'missing' => [], 'undeclared' => [], 'narrow' => []];
        $wanted  = self::columns($suffix);

        $suppressed = $db->suppress_errors(true);
        $found      = $db->get_results(sprintf('SHOW COLUMNS FROM `%s`', $prefix . $suffix), ARRAY_A);
        $error      = (string) $db->last_error;
        $db->suppress_errors($suppressed);

        if ('' !== $error || !is_array($found) || [] === $found) {
            return $state;
        }

        $actual = [];
        foreach ($found as $row) {
            // Lowercased on BOTH sides of every comparison below: MySQL column
            // identifiers are case-insensitive, so a hand-restored dump spelling a
            // column `Fingerprint` satisfies every query that names `fingerprint` —
            // reading it as "missing" here is a false drift report, and downstream
            // (visitor_id_expr's tier probe) it silently drops an identity tier and
            // changes grouping with no error anywhere.
            $actual[strtolower((string) ($row['Field'] ?? ''))] = (string) ($row['Type'] ?? '');
        }

        foreach ($wanted as $column => $definition) {
            $key = strtolower($column);
            if (!isset($actual[$key])) {
                $state['missing'][] = $column;
                continue;
            }

            $state['present'][] = $column;

            $declared = self::charLength($definition);
            $onTable  = self::charLength($actual[$key]);

            if (null !== $declared && null !== $onTable && $onTable < $declared) {
                $state['narrow'][$column] = sprintf('%d, declared %d', $onTable, $declared);
            }
        }

        foreach (array_keys($actual) as $column) {
            if (!isset($wanted[$column])) {
                $state['undeclared'][] = $column;
            }
        }

        return $state;
    }

    /**
     * The character length of a VARCHAR/CHAR declaration, or null for anything else.
     *
     * Null is the "not comparable" answer and it is load-bearing — see columnState(): every
     * integer type must reach it, because MySQL 8.0.19 dropped their display widths and a
     * comparison would be wrong on one server or the other.
     */
    private static function charLength(string $type): ?int
    {
        // char/varchar AND binary/varbinary. All four keep their declared length on every server
        // in ADR-2's range; integer display widths do not, and that is the whole reason this
        // returns null rather than a number for them. `binary` matters concretely: `ua_id` is
        // BINARY(8) and must equal SurrogateKey::WIDTH, so a narrowed one is a silently
        // truncated key — every value sharing its last bytes collapsing into one dimension row.
        if (!preg_match('/^\s*(?:var)?(?:char|binary)\s*\(\s*(\d+)\s*\)/i', $type, $m)) {
            return null;
        }

        return (int) $m[1];
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
