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

    protected function getIndexName(): string
    {
        return 'idx_dt_screen_width_screen_height';
    }

    protected function getIndexColumns(): array
    {
        return ['dt', 'screen_width', 'screen_height'];
    }

    protected function getTableName(): string
    {
        return $this->wpdb->prefix . 'slim_stats';
    }
}
