<?php
if (!defined('ABSPATH') || !is_admin()) {
    exit;
}

function bcm_handle_csv_upload() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (
        !isset($_FILES['bcm_csv_file']) ||
        !file_exists($_FILES['bcm_csv_file']['tmp_name']) ||
        !isset($_POST['taxonomy']) ||
        !isset($_POST['term_name']) ||
        !check_admin_referer('bcm_create_terms')
    ) {
        return;
    }

    $taxonomy = sanitize_text_field($_POST['taxonomy']);
    $term_name = sanitize_text_field($_POST['term_name']);
    $file = $_FILES['bcm_csv_file']['tmp_name'];
    $filename = isset($_FILES['bcm_csv_file']['name']) ? sanitize_file_name($_FILES['bcm_csv_file']['name']) : '';

    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
        echo '<div class="notice notice-error"><p>Please upload a valid .csv file.</p></div>';
        return;
    }

    if (!empty($_FILES['bcm_csv_file']['size']) && (int) $_FILES['bcm_csv_file']['size'] > 10485760) {
        echo '<div class="notice notice-error"><p>CSV files must be 10 MB or smaller.</p></div>';
        return;
    }

    if (!is_uploaded_file($file)) {
        echo '<div class="notice notice-error"><p>The uploaded file could not be verified.</p></div>';
        return;
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        echo '<div class="notice notice-error"><p>Could not read the CSV file.</p></div>';
        return;
    }

    $message = '';
    while (($data = fgetcsv($handle)) !== false) {
        $slug = sanitize_title(trim($data[0]));
        if (empty($slug)) continue;

        $result = wp_insert_term($term_name, $taxonomy, ['slug' => $slug]);
        if (is_wp_error($result)) {
            $message .= '<div class="notice notice-error"><p>Error with <code>' . esc_html($slug) . '</code>: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            $message .= '<div class="notice notice-success"><p>Term <code>' . esc_html($slug) . '</code> created.</p></div>';
        }
    }
    fclose($handle);

    echo $message;
}
