<?php
declare(strict_types=1);
namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;
use SlimStat\Migration\MigrationService;
use SlimStat\Schema\Schema;

/** Explicitly offered repair; never part of activation, tracking, or Apply All. */
class RepairLegacyColumnWidths extends AbstractMigration
{
    private const PASS_SECONDS = 10;
    private const TABLES = ['slim_stats', 'slim_stats_archive'];
    private const COLUMNS = ['city', 'username'];
    private $state;

    public function getId(): string { return 'repair-legacy-column-widths'; }
    public function getName(): string { return __('Repair legacy city and username column widths', 'wp-slimstat'); }
    public function isOptional(): bool { return true; }

    public function getDescription(): string
    {
        return __('Widens legacy city and username columns from 255 to 256 characters in analytics and archived data. '
            . 'Runs only when you choose it here. Existing values, nullability and collation are preserved. '
            . 'Only online InnoDB changes are attempted; the repair stops if the server requires blocking writes or finds '
            . 'custom column attributes. A brief metadata lock may still be needed. Large tables can take longer '
            . 'than one request; retry resumes from the remaining table.', 'wp-slimstat');
    }

    private function readColumns(string $suffix): ?array
    {
        $suppressed = $this->wpdb->suppress_errors(true);
        $rows = $this->wpdb->get_results(sprintf('SHOW FULL COLUMNS FROM `%s`', $this->tablePrefix() . $suffix), ARRAY_A);
        $failed = $this->probeFailed();
        $this->wpdb->suppress_errors($suppressed);
        if ($failed || !is_array($rows) || [] === $rows) { return null; }
        $columns = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field'])) { $columns[$row['Field']] = $row; }
        }
        return $columns;
    }

    private function inspect(): array
    {
        if (null !== $this->state) { return $this->state; }
        $state = ['pending' => [], 'blocked' => false, 'columns' => []];
        foreach (self::TABLES as $suffix) {
            $columns = $this->readColumns($suffix);
            $state['columns'][$suffix] = $columns ?? [];
            foreach (self::COLUMNS as $name) {
                $column = $columns[$name] ?? null;
                if (is_array($column) && 'varchar(256)' === strtolower((string) ($column['Type'] ?? ''))) {
                    continue;
                }
                try {
                    Schema::widenLegacyColumnsSql($suffix, $this->tablePrefix(), [$column ?? []]);
                    $state['pending'][$suffix][] = $column;
                } catch (\InvalidArgumentException $e) {
                    $state['blocked'] = true;
                }
            }
            if (!empty($state['pending'][$suffix])) {
                $engine = $this->wpdb->get_var($this->wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $this->tablePrefix() . $suffix
                ));
                if ($this->probeFailed() || 'innodb' !== strtolower((string) $engine)) {
                    $state['blocked'] = true;
                }
            }
        }
        return $this->state = $state;
    }

    public function shouldRun(): bool
    {
        $state = $this->inspect();
        return !$state['blocked'] && [] !== $state['pending'];
    }

    private function fail(string $message): bool
    {
        \wp_slimstat::record_degradation('legacy_column_width_repair', $message, \wp_slimstat::DEGRADATION_OPERATIONAL);
        return false;
    }

    public function run(): bool
    {
        if (MigrationService::migrationsDisabled()) { return false; }
        $this->state = null;
        $state = $this->inspect();
        if ($state['blocked']) {
            return $this->fail('Legacy width repair refused unexpected or unreadable column definitions. Ask your database administrator to review them; no columns were changed.');
        }
        if ([] === $state['pending']) { return true; }
        $oldTimeout = $this->wpdb->get_var('SELECT @@SESSION.lock_wait_timeout');
        if (!ctype_digit((string) $oldTimeout)
            || false === $this->wpdb->query('SET SESSION lock_wait_timeout = 5')) {
            return $this->fail('Legacy width repair could not set a safe metadata-lock wait; no columns were changed.');
        }
        $ok = true;
        $deadline = microtime(true) + self::PASS_SECONDS;
        $first = true;
        try {
            foreach ($state['pending'] as $suffix => $columns) {
                if (!$first && microtime(true) >= $deadline) {
                    $ok = $this->fail('Legacy width repair paused between tables. Retry to finish the remaining table; completed changes are retained.');
                    break;
                }
                $first = false;
                if (false === $this->wpdb->query(Schema::widenLegacyColumnsSql($suffix, $this->tablePrefix(), $columns))) {
                    $ok = $this->fail('The server refused the online legacy width repair. No blocking fallback was attempted; review the database error before retrying.');
                    break;
                }
                $after = $this->readColumns($suffix);
                foreach ($columns as $before) {
                    $actual = $after[$before['Field']] ?? [];
                    $expected = $before;
                    $expected['Type'] = 'varchar(256)';
                    foreach (['Field', 'Type', 'Collation', 'Null', 'Default', 'Extra', 'Comment'] as $field) {
                        if (!array_key_exists($field, $actual) || $expected[$field] !== $actual[$field]) {
                            $ok = $this->fail('Legacy width repair could not verify the resulting definition. Completion was not recorded; inspect the database before retrying.');
                            break 3;
                        }
                    }
                }
            }
        } finally {
            $this->state = null;
            if (false === $this->wpdb->query('SET SESSION lock_wait_timeout = ' . (int) $oldTimeout)) {
                $ok = $this->fail('Legacy width repair could not restore the connection metadata-lock timeout.');
            }
        }
        return $ok;
    }

    public function getDiagnostics(): array
    {
        $state = $this->inspect();
        $rows = [];
        foreach (self::TABLES as $suffix) {
            foreach (self::COLUMNS as $name) {
                $rows[] = ['key' => $this->getId(), 'table' => $this->tablePrefix() . $suffix,
                    'columns' => $name, 'exists' => 'varchar(256)' === strtolower((string) ($state['columns'][$suffix][$name]['Type'] ?? ''))];
            }
        }
        return $rows;
    }
}
