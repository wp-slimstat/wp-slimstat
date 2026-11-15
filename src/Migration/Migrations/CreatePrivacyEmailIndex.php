<?php
/** @license GPL-2.0-or-later */
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractIndexMigration;

/** Existing sites build this index explicitly from the Migration page. */
class CreatePrivacyEmailIndex extends AbstractIndexMigration
{
    public function getId(): string
    {
        return 'create-privacy-email-index';
    }

    public function getName(): string
    {
        return __('Index email addresses for personal data requests', 'wp-slimstat');
    }

    public function getDescription(): string
    {
        return __('Speeds up personal data export and erasure and limits erasure locks to matching email addresses. Building the index can take time on large databases. Run this maintenance when traffic is low.', 'wp-slimstat');
    }

    protected function getIndexKey(): string
    {
        return 'idx_privacy_email';
    }

    protected function getTableSuffix(): string
    {
        return 'slim_stats';
    }
}
