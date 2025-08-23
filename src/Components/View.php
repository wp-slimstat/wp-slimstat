<?php

namespace SlimStat\Components;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

use SlimStat\Exception\SystemErrorException;

class View
{
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
                    throw new SystemErrorException(esc_html__('View file not found: ' . $viewPath, 'wp-slimstat'));
                }

                if (!empty($args)) {
                    extract($args);
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
            \SlimStat::log($exception->getMessage(), 'error');
        }

        return null;
    }
}
