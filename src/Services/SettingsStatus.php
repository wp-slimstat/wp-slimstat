<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Services;

/**
 * Read-only summaries for the Settings screen: what the saved settings do, in plain words.
 *
 * Nothing here writes a setting. Fallbacks match the readers that act on each key
 * (Consent, Processor, the privacy notice), so a key missing on an upgraded site is
 * described the way it behaves, not the way a fresh install would set it.
 *
 * Each row: label, value, state ('ok', 'attention' or 'neutral'), tab and anchor to fix it.
 */
final class SettingsStatus
{
	/** Consent is required for identifiers, but no source can answer on this site. */
	public static function consentSourceMissing(array $s, bool $has_source): bool
	{
		return !$has_source && 'on' === ($s['gdpr_enabled'] ?? 'off') && \SlimStat\Utils\Consent::collectsIdentifiers($s);
	}

	/** Upgraded sites lack the key until an administrator marks the review complete. */
	public static function privacyReviewPending(array $s): bool
	{
		return 'no' !== ($s['privacy_review_notice'] ?? 'yes');
	}

	public static function privacyRows(array $s, bool $has_source): array
	{
		$sources = [
			'slimstat_banner'    => __('SlimStat consent banner', 'wp-slimstat'),
			'wp_consent_api'     => __('WP Consent API', 'wp-slimstat'),
			'real_cookie_banner' => __('WP Consent API', 'wp-slimstat'),
		];
		$source = (string) ($s['consent_integration'] ?? '');
		if ('on' !== ($s['gdpr_enabled'] ?? 'off')) {
			$consent = [__('Not required: visitors are tracked without a consent check', 'wp-slimstat'), 'neutral'];
		} elseif (self::consentSourceMissing($s, $has_source)) {
			$consent = [__('Required, but no working consent source: only limited counts are collected', 'wp-slimstat'), 'attention'];
		} elseif ($has_source) {
			/* translators: %s: consent source name, such as SlimStat consent banner. */
			$consent = [sprintf(__('Required, through %s', 'wp-slimstat'), $sources[$source] ?? $source), 'ok'];
		} else {
			$consent = [__('Required; no consent source, so tracking stays cookieless', 'wp-slimstat'), 'ok'];
		}

		if ('on' === ($s['hash_ip'] ?? 'off')) {
			$ip = __('Hashed with a daily salt, never stored', 'wp-slimstat');
		} elseif ('on' === ($s['anonymize_ip'] ?? 'off')) {
			$ip = __('Anonymized: the last part is removed', 'wp-slimstat');
		} else {
			$ip = __('Stored in full', 'wp-slimstat');
		}

		$provider = (string) ($s['geolocation_provider'] ?? 'disable');
		if ('disable' === $provider || '' === $provider) {
			$location = [__('Off', 'wp-slimstat'), 'third-party-libraries'];
		} else {
			$location = ['on' === ($s['geolocation_country'] ?? 'on') ? __('Country only', 'wp-slimstat') : __('City', 'wp-slimstat'), 'geolocation_country'];
		}

		return [
			self::row(__('Visitor consent', 'wp-slimstat'), $consent[0], $consent[1], 9, 'gdpr_enabled'),
			self::row(__('IP addresses', 'wp-slimstat'), $ip, 'neutral', 9, 'anonymize_ip'),
			self::row(__('Returning visitors', 'wp-slimstat'), 'on' === ($s['set_tracker_cookie'] ?? 'on') ? __('Recognized with a cookie', 'wp-slimstat') : __('Cookieless: estimated', 'wp-slimstat'), 'neutral', 9, 'set_tracker_cookie'),
			self::row(__('Do Not Track and GPC', 'wp-slimstat'), 'on' === ($s['do_not_track'] ?? 'off') ? __('Respected: no tracking', 'wp-slimstat') : __('Not checked', 'wp-slimstat'), 'neutral', 9, 'do_not_track'),
			self::row(__('Personal data in reports', 'wp-slimstat'), 'on' === ($s['mask_personal_data'] ?? 'off') ? __('Masked for viewers who cannot manage options', 'wp-slimstat') : __('Visible to everyone who can see reports', 'wp-slimstat'), 'neutral', 9, 'mask_personal_data'),
			self::row(__('Visitor location', 'wp-slimstat'), $location[0], 'neutral', 2, $location[1]),
			self::row(__('Retention', 'wp-slimstat'), self::retention($s), 'neutral', 9, 'auto_purge'),
			self::row(__('When the plugin is deleted', 'wp-slimstat'), 'on' === ($s['delete_data_on_uninstall'] ?? 'no') ? __('All SlimStat data is erased', 'wp-slimstat') : __('Data and settings are kept', 'wp-slimstat'), 'neutral', 6, 'delete_data_on_uninstall'),
		];
	}

	/**
	 * @param array $tracker_error slimstat_tracker_error option: [code, time] or empty.
	 */
	public static function setupRows(array $s, array $tracker_error, bool $geoip_error, bool $has_source): array
	{
		$tracking = 'on' === ($s['is_tracking'] ?? 'on');
		$code     = (int) ($tracker_error[0] ?? 0);
		if ([] === $tracker_error) {
			$tracker = [__('No errors', 'wp-slimstat'), 'ok'];
		} elseif (($code >= 300 && $code < 400) || 429 === $code) {
			$tracker = [__('Working: some pageviews were skipped on purpose', 'wp-slimstat'), 'ok'];
		} else {
			$tracker = [__('A pageview could not be recorded', 'wp-slimstat'), 'attention'];
		}

		$provider = (string) ($s['geolocation_provider'] ?? 'disable');
		if ('disable' === $provider || '' === $provider) {
			$location = [__('Off', 'wp-slimstat'), 'neutral'];
		} elseif ($geoip_error) {
			$location = [__('The location database reported an error', 'wp-slimstat'), 'attention'];
		} else {
			$location = ['on' === ($s['geolocation_country'] ?? 'on') ? __('Country only', 'wp-slimstat') : __('City', 'wp-slimstat'), 'ok'];
		}

		if (self::consentSourceMissing($s, $has_source)) {
			$privacy = [__('No working consent source', 'wp-slimstat'), 'attention'];
		} elseif (self::privacyReviewPending($s)) {
			$privacy = [__('Not reviewed yet', 'wp-slimstat'), 'attention'];
		} else {
			$privacy = [__('Reviewed', 'wp-slimstat'), 'ok'];
		}

		return [
			self::row(__('Tracking', 'wp-slimstat'), $tracking ? __('On', 'wp-slimstat') : __('Off: no new visits are recorded', 'wp-slimstat'), $tracking ? 'ok' : 'attention', 1, 'is_tracking'),
			self::row(__('Tracker', 'wp-slimstat'), $tracker[0], $tracker[1], 6, 'last_tracker_error'),
			self::row(__('Visitor location', 'wp-slimstat'), $location[0], $location[1], 2, 'third-party-libraries'),
			self::row(__('Privacy review', 'wp-slimstat'), $privacy[0], $privacy[1], 9, ''),
		];
	}

	private static function retention(array $s): string
	{
		$days    = (int) ($s['auto_purge'] ?? 0);
		$archive = (int) ($s['archive_retention_days'] ?? 0);
		// wp_slimstat_purge() expires archives on every run, whether or not live data is purged.
		$archives = $archive > 0
			/* translators: %d: number of days. */
			? sprintf(_n('archives deleted after %d day', 'archives deleted after %d days', $archive, 'wp-slimstat'), $archive)
			: __('archives kept indefinitely', 'wp-slimstat');
		if ($days <= 0) {
			$live = __('Kept indefinitely', 'wp-slimstat');
		} elseif ('no' === ($s['auto_purge_delete'] ?? 'on')) {
			// Same test as wp_slimstat_purge(): anything but 'no' archives.
			/* translators: %d: number of days. */
			$live = sprintf(_n('Deleted after %d day', 'Deleted after %d days', $days, 'wp-slimstat'), $days);
		} else {
			/* translators: %d: number of days. */
			return sprintf(_n('Archived after %d day', 'Archived after %d days', $days, 'wp-slimstat'), $days) . '; ' . $archives;
		}
		// Not archiving now: mention archives only when older ones still expire.
		return $archive > 0 ? $live . '; ' . $archives : $live;
	}

	private static function row(string $label, string $value, string $state, int $tab, string $anchor): array
	{
		return ['label' => $label, 'value' => $value, 'state' => $state, 'tab' => $tab, 'anchor' => $anchor];
	}
}
