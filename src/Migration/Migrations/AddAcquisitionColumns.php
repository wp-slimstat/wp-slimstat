<?php

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;
use SlimStat\Schema\Schema;
use SlimStat\Tracker\Acquisition;

/** Add all attribution columns in one ALTER per table, without rewriting historical data. */
class AddAcquisitionColumns extends AbstractMigration
{
    public function getId(): string
    {
        return 'add-acquisition-columns';
    }

    public function getName(): string
    {
        return __('Enable UTM and channel reports', 'wp-slimstat');
    }

    public function getDescription(): string
    {
        return __('Adds campaign and channel fields to the analytics table and archive. Each table may be rebuilt; the time depends on its size and your database server. Servers without online ALTER support may pause tracking writes. Existing pageviews remain unchanged and appear as Not attributed. Attribution starts after setup completes.', 'wp-slimstat');
    }

    // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fresh schema probe uses a stripped core prefix and fixed table suffix.
    private function missing(string $suffix): array
    {
        $table = str_replace('`', '', $this->tablePrefix() . $suffix);
        $columns = $this->wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        if ($this->probeFailed() || !is_array($columns) || !$columns) {
            return Acquisition::COLUMNS;
        }
        return array_values(array_diff(Acquisition::COLUMNS, $columns));
    }
    // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    public function shouldRun(): bool
    {
        return '1' !== get_option(Acquisition::readinessKey(), '0')
            || (bool) $this->missing('slim_stats') || (bool) $this->missing('slim_stats_archive');
    }

    public function run(): bool
    {
        foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
            $missing = $this->missing($suffix);
            if ($this->probeFailed()) {
                return false;
            }
            if (!$missing) {
                continue;
            }
            $sql = Schema::addColumnsSql($suffix, $missing, $this->tablePrefix());
            $suppressed = $this->wpdb->suppress_errors(true);
            try {
                // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Schema validates columns against its manifest; only fixed DDL hints are appended.
                $result = $this->wpdb->query($sql . ', ALGORITHM=INPLACE, LOCK=NONE');
                // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
                if (false === $result) {
                    // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Schema validates columns against its manifest; only fixed DDL hints are appended.
                    $result = $this->wpdb->query($sql);
                    // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
                }
            } finally {
                $this->wpdb->suppress_errors($suppressed);
            }
            if (false === $result) {
                return false;
            }
        }
        return Acquisition::checkSchema();
    }

    public function getDiagnostics(): array
    {
        $rows = [];
        foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
            $rows[] = [
                'key' => $this->getId(), 'exists' => !$this->missing($suffix),
                'table' => $this->tablePrefix() . $suffix,
                'columns' => implode(', ', Acquisition::COLUMNS),
            ];
        }
        return $rows;
    }
}
