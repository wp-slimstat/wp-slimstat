<?php

namespace SlimStat\GDPR\Interfaces;

/**
 * Interface for rendering GDPR banners
 */
interface BannerRendererInterface
{
    /**
     * Render consent banner HTML
     */
    public function renderBanner(): string;

    /**
     * Render consent management HTML
     */
    public function renderManagement(string $style = 'default'): string;

    /**
     * Render consent status HTML
     */
    public function renderStatus(): string;

    /**
     * Check if banner should be shown
     */
    public function shouldShowBanner(): bool;
}
