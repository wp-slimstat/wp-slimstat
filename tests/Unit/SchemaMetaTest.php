<?php
/**
 * SlimStat\Schema\Meta — the C48 identity and lease primitives on `slim_meta`.
 *
 * The table lives ON THE CONNECTION IT DESCRIBES (the analytics database, wherever that
 * is), which is the entire supersession of ADR-14: identity must not be derived from
 * credentials, because dbhost changes on failover and a dump copies every credential-
 * derived fingerprint byte-for-byte into a different database. `install_uuid` and
 * `owner_site_url` travel WITH the data; `home_url()` does not travel with a dump —
 * that asymmetry is what P5's topology-F refusal reads.
 *
 * The lease facts this test pins, all learned on OptionClaim:
 *
 *   - A claim is ONE statement plus a READ-BACK. The write is INSERT ... ON DUPLICATE
 *     KEY UPDATE with the steal condition in SQL; whether *I* hold the lease is decided
 *     by re-reading the row, never by interpreting the write's return value (rows-
 *     affected is 1 for insert, 2 for update, 0 for no-op — three values, none of which
 *     answers "who holds it now" under a concurrent writer).
 *   - Identity is written with a bare INSERT IGNORE so the PRIMARY KEY rejects the
 *     loser, and the value RETURNED is the re-read row — what the table holds, not what
 *     this process tried to write (first-writer-wins, add_option()'s trap avoided).
 *   - Release is holder-guarded: DELETE ... AND meta_value = %s cannot release a lease
 *     someone else has since stolen.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use SlimStat\Schema\Meta;
use SlimStat\Schema\Schema;

class SchemaMetaTest extends WpSlimstatTestCase
{
    /** @var string[] Statements issued through query(). */
    private array $statements = [];

    /** @var mixed What the next query() reports. */
    private $writeResult = 1;

    /** @var array<int, mixed> Queued get_row() responses (each shifted per call). */
    private array $rows = [];

    /** @var array<int, mixed> Queued get_var() responses. */
    private array $vars = [];

    /** @var \Mockery\MockInterface&\wpdb */
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statements  = [];
        $this->writeResult = 1;
        $this->rows        = [];
        $this->vars        = [];

        $db = \Mockery::mock(\wpdb::class);
        $db->shouldReceive('prepare')->andReturnUsing(
            static fn($sql, ...$args) => $sql . ' -- ' . implode('|', array_map('strval', $args))
        );
        $db->shouldReceive('query')->andReturnUsing(function ($sql) {
            $this->statements[] = $sql;
            return $this->writeResult;
        });
        $db->shouldReceive('get_var')->andReturnUsing(function ($sql) {
            $this->statements[] = $sql;
            return array_shift($this->vars);
        });
        $db->shouldReceive('get_row')->andReturnUsing(function ($sql) {
            $this->statements[] = $sql;
            return array_shift($this->rows);
        });
        $this->db = $db;
    }

    // ── the manifest declares the table (C39: fresh installs are born with it) ──────────

    public function test_slim_meta_is_a_manifest_table_with_meta_key_primary(): void
    {
        // Public projections only — the manifest itself is private, and the DDL is what
        // an install actually receives anyway.
        $this->assertContains('wp_slim_meta', Schema::tableNames('wp_'));

        $sql = Schema::createTableSql('slim_meta', 'wp_', 'utf8mb4_unicode_ci');
        $this->assertStringContainsString('PRIMARY KEY (meta_key)', $sql);
        // 191, not 256: the PRIMARY KEY must fit 767 index bytes under utf8mb4.
        $this->assertMatchesRegularExpression('/meta_key\s+VARCHAR\(191\)\s+NOT NULL/i', $sql);
        $this->assertMatchesRegularExpression('/meta_value\s+VARCHAR\(2048\)/i', $sql);
        $this->assertMatchesRegularExpression('/dt\s+INT\(10\) UNSIGNED NOT NULL/i', $sql);
    }

    // ── get / putIfAbsent ───────────────────────────────────────────────────────────────

    public function test_get_reads_one_row_by_key_and_null_means_absent(): void
    {
        $this->vars[] = null;
        $this->assertNull(Meta::get($this->db, 'wp_', 'install_uuid'));

        $this->vars[] = 'abc';
        $this->assertSame('abc', Meta::get($this->db, 'wp_', 'install_uuid'));

        $this->assertStringContainsString('wp_slim_meta', $this->statements[0]);
        $this->assertStringContainsString('meta_key = %s', $this->statements[0]);
    }

    public function test_put_if_absent_is_insert_ignore_so_the_primary_key_rejects_the_loser(): void
    {
        $this->assertTrue(Meta::putIfAbsent($this->db, 'wp_', 'k', 'v'));
        $this->assertCount(1, $this->statements);
        $this->assertMatchesRegularExpression('/^\s*INSERT IGNORE INTO wp_slim_meta/i', $this->statements[0]);
        $this->assertStringNotContainsString('ON DUPLICATE', $this->statements[0]);
    }

    public function test_put_is_an_unconditional_upsert_for_the_adopt_path_only(): void
    {
        $this->assertTrue(Meta::put($this->db, 'wp_', 'owner_site_url', 'https://new.example'));
        $this->assertCount(1, $this->statements);
        $this->assertMatchesRegularExpression('/^\s*INSERT INTO wp_slim_meta/i', $this->statements[0]);
        $this->assertMatchesRegularExpression('/ON DUPLICATE KEY UPDATE meta_value = VALUES\(meta_value\)/i', $this->statements[0]);
        // No IGNORE: overwriting is this method's whole reason to exist (SLIMSTAT_EXT_DB_ADOPT).
        $this->assertStringNotContainsStringIgnoringCase('IGNORE', $this->statements[0]);
    }

    // ── ensureIdentity: first-writer-wins, and the answer is the RE-READ row ────────────

    public function test_ensure_identity_mints_uuid_and_owner_once_and_returns_what_the_table_holds(): void
    {
        Functions\when('wp_generate_uuid4')->justReturn('11111111-2222-3333-4444-555555555555');
        Functions\when('home_url')->justReturn('https://site-a.example');

        // First reads: both absent → both written; re-read returns the CONCURRENT
        // winner's values, not ours — and ensureIdentity must report the winner's.
        $this->vars = [
            null, null,                                     // initial get(): uuid, owner
            '99999999-8888-7777-6666-555555555555',         // re-read uuid (someone else won)
            'https://site-b.example',                       // re-read owner
        ];

        $identity = Meta::ensureIdentity($this->db, 'wp_');

        $this->assertSame('99999999-8888-7777-6666-555555555555', $identity['install_uuid']);
        $this->assertSame('https://site-b.example', $identity['owner_site_url']);

        $writes = array_values(array_filter($this->statements,
            static fn($s) => false !== stripos($s, 'INSERT')));
        $this->assertCount(2, $writes);
        foreach ($writes as $w) {
            $this->assertMatchesRegularExpression('/INSERT IGNORE/i', $w);
        }
    }

    public function test_ensure_identity_writes_nothing_when_identity_already_exists(): void
    {
        $this->vars = ['uuid-already', 'https://owner.example'];

        $identity = Meta::ensureIdentity($this->db, 'wp_');

        $this->assertSame('uuid-already', $identity['install_uuid']);
        $this->assertSame('https://owner.example', $identity['owner_site_url']);
        foreach ($this->statements as $s) {
            $this->assertStringNotContainsStringIgnoringCase('INSERT', $s);
        }
    }

    // ── claimLease: one upsert with the steal condition in SQL, then a read-back ────────

    public function test_claim_is_won_only_when_the_read_back_row_holds_my_name_unexpired(): void
    {
        $now = 1700000000;

        $this->rows[] = (object) ['meta_value' => 'me', 'dt' => (string) ($now + 300)];
        $this->assertTrue(Meta::claimLease($this->db, 'wp_', 'schema', 'me', 300, $now));

        $upsert = $this->statements[0];
        $this->assertMatchesRegularExpression('/INSERT INTO wp_slim_meta/i', $upsert);
        $this->assertMatchesRegularExpression('/ON DUPLICATE KEY UPDATE/i', $upsert);
        // The steal condition's SHAPE is the contract, asserted literally: a lease is
        // stolen when EXPIRED (dt < now — flip that and every live lease is stealable),
        // and the assignments run dt FIRST (while meta_value still holds the OLD holder)
        // then meta_value keyed off dt's outcome — MySQL evaluates them left to right,
        // so reordering them silently changes who wins.
        $this->assertMatchesRegularExpression(
            '/dt\s*=\s*IF\s*\(\s*meta_value\s*=\s*VALUES\(meta_value\)\s*OR\s*dt\s*<\s*%d\s*,\s*VALUES\(dt\)\s*,\s*dt\s*\)/i',
            $upsert
        );
        $this->assertMatchesRegularExpression(
            '/meta_value\s*=\s*IF\s*\(\s*dt\s*=\s*VALUES\(dt\)\s*,\s*VALUES\(meta_value\)\s*,\s*meta_value\s*\)/i',
            $upsert
        );
        $this->assertLessThan(
            stripos($upsert, 'meta_value = IF'),
            stripos($upsert, 'dt         = IF'),
            'dt must be assigned BEFORE meta_value — the second assignment reads the first\'s outcome'
        );
        // The read-back is a second statement; the verdict never comes from the upsert.
        $this->assertGreaterThanOrEqual(2, count($this->statements));
    }

    public function test_claim_is_lost_when_the_read_back_shows_another_unexpired_holder(): void
    {
        $now = 1700000000;

        $this->rows[] = (object) ['meta_value' => 'other', 'dt' => (string) ($now + 200)];
        $this->assertFalse(Meta::claimLease($this->db, 'wp_', 'schema', 'me', 300, $now));
    }

    public function test_claim_is_lost_when_the_write_itself_fails(): void
    {
        $this->writeResult = false;
        $this->assertFalse(Meta::claimLease($this->db, 'wp_', 'schema', 'me', 300, 1700000000));
    }

    public function test_claim_is_lost_when_the_read_back_row_is_gone(): void
    {
        $this->rows[] = null;
        $this->assertFalse(Meta::claimLease($this->db, 'wp_', 'schema', 'me', 300, 1700000000));
    }

    // ── releaseLease: holder-guarded, so a stolen lease cannot be released by the loser ─

    public function test_release_deletes_only_my_own_lease_row(): void
    {
        $this->assertTrue(Meta::releaseLease($this->db, 'wp_', 'schema', 'me'));

        $delete = $this->statements[0];
        $this->assertMatchesRegularExpression('/^\s*DELETE FROM wp_slim_meta/i', $delete);
        $this->assertStringContainsString('meta_key = %s', $delete);
        $this->assertStringContainsString('meta_value = %s', $delete);
        $this->assertStringContainsString('me', $delete);
    }
}
