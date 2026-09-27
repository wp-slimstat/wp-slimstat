<?php
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    throw new RuntimeException('Woo fixture requires disposable E2E mode');
}
$fixture_key = 'slimstat_e2e_woo_store';
if ($fixture_mode === 'cleanup') {
    $fixture = get_option($fixture_key, []);
    if ($fixture) {
        foreach (wc_get_orders(['billing_email' => $fixture['email'], 'limit' => -1]) as $order) {
            $order->delete(true);
        }
        foreach ($fixture['posts'] as $post_id) {
            wp_delete_post($post_id, true);
        }
        foreach ($fixture['options'] as $name => $old) {
            if ($old['exists']) update_option($name, $old['value']);
            else delete_option($name);
        }
        delete_option($fixture_key);
    }
    echo wp_json_encode(['cleaned' => true]);
    return;
}
if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
    throw new RuntimeException('UNAVAILABLE PREREQUISITE: active WooCommerce installation required');
}
if (get_option($fixture_key, false) !== false) {
    throw new RuntimeException('Unclean Woo fixture found; cleanup required before seeding');
}
$settings = [
    // WooCommerce 9.1+ turns "Coming soon" ON for every newly installed store, so a fresh
    // lane serves "Great things are on the horizon" to logged-out visitors instead of the
    // product page -- the shopper context has no cookies, so it gets exactly that. The
    // journey then hangs at the first tracking wait until the test times out, which reads
    // as a tracking defect and is not one. Saved and restored like every other setting here.
    'woocommerce_coming_soon' => 'no',
    'woocommerce_enable_guest_checkout' => 'yes',
    'woocommerce_enable_signup_and_login_from_checkout' => 'no',
    'woocommerce_cart_redirect_after_add' => 'yes',
    'woocommerce_calc_taxes' => 'no',
    'woocommerce_terms_page_id' => 0,
    'woocommerce_cart_page_id' => 0,
    'woocommerce_checkout_page_id' => 0,
    'woocommerce_bacs_settings' => ['enabled' => 'yes', 'title' => 'Internal QA bank transfer', 'instructions' => 'Internal QA only. Do not pay.'],
];
$fixture = ['posts' => [], 'options' => [], 'email' => $fixture_run . '@example.test'];
foreach ($settings as $name => $value) {
    $old = get_option($name, null);
    $fixture['options'][$name] = ['exists' => null !== $old, 'value' => $old];
}
update_option($fixture_key, $fixture, false);
foreach ($settings as $name => $value) update_option($name, $value);
foreach (['cart', 'checkout'] as $page) {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA ' . $page, 'post_name' => $fixture_run . '-' . $page, 'post_content' => '[woocommerce_' . $page . ']'], true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $fixture['posts'][] = $id;
    update_option($fixture_key, $fixture, false);
    update_option('woocommerce_' . $page . '_page_id', $id);
}
$product = new WC_Product_Simple();
$product->set_name('QA purchase item');
$product->set_status('publish');
$product->set_virtual(true);
$product->set_regular_price('1.00');
$product->set_sku($fixture_run);
$product->save();
$fixture['posts'][] = $product->get_id();
update_option($fixture_key, $fixture, false);
echo wp_json_encode(['product' => get_permalink($product->get_id()), 'cart' => wc_get_cart_url(), 'checkout' => wc_get_checkout_url(), 'email' => $fixture['email'], 'version' => WC_VERSION]);
