<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Ecommerce;

/** Exact decimal arithmetic for WooCommerce amounts; no BCMath dependency. */
final class Money
{
	private const SCALE = 1000000;

	/** Parse up to six decimal places. Reject overflow/precision loss rather than misreport. */
	public static function units(string $value): int
	{
		if (!preg_match('/\A(-?)(\d{1,12})(?:\.(\d{1,6}))?\z/', $value, $parts)) {
			throw new \InvalidArgumentException('Unsupported commerce amount.');
		}
		$amount = (int) $parts[2] * self::SCALE + (int) str_pad($parts[3] ?? '', 6, '0');
		return '-' === $parts[1] ? -$amount : $amount;
	}

	/** Format the internal integer for DECIMAL storage. */
	public static function decimal(int $amount): string
	{
		return ($amount < 0 ? '-' : '') . intdiv(abs($amount), self::SCALE) . '.'
			. str_pad((string) (abs($amount) % self::SCALE), 6, '0', STR_PAD_LEFT);
	}

	/** Current net sales of an order cohort; totals already contain discounts. */
	public static function net(string $total, string $tax, string $shipping, array $refunds): int
	{
		$net = self::units($total) - self::units($tax) - self::units($shipping);
		foreach ($refunds as $refund) {
			$net -= self::units($refund['amount']) - self::units($refund['tax']) - self::units($refund['shipping']);
		}
		return $net;
	}
}
