<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\GuzzleHttp\Psr7;

use SlimStat\Dependencies\Psr\Http\Message\UriInterface;

/**
 * Provides methods to determine if a modified URL should be considered cross-origin.
 *
 * @author Graham Campbell
 */
final class UriComparator
{
    /**
     * Determines if a modified URL should be considered cross-origin with
     * respect to an original URL.
     */
    public static function isCrossOrigin(UriInterface $original, UriInterface $modified): bool
    {
        if (0 !== \strcasecmp($original->getHost(), $modified->getHost())) {
            return true;
        }

        if ($original->getScheme() !== $modified->getScheme()) {
            return true;
        }
        return self::computePort($original) !== self::computePort($modified);
    }

    private static function computePort(UriInterface $uri): int
    {
        $port = $uri->getPort();

        if (null !== $port) {
            return $port;
        }

        return 'https' === $uri->getScheme() ? 443 : 80;
    }

    private function __construct()
    {
        // cannot be instantiated
    }
}
