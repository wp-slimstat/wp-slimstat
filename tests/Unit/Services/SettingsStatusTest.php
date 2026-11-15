<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Services;

use Brain\Monkey\Functions;
use SlimStat\Services\SettingsStatus;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/** The Settings summaries describe saved settings the way the tracker reads them, with upgrade fallbacks. */
class SettingsStatusTest extends WpSlimstatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
    }

    private function values(array $rows): array
    {
        return array_column($rows, 'value', 'label');
    }

    public function test_upgraded_site_without_new_keys_is_described_by_runtime_fallbacks(): void
    {
        // An upgraded site may lack every key added later: read it the way Consent and Processor do.
        $rows = $this->values(SettingsStatus::privacyRows([], false));
        $this->assertSame('Not required: visitors are tracked without a consent check', $rows['Visitor consent']);
        $this->assertSame('Stored in full', $rows['IP addresses']);
        $this->assertSame('Recognized with a cookie', $rows['Returning visitors']);
        $this->assertSame('Kept indefinitely', $rows['Retention']);
        $this->assertSame('Data and settings are kept', $rows['When the plugin is deleted']);
        $this->assertTrue(SettingsStatus::privacyReviewPending([]));
        $this->assertFalse(SettingsStatus::privacyReviewPending(['privacy_review_notice' => 'no']));
    }

    public function test_missing_consent_source_needs_attention_only_when_identifiers_are_collected(): void
    {
        $gdpr = ['gdpr_enabled' => 'on', 'set_tracker_cookie' => 'on'];
        $this->assertTrue(SettingsStatus::consentSourceMissing($gdpr, false));
        $this->assertFalse(SettingsStatus::consentSourceMissing($gdpr, true));
        $cookieless = ['gdpr_enabled' => 'on', 'set_tracker_cookie' => 'no', 'hash_ip' => 'on'];
        $this->assertFalse(SettingsStatus::consentSourceMissing($cookieless, false));
        $this->assertSame('attention', SettingsStatus::privacyRows($gdpr, false)[0]['state']);
        $this->assertSame('ok', SettingsStatus::privacyRows($cookieless, false)[0]['state']);
        $this->assertSame('Required, through SlimStat consent banner', SettingsStatus::privacyRows($gdpr + ['consent_integration' => 'slimstat_banner'], true)[0]['value']);
    }

    public function test_retention_matches_the_purge_job(): void
    {
        // wp_slimstat_purge() archives unless auto_purge_delete is exactly 'no'.
        $this->assertSame('Deleted after 30 days', $this->values(SettingsStatus::privacyRows(['auto_purge' => 30, 'auto_purge_delete' => 'no'], false))['Retention']);
        $this->assertSame('Archived after 30 days; archives kept indefinitely', $this->values(SettingsStatus::privacyRows(['auto_purge' => 30], false))['Retention']);
        $this->assertSame('Archived after 30 days; archives deleted after 90 days', $this->values(SettingsStatus::privacyRows(['auto_purge' => 30, 'auto_purge_delete' => 'on', 'archive_retention_days' => 90], false))['Retention']);
        // Archive expiry runs even when live data is kept or deleted outright.
        $this->assertSame('Kept indefinitely; archives deleted after 90 days', $this->values(SettingsStatus::privacyRows(['auto_purge' => 0, 'archive_retention_days' => 90], false))['Retention']);
        $this->assertSame('Deleted after 30 days; archives deleted after 7 days', $this->values(SettingsStatus::privacyRows(['auto_purge' => 30, 'auto_purge_delete' => 'no', 'archive_retention_days' => 7], false))['Retention']);
    }

    public function test_setup_rows_flag_real_problems_and_link_to_the_fix(): void
    {
        $ok = SettingsStatus::setupRows(['privacy_review_notice' => 'no'], [], false, false);
        $this->assertSame(['ok', 'ok', 'neutral', 'ok'], array_column($ok, 'state'));
        $this->assertSame([1, 6, 2, 9], array_column($ok, 'tab'));

        $skipped = SettingsStatus::setupRows([], [301, time()], false, false);
        $this->assertSame('ok', $skipped[1]['state'], 'A bot or excluded IP is SlimStat working, not an error.');
        $failed = SettingsStatus::setupRows(['is_tracking' => 'no', 'geolocation_provider' => 'dbip'], [101, time()], true, false);
        $this->assertSame(['attention', 'attention', 'attention', 'attention'], array_column($failed, 'state'));
    }
}
