<?php

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

function slimstat_normalize_scoped_constants($target)
{
    if (!is_dir($target)) {
        throw new RuntimeException("Scoped target does not exist: {$target}");
    }

    // ponytail: maintained locked-payload list; remove when WP Scoper excludes native/polyfill constants upstream.
    $constants = array(
        'MB_CASE_UPPER',
        'MB_CASE_LOWER',
        'MB_CASE_TITLE',
        'GRAPHEME_EXTR_COUNT',
        'GRAPHEME_EXTR_MAXBYTES',
        'GRAPHEME_EXTR_MAXCHARS',
        'FILTER_VALIDATE_BOOL',
        'SYMFONY_GRAPHEME_CLUSTER_RX',
    );
    $prefix = 'SLIMSTAT_DEPENDENCIES_';
    $changed = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (!$file->isFile() || 'php' !== strtolower($file->getExtension())) {
            continue;
        }

        $path = $file->getPathname();
        $source = file_get_contents($path);
        if (false === $source) {
            throw new RuntimeException("Cannot read {$path}");
        }
        $original = $source;

        foreach ($constants as $constant) {
            $source = preg_replace(
                '/(?<![A-Z0-9_])' . preg_quote($prefix . $constant, '/') . '(?![A-Z0-9_])/',
                $constant,
                $source
            );
            if (null === $source) {
                throw new RuntimeException("Cannot normalize {$path}");
            }
        }

        if ($source !== $original) {
            if (false === file_put_contents($path, $source, LOCK_EX)) {
                throw new RuntimeException("Cannot write {$path}");
            }
            $changed++;
        }
    }

    return $changed;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $target = isset($argv[1]) ? $argv[1] : dirname(__DIR__) . '/src/Dependencies';
        $changed = slimstat_normalize_scoped_constants($target);
        echo "SCOPER-EXT-CONSTANTS-COMPLETE changed={$changed}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
