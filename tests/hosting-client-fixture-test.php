<?php
/** Exercise the disposable client fixture guards in separate interpreters. */
$fixture = __DIR__ . '/hosting/client-fixture.php';
$cases = [
    [true, true, 'tests-pilot', 'db', 'run', '198.18.1.2', '198.18.1.2'],
    [false, true, 'tests-pilot', 'db', 'run', '198.18.1.2', '127.0.0.1'],
    [true, false, 'tests-pilot', 'db', 'run', '198.18.1.2', '127.0.0.1'],
    [true, true, 'local', 'db', 'run', '198.18.1.2', '127.0.0.1'],
    [true, true, 'tests-pilot', 'localhost', 'run', '198.18.1.2', '127.0.0.1'],
    [true, true, 'tests-pilot', 'db', '', '198.18.1.2', '127.0.0.1'],
    [true, true, 'tests-pilot', 'db', 'run', '8.8.8.8', '127.0.0.1'],
    [true, true, 'tests-pilot', 'db', 'run', '198.18.999.1', '127.0.0.1'],
    [true, true, 'tests-pilot', 'db', 'run', ['198.18.1.2'], '127.0.0.1'],
];
foreach ($cases as $case) {
    [$bench, $armed, $database, $host, $label, $ip, $expected] = $case;
    $constants = ['ABSPATH' => '/', 'SLIMSTAT_BENCH' => $bench, 'SLIMSTAT_HOSTING_DISPOSABLE' => $armed, 'DB_NAME' => $database, 'DB_HOST' => $host];
    $code = '';
    foreach ($constants as $name => $value) {
        $code .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');';
    }
    $code .= '$_SERVER=' . var_export(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_SLIMSTAT_BENCH' => $label, 'HTTP_X_SLIMSTAT_BENCH_IP' => $ip], true) . ';';
    $code .= 'require ' . var_export($fixture, true) . ';echo $_SERVER["REMOTE_ADDR"];';
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $output, $status);
    if ($status !== 0 || implode('', $output) !== $expected) {
        fwrite(STDERR, "FAIL: client fixture guard\n");
        exit(1);
    }
    $output = [];
}
echo "PASS: nine disposable client fixture guards\n";
