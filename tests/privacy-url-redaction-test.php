<?php
require dirname(__DIR__) . '/src/Tracker/Storage.php';
function apply_filters($hook, $value) { return $value; }
$cases = [
    '//user:sec@ret@example.test/path' => '//example.test/path',
    'ftp://user:secret@example.test/file' => 'ftp://example.test/file',
    '/thanks?email=jane%40example.test&token=secret&utm_source=email&page=2' => '/thanks?utm_source=email&page=2',
    '/wp-login.php?action=rp&key=secret&login=person' => '/wp-login.php?action=rp',
    '/checkout/order-received/2/?key=wc_order_secret' => '/checkout/order-received/2/',
    'https://user:secret@example.test/path?billing%5Bemail%5D=a&access_token=b&view=grid' => 'https://example.test/path?view=grid',
    '/?EMAIL=a&%2574oken=b&password=c&foo=bar' => '/?foo=bar',
    '/?utm_campaign=spring&utm_content=button&order=asc#section' => '/?utm_campaign=spring&utm_content=button&order=asc#section',
    '/#access_token=secret&view=grid' => '/#view=grid',
];
foreach ($cases as $input => $expected) {
    $actual = \SlimStat\Tracker\Storage::redactSensitiveUrl($input);
    if ($expected !== $actual) { throw new RuntimeException("URL redaction failed: {$input} => {$actual}"); }
}
echo 'PASS: sensitive query parameters, encoded/nested keys and URL credentials are removed; campaign and ordinary filters survive' . PHP_EOL;
