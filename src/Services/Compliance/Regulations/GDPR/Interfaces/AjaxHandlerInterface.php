<?php
declare(strict_types=1);

namespace SlimStat\Services\Compliance\Regulations\GDPR\Interfaces;

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
