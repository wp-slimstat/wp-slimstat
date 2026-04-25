<?php
/**
 * Suggestions card — slim_p9_00 on slimview6.
 *
 * Renders one of three states (server-side based on cached analysis):
 *  - "empty"    : no analysis yet → show Analyze CTA + helper text
 *  - "no-results" : analysis ran but found nothing → friendly message + Re-analyze
 *  - "results"  : analysis found suggestions → list with one-click "Use this …"
 *
 * Caller-scope variables (set by show_suggestions()):
 *   bool   $has_cache         — true if a cached analysis exists
 *   array  $suggestions       — list of suggestion records (may be empty)
 *   int    $analyzed_at       — unix timestamp of last analysis (0 if never)
 *   int    $range_days        — analysis window (currently always 30)
 *   bool   $is_pro
 *   int    $max_goals         — apply_filters('slimstat_max_goals')
 *   int    $active_goal_count — count of active goals
 *   bool   $goal_at_limit     — $active_goal_count >= $max_goals
 */

if (!defined('ABSPATH')) {
    exit;
}

$state = !$has_cache ? 'empty' : (empty($suggestions) ? 'no-results' : 'results');
$pricing_url = 'https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=site-analyzer';
?>
<section class="slimstat-gf-card slimstat-gf-suggestions"
         data-component="suggestions"
         data-state="<?php echo esc_attr($state); ?>">

    <?php if ('empty' === $state) : ?>
        <div class="slimstat-gf-suggestions__empty" data-role="suggestions-empty">
            <div class="slimstat-gf-suggestions__empty-icon" aria-hidden="true">📊</div>
            <h3 class="slimstat-gf-suggestions__empty-title">
                <?php esc_html_e('Get goals & funnels tailored to your site', 'wp-slimstat'); ?>
            </h3>
            <p class="slimstat-gf-suggestions__empty-body">
                <?php esc_html_e('We can scan your traffic from the last 30 days and suggest goals & funnels based on what we detect — your active plugins (WooCommerce, GiveWP, EDD) and your real page paths.', 'wp-slimstat'); ?>
            </p>
            <button type="button"
                    class="button button-primary slimstat-gf-cta"
                    data-action="analyze-site">
                <?php esc_html_e('Analyze my site', 'wp-slimstat'); ?>
            </button>
            <p class="slimstat-gf-suggestions__disclosure">
                <?php esc_html_e('Reads pageview URLs only. Nothing leaves your server.', 'wp-slimstat'); ?>
            </p>
        </div>
    <?php elseif ('no-results' === $state) : ?>
        <div class="slimstat-gf-suggestions__no-results" data-role="suggestions-no-results">
            <p>
                <?php esc_html_e('We didn\'t find any patterns to suggest based on your last 30 days of data. Pick a template below to start manually.', 'wp-slimstat'); ?>
            </p>
            <p class="slimstat-gf-suggestions__meta">
                <?php
                printf(
                    /* translators: 1: human-readable time difference (e.g. "2 minutes") */
                    esc_html__('Last analyzed %s ago', 'wp-slimstat'),
                    esc_html(human_time_diff($analyzed_at, time()))
                );
                ?>
                ·
                <button type="button"
                        class="button-link slimstat-gf-suggestions__re-analyze"
                        data-action="analyze-site"
                        data-force="1">
                    <?php esc_html_e('Re-analyze', 'wp-slimstat'); ?>
                </button>
            </p>
        </div>
    <?php else : /* results */ ?>
        <header class="slimstat-gf-suggestions__head">
            <p class="slimstat-gf-suggestions__meta">
                <?php
                printf(
                    /* translators: 1: human-readable time difference, 2: range in days */
                    esc_html__('Last analyzed %1$s ago · last %2$d days', 'wp-slimstat'),
                    esc_html(human_time_diff($analyzed_at, time())),
                    (int) $range_days
                );
                ?>
                ·
                <button type="button"
                        class="button-link slimstat-gf-suggestions__re-analyze"
                        data-action="analyze-site"
                        data-force="1">
                    <?php esc_html_e('Re-analyze', 'wp-slimstat'); ?>
                </button>
            </p>
        </header>

        <ul class="slimstat-gf-suggestions__list" role="list" data-role="suggestions-list">
            <?php foreach ($suggestions as $suggestion) :
                $kind          = $suggestion['kind']      ?? 'goal';
                $title         = $suggestion['title']     ?? '';
                $rationale     = $suggestion['rationale'] ?? '';
                $prefill       = $suggestion['prefill']   ?? [];
                $prefill_json  = wp_json_encode($prefill);
                $is_funnel     = ('funnel' === $kind);
                $needs_pro     = $is_funnel && !$is_pro;
                $blocked_free  = !$is_funnel && $goal_at_limit;
                $data_attr     = $is_funnel ? 'data-funnel' : 'data-goal';
                $action_attr   = $is_funnel ? 'open-funnel-builder' : 'open-goal-drawer';
                $cta_label     = $is_funnel
                    ? __('Use this funnel', 'wp-slimstat')
                    : __('Use this goal', 'wp-slimstat');
                ?>
                <li class="slimstat-gf-suggestion"
                    data-kind="<?php echo esc_attr($kind); ?>"
                    data-suggestion-id="<?php echo esc_attr($suggestion['id'] ?? ''); ?>">
                    <div class="slimstat-gf-suggestion__body">
                        <h4 class="slimstat-gf-suggestion__title">
                            <span class="slimstat-gf-suggestion__icon" aria-hidden="true">⚡</span>
                            <?php echo esc_html($title); ?>
                            <?php if ($is_funnel) : ?>
                                <span class="slimstat-gf-suggestion__kind"><?php esc_html_e('funnel', 'wp-slimstat'); ?></span>
                            <?php else : ?>
                                <span class="slimstat-gf-suggestion__kind"><?php esc_html_e('goal', 'wp-slimstat'); ?></span>
                            <?php endif; ?>
                        </h4>
                        <p class="slimstat-gf-suggestion__rationale">
                            <?php echo esc_html($rationale); ?>
                        </p>
                    </div>
                    <div class="slimstat-gf-suggestion__actions">
                        <?php if ($needs_pro) : ?>
                            <a class="button button-primary slimstat-gf-cta"
                               href="<?php echo esc_url($pricing_url); ?>"
                               target="_blank"
                               rel="noopener noreferrer">
                                <?php esc_html_e('Upgrade to Pro', 'wp-slimstat'); ?>
                            </a>
                        <?php elseif ($blocked_free) : ?>
                            <button type="button"
                                    class="button slimstat-gf-cta"
                                    disabled
                                    aria-disabled="true">
                                <?php echo esc_html($cta_label); ?>
                            </button>
                            <p class="slimstat-gf-suggestion__limit-note">
                                <?php
                                $limit_template = wp_kses(
                                    sprintf(
                                        /* translators: 1: active goals, 2: max goals, 3: anchor open, 4: anchor close */
                                        __('You\'re using %1$d of %2$d goals. Pause an existing goal to use this one, or %3$supgrade to Pro%4$s for more.', 'wp-slimstat'),
                                        (int) $active_goal_count,
                                        (int) $max_goals,
                                        '<a href="' . esc_url($pricing_url) . '" target="_blank" rel="noopener noreferrer">',
                                        '</a>'
                                    ),
                                    ['a' => ['href' => true, 'target' => true, 'rel' => true]]
                                );
                                echo $limit_template;
                                ?>
                            </p>
                        <?php else : ?>
                            <button type="button"
                                    class="button button-primary slimstat-gf-cta"
                                    data-action="<?php echo esc_attr($action_attr); ?>"
                                    data-mode="create"
                                    <?php echo esc_attr($data_attr); ?>='<?php echo esc_attr($prefill_json); ?>'>
                                <?php echo esc_html($cta_label); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="slimstat-gf-suggestions__loading" data-role="suggestions-loading" hidden>
        <span class="spinner is-active" aria-hidden="true"></span>
        <span class="slimstat-gf-suggestions__loading-text">
            <?php esc_html_e('Reading your last 30 days of pageviews…', 'wp-slimstat'); ?>
        </span>
    </div>
</section>
