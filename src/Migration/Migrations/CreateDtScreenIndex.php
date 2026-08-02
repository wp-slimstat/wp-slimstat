<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateDtScreenIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-dt-screen-index';
    }

    public function getName(): string
    {
        return __('Create Screen Resolution Index', 'wp-slimstat');
    }

    protected function getIndexKey(): string
    {
        return 'idx_dt_screen_width_screen_height';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
