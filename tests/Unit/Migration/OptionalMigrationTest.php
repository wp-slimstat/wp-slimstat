<?php
/**
 * An OFFERED migration is listed and runnable, and it never asks for anything.
 *
 * WHY THIS EXISTS. Run 9 measured what F10 Layer 1 buys on the read path and the answer was
 * nothing — the star schema cannot pay while P4 keeps `browser`, `browser_version`,
 * `browser_type` and `platform` on the fact row, because the dimension is then an index over
 * data that is already there and `idx_dt_browser_browser_version` already indexes it better.
 *
 * That leaves a column whose cost is entirely real — a fact-table rebuild, ~14 s at 443k rows and
 * ~5 min at 10M — and whose benefit is entirely future. A fresh install pays none of it (the
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

    public function test_an_offered_migration_can_still_be_run_by_name(): void
    {
        $offered = $this->offered();
        $manager = $this->manager([$offered]);

        $this->assertTrue($manager->runOne('offered-migration'));
        $this->assertTrue($offered->ran, 'the screen could list it and never run it');
    }
}
