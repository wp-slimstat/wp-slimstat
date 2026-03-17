<?php
/**
 * MU-Plugin: Calendar Extension Simulator
 *
 * Defines jddayofweek() in the SlimStat\Helpers namespace to throw a RuntimeException,
 * simulating an environment where PHP's ext-calendar extension is not loaded.
 *
 * How PHP namespace resolution works here:
 *   When DataBuckets::initSeqWeek() calls jddayofweek() (without a leading backslash),
 *   PHP looks for SlimStat\Helpers\jddayofweek() first, before falling back to the
 *   global ext-calendar function. Our stub intercepts that lookup and throws — identical
 *   to what a server with no ext-calendar would do.
 *
 * After the v5.4.3 fix, initSeqWeek() uses self::DAY_NAMES[] instead of jddayofweek(),
 * so this stub is never called and the chart loads cleanly.
 *
 * Used exclusively by E2E tests for the calendar-extension-fallback spec.
 * Remove this file (or uninstall via setup.ts) to disable.
 */

namespace SlimStat\Helpers;

function jddayofweek(int $julianday = 0, int $mode = 0): mixed
{
    throw new \RuntimeException(
        'ext-calendar absent: jddayofweek() was called from SlimStat\\Helpers namespace. ' .
        'DataBuckets must use the DAY_NAMES constant instead (v5.4.3 regression).'
    );
}
