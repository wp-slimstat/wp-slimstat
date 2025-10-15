<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\MaxMind\WebService;

/**
 * Thrown when the account is out of credits.
 */
class InsufficientFundsException extends InvalidRequestException
{
}
