<?php
/** Emit S5's raw golden rows and hand-ranked expectation for the Python oracle gate. */

declare(strict_types=1);

require_once __DIR__ . '/expand.php';

$spec = require __DIR__ . '/spec.php';
$case = $spec['expected']['top_resource_ranked'];

echo json_encode([
    'rows' => slimstat_golden_counted_rows($spec),
    'expected' => $case,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
