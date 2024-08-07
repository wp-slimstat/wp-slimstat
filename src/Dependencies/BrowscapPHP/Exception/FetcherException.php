<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\BrowscapPHP\Exception;

use function sprintf;

/**
 * Exception to handle errors while fetching a remote file
 */
final class FetcherException extends DomainException
{
    /**
     * @return FetcherException
     */
    public static function httpError(string $resource, string $error): self
    {
        return new self(
            sprintf('Could not fetch HTTP resource "%s": %s', $resource, $error)
        );
    }
}
