<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\GuzzleHttp\Promise;

final class Is
{
    /**
     * Returns true if a promise is pending.
     */
    public static function pending(PromiseInterface $promise): bool
    {
        return PromiseInterface::PENDING === $promise->getState();
    }

    /**
     * Returns true if a promise is fulfilled or rejected.
     */
    public static function settled(PromiseInterface $promise): bool
    {
        return PromiseInterface::PENDING !== $promise->getState();
    }

    /**
     * Returns true if a promise is fulfilled.
     */
    public static function fulfilled(PromiseInterface $promise): bool
    {
        return PromiseInterface::FULFILLED === $promise->getState();
    }

    /**
     * Returns true if a promise is rejected.
     */
    public static function rejected(PromiseInterface $promise): bool
    {
        return PromiseInterface::REJECTED === $promise->getState();
    }
}
