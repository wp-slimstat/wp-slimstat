<?php
// The corpus and the catalogue this harness hands the subject.
//
// The CATALOGUE is written out by hand rather than derived from Schema::columns(). That is the
// point: CONTROL 6's whole argument is that the manifest and the server's own catalogue are two
// parties, and a fixture computed from the manifest would reproduce the circularity the control
// exists to break — it would agree by construction, and dropping the control entirely would not
// change the result. Written out, the baseline PASS is two independently stated things agreeing,
// and one edited entry is drift.
//
// It is still a FIXTURE and not a server: it says nothing about what MySQL would report. See the
// header of fake-server.php.

/**
 * information_schema.COLUMNS as MySQL 8 renders it: [COLUMN_TYPE, IS_NULLABLE].
 *
 * Integer display width is absent because 8.0.19 removed it; varchar/binary lengths are kept.
 * That is the same split slimstat_fp2_canonical_type() applies from the other side, which is why
 * the two agree without either being derived from the other.
 */
function slimstat_neg_catalogue()
{
    return [
        'wp_slim_stats' => [
            'id'                => ['int unsigned', false],
            'ip'                => ['varchar(39)', true],
            'other_ip'          => ['varchar(39)', true],
            'username'          => ['varchar(256)', true],
            'email'             => ['varchar(256)', true],
            'country'           => ['varchar(16)', true],
            'location'          => ['varchar(36)', true],
            'city'              => ['varchar(256)', true],
            'referer'           => ['varchar(2048)', true],
            'resource'          => ['varchar(2048)', true],
            'searchterms'       => ['varchar(2048)', true],
            'notes'             => ['varchar(2048)', true],
            'visit_id'          => ['int unsigned', false],
            'server_latency'    => ['int unsigned', true],
            'page_performance'  => ['int unsigned', true],
            'browser'           => ['varchar(40)', true],
            'browser_version'   => ['varchar(15)', true],
            'browser_type'      => ['tinyint unsigned', true],
            'platform'          => ['varchar(15)', true],
            'language'          => ['varchar(5)', true],
            'fingerprint'       => ['varchar(256)', true],
            'user_agent'        => ['varchar(2048)', true],
            'resolution'        => ['varchar(12)', true],
            'screen_width'      => ['smallint unsigned', true],
            'screen_height'     => ['smallint unsigned', true],
            'content_type'      => ['varchar(64)', true],
            'category'          => ['varchar(256)', true],
            'author'            => ['varchar(64)', true],
            'content_id'        => ['bigint unsigned', true],
            'outbound_resource' => ['varchar(2048)', true],
            // The two v6 migrations the pinned set excludes ON PURPOSE. They are on the server
            // and the manifest declares nothing about them, which is exactly the array_diff
            // exemption CONTROL 6 carries — so they belong here, or that exemption is untested.
            'ua_id'             => ['binary(8)', true],
            'vid_hash'          => ['binary(16)', true],
            'tz_offset'         => ['smallint', true],
            'dt_out'            => ['int unsigned', true],
            'dt'                => ['int unsigned', true],
        ],
        'wp_slim_events' => [
            'event_id'          => ['int', false],
            'type'              => ['tinyint unsigned', true],
            'event_description' => ['varchar(64)', true],
            'notes'             => ['varchar(256)', true],
            'position'          => ['varchar(32)', true],
            'id'                => ['int unsigned', false],
            'dt'                => ['int unsigned', true],
        ],
    ];
}

/** information_schema.STATISTICS, NON_UNIQUE = 0 only: [INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME]. */
function slimstat_neg_unique_indexes()
{
    return [
        'wp_slim_stats'  => [['PRIMARY', 1, 'id']],
        'wp_slim_events' => [['PRIMARY', 1, 'event_id']],
    ];
}

/**
 * The corpus, per table, as [column => value] overlaid on a per-type default.
 *
 * Chosen so that the BASELINE exercises every encoding branch — NULL, empty string, ASCII,
 * multi-byte UTF-8, zero, a wide integer — and so that CONTROL 7's re-read has somewhere to go:
 * slim_events is the smaller table and therefore the one the subject reorders, and its `type`
 * column is distinct-valued with its minimum on a row that is NOT the first by event_id, which
 * is what makes the alternate ordering a genuine reordering rather than the same sequence.
 *
 * `ip` is non-NULL in every row on purpose: the guard-removal scenario drops row_sql()'s NULL
 * guard for that column, and a NULL there would move the fingerprint too, so the negative test
 * would no longer isolate CONTROL 4's per-column census from the hash comparison.
 */
function slimstat_neg_corpus_rows()
{
    return [
        'slim_stats' => [
            ['id' => 1, 'ip' => '192.168.1.1', 'other_ip' => null, 'username' => 'alice',
             'resource' => '/', 'browser' => 'Firefox', 'searchterms' => '', 'visit_id' => 1,
             'country' => 'us', 'content_id' => 0, 'dt' => 1750000001, 'tz_offset' => -120],
            ['id' => 2, 'ip' => '10.0.0.7', 'other_ip' => '10.0.0.8', 'username' => 'bob',
             'resource' => "/caf\u{00e9}", 'browser' => 'Chrome', 'searchterms' => null, 'visit_id' => 1,
             'country' => 'de', 'content_id' => 42, 'dt' => 1750000002, 'tz_offset' => 0],
            ['id' => 3, 'ip' => '10.0.0.9', 'other_ip' => null, 'username' => '',
             'resource' => '/a?b=1&c=2', 'browser' => 'Safari', 'searchterms' => 'wp slimstat',
             'visit_id' => 2, 'country' => 'fr', 'content_id' => 9007199254740993,
             'dt' => 1750000003, 'tz_offset' => 60],
            ['id' => 4, 'ip' => '172.16.0.1', 'other_ip' => null, 'username' => "d\u{00e4}ve",
             'resource' => '/x', 'browser' => null, 'searchterms' => null, 'visit_id' => 3,
             'country' => 'gb', 'content_id' => 7, 'dt' => 1750000004, 'tz_offset' => 0],
            ['id' => 5, 'ip' => '192.0.2.55', 'other_ip' => null, 'username' => 'eve',
             'resource' => '/y', 'browser' => 'Edge', 'searchterms' => 'a b', 'visit_id' => 3,
             'country' => 'jp', 'content_id' => 0, 'dt' => 1750000005, 'tz_offset' => 540],
        ],
        'slim_events' => [
            // `type` is 2,1,3 so its minimum sits on event_id 2 — a different first row than the
            // pinned ORDER BY gives, and untied, which is what CONTROL 7(b) requires.
            ['event_id' => 1, 'type' => 2, 'event_description' => 'click', 'notes' => null,
             'position' => '10,20', 'id' => 1, 'dt' => 1750000001],
            ['event_id' => 2, 'type' => 1, 'event_description' => 'submit', 'notes' => '',
             'position' => '30,40', 'id' => 2, 'dt' => 1750000002],
            ['event_id' => 3, 'type' => 3, 'event_description' => 'download', 'notes' => 'pdf',
             'position' => '50,60', 'id' => 3, 'dt' => 1750000003],
        ],
    ];
}

/**
 * Build the SQLite table that stands in for the MySQL one, and fill it.
 *
 * varchars get TEXT affinity rather than BLOB because `col = ''` must be able to be TRUE — a
 * BLOB never compares equal to a TEXT literal in SQLite, which would make row_sql()'s \EMPTY
 * branch unreachable and CONTROL 8 vacuous here for a reason that has nothing to do with MySQL.
 */
function slimstat_neg_build_corpus(SQLite3 $db, $suffix, array $columns, array $rows, $prefix = 'wp_')
{
    $decl = [];
    foreach ($columns as $col) {
        $decl[] = '`' . $col[0] . '` ' . ('int' === slimstat_fp2_kind($col[1]) ? 'INTEGER' : 'TEXT');
    }
    $table = $prefix . $suffix;
    $db->exec('CREATE TABLE `' . $table . '` (' . implode(', ', $decl) . ')');

    if (!$rows) {
        return;
    }
    $names = $marks = [];
    foreach ($columns as $col) {
        $names[] = '`' . $col[0] . '`';
        $marks[] = '?';
    }
    $stmt = $db->prepare('INSERT INTO `' . $table . '` (' . implode(', ', $names) . ') VALUES ('
        . implode(', ', $marks) . ')');
    foreach ($rows as $row) {
        foreach ($columns as $i => $col) {
            list($name, $type) = $col;
            if (array_key_exists($name, $row)) {
                $value = $row[$name];
            } else {
                // The schema's own DEFAULT, roughly: integers 0, varchars NULL.
                $value = ('int' === slimstat_fp2_kind($type)) ? 0 : null;
            }
            if (null === $value) {
                $stmt->bindValue($i + 1, null, SQLITE3_NULL);
            } elseif ('int' === slimstat_fp2_kind($type)) {
                $stmt->bindValue($i + 1, (int) $value, SQLITE3_INTEGER);
            } else {
                $stmt->bindValue($i + 1, (string) $value, SQLITE3_TEXT);
            }
        }
        $stmt->execute();
        $stmt->reset();
    }
}
