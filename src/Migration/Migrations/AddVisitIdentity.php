<?php

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;

/**
 * Add `vid_hash BINARY(16)` to the fact table and its archive — D68, decision P2.
 *
 * The column IS the fix's schema half: the cookieless visitor's identity moves out of a
 * 32-bit truncation stored in `visit_id` (deterministic stranger-merging at scale — the
 * arithmetic lives on Schema's declaration and in SurrogateKey's note) into a full-width
 * HMAC of its own. Fresh installs are born with the column from the manifest (C39) and
 * this migration answers shouldRun() = false there (C41); it exists for UPGRADES only.
 *
 * REQUIRED, not optional — unlike AddUserAgentDimension. That one builds an optional
 * reporting dimension; this one is what the TRACKER writes on every anonymous hit. Until
 * it runs, each such hit pays P1's degradation dance — a failed INSERT, a column probe,
 * an intersected retry — and the identity is dropped. The pageview survives (that is
 * P1's guarantee), but three statements instead of one, per hit, is a state to leave
 * quickly, which is exactly what "required" means to the migration screen.
 *
 * NO BACKFILL, by design rather than by omission. A historical row's identity inputs
 * (daily salt + fingerprint, or salt + ip + user agent) are keyed to the day the hit
 * happened; the salts are gone, and recomputing with today's salt would fabricate an
 * identity the visitor never had. NULL on old rows is the true statement: "identity
 * unknown". Reports LEFT-fall through visitor_id_expr()'s ladder exactly as before.
 *
 * COST, measured once and stated here rather than in each caller's comment. 8.30 s for the
 * pair on the 443,543-row reference install (MySQL 8.0.35) — but that install's ARCHIVE IS
 * EMPTY, so the number is one table's rebuild plus a no-op, and a site with a populated
 * archive pays roughly twice it. Not instant on any server: addManifestColumn() asks for
 * ALGORITHM=INPLACE explicitly, which precludes the INSTANT path even where 8.0.12+ would
 * offer it. Do not extrapolate linearly — this repo's own Run 7 scaled 152k to "~14 s at
 * 443k" for the same operation class, and the measurement came in 1.7× under it. A rebuild
 * is linear only while it fits the buffer pool.
 *
 * The ARCHIVE gets the column too, in the same run: PurgeArchive::STATS_COLUMNS names
 * vid_hash, so a purge against an archive lacking it would fail whole — a skipped purge
 * and a degradation, per its guard, but a purge that works beats one that degrades.
 * Both ALTERs append (no AFTER clause): an upgraded table may lack the optional ua_id,
 * and anchoring position to a column that may be absent fails the statement for
 * cosmetics. Column ORDER is not part of the manifest's contract; presence and type are.
 */
class AddVisitIdentity extends AbstractMigration
{
    public function getId(): string
    {
        return 'add-visit-identity';
    }

    public function getName(): string
    {
        return __('Add the anonymous visitor identity column', 'wp-slimstat');
    }

    public function getDescription(): string
    {
        return __(
            'Adds a private, full-width identity column for visitors tracked without cookies. '
                . 'Until it runs, anonymous pageviews are still recorded but cost extra database '
                . 'work on every hit and cannot be grouped into visits reliably. The analytics '
                . 'table and its archive are each rebuilt in place — about 8 seconds per 440,000 '
                . 'rows on MySQL 8, so roughly double that if you also have archived data, and '
                . 'longer on bigger tables. Tracking and reports normally keep working while it '
                . 'runs, but a server that cannot rebuild online will pause tracking writes '
                . 'until it finishes. No existing data is changed or removed.',
            'wp-slimstat'
        );
    }

    public function shouldRun(): bool
    {
        // Either table missing the column means work remains: a run interrupted between
        // the two ALTERs must still report "needs to run" for the archive half.
        return !$this->columnExists('slim_stats', 'vid_hash')
            || !$this->columnExists('slim_stats_archive', 'vid_hash');
    }

    public function run(): bool
    {
        // The ALTER policy — manifest DDL, INPLACE-then-bare retry, degradation on
        // failure — lives on AbstractMigration::addManifestColumn(), one owner shared
        // with AddUserAgentDimension rather than a second verbatim copy of it here.
        //
        // Two literal calls, not a loop: the schema-single-source gate resolves LITERAL
        // (table, column) arguments against the manifest, and a variable table name is
        // a call site that gate cannot see (the same reason admin/index.php's legacy
        // block writes its six calls out longhand).
        $live = $this->addManifestColumn('slim_stats', 'vid_hash', 'add_visit_identity');

        // The index over vid_hash, which ensure() could not build on the upgrade pass because the
        // column did not exist yet — and will not revisit, because the same request stamped the
        // new version. Without it an upgraded install serves the anonymous reuse probe as an
        // unindexed 30-minute range scan on the fact table, per anonymous pageview, for the whole
        // life of this release, while a fresh install has had the index since CREATE TABLE.
        //
        // KEYED ON THE LIVE TABLE'S OWN ALTER, never on the pair. Gating it on both — the first
        // shape of this fix — means an archive ALTER that fails (bigger table, MyISAM, lock
        // timeout) leaves `slim_stats` carrying `vid_hash` with no index, while shouldRun() stays
        // true and every retry re-fails on the archive without ever reaching the index. That is
        // precisely the defect this repairs, reached through the repair's own control flow.
        //
        // slim_stats_archive gets no call at all: it declares the same index set with
        // `reconcile => false`, so a call would return at the guard on every possible run — and
        // would quietly start building the archive's whole declared index set on cold storage the
        // day E1 flips that flag.
        if ($live) {
            $this->reconcileColumnIndexes('slim_stats', 'vid_hash', 'add_visit_identity');
        }

        // Short-circuit preserved: the archive ALTER is attempted only when the live one landed.
        $ok = $live && $this->addManifestColumn('slim_stats_archive', 'vid_hash', 'add_visit_identity');

        if ($ok) {

            // The column this adds changes what visitor_id_expr() emits, and the
            // goal/funnel/unique-visitor transients are LADDER-BLIND — their keys
            // hash range + filters + version, never the SQL — so answers computed
            // under the degraded ladder would otherwise serve for up to 15 more
            // minutes after the schema is whole. Rotating the version orphans them
            // now. Same option clear_goals_cache() rotates, microtime for its same
            // two-writes-in-one-second reason; blind review measured the window.
            update_option('slimstat_goals_cache_ver', (string) microtime(true), false);
        }

        return $ok;
    }

    public function getDiagnostics(): array
    {
        // One row per table, in the base contract's shape — `exists` false on either row
        // is what shouldRun() reports as remaining work, so the diagnostics and the
        // decision cannot tell different stories.
        $rows = [];
        foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
            $rows[] = [
                'key'     => $this->getId(),
                'exists'  => $this->columnExists($suffix, 'vid_hash'),
                'table'   => $this->tablePrefix() . $suffix,
                'columns' => 'vid_hash',
            ];
        }

        return $rows;
    }
}
