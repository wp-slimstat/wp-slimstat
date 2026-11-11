<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Services;

/**
 * Pro entitlement, owned by Free so that no Pro version, old or new, runs without a license.
 *
 * Activating the Pro plugin is not entitlement. Free keeps one verdict per site, bound to the
 * license key and the domain (changing either discards it), and asks the license server through
 * Pro's own client, so Free itself never contacts it.
 *
 * - valid unlocks Pro. After 12 hours a background refresh confirms it again. If the license
 *   server cannot be reached, access lasts at most 3 days after the last confirmation.
 * - invalid or domain_not_activated locks Pro at once; it is checked again every 12 hours, and
 *   at once when an administrator saves License settings.
 * - an outage never grants first access; it is retried every 5 minutes.
 * - Pro without the client (older than 3.0.0) stays locked until it is updated.
 */
final class ProLicense
{
	public const OPTION = 'slimstat_pro_license_verdict';
	public const HOOK   = 'slimstat_pro_license_refresh';
	public const TTL    = 72 * HOUR_IN_SECONDS;
	public const DUE    = 12 * HOUR_IN_SECONDS;
	public const RETRY  = 5 * MINUTE_IN_SECONDS;
	public const CLIENT = 'WpSlimstatPro\Update\NexusClient';
	/** Where a customer finds the key of a license they already bought. */
	public const ACCOUNT = 'https://my.wp-slimstat.com/account/';

	private const DEFINITIVE = ['valid', 'invalid', 'domain_not_activated'];

	/** Pro code that keeps running while locked: the license screen, the updater and the framework. */
	private const KEPT = ['WpSlimstatPro\Admin\\', 'WpSlimstatPro\Update\\', 'WpSlimstatPro\Logger\\', 'WpSlimstatPro\Container\\', 'WpSlimstatPro\Vendor\\'];

	public static function register(): void
	{
		// Pro's framework boots its add-ons at plugins_loaded:10: verify before it, detach after it.
		add_action('plugins_loaded', [self::class, 'verify'], 9);
		add_action('plugins_loaded', [self::class, 'enforce'], 11);
		add_action('init', [self::class, 'schedule']);
		add_action(self::HOOK, static function (): void {
			self::refresh();
		});
		// Pro after 3.0.0 reports each definitive answer it gets, so saving License settings unlocks at once.
		add_action('slimstat_pro_license_checked', [self::class, 'record'], 10, 2);
		// SlimStat's own screens show the banner in their header; the Dashboard and Plugins screens get it here.
		add_action('admin_notices', [self::class, 'screen_notice']);
	}

	public static function key(): string
	{
		$options = get_option('slimstat_options', []);
		if (is_multisite()) {
			// Same key Pro's settings use: the network's in the network admin, and on every site once the network controls it.
			$network = get_site_option('slimstat_options', []);
			if (is_array($network) && (is_network_admin() || 'on' === ($network['addon_network_settings_slimstat_pro_license_key'] ?? ''))) {
				$options = $network;
			}
		}
		return is_array($options) ? trim((string) ($options['slimstat_pro_license_key'] ?? '')) : '';
	}

	/** @return string none, valid, invalid, domain_not_activated or unverified. */
	public static function state(): string
	{
		$key = self::key();
		if ('' === $key) {
			return 'none';
		}
		$verdict = self::verdict($key);
		$state   = $verdict['state'] ?? 'unverified';
		if ('valid' === $state) {
			$age = time() - (int) ($verdict['checked'] ?? 0);
			return $age >= -MINUTE_IN_SECONDS && $age < self::TTL ? 'valid' : 'unverified';
		}
		return in_array($state, self::DEFINITIVE, true) ? $state : 'unverified';
	}

	public static function active(): bool
	{
		return 'valid' === self::state();
	}

	/** Stores an answer for $key. An unreachable answer only moves the retry clock. */
	public static function record($key, $state): void
	{
		$key = trim((string) $key);
		if ('' === $key) {
			return;
		}
		$now     = time();
		$verdict = ['hash' => self::hash($key), 'tried' => $now];
		$verdict += in_array($state, self::DEFINITIVE, true) ? ['state' => $state, 'checked' => $now] : (self::verdict($key) ?? []);
		update_option(self::OPTION, $verdict, true);
	}

	/** Asks the license server through Pro's client. Returns the resulting state(). */
	public static function refresh(): string
	{
		$key    = self::key();
		$client = self::CLIENT;
		if ('' !== $key && class_exists($client)) {
			self::record($key, self::ask(new $client(), $key));
		}
		return self::state();
	}

	/** Queues a background refresh when one is due, so no visitor request waits on the license server. */
	public static function schedule(): void
	{
		$key = self::key();
		if ('' !== $key && \wp_slimstat::pro_plugin_active() && class_exists(self::CLIENT) && self::due($key) && !wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time(), self::HOOK);
		}
	}

	/** An administrator opening a SlimStat screen waits once for a due answer instead of seeing a stale lock. */
	public static function verify(): void
	{
		if (\wp_slimstat::pro_plugin_active() && !self::active() && self::interactive() && self::due(self::key())) {
			self::refresh();
		}
	}

	/** Detaches every Pro feature while the license is not valid, including in Pro versions that never checked it. */
	public static function enforce(): void
	{
		if (\wp_slimstat::pro_plugin_active() && !self::active()) {
			self::detach();
		}
	}

	/** Text for the locked-Pro notice, or '' when Pro is not locked. Its only markup is a link to the Pro overview and <strong>. */
	public static function message(): string
	{
		$state = \wp_slimstat::pro_plugin_active() ? self::state() : 'valid';
		if ('valid' === $state) {
			return '';
		}
		$kept = __('Your Pro settings and data are kept.', 'wp-slimstat');
		if (!current_user_can('manage_options')) {
			return __('SlimStat Pro features are locked until a site administrator activates the license.', 'wp-slimstat') . ' ' . $kept;
		}
		if (!class_exists(self::CLIENT)) {
			return __('Update SlimStat Pro to the latest version so SlimStat can verify your license. Pro features stay locked until then.', 'wp-slimstat') . ' ' . $kept;
		}
		$pro = '<a href="' . esc_url(admin_url('admin.php?page=slimpro')) . '">';
		switch ($state) {
			case 'none':
				/* translators: %1$s and %2$s wrap "SlimStat Pro" in a link to the Pro overview; %3$s and %4$s make "find your license key" bold. */
				$text = sprintf(__('%1$sSlimStat Pro%2$s is active, but its features stay locked until you activate a license for this site. Already purchased? Sign in to your SlimStat account to %3$sfind your license key%4$s. Haven\'t purchased yet? View plans and pricing.', 'wp-slimstat'), $pro, '</a>', '<strong>', '</strong>');
				break;
			case 'domain_not_activated':
				/* translators: %1$s and %2$s wrap "SlimStat Pro" in a link to the Pro overview. */
				$text = sprintf(__('Your %1$sSlimStat Pro%2$s license is not activated for this site, so Pro features are locked. Activate it in License settings. Need your key? Sign in to your SlimStat account to find it.', 'wp-slimstat'), $pro, '</a>');
				break;
			case 'invalid':
				/* translators: %1$s and %2$s wrap "SlimStat Pro" in a link to the Pro overview. */
				$text = sprintf(__('Your %1$sSlimStat Pro%2$s license is expired, revoked or not valid for this site, so Pro features are locked. Correct the key in License settings, find your current key in your SlimStat account, or view plans to renew.', 'wp-slimstat'), $pro, '</a>');
				break;
			default:
				$text = __('SlimStat could not verify your Pro license, so Pro features are locked for now. SlimStat retries automatically. To retry now, select Save Changes in License settings.', 'wp-slimstat');
		}
		return $text . ' ' . $kept;
	}

	/** Where an administrator fixes the lock, as [label, url, external], or [] when they cannot fix it from a link. */
	public static function action(): array
	{
		return self::links()[0] ?? [];
	}

	/**
	 * The fix first, then where to find or buy a key when that can help, each as [label, url, external].
	 * [] for anyone who cannot manage the license: they are told to ask an administrator instead.
	 */
	public static function links(): array
	{
		$state = \wp_slimstat::pro_plugin_active() && current_user_can('manage_options') ? self::state() : 'valid';
		if ('valid' === $state) {
			return [];
		}
		if (!class_exists(self::CLIENT)) {
			return [[__('Open Plugins', 'wp-slimstat'), admin_url('plugins.php'), false]];
		}
		$path  = 'admin.php?page=slimconfig&tab=8';
		$links = [[
			in_array($state, ['none', 'domain_not_activated'], true) ? __('Activate license', 'wp-slimstat') : __('Open License settings', 'wp-slimstat'),
			is_network_admin() ? network_admin_url($path) : admin_url($path),
			false,
		]];
		// A failed verification is not a missing or wrong key, so it offers only the retry.
		if ('unverified' !== $state) {
			$links[] = [__('Find my license', 'wp-slimstat'), \SlimStat\Utils\ProLinks::tag(self::ACCOUNT, 'license-activation', 'license-banner-find-license'), true];
		}
		if (in_array($state, ['none', 'invalid'], true)) {
			$links[] = [__('View plans', 'wp-slimstat'), \SlimStat\Utils\ProLinks::url('license-notice', $state), true];
		}
		return $links;
	}

	/** The locked-Pro banner. It cannot be dismissed, and it disappears once the license is valid. */
	public static function render(): void
	{
		$message = self::message();
		if ('' === $message) {
			return;
		}
		$links = self::links();
		// A wrong key, or one not activated here, is an error; a missing key, an outage or an outdated Pro is a warning.
		$tone = $links && class_exists(self::CLIENT) && in_array(self::state(), ['invalid', 'domain_not_activated'], true) ? 'error' : 'warning';
		echo '<div class="notice notice-' . esc_attr($tone) . ' slimstat-notice slimstat-pro-license-notice" role="status"><p>' . wp_kses($message, ['a' => ['href' => true], 'strong' => []]) . '</p>';
		if ($links) {
			echo '<p class="slimstat-pro-license-notice__actions">';
			foreach ($links as $i => [$label, $url, $external]) {
				echo '<a class="button' . (0 === $i ? ' button-primary' : '') . '" href="' . esc_url($url) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . esc_html($label);
				if ($external) {
					echo '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'wp-slimstat') . '</span>';
				}
				echo '</a> ';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/** Whether this Dashboard or Plugins screen shows the banner: administrators only, while Pro is locked. It reads the stored verdict only. */
	public static function on_screen(): bool
	{
		return in_array($GLOBALS['pagenow'] ?? '', ['index.php', 'plugins.php'], true) && current_user_can('manage_options') && '' !== self::message();
	}

	/** The banner on the Dashboard and the Plugins screen. */
	public static function screen_notice(): void
	{
		if (self::on_screen()) {
			self::render();
		}
	}

	private static function ask($client, string $key): string
	{
		$response = $client->licenseStatus($key, self::domain());
		if (is_wp_error($response)) {
			$data = $response->get_error_data();
			return is_array($data) && in_array((int) ($data['status'] ?? 0), [401, 403], true) ? 'invalid' : 'unreachable';
		}
		// Same classification as Pro's own license check: a malformed answer is an outage, never a verdict.
		$status  = is_array($response) ? ($response['status'] ?? null) : null;
		$details = is_array($response) && array_key_exists('license_details', $response) ? $response['license_details'] : [];
		if (!in_array($status, self::DEFINITIVE, true) || !is_array($details) || (array_key_exists('status', $details) && !is_string($details['status']))) {
			return 'unreachable';
		}
		if ('valid' === $status) {
			return 'active' === ($details['status'] ?? 'active') ? 'valid' : 'invalid';
		}
		return $status;
	}

	private static function due(string $key): bool
	{
		$verdict = self::verdict($key) ?? [];
		$now     = time();
		return abs($now - (int) ($verdict['tried'] ?? 0)) >= self::RETRY && abs($now - (int) ($verdict['checked'] ?? 0)) >= self::DUE;
	}

	private static function interactive(): bool
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; nothing is changed from this value.
		$page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		return is_admin() && !wp_doing_ajax() && 0 === strpos($page, 'slim') && current_user_can('manage_options');
	}

	private static function detach(): void
	{
		foreach ((array) ($GLOBALS['wp_filter'] ?? []) as $tag => $hook) {
			foreach ((array) ($hook->callbacks ?? []) as $priority => $callbacks) {
				foreach ($callbacks as $callback) {
					if (self::locked(self::owner($callback['function']))) {
						remove_filter($tag, $callback['function'], $priority);
					}
				}
			}
		}
		// Pro's network view hands Free's own menu builder to the network admin, so no Pro class owns it.
		remove_action('network_admin_menu', ['wp_slimstat_admin', 'add_menus']);
	}

	private static function owner($callback): string
	{
		if (is_array($callback)) {
			$callback = $callback[0] ?? '';
		}
		if ($callback instanceof \Closure) {
			$scope = (new \ReflectionFunction($callback))->getClosureScopeClass();
			return $scope ? $scope->getName() : '';
		}
		if (is_object($callback)) {
			return get_class($callback);
		}
		return is_string($callback) ? ltrim(explode('::', $callback)[0], '\\') : '';
	}

	private static function locked(string $class): bool
	{
		// Custom DB keeps running while locked, so tracking never splits across two databases.
		if (0 !== strpos($class, 'WpSlimstatPro\\') || 'WpSlimstatPro\Addon\Addons\CustomDBAddon' === $class) {
			return false;
		}
		foreach (self::KEPT as $prefix) {
			if (0 === strpos($class, $prefix)) {
				return false;
			}
		}
		return true;
	}

	private static function verdict(string $key): ?array
	{
		$verdict = get_option(self::OPTION, []);
		return is_array($verdict) && is_string($verdict['hash'] ?? null) && hash_equals($verdict['hash'], self::hash($key)) ? $verdict : null;
	}

	private static function hash(string $key): string
	{
		return hash('sha256', $key . '|' . self::domain());
	}

	/** The same domain Pro sends: the site host without a leading www. */
	private static function domain(): string
	{
		return (string) preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
	}
}
