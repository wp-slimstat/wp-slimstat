<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\League\Flysystem;

final class PortableVisibilityGuard
{
    public static function guardAgainstInvalidInput(string $visibility): void
    {
        if (Visibility::PUBLIC !== $visibility && Visibility::PRIVATE !== $visibility) {
            $className = Visibility::class;
            throw InvalidVisibilityProvided::withVisibility(
                $visibility,
                sprintf('either %s::PUBLIC or %s::PRIVATE', $className, $className)
            );
        }
    }
}
