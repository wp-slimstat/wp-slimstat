<?php
/** Run with wp eval-file in a disposable site with the candidate plugin active. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
if (!class_exists('WP_Hook') || !class_exists('wp_slimstat')) { fwrite(STDERR, "Load WordPress and the candidate plugin with wp eval-file.\n"); exit(2); }

$calls = [];
$object = new class($calls) {
    private $calls;
    public function __construct(&$calls) { $this->calls = &$calls; }
    public function __invoke($date, $format) { $this->calls[] = ['object', $format]; return $date; }
    public function method($date) { $this->calls[] = ['method']; return $date; }
};
$closure = static function ($date, $format, $timestamp, $gmt) use (&$calls) {
    $calls[] = ['closure', $format, $timestamp, $gmt];
    return $date;
};

remove_all_filters('date_i18n');
add_filter('date_i18n', $closure, 3, 4);
add_filter('date_i18n', $object, 19, 2);
add_filter('date_i18n', [$object, 'method'], 31, 1);
$expected = $GLOBALS['wp_filter']['date_i18n']->callbacks;

$value = wp_slimstat::date_i18n('Y', 1767225600);
if ('2026' !== $value || [] !== $calls || $expected !== $GLOBALS['wp_filter']['date_i18n']->callbacks) {
    throw new RuntimeException('date_i18n wrapper did not suppress and exactly restore mixed callbacks');
}

wp_slimstat::toggle_date_i18n_filters(false);
wp_slimstat::toggle_date_i18n_filters(false);
wp_slimstat::toggle_date_i18n_filters(true);
if (has_filter('date_i18n')) {
    throw new RuntimeException('nested suspension restored callbacks before its outer scope ended');
}
wp_slimstat::toggle_date_i18n_filters(true);
if ($expected !== $GLOBALS['wp_filter']['date_i18n']->callbacks) {
    throw new RuntimeException('nested suspension did not restore the exact callback registry');
}

$thrown = new RuntimeException('qualification exception');
$throwing = static function () use ($thrown) { throw $thrown; };
add_filter('pre_option_gmt_offset', $throwing);
try {
    wp_slimstat::date_i18n('U');
    throw new RuntimeException('exception control did not throw');
} catch (RuntimeException $caught) {
    if ($caught !== $thrown) { throw $caught; }
} finally {
    remove_filter('pre_option_gmt_offset', $throwing);
}
if ($expected !== $GLOBALS['wp_filter']['date_i18n']->callbacks) {
    throw new RuntimeException('exception path did not restore the exact callback registry');
}

echo "PASS: real WP_Hook callbacks are suppressed and exactly restored across normal, nested, and exception paths\n";
