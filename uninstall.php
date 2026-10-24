<?php
/**
 * Runs when the plugin is deleted from the Plugins screen (not when it is deactivated).
 *
 * Removes everything the plugin stored in the database: all infosub_* options
 * (connection settings, the cached product list),
 * and the transients used for subscriber sessions, sign-in state and cached
 * OpenID metadata and keys.
 *
 * The access rule on each article (post meta _infosub_product and _infosub_access_mode) is
 * content, so it is kept by default; add this to wp-config.php before deleting
 * the plugin if you want it removed too:
 *
 *     define('INFOSUB_REMOVE_ALL_DATA', true);
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function infosub_uninstall_site()
{
    global $wpdb;

    // Options, and the transients (stored as options) that start with our prefix.
    $patterns = array(
        $wpdb->esc_like('infosub_') . '%',
        $wpdb->esc_like('_transient_infosub_') . '%',
        $wpdb->esc_like('_transient_timeout_infosub_') . '%',
    );
    foreach ($patterns as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    }

    if (defined('INFOSUB_REMOVE_ALL_DATA') && INFOSUB_REMOVE_ALL_DATA) {
        delete_post_meta_by_key('_infosub_product');
        delete_post_meta_by_key('_infosub_access_mode');
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
        switch_to_blog($site_id);
        infosub_uninstall_site();
        restore_current_blog();
    }
} else {
    infosub_uninstall_site();
}
