<?php

namespace SlimStat\GDPR\Providers;

use SlimStat\GDPR\Interfaces\ConsentManagerInterface;
use SlimStat\GDPR\Interfaces\BannerRendererInterface;
use SlimStat\GDPR\Interfaces\AjaxHandlerInterface;
use SlimStat\GDPR\Services\ConsentManager;
use SlimStat\GDPR\Services\BannerRenderer;
use SlimStat\GDPR\Controllers\GDPRController;

/**
 * GDPR Service Provider for dependency injection
 */
class GDPRServiceProvider
{
    private array $services = [];
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Get Consent Manager service
     */
    public function getConsentManager(): ConsentManagerInterface
    {
        if (!isset($this->services['consentManager'])) {
            $this->services['consentManager'] = new ConsentManager($this->settings);
        }

        return $this->services['consentManager'];
    }

    /**
     * Get Banner Renderer service
     */
    public function getBannerRenderer(): BannerRendererInterface
    {
        if (!isset($this->services['bannerRenderer'])) {
            $consentManager = $this->getConsentManager();
            $this->services['bannerRenderer'] = new BannerRenderer($consentManager, $this->settings);
        }

        return $this->services['bannerRenderer'];
    }

    /**
     * Get GDPR Controller
     */
    public function getController(): AjaxHandlerInterface
    {
        if (!isset($this->services['controller'])) {
            $consentManager = $this->getConsentManager();
            $bannerRenderer = $this->getBannerRenderer();
            $this->services['controller'] = new GDPRController($consentManager, $bannerRenderer);
        }

        return $this->services['controller'];
    }

    /**
     * Register WordPress hooks
     */
    public function registerHooks(): void
    {
        $controller = $this->getController();

        // AJAX handlers
        add_action('wp_ajax_slimstat_gdpr_consent', [$controller, 'handleConsentRequest']);
        add_action('wp_ajax_nopriv_slimstat_gdpr_consent', [$controller, 'handleConsentRequest']);

        add_action('wp_ajax_slimstat_gdpr_banner', [$controller, 'handleBannerRequest']);
        add_action('wp_ajax_nopriv_slimstat_gdpr_banner', [$controller, 'handleBannerRequest']);

        add_action('wp_ajax_slimstat_optout_html', [$controller, 'handleOptOutRequest']);
        add_action('wp_ajax_nopriv_slimstat_optout_html', [$controller, 'handleOptOutRequest']);

        // Shortcode
        add_shortcode('slimstat_consent', [$controller, 'handleShortcode'], 15);
    }

    /**
     * Check if GDPR is enabled
     */
    public function isEnabled(): bool
    {
        return $this->getConsentManager()->isEnabled();
    }

    /**
     * Get consent status
     */
    public function getConsentStatus(): string
    {
        return $this->getConsentManager()->getConsentStatus();
    }

    /**
     * Set consent
     */
    public function setConsent(string $consent): bool
    {
        return $this->getConsentManager()->setConsent($consent);
    }
}
