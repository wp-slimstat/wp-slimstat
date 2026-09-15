<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateFunnelQueriesIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-funnel-queries-index';
    }

    public function getName(): string
    {
        return __('Create Funnel Queries Index', 'wp-slimstat');
    }

    protected function getIndexKey(): string
    {
        return 'idx_funnel_queries';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
