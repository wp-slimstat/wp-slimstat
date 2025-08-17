<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\League\Flysystem;

use InvalidArgumentException;

use function var_export;

class InvalidVisibilityProvided extends InvalidArgumentException implements FilesystemException
{
    public static function withVisibility(string $visibility, string $expectedMessage): InvalidVisibilityProvided
    {
        $provided = var_export($visibility, true);
        $message  = sprintf('Invalid visibility provided. Expected %s, received %s', $expectedMessage, $provided);

        throw new InvalidVisibilityProvided($message);
    }
}
