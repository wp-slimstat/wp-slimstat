<?php
/**
 * Name resolution for the per-blog table API (F8) lives in the manifest.
 *
 * `wp_slimstat::table()` / `::tables()` are thin wrappers: they resolve a prefix via
 * `wpdb::get_blog_prefix()` and delegate the name half here. These tests pin the name half:
 * every declared suffix resolves, an undeclared one throws AT CALL TIME naming the typo
 * (rather than at query time naming a table that does not exist), and the batch form covers
 * exactly the manifest — no more, no fewer — so a Phase G table added to the manifest is in
 * the API the moment it is declared, and a hand-maintained second list cannot drift.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use SlimStat\Schema\Schema;

class TableNameTest extends TestCase
{
    public function testEveryDeclaredSuffixResolvesUnderThePrefix(): void
    {
        foreach (Schema::tables() as $suffix) {
            $this->assertSame('wp_7_' . $suffix, Schema::tableName($suffix, 'wp_7_'));
        }
    }

    public function testUnknownSuffixThrowsNamingTheTypoAndTheAlternatives(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown table suffix 'slim_stat'");

        Schema::tableName('slim_stat', 'wp_');
    }

    /**
     * A fully-qualified name is not a suffix. Without this, a caller passing the already-
     * prefixed name it happens to hold gets `wp_wp_slim_stats` back from the batch form's
     * sibling and an SQL error pointing nowhere near the cause.
     */
    public function testAlreadyPrefixedNameIsRejectedNotDoublePrefixed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Schema::tableName('wp_slim_stats', 'wp_');
    }

    public function testBatchFormCoversExactlyTheManifest(): void
    {
        $names = Schema::tableNames('wp_3_');

        $this->assertSame(Schema::tables(), array_keys($names), 'keys are the manifest suffixes, in manifest order');

        foreach ($names as $suffix => $name) {
            $this->assertSame('wp_3_' . $suffix, $name);
        }
    }
}
