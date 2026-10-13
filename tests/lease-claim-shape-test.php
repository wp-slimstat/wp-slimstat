<?php
/**
 * Source-level: the C48 lease claim's SQL shape is the contract — F6 sub-seam 3.
 *
 * SchemaMetaTest proves claimLease()'s read-back logic against a double, but a double
 * never executes SQL, so the STEAL CONDITION and the ASSIGNMENT ORDER inside the upsert
 * are invisible to it — flip `dt < now` to `dt > now` and every unit test stays green
 * while every live lease becomes stealable. This gate pins the two facts the database
 * would enforce and the double cannot:
 *
 *   1. A standing lease is stolen only when EXPIRED (`dt < %d`) or already MINE
 *      (`meta_value = VALUES(meta_value)`).
 *   2. `dt` is assigned FIRST — while `meta_value` still holds the OLD holder — and
 *      `meta_value` keys off whether `dt` just became the new expiry. MySQL evaluates
 *      ON DUPLICATE KEY UPDATE assignments left to right, each seeing the previous
 *      ones' effects, so the order IS the semantics.
 *
 * Plus the two guards around the statement: the verdict comes from a READ-BACK
 * (never rows-affected), and release is HOLDER-GUARDED.
 *
 * Serves as the kill gate for the C48-lease-* mutations.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$source = (string) @file_get_contents(dirname(__DIR__) . '/src/Schema/Meta.php');
$failures = [];

if ('' === $source) {
    $failures[] = 'src/Schema/Meta.php is unreadable — the lease primitive is gone';
} else {
    $claim = slimstat_function_body($source, 'claimLease');

    if (!preg_match('/dt\s*=\s*IF\s*\(\s*meta_value\s*=\s*VALUES\(meta_value\)\s*OR\s*dt\s*<\s*%d\s*,\s*VALUES\(dt\)\s*,\s*dt\s*\)/', $claim)) {
        $failures[] = 'claimLease(): the steal condition is not `expired (dt < %d) or already '
            . 'mine` — inverted or loosened, every live lease is stealable by any concurrent '
            . 'claimer, which un-single-flights everything the lease protects';
    }

    if (!preg_match('/meta_value\s*=\s*IF\s*\(\s*dt\s*=\s*VALUES\(dt\)\s*,\s*VALUES\(meta_value\)\s*,\s*meta_value\s*\)/', $claim)) {
        $failures[] = 'claimLease(): meta_value is no longer keyed off dt\'s outcome — the holder '
            . 'can change without the expiry changing, or vice versa, splitting the lease row\'s '
            . 'two halves between two claimers';
    }

    $dt_at    = strpos($claim, 'dt         = IF(');
    $value_at = strpos($claim, 'meta_value = IF(');
    if (false === $dt_at || false === $value_at || $dt_at > $value_at) {
        $failures[] = 'claimLease(): dt must be assigned BEFORE meta_value — assignments run left '
            . 'to right and the second reads the first\'s effect; reordered, the dt assignment '
            . 'compares against the NEW holder and self-poisons';
    }

    if (!preg_match('/SELECT meta_value, dt FROM/', $claim)
        || !preg_match('/\(int\)\s*\$row->dt\s*>\s*\$now/', $claim)) {
        $failures[] = 'claimLease(): the verdict no longer comes from a read-back holding my name '
            . 'unexpired — rows-affected answers 0, 1 or 2 and none of those says who holds the '
            . 'row under a concurrent writer';
    }

    $release = slimstat_function_body($source, 'releaseLease');
    if (!preg_match('/meta_key\s*=\s*%s\s+AND\s+meta_value\s*=\s*%s/', $release)) {
        $failures[] = 'releaseLease(): the delete is no longer holder-guarded — a process whose '
            . 'lease was stolen can release the thief\'s lease';
    }
}

echo "\n";
if ([] !== $failures) {
    fwrite(STDERR, 'FAIL: lease claim shape (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: the lease claim steals only expired-or-mine, assigns dt before meta_value, "
    . "answers from a read-back, and releases only its own row\n";
exit(0);
