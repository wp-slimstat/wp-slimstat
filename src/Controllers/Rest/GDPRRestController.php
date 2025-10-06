<?php
declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Services\Compliance\Regulations\GDPR\Factories\GDPRFactory;
use SlimStat\Interfaces\RestControllerInterface;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class GDPRRestController implements RestControllerInterface
{
    public function register_routes(): void
    {
        $gdpr_provider = GDPRFactory::create(\wp_slimstat::$settings);
        $controller    = $gdpr_provider->getController();

        register_rest_route('slimstat/v1', '/gdpr/banner', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handleBannerRequest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('slimstat/v1', '/gdpr/consent', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handleConsentRequest'],
            'permission_callback' => '__return_true',
        ]);
    }
}
