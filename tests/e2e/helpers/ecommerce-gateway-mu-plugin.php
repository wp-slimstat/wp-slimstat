<?php
/** Local successful-payment simulator; only the currently owned audit fixture. */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) { return; }
add_filter('woocommerce_bacs_process_payment_order_status', static function ($status, $order) {
    $fixture = get_option('slimstat_e2e_woo_store', []);
    return !empty($fixture['audit_gateway']) && $order->get_billing_email() === $fixture['email'] ? 'completed' : $status;
}, 10, 2);
