<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\League\MimeTypeDetection;

interface ExtensionToMimeTypeMap
{
    public function lookupMimeType(string $extension): ?string;
}
