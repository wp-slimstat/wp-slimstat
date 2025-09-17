<?php

namespace SlimStat\GDPR\Services;

use SlimStat\GDPR\Interfaces\BannerRendererInterface;
use SlimStat\GDPR\Interfaces\ConsentManagerInterface;

/**
 * GDPR Banner Renderer Service
 */
class BannerRenderer implements BannerRendererInterface
{
    private ConsentManagerInterface $consentManager;
    public array $settings;

    public function __construct(ConsentManagerInterface $consentManager, array $settings)
    {
        $this->consentManager = $consentManager;
        $this->settings = $settings;
    }

    /**
     * Render consent banner HTML
     */
    public function renderBanner(): string
    {
        if (!$this->consentManager->isEnabled()) {
            return '';
        }

        $message = $this->settings['opt_out_message'] ?? '';

        // If message already contains banner structure, return as is
        if (strpos($message, 'slimstat-gdpr-banner') !== false) {
            return $message;
        }

        // Wrap old message in new banner structure
        return $this->wrapInBannerStructure($message);
    }

    /**
     * Render consent management HTML
     */
    public function renderManagement(string $style = 'default'): string
    {
        if (!$this->consentManager->isEnabled()) {
            return '<p>' . __('GDPR consent management is not enabled.', 'wp-slimstat') . '</p>';
        }

        $consentStatus = $this->consentManager->getConsentStatus();

        $html = '<div class="slimstat-gdpr-management">';

        switch ($style) {
            case 'minimal':
                $html .= $this->renderMinimalManagement($consentStatus);
                break;
            case 'full':
                $html .= $this->renderFullManagement($consentStatus);
                break;
            default:
                $html .= $this->renderDefaultManagement($consentStatus);
                break;
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render consent status HTML
     */
    public function renderStatus(): string
    {
        $consentStatus = $this->consentManager->getConsentStatus();
        $statusText = '';

        switch ($consentStatus) {
            case 'accepted':
                $statusText = __('Analytics tracking is enabled.', 'wp-slimstat');
                break;
            case 'denied':
                $statusText = __('Analytics tracking is disabled.', 'wp-slimstat');
                break;
            default:
                $statusText = __('Analytics consent not yet given.', 'wp-slimstat');
                break;
        }

        return '<div class="slimstat-consent-status">' . esc_html($statusText) . '</div>';
    }

    /**
     * Check if banner should be shown
     */
    public function shouldShowBanner(): bool
    {
        return $this->consentManager->isEnabled() && !$this->consentManager->hasConsent();
    }

    /**
     * Wrap message in banner structure
     */
    private function wrapInBannerStructure(string $message): string
    {
        // Get button texts from settings, fallback to defaults
        $acceptText = $this->settings['gdpr_accept_button_text'] ?? __('Accept', 'wp-slimstat');
        $declineText = $this->settings['gdpr_decline_button_text'] ?? __('Decline', 'wp-slimstat');

        return sprintf(
            '<div class="slimstat-gdpr-banner" id="slimstat-gdpr-banner">
                <div class="slimstat-gdpr-content">
                    <div class="slimstat-gdpr-message">%s</div>
                    <div class="slimstat-gdpr-buttons">
                        <button type="button" class="slimstat-gdpr-accept" data-consent="accepted">%s</button>
                        <button type="button" class="slimstat-gdpr-deny" data-consent="denied">%s</button>
                    </div>
                </div>
            </div>',
            wp_kses_post($message),
            esc_html($acceptText),
            esc_html($declineText)
        );
    }

    /**
     * Render default management interface
     */
    private function renderDefaultManagement(string $consentStatus): string
    {
        // Get button texts from settings, fallback to defaults
        $acceptText = $this->settings['gdpr_accept_button_text'] ?? __('Accept Analytics', 'wp-slimstat');
        $declineText = $this->settings['gdpr_decline_button_text'] ?? __('Deny Analytics', 'wp-slimstat');

        $html = '<h3>' . __('Analytics Consent Management', 'wp-slimstat') . '</h3>';
        $html .= '<p>' . __('Current status: ', 'wp-slimstat') . '<strong>' . esc_html($consentStatus) . '</strong></p>';
        $html .= '<div class="slimstat-gdpr-buttons">';

        if ($consentStatus !== 'accepted') {
            $html .= '<button type="button" class="slimstat-gdpr-accept" data-consent="accepted">' . esc_html($acceptText) . '</button>';
        }

        if ($consentStatus !== 'denied') {
            $html .= '<button type="button" class="slimstat-gdpr-deny" data-consent="denied">' . esc_html($declineText) . '</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render minimal management interface
     */
    private function renderMinimalManagement(string $consentStatus): string
    {
        // Get button texts from settings, fallback to defaults
        $acceptText = $this->settings['gdpr_accept_button_text'] ?? __('Accept', 'wp-slimstat');
        $declineText = $this->settings['gdpr_decline_button_text'] ?? __('Deny', 'wp-slimstat');

        $html = '<p>' . __('Analytics: ', 'wp-slimstat') . '<strong>' . esc_html($consentStatus) . '</strong></p>';
        $html .= '<div class="slimstat-gdpr-buttons">';

        if ($consentStatus !== 'accepted') {
            $html .= '<button type="button" class="slimstat-gdpr-accept" data-consent="accepted">' . esc_html($acceptText) . '</button>';
        }

        if ($consentStatus !== 'denied') {
            $html .= '<button type="button" class="slimstat-gdpr-deny" data-consent="denied">' . esc_html($declineText) . '</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render full management interface
     */
    private function renderFullManagement(string $consentStatus): string
    {
        // Get button texts from settings, fallback to defaults
        $acceptText = $this->settings['gdpr_accept_button_text'] ?? __('Accept Analytics Tracking', 'wp-slimstat');
        $declineText = $this->settings['gdpr_decline_button_text'] ?? __('Deny Analytics Tracking', 'wp-slimstat');

        $html = '<h2>' . __('Analytics Consent Management', 'wp-slimstat') . '</h2>';
        $html .= '<div class="slimstat-gdpr-info">';
        $html .= '<p>' . __('This website uses analytics to understand visitor behavior and improve user experience.', 'wp-slimstat') . '</p>';
        $html .= '<p>' . __('Current consent status: ', 'wp-slimstat') . '<strong>' . esc_html($consentStatus) . '</strong></p>';
        $html .= '</div>';

        $html .= '<div class="slimstat-gdpr-buttons">';

        if ($consentStatus !== 'accepted') {
            $html .= '<button type="button" class="slimstat-gdpr-accept" data-consent="accepted">' . esc_html($acceptText) . '</button>';
        }

        if ($consentStatus !== 'denied') {
            $html .= '<button type="button" class="slimstat-gdpr-deny" data-consent="denied">' . esc_html($declineText) . '</button>';
        }

        $html .= '</div>';

        return $html;
    }
}
