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

    protected function getIndexKey(): string
    {
        return 'idx_goal_queries';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
