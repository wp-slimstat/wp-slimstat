<?php
/**
 * An OFFERED migration is listed and runnable, and it never asks for anything.
 *
 * WHY THIS EXISTS. Run 9 measured what F10 Layer 1 buys on the read path and the answer was
 * nothing — the star schema cannot pay while P4 keeps `browser`, `browser_version`,
 * `browser_type` and `platform` on the fact row, because the dimension is then an index over
 * data that is already there and `idx_dt_browser_browser_version` already indexes it better.
 *
 * That leaves a column whose cost is entirely real — a fact-table rebuild plus a backfill,
 * `AddUserAgentDimension::MEASURED_COST` — and whose benefit is entirely future. This header said
 * "~14 s at 443k rows and ~5 min at 10M" until the migration was actually run and went past eight
 * minutes on that table; it was the fourth copy of a figure measured for something else. A fresh
 * install pays none of it (the
 * column is in the manifest, so it arrives inside CREATE TABLE), but an upgrading site would be
 * shown a notice on every admin page asking it to.
 *
 * So `isOptional()` splits two questions that had been one:
 *
 *     shouldRun()   — is there work to do?          (unchanged)
 *     isOptional()  — is that work OWED, or OFFERED? (new)
 *
 * THE FAILURE MODE THIS PINS is not "optional migrations run anyway". It is the opposite, and
 * quieter: an optional migration that becomes INVISIBLE. Excluding it from the notice is the
 * point; excluding it from getMigrations() or from runOne() would make "opt-in" mean "gone", and
 * the difference is only observable from a test that asks for both.
 *
 * 7.4-safe: doubles only, no database, no WordPress option store beyond the test case's own.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use SlimStat\Migration\AbstractMigration;
use SlimStat\Migration\MigrationManager;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class OptionalMigrationTest extends WpSlimstatTestCase
{
    /** @var mixed The global handle as it stood before this case ran. */
    private $originalWpdb;

    /** AbstractMigration takes a wpdb; nothing here reaches the database, so a bare double does. */
    private function db(): \wpdb
    {
        $wpdb         = \Mockery::mock(\wpdb::class);
        $wpdb->prefix = 'wp_';

        return $wpdb;
    }

    private function owed(bool $shouldRun = true): AbstractMigration
    {
        return new class ($this->db(), $shouldRun) extends AbstractMigration {
            /** @var bool */
            public $ran = false;
            /** @var bool */
            private $should;

            public function __construct(\wpdb $wpdb, bool $should)
            {
                parent::__construct($wpdb);
                $this->should = $should;
            }

            public function getId(): string
            {
                return 'owed-migration';
            }

            public function getName(): string
            {
                return 'Owed';
            }

            public function getDescription(): string
            {
                return 'Owed';
            }

            public function shouldRun(): bool
            {
                return $this->should;
            }

            public function run(): bool
            {
                $this->ran = true;
                return true;
            }
        };
    }

    private function offered(): AbstractMigration
    {
        return new class ($this->db()) extends AbstractMigration {
            /** @var bool */
            public $ran = false;

            public function getId(): string
            {
                return 'offered-migration';
            }

            public function getName(): string
            {
                return 'Offered';
            }

            public function getDescription(): string
            {
                return 'Offered';
            }

            // Deliberately TRUE. "Optional" must not be expressed by lying about whether there is
            // work to do — a shouldRun() of false would also silence the notice, and would then
            // make the screen render it as already done.
            public function shouldRun(): bool
            {
                return true;
            }

            public function isOptional(): bool
            {
                return true;
            }

            public function run(): bool
            {
                $this->ran = true;
                return true;
            }
        };
    }

    /**
     * An in-memory option and transient store, so the manager's real caching runs.
     *
     * Not stubbed to no-ops: needsMigration() consults a dismissal option and a probe transient
     * BEFORE it walks the migrations, and runAll() writes a status map and re-probes afterwards.
     * Stubbing those away would let every assertion below pass against a manager that never
     * reached the loop they are about.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // get_option()/delete_option() are declared as REAL functions in tests/Unit/Tracker/
        // stubs.php, which the bootstrap loads before Patchwork — so Brain Monkey cannot
        // redefine them and refuses with DefinedTooEarly. They read $GLOBALS, which is the
        // seam that file provides for exactly this. update_option() and the transient family
        // are not declared there, so those go through Brain Monkey.
        $GLOBALS['slimstat_test_options'] = [];
        $transients                       = [];

        // runAll()/runOne() take a single-flight claim through OptionClaim, which goes straight
        // to `global $wpdb` rather than through the option API — the whole point of X7's fix is
        // that the claim is a database-level compare-and-swap and not a read-then-write. The
        // stub $GLOBALS['wpdb'] is a bare stdClass, so it has none of that surface.
        //
        // The claim is granted (query returns 1). Refusing it would make runAll() return early
        // and every assertion about what it did or did not run would pass vacuously.
        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;

        $wpdb          = \Mockery::mock(\wpdb::class);
        $wpdb->prefix  = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql) => $sql);
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(null);

        $GLOBALS['wpdb'] = $wpdb;

        \Brain\Monkey\Functions\stubs([
            'update_option'    => static function ($k, $v) {
                $GLOBALS['slimstat_test_options'][$k] = $v;
                return true;
            },
            // OptionClaim invalidates the option cache after a write it won.
            'wp_cache_delete'  => static fn() => true,
            'wp_cache_set'     => static fn() => true,
            'get_transient'    => static fn($k) => $transients[$k] ?? false,
            'set_transient'    => static function ($k, $v) use (&$transients) {
                $transients[$k] = $v;
                return true;
            },
            'delete_transient' => static function ($k) use (&$transients) {
                unset($transients[$k]);
                return true;
            },
        ]);
    }

    protected function tearDown(): void
    {
        $GLOBALS['slimstat_test_options'] = [];

        if (null === $this->originalWpdb) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    /** @param string[] $ids */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function manager(array $migrations): MigrationManager
    {
        $manager = new MigrationManager();
        foreach ($migrations as $migration) {
            $manager->register($migration);
        }
        $manager->forgetProbe();

        return $manager;
    }

    public function test_an_offered_migration_alone_does_not_raise_the_notice(): void
    {
        $manager = $this->manager([$this->offered()]);

        $this->assertFalse(
            $manager->needsMigration(),
            'an optional migration put a notice on every admin page asking for a fact-table '
                . 'rebuild that Run 9 measured as buying nothing yet'
        );
    }

    public function test_an_owed_migration_still_raises_the_notice(): void
    {
        // The vacuity control for the case above: a manager that answers false to everything
        // would satisfy it perfectly.
        $manager = $this->manager([$this->offered(), $this->owed()]);

        $this->assertTrue(
            $manager->needsMigration(),
            'skipping optional migrations silenced the notice for the owed ones beside them'
        );
    }

    public function test_offered_migrations_are_not_owed(): void
    {
        $manager = $this->manager([$this->offered(), $this->owed()]);

        $ids = array_map(
            static fn($m) => $m->getId(),
            array_values($manager->getRequiredMigrations())
        );

        $this->assertSame(['owed-migration'], $ids);
    }

    public function test_offered_migrations_are_reachable_through_the_set_the_screen_renders(): void
    {
        // THE HALF THAT MAKES "OPT-IN" MEAN OFFERED RATHER THAN GONE — and the first version of
        // this case asserted it against getMigrations(), which NO rendering path calls. It
        // passed while MigrationAdmin, which renders exclusively from getRequiredMigrations(),
        // showed nothing at all: no menu item, no row, and getDescription()'s "Optional…" copy
        // unreachable in every locale. A test can assert the right property about the wrong
        // collection and stay green forever.
        $manager = $this->manager([$this->offered(), $this->owed()]);

        $offered = array_map(
            static fn($m) => $m->getId(),
            array_values($manager->getOfferedMigrations())
        );

        $this->assertSame(['offered-migration'], $offered);

        // And the two sets must PARTITION the work — anything that has work to do is in exactly
        // one of them, or the screen either double-renders it or drops it.
        $required = array_map(
            static fn($m) => $m->getId(),
            array_values($manager->getRequiredMigrations())
        );

        $this->assertSame([], array_intersect($required, $offered));
        $this->assertSame(
            ['offered-migration', 'owed-migration'],
            $this->sorted(array_merge($required, $offered))
        );
    }

    public function test_a_migration_with_no_work_is_in_neither_set(): void
    {
        // Both sets filter on shouldRun(), so a finished migration disappears from the screen
        // rather than rendering as a row that does nothing.
        $manager = $this->manager([$this->owed(false)]);

        $this->assertSame([], $manager->getRequiredMigrations());
        $this->assertSame([], $manager->getOfferedMigrations());
    }

    public function test_apply_all_walks_past_an_offered_migration(): void
    {
        $offered = $this->offered();
        $owed    = $this->owed();
        $manager = $this->manager([$offered, $owed]);

        $results = $manager->runAll();

        $this->assertTrue($owed->ran, 'Apply All stopped applying what is owed');
        $this->assertFalse($offered->ran, 'Apply All ran a migration nobody asked for');

        // Absent from the map, not recorded true. Writing `true` for something that did not run
        // is how a status map starts lying, and this map is what the screen renders.
        $this->assertArrayNotHasKey('offered-migration', $results);
        $this->assertArrayHasKey('owed-migration', $results);
    }

    /**
     * The charset rebuild is OFFERED, and this is the case that says so about the real class.
     *
     * Every case above this one is about doubles, which is the right way to pin the manager's
     * partition and the wrong way to learn what ships. `ConvertTablesToUtf8mb4` inherited
     * `AbstractMigration::isOptional()`, which returns false, so it was OWED — and "owed" is not
     * a shade of emphasis on this screen. It is the difference between a notice on every admin
     * page saying the database "needs to be migrated" and a row on a page the owner chose to
     * open.
     *
     * What that notice was asking for, in the class's own words at the top of its file:
     *
     *     ALGORITHM=INPLACE is refused for this change ... So it is a full table rebuild that
     *     blocks writes for its duration, and on a tracking table blocked writes mean dropped
     *     pageviews.
     *     ... That is why this ships as a migration behind an explicit click rather than an
     *     upgrade hook: the site owner chooses when to take the write pause.
     *
     * Measured there at 12.4 s on the real 443,535-row table and extrapolated to ~5 minutes at
     * 10M rows. ADR-6 says the same thing — user-triggered, never an upgrade hook. So the
     * required flag contradicted both the class it sat on and the decision that governs it, and
     * the contradiction was invisible because no test asked.
     *
     * Pinned against the REAL class, not a double: a double would only re-assert the manager's
     * partition, which the cases above already prove.
     */
    public function test_the_charset_rebuild_is_offered_rather_than_owed(): void
    {
        $migration = $this->charsetRebuild();

        $this->assertTrue(
            $migration->isOptional(),
            'the utf8mb4 rebuild is OWED, so every upgrading site is told on every admin page to '
                . 'take a write pause that drops pageviews — which is the opposite of the '
                . '"explicit click" its own header and ADR-6 both promise'
        );

        // The vacuity control for the assertion above: isOptional() is only meaningful while
        // there is work to do. A migration answering false to shouldRun() is in neither set, so
        // an "offered" flag on a finished migration would prove nothing about the notice.
        $this->assertTrue($migration->shouldRun(), 'the fixture no longer has work to offer');
    }

    public function test_a_pending_charset_rebuild_raises_no_notice_and_stays_reachable(): void
    {
        $manager = $this->manager([$this->charsetRebuild()]);

        $this->assertFalse(
            $manager->needsMigration(),
            'a pending charset rebuild put the migration-required notice on every admin page'
        );

        // And OFFERED must not mean GONE — the quieter half, the one this file already records
        // as the failure mode worth pinning. The screen renders from these two sets, so absent
        // from both is invisible.
        $offered = array_map(
            static fn($m) => $m->getId(),
            array_values($manager->getOfferedMigrations())
        );
        $this->assertSame(['convert-tables-to-utf8mb4'], $offered);
        $this->assertSame([], $manager->getRequiredMigrations());
    }

    /**
     * The real ConvertTablesToUtf8mb4 over a doubled connection reporting stale columns.
     *
     * information_schema is answered with a table that has columns and has NOT been converted,
     * because that is the only state in which the owed/offered distinction is observable.
     */
    private function charsetRebuild(): \SlimStat\Migration\Migrations\ConvertTablesToUtf8mb4
    {
        $wpdb             = \Mockery::mock(\wpdb::class);
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql) => $sql);
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        // 30 columns, 3 of them still utf8mb3 — deliberately DIFFERENT numbers. The sibling
        // fixture in ExternalDatabaseMigrationTest uses the same pair for the same reason: C29's
        // live defect was code reading one where it meant the other, and `22/22` makes that
        // confusion invisible. `total` is also what tells a fully-converted table apart from one
        // that is not on this connection at all — both report zero stale.
        $wpdb->shouldReceive('get_row')->andReturn(['total' => 30, 'stale' => 3]);
        $wpdb->shouldReceive('get_var')->andReturn(null);

        return new \SlimStat\Migration\Migrations\ConvertTablesToUtf8mb4($wpdb, $this->db());
    }

    public function test_an_offered_migration_can_still_be_run_by_name(): void
    {
        $offered = $this->offered();
        $manager = $this->manager([$offered]);

        $this->assertTrue($manager->runOne('offered-migration'));
        $this->assertTrue($offered->ran, 'the screen could list it and never run it');
    }
}
