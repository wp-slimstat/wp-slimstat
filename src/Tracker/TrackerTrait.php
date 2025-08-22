<?php

namespace SlimStat\Tracker;

/**
 * Composition trait: keeps a single, small entry point and composes specialized
 * tracker traits. This file intentionally stays minimal so other classes can
 * continue importing / using `TrackerTrait` unchanged.
 */
trait TrackerTrait
{
    use TrackerAjaxTrait;
    use TrackerTrackTrait;
    use TrackerDBTrait;
    use TrackerHelpersTrait;
}
