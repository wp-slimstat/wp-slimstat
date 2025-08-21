<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

// Project-specific Rector configuration for the Salon Pro theme.
// Adjust PhpVersion::PHP_74 to match your actual PHP target if needed.

return static function (RectorConfig $rectorConfig): void {
    // Paths Rector should analyze and refactor
    $rectorConfig->paths([
        __DIR__ . '/admin',
        __DIR__ . '/src',
        __DIR__ . '/views',
        __DIR__ . '/uninstall.php',
        __DIR__ . '/wp-slimstat.php',
        __DIR__ . '/index.php',
    ]);

    // // Ensure Rector can autoload project classes and stubs
    // $rectorConfig->autoloadPaths([
    //     __DIR__ . '/vendor/autoload.php',
    // ]);

    // Recommended sets - modernize to PHP 7.4 and apply quality/cleanup rules
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_74,

        // Safe sets only
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::CODING_STYLE,
    ]);

    // Auto-import class names where possible
    $rectorConfig->importNames(true);
    $rectorConfig->importShortClasses(false);

    // Skip files and folders that should not be modified
    $rectorConfig->skip([
        __DIR__ . '/vendor',
        __DIR__ . '/node_modules',
        __DIR__ . '/phpstan-cache',
        __DIR__ . '/languages',
        __DIR__ . '/rector.php',
        __DIR__ . '/src/Dependencies',
        __DIR__ . '/src/symfony',
    ]);
};
