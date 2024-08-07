<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\BrowscapPHP\Exception;

use InvalidArgumentException as BaseInvalidArgumentException;

use function implode;
use function sprintf;

/**
 * Exception to handle errors if one argument is required
 */
final class InvalidArgumentException extends BaseInvalidArgumentException
{
    public static function oneOfCommandArguments(string ...$requiredArguments): self
    {
        return new self(
            sprintf('One of the command arguments "%s" is required', implode('", "', $requiredArguments))
        );
    }
}
