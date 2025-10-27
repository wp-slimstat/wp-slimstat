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

    protected function getIndexName(): string
    {
        return 'idx_country_dt';
    }

    protected function getIndexColumns(): array
    {
        return ['country', 'dt'];
    }

    protected function getTableName(): string
    {
        return $this->wpdb->prefix . 'slim_stats';
    }
}
