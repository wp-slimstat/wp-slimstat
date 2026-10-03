<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Heatmap;

use Brain\Monkey\Functions;
use SlimStat\Heatmap\Query;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class QueryTest extends WpSlimstatTestCase
{
	/** D2: devices come from the numeric width, never a string comparison of "WxH". */
	public function test_device_buckets_compare_widths_as_numbers(): void
	{
		self::assertSame('mobile', Query::device(360));
		self::assertSame('mobile', Query::device(767));
		self::assertSame('tablet', Query::device(768));
		self::assertSame('tablet', Query::device(1023));
		self::assertSame('desktop', Query::device(1024));
		self::assertSame('desktop', Query::device(1920));
		// As strings, '360x800' > '1200' and '1024x768' < '1200'; the old filter got both wrong.
		self::assertNotSame(Query::device(360), Query::device(1920));
		self::assertSame('', Query::device(0));
	}

	/** D4: a page key is the exact page, so '/' is never a blend of the whole site. */
	public function test_page_key_is_the_exact_page(): void
	{
		$cases = [
			'/'                         => '/',
			'/?utm_source=x'            => '/',
			'/#top'                     => '/',
			'/about'                    => '/about',
			'/about-us'                 => '/about-us',
			'/about?ref=nav#team'       => '/about',
			'/?p=12'                    => '/?p=12',
			'/?p=12&replytocom=4'       => '/?p=12',
			'/?page_id=7#comments'      => '/?page_id=7',
			'/blog/?p=3'                => '/blog/?p=3',
			'/?s=p=1'                   => '/',
			'/shop/?product=x&p=4'      => '/shop/',
		];
		foreach ($cases as $resource => $key) {
			self::assertSame($key, Query::pageKey($resource), $resource);
		}
		self::assertNotSame(Query::pageKey('/about-us'), Query::pageKey('/about'));
	}

	/** The overlay sends location's percent-encoded path; it must match the key ingest stored. */
	public function test_page_key_from_browser_url_matches_stored_resource(): void
	{
		Functions\when('sanitize_text_field')->returnArg();
		self::assertSame('/caf%c3%a9/', Query::pageKeyFromUrl('/caf%C3%A9/?utm=x'));
		self::assertSame('/about us', Query::pageKeyFromUrl('/about%20us#team'));
		self::assertSame('/?p=12', Query::pageKeyFromUrl('/?p=12&replytocom=4'));
	}

	public function test_element_identity_comes_from_legacy_notes(): void
	{
		self::assertSame(['id' => 'buy-now', 'text' => 'Buy now'], Query::element('{"text":"  Buy\n now ","id":"buy-now","type":"click"}'));
		self::assertSame(['id' => '', 'text' => ''], Query::element('{"type":"click"}'));
		// Notes are cut at 256 bytes; the id survives a truncated object.
		self::assertSame(['id' => 'menu-toggle', 'text' => ''], Query::element('{"id":"menu-toggle","text":"' . str_repeat('a', 300)));
		// Ids that could break a selector are dropped, not escaped into one.
		self::assertSame('', Query::element('{"id":"x\"]);alert(1)//"}')['id']);
		self::assertSame('', Query::element('not json')['id']);
	}
}
