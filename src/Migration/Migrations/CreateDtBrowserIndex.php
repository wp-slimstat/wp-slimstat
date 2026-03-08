<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateDtBrowserIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-dt-browser-index';
    }

    public function getName(): string
    {
        return __('Create Browser Index', 'wp-slimstat');
    }

    protected function getIndexName(): string
    {
        return 'idx_dt_browser_browser_version';
    }

    protected function getIndexColumns(): array
    {
        return ['dt', 'browser', 'browser_version'];
    }

    protected function getTableName(): string
    {
        return $this->wpdb->prefix . 'slim_stats';
    }
}
