<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use SlimStat\Dependencies\Symfony\Polyfill\Intl\SlimStat_Normalizer as p;

if (\PHP_VERSION_ID >= 80000) {
    return require __DIR__.'/bootstrap80.php';
}

if (!function_exists('normalizer_is_normalized')) {
    function normalizer_is_normalized($string, $form = p\SlimStat_Normalizer::FORM_C) { return p\SlimStat_Normalizer::isNormalized($string, $form); }
}
if (!function_exists('normalizer_normalize')) {
    function normalizer_normalize($string, $form = p\SlimStat_Normalizer::FORM_C) { return p\SlimStat_Normalizer::normalize($string, $form); }
}
