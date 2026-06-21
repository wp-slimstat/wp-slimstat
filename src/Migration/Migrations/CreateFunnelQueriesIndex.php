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

    protected function getIndexName(): string
    {
        return 'idx_funnel_queries';
    }

    protected function getIndexColumns(): array
    {
        return ['fingerprint(20)', 'dt', 'resource(191)'];
    }

    protected function getTableName(): string
    {
        return $this->wpdb->prefix . 'slim_stats';
    }
}
