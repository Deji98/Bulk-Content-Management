<?php
/**
 * Plugin Name: Bulk Content Management
 * Plugin URI:  github.com/deji98/bulk-content-management
 * Description: Manage, generate, import, export, and assign WordPress posts and taxonomy terms in bulk.
 * Version:     1.0.0
 * Author:      DEJI98
 * Text Domain: bulk-content-management
 * Domain Path: /languages
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package BulkContentManagement
 */

defined('ABSPATH') || exit;

if (!defined('BCM_PLUGIN_FILE')) {
    define('BCM_PLUGIN_FILE', __FILE__);
}

require_once __DIR__ . '/plugin.php';
