<?php

namespace SlimStat\Controllers;

use SlimStat\Services\GDPRService;

/**
 * GDPR Controller
 *
 * Handles GDPR-related AJAX requests and responses
 * following Single Responsibility Principle
 */
class GDPRController
{
    /**
     * @var GDPRService
     */
    private $gdprService;

    public function __construct(GDPRService $gdprService)
    {
        $this->gdprService = $gdprService;
    }

    /**
     * Handle GDPR consent AJAX request
     */
    public function handleConsent(): void
    {
        if (empty($_POST['consent']) || !in_array($_POST['consent'], ['accepted', 'denied'])) {
            wp_send_json_error('Invalid consent value');
        }

        $consent = sanitize_text_field($_POST['consent']);
        $success = $this->gdprService->setConsent($consent);

        if ($success) {
            wp_send_json_success(['consent' => $consent]);
        } else {
            wp_send_json_error('Failed to set consent');
        }
    }

    /**
     * Get GDPR banner HTML via AJAX
     */
    public function getBanner(): void
    {
        if (!$this->gdprService->isEnabled()) {
            wp_send_json_error('GDPR consent is not enabled');
        }

        $html = $this->gdprService->getBannerHtml();
        wp_send_json_success(['html' => $html]);
    }

    /**
     * Get consent management HTML for shortcode
     */
    public function getConsentManagement(): string
    {
        if (!$this->gdprService->isEnabled()) {
            return '';
        }

        return $this->gdprService->getConsentManagementHtml();
    }
}
