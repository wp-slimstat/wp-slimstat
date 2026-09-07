<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use SlimStat\Schema\Schema;
use wpdb;

/**
 * Base helper for migrations that add a single database index.
 *
 * A subclass declares WHICH index it is responsible for — a key into the manifest — and nothing
 * about its shape. Before this, each of the eight subclasses restated the index name, its column
 * list and its table, which made them the sixth independent declaration of objects the installer
 * and the upgrade path also declared. Two of those declarations disagreeing is not hypothetical:
 * it is the mechanism behind C39, where a fresh install ended up with eleven secondary indexes on
 * slim_stats and an upgraded one with thirteen.
 */
abstract class AbstractIndexMigration extends AbstractMigration
{
    /** The manifest key for this index, `{prefix}` unresolved. */
    abstract protected function getIndexKey(): string;

    /** The manifest table suffix this index belongs to, e.g. `slim_stats`. */
    abstract protected function getTableSuffix(): string;

    protected function getIndexName(): string
    {
        return Schema::resolve($this->getIndexKey(), $this->tablePrefix());
    }

    /**
     * @return string[]
     */
    protected function getIndexColumns(): array
    {
        return array_map('trim', explode(',', Schema::indexes($this->getTableSuffix())[$this->getIndexKey()]));
    }

    protected function getTableName(): string
    {
        return $this->tablePrefix() . $this->getTableSuffix();
    }

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

    /** @var array{present:string[], missing:string[], malformed:string[]}|null */
    private $indexState;

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
            return true;
        }

        // A refused/unknown definition is not a completed migration.
        return in_array($this->getIndexName(), $this->indexState['present'] ?? [], true);
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

        $this->indexState = Schema::indexState($this->wpdb, $this->getTableSuffix(), $this->tablePrefix());
        if ($this->probeFailed()) {
            return $this->shouldRunCache = false;
        }
        if (in_array($this->getIndexKey(), $this->indexState['malformed'], true)) {
            \wp_slimstat::record_degradation(
                'index-definition-' . $this->getId(),
                sprintf('Index %s on %s has an unexpected definition. Automatic repair was skipped; '
                    . 'ask your database administrator to review the index definition before rebuilding it.',
                    $this->getIndexName(), $this->getTableName()),
                \wp_slimstat::DEGRADATION_OPERATIONAL
            );
        }

        return $this->shouldRunCache = in_array($this->getIndexKey(), $this->indexState['missing'], true);
    }

    public function getDiagnostics(): array
    {
        $this->shouldRun();
        return [
            [
                'key'     => $this->getIndexName(),
                'exists'  => in_array($this->getIndexName(), $this->indexState['present'], true),
                'table'   => $this->getTableName(),
                'columns' => implode(', ', $this->getIndexColumns()),
            ]
        ];
    }
}
