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
    private const COLUMNS = ['city', 'username', 'email', 'fingerprint'];
    private const ARCHIVE_COLUMNS = ['dt_out', 'tz_offset'];
    private $state;

    public function getId(): string { return 'repair-legacy-column-widths'; }
    public function getName(): string { return __('Repair legacy analytics and archive columns', 'wp-slimstat'); }
    public function isOptional(): bool { return true; }

    public function getDescription(): string
    {
        return __('Widens legacy city, username, email and fingerprint columns from 255 to 256 characters and adds missing legacy archive columns. Existing historical values are preserved; newly added fields use their declared defaults and cannot recover previously unrecorded data. Runs only when you choose it here. Existing values, nullability and collation are preserved. Only online InnoDB changes are attempted; the repair stops if the server requires blocking writes or finds custom column attributes. A brief metadata lock may still be needed. Large tables can take longer than one request; retry resumes from the remaining table.', 'wp-slimstat');
    }

    private function readColumns(string $suffix): ?array
    {
        $suppressed = $this->wpdb->suppress_errors(true);
        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are fixed migration columns/core prefix; lookup values are prepared and session timeout is integer-cast.
        $rows = $this->wpdb->get_results(sprintf('SHOW FULL COLUMNS FROM `%s`', $this->tablePrefix() . $suffix), ARRAY_A);
        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
        $failed = $this->probeFailed();
        $this->wpdb->suppress_errors($suppressed);
        if ($failed || !is_array($rows) || [] === $rows) { return null; }
        $columns = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field'])) { $columns[strtolower((string) $row['Field'])] = $row; }
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
            if (null === $columns) { $state['blocked'] = true; continue; }
            $names = 'slim_stats_archive' === $suffix ? array_merge(self::COLUMNS, self::ARCHIVE_COLUMNS) : self::COLUMNS;
            foreach ($names as $name) {
                $column = $columns[$name] ?? null;
                if (null === $column && 'slim_stats_archive' === $suffix) {
                    $state['pending'][$suffix]['missing'][] = $name;
                    continue;
                }
                if (in_array($name, self::ARCHIVE_COLUMNS, true)) { continue; }
                if (is_array($column) && 'varchar(256)' === strtolower((string) ($column['Type'] ?? ''))) {
                    continue;
                }
                try {
                    Schema::widenLegacyColumnsSql($suffix, $this->tablePrefix(), [$column ?? []]);
                    $state['pending'][$suffix]['widen'][] = $column;
                } catch (\InvalidArgumentException $e) {
                    $state['blocked'] = true;
                }
            }
            if (!empty($state['pending'][$suffix])) {
                // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are fixed migration columns/core prefix; lookup values are prepared and session timeout is integer-cast.
                $engine = $this->wpdb->get_var($this->wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $this->tablePrefix() . $suffix
                ));
                // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
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
                // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are fixed migration columns/core prefix; lookup values are prepared and session timeout is integer-cast.
                if (false === $this->wpdb->query(Schema::widenLegacyColumnsSql($suffix, $this->tablePrefix(), $columns['widen'] ?? [], $columns['missing'] ?? []))) {
                    $ok = $this->fail('The server refused the online legacy width repair. No blocking fallback was attempted; review the database error before retrying.');
                // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
                    break;
                }
                $after = $this->readColumns($suffix);
                foreach ($columns['widen'] ?? [] as $before) {
                    $actual = $after[strtolower((string) $before['Field'])] ?? [];
                    $expected = $before;
                    $expected['Type'] = 'varchar(256)';
                    foreach (['Field', 'Type', 'Collation', 'Null', 'Default', 'Extra', 'Comment'] as $field) {
                        if (!array_key_exists($field, $actual) || $expected[$field] !== $actual[$field]) {
                            $ok = $this->fail('Legacy width repair could not verify the resulting definition. Completion was not recorded; inspect the database before retrying.');
                            break 3;
                        }
                    }
                }
                foreach ($columns['missing'] ?? [] as $name) {
                    $actual = $after[$name] ?? [];
                    $definition = Schema::columns($suffix)[$name];
                    // Integer display widths disappear on MySQL 8; signedness and defaults do not.
                    $type = strtolower(explode(' DEFAULT ', $definition)[0]);
                    $normalize = static function (string $value): string { return preg_replace('/int\(\d+\)/', 'int', strtolower($value)); };
                    $default = false !== strpos($definition, 'DEFAULT 0') ? '0' : null;
                    if (!isset($actual['Type'], $actual['Null']) || 'YES' !== $actual['Null']
                        || $normalize((string) $actual['Type']) !== $normalize($type)
                        || !array_key_exists('Default', $actual)
                        || ($default === null ? $actual['Default'] !== null : (string) $actual['Default'] !== $default)) {
                        $ok = $this->fail('Legacy archive repair could not verify the added column. Completion was not recorded; inspect the database before retrying.');
                        break 2;
                    }
                }
            }
        } finally {
            $this->state = null;
            // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are fixed migration columns/core prefix; lookup values are prepared and session timeout is integer-cast.
            if (false === $this->wpdb->query('SET SESSION lock_wait_timeout = ' . (int) $oldTimeout)) {
                $ok = $this->fail('Legacy width repair could not restore the connection metadata-lock timeout.');
            // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
            }
        }
        return $ok;
    }

    public function getDiagnostics(): array
    {
        $state = $this->inspect();
        $rows = [];
        foreach (self::TABLES as $suffix) {
            foreach ('slim_stats_archive' === $suffix ? array_merge(self::COLUMNS, self::ARCHIVE_COLUMNS) : self::COLUMNS as $name) {
                $rows[] = ['key' => $this->getId(), 'table' => $this->tablePrefix() . $suffix,
                    'columns' => $name, 'exists' => in_array($name, self::ARCHIVE_COLUMNS, true) ? isset($state['columns'][$suffix][$name]) : 'varchar(256)' === strtolower((string) ($state['columns'][$suffix][$name]['Type'] ?? ''))];
            }
        }
        return $rows;
    }
}
