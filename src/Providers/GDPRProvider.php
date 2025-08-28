<?php

namespace SlimStat\Providers;

use SlimStat\Services\GDPRService;
use SlimStat\Controllers\GDPRController;

/**
 * GDPR Provider
 *
 * Handles GDPR functionality initialization and WordPress hooks
 * following Dependency Inversion Principle
 */
class GDPRProvider
{
    /**
     * @var GDPRService
     */
    private $gdprService;

    /**
     * @var GDPRController
     */
    private $gdprController;

    /**
     * @var array
     */
    private $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->gdprService = new GDPRService($settings);
        $this->gdprController = new GDPRController($this->gdprService);
    }

    /**
     * Initialize GDPR functionality
     */
    public function init(): void
    {
        if (!$this->gdprService->isEnabled()) {
            return;
        }

        $this->registerHooks();
        $this->enqueueAssets();
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void
    {
        // AJAX handlers
        add_action('wp_ajax_slimstat_gdpr_consent', [$this->gdprController, 'handleConsent']);
        add_action('wp_ajax_nopriv_slimstat_gdpr_consent', [$this->gdprController, 'handleConsent']);
        add_action('wp_ajax_slimstat_gdpr_banner', [$this->gdprController, 'getBanner']);
        add_action('wp_ajax_nopriv_slimstat_gdpr_banner', [$this->gdprController, 'getBanner']);

        // Shortcode
        add_shortcode('slimstat_consent', [$this->gdprController, 'getConsentManagement']);
    }

    /**
     * Enqueue GDPR assets
     */
    private function enqueueAssets(): void
    {
        add_action('wp_enqueue_scripts', function() {
            wp_enqueue_style(
                'slimstat-gdpr-consent',
                plugins_url('/assets/css/gdpr-consent.css', dirname(dirname(__DIR__)) . '/wp-slimstat.php'),
                [],
                SLIMSTAT_ANALYTICS_VERSION
            );
        });
    }

    /**
     * Get GDPR service instance
     */
    public function getService(): GDPRService
    {
        return $this->gdprService;
    }

    /**
     * Get GDPR controller instance
     */
    public function getController(): GDPRController
    {
        return $this->gdprController;
    }

    /**
     * Add GDPR parameters to JavaScript
     */
    public function addJavaScriptParams(array &$params): void
    {
        if ($this->gdprService->isEnabled()) {
            $params['gdpr_enabled'] = '1';
        }
    }
}
