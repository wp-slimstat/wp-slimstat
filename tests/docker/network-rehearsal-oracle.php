<?php
declare(strict_types=1);
function slimstat_network_rehearsal_compare(array $before, array $after): array
{
    $failures = [];
    if (!in_array($before['owner'] ?? null, ['local', 'external'], true) || ($after['owner'] ?? null) !== $before['owner']) { $failures[] = 'analytics-owner'; }
    if (!is_bool($before['network_credentials_present'] ?? null) || ($after['network_credentials_present'] ?? null) !== $before['network_credentials_present']) { $failures[] = 'network-credentials'; }
    if (array_keys($before['blogs']) !== array_keys($after['blogs'])) { $failures[] = 'blog-inventory'; }
    foreach ($before['blogs'] as $id => $blog) {
        $current = $after['blogs'][$id] ?? [];
        foreach (['fingerprint', 'archived', 'domain', 'path', 'isolation_fingerprint', 'credentials_present'] as $key) {
            if (!array_key_exists($key, $blog) || !array_key_exists($key, $current) || $current[$key] !== $blog[$key]) { $failures[] = 'blog.' . $id . '.' . $key; }
        }
        if ([] !== ($current['missing'] ?? null)) { $failures[] = 'blog.' . $id . '.missing-tables'; }
    }
    if ([] !== $after['pending']) { $failures[] = 'pending-cursor'; }
    return $failures;
}

function slimstat_network_offline_compare(array $before, array $after): array
{
    $failures = [];
    if ('external' !== ($before['owner'] ?? null) || 'external' !== ($after['owner'] ?? null)) { $failures[] = 'offline-owner'; }
    if (!is_array($before['pending'] ?? null) || [] === $before['pending'] || ($after['pending'] ?? null) !== $before['pending']) { $failures[] = 'offline-pending'; }
    foreach (['local_unchanged', 'analytics_unavailable', 'outage_hit_lost'] as $key) {
        if (true !== ($after[$key] ?? null)) { $failures[] = 'offline.' . $key; }
    }
    return $failures;
}
