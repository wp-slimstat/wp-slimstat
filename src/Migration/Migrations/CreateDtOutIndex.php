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

    protected function getIndexKey(): string
    {
        return 'idx_dt_out';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
