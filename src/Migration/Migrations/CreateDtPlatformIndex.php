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

    protected function getIndexKey(): string
    {
        return 'idx_dt_platform';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
