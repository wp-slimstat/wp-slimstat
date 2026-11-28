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
    private $failureMessage = '';

    public function getFailureMessage(): string { return $this->failureMessage; }

    public function getId(): string { return 'repair-legacy-column-widths'; }
    public function getName(): string { return __('Repair legacy analytics and archive columns', 'wp-slimstat'); }
    public function isOptional(): bool { return true; }

    public function getDescription(): string
    {
        return __('Widens legacy city, username, email and fingerprint columns from 255 to 256 characters and adds missing legacy archive columns. Existing historical values are preserved; newly added fields use their declared defaults and cannot recover previously unrecorded data. Runs only when you choose it here. Existing values, nullability and collation are preserved. Only online InnoDB changes are attempted; the repair stops if the server requires blocking writes or finds custom column attributes. A brief metadata lock may still be needed. Large tables can take longer than one request; retry resumes from the remaining table.', 'wp-slimstat');
    }

    private function columnsFor(string $suffix): array
    {
        return 'slim_stats_archive' === $suffix ? array_merge(self::COLUMNS, self::ARCHIVE_COLUMNS) : self::COLUMNS;
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
            $names = $this->columnsFor($suffix);
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

    /** Read-only recovery guidance, including when the online step cannot be offered. */
    public function getManualRepairGuidance(): array
    {
        $guidance = [];
        foreach (self::TABLES as $suffix) {
            $table = $this->tablePrefix() . $suffix;
            $columns = $this->readColumns($suffix);
            $widen = $missing = $reasons = [];
            $blocked = false;
            if (null === $columns) {
                $blocked = true;
                $reasons[] = __('SlimStat could not read this table. Ask your database host to check that it exists and that the analytics database account can read its definition.', 'wp-slimstat');
            } else {
                foreach ($this->columnsFor($suffix) as $name) {
                    $column = $columns[$name] ?? null;
                    if (null === $column && 'slim_stats_archive' === $suffix) {
                        $missing[] = $name;
                        continue;
                    }
                    if (in_array($name, self::ARCHIVE_COLUMNS, true)
                        || (is_array($column) && preg_match('/^varchar\((\d+)\)$/i', (string) ($column['Type'] ?? ''), $width) && (int) $width[1] >= 256)) {
                        continue;
                    }
                    try {
                        Schema::widenLegacyColumnsSql($suffix, $this->tablePrefix(), [$column ?? []]);
                        $widen[] = $column;
                    } catch (\InvalidArgumentException $e) {
                        $blocked = true;
                        if (null === $column) {
                            /* translators: %s: database column name. */
                            $reasons[] = sprintf(__('Column %s is missing. Ask your database host to review the table definition before adding it.', 'wp-slimstat'), $name);
                        } elseif ('varchar(255)' === strtolower((string) ($column['Type'] ?? ''))
                            && (isset($column['Default']) || '' !== ($column['Comment'] ?? '') || '' !== ($column['Extra'] ?? ''))) {
                            $attributes = [];
                            if (isset($column['Default'])) { $attributes[] = 'DEFAULT'; }
                            if ('' !== ($column['Comment'] ?? '')) { $attributes[] = 'COMMENT'; }
                            if ('' !== ($column['Extra'] ?? '')) { $attributes[] = 'EXTRA'; }
                            /* translators: 1: database column name, 2: SQL attribute names (DEFAULT, COMMENT, EXTRA). */
                            $reasons[] = sprintf(__('Column %1$s has custom attributes: %2$s. Ask your database host to widen it to VARCHAR(256), preserving all existing attributes and values. SlimStat does not generate SQL that could overwrite these attributes.', 'wp-slimstat'), $name, implode(', ', $attributes));
                        } else {
                            /* translators: %s: database column name. */
                            $reasons[] = sprintf(__('Column %s has an unsupported type or incomplete definition. Ask your database host to review its type, width, nullability and collation against VARCHAR(256) before changing it.', 'wp-slimstat'), $name);
                        }
                    }
                }
            }
            if (!$blocked && [] === $widen && [] === $missing) { continue; }
            // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Fixed metadata query; table name is a prepared value.
            $engine = $this->wpdb->get_var($this->wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
            ));
            // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
            if ($this->probeFailed() || !in_array(strtolower((string) $engine), ['innodb', 'myisam'], true)) {
                $blocked = true;
                $reasons[] = __('The table engine is unsupported or could not be read. Ask your database host to review it before attempting a repair.', 'wp-slimstat');
            } elseif ('myisam' === strtolower((string) $engine)) {
                $reasons[] = __('This table uses MyISAM, which cannot perform the online repair without blocking writes.', 'wp-slimstat');
            }
            $sql = '';
            if (!$blocked) {
                $reasons[] = __('If the online repair fails, the server may need to rebuild this table to widen its columns. The SQL below permits that rebuild and must be reviewed and run by your database host during a maintenance window.', 'wp-slimstat');
                $sql = str_replace('ALGORITHM=INPLACE, LOCK=NONE', 'ALGORITHM=COPY',
                    Schema::widenLegacyColumnsSql($suffix, $this->tablePrefix(), $widen, $missing)) . ';';
            }
            $needs_utf8mb4 = !$blocked && 'innodb' === strtolower((string) $engine) && (bool) array_filter($widen, static function (array $column): bool {
                return 0 !== strpos((string) ($column['Collation'] ?? ''), 'utf8mb4_');
            });
            $guidance[] = ['table' => $table, 'reasons' => $reasons, 'sql' => $sql, 'needs_utf8mb4' => $needs_utf8mb4,
                'inspect' => 'SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`;'];
        }
        return $guidance;
    }

    private function fail(string $message): bool
    {
        $this->failureMessage = $message;
        \wp_slimstat::record_degradation('legacy_column_width_repair', $message, \wp_slimstat::DEGRADATION_OPERATIONAL);
        return false;
    }

    public function run(): bool
    {
        $this->failureMessage = '';
        if (MigrationService::migrationsDisabled()) { return false; }
        $this->state = null;
        $state = $this->inspect();
        if ($state['blocked']) {
            return $this->fail(__('Legacy width repair refused unexpected or unreadable column definitions. Ask your database administrator to review them; no columns were changed.', 'wp-slimstat'));
        }
        if ([] === $state['pending']) { return true; }
        $oldTimeout = $this->wpdb->get_var('SELECT @@SESSION.lock_wait_timeout');
        if (!ctype_digit((string) $oldTimeout)
            || false === $this->wpdb->query('SET SESSION lock_wait_timeout = 5')) {
            return $this->fail(__('Legacy width repair could not set a safe metadata-lock wait; no columns were changed.', 'wp-slimstat'));
        }
        $ok = true;
        $deadline = microtime(true) + self::PASS_SECONDS;
        $first = true;
        try {
            foreach ($state['pending'] as $suffix => $columns) {
                if (!$first && microtime(true) >= $deadline) {
                    $ok = $this->fail(__('Legacy width repair paused between tables. Retry to finish the remaining table; completed changes are retained.', 'wp-slimstat'));
                    break;
                }
                $first = false;
                // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are fixed migration columns/core prefix; lookup values are prepared and session timeout is integer-cast.
                if (false === $this->wpdb->query(Schema::widenLegacyColumnsSql($suffix, $this->tablePrefix(), $columns['widen'] ?? [], $columns['missing'] ?? []))) {
                    $ok = $this->fail(__('The server refused the online legacy width repair. No blocking fallback was attempted; review the database error before retrying.', 'wp-slimstat'));
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
                            $ok = $this->fail(__('Legacy width repair could not verify the resulting definition. Completion was not recorded; inspect the database before retrying.', 'wp-slimstat'));
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
                        $ok = $this->fail(__('Legacy archive repair could not verify the added column. Completion was not recorded; inspect the database before retrying.', 'wp-slimstat'));
                        break 2;
                    }
                }
            }
        } finally {
            $this->state = null;
            // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are fixed migration columns/core prefix; lookup values are prepared and session timeout is integer-cast.
            if (false === $this->wpdb->query('SET SESSION lock_wait_timeout = ' . (int) $oldTimeout)) {
                $ok = $this->fail(__('Legacy width repair could not restore the connection metadata-lock timeout.', 'wp-slimstat'));
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
            foreach ($this->columnsFor($suffix) as $name) {
                $rows[] = ['key' => $this->getId(), 'table' => $this->tablePrefix() . $suffix,
                    'columns' => $name, 'exists' => in_array($name, self::ARCHIVE_COLUMNS, true) ? isset($state['columns'][$suffix][$name]) : 'varchar(256)' === strtolower((string) ($state['columns'][$suffix][$name]['Type'] ?? ''))];
            }
        }
        return $rows;
    }
}
