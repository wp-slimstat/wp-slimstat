<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Ecommerce;

use SlimStat\Tracker\Acquisition;

/** Pure projection of WC CRUD objects. WooCommerce remains the source of truth. */
final class OrderData
{
	/** One summary plus product/coupon rows; IDs make repeated synchronization idempotent. */
	public static function rows($order): array
	{
		if ('shop_order' !== $order->get_type() || 'trash' === $order->get_status() || !$order->get_date_created()) {
			return [];
		}
		$utc = $order->get_date_created()->getTimestamp();
		$base = [
			'order_id' => $order->get_id(), 'item_id' => 0, 'kind' => 0,
			'created_utc' => $utc,
			'dt' => (new \DateTimeImmutable(wp_date('Y-m-d H:i:s', $utc, wp_timezone()), new \DateTimeZone('UTC')))->getTimestamp(),
			'currency' => substr($order->get_currency(), 0, 8), 'status' => $order->get_status(),
			'customer' => $order->get_customer_id() > 0 ? 1 : 0,
			'channel' => '', 'source' => '', 'campaign' => '',
			'net' => '0', 'refund' => '0', 'discount' => '0', 'tax' => '0', 'shipping' => '0', 'quantity' => '0',
		];
		// Expired orders need no item/refund reads, including invalid legacy metadata.
		if ($base['dt'] < Integration::retentionStart()) { return []; }
		$type = $order->get_meta('_wc_order_attribution_source_type');
		if (in_array($type, ['utm', 'organic', 'referral', 'typein'], true)) {
			$tags = [];
			foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
				$value = (string) $order->get_meta('_wc_order_attribution_' . $key);
				if (!in_array($value, ['', '(none)', '(not set)', '(direct)'], true)) {
					$tags[$key] = $value;
				}
			}
			$referrer = (string) $order->get_meta('_wc_order_attribution_referrer');
			if ('typein' === $type || isset($tags['utm_source']) || filter_var($referrer, FILTER_VALIDATE_URL)) {
				$attribution = Acquisition::classify($tags, $referrer, '', 0, home_url());
				$base['channel'] = 'typein' === $type ? 'direct' : $attribution['traffic_channel'];
				$base['source'] = self::text($attribution['traffic_source'] ?? '', 191);
				$base['campaign'] = self::text($tags['utm_campaign'] ?? '', 191);
			}
		}

		$refunds = [];
		$lineRefunds = [];
		$refunded = $taxRefunded = $shippingRefunded = 0;
		foreach ($order->get_refunds() as $refund) {
			// Refund line tax/shipping totals are negative; amount is positive.
			$tax = abs(Money::units((string) $refund->get_total_tax()));
			$shipping = abs(Money::units((string) $refund->get_shipping_total()));
			$amount = Money::units((string) $refund->get_amount());
			$refunds[] = ['amount' => Money::decimal($amount), 'tax' => Money::decimal($tax), 'shipping' => Money::decimal($shipping)];
			$refunded += $amount;
			$taxRefunded += $tax;
			$shippingRefunded += $shipping;
			foreach ($refund->get_items('line_item') as $line) {
				$id = (int) $line->get_meta('_refunded_item_id');
				$lineRefunds[$id]['total'] = ($lineRefunds[$id]['total'] ?? 0) + Money::units((string) $line->get_total());
				$lineRefunds[$id]['quantity'] = ($lineRefunds[$id]['quantity'] ?? 0) + Money::units((string) $line->get_quantity());
			}
		}
		$base['net'] = Money::decimal(Money::net((string) $order->get_total(), (string) $order->get_total_tax(), (string) $order->get_shipping_total(), $refunds));
		$base['refund'] = Money::decimal($refunded);
		$base['discount'] = (string) $order->get_discount_total();
		$base['tax'] = Money::decimal(Money::units((string) $order->get_total_tax()) - $taxRefunded);
		$base['shipping'] = Money::decimal(Money::units((string) $order->get_shipping_total()) - $shippingRefunded);
		$rows = [$base];
		foreach ($order->get_items('line_item') as $id => $item) {
			$line = $base;
			$line['item_id'] = $id;
			$line['kind'] = 1;
			$line['product_id'] = $item->get_variation_id() ?: $item->get_product_id();
			$line['label'] = self::text($item->get_name(), 255);
			$line['net'] = Money::decimal(Money::units((string) $item->get_total()) + ($lineRefunds[$id]['total'] ?? 0));
			$line['quantity'] = Money::decimal(Money::units((string) $item->get_quantity()) + ($lineRefunds[$id]['quantity'] ?? 0));
			$line['refund'] = Money::decimal(-($lineRefunds[$id]['total'] ?? 0));
			$line['discount'] = Money::decimal(Money::units((string) $item->get_subtotal()) - Money::units((string) $item->get_total()));
			$rows[] = $line;
		}
		foreach ($order->get_items('coupon') as $id => $item) {
			$line = $base;
			$line['item_id'] = $id;
			$line['kind'] = 2;
			$line['label'] = self::text($item->get_code(), 255);
			$line['net'] = $line['refund'] = '0';
			$line['discount'] = (string) $item->get_discount();
			$rows[] = $line;
		}
		return $rows;
	}

	/** Bounded plain text only; no order/customer identifiers in dimension labels. */
	private static function text(string $value, int $length): string
	{
		return mb_substr(trim(wp_strip_all_tags(wp_check_invalid_utf8($value))), 0, $length);
	}
}
