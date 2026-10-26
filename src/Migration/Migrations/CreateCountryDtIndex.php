<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateCountryDtIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-country-dt-index';
    }

    public function getName(): string
    {
        return __('Create country, dt Index', 'wp-slimstat');
    }

    protected function getIndexKey(): string
    {
        return 'idx_country_dt';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
