<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\League\Flysystem;

use RuntimeException;

final class UnreadableFileEncountered extends RuntimeException implements FilesystemException
{
    /**
     * @var string
     */
    private $location;

    public function location(): string
    {
        return $this->location;
    }

    public static function atLocation(string $location): UnreadableFileEncountered
    {
        $e           = new self(sprintf('Unreadable file encountered at location %s.', $location));
        $e->location = $location;

        return $e;
    }
}
