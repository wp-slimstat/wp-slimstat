<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (\PHP_VERSION_ID < 80000 && extension_loaded('tokenizer')) {
    class SlimStat_SlimStat_PhpToken extends SlimStat\Dependencies\Symfony\Polyfill\Php80\SlimStat_SlimStat_PhpToken
    {
    }
}
