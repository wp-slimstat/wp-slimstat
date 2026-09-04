<?php
/**
 * The wpdb double AddVisitIdentity's tests share.
 *
 * Hoisted on the first commit that needed it twice, for the reason
 * `WpSlimstatTestCase::stubTransientCacheMiss()` records for itself: inline copies of a double
 * drift. The drift had already started here — adding `reconcileColumnIndexes()` to the migration
 * meant hand-editing a `get_col` expectation into a sibling test whose subject is the goals-cache
 * version and which does not care about indexes at all.
 *
 * It lives in the Migration test directory rather than on the global base case because it doubles
 * ONE migration's collaborators. `WpSlimstatTestCase` carries stubs every unit test needs;
 * `tests/Unit/Schema/SchemaEnsureTest.php` builds a much richer SQL-dispatching double for a
 * different job. Neither is the right home for this.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Mockery;

trait AddVisitIdentityDouble
{
    /**
     * @param bool|\Closure  $columnPresent  what SHOW COLUMNS reports for `vid_hash`. A closure
     *                                       receives the SQL and returns a bool, which is the only
     *                                       way to answer differently PER TABLE — needed by the
     *                                       archive-fails case, where the live table must report
     *                                       the column present and the archive must not.
     * @param string[]|\Closure $indexes     what SHOW INDEX reports, or a closure to compute it
     *                                       (a closure is how the unreadable-table case sets
     *                                       last_error at probe time, which is the ordering the
     *                                       real wpdb produces)
     */
    private function addVisitIdentityDb($columnPresent, $indexes): \wpdb
    {
        $wpdb             = Mockery::mock(\wpdb::class);
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $present = static fn (string $sql): array => $columnPresent instanceof \Closure
            ? ($columnPresent($sql)
                ? [['Field' => 'id', 'Type' => 'int'], ['Field' => 'vid_hash', 'Type' => 'binary(16)']]
                : [['Field' => 'id', 'Type' => 'int']])
            : ($columnPresent
                ? [['Field' => 'id', 'Type' => 'int'], ['Field' => 'vid_hash', 'Type' => 'binary(16)']]
                : [['Field' => 'id', 'Type' => 'int']]);

        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        $wpdb->shouldReceive('get_results')->andReturnUsing(
            static fn ($sql = '') => $present((string) $sql)
        );

        if ($indexes instanceof \Closure) {
            $wpdb->shouldReceive('get_col')->andReturnUsing(fn () => $indexes($wpdb));
        } else {
            $wpdb->shouldReceive('get_col')->andReturn($indexes);
        }

        return $wpdb;
    }
}
