<?php
declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Tracker\Tracker;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class TrackingRestController implements RestControllerInterface
{
    public function register_routes(): void
    {
        register_rest_route('slimstat/v1', '/hit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_tracking'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_tracking(\WP_REST_Request $request)
    {
        // Handle tracking hits
        $result = null;
        if (function_exists('ob_start')) {
            ob_start();
            $maybe = Tracker::slimtrack_ajax();
            $output = ob_get_clean();
            $result = $maybe ?? $output;
        } else {
            $result = Tracker::slimtrack_ajax();
        }

        // Normalize to string numeric id if possible
        if (is_numeric($result)) {
            return rest_ensure_response((string) $result);
        }

        // If no numeric id detected, still return 200 OK to satisfy queue
        return rest_ensure_response('');
    }
}
