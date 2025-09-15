<?php

namespace SlimStat\GDPR\Controllers;

use SlimStat\GDPR\Interfaces\AjaxHandlerInterface;
use SlimStat\GDPR\Interfaces\ConsentManagerInterface;
use SlimStat\GDPR\Interfaces\BannerRendererInterface;

/**
 * GDPR Controller for handling AJAX requests and shortcodes
 */
class GDPRController implements AjaxHandlerInterface
{
    private ConsentManagerInterface $consentManager;
    private BannerRendererInterface $bannerRenderer;

    public function __construct(
        ConsentManagerInterface $consentManager,
        BannerRendererInterface $bannerRenderer
    ) {
        $this->consentManager = $consentManager;
        $this->bannerRenderer = $bannerRenderer;
    }

    /**
     * Handle consent AJAX request
     */
    public function handleConsentRequest(): void
    {
        if (empty($_POST['consent']) || !in_array($_POST['consent'], ['accepted', 'denied'])) {
            wp_send_json_error('Invalid consent value');
        }

        $consent = sanitize_text_field($_POST['consent']);
        $success = $this->consentManager->setConsent($consent);

        if ($success) {
            wp_send_json_success(['consent' => $consent]);
        } else {
            wp_send_json_error('Failed to set consent');
        }
    }

    /**
     * Handle banner AJAX request
     */
    public function handleBannerRequest(): void
    {
        if (!$this->consentManager->isEnabled()) {
            wp_send_json_error('GDPR banner is not enabled');
        }

        if ($this->consentManager->hasConsent()) {
            wp_send_json_error('Consent already given');
        }

        $bannerHtml = $this->bannerRenderer->renderBanner();
        wp_send_json_success(['html' => $bannerHtml]);
    }

    /**
     * Handle opt-out HTML request
     */
    public function handleOptOutRequest(): void
    {
        if ($this->consentManager->isEnabled()) {
            die($this->bannerRenderer->renderBanner());
        } else {
            // Fallback to old message for backward compatibility
            die(stripslashes($this->bannerRenderer->settings['opt_out_message'] ?? ''));
        }
    }

    /**
     * Handle shortcode rendering
     */
    public function handleShortcode(array $attributes = []): string
    {
        if (!$this->consentManager->isEnabled()) {
            return '<p>' . __('GDPR consent management is not enabled. Please enable "GDPR Consent Banner" in SlimStat settings.', 'wp-slimstat') . '</p>';
        }

        $attributes = shortcode_atts([
            'type' => 'management',
            'style' => 'default',
        ], $attributes);

        $type = $attributes['type'];
        $style = $attributes['style'];

        switch ($type) {
            case 'management':
                return $this->bannerRenderer->renderManagement($style);

            case 'banner':
                return $this->bannerRenderer->renderBanner();

            case 'status':
                return $this->bannerRenderer->renderStatus();

            default:
                return '<p>' . __('Invalid consent type specified.', 'wp-slimstat') . '</p>';
        }
    }
}
