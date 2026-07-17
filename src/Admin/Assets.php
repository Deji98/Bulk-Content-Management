<?php
/**
 * Admin asset loading.
 *
 * @package BulkContentManagement
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCM_Admin_Assets {
    public function __construct() {
        add_action('admin_menu', 'bcm_add_top_level_menu');
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue($hook) {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $allowed_pages = [
            'bulk-content-management',
            'bcm-bulk-create-terms',
            'bcm-generate-terms',
            'bcm-generate-posts',
            'bcm-import-export',
        ];
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if (!in_array($page, $allowed_pages, true)) {
            return;
        }

        $css_path = BCM_PLUGIN_DIR . 'assets/css/admin.css';
        $js_path = BCM_PLUGIN_DIR . 'assets/js/admin.js';

        wp_enqueue_style(
            'bcm-admin',
            BCM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            file_exists($css_path) ? filemtime($css_path) : BCM_VERSION
        );

        wp_enqueue_script(
            'bcm-admin',
            BCM_PLUGIN_URL . 'assets/js/admin.js',
            [],
            file_exists($js_path) ? filemtime($js_path) : BCM_VERSION,
            true
        );

        wp_localize_script(
            'bcm-admin',
            'bcmAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'strings' => [
                    'starting' => __('Preparing generation...', 'bulk-content-management'),
                    'stopping' => __('Stopping after the current batch...', 'bulk-content-management'),
                    'paused' => __('Batch generation paused.', 'bulk-content-management'),
                    'complete' => __('Final batch complete. Reloading the report...', 'bulk-content-management'),
                    'failed' => __('Generation could not continue.', 'bulk-content-management'),
                ],
            ]
        );
    }
}
