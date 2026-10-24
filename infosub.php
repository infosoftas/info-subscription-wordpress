<?php
/**
 * Plugin Name:       INFO-Subscription Access
 * Description:       Lets visitors sign in with INFO-Subscription and restricts articles to subscribers holding the right product. No WordPress user accounts are needed for subscribers.
 * Version:           0.1.0
 * Requires at least: 5.6
 * Requires PHP:      8.0
 * Text Domain:       infosub
 */

if (!defined('ABSPATH')) {
    exit;
}

define('INFOSUB_VERSION', '0.1.0');
define('INFOSUB_FILE', __FILE__);
define('INFOSUB_DIR', plugin_dir_path(__FILE__));

/* ----------------------------------------------------------------------
 * Dependencies (firebase/php-jwt through Composer) and classes
 * -------------------------------------------------------------------- */

if (file_exists(INFOSUB_DIR . 'vendor/autoload.php')) {
    require_once INFOSUB_DIR . 'vendor/autoload.php';
}

require_once INFOSUB_DIR . 'includes/class-session.php';
require_once INFOSUB_DIR . 'includes/class-settings.php';
require_once INFOSUB_DIR . 'includes/class-auth.php';
require_once INFOSUB_DIR . 'includes/class-api.php';
require_once INFOSUB_DIR . 'includes/class-gate.php';
require_once INFOSUB_DIR . 'includes/class-meta-box.php';
require_once INFOSUB_DIR . 'includes/class-notices.php';

/* ----------------------------------------------------------------------
 * Activation: refuse to activate on unsupported environments
 * -------------------------------------------------------------------- */

function infosub_activate()
{
    $problems = array();
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        $problems[] = __('INFO-Subscription Access needs PHP 8.0 or newer.', 'infosub');
    }
    if ($problems) {
        deactivate_plugins(plugin_basename(INFOSUB_FILE));
        wp_die(
            esc_html(implode(' ', $problems)),
            esc_html__('Plugin activation failed', 'infosub'),
            array('back_link' => true)
        );
    }
}
register_activation_hook(INFOSUB_FILE, 'infosub_activate');

/* ----------------------------------------------------------------------
 * Boot
 * -------------------------------------------------------------------- */

function infosub_boot()
{
    load_plugin_textdomain('infosub', false, dirname(plugin_basename(INFOSUB_FILE)) . '/languages');

    Infosub_Settings::init();
    Infosub_Auth::init();
    Infosub_Api::init();
    Infosub_Gate::init();
    Infosub_Meta_Box::init();
    Infosub_Notices::init();
}
add_action('plugins_loaded', 'infosub_boot');

/* ----------------------------------------------------------------------
 * Admin conveniences
 * -------------------------------------------------------------------- */

// "Settings" link on the Plugins screen.
add_filter('plugin_action_links_' . plugin_basename(INFOSUB_FILE), function ($links) {
    $url = admin_url('options-general.php?page=' . Infosub_Settings::PAGE);
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'infosub') . '</a>');
    return $links;
});

// Sign-in cannot work without the JWT library, so say so where an administrator will see it.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options') || class_exists('\Infosub\Vendor\Firebase\JWT\JWT')) {
        return;
    }
    echo '<div class="notice notice-error"><p>'
        . esc_html__('INFO-Subscription Access: the firebase/php-jwt library is missing, so sign-in is disabled. Run "composer install" in the plugin folder.', 'infosub')
        . '</p></div>';
});
