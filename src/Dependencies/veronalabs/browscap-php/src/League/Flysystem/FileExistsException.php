<?php

namespace SlimStat\Dependencies\League\Flysystem;

use RuntimeException;

/**
 * Flysystem 1.x compatibility stub.
 * This exception is thrown when attempting to write a file that already exists.
 */
class FileExistsException extends RuntimeException implements FilesystemException
{
}
