<?php
/** Pure lifecycle oracle; snapshots contain hashes/credential presence, never secrets. */
declare(strict_types=1);
function slimstat_uninstall_compare(array $before, array $after, string $mode, string $owner, bool $paired): array
{
    if ('keep-no' === $mode) { $mode = 'keep'; }
    $refusedPath = 'delete-path-refused' === $mode;
    if ($refusedPath) { $mode = 'delete'; }
    $failures = [];
    $same = static function ($a, $b, string $leg) use (&$failures) { if ($a !== $b) { $failures[] = $leg; } };
    $same($before['sentinels'], $after['sentinels'], 'unrelated.tables');
    foreach ($before['tables'] as $database => $tables) {
        foreach ($tables as $table => $hash) {
            $same('delete' === $mode && $owner === $database ? null : $hash,
                $after['tables'][$database][$table] ?? null, 'tables.' . $database . '.' . $table);
        }
    }
    $same($before['files']['unrelated'], $after['files']['unrelated'], 'uploads.unrelated');
    foreach (['geo', 'cache'] as $file) {
        $removed = !$refusedPath && ('delete' === $mode || ('keep' === $mode && 'cache' === $file));
        $same($removed ? null : $before['files'][$file], $after['files'][$file], 'uploads.' . $file);
    }
    foreach ($before['blogs'] as $id => $blog) {
        $current = $after['blogs'][$id] ?? [];
        foreach ($blog as $key => $value) {
            $expected = $value;
            if (in_array($mode, ['keep', 'delete'], true) && in_array($key, ['credentials', 'free_cron'], true)) { $expected = false; }
            if ('delete' === $mode && in_array($key, ['settings', 'pro_setting', 'layout_metadata'], true)) { $expected = false; }
            if ('delete' === $mode && 'free_setting' === $key) { $expected = null; }
            if ('pro' === $mode && in_array($key, ['pro_setting', 'pro_cron'], true)) { $expected = false; }
            if (false === $expected && is_array($value)) { $expected = array_fill_keys(array_keys($value), false); }
            $same($expected, $current[$key] ?? null, 'blog.' . $id . '.' . $key);
        }
    }
    // Pro-only credentials are recorded, not adjudicated: removing the connection can break
    // retained Free analytics. The plan's no-password policy needs an explicit disposition.
    $same(in_array($mode, ['keep', 'delete'], true) ? false : $before['network_credentials'], $after['network_credentials'], 'network.credentials');
    $same($before['network_free_setting'], $after['network_free_setting'], 'network.free-setting');
    $same('pro' === $mode ? false : $before['network_pro_setting'], $after['network_pro_setting'], 'network.pro-setting');
    $same('pro' === $mode, $after['plugins']['free'], 'free.files');
    $same($paired && 'pro' !== $mode, $after['plugins']['pro'], 'pro.files');
    return $failures;
}
