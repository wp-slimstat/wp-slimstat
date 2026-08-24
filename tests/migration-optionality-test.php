<?php
/**
 * Every migration declares whether it is OWED or OFFERED, and the answer is written down here.
 *
 * ── Why a whole-set gate rather than another per-class case ─────────────────────────────────
 *
 * `ConvertTablesToUtf8mb4` shipped as REQUIRED because it inherited
 * `AbstractMigration::isOptional()`, which returns false. Required means the admin notice
 * demands it on every page of every upgrading site, and what it was demanding is an
 * ALGORITHM=COPY rebuild that blocks writes — measured at 12.4 s on the real 443,535-row table,
 * ~5 minutes at 10M — against its own header ("behind an explicit click ... the site owner
 * chooses when to take the write pause") and against ADR-6.
 *
 * The defect was not that someone chose wrongly. It is that NOBODY CHOSE: the value arrived by
 * inheritance and no test asked. A case pinning that one class fixes the instance and leaves
 * the mechanism, and the next migration added inherits `false` in silence with every suite
 * still green. So the subject here is the SET.
 *
 * Adding a migration therefore fails this gate until its row is added, which is the point: the
 * cost of a migration is a product decision and this file is where it is recorded.
 *
 * ── Why source-level rather than instantiation ───────────────────────────────────────────────
 *
 * `isOptional()` is a pure return in every implementation, but reaching it through a constructor
 * means a wpdb double per class, and `shouldRun()` on several of them talks to
 * information_schema. Reading the declaration is what this gate is about: whether the class
 * SAYS which it is. Behaviour — that offered migrations raise no notice and stay runnable — is
 * pinned by tests/Unit/Migration/OptionalMigrationTest.php against a real class.
 */

declare(strict_types=1);

// Tokenised, never raw text. `tests/source-scan-strength-test.php` refuses a new raw-text
// scanner of production source, and it is right to: `function isOptional(): bool` written inside
// a docblock or a string would satisfy a bare preg_match, and this gate's whole subject is
// whether a class SAYS which it is. slimstat_function_body() additionally throws rather than
// matching a mention, so "declared" here means declared.
require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);

// The declared table. `true` = OFFERED (listed on the Migration screen, no notice);
// `false` = OWED (the notice demands it).
$declared = [
    // Two metadata-only ALTERs, INSTANT on MySQL 8. Until it lands every anonymous pageview
    // pays the failed-INSERT-probe-retry dance and loses its identity field, so this is the
    // one the notice should be about.
    'AddVisitIdentity'                 => false,
    // Run 9 measured that the star-schema dimension buys nothing on the read path while P4
    // keeps the browser columns on the fact row. Cost real, benefit future.
    'AddUserAgentDimension'            => true,
    // A write-blocking rebuild whose benefit is real (11x on the Pro user join) but whose
    // timing is the owner's to choose. ADR-6.
    'ConvertTablesToUtf8mb4'           => true,
    // Index builds. Online on every supported server, and the reports are slow without them.
    'CreateCountryDtIndex'             => false,
    'CreateDtBrowserIndex'             => false,
    'CreateDtOutIndex'                 => false,
    'CreateDtPlatformIndex'            => false,
    'CreateDtScreenIndex'              => false,
    'CreateEventsNotesDtIndex'         => false,
    'CreateFunnelQueriesIndex'         => false,
    'CreateGoalQueriesIndex'           => false,
    // Repairs corrupted rows. Data is wrong until it runs.
    'RecoverCorruptedHeatmapPositions' => false,
];

$dir   = $plugin_root . '/src/Migration/Migrations';
$files = glob($dir . '/*.php');
if (false === $files || [] === $files) {
    fwrite(STDERR, "FAIL: no migrations found under src/Migration/Migrations\n");
    exit(1);
}

$failures = [];
$found    = [];

foreach ($files as $file) {
    $name     = basename($file, '.php');
    $found[]  = $name;
    $source   = (string) file_get_contents($file);

    // Does the class state it, or inherit it? A declaration is what makes the choice deliberate.
    $declares = false;
    $returns  = null;
    try {
        $body     = slimstat_function_body($source, 'isOptional');
        $declares = true;
        if (preg_match('/return\s+(true|false)\s*;/', $body, $m)) {
            $returns = 'true' === $m[1];
        }
    } catch (RuntimeException $e) {
        // No definition here — the class inherits AbstractMigration::isOptional().
    }

    if (!array_key_exists($name, $declared)) {
        $failures[] = sprintf(
            '%s is not in this gate\'s table. Add a row saying whether it is OWED (false) or '
            . 'OFFERED (true), and say why — a migration whose cost nobody decided is how the '
            . 'utf8mb4 rebuild came to be demanded of every upgrading site.',
            $name
        );
        continue;
    }

    $expected = $declared[$name];
    $actual   = $declares ? $returns : false;   // no declaration means AbstractMigration's false

    if (null === $returns && $declares) {
        $failures[] = sprintf('%s declares isOptional() but not as a plain return; re-anchor this gate', $name);
        continue;
    }

    if ($actual !== $expected) {
        $failures[] = sprintf(
            '%s is %s and the table says %s',
            $name,
            $actual ? 'OFFERED' : 'OWED',
            $expected ? 'OFFERED' : 'OWED'
        );
    }

    // An OFFERED migration must SAY so. Inheriting `false` is how the defect happened; the
    // mirror — inheriting a `true` from somewhere — must not become possible either.
    if ($expected && !$declares) {
        $failures[] = sprintf('%s is offered by inheritance rather than by declaration', $name);
    }
}

foreach (array_keys($declared) as $name) {
    if (!in_array($name, $found, true)) {
        $failures[] = sprintf('%s is in this gate\'s table but no longer in the tree', $name);
    }
}

if ([] !== $failures) {
    fwrite(STDERR, sprintf("FAIL: migration optionality (%d problem(s))\n", count($failures)));
    foreach ($failures as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    exit(1);
}

printf(
    "PASS: migration optionality (%d migrations, %d offered, %d owed — every one declared)\n",
    count($found),
    count(array_filter($declared)),
    count($declared) - count(array_filter($declared))
);
