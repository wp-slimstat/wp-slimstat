<?php
/** Optional dependency signatures for static analysis only. Never loaded at runtime. */
namespace {
	function wc_get_orders(array $args = []) {}
	function wc_get_order($order = false) {}
	function wc_get_order_statuses(): array {}
	function wc_get_page_id(string $page): int {}
	function get_woocommerce_currency(): string {}
	function wc_price($price, array $args = []): string {}
	function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int {}
	function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int {}
	function as_unschedule_all_actions(string $hook, array $args = [], string $group = '') {}
}
namespace Automattic\WooCommerce\Utilities {
	class OrderUtil {
		public static function get_order_type($order_id) {}
		public static function custom_orders_table_usage_is_enabled(): bool {}
	}
	class FeaturesUtil {
		public static function declare_compatibility(string $feature_id, string $plugin_file, bool $positive_compatibility = true): void {}
	}
}
