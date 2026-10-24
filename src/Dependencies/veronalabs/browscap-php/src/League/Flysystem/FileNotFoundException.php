<?php

namespace SlimStat\Dependencies\League\Flysystem;

use RuntimeException;

/**
 * Flysystem 1.x compatibility stub.
 * This exception is thrown when a file is not found.
 */
class FileNotFoundException extends RuntimeException implements FilesystemException
{
}
