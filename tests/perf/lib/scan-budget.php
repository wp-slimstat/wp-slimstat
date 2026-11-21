<?php
declare(strict_types=1);

/** Normalize fixture identity and timestamps only; predicates and limits remain significant. */
function slimstat_scan_shape(string $sql, string $prefix, string $host): string
{
    $sql = str_replace($prefix . 'slim_', '{prefix}slim_', $sql);
    if ($host !== '') { $sql = str_replace($host, '{host}', $sql); }
    $sql = (string) preg_replace('/\b[0-9]{10}\b/', '{timestamp}', $sql);
    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

/** Exact reviewed aggregate shapes only. New/selective queries keep the strict scan gate. */
function slimstat_scan_budget(array $budgets, string $shape, string $context): ?array
{
    $report = explode(' | ', $context)[0];
    foreach ($budgets as $budget) {
        if ($budget['report'] === $report && $budget['sql'] === $shape) { return $budget; }
    }
    return null;
}

/** No retry/median can hide a slow sample or excess work. */
function slimstat_scan_within_budget(array $samples, int $rows, array $budget): bool
{
    if (count($samples) !== 3 || $rows <= 0) { return false; }
    foreach ($samples as $sample) {
        if (!isset($sample['ms'], $sample['reads']) || !is_finite((float) $sample['ms'])
            || $sample['ms'] < 0 || $sample['reads'] < 0
            || $sample['ms'] > $budget['max_ms']
            || $sample['reads'] > $rows * $budget['max_reads_per_row'] + 100) {
            return false;
        }
    }
    return true;
}

/** Measure on the raw connection; include temporary-table/index work in the read budget. */
function slimstat_measure_scan(wpdb $db, string $sql): array
{
    $reads = static function () use ($db): int {
        $values = $db->get_results("SHOW SESSION STATUS LIKE 'Handler_read%'", ARRAY_A);
        if (!$values || $db->last_error) { throw new RuntimeException('Cannot read query work counters'); }
        return array_sum(array_map('intval', array_column($values, 'Value')));
    };
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $before = $reads();
        $start = microtime(true);
        $db->get_results($sql, ARRAY_A);
        $ms = (microtime(true) - $start) * 1000;
        if ($db->last_error) { throw new RuntimeException($db->last_error); }
        $samples[] = ['ms' => $ms, 'reads' => $reads() - $before];
    }
    return $samples;
}
