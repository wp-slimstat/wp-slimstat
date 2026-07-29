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

    /**
     * Per-request answer to shouldRun(), because it is asked more than once.
     *
     * @var bool|null
     */
    private $shouldRunCache;

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

            // The index now exists; a later shouldRun() in this request must say so.
            $this->shouldRunCache = null;
        }

        return true;
    }

    /**
     * Does this index still need creating?
     *
     * Memoised: rendering the migration notice asks once via needsMigration() and again via
     * getRequiredDiagnostics(), and each ask is a `SHOW INDEX` against a table with 443k
     * rows. The sibling migration that recovers heatmap positions already caches its own
     * probe for the same reason.
     */
    public function shouldRun(): bool
    {
        if (null !== $this->shouldRunCache) {
            return $this->shouldRunCache;
        }

        // Use backticks for table name to avoid issues with %i placeholder
        $table_name = $this->getTableName();
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            sprintf('SHOW INDEX FROM `%s` WHERE Key_name = %%s', $table_name),
            $this->getIndexName()
        ));

        return $this->shouldRunCache = empty($exists);
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
