<?php
/** Real WooCommerce CRUD refunds, without payments, emails or existing customer records. */
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME) || !function_exists('wc_create_order')) {
    throw new RuntimeException('Disposable WordPress database and WooCommerce required');
}
$orders = [];
add_filter('pre_wp_mail', '__return_true');
try {
    foreach ([[106], [25, 81], [30]] as $amounts) {
        $order = wc_create_order();
        if (is_wp_error($order)) { throw new RuntimeException($order->get_error_message()); }
        $orders[] = $order->get_id();
        $item = new WC_Order_Item_Product();
        $item->set_name('Disposable refund fixture'); $item->set_quantity(1); $item->set_subtotal('100'); $item->set_total('100');
        $order->add_item($item);
        $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_title('Fixture shipping'); $shipping->set_total('6');
        $order->add_item($shipping); $order->calculate_totals(false); $order->save();
        foreach ($amounts as $amount) {
            $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => $amount, 'refund_payment' => false, 'restock_items' => false]);
            if (is_wp_error($refund)) { throw new RuntimeException($refund->get_error_message()); }
        }
        $rows = \SlimStat\Ecommerce\OrderData::rows(wc_get_order($order->get_id()));
        $full = array_sum($amounts) === 106;
        $expected = $full ? '0.000000' : '70.000000';
        if ($rows[0]['net'] !== $expected || $rows[0]['shipping'] !== ($full ? '0.000000' : '6.000000')) {
            throw new RuntimeException('Amount-only refund must preserve exact remaining net/shipping: ' . wp_json_encode($rows[0]));
        }
        if ($full && ($rows[1]['net'] !== '0.000000' || $rows[1]['quantity'] !== '0.000000' || $rows[1]['refund'] !== '100.000000')) {
            throw new RuntimeException('Fully refunded product rows must agree with the order summary');
        }
        echo 'PASS: actual WooCommerce amount-only ' . ($full ? 'full' : 'partial') . ' refund ' . implode('+', $amounts) . "\n";
    }
} finally {
    foreach ($orders as $id) {
        $order = wc_get_order($id); if ($order) { $order->delete(true); }
        as_unschedule_all_actions('slimstat_ecommerce_sync', [$id], \SlimStat\Ecommerce\Integration::GROUP);
        if (\SlimStat\Ecommerce\Integration::ready()) {
            \SlimStat\Ecommerce\Integration::db()->delete(\SlimStat\Ecommerce\Integration::table(), ['order_id' => $id]);
        }
    }
    remove_filter('pre_wp_mail', '__return_true');
}
