<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use RuntimeException;

final class WpAjaxDie extends RuntimeException
{
    public $payload;

    public function __construct(string $outcome, $payload = null)
    {
        parent::__construct($outcome);
        $this->payload = $payload;
    }

    public function outcome(): string
    {
        return $this->getMessage();
    }
}
