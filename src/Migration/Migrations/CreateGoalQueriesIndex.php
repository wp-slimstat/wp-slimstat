<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateGoalQueriesIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-goal-queries-index';
    }

    public function getName(): string
    {
        return __('Create Goal Queries Index', 'wp-slimstat');
    }

    protected function getIndexName(): string
    {
        return 'idx_goal_queries';
    }

    protected function getIndexColumns(): array
    {
        return ['resource(191)', 'dt', 'fingerprint(20)'];
    }

    protected function getTableName(): string
    {
        return $this->wpdb->prefix . 'slim_stats';
    }
}
