<?php
// src/Interfaces/RestControllerInterface.php

declare(strict_types=1);

namespace SlimStat\Interfaces;

interface RestControllerInterface
{
    /**
     * Registers the REST API routes.
     */
    public function register_routes(): void;
}
