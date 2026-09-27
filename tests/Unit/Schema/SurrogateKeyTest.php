<?php
/**
 * Deterministic surrogate keys (F10 / C44).
 *
 * The whole affordability of ADR-9's Layer 1 rests on one property: a dimension key can be
 * computed from its value WITHOUT asking the database. If that ever stops being true — a key
 * that varies by host, by PHP version, by call — the tracker goes back to a SELECT-then-INSERT
 * per dimension per hit, which costs more than the star schema saves.
 *
 * These pin the properties a join depends on, not the hash function. Changing the algorithm is
 * allowed; producing a different key for the same value on a second call is not.
 *
 * @package wp-slimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Schema;

use SlimStat\Schema\SurrogateKey;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class SurrogateKeyTest extends WpSlimstatTestCase
{
    public function testTheSameValueAlwaysYieldsTheSameKey(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';

        $this->assertSame(
            SurrogateKey::for($ua),
            SurrogateKey::for($ua),
            'a key that varies between calls cannot be a primary key'
        );
    }

    public function testDifferentValuesYieldDifferentKeys(): void
    {
        $this->assertNotSame(
            SurrogateKey::for('Chrome/120'),
            SurrogateKey::for('Chrome/121'),
            'user agents commonly differ only in a version suffix; keys must separate them'
        );
    }

    public function testTheKeyIsExactlyTheDeclaredWidth(): void
    {
        // The manifest declares BINARY(WIDTH). If these disagree MySQL truncates silently, and
        // every key sharing its last bytes collapses into one dimension row.
        foreach (['', 'a', str_repeat('x', 2048)] as $value) {
            $this->assertSame(
                SurrogateKey::WIDTH,
                strlen(SurrogateKey::for($value)),
                'key width must not vary with input length'
            );
        }
    }

    public function testTheEmptyValueHasItsOwnKey(): void
    {
        // A hit with no user agent is a real case. Without a key it would join to nothing and
        // the report would drop the row rather than showing it as unknown.
        $this->assertSame(SurrogateKey::WIDTH, strlen(SurrogateKey::empty()));
        $this->assertSame(SurrogateKey::for(''), SurrogateKey::empty());
    }

    public function testWhitespaceIsNormalisedButCaseIsNot(): void
    {
        $this->assertSame(
            SurrogateKey::for('Chrome/120'),
            SurrogateKey::for('  Chrome/120  '),
            'trailing whitespace is transport noise, not a different browser'
        );

        // Deliberately NOT case-insensitive: lowercasing would merge genuinely distinct agents,
        // and every extra normalisation rule is a second parser that must agree with whatever
        // reads the dimension back.
        $this->assertNotSame(
            SurrogateKey::for('chrome/120'),
            SurrogateKey::for('Chrome/120')
        );
    }

    public function testHexIsTheSameKeyRenderedDifferently(): void
    {
        $ua = 'curl/8.4.0';

        $this->assertSame(
            bin2hex(SurrogateKey::for($ua)),
            SurrogateKey::hex($ua),
            'hex must be derived from the binary key, not computed independently — two '
                . 'implementations of one key are free to disagree'
        );
        $this->assertSame(SurrogateKey::WIDTH * 2, strlen(SurrogateKey::hex($ua)));
    }

    public function testTheKeyIsRawBinaryAndNotHex(): void
    {
        // A BINARY(8) column holding 16 hex characters truncates to 8 and collides on every
        // value sharing a prefix. Asserted because the two forms are easy to confuse and the
        // failure is silent.
        $key = SurrogateKey::for('Mozilla/5.0');

        $this->assertSame(8, strlen($key));
        $this->assertNotSame(1, preg_match('/^[0-9a-f]{16}$/', $key));
    }

    public function testKeysAreWellDistributedAcrossRealisticInput(): void
    {
        // Not a hash-quality test — a vacuity guard. If normalisation ever collapsed its input
        // (lowercasing everything, or truncating), the assertions above would still pass while
        // the dimension merged unrelated rows. 5,000 distinct agents, zero collisions expected:
        // at 64 bits the birthday probability here is about 7e-13.
        $keys = [];
        for ($i = 0; $i < 5000; $i++) {
            $keys[SurrogateKey::hex('Mozilla/5.0 (compatible; Agent/' . $i . '.0)')] = true;
        }

        $this->assertCount(5000, $keys, 'distinct inputs collapsed into fewer keys');
    }
}
