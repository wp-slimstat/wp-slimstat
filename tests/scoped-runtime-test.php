<?php

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

$root = isset($argv[1]) ? realpath($argv[1]) : dirname(__DIR__);
$fail = static function ($message) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$assert = static function ($condition, $message) use ($fail) {
    if (!$condition) {
        $fail($message);
    }
};
$target = $root . '/src/Dependencies';
$package = $target . '/veronalabs/browscap-php/src';
$autoload = $target . '/autoload.php';
$mapFile = $target . '/autoload-classmap.php';

$assert(is_dir($package), 'scoped package target is missing');
$assert(is_file($autoload), 'scoped autoloader is missing');
$assert(is_file($mapFile), 'scoped classmap is missing');
defined('ABSPATH') || define('ABSPATH', $root . '/');
$map = require $mapFile;
$assert(is_array($map) && $map, 'scoped classmap is empty');

$required = array(
    'SlimStat\\Dependencies\\BrowscapPHP\\Helper\\Quoter',
    'SlimStat\\Dependencies\\League\\Flysystem\\Filesystem',
    'SlimStat\\Dependencies\\Symfony\\Component\\String\\UnicodeString',
);
foreach ($required as $class) {
    $assert(isset($map[$class]), "{$class} is missing from the scoped classmap");
    $assert(is_file(dirname($mapFile) . '/' . $map[$class]), "{$class} maps to a missing file");
}

require $autoload;
foreach (array(
    'Symfony/Polyfill/Mbstring/bootstrap.php',
    'Symfony/Polyfill/Intl/Normalizer/bootstrap.php',
    'Symfony/Polyfill/Intl/Grapheme/bootstrap.php',
    'Symfony/Polyfill/Php73/bootstrap.php',
    'Symfony/Polyfill/Php80/bootstrap.php',
) as $bootstrap) {
    require_once $package . '/' . $bootstrap;
}

try {
    $quoter = new \SlimStat\Dependencies\BrowscapPHP\Helper\Quoter();
    $assert('Mozilla.*' === $quoter->pregQuote('Mozilla*'), 'Browscap helper behavior failed');
    if (PHP_VERSION_ID >= 80000) {
        $string = new \SlimStat\Dependencies\Symfony\Component\String\UnicodeString('hÉLLo world');
        $assert('Héllo World' === (string)$string->lower()->title(true), 'Symfony Unicode casing/normalization failed');
    }
    $assert(class_exists('Normalizer'), 'Normalizer global stub/native class did not resolve');
    $assert(class_exists('PhpToken'), 'PhpToken global stub/native class did not resolve');
    $assert(str_contains('slimstat', 'stat'), 'Php80 bootstrap function failed');
} catch (Throwable $error) {
    $fail('scoped runtime threw ' . get_class($error) . ': ' . $error->getMessage());
}

foreach (array(
    'Stringable' => 'interface',
    'Attribute' => 'class',
    'UnhandledMatchError' => 'class',
    'ValueError' => 'class',
    'PhpToken' => 'class',
    'JsonException' => 'class',
    'Normalizer' => 'class',
) as $stub => $kind) {
    $assert(isset($map[$stub]), "global {$kind} stub {$stub} is absent from the classmap");
    $assert(is_file(dirname($mapFile) . '/' . $map[$stub]), "global {$kind} stub {$stub} file is missing");
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || 'php' !== strtolower($file->getExtension())) {
        continue;
    }
    $source = (string)file_get_contents($file->getPathname());
    $assert(!preg_match('/(?<![A-Z0-9_])SLIMSTAT_DEPENDENCIES_(?:MB_CASE_[A-Z]+|GRAPHEME_EXTR_[A-Z]+|FILTER_VALIDATE_BOOL|SYMFONY_GRAPHEME_CLUSTER_RX)(?![A-Z0-9_])/', $source), $file->getPathname() . ' contains a prefixed native/polyfill constant');
}

$fixture = sys_get_temp_dir() . '/slimstat-constants-' . uniqid('', true);
$assert(mkdir($fixture), 'cannot create normalizer fixture');
$fixtureFile = $fixture . '/constants.php';
$fixtureSource = <<<'PHP'
<?php
define('SLIMSTAT_DEPENDENCIES_MB_CASE_TITLE', 2);
$native = SLIMSTAT_DEPENDENCIES_MB_CASE_TITLE;
$defined = defined('SLIMSTAT_DEPENDENCIES_FILTER_VALIDATE_BOOL');
$dynamic = constant('SLIMSTAT_DEPENDENCIES_GRAPHEME_EXTR_COUNT');
define('SLIMSTAT_DEPENDENCIES_LIBRARY_FLAG', 1);
$longer = SLIMSTAT_DEPENDENCIES_MB_CASE_TITLE_SUFFIX;
PHP;
$assert(false !== file_put_contents($fixtureFile, $fixtureSource), 'cannot write normalizer fixture');
require_once dirname(__DIR__) . '/build/scoper-ext-constants.php';
$assert(1 === slimstat_normalize_scoped_constants($fixture), 'first normalization must change the fixture');
$first = file_get_contents($fixtureFile);
$assert(0 === slimstat_normalize_scoped_constants($fixture), 'second normalization must be idempotent');
$assert($first === file_get_contents($fixtureFile), 'second normalization changed bytes');
$assert(false !== strpos($first, "define('MB_CASE_TITLE'"), 'define string was not normalized');
$assert(false !== strpos($first, '$native = MB_CASE_TITLE;'), 'bare native constant was not normalized');
$assert(false !== strpos($first, "defined('FILTER_VALIDATE_BOOL')"), 'defined string was not normalized');
$assert(false !== strpos($first, "constant('GRAPHEME_EXTR_COUNT')"), 'constant string was not normalized');
$assert(false !== strpos($first, 'SLIMSTAT_DEPENDENCIES_LIBRARY_FLAG'), 'ordinary library constant lost its prefix');
$assert(false !== strpos($first, 'SLIMSTAT_DEPENDENCIES_MB_CASE_TITLE_SUFFIX'), 'longer identifier was altered');
unlink($fixtureFile);
rmdir($fixture);

$negative = sys_get_temp_dir() . '/slimstat-classmap-' . uniqid('', true);
$assert(mkdir($negative), 'cannot create classmap fixture');
$badMap = $map;
unset($badMap[$required[0]]);
$badMapFile = $negative . '/autoload-classmap.php';
$assert(false !== file_put_contents($badMapFile, '<?php return ' . var_export($badMap, true) . ';'), 'cannot write classmap fixture');
$loadedBadMap = require $badMapFile;
$assert(!isset($loadedBadMap[$required[0]]), 'negative classmap fixture retained the removed entry');
unlink($badMapFile);
rmdir($negative);

echo "SLIMSTAT-SCOPED-RUNTIME-COMPLETE\n";
