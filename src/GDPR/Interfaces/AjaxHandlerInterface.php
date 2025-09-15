<?php

namespace SlimStat\GDPR\Interfaces;

/**
 * Interface for handling GDPR AJAX requests
 */
interface AjaxHandlerInterface
{
    /**
     * Handle consent AJAX request
     */
    public function handleConsentRequest(): void;

    /**
     * Handle banner AJAX request
     */
    public function handleBannerRequest(): void;

    /**
     * Handle opt-out HTML request
     */
    public function handleOptOutRequest(): void;
}
