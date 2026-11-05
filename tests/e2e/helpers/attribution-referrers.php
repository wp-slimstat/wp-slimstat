<?php
/** Real WooCommerce projection; an owned order, no payments or customer notifications. */
if (PHP_SAPI !== 'cli' || 'tests-wordpress' !== DB_NAME) { throw new RuntimeException('Disposable CLI database required.'); }
add_filter('pre_wp_mail', '__return_true');
$order = new WC_Order();
$order->set_date_created(time());
$order->set_total('19.95');
$order->set_created_via('slimstat-attribution-regression');
$order->save();
$cases = [
 ['referral', 'https://www.facebook.com/post', 'facebook.com', 'referral', 'organic_social'],
 ['referral', 'https://t.me/channel', 't.me', 'referral', 'messaging'],
 ['referral', 'https://example.org/article', 'example.org', 'referral', 'referral'],
 ['utm', 'https://www.facebook.com/post', 'newsletter', 'email', 'email'],
 ['referral', '', 'example.org', 'referral', 'referral'],
 ['typein', '', '(direct)', '(none)', 'direct'],
];
$answers = [];
try {
 foreach ($cases as [$type, $referrer, $source, $medium, $expected]) {
  foreach (['source_type'=>$type, 'referrer'=>$referrer, 'utm_source'=>$source, 'utm_medium'=>$medium] as $key=>$value) {
   $order->update_meta_data('_wc_order_attribution_'.$key, $value);
  }
  $order->save();
  $row = \SlimStat\Ecommerce\OrderData::rows(wc_get_order($order->get_id()))[0];
  if ($expected !== $row['channel'] || '19.950000' !== $row['net']) {
   throw new RuntimeException(wp_json_encode(['expected'=>$expected, 'actual'=>$row['channel'], 'net'=>$row['net']]));
  }
  $answers[] = [$type, $source, $row['channel'], $row['net']];
 }
 echo wp_json_encode(['result'=>'pass', 'cases'=>$answers]);
} finally {
 $id = $order->get_id(); $order->delete(true); \SlimStat\Ecommerce\Integration::sync($id);
}
