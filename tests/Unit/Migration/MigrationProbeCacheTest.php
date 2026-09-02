<?php
/**
 * The offered-migration probe must cost its queries once per request, and once per twelve hours.
 *
 * THE DEFECT. `MigrationAdmin::registerPage()` guards on
 * `!needsMigration() && [] === getOfferedMigrations()`. the LEFT operand is a NEGATION, so it is true exactly
 * when nothing is owed — and the right side therefore runs in the healthy steady state.
 * `getOfferedMigrations()` had no cache. It is reached from registerPage() on `admin_menu` — so
 * every admin page load pays it — and twice more on the migration screen itself. maybeShowNotice()
 * on `admin_notices` asks needsMigration(), NOT this.
 *
 * WHY THIS FILE DRIVES BOTH CALLS. A budget test that calls `getOfferedMigrations()` once passes
 * on machinery that was already there: `ConvertTablesToUtf8mb4::shouldRun()` memoises on its own
 * `$shouldRunCache`, so its six `information_schema` aggregates are paid once per INSTANCE
 * regardless of this fix. `AddUserAgentDimension::shouldRun()` does not memoise and re-probes on
 * the second call. So the defect is only visible across two calls in one request — which is
 * exactly the shape the admin page produces, and exactly what a one-call test would miss.
 *
 * The doubles below count `shouldRun()` invocations rather than SQL, because the queries live
 * inside each migration's own `shouldRun()` and counting there is the same measurement one level
 * up, without needing a database.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use SlimStat\Migration\MigrationInterface;
use SlimStat\Migration\MigrationManager;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class MigrationProbeCacheTest extends WpSlimstatTestCase
{
    /** Transient store shared by the stubs, so a set is visible to the next get. */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transients = [];

        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;

            return true;
        });
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);

            return true;
        });
    }

    /**
     * A migration double that counts how often it is probed.
     *
     * `$probes` is by reference so the test can read the count without reaching into the object.
     */
    private function migration(string $id, bool $optional, bool $shouldRun, int &$probes): MigrationInterface
    {
        return new class ($id, $optional, $shouldRun, $probes) implements MigrationInterface {
            private string $id;
            private bool $optional;
            private bool $should;
            /** @var int */
            private $probes;

            public function __construct(string $id, bool $optional, bool $should, int &$probes)
            {
                $this->id       = $id;
                $this->optional = $optional;
                $this->should   = $should;
                $this->probes   = &$probes;
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getName(): string
            {
                return $this->id;
            }

            public function isOptional(): bool
            {
                return $this->optional;
            }

            public function shouldRun(): bool
            {
                $this->probes++;

                return $this->should;
            }

            public function run(): bool
            {
                return true;
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getDiagnostics(): array
            {
                return [];
            }
        };
    }

    /** A double whose probe reports the database unreachable, so the cache must not be written. */
    private function unreachable(int &$probes): MigrationInterface
    {
        return new class ($probes) implements MigrationInterface {
            /** @var int */
            private $probes;

            public function __construct(int &$probes)
            {
                $this->probes = &$probes;
            }

            public function getId(): string
            {
                return 'unreachable';
            }

            public function getName(): string
            {
                return 'unreachable';
            }

            public function isOptional(): bool
            {
                return true;
            }

            public function shouldRun(): bool
            {
                $this->probes++;

                return false;
            }

            public function probeUnavailable(): bool
            {
                return true;
            }

            public function run(): bool
            {
                return true;
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getDiagnostics(): array
            {
                return [];
            }
        };
    }

    private function manager(int &$probes): MigrationManager
    {
        $manager = new MigrationManager();
        $manager->register($this->migration('optional-a', true, true, $probes));
        $manager->register($this->migration('optional-b', true, false, $probes));

        return $manager;
    }

    /** @test */
    public function test_two_call_sites_in_one_request_probe_once(): void
    {
        // The assertion a single-call test cannot make: before this fix every call paid the full
        // probe, and the migration screen makes three of them in one request.
        //
        // Note what this does NOT isolate. Removing the per-request memo leaves this GREEN,
        // because the first call writes the transient and the second reads it — verified by
        // perturbation. The memo's own value is on the path where the transient is deliberately
        // not written, which is test_an_unavailable_probe_is_still_only_made_once() below.
        $probes  = 0;
        $manager = $this->manager($probes);

        $first  = $manager->getOfferedMigrations();   // registerPage()
        $second = $manager->getOfferedMigrations();   // enqueueAssets() / renderPage()

        $this->assertSame(2, $probes, 'each optional migration must be probed exactly once');
        $this->assertCount(1, $first);
        $this->assertSame($first, $second, 'the second call must return the same set');
    }

    /** @test */
    public function test_a_warm_transient_probes_nothing_at_all(): void
    {
        // The across-request half. A per-request memo dies with the request; without the
        // transient the next page load pays the full probe again, forever, because the release
        // notes tell owners not to run the optional migrations.
        $probes  = 0;
        $this->manager($probes)->getOfferedMigrations();
        $this->assertSame(2, $probes, 'the cold pass probes');

        $fresh   = 0;
        $offered = $this->manager($fresh)->getOfferedMigrations();

        $this->assertSame(0, $fresh, 'a warm transient must issue no probe at all');
        $this->assertCount(1, $offered, 'and must still report the right set');
        $this->assertSame('optional-a', reset($offered)->getId());
    }

    /** @test */
    public function test_an_unreachable_database_is_never_cached(): void
    {
        // The rule needsMigration() already states: "I could not look" must not be persisted as
        // "nothing to offer". This cache lives twelve hours and only run/dismiss clears it, so
        // caching an unreachable database would hide the offered steps for half a day after the
        // admin fixed the configuration that broke it.
        $probes  = 0;
        $manager = new MigrationManager();
        $manager->register($this->unreachable($probes));

        $manager->getOfferedMigrations();

        $this->assertArrayNotHasKey(
            'slimstat_migration_offered',
            $this->transients,
            'an unavailable probe must leave the cache unwritten'
        );
    }

    /** @test */
    public function test_forget_probe_clears_the_offered_cache(): void
    {
        // A cache nothing invalidates is worse than no cache: after a migration runs, the screen
        // would keep offering it until the transient expires.
        $probes = 0;
        $this->manager($probes)->getOfferedMigrations();
        $this->assertArrayHasKey('slimstat_migration_offered', $this->transients);

        $manager = $this->manager($probes);
        $manager->forgetProbe();

        $this->assertArrayNotHasKey(
            'slimstat_migration_offered',
            $this->transients,
            'forgetProbe() must delete the offered cache, not only the required one'
        );
    }

    /** @test */
    public function test_an_unavailable_probe_is_still_only_made_once(): void
    {
        // THIS is what the memo buys, and nothing else does. When a probe reports the database
        // unreachable the transient is deliberately NOT written — so without a per-request memo
        // both call sites re-probe an unreachable database on every admin page load, which is the
        // worst case rather than the cheap one.
        $probes  = 0;
        $manager = new MigrationManager();
        $manager->register($this->unreachable($probes));

        $manager->getOfferedMigrations();   // registerPage()
        $manager->getOfferedMigrations();   // enqueueAssets() / renderPage()

        $this->assertSame(1, $probes, 'an unreachable probe must be attempted once per request');
        $this->assertArrayNotHasKey('slimstat_migration_offered', $this->transients);
    }

    /** @test */
    public function test_an_empty_offered_set_is_cached_not_re_probed(): void
    {
        // THE COMMON CASE, not an edge case: once both optional migrations have been run the
        // offered set is empty forever, and that is exactly the state a healthy install sits in.
        //
        // The cache stores `[]`, and `get_transient()` returns `false` on a MISS. Those must not
        // be confused: `is_array([])` is true so the empty set is served from cache, while
        // `is_array(false)` is false so a genuine miss still probes. Storing a verdict that is
        // falsy is how a cache silently degrades into no cache at all.
        $probes  = 0;
        $manager = new MigrationManager();
        $manager->register($this->migration('optional-a', true, false, $probes));
        $manager->register($this->migration('optional-b', true, false, $probes));

        $this->assertSame([], $manager->getOfferedMigrations());
        $this->assertSame(2, $probes, 'the cold pass probes both');
        $this->assertArrayHasKey(
            'slimstat_migration_offered',
            $this->transients,
            'an empty offered set must still be cached'
        );

        $fresh = 0;
        $again = new MigrationManager();
        $again->register($this->migration('optional-a', true, false, $fresh));
        $again->register($this->migration('optional-b', true, false, $fresh));

        $this->assertSame([], $again->getOfferedMigrations());
        $this->assertSame(0, $fresh, 'an empty cached set must not be mistaken for a cache miss');
    }
}
