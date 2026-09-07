<?php
declare(strict_types=1);
function slimstat_network_rehearsal_compare(array $before, array $after): array
{
    $failures = [];
    if (array_keys($before['blogs']) !== array_keys($after['blogs'])) { $failures[] = 'blog-inventory'; }
    foreach ($before['blogs'] as $id => $blog) {
        $current = $after['blogs'][$id] ?? [];
        foreach (['fingerprint', 'archived'] as $key) {
            if (($current[$key] ?? null) !== $blog[$key]) { $failures[] = 'blog.' . $id . '.' . $key; }
        }
        if ([] !== ($current['missing'] ?? null)) { $failures[] = 'blog.' . $id . '.missing-tables'; }
    }
    if ([] !== $after['pending']) { $failures[] = 'pending-cursor'; }
    return $failures;
}
