<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\BrowscapPHP\Parser\Helper;

use function md5;
use function preg_match;
use function str_replace;
use function strlen;
use function substr;

/**
 * includes general functions for the work with patterns
 */
final class Pattern
{
    /**
     * @throws void
     */
    private function __construct()
    {
    }

    /**
     * Gets a hash or an array of hashes from the first characters of a pattern/user agent, that can
     * be used for a fast comparison, by comparing only the hashes, without having to match the
     * complete pattern against the user agent.
     *
     * With the variants options, all variants from the maximum number of pattern characters to one
     * character will be returned. This is required in some cases, the a placeholder is used very
     * early in the pattern.
     *
     * Example:
     *
     * Pattern: "Mozilla/* (Nintendo 3DS; *) Version/*"
     * User agent: "Mozilla/5.0 (Nintendo 3DS; U; ; en) Version/1.7567.US"
     *
     * In this case the has for the pattern is created for "Mozilla/" while the pattern
     * for the hash for user agent is created for "Mozilla/5.0". The variants option
     * results in an array with hashes for "Mozilla/5.0", "Mozilla/5.", "Mozilla/5",
     * "Mozilla/" ... "M", so that the pattern hash is included.
     *
     * @return string[]
     *
     * @throws void
     */
    public static function getHashForPattern(string $pattern, bool $variants = false): array
    {
        $regex   = '/^([^\.\*\?\s\r\n\\\\]+).*$/';
        $pattern = substr($pattern, 0, 32);
        $matches = [];

        if (! preg_match($regex, $pattern, $matches)) {
            return [md5('')];
        }

        if (! isset($matches[1])) {
            return [md5('')];
        }

        $string = $matches[1];

        if ($variants === true) {
            $patternStarts = [];

            for ($i = strlen($string); 1 <= $i; --$i) {
                $string          = substr($string, 0, $i);
                $patternStarts[] = md5($string);
            }

            // Add empty pattern start to include patterns that start with "*",
            // e.g. "*FAST Enterprise Crawler*"
            $patternStarts[] = md5('');

            return $patternStarts;
        }

        return [md5($string)];
    }

    /**
     * returns a hash for one pattern
     *
     * @throws void
     */
    public static function getHashForParts(string $pattern): string
    {
        return md5($pattern);
    }

    /**
     * Gets the minimum length of the patern (used in the getPatterns() method to
     * check against the user agent length)
     *
     * @throws void
     */
    public static function getPatternLength(string $pattern): int
    {
        return strlen(str_replace('*', '', $pattern));
    }
}
