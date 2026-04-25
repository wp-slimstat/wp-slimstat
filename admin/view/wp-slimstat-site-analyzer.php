<?php
/**
 * Site analyzer — generates goal/funnel suggestions from real wp_slim_stats
 * data plus active-plugin detection.
 *
 * v1 scope: WooCommerce purchase + GiveWP donation + EDD digital purchase.
 * One index-friendly SQL query (left-anchored LIKE on idx_goal_queries),
 * suggestions deduped against existing user-defined goals/funnels,
 * results cached for 24h via the version-key invalidation pattern shared
 * with the goals/funnels feature.
 *
 * Out of v1: event-based goals (tel/mailto/submit), session-sequence
 * inference, multilingual URL handling, ML clustering. See
 * jaan-to/outputs/research/22-funnel_templates_report.md.
 */
class wp_slimstat_site_analyzer
{
    const TRANSIENT_PREFIX = 'slimstat_site_analysis_';
    const CACHE_VER_OPTION = 'slimstat_site_analysis_cache_ver';
    const CACHE_TTL        = DAY_IN_SECONDS;
    const RANGE_DAYS       = 30;
    const MAX_SUGGESTIONS  = 5;

    const KIND_GOAL   = 'goal';
    const KIND_FUNNEL = 'funnel';

    /**
     * Returns the cached analysis if present, or null. Page-render path
     * (show_suggestions()) must use this — never run_analysis() — so a fresh
     * page load on a large site never triggers the multi-LIKE scan.
     */
    public static function get_cached_analysis(): ?array
    {
        $cached = get_transient(self::cache_key());
        return (false !== $cached && is_array($cached)) ? $cached : null;
    }

    /**
     * Runs the detection rules, persists the result to the transient, and
     * returns it. Called only by the AJAX handler (user clicked "Analyze my
     * site"). Cross-request caching is handled by the transient + version-key.
     *
     * @return array{suggestions:array, analyzed_at:int, range_days:int, took_ms:int}
     */
    public static function run_analysis(): array
    {
        $started = microtime(true);
        $result  = [
            'suggestions' => self::generate_suggestions(),
            'analyzed_at' => time(),
            'range_days'  => self::RANGE_DAYS,
            'took_ms'     => (int) round((microtime(true) - $started) * 1000),
        ];

        set_transient(self::cache_key(), $result, self::CACHE_TTL);
        return $result;
    }

    private static function cache_key(): string
    {
        return self::TRANSIENT_PREFIX . self::cache_version() . '_' . get_current_blog_id();
    }

    /**
     * Bumps the cache version key so the next get_analysis() call re-runs the
     * SQL. Backend-agnostic (works with Redis/Memcached AND wp_options
     * transients) because key rotation makes prior entries unreachable rather
     * than relying on a DELETE LIKE sweep.
     *
     * Sub-second precision avoids collisions when two events fire in the same
     * second (matches the slimstat_goals_cache_ver pattern).
     */
    public static function invalidate_cache(): void
    {
        update_option(self::CACHE_VER_OPTION, (string) microtime(true), false);
    }

    private static function cache_version(): string
    {
        return (string) get_option(self::CACHE_VER_OPTION, '0');
    }

    /**
     * Runs the single combined SQL query (rules 1 + 3) and assembles the
     * ranked, deduped suggestion list.
     */
    private static function generate_suggestions(): array
    {
        $now   = time();
        $start = $now - (self::RANGE_DAYS * DAY_IN_SECONDS);

        $wc_active     = self::woocommerce_available();
        $givewp_active = is_plugin_active('give/give.php');
        $edd_active    = is_plugin_active('easy-digital-downloads/easy-digital-downloads.php');
        $wc_paths      = $wc_active ? self::woocommerce_paths() : null;

        if (!$wc_paths && !$givewp_active && !$edd_active) {
            return [];
        }

        $counts = self::run_detection_query($start, $now, $wc_paths, $givewp_active, $edd_active);

        $suggestions = [];

        // Rule 1: WooCommerce — order-placed goal + full purchase funnel.
        if ($wc_paths && $counts['order_received_visits'] > 0) {
            $suggestions[] = self::build_wc_order_placed_goal(
                $wc_paths['order_received'],
                (int) $counts['order_received_visits']
            );

            $has_full_funnel =
                $counts['product_visits']        > 0 &&
                $counts['cart_visits']           > 0 &&
                $counts['checkout_visits']       > 0 &&
                $counts['order_received_visits'] > 0;

            if ($has_full_funnel) {
                $suggestions[] = self::build_wc_purchase_funnel(
                    $wc_paths,
                    (int) $counts['order_received_visits']
                );
            }
        }

        // Rule 3a: GiveWP donation goal.
        if ($givewp_active && $counts['donation_visits'] > 0) {
            $suggestions[] = self::build_givewp_donation_goal((int) $counts['donation_visits']);
        }

        // Rule 3b: EDD digital purchase goal.
        if ($edd_active && $counts['edd_purchase_visits'] > 0) {
            $suggestions[] = self::build_edd_purchase_goal((int) $counts['edd_purchase_visits']);
        }

        usort($suggestions, function ($a, $b) {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });
        $suggestions = array_slice($suggestions, 0, self::MAX_SUGGESTIONS);

        return self::dedup_against_existing($suggestions);
    }

    /**
     * Single conditional-aggregation query against wp_slim_stats. All `LIKE`
     * predicates are left-anchored ('/x/%') so wp_slim_stats's existing date
     * index (`idx_dt_*`) drives the range scan and only the leftmost-prefix
     * comparison runs per row. Inactive-plugin columns are *omitted* from
     * the SELECT so MySQL doesn't waste cycles evaluating a no-op `LIKE`
     * against every row.
     */
    private static function run_detection_query(int $start, int $end, ?array $wc_paths, bool $givewp_active, bool $edd_active): array
    {
        $wpdb  = wp_slimstat::$wpdb;
        $table = $wpdb->prefix . 'slim_stats';

        $select_parts = [];
        $like_args    = [];
        $defaults     = [
            'cart_visits'           => 0,
            'checkout_visits'       => 0,
            'order_received_visits' => 0,
            'product_visits'        => 0,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 0,
        ];

        if ($wc_paths) {
            $select_parts[] = "SUM(CASE WHEN resource LIKE %s THEN 1 ELSE 0 END) AS cart_visits";
            $select_parts[] = "SUM(CASE WHEN resource LIKE %s THEN 1 ELSE 0 END) AS checkout_visits";
            $select_parts[] = "SUM(CASE WHEN resource LIKE %s THEN 1 ELSE 0 END) AS order_received_visits";
            $select_parts[] = "SUM(CASE WHEN content_type = 'cpt:product' THEN 1 ELSE 0 END) AS product_visits";
            $like_args[] = $wpdb->esc_like($wc_paths['cart'])           . '%';
            $like_args[] = $wpdb->esc_like($wc_paths['checkout'])       . '%';
            $like_args[] = $wpdb->esc_like($wc_paths['order_received']) . '%';
        }
        if ($givewp_active) {
            $select_parts[] = "SUM(CASE WHEN resource LIKE %s THEN 1 ELSE 0 END) AS donation_visits";
            $like_args[]    = $wpdb->esc_like('/donation-confirmation/') . '%';
        }
        if ($edd_active) {
            $select_parts[] = "SUM(CASE WHEN resource LIKE %s THEN 1 ELSE 0 END) AS edd_purchase_visits";
            $like_args[]    = $wpdb->esc_like('/checkout/purchase-confirmation/') . '%';
        }

        // No active rules → no SQL. Caller already short-circuits when no
        // plugin gates pass, but defend in depth.
        if (empty($select_parts)) {
            return $defaults;
        }

        $sql = 'SELECT ' . implode(', ', $select_parts) .
               " FROM {$table} WHERE dt BETWEEN %d AND %d";

        $row = $wpdb->get_row($wpdb->prepare($sql, ...array_merge($like_args, [$start, $end])), ARRAY_A);

        if (!is_array($row)) {
            return $defaults;
        }

        // wpdb returns strings; cast to ints. Merge over defaults so
        // omitted-column callers can still read e.g. $r['donation_visits'].
        return array_merge($defaults, array_map('intval', $row));
    }

    private static function woocommerce_available(): bool
    {
        return class_exists('WooCommerce')
            && function_exists('wc_get_page_permalink')
            && function_exists('is_plugin_active')
            && is_plugin_active('woocommerce/woocommerce.php');
    }

    /**
     * Resolves the user's actual WC page paths + product permalink base.
     * Returns null if either cart or checkout is unset (deleted or never
     * assigned during WC setup). The product base reads from the
     * `woocommerce_permalinks` option so funnel suggestions match stores
     * that customised the `/product/` slug (e.g. multilingual installs
     * using `/produit/`).
     *
     * @return array{cart:string,checkout:string,product:string,order_received:string}|null
     */
    private static function woocommerce_paths(): ?array
    {
        $cart_url     = wc_get_page_permalink('cart');
        $checkout_url = wc_get_page_permalink('checkout');

        if (!$cart_url || !$checkout_url) {
            return null;
        }

        $cart_path     = self::path_from_url($cart_url);
        $checkout_path = self::path_from_url($checkout_url);

        if ('' === $cart_path || '' === $checkout_path) {
            return null;
        }

        $permalinks   = get_option('woocommerce_permalinks', []);
        $product_base = is_array($permalinks) && !empty($permalinks['product_base'])
            ? trim((string) $permalinks['product_base'], '/')
            : 'product';

        return [
            'cart'           => $cart_path,
            'checkout'       => $checkout_path,
            'product'        => '/' . $product_base . '/',
            'order_received' => $checkout_path . 'order-received/',
        ];
    }

    /**
     * Strips scheme/host from a URL and returns the path normalised to have
     * both a leading and trailing slash. e.g. "https://site.com/basket" → "/basket/".
     * Trailing-slash normalisation is local because WC permalinks vary by site.
     */
    private static function path_from_url(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return '';
        }
        return '/' . trim($path, '/') . '/';
    }

    private static function build_wc_order_placed_goal(string $order_received_path, int $hits): array
    {
        return [
            'kind'      => self::KIND_GOAL,
            'id'        => 'wc-order-placed',
            'title'     => __('Order placed', 'wp-slimstat'),
            'rationale' => sprintf(
                /* translators: 1: completed-order count */
                __('WooCommerce active · %d completed orders in the last 30 days', 'wp-slimstat'),
                $hits
            ),
            'priority'  => 100,
            'prefill'   => [
                'name'      => __('Order placed', 'wp-slimstat'),
                'dimension' => 'resource',
                'operator'  => 'starts_with',
                'value'     => $order_received_path,
                'active'    => true,
            ],
        ];
    }

    private static function build_wc_purchase_funnel(array $wc_paths, int $completed_orders): array
    {
        $steps = [
            ['name' => __('Product',        'wp-slimstat'), 'dimension' => 'resource', 'operator' => 'starts_with', 'value' => $wc_paths['product']],
            ['name' => __('Cart',           'wp-slimstat'), 'dimension' => 'resource', 'operator' => 'starts_with', 'value' => $wc_paths['cart']],
            ['name' => __('Checkout',       'wp-slimstat'), 'dimension' => 'resource', 'operator' => 'starts_with', 'value' => $wc_paths['checkout']],
            ['name' => __('Order received', 'wp-slimstat'), 'dimension' => 'resource', 'operator' => 'starts_with', 'value' => $wc_paths['order_received']],
        ];

        return [
            'kind'      => self::KIND_FUNNEL,
            'id'        => 'wc-purchase-funnel',
            'title'     => __('WooCommerce purchase funnel', 'wp-slimstat'),
            'rationale' => sprintf(
                /* translators: 1: completed-order count */
                __('WooCommerce active · %d completed orders detected', 'wp-slimstat'),
                $completed_orders
            ),
            'priority'  => 110,
            'prefill'   => [
                'name'  => __('WooCommerce purchase', 'wp-slimstat'),
                'steps' => $steps,
            ],
        ];
    }

    private static function build_givewp_donation_goal(int $hits): array
    {
        return [
            'kind'      => self::KIND_GOAL,
            'id'        => 'givewp-donation',
            'title'     => __('Donation completed', 'wp-slimstat'),
            'rationale' => sprintf(
                /* translators: 1: donation count */
                __('GiveWP active · %d donations in the last 30 days', 'wp-slimstat'),
                $hits
            ),
            'priority'  => 90,
            'prefill'   => [
                'name'      => __('Donation completed', 'wp-slimstat'),
                'dimension' => 'resource',
                'operator'  => 'starts_with',
                'value'     => '/donation-confirmation/',
                'active'    => true,
            ],
        ];
    }

    private static function build_edd_purchase_goal(int $hits): array
    {
        return [
            'kind'      => self::KIND_GOAL,
            'id'        => 'edd-purchase',
            'title'     => __('Digital purchase completed', 'wp-slimstat'),
            'rationale' => sprintf(
                /* translators: 1: purchase count */
                __('Easy Digital Downloads active · %d purchases in the last 30 days', 'wp-slimstat'),
                $hits
            ),
            'priority'  => 90,
            'prefill'   => [
                'name'      => __('Digital purchase completed', 'wp-slimstat'),
                'dimension' => 'resource',
                'operator'  => 'starts_with',
                'value'     => '/checkout/purchase-confirmation/',
                'active'    => true,
            ],
        ];
    }

    /**
     * Removes suggestions that exactly match an existing user-defined goal
     * or funnel (dimension + operator + value). Predictable, simple — no
     * operator-equivalence normalization in v1.
     */
    private static function dedup_against_existing(array $suggestions): array
    {
        $existing_keys = array_merge(
            self::existing_goal_keys(),
            self::existing_funnel_keys()
        );

        if (empty($existing_keys)) {
            return $suggestions;
        }

        return array_values(array_filter($suggestions, function ($suggestion) use ($existing_keys) {
            $key = self::suggestion_dedup_key($suggestion);
            return null === $key || !in_array($key, $existing_keys, true);
        }));
    }

    private static function existing_goal_keys(): array
    {
        $goals = get_option('slimstat_goals', []);
        if (!is_array($goals)) {
            return [];
        }
        $keys = [];
        foreach ($goals as $goal) {
            $key = self::rule_key(
                $goal['dimension'] ?? '',
                $goal['operator']  ?? '',
                $goal['value']     ?? ''
            );
            if ('' !== $key) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * For funnels, dedup against the FIRST step only — that's the "entry
     * point" identity. v2 could compare full step lists if false-positives
     * appear in practice.
     */
    private static function existing_funnel_keys(): array
    {
        $funnels = get_option('slimstat_funnels', []);
        if (!is_array($funnels)) {
            return [];
        }
        $keys = [];
        foreach ($funnels as $funnel) {
            $first = $funnel['steps'][0] ?? null;
            if (!is_array($first)) {
                continue;
            }
            $key = self::rule_key(
                $first['dimension'] ?? '',
                $first['operator']  ?? '',
                $first['value']     ?? ''
            );
            if ('' !== $key) {
                $keys[] = 'funnel:' . $key;
            }
        }
        return $keys;
    }

    private static function suggestion_dedup_key(array $suggestion): ?string
    {
        $kind    = $suggestion['kind']    ?? '';
        $prefill = $suggestion['prefill'] ?? [];

        if (self::KIND_GOAL === $kind) {
            return self::rule_key(
                $prefill['dimension'] ?? '',
                $prefill['operator']  ?? '',
                $prefill['value']     ?? ''
            );
        }

        if (self::KIND_FUNNEL === $kind) {
            $first = $prefill['steps'][0] ?? null;
            if (!is_array($first)) {
                return null;
            }
            $key = self::rule_key(
                $first['dimension'] ?? '',
                $first['operator']  ?? '',
                $first['value']     ?? ''
            );
            return '' === $key ? null : 'funnel:' . $key;
        }

        return null;
    }

    private static function rule_key(string $dimension, string $operator, string $value): string
    {
        if ('' === $dimension || '' === $operator) {
            return '';
        }
        return $dimension . '|' . $operator . '|' . $value;
    }
}
