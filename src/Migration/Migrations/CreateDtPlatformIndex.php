<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateDtPlatformIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-dt-platform-index';
    }

    public function getName(): string
    {
        return __('Create Platform Index', 'wp-slimstat');
    }

    protected function getIndexName(): string
    {
        return 'idx_dt_platform';
    }

    protected function getIndexColumns(): array
    {
        return ['dt', 'platform'];
    }

    protected function getTableName(): string
    {
        return $this->wpdb->prefix . 'slim_stats';
    }
}
