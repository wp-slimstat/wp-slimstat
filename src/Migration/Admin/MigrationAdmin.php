<?php
declare(strict_types=1);

namespace SlimStat\Migration\Admin;

use SlimStat\Migration\MigrationManager;
use SlimStat\Migration\Migrations\AddMissingIndexes;
use SlimStat\Migration\Migrations\OptimizeColumnTypes;
use wp_slimstat_admin;

class MigrationAdmin
{
	/** @var MigrationManager */
	private $manager;

	public function __construct(MigrationManager $manager)
	{
		$this->manager = $manager;
	}

	public function hooks(): void
	{
		// Register after SlimStat menus so we can attach under its parent
		add_action('admin_menu', [$this, 'registerPage'], 20);
		add_action('admin_notices', [$this, 'maybeShowNotice']);
		add_action('wp_ajax_slimstat_run_migrations', [$this, 'ajaxRunMigrations']);
		add_action('wp_ajax_slimstat_migration_dismiss', [$this, 'ajaxDismiss']);
		add_action('wp_ajax_slimstat_migration_reset', [$this, 'ajaxResetDismissal']);
	}

	public function registerPage(): void
	{
		// Only register the migration page if there are migrations that need to run
		if (!$this->manager->needsMigration()) {
			return;
		}

		$parent = empty(wp_slimstat_admin::$main_menu_slug) ? 'slimview1' : wp_slimstat_admin::$main_menu_slug;
		$hook = add_submenu_page(
			$parent,
			__('Migration', 'wp-slimstat'),
			__('Migration', 'wp-slimstat'),
			'manage_options',
			'slimstat_migration',
			[$this, 'renderPage']
		);
		add_action('load-' . $hook, [$this, 'enqueueAssets']);
	}

	public function enqueueAssets(): void
	{
		global $parent_file, $submenu_file;
		if (!empty(wp_slimstat_admin::$main_menu_slug)) {
			$parent_file = wp_slimstat_admin::$main_menu_slug;
			$submenu_file = 'slimstat_migration'; // highlight Migration submenu
		}

		// Hide all admin notices on migration page
		remove_all_actions('admin_notices');
		remove_all_actions('all_admin_notices');

		// Add body class for migration page styling
		add_filter('admin_body_class', [$this, 'addBodyClass']);

		// Base SlimStat admin styles (if present)
		wp_register_style('slimstat-admin-base', plugins_url('/admin/assets/css/admin.css', SLIMSTAT_FILE), [], SLIMSTAT_ANALYTICS_VERSION);
		wp_enqueue_style('slimstat-admin-base');
		// Migration page extras
		wp_register_style('slimstat-migration', plugins_url('/admin/assets/css/migration.css', SLIMSTAT_FILE), ['slimstat-admin-base'], SLIMSTAT_ANALYTICS_VERSION);
		wp_enqueue_style('slimstat-migration');

		wp_enqueue_script('jquery');
		wp_register_script('slimstat-migration', plugins_url('/admin/assets/js/migration.js', SLIMSTAT_FILE), ['jquery'], SLIMSTAT_ANALYTICS_VERSION, true);

        $steps = [];
        foreach ($this->manager->getRequiredMigrations() as $migration) {
            $steps[] = [
                'id'   => $migration->getId(),
                'name' => $migration->getName(),
                'desc' => $migration->getDescription(),
            ];
        }

		wp_localize_script('slimstat-migration', 'SlimstatMigration', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('slimstat_run_migrations'),
			'steps' => $steps,
			'diagnostics' => $this->getRequiredDiagnostics(),
			'labels' => [
				'running' => __('Running migrations…', 'wp-slimstat'),
				'inProgress' => __('In progress…', 'wp-slimstat'),
				'done' => __('Done', 'wp-slimstat'),
				'failed' => __('Failed', 'wp-slimstat'),
				'allFinished' => __('All migrations finished.', 'wp-slimstat'),
			],
		]);
		wp_enqueue_script('slimstat-migration');
	}

	public function addBodyClass($classes): string
	{
		return $classes . ' slimstat_page_migration';
	}

	/**
	 * Check if the current screen is a SlimStat page.
	 */
	private function isSlimStatPage($screen): bool
	{
		// Check if it's a SlimStat page by looking at the page parameter
		if (isset($_GET['page'])) {
			$page = sanitize_text_field(wp_unslash($_GET['page']));
			// SlimStat pages start with 'slim' (slimview1, slimview2, slimconfig, etc.)
			if (strpos($page, 'slim') === 0) {
				return true;
			}
		}

		// Fallback: check screen ID patterns
		$slimstat_patterns = [
			'slimview',
			'slimconfig',
			'slimlayout',
			'slimpro',
			'slimstat'
		];

		foreach ($slimstat_patterns as $pattern) {
			if (strpos($screen->id, $pattern) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get diagnostics only for migrations that need to run.
	 * @return array<int,array{key:string,exists:bool,table:string,columns:string}>
	 */
	private function getRequiredDiagnostics(): array
	{
		$diagnostics = [];
		foreach ($this->manager->getRequiredMigrations() as $migration) {
			$diagnostics = array_merge($diagnostics, $migration->getDiagnostics());
		}

		return $diagnostics;
	}

	public function renderPage(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-slimstat'));
		}

		// Check if there are any migrations that need to run
		$required_migrations = $this->manager->getRequiredMigrations();
		$has_required_migrations = !empty($required_migrations);

		// If no migrations are needed, redirect to the main SlimStat page
		if (!$has_required_migrations) {
			$parent = empty(wp_slimstat_admin::$main_menu_slug) ? 'slimview1' : wp_slimstat_admin::$main_menu_slug;
			wp_safe_redirect(admin_url('admin.php?page=' . $parent));
			exit;
		}

		include plugin_dir_path(__FILE__) . '/../../view/migration-page.php';
	}

	public function maybeShowNotice(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || $screen->base !== 'dashboard' && !$this->isSlimStatPage($screen)) {
			return;
		}

		// Don't show notice on the migration page itself
		if ($screen->id === 'slimstat_page_slimstat_migration') {
			return;
		}

		// Debug: Check if migrations are needed
		$needs_migration = $this->manager->needsMigration();
		$required_migrations = $this->manager->getRequiredMigrations();

		// Add debug info as HTML comment (only visible in source)
		if (defined('WP_DEBUG') && WP_DEBUG) {
			echo "<!-- SlimStat Migration Debug: needsMigration=" . ($needs_migration ? 'true' : 'false') . ", required_count=" . count($required_migrations) . " -->";
		}

		if (!$needs_migration) {
			return;
		}

		$url = esc_url(admin_url('admin.php?page=slimstat_migration'));
		$nonce = wp_create_nonce('slimstat_migration_dismiss');
		$extra_class = 'slimstat-migration-notice-show'; // Always show if checks pass
        $diag = $this->getRequiredDiagnostics();

        echo '<div class="notice slimstat-notice notice-info notice-alt is-dismissible slimstat-migration-notice ' . esc_attr($extra_class) . '" data-nonce="' . esc_attr($nonce) . '" role="region" aria-label="' . esc_attr__('SlimStat Database Migration', 'wp-slimstat') . '">';
        echo '<h2>' . esc_html__('SlimStat database migration required', 'wp-slimstat') . '</h2>';
        echo '<p>' . esc_html__('To improve SlimStat performance and stability, your database needs to be migrated.', 'wp-slimstat') . '</p>';

        if ($diag !== []) {
            echo '<details>';
            echo '<summary>' . esc_html__('Technical details', 'wp-slimstat') . '</summary>';
            echo '<ul>';
            foreach ($diag as $row) {
                $icon = $row['exists'] ? 'yes' : 'warning';
                echo '<li>';
                echo '<span class="dashicons dashicons-' . esc_attr($icon) . '"></span>';
                echo '<code>' . esc_html($row['key']) . '</code>';
                echo '<span class="details-col-desc">(' . esc_html($row['columns']) . ')</span>';
                echo '</li>';
            }

            echo '</ul>';
            echo '</details>';
        }

        echo '<p class="wp-core-ui">';
        echo '<a href="' . $url . '" class="button button-primary">' . esc_html__('Go to Migration Page', 'wp-slimstat') . '</a>';
        echo '</p>';
        echo '</div>';
	}

	public function ajaxRunMigrations(): void
	{
		check_ajax_referer('slimstat_run_migrations');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wp-slimstat'));
		}

		$only = isset($_POST['migration']) ? sanitize_key(wp_unslash($_POST['migration'])) : '';

        if ($only) {
            $migration_to_run = null;
            foreach ($this->manager->getMigrations() as $migration) {
                if ($migration->getId() === $only) {
                    $migration_to_run = $migration;
                    break;
                }
            }

            if ($migration_to_run) {
                $ok = $migration_to_run->run();

                $status = $this->manager->getStatus();
                $status[$migration_to_run->getName()] = $ok;
                update_option('slimstat_migration_status', $status, false);

                if ($ok) {
                    wp_send_json_success([$migration_to_run->getName() => true]);
                }

                wp_send_json_error([$migration_to_run->getName() => false]);
            }
        }

		// Run all
		$result = $this->manager->runAll();

		// Check if all migrations are now complete
		$all_complete = !$this->manager->needsMigration();

		wp_send_json_success([
			'results' => $result,
			'all_complete' => $all_complete,
			'message' => $all_complete ? __('All migrations completed successfully!', 'wp-slimstat') : __('Migrations completed, but some may still be needed.', 'wp-slimstat')
		]);
	}

	public function ajaxDismiss(): void
	{
		check_ajax_referer('slimstat_migration_dismiss');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wp-slimstat'));
		}

		$this->manager->dismissNotice();
		wp_send_json_success();
	}

	/**
	 * Reset migration dismissal for testing purposes.
	 * This can be called via AJAX or directly for debugging.
	 */
	public function resetDismissal(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$this->manager->resetDismissal();
	}

	/**
	 * AJAX handler to reset migration dismissal for testing.
	 */
	public function ajaxResetDismissal(): void
	{
		check_ajax_referer('slimstat_migration_reset');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wp-slimstat'));
		}

		$this->manager->resetDismissal();
		wp_send_json_success(__('Migration dismissal reset', 'wp-slimstat'));
	}
}
