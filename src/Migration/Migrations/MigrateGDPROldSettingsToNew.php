<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;

/**
 * Migration to add new GDPR settings fields for existing users
 *
 * For existing users who had only opt_out_message configured, this migration
 * ensures the new GDPR banner settings (accept button text, decline button text,
 * theme mode) have default values so the banner works correctly after update.
 *
 * @since 5.4.0
 */
class MigrateGDPROldSettingsToNew extends AbstractMigration
{
	public function getId(): string
	{
		return 'migrate-gdpr-old-settings-to-new';
	}

	public function getName(): string
	{
		return __('Migrate GDPR Settings to New Format', 'wp-slimstat');
	}

	public function getDescription(): string
	{
		return __('Migrates old GDPR settings (display_opt_out, opt_out_message) to the new format with consent_integration, button labels, and theme mode. Ensures existing users continue to have banner functionality after update.', 'wp-slimstat');
	}

	public function shouldRun(): bool
	{
		$settings = get_option('slimstat_options', []);

		if (empty($settings)) {
			return false;
		}

		// Check if user has old settings from development branch
		$display_opt_out = ('yes' === ($settings['display_opt_out'] ?? 'no'));
		$has_opt_out_message = !empty($settings['opt_out_message']);
		$consent_integration = $settings['consent_integration'] ?? '';
		$gdpr_enabled = $settings['gdpr_enabled'] ?? '';

		// Check if new fields are missing
		$missing_accept_text = empty($settings['gdpr_accept_button_text']);
		$missing_decline_text = empty($settings['gdpr_decline_button_text']);
		$missing_theme_mode = empty($settings['gdpr_theme_mode']);

		// Run migration if:
		// 1. User had display_opt_out enabled (old setting)
		// 2. User has opt_out_message but consent_integration is not set
		// 3. New fields are missing and user was using banner
		$needs_migration = false;

		if ($display_opt_out) {
			$needs_migration = true;
		} elseif ($has_opt_out_message && empty($consent_integration)) {
			$needs_migration = true;
		} elseif (('slimstat_banner' === $consent_integration || 'on' === ($settings['use_slimstat_banner'] ?? 'off')) &&
				  ($missing_accept_text || $missing_decline_text || $missing_theme_mode)) {
			$needs_migration = true;
		}

		return $needs_migration;
	}

	public function run(): bool
	{
		$settings = get_option('slimstat_options', []);

		if (empty($settings)) {
			return false;
		}

		$needs_update = false;
		$display_opt_out = ('yes' === ($settings['display_opt_out'] ?? 'no'));
		$has_opt_out_message = !empty($settings['opt_out_message']);
		$consent_integration = $settings['consent_integration'] ?? '';
		$gdpr_enabled = $settings['gdpr_enabled'] ?? '';

		// If display_opt_out was enabled (old setting), migrate to new format
		if ($display_opt_out) {
			// Enable GDPR mode if not already set
			if (empty($gdpr_enabled) || 'off' === $gdpr_enabled) {
				$settings['gdpr_enabled'] = 'on';
				$needs_update = true;
			}

			// Set consent_integration to slimstat_banner if not already set
			if (empty($consent_integration)) {
				$settings['consent_integration'] = 'slimstat_banner';
				$settings['use_slimstat_banner'] = 'on';
				$needs_update = true;
			}
		}

		// If user has opt_out_message but consent_integration is not set, enable banner
		if ($has_opt_out_message && empty($consent_integration)) {
			// Enable GDPR mode if not already set
			if (empty($gdpr_enabled) || 'off' === $gdpr_enabled) {
				$settings['gdpr_enabled'] = 'on';
				$needs_update = true;
			}

			$settings['consent_integration'] = 'slimstat_banner';
			$settings['use_slimstat_banner'] = 'on';
			$needs_update = true;
		}

		// Set default accept button text if missing
		if (empty($settings['gdpr_accept_button_text'])) {
			$settings['gdpr_accept_button_text'] = __('Accept', 'wp-slimstat');
			$needs_update = true;
		}

		// Set default decline button text if missing
		if (empty($settings['gdpr_decline_button_text'])) {
			$settings['gdpr_decline_button_text'] = __('Decline', 'wp-slimstat');
			$needs_update = true;
		}

		// Set default theme mode if missing
		if (empty($settings['gdpr_theme_mode'])) {
			$settings['gdpr_theme_mode'] = 'auto';
			$needs_update = true;
		}

		if ($needs_update) {
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
