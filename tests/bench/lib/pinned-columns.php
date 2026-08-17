<?php
// The PINNED COLUMN SET for fingerprint v2 — derived from the schema manifest, never transcribed.
//
// tests/oracle/encoding-spec.md lists the pinned columns in PROSE, and S2 shipped with the
// encoders taking a caller-supplied array and no caller. That prose would have become the FOURTH
// hand-written copy of this schema (`run-rollup-floor.sh`'s CRC32 probe is already the third),
// free to drift from the declaration the same way init_tables() drifted from $index_defs — the
// C39/C11 shape this tree spent a refactor collapsing.
//
// So the set comes from SlimStat\Schema\Schema::columns(), which is the single source
// tests/schema-single-source-test.php already gates, and which is deliberately dependency-free:
// it calls no WordPress function at require time, so a bench script and the PHP-only CI lanes
// can both read it.
//
// PINNING = the v5-era set. `vid_hash` and `ua_id` are v6 migrations; including them would make
// the fingerprint of an unmigrated corpus differ from a migrated one for a reason that is not a
// data difference. Excluding them also happens to remove the only BINARY columns in either
// table, which is why ENCODING_V1 needs no BINARY rule — that is a consequence of the exclusion,
// not an independent decision, and it is written here so the next person does not re-derive it.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s bench libs, where a declare()
// that is not the first statement fatals. PHP 7.4 floor.

if (!function_exists('slimstat_fp2_pinned_columns')) {

    require_once dirname(dirname(dirname(__DIR__))) . '/src/Schema/Schema.php';

    /**
     * Columns a v6 migration ADDS. Named in ONE place, and asserted to still exist in the
     * manifest — an exclusion list that silently stops matching anything is how a v6 column
     * creeps into a "v5-era" fingerprint without any hash appearing to change meaning.
     */
    function slimstat_fp2_v6_added_columns()
    {
        return ['vid_hash', 'ua_id'];
    }

    /**
     * Split a manifest DDL fragment into its declared TYPE and nullability.
     *
     * Fragments look like `VARCHAR(39) DEFAULT NULL`, `INT UNSIGNED NOT NULL auto_increment`,
     * `TINYINT UNSIGNED DEFAULT 0`, `INT(10) NOT NULL AUTO_INCREMENT`. The type is everything
     * before the first column-attribute keyword.
     *
     * @return array{0:string,1:bool} [type, nullable]
     */
    function slimstat_fp2_split_declaration($name, $fragment)
    {
        $parts = preg_split('/\s+(?:DEFAULT|NOT\s+NULL|NULL|AUTO_INCREMENT|COMMENT|COLLATE|CHARACTER\s+SET|UNIQUE|PRIMARY)\b/i', trim((string) $fragment), 2);
        $type  = isset($parts[0]) ? trim($parts[0]) : '';
        if ('' === $type) {
            // Loud, not a default. A fragment this cannot read is a column whose type the
            // fingerprint would be guessing at, and a guessed type is a hash that means nothing.
            throw new RuntimeException("cannot read a declared type out of `{$name} {$fragment}`");
        }
        return [$type, false === stripos($fragment, 'NOT NULL')];
    }

    /**
     * The pinned set for one table, in MANIFEST ORDER (which is the order the manifest hash and
     * every row encoding depend on).
     *
     * @return array<int, array{0:string,1:string,2:bool}> [[name, type, nullable], ...]
     */
    function slimstat_fp2_pinned_columns($suffix)
    {
        $declared = \SlimStat\Schema\Schema::columns($suffix);
        $v6       = slimstat_fp2_v6_added_columns();

        // The exclusion list must still MATCH something, or it has quietly stopped excluding.
        // slim_events has never held either column, so the assertion is scoped to the table that
        // does — checking it everywhere would fail for a true reason and teach people to ignore it.
        if ('slim_stats' === $suffix) {
            foreach ($v6 as $name) {
                if (!array_key_exists($name, $declared)) {
                    throw new RuntimeException(
                        "the v6-added exclusion names `{$name}`, which is no longer in the "
                        . "{$suffix} manifest. Either it was renamed and this list is stale, or it "
                        . 'shipped in v5 and is no longer an exclusion — decide which, do not delete this.'
                    );
                }
            }
        }

        $pinned = [];
        foreach ($declared as $name => $fragment) {
            if (in_array($name, $v6, true)) {
                continue;
            }
            list($type, $nullable) = slimstat_fp2_split_declaration($name, $fragment);
            $pinned[] = [$name, $type, $nullable];
        }
        return $pinned;
    }

    /** The ORDER BY that makes the chain deterministic, per table. Part of the manifest hash. */
    function slimstat_fp2_order_by($suffix)
    {
        $keys = ['slim_stats' => 'id', 'slim_events' => 'event_id'];
        if (!isset($keys[$suffix])) {
            throw new RuntimeException(
                "no ORDER BY declared for `{$suffix}`. The chain is order-dependent, so the "
                . 'ordering IS part of the identity and cannot be defaulted.'
            );
        }
        return $keys[$suffix];
    }
}
