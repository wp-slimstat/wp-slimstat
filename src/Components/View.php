<?php

namespace SlimStat\Components;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}


class View
{
    /**
     * Allowed variable names for extraction.
     * Only these keys will be extracted from $args to prevent variable injection.
     *
     * @var array
     */
    private static $allowed_keys = [
        'data',
        'prevData',
        'chartLabels',
        'translations',
        'args',
        'totals',
        'is_pro',
        'report_id',
        'settings',
        'options',
        'items',
        'title',
        'description',
        'content',
        'filters',
        'columns',
        'rows',
        'pagination',
        'chart_args',
        'chart_data',
        'chart_type',
        'granularity',
        'visitors',
        'pageviews',
        'events',
        'countries',
        'cities',
        'browsers',
        'platforms',
        'screen_sizes',
        'languages',
        'referrers',
        'search_terms',
        'resources',
        'outbound',
        'downloads',
        'notices',
        'message',
        'type',
        'class',
    ];

    /**
     * Load a view file and pass data to it.
     *
     * @param string|array $view    The view path inside views directory
     * @param array        $args    An associative array of data to pass to the view.
     * @param bool         $return  Return the template if requested
     * @param string       $baseDir The base directory to load the view, defaults to SLIMSTAT_DIR
     *
     * @throws Exception if the view file cannot be found.
     */
    public static function load($view, $args = [], $return = false, $baseDir = null)
    {
        // Default to SLIMSTAT_DIR
        $baseDir = empty($baseDir) ? SLIMSTAT_DIR : $baseDir;

        try {
            $viewList = is_array($view) ? $view : [$view];

            foreach ($viewList as $view) {
                $viewPath = sprintf('%s/views/%s.php', $baseDir, $view);

                if (!file_exists($viewPath)) {
                    throw new \Exception(esc_html__('View file not found: ' . $viewPath, 'wp-slimstat'));
                }

                // Make $view_args available to templates (safer than extract)
                $view_args = $args;

                // For backward compatibility, extract only allowed keys
                // This prevents variable injection attacks while maintaining existing functionality
                if (!empty($args) && is_array($args)) {
                    $safe_args = array_intersect_key($args, array_flip(self::$allowed_keys));
                    // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Intentionally limited to allowed keys only
                    extract($safe_args, EXTR_SKIP);
                }

                // Return the template if requested
                if ($return) {
                    ob_start();
                    include $viewPath;
                    return ob_get_clean();
                }

                include $viewPath;
            }
        } catch (\Exception $exception) {
            \wp_slimstat::log($exception->getMessage(), 'error');
        }

        return null;
    }
}
