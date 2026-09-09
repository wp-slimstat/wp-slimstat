<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SlimStat\Dependencies\Symfony\Contracts\Service\Test;

// Scoped SlimStat module: allow plugin/CLI autoload, deny direct web execution.
if (!defined('ABSPATH') && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit;
}


class_alias(ServiceLocatorTestCase::class, ServiceLocatorTest::class);
if (false) {
    /**
     * @deprecated since PHPUnit 9.6
     */
    class ServiceLocatorTest
    {
    }
}