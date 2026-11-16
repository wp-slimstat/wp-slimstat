<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\MaxMind\WebService;

/**
 * This exception is thrown when the service requires permission to access.
 */
class PermissionRequiredException extends InvalidRequestException
{
}
