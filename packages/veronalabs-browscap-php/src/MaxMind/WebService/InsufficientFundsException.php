<?php

declare(strict_types=1);

namespace MaxMind\WebService;

/**
 * Thrown when the account is out of credits.
 */
class InsufficientFundsException extends InvalidRequestException
{
}
