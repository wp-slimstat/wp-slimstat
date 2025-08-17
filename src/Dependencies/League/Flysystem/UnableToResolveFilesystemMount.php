<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\League\Flysystem;

use RuntimeException;

class UnableToResolveFilesystemMount extends RuntimeException implements FilesystemException
{
    public static function becauseTheSeparatorIsMissing(string $path): UnableToResolveFilesystemMount
    {
        return new UnableToResolveFilesystemMount(sprintf('Unable to resolve the filesystem mount because the path (%s) is missing a separator (://).', $path));
    }

    public static function becauseTheMountWasNotRegistered(string $mountIdentifier): UnableToResolveFilesystemMount
    {
        return new UnableToResolveFilesystemMount(sprintf('Unable to resolve the filesystem mount because the mount (%s) was not registered.', $mountIdentifier));
    }
}
