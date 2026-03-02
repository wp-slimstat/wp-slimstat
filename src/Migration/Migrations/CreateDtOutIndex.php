<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateDtOutIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-dt-out-index';
    }

    public function getName(): string
    {
        return __('Create dt_out Index', 'wp-slimstat');
    }

    protected function getIndexName(): string
    {
        return 'idx_dt_out';
    }

    protected function getIndexColumns(): array
    {
        return ['dt_out'];
    }

    protected function getTableName(): string
    {
        return $this->wpdb->prefix . 'slim_stats';
    }
}
