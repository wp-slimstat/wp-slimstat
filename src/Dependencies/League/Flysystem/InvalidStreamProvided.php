<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\League\Flysystem;

use InvalidArgumentException as BaseInvalidArgumentException;

class InvalidStreamProvided extends BaseInvalidArgumentException implements FilesystemException
{
}
