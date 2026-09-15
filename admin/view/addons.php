<?php
if (!defined('ABSPATH')) {
    exit;
}

// Update license keys, if needed. This preserves the existing license-key
// sanitizer; authorization and request shapes must precede every settings write.
if (!empty($_POST['licenses']) && isset($_POST['slimstat_update_licenses']) && is_string($_POST['slimstat_update_licenses']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['slimstat_update_licenses'])), 'slimstat_update_licenses')) {
    if (!current_user_can(is_network_admin() ? 'manage_network_options' : 'manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'wp-slimstat'));
    }
    if (!is_array($_POST['licenses'])) {
        wp_die(esc_html__('Invalid license data.', 'wp-slimstat'));
    }
    foreach ($_POST['licenses'] as $a_license_slug => $a_license_key) {
        if (!is_string($a_license_slug) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $a_license_slug) || !is_string($a_license_key)) {
            wp_die(esc_html__('Invalid license data.', 'wp-slimstat'));
        }
    }
    foreach (wp_unslash($_POST['licenses']) as $a_license_slug => $a_license_key) {
        wp_slimstat::$settings['addon_licenses'][$a_license_slug] = sanitize_title($a_license_key);
    }
    wp_slimstat::update_option('slimstat_options', wp_slimstat::$settings);
}

$response      = get_transient('wp_slimstat_addon_list');
$error_message = '';

if (!empty($_GET['force_refresh']) || false === $response) {
    $response = wp_remote_get('https://www.wp-slimstat.com/update-checker/', ['headers' => ['referer' => get_site_url()]]);
    if (is_wp_error($response) || 200 != $response['response']['code']) {
        $error_message = is_wp_error($response) ? $response->get_error_message() : $response['response']['code'] . ' ' . $response['response']['message'];
        /* translators: %s: error message returned while retrieving the add-ons list. */
        $error_message = sprintf(__('There was an error retrieving the add-ons list from the server. Please try again later. Error Message: %s', 'wp-slimstat'), $error_message);
    } else {
        set_transient('wp_slimstat_addon_list', $response, 86400);
    }
}

$at_least_one_add_on_active = false;
// Security: Use JSON decode only to prevent PHP Object Injection
$list_addons                = json_decode(wp_remote_retrieve_body($response), true);

if (is_array($list_addons)) {
    foreach ($list_addons as $a_addon) {
        if (!is_array($a_addon) || !isset($a_addon['slug']) || !is_string($a_addon['slug']) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $a_addon['slug'])) {
            $list_addons = null;
            break;
        }
        foreach (['name', 'download_url', 'description', 'price', 'version'] as $field) {
            if (('version' !== $field && !isset($a_addon[$field])) || (isset($a_addon[$field]) && !is_scalar($a_addon[$field]))) {
                $list_addons = null;
                break 2;
            }
        }
    }
}
if (!is_array($list_addons)) {
    $error_message = __('There was an error decoding the add-ons list from the server. Please try again later.', 'wp-slimstat');
}
?>

<div class="wrap-slimstat">
    <h2><?php esc_html_e('Add-ons', 'wp-slimstat') ?></h2>
    <p><?php echo wp_kses_post(__('Add-ons extend the functionality of Slimstat in many interesting ways. We offer both free and premium (paid) extensions. Each add-on can be installed as a separate plugin, which will receive regular updates via the WordPress Plugins panel. In order to be notified when a new version of a premium add-on is available, please enter the <strong>license key</strong> you received when you purchased it.', 'wp-slimstat')); ?><?php
if (empty($_GET['force_refresh'])) {
    echo ' ';
    /* translators: %s: current settings page URL, before the force-refresh parameter. */
    echo wp_kses_post(sprintf(__('This list is refreshed once daily: <a href="%s&amp;force_refresh=true" class="noslimstat">click here</a> to clear the cache.', 'wp-slimstat'), esc_url(isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '')));
}

if (!empty($error_message)) {
    wp_slimstat_admin::show_message($error_message, 'warning');
    return;
}
?>
    </p>

    <form method="post" id="form-slimstat-options-tab-addons">
        <?php wp_nonce_field('slimstat_update_licenses', 'slimstat_update_licenses'); ?>
        <table class="wp-list-table widefat plugins slimstat-addons" cellspacing="0">
            <thead>
            <tr>
                <th scope="col" id="name" class="manage-column column-name"><?php esc_html_e('Add-on', 'wp-slimstat') ?></th>
                <th scope="col" id="description" class="manage-column column-description" style=""><?php esc_html_e('Description', 'wp-slimstat') ?></th>
            </tr>
            </thead>

            <tbody id="the-list">
            <?php foreach ($list_addons as $a_addon): $is_active = is_plugin_active($a_addon['slug'] . '/index.php') || is_plugin_active($a_addon['slug'] . '/' . $a_addon['slug'] . '.php'); ?>
                <tr id="<?php echo esc_attr($a_addon['slug']); ?>" <?php echo $is_active ? 'class="active"' : '' ?>>
                    <th scope="row" class="plugin-title">
                        <strong><a target="_blank" href="<?php echo esc_url($a_addon['download_url']); ?>"><?php echo esc_html($a_addon['name']); ?></a></strong>
                        <div class="row-actions-visible"><?php
                    if (!empty($a_addon['version'])) {
                        echo esc_html($is_active ? __('Repo Version', 'wp-slimstat') : __('Version', 'wp-slimstat')) . ': ' . esc_html($a_addon['version']) . '<br/>';
                    }

                if ($is_active) {
                    if (is_plugin_active($a_addon['slug'] . '/index.php')) {
                        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $a_addon['slug'] . '/index.php');
                    } else {
                        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $a_addon['slug'] . '/' . $a_addon['slug'] . '.php');
                    }

                    if (!empty($plugin_data['Version'])) {
                        echo esc_html__('Your Version:', 'wp-slimstat') . ' ' . esc_html($plugin_data['Version']);
                    } else {
                        esc_html_e('Installed and Active', 'wp-slimstat');
                    }
                    $at_least_one_add_on_active = true;
                } else {
                    echo esc_html__('Price:', 'wp-slimstat') . ' ' . esc_html(is_numeric($a_addon['price']) ? '$' . $a_addon['price'] : $a_addon['price']);
                } ?>
                        </div>
                    </th>
                    <td class="column-description desc">
                        <div class="plugin-description"><p><?php echo wp_kses_post($a_addon['description']); ?></p></div>
                        <?php if ((is_plugin_active($a_addon['slug'] . '/index.php') || is_plugin_active($a_addon['slug'] . '/' . $a_addon['slug'] . '.php'))): ?>
                            <div class="active second">
                                <?php esc_html_e('License Key', 'wp-slimstat'); ?> <input type="text" name="licenses[<?php echo esc_attr($a_addon['slug']); ?>]" value="<?php echo empty(wp_slimstat::$settings['addon_licenses'][$a_addon['slug']]) ? '' : esc_attr(wp_slimstat::$settings['addon_licenses'][$a_addon['slug']]) ?>" size="50">
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php if ($at_least_one_add_on_active): ?><input type="submit" value="<?php esc_attr_e('Save License Keys', 'wp-slimstat'); ?>" class="button-primary" name="Submit"><?php endif ?>

    </form>
</div>
