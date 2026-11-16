<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Reports\AiReport;
use SlimStat\Shortcodes\Shortcode;

if (!defined('ABSPATH')) { exit; }

/** Prepare a clipboard prompt locally; no AI connection or external request. */
final class AiPromptRestController implements RestControllerInterface
{
    /** Register the local prompt endpoint; it never invokes an AI provider. */
    public function register_routes(): void
    {
        register_rest_route('slimstat/v1', '/ai/prompt', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'permission'],
            'callback' => [$this, 'prompt'],
        ]);
    }

    /** Require a logged-in report viewer and a REST nonce before reading analytics. */
    public function permission(\WP_REST_Request $request): bool
    {
        return get_current_user_id() && wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest') && Shortcode::canView();
    }

    /** Validate bounded selection metadata and return only authorized aggregate data.
     * @return array|\WP_Error Prepared prompt or a safe context/permission error.
     */
    public function prompt(\WP_REST_Request $request)
    {
        $input = $request->get_json_params();
        if (strlen($request->get_body()) > 8192 || !is_array($input) || 'slim_p10_01' !== ($input['report'] ?? '')) {
            return new \WP_Error('slimstat_ai_context', __('Choose a valid Ecommerce report.', 'wp-slimstat'), ['status' => 400]);
        }
        $context = AiReport::context($input);
        return is_wp_error($context) ? $context : AiReport::build($context);
    }
}
