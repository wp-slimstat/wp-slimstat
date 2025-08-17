<?php

namespace SlimStat\Dependencies\Psr\Http\Message;

interface UriFactoryInterface
{
    /**
     * Create a new URI.
     *
     *
     *
     * @throws \InvalidArgumentException If the given URI cannot be parsed.
     */
    public function createUri(string $uri = ''): UriInterface;
}
