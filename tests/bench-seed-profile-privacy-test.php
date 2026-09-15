<?php
/**
 * Privacy guard for the committed benchmark seed profile.
 *
 * tests/bench/seed-profile.json is generated from a real production dump and
 * committed to a public repository. The extractor scrubs it, but the extractor
 * can be changed and the profile can be regenerated from a different dump — so
 * the property is asserted here rather than trusted.
 *
 * Contains no personal data:
 *   - no IP addresses (v4 or v6)
 *   - no personal email addresses (published crawler contact addresses in bot
 *     User-Agent strings are allowed — they identify a robot, not a person)
 *   - no columns that hold identifiers: ip, username, email, fingerprint,
 *     city, searchterms, notes
 *   - referers reduced to scheme://host, so no search terms or tracking tokens
 *   - resource query values and numeric path segments reduced to placeholders
 *
 * @see tests/bench/lib/extract-seed-profile.py
 */

declare(strict_types=1);

$plugin_root  = dirname(__DIR__);
$profile_path = $plugin_root . '/tests/bench/seed-profile.json';
$failures     = [];

if (!file_exists($profile_path)) {
    // Not fatal: the profile is only needed to run benchmarks, and a checkout
    // without it is valid. Nothing to assert.
    echo "SKIP: tests/bench/seed-profile.json not present\n";
    exit(0);
}

$raw     = (string) file_get_contents($profile_path);
$profile = json_decode($raw, true);

if (!is_array($profile)) {
    fwrite(STDERR, "FAIL: seed-profile.json is not valid JSON\n");
    exit(1);
}

// ── Columns that must never appear ──────────────────────────────────────────
$forbidden = ['ip', 'other_ip', 'username', 'email', 'fingerprint', 'city', 'searchterms', 'notes'];
foreach ($forbidden as $col) {
    if (isset($profile['weighted'][$col])) {
        $failures[] = "weighted.{$col} is present — that column holds identifiers and must not be sampled";
    }
}

// ── No IP literals anywhere in the file ─────────────────────────────────────
if (preg_match('/"(?:\d{1,3}\.){3}\d{1,3}"/', $raw, $m)) {
    $failures[] = "contains an IPv4 literal as a value: {$m[0]}";
}
if (preg_match('/"[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){7}"/i', $raw, $m)) {
    $failures[] = "contains an IPv6 literal as a value: {$m[0]}";
}

// ── Emails: only published crawler contacts are acceptable ──────────────────
// Bot UA strings legitimately advertise a contact address (Bytespider,
// ClaudeBot, AhrefsBot…). Those identify an operator, not a visitor. Anything
// outside a bot UA is a leak.
$bot_markers = '/bot|crawl|spider|slurp|bing|yandex|baidu|scrap|preview|fetch/i';
foreach (($profile['weighted'] ?? []) as $column => $items) {
    foreach ($items as $pair) {
        $value = (string) ($pair[0] ?? '');
        if (!preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $value, $em)) {
            continue;
        }
        foreach ($em[0] as $address) {
            if ($column === 'user_agent' && preg_match($bot_markers, $value)) {
                continue; // published crawler contact
            }
            $failures[] = "weighted.{$column} contains an email address ({$address}) "
                . 'outside a crawler User-Agent';
        }
    }
}

// ── Referers must be host-only ──────────────────────────────────────────────
foreach (($profile['weighted']['referer'] ?? []) as $pair) {
    $value = (string) ($pair[0] ?? '');
    if ($value === '') {
        continue;
    }
    if (strpos($value, '?') !== false || strpos($value, '#') !== false) {
        $failures[] = "referer retains a query string or fragment: {$value}";
        continue;
    }
    // scheme://host/ — at most one path segment, and it must be empty.
    if (preg_match('#^https?://[^/]+/.+#', $value)) {
        $failures[] = "referer retains a path: {$value}";
    }
}

// ── Resource query values and numeric ids must be placeholders ──────────────
foreach (($profile['weighted']['resource'] ?? []) as $pair) {
    $value = (string) ($pair[0] ?? '');
    if (strpos($value, '?') !== false) {
        [, $query] = explode('?', $value, 2);
        foreach (explode('&', $query) as $param) {
            if ($param !== '' && substr($param, -2) !== '=x') {
                $failures[] = "resource query value not scrubbed: {$value}";
                break;
            }
        }
    }
    [$path] = explode('?', $value, 2);
    if (preg_match('#/(\d{2,})(?:/|$)#', $path, $pm)) {
        $failures[] = "resource retains a numeric id segment ({$pm[1]}): {$path}";
    }
}

// ── The profile must actually describe something ────────────────────────────
foreach (['source_rows', 'weighted', 'distinct', 'pageviews_per_visit'] as $key) {
    if (empty($profile[$key])) {
        $failures[] = "missing or empty key: {$key} — the profile cannot drive a seeder";
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: seed-profile privacy (' . count($failures) . " problem(s))\n");
    foreach (array_unique($failures) as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf(
    "PASS: seed-profile privacy (%d columns, %s source rows, no personal data)\n",
    count($profile['weighted']),
    number_format((int) $profile['source_rows'])
);
exit(0);
