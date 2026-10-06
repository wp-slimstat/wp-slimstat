<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Ecommerce;

use PHPUnit\Framework\TestCase;
use SlimStat\Ecommerce\Money;

class MoneyTest extends TestCase
{
	public function test_decimal_values_round_trip_without_binary_float_loss(): void
	{
		foreach (['0', '0.1', '19.99', '-4.125', '999999999999.123456'] as $value) {
			self::assertSame(Money::units($value), Money::units(Money::decimal(Money::units($value))));
		}
		self::assertSame(300000, Money::units('0.1') + Money::units('0.2'));
		self::assertSame('-0.000001', Money::decimal(-1));
	}

	public function test_discounts_are_not_subtracted_twice_and_refund_tax_shipping_are_removed(): void
	{
		// Discounted goods 80 + fee 5 + shipping 10 + tax 19 = 114.
		// Refund: goods 20 + shipping 5 + tax 5 = 30. Net sales: 85 - 20 = 65.
		self::assertSame('65.000000', Money::decimal(Money::net('114', '19', '10', [
			['amount' => '30', 'tax' => '5', 'shipping' => '5'],
		])));
		self::assertSame(0, Money::net('114', '19', '10', [
			['amount' => '114', 'tax' => '19', 'shipping' => '10'],
		]));
	}

	/** @dataProvider invalidMoney */
	public function test_invalid_or_unsupported_precision_is_not_silently_coerced(string $value): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Money::units($value);
	}

	public static function invalidMoney(): array
	{
		return [[''], ['NaN'], ['1e3'], ['1,000'], ['0.0000001'], ['9999999999999']];
	}
}
