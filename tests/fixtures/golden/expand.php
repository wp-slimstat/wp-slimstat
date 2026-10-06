<?php
/**
 * Expand the golden fixture's visit declarations into individual pageview rows.
 *
 * Shared by the arithmetic gate and the environment loader so that "what the fixture is" has
 * one implementation. Two expanders would disagree, and the disagreement would be invisible
 * until one of them produced a confident wrong number (PITFALLS #5).
 *
 * Deterministic: no randomness, no clock. The same spec always yields the same rows in the same
 * order, which is what lets a checksum over them mean anything.
 */

declare(strict_types=1);

/**
 * @param  array $spec The array returned by spec.php.
 * @return array<int,array<string,mixed>> One row per pageview.
 */
function slimstat_golden_rows(array $spec): array
{
    $rows = [];

    foreach ($spec['visits'] as $visit) {
        $dt = strtotime($spec['days'][$visit['day']] . ' UTC');

        // Second-level offsets inside a visit, so dt is strictly increasing within it and a
        // "first page of the visit" or duration calculation has a defined answer. Without this
        // every row in a visit shares a timestamp and any ordering is arbitrary.
        $offset = 0;

        foreach ($visit['hits'] as $resource => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'blog_id'      => $visit['blog'],
                    'visit_id'     => $visit['visit'],
                    'ip'           => $visit['ip'],
                    'resource'     => $resource,
                    'dt'           => $dt + ($offset * 30),
                    'day'          => $visit['day'],
                    'browser_type' => 0,
                ];
                $offset++;
            }
        }
    }

    return $rows;
}

/** Rows for the blogs that count — i.e. excluding archived/deleted subsites. */
function slimstat_golden_counted_rows(array $spec): array
{
    $counted = [];
    foreach ($spec['blogs'] as $id => $blog) {
        if (!empty($blog['counted'])) {
            $counted[$id] = true;
        }
    }

    return array_values(array_filter(
        slimstat_golden_rows($spec),
        static function (array $row) use ($counted) {
            return isset($counted[$row['blog_id']]);
        }
    ));
}
