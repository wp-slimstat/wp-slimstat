<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/lib/source-scan.php';

function slimstat_dependency_inventory($directory, $root)
{
    $declared = array();
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $files[$relative] = hash_file('sha256', $file->getPathname());
        if ('php' !== strtolower($file->getExtension())) {
            continue;
        }

        $tokens = token_get_all((string)file_get_contents($file->getPathname()));
        $namespace = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if (T_NAMESPACE === $token[0]) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $part = $tokens[$j];
                    if (';' === $part || '{' === $part) {
                        break;
                    }
                    if (is_array($part) && !in_array($part[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                        $namespace .= $part[1];
                    }
                }
                continue;
            }
            if (!in_array(strtolower($token[1]), array('class', 'interface', 'trait', 'enum'), true)) {
                continue;
            }

            $previous = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                    $previous = $tokens[$j];
                    break;
                }
            }
            if (is_array($previous) && T_DOUBLE_COLON === $previous[0]) {
                continue;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $part = $tokens[$j];
                if (is_array($part) && in_array($part[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                    continue;
                }
                if (is_array($part) && T_STRING === $part[0]) {
                    $name = $part[1];
                }
                break;
            }
            if (null !== $name) {
                $declared[$namespace ? $namespace . '\\' . $name : $name] = $relative;
            }
        }
    }

    ksort($declared);
    ksort($files);
    return array($declared, $files);
}

$root = dirname(__DIR__);
$fixture = __DIR__ . '/fixtures/dependencies-fqcn-3055864f.txt';
$packageRoot = $root . '/packages/veronalabs-browscap-php/src';
list($declared, $files) = slimstat_dependency_inventory($root . '/src/Dependencies', $root);
list($packageDeclared, $packageFiles) = slimstat_dependency_inventory($packageRoot, $packageRoot);

if (in_array('--write-fixture', $argv, true)) {
    $namespaced = array();
    $global = array();
    foreach (array_keys($declared) as $name) {
        if (false === strpos($name, '\\')) {
            $global[] = $name;
        } else {
            $namespaced[] = $name;
        }
    }
    $lines = array('[namespaced]');
    $lines = array_merge($lines, $namespaced, array('[global]'), $global, array('[files]'));
    foreach ($files as $path => $hash) {
        $lines[] = $hash . '  ' . $path;
    }
    if (false === file_put_contents($fixture, implode("\n", $lines) . "\n")) {
        fwrite(STDERR, "FAIL: cannot write {$fixture}\n");
        exit(1);
    }
    echo 'WROTE: ' . count($declared) . ' declarations and ' . count($files) . " file hashes\n";
    exit(0);
}

$lines = is_file($fixture) ? file($fixture, FILE_IGNORE_NEW_LINES) : false;
if (false === $lines) {
    fwrite(STDERR, "FAIL: baseline declaration fixture is missing\n");
    exit(1);
}
$expected = array();
$expectedFiles = array();
$section = '';
foreach ($lines as $line) {
    if (preg_match('/^\[(.+)\]$/', $line, $match)) {
        $section = $match[1];
    } elseif (('namespaced' === $section || 'global' === $section) && '' !== $line) {
        $expected[$line] = true;
    } elseif ('files' === $section && preg_match('/^([a-f0-9]{64})  src\/Dependencies\/(.+)$/', $line, $match)) {
        $expectedFiles[$match[2]] = $match[1];
    }
}

$failures = array();
$sourceNames = array();
foreach ($packageDeclared as $name => $path) {
    $sourceNames[false === strpos($name, '\\') || 0 === strpos($name, 'MaxMind\\Exception\\')
        ? $name
        : 'SlimStat\\Dependencies\\' . $name] = $path;
}
foreach (array_diff_key($expected, $sourceNames) as $name => $_) {
    $failures[] = "snapshot lost baseline declaration {$name}";
}
foreach (array_diff_key($sourceNames, $expected) as $name => $_) {
    $failures[] = "snapshot added declaration {$name}";
}
foreach (array_diff_key($expectedFiles, $packageFiles) as $path => $_) {
    $failures[] = "snapshot lost baseline file {$path}";
}
foreach (array_diff_key($packageFiles, $expectedFiles) as $path => $_) {
    $failures[] = "snapshot added file {$path}";
}
foreach ($expectedFiles as $path => $hash) {
    if (isset($packageFiles[$path]) && 'php' !== strtolower(pathinfo($path, PATHINFO_EXTENSION)) && $hash !== $packageFiles[$path]) {
        $failures[] = "snapshot changed non-PHP resource {$path}";
    }
}

// WP Scoper 1.4.2 deliberately excludes dependency test directories. These five
// dev-only declarations are pinned here so a runtime class cannot join them silently.
$excludedTests = array_fill_keys(array(
    'SlimStat\\Dependencies\\Psr\\Log\\Test\\DummyTest',
    'SlimStat\\Dependencies\\Psr\\Log\\Test\\LoggerInterfaceTest',
    'SlimStat\\Dependencies\\Psr\\Log\\Test\\TestLogger',
    'SlimStat\\Dependencies\\Symfony\\Contracts\\Service\\Test\\ServiceLocatorTest',
    'SlimStat\\Dependencies\\Symfony\\Contracts\\Service\\Test\\ServiceLocatorTestCase',
), true);
$runtimeExpected = array_diff_key($expected, $excludedTests);
$actual = array_fill_keys(array_keys($declared), true);
foreach (array_diff_key($runtimeExpected, $actual) as $name => $_) {
    $failures[] = "missing declaration {$name}";
}
foreach (array_diff_key($actual, $runtimeExpected) as $name => $_) {
    $failures[] = "unexpected declaration {$name}";
}
foreach (array_keys($actual) as $name) {
    if (false !== strpos($name, 'SlimStat\\Dependencies\\SlimStat\\Dependencies\\')) {
        $failures[] = "doubled dependency prefix {$name}";
    }
}

$mapFile = $root . '/src/Dependencies/autoload-classmap.php';
defined('ABSPATH') || define('ABSPATH', $root . '/');
$map = is_file($mapFile) ? require $mapFile : array();
if (!is_array($map) || !$map) {
    $failures[] = 'scoped classmap is missing or empty';
} else {
    foreach ($map as $class => $path) {
        if (!is_file(dirname($mapFile) . '/' . $path)) {
            $failures[] = "classmap path missing for {$class}";
        }
    }
    foreach ($runtimeExpected as $name => $_) {
        if (false !== strpos($name, '\\') && !isset($map[$name])) {
            $failures[] = "namespaced dependency absent from classmap: {$name}";
        }
    }
}

$globals = array_filter(array_keys($runtimeExpected), static function ($name) { return false === strpos($name, '\\'); });
if (7 !== count($globals)) {
    $failures[] = 'baseline must contain exactly seven global polyfill declarations, found ' . count($globals);
}

foreach ($map as $stubName => $stubPath) {
    if (false !== strpos($stubName, '\\')) {
        continue;
    }
    $stub = dirname($mapFile) . '/' . $stubPath;
    $source = slimstat_strip_comments_and_strings((string)file_get_contents($stub));
    if (preg_match('/\b(?:extends|implements)\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $source, $match)
        && false !== strpos($match[1], '\\') && !isset($map[ltrim($match[1], '\\')])) {
        $failures[] = basename($stub) . ' has an unresolved namespaced parent ' . $match[1];
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: scoped dependency parity\n  - " . implode("\n  - ", array_slice($failures, 0, 30)) . "\n");
    exit(1);
}

echo 'SLIMSTAT-SCOPED-PARITY-COMPLETE declarations=' . count($runtimeExpected) . ' snapshot-files=' . count($packageFiles) . ' generated-files=' . count($files) . "\n";
