<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

abstract class IntegrationTestCase extends WpSlimstatTestCase
{
    protected array $optionStore = [];
    protected array $filterValues = [];
    protected bool $nonceValid = true;
    protected bool $capability = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapAdminClassOnce();
        $this->stubWpFunctions();
    }

    protected function tearDown(): void
    {
        $this->optionStore  = [];
        $this->filterValues = [];
        $_POST              = [];
        parent::tearDown();
    }

    /**
     * Loads admin/index.php once per test run so the wp_slimstat_admin class
     * is defined. Pre-declares a minimal wp_slimstat stub (static settings +
     * pro_is_installed) so class-level references in the admin file resolve.
     */
    private function bootstrapAdminClassOnce(): void
    {
        if (class_exists('wp_slimstat_admin', false)) {
            return;
        }

        if (!class_exists('wp_slimstat', false)) {
            eval(<<<'PHP'
            class wp_slimstat
            {
                public static $settings = [
                    'capability_can_admin' => 'manage_options',
                    'capability_can_view'  => 'read',
                    'can_view'             => '',
                ];
                public static $wpdb = null;
                public static function pro_is_installed() { return false; }
            }
            PHP);
        }

        // check_ajax_view_capability() reads $GLOBALS['current_user']->user_login.
        // Stub a minimal user object so warnings don't bleed into integration runs.
        if (empty($GLOBALS['current_user'])) {
            $GLOBALS['current_user'] = (object) ['user_login' => 'test_admin'];
        }

        // The admin class references a handful of WP functions at class-load time
        // only indirectly (inside method bodies). We stub them lazily per-test via
        // Brain Monkey so the file can be required here.
        $adminPath = dirname(__DIR__, 2) . '/admin/index.php';
        require_once $adminPath;
    }

    protected function stubWpFunctions(): void
    {
        Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? trim($v) : '',
            'wp_unslash'          => static fn($v) => is_array($v) ? array_map('stripslashes', $v) : (is_string($v) ? stripslashes($v) : $v),
            '__'                  => static fn($text, $domain = 'default') => $text,
            '_e'                  => static fn($text, $domain = 'default') => print $text,
            'esc_html__'          => static fn($text, $domain = 'default') => $text,
            'esc_html_e'          => static fn($text, $domain = 'default') => print $text,
            'esc_html'            => static fn($v) => $v,
            'esc_attr'            => static fn($v) => $v,
            'esc_url'             => static fn($v) => $v,
            'number_format_i18n'  => static fn($n) => number_format((float) $n),
            'wp_has_consent'      => static fn() => true,
        ]);

        // Nonce check: default to valid; tests that assert rejection flip $this->nonceValid.
        Functions\when('check_ajax_referer')->alias(function ($action, $query_arg = false, $die = true) {
            if (!$this->nonceValid) {
                throw new WpAjaxDie('nonce_invalid');
            }
            return 1;
        });

        // Capability gate: default to granted; tests flip $this->capability.
        Functions\when('current_user_can')->alias(function ($cap) {
            return $this->capability;
        });

        // Option store stubs.
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $this->optionStore[$key] ?? $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = null) {
            $this->optionStore[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->optionStore[$key]);
            return true;
        });

        // Filter resolver — per-test overrides via $this->filterValues.
        Functions\when('apply_filters')->alias(function ($hook, $default = null) {
            return $this->filterValues[$hook] ?? $default;
        });

        // wp_send_json_* throw so handler paths are observable via expectException.
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            throw new WpAjaxDie('error', $data);
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new WpAjaxDie('success', $data);
        });

        // Transients no-op by default; CacheTest overrides.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        // WP helpers various tests need; cheap to stub once globally.
        Functions\when('human_time_diff')->justReturn('a moment');
        Functions\when('get_current_blog_id')->justReturn(1);
    }

    protected function setGoals(array $goals): void
    {
        $this->optionStore['slimstat_goals'] = $goals;
    }

    protected function setFunnels(array $funnels): void
    {
        $this->optionStore['slimstat_funnels'] = $funnels;
    }

    protected function setMaxGoals(int $max): void
    {
        $this->filterValues['slimstat_max_goals'] = $max;
    }

    protected function setMaxFunnels(int $max): void
    {
        $this->filterValues['slimstat_max_funnels'] = $max;
    }
}
