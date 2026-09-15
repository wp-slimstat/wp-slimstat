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

    protected function getIndexKey(): string
    {
        return 'idx_dt_browser_browser_version';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
