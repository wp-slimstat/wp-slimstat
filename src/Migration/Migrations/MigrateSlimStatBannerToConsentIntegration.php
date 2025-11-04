<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;

/**
 * Migration to convert use_slimstat_banner to consent_integration setting
 *
 * For existing users who had the SlimStat banner enabled, this migration
 * sets consent_integration to 'slimstat_banner' and ensures related settings
 * are properly configured.
 *
 * @since 5.4.0
 */
class MigrateSlimStatBannerToConsentIntegration extends AbstractMigration
{
	public function getId(): string
	{
		return 'migrate-slimstat-banner-to-consent-integration';
	}

	public function getName(): string
	{
		return __('Migrate SlimStat Banner to Consent Integration', 'wp-slimstat');
	}

	public function getDescription(): string
	{
		return __('Converts legacy use_slimstat_banner setting to the new consent_integration system. Sets consent_integration to "slimstat_banner" for users who previously had the banner enabled.', 'wp-slimstat');
	}

	public function shouldRun(): bool
	{
		$settings = get_option('slimstat_options', []);

		// Only run if:
		// 1. Banner was previously enabled (use_slimstat_banner = 'on')
		// 2. AND consent_integration is empty or not set to 'slimstat_banner'
		$banner_enabled = ('on' === ($settings['use_slimstat_banner'] ?? 'off'));
		$consent_integration = $settings['consent_integration'] ?? '';

		return $banner_enabled && 'slimstat_banner' !== $consent_integration;
	}

	public function run(): bool
	{
		$settings = get_option('slimstat_options', []);

		if (empty($settings)) {
			return false;
		}

		$banner_enabled = ('on' === ($settings['use_slimstat_banner'] ?? 'off'));
		$consent_integration = $settings['consent_integration'] ?? '';

		// Only migrate if banner was enabled and consent_integration is not already set
		if ($banner_enabled && ('slimstat_banner' !== $consent_integration)) {
			$settings['consent_integration'] = 'slimstat_banner';
			$settings['use_slimstat_banner'] = 'on'; // Keep banner enabled

			// Ensure related settings have default values if not set
			if (empty($settings['opt_out_message'])) {
				// Keep empty - user can set their own message
			}

			if (empty($settings['gdpr_accept_button_text'])) {
				// Keep empty - will use default "Accept"
			}

			if (empty($settings['gdpr_decline_button_text'])) {
				// Keep empty - will use default "Deny"
			}

			$result = update_option('slimstat_options', $settings, false);

			return false !== $result;
		}

		return true;
	}

	public function getDiagnostics(): array
	{
		return [];
	}
}
