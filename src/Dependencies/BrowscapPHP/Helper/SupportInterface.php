<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\BrowscapPHP\Helper;

/**
 * class to help getting the user agent
 */
interface SupportInterface
{
    /**
     * detect the useragent
     *
     * @throws void
     *
     * @no-named-arguments
     */
    public function getUserAgent(): string;
}
