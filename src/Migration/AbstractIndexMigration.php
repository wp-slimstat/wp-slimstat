<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use wpdb;

/**
 * Base helper for migrations that add a single database index.
 */
abstract class AbstractIndexMigration extends AbstractMigration
{
    abstract protected function getIndexName(): string;

    abstract protected function getIndexColumns(): array;

    abstract protected function getTableName(): string;

    public function getDescription(): string
    {
        return sprintf(
            // translators: %1$s is the index name, %2$s is the table name.
            __('Ensures the %1$s index exists on the %2$s table for performance.', 'wp-slimstat'),
            "<code>" . $this->getIndexName() . "</code>",
            "<code>" . $this->getTableName() . "</code>"
        );
    }

    public function run(): bool
    {
        if ($this->shouldRun()) {
            $sql = sprintf(
                'CREATE INDEX %s ON %s (%s)',
                $this->getIndexName(),
                $this->getTableName(),
                implode(', ', $this->getIndexColumns())
            );

            $result = $this->wpdb->query($sql);
            if (false === $result) {
                // Optionally log error: $this->wpdb->last_error
                return false;
            }
        }
        return true;
    }

    public function shouldRun(): bool
    {
        // Use backticks for table name to avoid issues with %i placeholder
        $table_name = $this->getTableName();
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s",
            $this->getIndexName()
        ));
        return empty($exists);
    }

    public function getDiagnostics(): array
    {
        return [
            [
                'key'     => $this->getIndexName(),
                'exists'  => !$this->shouldRun(),
                'table'   => $this->getTableName(),
                'columns' => implode(', ', $this->getIndexColumns()),
            ]
        ];
    }
}
