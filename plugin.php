<?php
/**
 * Bootstrap file for Bulk Content Management.
 *
 * @package BulkContentManagement
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BCM_VERSION')) {
    define('BCM_VERSION', '1.0.0');
}

if (!defined('BCM_BATCH_SIZE')) {
    define('BCM_BATCH_SIZE', 50);
}

if (!defined('BCM_PLUGIN_DIR')) {
    define('BCM_PLUGIN_DIR', plugin_dir_path(BCM_PLUGIN_FILE));
}

if (!defined('BCM_PLUGIN_URL')) {
    define('BCM_PLUGIN_URL', plugin_dir_url(BCM_PLUGIN_FILE));
}

$autoload = BCM_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

add_action('plugins_loaded', function () {
    load_plugin_textdomain('bulk-content-management', false, dirname(plugin_basename(BCM_PLUGIN_FILE)) . '/languages');
});

require_once BCM_PLUGIN_DIR . 'src/Admin/Assets.php';
require_once BCM_PLUGIN_DIR . 'src/Core/functions.php';

if (is_admin()) {
    require_once BCM_PLUGIN_DIR . 'src/ImportExport/slug-importer.php';
}

add_action('admin_post_bcm_export_terms', 'bcm_export_terms_csv');
add_action('admin_post_bcm_export_posts', 'bcm_export_posts_csv');
add_action('wp_ajax_bcm_generate_terms_batch', 'bcm_ajax_generate_terms_batch');
add_action('wp_ajax_bcm_generate_posts_batch', 'bcm_ajax_generate_posts_batch');

new BCM_Admin_Assets();
