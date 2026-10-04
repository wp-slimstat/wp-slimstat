<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Ecommerce;

use PHPUnit\Framework\TestCase;
use SlimStat\Ecommerce\Integration;

class SyncStateTest extends TestCase
{
	/** A failed order after the import finished is its own message; the totals are not provisional. */
	public function test_only_an_unfinished_import_makes_figures_provisional(): void
	{
		self::assertTrue(Integration::provisional([]));
		self::assertTrue(Integration::provisional(['complete' => false, 'error' => false]));
		self::assertTrue(Integration::provisional(['complete' => false, 'error' => true]));
		self::assertFalse(Integration::provisional(['complete' => true, 'error' => true, 'failed_order' => 8275]));
		self::assertFalse(Integration::provisional(['complete' => true, 'error' => false]));
	}
}
