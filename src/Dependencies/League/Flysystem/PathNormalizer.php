<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\League\Flysystem;

interface PathNormalizer
{
    public function normalizePath(string $path): string;
}
