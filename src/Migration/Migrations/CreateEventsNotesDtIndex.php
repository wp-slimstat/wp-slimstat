<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

class CreateEventsNotesDtIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-events-notes-dt-index';
    }

    public function getName(): string
    {
        return __('Create Events Notes Index', 'wp-slimstat');
    }

    protected function getIndexKey(): string
    {
        return 'idx_events_notes_dt';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_events';
    }
}
