<?php
/**
 * Core procedural functions for Bulk Content Management.
 *
 * @package BulkContentManagement
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcm_add_top_level_menu() {
    add_menu_page(
        'Bulk Content Management',      // Page title
        'BCM',                          // Menu title
        'manage_options',          // Capability
        'bulk-content-management',       // Menu slug
        'bcm_render_overview_page',// Callback function
        'dashicons-tag',           // Icon (you can change this)
        6                         // Position (optional)
    );

    add_submenu_page(
        'bulk-content-management',
        'Home',
        'Home',
        'manage_options',
        'bulk-content-management',
        'bcm_render_overview_page'
    );

    add_submenu_page(
        'bulk-content-management',
        'Bulk Create Terms',
        'Bulk Create Terms',
        'manage_options',
        'bcm-bulk-create-terms',
        'bcm_render_page'
    );

    add_submenu_page(
        'bulk-content-management',
        'Generate Terms',
        'Generate Terms',
        'manage_options',
        'bcm-generate-terms',
        'bcm_render_generator_page'
    );

    add_submenu_page(
        'bulk-content-management',
        'Generate Posts',
        'Generate Posts',
        'manage_options',
        'bcm-generate-posts',
        'bcm_render_post_generator_page'
    );

    add_submenu_page(
        'bulk-content-management',            // Parent slug (MUST match top-level menu slug)
        'Import / Export',              // Page title
        'Import / Export',              // Menu title (in sidebar)
        'manage_options',              // Capability
        'bcm-import-export',           // Menu slug
        'bcm_render_import_export_page'// Callback function to render page
    );    
}


function bcm_get_batch_context($total_count, $offset = 0) {
    $total_count = max(1, absint($total_count));
    $offset = max(0, absint($offset));
    $remaining = max(0, $total_count - $offset);
    $batch_count = min(BCM_BATCH_SIZE, $remaining);

    return [
        'total' => $total_count,
        'offset' => $offset,
        'batch_count' => $batch_count,
        'processed' => $offset + $batch_count,
        'remaining' => max(0, $remaining - $batch_count),
        'is_batched' => $total_count > BCM_BATCH_SIZE,
    ];
}

function bcm_render_continue_batch_fields($fields) {
    foreach ($fields as $name => $value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                echo '<input type="hidden" name="' . esc_attr($name) . '[]" value="' . esc_attr($item) . '">';
            }
            continue;
        }

        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }
}

function bcm_render_collapsible_items($title, $items, $list_id) {
    $items = array_values($items);
    $hidden_count = max(0, count($items) - 100);

    $html = '<div class="notice notice-info bcm-collapsible-results"><p><strong>' . esc_html($title) . '</strong></p><ol id="' . esc_attr($list_id) . '">';

    foreach ($items as $index => $item) {
        $hidden = $index >= 100 ? ' class="bcm-extra-result" style="display:none;"' : '';
        $html .= '<li' . $hidden . '>' . $item . '</li>';
    }

    $html .= '</ol>';

    if ($hidden_count > 0) {
        $html .= '<p><a href="#" class="bcm-see-more" data-target="' . esc_attr($list_id) . '">See more (' . esc_html($hidden_count) . ')</a> <a href="#" class="bcm-see-less" data-target="' . esc_attr($list_id) . '" style="display:none;">See less</a></p>';
    }

    $html .= '</div>';

    return $html;
}

function bcm_render_auto_batch_notice($batch, $fields, $nonce_action, $form_id, $item_label) {
    $total_batches = (int) ceil($batch['total'] / BCM_BATCH_SIZE);
    $completed_batch = (int) ceil($batch['processed'] / BCM_BATCH_SIZE);

    $html = '<div class="notice notice-warning bcm-auto-batch-notice" data-form-id="' . esc_attr($form_id) . '">';
    $html .= '<p><strong>Batch ' . esc_html($completed_batch) . ' of ' . esc_html($total_batches) . ' complete.</strong> Processed ' . esc_html($batch['processed']) . ' of ' . esc_html($batch['total']) . ' ' . esc_html($item_label) . '. Next batch starts automatically.</p>';
    $html .= '<p><a href="#" class="bcm-pause-batch">Pause</a> | <a href="' . esc_url(remove_query_arg(['bcm_paused'])) . '">Stop</a></p>';
    $html .= '<form method="post" id="' . esc_attr($form_id) . '" class="bcm-auto-batch-form">';
    ob_start();
    wp_nonce_field($nonce_action);
    bcm_render_continue_batch_fields($fields);
    $html .= ob_get_clean();
    $html .= '</form></div>';

    return $html;
}

function bcm_get_generation_run_id($run_id = '') {
    $run_id = sanitize_key($run_id);

    if ($run_id) {
        return $run_id;
    }

    return sanitize_key(wp_generate_uuid4());
}

function bcm_get_generation_transient_key($run_id) {
    return 'bcm_generation_' . get_current_user_id() . '_' . md5($run_id);
}

function bcm_get_generation_result_url($page_slug, $run_id, $status = 'complete') {
    return add_query_arg(
        [
            'page' => sanitize_key($page_slug),
            'bcm_generation_result' => sanitize_key($run_id),
            'bcm_generation_status' => sanitize_key($status),
            '_wpnonce' => wp_create_nonce('bcm_generation_result_' . $run_id),
        ],
        admin_url('admin.php')
    );
}

function bcm_get_generation_summary($run_id, $type) {
    $summary = get_transient(bcm_get_generation_transient_key($run_id));

    if (!is_array($summary)) {
        $summary = [];
    }

    return wp_parse_args(
        $summary,
        [
            'type' => $type,
            'created' => [],
            'errors' => [],
            'assigned_post_ids' => [],
            'total' => 0,
            'processed' => 0,
            'label' => $type === 'posts' ? 'posts' : 'terms',
        ]
    );
}

function bcm_store_generation_summary($run_id, $summary) {
    set_transient(bcm_get_generation_transient_key($run_id), $summary, HOUR_IN_SECONDS);
}

function bcm_append_terms_generation_summary($run_id, $result, $assignment, $total, $processed) {
    $summary = bcm_get_generation_summary($run_id, 'terms');
    $summary['total'] = absint($total);
    $summary['processed'] = absint($processed);

    foreach ($result['created'] as $created) {
        $summary['created'][] = [
            'name' => $created['name'],
            'depth' => (int) $created['depth'],
        ];
    }

    if (!empty($assignment['assigned'])) {
        $summary['assigned_post_ids'] = array_values(array_unique(array_merge(
            bcm_sanitize_ids($summary['assigned_post_ids']),
            bcm_sanitize_ids($assignment['assigned'])
        )));
    }

    $summary['errors'] = array_merge($summary['errors'], $result['errors'], $assignment['errors']);
    bcm_store_generation_summary($run_id, $summary);

    return $summary;
}

function bcm_append_posts_generation_summary($run_id, $result, $total, $processed) {
    $summary = bcm_get_generation_summary($run_id, 'posts');
    $summary['total'] = absint($total);
    $summary['processed'] = absint($processed);

    foreach ($result['created'] as $created) {
        $summary['created'][] = [
            'title' => $created['title'],
            'post_id' => (int) $created['post_id'],
        ];
    }

    $summary['errors'] = array_merge($summary['errors'], $result['errors']);
    bcm_store_generation_summary($run_id, $summary);

    return $summary;
}

function bcm_render_generation_summary($summary, $run_id, $status = 'complete') {
    $created_count = count($summary['created']);
    $error_count = count($summary['errors']);
    $is_stopped = $status === 'stopped';
    $notice_class = $is_stopped ? 'notice-warning' : 'notice-success';
    $title = $is_stopped ? 'Generation stopped.' : 'Generation complete.';
    $item_label = $summary['type'] === 'posts' ? 'posts' : 'terms';
    $html = '<div class="notice ' . esc_attr($notice_class) . '"><p><strong>' . esc_html($title) . '</strong> Created ' . esc_html($created_count) . ' ' . esc_html($item_label) . '.</p>';

    if ($summary['type'] === 'terms' && !empty($summary['assigned_post_ids'])) {
        $html .= '<p>Added generated terms to ' . esc_html(count($summary['assigned_post_ids'])) . ' selected post(s).</p>';
    }

    if ($error_count > 0) {
        $html .= '<p><strong>' . esc_html($error_count) . ' issue(s) were reported.</strong></p><ul>';
        foreach ($summary['errors'] as $error) {
            $html .= '<li>' . esc_html($error) . '</li>';
        }
        $html .= '</ul>';
    }

    $html .= '</div>';

    if (!empty($summary['created'])) {
        $items = [];

        foreach ($summary['created'] as $created) {
            if ($summary['type'] === 'posts') {
                $items[] = esc_html($created['title']) . ' #' . esc_html($created['post_id']);
            } else {
                $indent = str_repeat('&mdash; ', max(0, (int) $created['depth'] - 1));
                $items[] = $indent . esc_html($created['name']);
            }
        }

        $html .= bcm_render_collapsible_items(
            'Created ' . $item_label . ':',
            $items,
            'bcm-generated-' . sanitize_key($summary['type']) . '-' . sanitize_key($run_id)
        );
    }

    return $html;
}

function bcm_render_generation_summary_from_query($expected_type) {
    if (empty($_GET['bcm_generation_result'])) {
        return '';
    }

    $run_id = sanitize_key(wp_unslash($_GET['bcm_generation_result']));
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

    if (!$run_id || !wp_verify_nonce($nonce, 'bcm_generation_result_' . $run_id)) {
        return '';
    }

    $summary = get_transient(bcm_get_generation_transient_key($run_id));

    if (!is_array($summary) || empty($summary['type']) || $summary['type'] !== $expected_type) {
        return '';
    }

    delete_transient(bcm_get_generation_transient_key($run_id));

    $status = isset($_GET['bcm_generation_status']) ? sanitize_key(wp_unslash($_GET['bcm_generation_status'])) : 'complete';

    return bcm_render_generation_summary($summary, $run_id, $status);
}

function bcm_render_tool_page_open($title, $subtitle, $icon = 'dashicons-admin-tools', $class = '') {
    echo '<div class="wrap bcm-tool-wrap ' . esc_attr($class) . '">
        <section class="bcm-tool-shell">
            <header class="bcm-tool-hero">
                <div class="bcm-tool-hero-icon"><span class="dashicons ' . esc_attr($icon) . '"></span></div>
                <div>
                    <p class="bcm-home-eyebrow">Bulk Content Management</p>
                    <h1>' . esc_html($title) . '</h1>
                    <p>' . esc_html($subtitle) . '</p>
                </div>
            </header>';
}

function bcm_render_tool_page_close() {
    echo '</section></div>';
}

function bcm_render_panel_open($title, $icon = 'dashicons-admin-generic') {
    echo '<section class="bcm-modern-card"><div class="bcm-modern-card-header"><span class="dashicons ' . esc_attr($icon) . '"></span><h2>' . esc_html($title) . '</h2></div><div class="bcm-modern-card-body">';
}

function bcm_render_panel_close() {
    echo '</div></section>';
}

function bcm_render_overview_page() {
    if (!current_user_can('manage_options')) return;

    $cards = [
        [
            'icon' => 'dashicons-list-view',
            'title' => 'Bulk Create Terms',
            'text' => 'Create many terms from names or slugs when you already know the structure you want.',
            'url' => admin_url('admin.php?page=bcm-bulk-create-terms'),
            'label' => 'Open creator',
        ],
        [
            'icon' => 'dashicons-randomize',
            'title' => 'Generate Terms',
            'text' => 'Generate flat or nested taxonomy terms locally, then optionally attach them to posts.',
            'url' => admin_url('admin.php?page=bcm-generate-terms'),
            'label' => 'Generate terms',
        ],
        [
            'icon' => 'dashicons-admin-page',
            'title' => 'Generate Posts',
            'text' => 'Create posts in batches and assign existing terms using selected or random modes.',
            'url' => admin_url('admin.php?page=bcm-generate-posts'),
            'label' => 'Generate posts',
        ],
        [
            'icon' => 'dashicons-database-import',
            'title' => 'Import / Export',
            'text' => 'Move terms and posts in or out with focused CSV import/export tabs.',
            'url' => admin_url('admin.php?page=bcm-import-export'),
            'label' => 'Manage data',
        ],
    ];

    require BCM_PLUGIN_DIR . 'templates/admin/home.php';
}

function bcm_get_taxonomy_options($hierarchical_only = false) {
    $args = ['public' => true];

    if ($hierarchical_only) {
        $args['hierarchical'] = true;
    }

    return get_taxonomies($args, 'objects');
}

function bcm_parse_generator_prompt($prompt) {
    $parsed = [
        'count' => null,
        'max_depth' => null,
        'taxonomy' => null,
        'style' => null,
        'structure' => null,
        'post_ids' => [],
        'post_titles' => [],
    ];

    if (preg_match('/\b(\d{1,5})\s+(?:random\s+)?(?:terms?|tags?|categories)\b/i', $prompt, $matches)) {
        $parsed['count'] = (int) $matches[1];
    } elseif (preg_match('/\b(?:create|generate|make)\D{0,40}(\d{1,5})\b/i', $prompt, $matches)) {
        $parsed['count'] = (int) $matches[1];
    }

    if (preg_match('/(?:up\s+to\s+)?(\d{1,2})\s+levels?\s+deep/i', $prompt, $matches)) {
        $parsed['max_depth'] = (int) $matches[1];
    } elseif (preg_match('/(?:depth|deep|levels?)\D+(\d{1,2})/i', $prompt, $matches)) {
        $parsed['max_depth'] = (int) $matches[1];
    }

    if (preg_match('/\b(category|categories|post_tag|tag|tags)\b/i', $prompt, $matches)) {
        $parsed['taxonomy'] = in_array(strtolower($matches[1]), ['tag', 'tags'], true) ? 'post_tag' : 'category';
    }

    if (preg_match('/\b(?:assign|add|attach)\b.*?\b(?:to\s+)?(?:posts?|pages?|content|post\s+ids?)\b(.+)/i', $prompt, $matches)) {
        $assignment_text = $matches[1];

        if (preg_match_all('/#?\b(\d+)\b/', $assignment_text, $id_matches)) {
            $parsed['post_ids'] = bcm_sanitize_ids($id_matches[1]);
        }

        if (preg_match_all('/["“”\']([^"“”\']+)["“”\']/', $assignment_text, $title_matches)) {
            $parsed['post_titles'] = array_values(array_unique(array_map('sanitize_text_field', $title_matches[1])));
        }
    }

    if (preg_match('/\b(flat|non[-\s]?hierarchical|not\s+hierarchical|no\s+hierarchy|tags?)\b/i', $prompt)) {
        $parsed['structure'] = 'flat';
    } elseif (preg_match('/\b(hierarchical|hierarchy|nested|parent[-\s]?child|levels?)\b/i', $prompt)) {
        $parsed['structure'] = 'hierarchical';
    }

    if (preg_match('/\b(continent|country|city|cities|region|location|place|places|geography|geographic)\b/i', $prompt)) {
        $parsed['style'] = 'places';
    } elseif (preg_match('/\b(product|store|shop|commerce|woocommerce|catalog)\b/i', $prompt)) {
        $parsed['style'] = 'commerce';
    } elseif (preg_match('/\b(topic|knowledge|course|lesson|subject|content)\b/i', $prompt)) {
        $parsed['style'] = 'topics';
    }

    return $parsed;
}

function bcm_get_generator_bank($style) {
    $banks = [
        'places' => [
            ['Africa', 'Americas', 'Asia', 'Europe', 'Oceania', 'Northern Region', 'Southern Region', 'Eastern Region', 'Western Region'],
            ['Nigeria', 'Ghana', 'Kenya', 'Brazil', 'Canada', 'Japan', 'India', 'France', 'Spain', 'Australia', 'Mexico', 'Egypt'],
            ['Lagos', 'Accra', 'Nairobi', 'Recife', 'Toronto', 'Osaka', 'Mumbai', 'Lyon', 'Valencia', 'Sydney', 'Cairo', 'Monterrey'],
            ['Central District', 'Harbor Quarter', 'Old Town', 'Market Zone', 'Garden Ward', 'Tech Corridor', 'Riverside', 'North End'],
        ],
        'commerce' => [
            ['Electronics', 'Home', 'Fashion', 'Outdoors', 'Beauty', 'Automotive', 'Books', 'Sports', 'Office'],
            ['Accessories', 'Appliances', 'Footwear', 'Furniture', 'Skincare', 'Tools', 'Fiction', 'Training Gear'],
            ['Premium', 'Budget', 'Compact', 'Professional', 'Eco', 'Classic', 'Portable', 'Smart', 'Heavy Duty'],
            ['Starter Kits', 'Replacement Parts', 'Bundles', 'Limited Editions', 'Refills', 'Clearance', 'Gift Sets'],
        ],
        'topics' => [
            ['Business', 'Technology', 'Health', 'Education', 'Culture', 'Science', 'Finance', 'Travel'],
            ['Strategy', 'Software', 'Nutrition', 'Learning', 'Design', 'Research', 'Investing', 'Planning'],
            ['Beginner', 'Advanced', 'Case Studies', 'Tools', 'Frameworks', 'Trends', 'Guides', 'Templates'],
            ['Checklists', 'Examples', 'Resources', 'Workshops', 'Reports', 'Reviews', 'Playbooks', 'FAQs'],
        ],
        'generic' => [
            ['Alpha', 'Atlas', 'Beacon', 'Cedar', 'Delta', 'Echo', 'Harbor', 'Iris', 'Juno', 'Keystone'],
            ['North', 'South', 'East', 'West', 'Central', 'Upper', 'Lower', 'Prime', 'Core', 'Outer'],
            ['Group', 'Series', 'Cluster', 'Branch', 'Collection', 'Division', 'Range', 'Set', 'Class'],
            ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'],
        ],
    ];

    return isset($banks[$style]) ? $banks[$style] : $banks['generic'];
}

function bcm_random_bank_value($values, $index) {
    return $values[$index % count($values)];
}

function bcm_make_generated_term_name($style, $depth, $index, $parent_name = '') {
    $bank = bcm_get_generator_bank($style);
    $level = min($depth - 1, count($bank) - 1);
    $name = bcm_random_bank_value($bank[$level], $index);

    if ($style === 'generic') {
        return trim($name . ' ' . ($index + 1));
    }

    if ($depth > 1 && $parent_name) {
        $name = $parent_name . ' - ' . $name;
    }

    return $name . ' ' . ($index + 1);
}

function bcm_insert_generated_term($name, $taxonomy, $parent_id = 0, $use_hierarchy = false) {
    $base_name = $name;
    $suffix = 2;
    $term_exists_parent = $use_hierarchy ? (int) $parent_id : null;

    while (term_exists($name, $taxonomy, $term_exists_parent)) {
        $name = $base_name . ' ' . $suffix;
        $suffix++;
    }

    $args = [];
    if ($use_hierarchy) {
        $args['parent'] = (int) $parent_id;
    }

    return wp_insert_term($name, $taxonomy, $args);
}

function bcm_sanitize_ids($ids) {
    if (!is_array($ids)) {
        $ids = [$ids];
    }

    $clean_ids = [];
    foreach ($ids as $id) {
        $id = absint($id);

        if ($id > 0) {
            $clean_ids[] = $id;
        }
    }

    return array_values(array_unique($clean_ids));
}

function bcm_get_taxonomy_post_types($taxonomy) {
    $taxonomy_object = get_taxonomy($taxonomy);

    if (!$taxonomy_object || empty($taxonomy_object->object_type)) {
        return [];
    }

    return array_values(array_filter((array) $taxonomy_object->object_type, 'post_type_exists'));
}

function bcm_resolve_post_titles($post_titles, $taxonomy) {
    $resolved_ids = [];
    $errors = [];
    $post_types = bcm_get_taxonomy_post_types($taxonomy);

    if (empty($post_titles) || empty($post_types)) {
        return [
            'post_ids' => $resolved_ids,
            'errors' => $errors,
        ];
    }

    foreach ($post_titles as $post_title) {
        $post_title = trim(wp_strip_all_tags($post_title));

        if ($post_title === '') {
            continue;
        }

        $matches = get_posts([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'title' => $post_title,
            'numberposts' => 2,
        ]);

        if (count($matches) === 1) {
            $resolved_ids[] = (int) $matches[0]->ID;
            continue;
        }

        if (count($matches) > 1) {
            $errors[] = 'More than one compatible post is named "' . $post_title . '". Select it from the post list instead.';
            continue;
        }

        $errors[] = 'No compatible post named "' . $post_title . '" was found.';
    }

    return [
        'post_ids' => array_values(array_unique($resolved_ids)),
        'errors' => $errors,
    ];
}

function bcm_get_assignable_posts($taxonomy, $selected_post_ids = []) {
    $post_types = bcm_get_taxonomy_post_types($taxonomy);

    if (empty($post_types)) {
        return [];
    }

    $posts = get_posts([
        'post_type' => $post_types,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts' => 200,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if (!empty($selected_post_ids)) {
        $selected_posts = get_posts([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'post__in' => $selected_post_ids,
            'numberposts' => count($selected_post_ids),
            'orderby' => 'post__in',
        ]);

        $posts_by_id = [];
        foreach (array_merge($selected_posts, $posts) as $post) {
            $posts_by_id[$post->ID] = $post;
        }

        return array_values($posts_by_id);
    }

    return $posts;
}

function bcm_assign_terms_to_posts($post_ids, $term_ids, $taxonomy) {
    $assigned = [];
    $errors = [];

    $post_ids = bcm_sanitize_ids($post_ids);
    $term_ids = bcm_sanitize_ids($term_ids);
    $taxonomy_object = get_taxonomy($taxonomy);
    $allowed_post_types = $taxonomy_object ? (array) $taxonomy_object->object_type : [];

    if (empty($post_ids) || empty($term_ids)) {
        return [
            'assigned' => $assigned,
            'errors' => $errors,
        ];
    }

    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);

        if (!$post) {
            $errors[] = 'Post ID ' . $post_id . ' was not found.';
            continue;
        }

        if (!empty($allowed_post_types) && !in_array($post->post_type, $allowed_post_types, true)) {
            $errors[] = get_the_title($post_id) . ' does not use the selected taxonomy.';
            continue;
        }

        $result = wp_set_object_terms($post_id, $term_ids, $taxonomy, true);

        if (is_wp_error($result)) {
            $errors[] = get_the_title($post_id) . ': ' . $result->get_error_message();
            continue;
        }

        $assigned[] = $post_id;
    }

    return [
        'assigned' => $assigned,
        'errors' => $errors,
    ];
}

function bcm_generate_terms($taxonomy, $count, $max_depth, $style, $structure, $start_index = 0) {
    $created = [];
    $errors = [];
    $nodes_by_depth = [];
    $parent_id = 0;
    $parent_name = '';
    $index = absint($start_index);

    if (!taxonomy_exists($taxonomy)) {
        return [
            'created' => [],
            'errors' => ['Invalid taxonomy selected.'],
        ];
    }

    $taxonomy_is_hierarchical = is_taxonomy_hierarchical($taxonomy);
    $use_hierarchy = $structure === 'hierarchical' || ($structure === 'auto' && $taxonomy_is_hierarchical && $max_depth > 1);

    if ($use_hierarchy && !$taxonomy_is_hierarchical) {
        return [
            'created' => [],
            'errors' => ['The selected taxonomy does not support hierarchy. Choose a hierarchical taxonomy or switch Term Structure to Flat.'],
        ];
    }

    if (!$use_hierarchy) {
        for ($local_index = 0; $local_index < $count; $local_index++) {
            $name = bcm_make_generated_term_name($style, 1, $index + $local_index);
            $result = bcm_insert_generated_term($name, $taxonomy, 0, false);

            if (is_wp_error($result)) {
                $errors[] = $name . ': ' . $result->get_error_message();
                continue;
            }

            $created[] = [
                'term_id' => (int) $result['term_id'],
                'name' => $name,
                'depth' => 1,
                'parent' => '',
            ];
        }

        return [
            'created' => $created,
            'errors' => $errors,
        ];
    }

    $chain_depth = min($count, $max_depth);
    for ($depth = 1; $depth <= $chain_depth; $depth++) {
        $name = bcm_make_generated_term_name($style, $depth, $index, $parent_name);
        $result = bcm_insert_generated_term($name, $taxonomy, $parent_id, true);

        if (is_wp_error($result)) {
            $errors[] = $name . ': ' . $result->get_error_message();
            break;
        }

        $term_id = (int) $result['term_id'];
        $nodes_by_depth[$depth][] = [
            'term_id' => $term_id,
            'name' => $name,
        ];
        $created[] = [
            'term_id' => $term_id,
            'name' => $name,
            'depth' => $depth,
            'parent' => $parent_name,
        ];
        $parent_id = $term_id;
        $parent_name = $name;
        $index++;
    }

    while (count($created) < $count && $index < ($start_index + ($count * 3))) {
        if (empty($nodes_by_depth)) {
            break;
        }

        $available_depths = array_keys($nodes_by_depth);
        $parent_depth = $available_depths[array_rand($available_depths)];

        if ($parent_depth >= $max_depth || mt_rand(1, 4) === 1) {
            $depth = 1;
            $parent_id = 0;
            $parent_name = '';
        } else {
            $parent_node = $nodes_by_depth[$parent_depth][array_rand($nodes_by_depth[$parent_depth])];
            $depth = $parent_depth + 1;
            $parent_id = (int) $parent_node['term_id'];
            $parent_name = $parent_node['name'];
        }

        $name = bcm_make_generated_term_name($style, $depth, $index, $parent_name);
        $result = bcm_insert_generated_term($name, $taxonomy, $parent_id, true);

        if (is_wp_error($result)) {
            $errors[] = $name . ': ' . $result->get_error_message();
            $index++;
            continue;
        }

        $nodes_by_depth[$depth][] = [
            'term_id' => (int) $result['term_id'],
            'name' => $name,
        ];
        $created[] = [
            'term_id' => (int) $result['term_id'],
            'name' => $name,
            'depth' => $depth,
            'parent' => $parent_name,
        ];
        $index++;
    }

    if (count($created) < $count) {
        $errors[] = 'Stopped before reaching the requested count because too many generated names collided.';
    }

    return [
        'created' => $created,
        'errors' => $errors,
    ];
}

function bcm_get_public_post_type_options() {
    return get_post_types(['public' => true], 'objects');
}

function bcm_get_taxonomies_for_post_type($post_type) {
    if (!post_type_exists($post_type)) {
        return [];
    }

    return get_object_taxonomies($post_type, 'objects');
}

function bcm_parse_post_generator_prompt($prompt) {
    $parsed = [
        'count' => null,
        'post_type' => null,
        'status' => null,
        'style' => null,
        'taxonomy' => null,
        'term_mode' => null,
        'terms_per_post' => null,
    ];

    if (preg_match('/\b(\d{1,5})\s+(?:random\s+)?(?:posts?|pages?|articles?)\b/i', $prompt, $matches)) {
        $parsed['count'] = (int) $matches[1];
    } elseif (preg_match('/\b(?:create|generate|make)\D{0,40}(\d{1,5})\b/i', $prompt, $matches)) {
        $parsed['count'] = (int) $matches[1];
    }

    if (preg_match('/\bpages?\b/i', $prompt)) {
        $parsed['post_type'] = 'page';
    } elseif (preg_match('/\bposts?|articles?\b/i', $prompt)) {
        $parsed['post_type'] = 'post';
    }

    if (preg_match('/\b(publish|published)\b/i', $prompt)) {
        $parsed['status'] = 'publish';
    } elseif (preg_match('/\b(draft|drafts)\b/i', $prompt)) {
        $parsed['status'] = 'draft';
    } elseif (preg_match('/\bprivate\b/i', $prompt)) {
        $parsed['status'] = 'private';
    }

    if (preg_match('/\b(category|categories|post_tag|tag|tags)\b/i', $prompt, $matches)) {
        $parsed['taxonomy'] = in_array(strtolower($matches[1]), ['tag', 'tags'], true) ? 'post_tag' : 'category';
    }

    if (preg_match('/\b(?:assign|add|choose)\b.*?\b(?:random|different|existing)\b/i', $prompt)) {
        $parsed['term_mode'] = 'random_existing';
    } elseif (preg_match('/\b(?:assign|add)\b.*?\b(?:selected|chosen)\b/i', $prompt)) {
        $parsed['term_mode'] = 'selected_all';
    }

    if (preg_match('/\b(\d{1,3})\s+(?:random\s+)?(?:different\s+)?(?:existing\s+)?terms?\b/i', $prompt, $matches)) {
        $parsed['terms_per_post'] = (int) $matches[1];
    }

    if (preg_match('/\b(product|store|shop|commerce|woocommerce|catalog)\b/i', $prompt)) {
        $parsed['style'] = 'commerce';
    } elseif (preg_match('/\b(continent|country|city|travel|location|place|places)\b/i', $prompt)) {
        $parsed['style'] = 'places';
    } elseif (preg_match('/\b(topic|knowledge|course|lesson|subject|content|article)\b/i', $prompt)) {
        $parsed['style'] = 'topics';
    }

    return $parsed;
}

function bcm_make_generated_post_title($style, $index) {
    $bank = bcm_get_generator_bank($style);
    $primary = bcm_random_bank_value($bank[0], $index);
    $secondary = bcm_random_bank_value($bank[1], $index);

    if ($style === 'commerce') {
        return $primary . ' Buying Guide ' . ($index + 1);
    }

    if ($style === 'places') {
        return $primary . ' Travel Notes ' . ($index + 1);
    }

    if ($style === 'topics') {
        return $primary . ' ' . $secondary . ' Guide ' . ($index + 1);
    }

    return $primary . ' ' . $secondary . ' Post ' . ($index + 1);
}

function bcm_get_default_generated_post_content() {
    return 'This post is auto generated by Bulk Content Management.';
}

function bcm_prepare_generated_post_content($content, $title, $index) {
    $content = trim((string) $content);

    if ($content === '') {
        $content = bcm_get_default_generated_post_content();
    }

    $content = str_replace(
        ['{title}', '{index}'],
        [$title, (string) ($index + 1)],
        $content
    );

    return wpautop(wp_kses_post($content));
}

function bcm_make_generated_post_content($style, $index, $custom_content = '') {
    $title = bcm_make_generated_post_title($style, $index);

    return bcm_prepare_generated_post_content($custom_content, $title, $index);
}

function bcm_get_terms_for_assignment($taxonomy, $selected_term_ids = []) {
    if (!taxonomy_exists($taxonomy)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'number' => 500,
    ]);

    if (is_wp_error($terms)) {
        return [];
    }

    $selected_term_ids = bcm_sanitize_ids($selected_term_ids);

    if (!empty($selected_term_ids)) {
        $selected_terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'include' => $selected_term_ids,
        ]);

        if (!is_wp_error($selected_terms)) {
            $terms_by_id = [];
            foreach (array_merge($selected_terms, $terms) as $term) {
                $terms_by_id[$term->term_id] = $term;
            }

            return array_values($terms_by_id);
        }
    }

    return $terms;
}

function bcm_pick_term_ids_for_post($mode, $selected_term_ids, $available_term_ids, $terms_per_post) {
    $selected_term_ids = bcm_sanitize_ids($selected_term_ids);
    $available_term_ids = bcm_sanitize_ids($available_term_ids);
    $terms_per_post = max(1, absint($terms_per_post));

    if ($mode === 'selected_all') {
        return $selected_term_ids;
    }

    if ($mode === 'random_selected') {
        $pool = $selected_term_ids;
    } elseif ($mode === 'random_existing') {
        $pool = $available_term_ids;
    } else {
        return [];
    }

    if (empty($pool)) {
        return [];
    }

    shuffle($pool);

    return array_slice($pool, 0, min($terms_per_post, count($pool)));
}

function bcm_generate_posts($post_type, $status, $count, $style, $taxonomy, $term_mode, $selected_term_ids, $terms_per_post, $start_index = 0, $custom_content = '') {
    $created = [];
    $errors = [];
    $start_index = absint($start_index);
    $allowed_statuses = ['draft', 'publish', 'private', 'pending'];

    if (!post_type_exists($post_type)) {
        return [
            'created' => [],
            'errors' => ['Invalid post type selected.'],
        ];
    }

    $status = in_array($status, $allowed_statuses, true) ? $status : 'draft';
    $available_term_ids = [];

    if ($term_mode !== 'none') {
        if (!taxonomy_exists($taxonomy)) {
            $errors[] = 'Invalid taxonomy selected for term assignment.';
            $term_mode = 'none';
        } elseif (!is_object_in_taxonomy($post_type, $taxonomy)) {
            $errors[] = 'The selected taxonomy is not assigned to the selected post type.';
            $term_mode = 'none';
        } else {
            $available_terms = bcm_get_terms_for_assignment($taxonomy, $selected_term_ids);
            $available_term_ids = bcm_sanitize_ids(wp_list_pluck($available_terms, 'term_id'));
        }
    }

    for ($local_index = 0; $local_index < $count; $local_index++) {
        $index = $start_index + $local_index;
        $title = bcm_make_generated_post_title($style, $index);
        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => bcm_make_generated_post_content($style, $index, $custom_content),
            'post_type' => $post_type,
            'post_status' => $status,
        ], true);

        if (is_wp_error($post_id)) {
            $errors[] = $title . ': ' . $post_id->get_error_message();
            continue;
        }

        $assigned_term_ids = bcm_pick_term_ids_for_post($term_mode, $selected_term_ids, $available_term_ids, $terms_per_post);

        if (!empty($assigned_term_ids)) {
            $term_result = wp_set_object_terms($post_id, $assigned_term_ids, $taxonomy, true);

            if (is_wp_error($term_result)) {
                $errors[] = $title . ': ' . $term_result->get_error_message();
            }
        }

        $created[] = [
            'post_id' => (int) $post_id,
            'title' => $title,
            'terms' => $assigned_term_ids,
        ];
    }

    return [
        'created' => $created,
        'errors' => $errors,
    ];
}

function bcm_get_terms_generation_request_from_post() {
    $prompt = isset($_POST['bcm_generator_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['bcm_generator_prompt'])) : '';
    $parsed = bcm_parse_generator_prompt($prompt);
    $submitted_taxonomy = isset($_POST['bcm_generator_taxonomy']) ? sanitize_text_field(wp_unslash($_POST['bcm_generator_taxonomy'])) : 'category';
    $submitted_count = isset($_POST['bcm_generator_count']) ? absint($_POST['bcm_generator_count']) : 56;
    $submitted_depth = isset($_POST['bcm_generator_depth']) ? absint($_POST['bcm_generator_depth']) : 4;
    $submitted_style = isset($_POST['bcm_generator_style']) ? sanitize_key(wp_unslash($_POST['bcm_generator_style'])) : 'generic';
    $submitted_structure = isset($_POST['bcm_generator_structure']) ? sanitize_key(wp_unslash($_POST['bcm_generator_structure'])) : 'auto';
    $submitted_post_ids = isset($_POST['bcm_assign_posts']) ? bcm_sanitize_ids(wp_unslash($_POST['bcm_assign_posts'])) : [];

    $taxonomy = $parsed['taxonomy'] ?: $submitted_taxonomy;
    $count = $parsed['count'] ?: $submitted_count;
    $max_depth = $parsed['max_depth'] ?: $submitted_depth;
    $style = $parsed['style'] ?: $submitted_style;
    $structure = $parsed['structure'] ?: $submitted_structure;
    $resolved_posts = bcm_resolve_post_titles($parsed['post_titles'], $taxonomy);

    return [
        'prompt' => $prompt,
        'taxonomy' => $taxonomy,
        'count' => max(1, min(10000, $count)),
        'max_depth' => max(1, min(10, $max_depth)),
        'style' => $style,
        'structure' => in_array($structure, ['auto', 'hierarchical', 'flat'], true) ? $structure : 'auto',
        'selected_post_ids' => bcm_sanitize_ids(array_merge($submitted_post_ids, $parsed['post_ids'], $resolved_posts['post_ids'])),
        'resolved_errors' => $resolved_posts['errors'],
        'batch_offset' => isset($_POST['bcm_batch_offset']) ? absint($_POST['bcm_batch_offset']) : 0,
        'run_id' => bcm_get_generation_run_id(isset($_POST['bcm_generation_run_id']) ? wp_unslash($_POST['bcm_generation_run_id']) : ''),
    ];
}

function bcm_get_posts_generation_request_from_post() {
    $prompt = isset($_POST['bcm_post_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['bcm_post_prompt'])) : '';
    $parsed = bcm_parse_post_generator_prompt($prompt);
    $submitted_post_type = isset($_POST['bcm_post_type']) ? sanitize_key(wp_unslash($_POST['bcm_post_type'])) : 'post';
    $submitted_status = isset($_POST['bcm_post_status']) ? sanitize_key(wp_unslash($_POST['bcm_post_status'])) : 'draft';
    $submitted_count = isset($_POST['bcm_post_count']) ? absint($_POST['bcm_post_count']) : 10;
    $submitted_style = isset($_POST['bcm_post_style']) ? sanitize_key(wp_unslash($_POST['bcm_post_style'])) : 'generic';
    $submitted_content = isset($_POST['bcm_post_content']) ? wp_kses_post(wp_unslash($_POST['bcm_post_content'])) : bcm_get_default_generated_post_content();
    $submitted_taxonomy = isset($_POST['bcm_post_taxonomy']) ? sanitize_key(wp_unslash($_POST['bcm_post_taxonomy'])) : 'category';
    $submitted_term_mode = isset($_POST['bcm_post_term_mode']) ? sanitize_key(wp_unslash($_POST['bcm_post_term_mode'])) : 'none';
    $submitted_terms_per_post = isset($_POST['bcm_terms_per_post']) ? absint($_POST['bcm_terms_per_post']) : 3;
    $selected_term_ids = isset($_POST['bcm_post_terms']) ? bcm_sanitize_ids(wp_unslash($_POST['bcm_post_terms'])) : [];

    $post_type = $parsed['post_type'] ?: $submitted_post_type;
    $status = $parsed['status'] ?: $submitted_status;
    $count = $parsed['count'] ?: $submitted_count;
    $style = $parsed['style'] ?: $submitted_style;
    $taxonomy = $parsed['taxonomy'] ?: $submitted_taxonomy;
    $term_mode = $parsed['term_mode'] ?: $submitted_term_mode;
    $terms_per_post = $parsed['terms_per_post'] ?: $submitted_terms_per_post;
    $post_types = bcm_get_public_post_type_options();

    if (!isset($post_types[$post_type])) {
        $post_type = 'post';
    }

    $taxonomies = bcm_get_taxonomies_for_post_type($post_type);
    if (!isset($taxonomies[$taxonomy]) && !empty($taxonomies)) {
        $taxonomy_keys = array_keys($taxonomies);
        $taxonomy = reset($taxonomy_keys);
    }

    if (empty($taxonomies)) {
        $term_mode = 'none';
    }

    return [
        'prompt' => $prompt,
        'post_type' => $post_type,
        'status' => in_array($status, ['draft', 'publish', 'private', 'pending'], true) ? $status : 'draft',
        'count' => max(1, min(10000, $count)),
        'style' => $style,
        'content' => $submitted_content,
        'taxonomy' => $taxonomy,
        'term_mode' => in_array($term_mode, ['none', 'selected_all', 'random_existing', 'random_selected'], true) ? $term_mode : 'none',
        'selected_term_ids' => $selected_term_ids,
        'terms_per_post' => max(1, min(100, $terms_per_post)),
        'batch_offset' => isset($_POST['bcm_post_batch_offset']) ? absint($_POST['bcm_post_batch_offset']) : 0,
        'run_id' => bcm_get_generation_run_id(isset($_POST['bcm_generation_run_id']) ? wp_unslash($_POST['bcm_generation_run_id']) : ''),
    ];
}

function bcm_send_generation_batch_json($page_slug, $run_id, $batch, $summary, $item_label, $complete) {
    $total_batches = (int) ceil($batch['total'] / BCM_BATCH_SIZE);
    $current_batch = (int) ceil($batch['processed'] / BCM_BATCH_SIZE);

    wp_send_json_success([
        'runId' => $run_id,
        'processed' => $batch['processed'],
        'total' => $batch['total'],
        'remaining' => $batch['remaining'],
        'currentBatch' => $current_batch,
        'totalBatches' => $total_batches,
        'itemLabel' => $item_label,
        'createdTotal' => count($summary['created']),
        'errorTotal' => count($summary['errors']),
        'complete' => (bool) $complete,
        'nextOffset' => $batch['processed'],
        'redirectUrl' => bcm_get_generation_result_url($page_slug, $run_id, 'complete'),
        'stopUrl' => bcm_get_generation_result_url($page_slug, $run_id, 'stopped'),
    ]);
}

function bcm_ajax_generate_terms_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to generate terms.'], 403);
    }

    if (!check_ajax_referer('bcm_generate_terms', '_wpnonce', false)) {
        wp_send_json_error(['message' => 'The security check failed. Please reload the page and try again.'], 403);
    }

    $request = bcm_get_terms_generation_request_from_post();
    $batch = bcm_get_batch_context($request['count'], $request['batch_offset']);
    $result = bcm_generate_terms(
        $request['taxonomy'],
        $batch['batch_count'],
        $request['max_depth'],
        $request['style'],
        $request['structure'],
        $batch['offset']
    );

    if ($batch['offset'] === 0 && !empty($request['resolved_errors'])) {
        $result['errors'] = array_merge($request['resolved_errors'], $result['errors']);
    }

    $assignment = [
        'assigned' => [],
        'errors' => [],
    ];

    if (!empty($result['created'])) {
        $created_term_ids = bcm_sanitize_ids(wp_list_pluck($result['created'], 'term_id'));
        $assignment = bcm_assign_terms_to_posts($request['selected_post_ids'], $created_term_ids, $request['taxonomy']);
    }

    $summary = bcm_append_terms_generation_summary($request['run_id'], $result, $assignment, $batch['total'], $batch['processed']);
    $complete = $batch['remaining'] <= 0 || (empty($result['created']) && !empty($result['errors']));

    bcm_send_generation_batch_json('bcm-generate-terms', $request['run_id'], $batch, $summary, 'terms', $complete);
}

function bcm_ajax_generate_posts_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to generate posts.'], 403);
    }

    if (!check_ajax_referer('bcm_generate_posts', '_wpnonce', false)) {
        wp_send_json_error(['message' => 'The security check failed. Please reload the page and try again.'], 403);
    }

    $request = bcm_get_posts_generation_request_from_post();
    $batch = bcm_get_batch_context($request['count'], $request['batch_offset']);
    $result = bcm_generate_posts(
        $request['post_type'],
        $request['status'],
        $batch['batch_count'],
        $request['style'],
        $request['taxonomy'],
        $request['term_mode'],
        $request['selected_term_ids'],
        $request['terms_per_post'],
        $batch['offset'],
        $request['content']
    );

    $summary = bcm_append_posts_generation_summary($request['run_id'], $result, $batch['total'], $batch['processed']);
    $complete = $batch['remaining'] <= 0 || (empty($result['created']) && !empty($result['errors']));

    bcm_send_generation_batch_json('bcm-generate-posts', $request['run_id'], $batch, $summary, 'posts', $complete);
}

function bcm_csv_rows_from_upload($field_name) {
    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['tmp_name']) || !file_exists($_FILES[$field_name]['tmp_name'])) {
        return new WP_Error('bcm_missing_csv', 'Please choose a CSV file.');
    }

    $file = $_FILES[$field_name];
    $filename = isset($file['name']) ? sanitize_file_name($file['name']) : '';
    $tmp_name = $file['tmp_name'];

    if (!empty($file['size']) && (int) $file['size'] > 10485760) {
        return new WP_Error('bcm_csv_too_large', 'CSV files must be 10 MB or smaller.');
    }

    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
        return new WP_Error('bcm_invalid_csv_type', 'Please upload a valid .csv file.');
    }

    if (!is_uploaded_file($tmp_name)) {
        return new WP_Error('bcm_invalid_upload', 'The uploaded file could not be verified.');
    }

    $handle = fopen($tmp_name, 'r');
    if (!$handle) {
        return new WP_Error('bcm_unreadable_csv', 'Could not read the CSV file.');
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return new WP_Error('bcm_empty_csv', 'The CSV file is empty.');
    }

    $headers = array_map('sanitize_key', $headers);
    $rows = [];

    while (($data = fgetcsv($handle)) !== false) {
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = isset($data[$index]) ? $data[$index] : '';
        }
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

function bcm_escape_csv_cell($value) {
    $value = (string) $value;

    if ($value !== '' && preg_match('/^[=\-+@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

function bcm_import_terms_from_csv($field_name) {
    $rows = bcm_csv_rows_from_upload($field_name);
    if (is_wp_error($rows)) {
        return [
            'created' => 0,
            'errors' => [$rows->get_error_message()],
        ];
    }

    $created = 0;
    $errors = [];

    foreach ($rows as $row) {
        $taxonomy = isset($row['taxonomy']) ? sanitize_key($row['taxonomy']) : '';
        $name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
        $slug = isset($row['slug']) ? sanitize_title($row['slug']) : '';
        $description = isset($row['description']) ? sanitize_textarea_field($row['description']) : '';
        $parent_slug = isset($row['parent_slug']) ? sanitize_title($row['parent_slug']) : '';
        $parent_id = 0;

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            $errors[] = 'Invalid taxonomy for row: ' . $name;
            continue;
        }

        if ($name === '') {
            $errors[] = 'A term row is missing a name.';
            continue;
        }

        if ($parent_slug !== '') {
            $parent = get_term_by('slug', $parent_slug, $taxonomy);
            if ($parent) {
                $parent_id = (int) $parent->term_id;
            }
        }

        $args = [
            'description' => $description,
        ];

        if ($slug !== '') {
            $args['slug'] = $slug;
        }

        if ($parent_id > 0 && is_taxonomy_hierarchical($taxonomy)) {
            $args['parent'] = $parent_id;
        }

        $result = wp_insert_term($name, $taxonomy, $args);

        if (is_wp_error($result)) {
            $errors[] = $name . ': ' . $result->get_error_message();
            continue;
        }

        $created++;
    }

    return [
        'created' => $created,
        'errors' => $errors,
    ];
}

function bcm_import_posts_from_csv($field_name) {
    $rows = bcm_csv_rows_from_upload($field_name);
    if (is_wp_error($rows)) {
        return [
            'created' => 0,
            'errors' => [$rows->get_error_message()],
        ];
    }

    $created = 0;
    $errors = [];

    foreach ($rows as $row) {
        $post_type = isset($row['post_type']) ? sanitize_key($row['post_type']) : 'post';
        $post_status = isset($row['post_status']) ? sanitize_key($row['post_status']) : 'draft';
        $post_title = isset($row['post_title']) ? sanitize_text_field($row['post_title']) : '';
        $post_content = isset($row['post_content']) ? wp_kses_post($row['post_content']) : '';
        $post_excerpt = isset($row['post_excerpt']) ? sanitize_textarea_field($row['post_excerpt']) : '';
        $terms_taxonomy = isset($row['terms_taxonomy']) ? sanitize_key($row['terms_taxonomy']) : '';
        $terms = isset($row['terms']) ? sanitize_text_field($row['terms']) : '';

        if (!post_type_exists($post_type)) {
            $errors[] = 'Invalid post type for row: ' . $post_title;
            continue;
        }

        if ($post_title === '') {
            $errors[] = 'A post row is missing a title.';
            continue;
        }

        $post_id = wp_insert_post([
            'post_type' => $post_type,
            'post_status' => in_array($post_status, ['draft', 'publish', 'private', 'pending'], true) ? $post_status : 'draft',
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_excerpt' => $post_excerpt,
        ], true);

        if (is_wp_error($post_id)) {
            $errors[] = $post_title . ': ' . $post_id->get_error_message();
            continue;
        }

        if ($terms_taxonomy && taxonomy_exists($terms_taxonomy) && is_object_in_taxonomy($post_type, $terms_taxonomy) && $terms !== '') {
            $term_names = array_filter(array_map('trim', explode('|', $terms)));
            $term_result = wp_set_object_terms($post_id, $term_names, $terms_taxonomy, true);

            if (is_wp_error($term_result)) {
                $errors[] = $post_title . ': ' . $term_result->get_error_message();
            }
        }

        $created++;
    }

    return [
        'created' => $created,
        'errors' => $errors,
    ];
}

function bcm_send_csv_download($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);

    foreach ($rows as $row) {
        fputcsv($output, array_map('bcm_escape_csv_cell', $row));
    }

    fclose($output);
    exit;
}

function bcm_export_terms_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied.');
    }

    check_admin_referer('bcm_export_terms');

    $taxonomy = isset($_POST['bcm_export_taxonomy']) ? sanitize_key(wp_unslash($_POST['bcm_export_taxonomy'])) : '';
    $taxonomies = $taxonomy && taxonomy_exists($taxonomy) ? [$taxonomy] : array_keys(get_taxonomies(['public' => true], 'objects'));
    $rows = [];

    foreach ($taxonomies as $tax) {
        $terms = get_terms([
            'taxonomy' => $tax,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            continue;
        }

        foreach ($terms as $term) {
            $parent_slug = '';
            if ((int) $term->parent > 0) {
                $parent = get_term($term->parent, $tax);
                if ($parent && !is_wp_error($parent)) {
                    $parent_slug = $parent->slug;
                }
            }

            $rows[] = [
                $tax,
                $term->term_id,
                $term->name,
                $term->slug,
                $term->description,
                $term->parent,
                $parent_slug,
            ];
        }
    }

    bcm_send_csv_download('terms-export.csv', ['taxonomy', 'term_id', 'name', 'slug', 'description', 'parent_id', 'parent_slug'], $rows);
}

function bcm_export_posts_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied.');
    }

    check_admin_referer('bcm_export_posts');

    $post_type = isset($_POST['bcm_export_post_type']) ? sanitize_key(wp_unslash($_POST['bcm_export_post_type'])) : 'post';
    $post_types = post_type_exists($post_type) ? [$post_type] : array_keys(bcm_get_public_post_type_options());
    $posts = get_posts([
        'post_type' => $post_types,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    $rows = [];

    foreach ($posts as $post) {
        $taxonomy_parts = [];
        foreach (get_object_taxonomies($post->post_type, 'names') as $taxonomy) {
            $terms = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'names']);
            if (!is_wp_error($terms) && !empty($terms)) {
                $taxonomy_parts[] = $taxonomy . ':' . implode('|', $terms);
            }
        }

        $rows[] = [
            $post->ID,
            $post->post_type,
            $post->post_status,
            $post->post_title,
            $post->post_content,
            $post->post_excerpt,
            $post->post_date,
            implode(';', $taxonomy_parts),
        ];
    }

    bcm_send_csv_download('posts-export.csv', ['post_id', 'post_type', 'post_status', 'post_title', 'post_content', 'post_excerpt', 'post_date', 'terms_by_taxonomy'], $rows);
}

function bcm_render_page() {
    if (!current_user_can('manage_options')) return;

    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('bcm_create_terms')) {
        $taxonomy = sanitize_text_field($_POST['taxonomy']);
        $term_name = sanitize_text_field($_POST['term_name']);

        // Get raw input and normalize newlines
        $raw_input = str_replace(["\r\n", "\r"], "\n", $_POST['term_slugs']);
        // Replace newlines with commas, then split by comma
        $slug_list = explode(',', str_replace("\n", ',', $raw_input));
        $slugs = array_filter(array_map('sanitize_title', array_map('trim', $slug_list)));

        foreach ($slugs as $slug) {
            $result = wp_insert_term($term_name, $taxonomy, ['slug' => $slug]);
            if (is_wp_error($result)) {
                $message .= '<div class="notice notice-error"><p>Error with slug <code>' . esc_html($slug) . '</code>: ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $message .= '<div class="notice notice-success"><p>Term <code>' . esc_html($slug) . '</code> created.</p></div>';
            }
        }
    }

    $taxonomies = get_taxonomies(['public' => true], 'objects');

    bcm_render_tool_page_open('Bulk Create Terms', 'Create many terms from prepared slugs in a selected taxonomy.', 'dashicons-list-view', 'bcm-create-terms-wrap');
    echo $message;
    bcm_render_panel_open('Manual Term Creation', 'dashicons-tag');
    echo '<form method="post">';
    wp_nonce_field('bcm_create_terms');
    echo '<table class="form-table">
        <tr>
            <th scope="row"><label for="term_name">Term Name</label></th>
            <td><input name="term_name" type="text" id="term_name" value="" class="regular-text" required></td>
        </tr>
        <tr>
            <th scope="row"><label for="taxonomy">Taxonomy</label></th>
            <td><select name="taxonomy" id="taxonomy">';
    foreach ($taxonomies as $tax) {
        echo '<option value="' . esc_attr($tax->name) . '">' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
    }
    echo '</select></td></tr>
        <tr>
            <th scope="row"><label for="term_slugs">Slugs (comma or newline separated)</label></th>
            <td><textarea name="term_slugs" id="term_slugs" rows="10" class="large-text code" required></textarea>
            <p class="description">Example: <code>slug-one, slug-two, slug-three</code> or one per line.</p></td>
        </tr>
    </table>';
    submit_button('Create Terms');
    echo '</form>';
    bcm_render_panel_close();
    bcm_render_tool_page_close();
}

function bcm_render_import_export_page() {
    if (!current_user_can('manage_options')) return;

    $taxonomies = get_taxonomies(['public' => true], 'objects');
    $post_types = bcm_get_public_post_type_options();
    $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'import-terms';
    $tabs = [
        'import-terms' => 'Import Terms',
        'import-posts' => 'Import Posts',
        'export-terms' => 'Export Terms',
        'export-posts' => 'Export Posts',
        'same-name-slugs' => 'Same Name / Many Slugs',
    ];
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bcm_import_terms_submit']) && check_admin_referer('bcm_import_terms')) {
        $result = bcm_import_terms_from_csv('bcm_terms_csv_file');
        $message .= '<div class="notice notice-success"><p>Imported ' . esc_html($result['created']) . ' terms.</p></div>';
        if (!empty($result['errors'])) {
            $message .= '<div class="notice notice-error"><p><strong>Some terms could not be imported:</strong></p><ul>';
            foreach ($result['errors'] as $error) {
                $message .= '<li>' . esc_html($error) . '</li>';
            }
            $message .= '</ul></div>';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bcm_import_posts_submit']) && check_admin_referer('bcm_import_posts')) {
        $result = bcm_import_posts_from_csv('bcm_posts_csv_file');
        $message .= '<div class="notice notice-success"><p>Imported ' . esc_html($result['created']) . ' posts.</p></div>';
        if (!empty($result['errors'])) {
            $message .= '<div class="notice notice-error"><p><strong>Some posts could not be imported:</strong></p><ul>';
            foreach ($result['errors'] as $error) {
                $message .= '<li>' . esc_html($error) . '</li>';
            }
            $message .= '</ul></div>';
        }
    }

    bcm_render_tool_page_open('Import / Export', 'Move WordPress posts and taxonomy terms in or out using focused CSV workflows.', 'dashicons-database-import', 'bcm-import-export-wrap');
    echo '<nav class="nav-tab-wrapper bcm-ie-tabs">';
    foreach ($tabs as $tab_key => $tab_label) {
        $class = $active_tab === $tab_key ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=bcm-import-export&tab=' . $tab_key)) . '">' . esc_html($tab_label) . '</a>';
    }
    echo '</nav>';
    echo $message;
    echo '<div class="bcm-ie-tab-panels"><div class="bcm-ie-tab-panel is-active">';

    if ($active_tab === 'import-terms') {
        echo '<section class="bcm-ie-card"><div class="bcm-ie-card-header"><span class="dashicons dashicons-category"></span><h2>Import Terms</h2></div><div class="bcm-ie-card-body">
            <p>CSV columns: <code>taxonomy,name,slug,description,parent_slug</code>. The <code>description</code> column is the term content field.</p>
            <form method="post" enctype="multipart/form-data">';
        wp_nonce_field('bcm_import_terms');
        echo '<table class="form-table">
            <tr>
                <th scope="row"><label for="bcm_terms_csv_file">Terms CSV</label></th>
                <td><input type="file" name="bcm_terms_csv_file" id="bcm_terms_csv_file" accept=".csv" required></td>
            </tr>
        </table>';
        submit_button('Import Terms', 'primary', 'bcm_import_terms_submit');
        echo '</form></div></section>';
    } elseif ($active_tab === 'import-posts') {
        echo '<section class="bcm-ie-card"><div class="bcm-ie-card-header"><span class="dashicons dashicons-admin-page"></span><h2>Import Posts</h2></div><div class="bcm-ie-card-body">
            <p>CSV columns: <code>post_type,post_status,post_title,post_content,post_excerpt,terms_taxonomy,terms</code>. Separate multiple terms with <code>|</code>.</p>
            <form method="post" enctype="multipart/form-data">';
        wp_nonce_field('bcm_import_posts');
        echo '<table class="form-table">
            <tr>
                <th scope="row"><label for="bcm_posts_csv_file">Posts CSV</label></th>
                <td><input type="file" name="bcm_posts_csv_file" id="bcm_posts_csv_file" accept=".csv" required></td>
            </tr>
        </table>';
        submit_button('Import Posts', 'primary', 'bcm_import_posts_submit');
        echo '</form></div></section>';
    } elseif ($active_tab === 'export-terms') {
        echo '<section class="bcm-ie-card"><div class="bcm-ie-card-header"><span class="dashicons dashicons-database-export"></span><h2>Export Terms</h2></div><div class="bcm-ie-card-body">
            <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">
            <input type="hidden" name="action" value="bcm_export_terms">';
        wp_nonce_field('bcm_export_terms');
        echo '<table class="form-table">
            <tr>
                <th scope="row"><label for="bcm_export_taxonomy">Taxonomy</label></th>
                <td><select name="bcm_export_taxonomy" id="bcm_export_taxonomy">
                    <option value="">All public taxonomies</option>';
        foreach ($taxonomies as $tax) {
            echo '<option value="' . esc_attr($tax->name) . '">' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
        }
        echo '</select></td>
            </tr>
        </table>';
        submit_button('Download Terms CSV');
        echo '</form></div></section>';
    } elseif ($active_tab === 'export-posts') {
        echo '<section class="bcm-ie-card"><div class="bcm-ie-card-header"><span class="dashicons dashicons-database-export"></span><h2>Export Posts</h2></div><div class="bcm-ie-card-body">
            <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">
            <input type="hidden" name="action" value="bcm_export_posts">';
        wp_nonce_field('bcm_export_posts');
        echo '<table class="form-table">
            <tr>
                <th scope="row"><label for="bcm_export_post_type">Post Type</label></th>
                <td><select name="bcm_export_post_type" id="bcm_export_post_type">
                    <option value="">All public post types</option>';
        foreach ($post_types as $post_type) {
            echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->labels->singular_name) . ' (' . esc_html($post_type->name) . ')</option>';
        }
        echo '</select></td>
            </tr>
        </table>';
        submit_button('Download Posts CSV');
        echo '</form></div></section>';
    } else {
        echo '<section class="bcm-ie-card"><div class="bcm-ie-card-header"><span class="dashicons dashicons-tag"></span><h2>Same Name / Many Slugs</h2></div><div class="bcm-ie-card-body">
            <p>Use this focused importer when every row should create the same term name with a different slug.</p>';
        if (!empty($_FILES['bcm_csv_file'])) {
            bcm_handle_csv_upload();
        }

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('bcm_create_terms');
        echo '<table class="form-table">
            <tr>
                <th scope="row"><label for="term_name">Term Name</label></th>
                <td><input name="term_name" type="text" id="term_name" value="" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="taxonomy">Taxonomy</label></th>
                <td><select name="taxonomy" id="taxonomy">';
        foreach ($taxonomies as $tax) {
            echo '<option value="' . esc_attr($tax->name) . '">' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
        }
        echo '</select></td>
            </tr>
            <tr>
                <th scope="row"><label for="bcm_csv_file">Slug CSV</label></th>
                <td><input type="file" name="bcm_csv_file" id="bcm_csv_file" accept=".csv" required>
                <p class="description">CSV must contain one slug per line.</p></td>
            </tr>
        </table>';
        submit_button('Import Slugs');
        echo '</form></div></section>';
    }

    echo '</div></div>';
    bcm_render_tool_page_close();
}

function bcm_render_generator_page() {
    if (!current_user_can('manage_options')) return;

    $message = bcm_render_generation_summary_from_query('terms');
    $prompt = isset($_POST['bcm_generator_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['bcm_generator_prompt'])) : '';
    $parsed = bcm_parse_generator_prompt($prompt);
    $submitted_taxonomy = isset($_POST['bcm_generator_taxonomy']) ? sanitize_text_field(wp_unslash($_POST['bcm_generator_taxonomy'])) : 'category';
    $submitted_count = isset($_POST['bcm_generator_count']) ? absint($_POST['bcm_generator_count']) : 56;
    $submitted_depth = isset($_POST['bcm_generator_depth']) ? absint($_POST['bcm_generator_depth']) : 4;
    $submitted_style = isset($_POST['bcm_generator_style']) ? sanitize_key(wp_unslash($_POST['bcm_generator_style'])) : 'generic';
    $submitted_structure = isset($_POST['bcm_generator_structure']) ? sanitize_key(wp_unslash($_POST['bcm_generator_structure'])) : 'auto';
    $submitted_post_ids = isset($_POST['bcm_assign_posts']) ? bcm_sanitize_ids(wp_unslash($_POST['bcm_assign_posts'])) : [];
    $batch_offset = isset($_POST['bcm_batch_offset']) ? absint($_POST['bcm_batch_offset']) : 0;

    $taxonomy = $parsed['taxonomy'] ?: $submitted_taxonomy;
    $count = $parsed['count'] ?: $submitted_count;
    $max_depth = $parsed['max_depth'] ?: $submitted_depth;
    $style = $parsed['style'] ?: $submitted_style;
    $structure = $parsed['structure'] ?: $submitted_structure;
    $resolved_posts = bcm_resolve_post_titles($parsed['post_titles'], $taxonomy);
    $selected_post_ids = bcm_sanitize_ids(array_merge($submitted_post_ids, $parsed['post_ids'], $resolved_posts['post_ids']));

    $count = max(1, min(10000, $count));
    $max_depth = max(1, min(10, $max_depth));
    $structure = in_array($structure, ['auto', 'hierarchical', 'flat'], true) ? $structure : 'auto';
    $batch = bcm_get_batch_context($count, $batch_offset);

    if (!empty($resolved_posts['errors'])) {
        $message .= '<div class="notice notice-warning"><p><strong>Some post names could not be matched:</strong></p><ul>';
        foreach ($resolved_posts['errors'] as $post_title_error) {
            $message .= '<li>' . esc_html($post_title_error) . '</li>';
        }
        $message .= '</ul></div>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bcm_generate_terms_submit']) && check_admin_referer('bcm_generate_terms')) {
        $result = bcm_generate_terms($taxonomy, $batch['batch_count'], $max_depth, $style, $structure, $batch['offset']);

        if (!empty($result['created'])) {
            $message .= '<div class="notice notice-success"><p>Created ' . esc_html(count($result['created'])) . ' terms in <code>' . esc_html($taxonomy) . '</code>.</p></div>';

            $created_term_ids = bcm_sanitize_ids(wp_list_pluck($result['created'], 'term_id'));
            $assignment = bcm_assign_terms_to_posts($selected_post_ids, $created_term_ids, $taxonomy);

            if (!empty($assignment['assigned'])) {
                $message .= '<div class="notice notice-success"><p>Added the generated terms to ' . esc_html(count($assignment['assigned'])) . ' selected post(s).</p></div>';
            }

            if (!empty($assignment['errors'])) {
                $message .= '<div class="notice notice-error"><p><strong>Some posts could not be updated:</strong></p><ul>';
                foreach ($assignment['errors'] as $assignment_error) {
                    $message .= '<li>' . esc_html($assignment_error) . '</li>';
                }
                $message .= '</ul></div>';
            }

            $term_items = [];
            foreach ($result['created'] as $created) {
                $indent = str_repeat('&mdash; ', max(0, (int) $created['depth'] - 1));
                $term_items[] = $indent . esc_html($created['name']);
            }
            $message .= bcm_render_collapsible_items('Created terms:', $term_items, 'bcm-created-terms');

            if ($batch['remaining'] > 0) {
                $message .= bcm_render_auto_batch_notice($batch, [
                    'bcm_generator_prompt' => $prompt,
                    'bcm_generator_taxonomy' => $taxonomy,
                    'bcm_generator_count' => $count,
                    'bcm_generator_depth' => $max_depth,
                    'bcm_generator_style' => $style,
                    'bcm_generator_structure' => $structure,
                    'bcm_assign_posts' => $selected_post_ids,
                    'bcm_batch_offset' => $batch['processed'],
                    'bcm_generate_terms_submit' => '1',
                ], 'bcm_generate_terms', 'bcm-auto-terms-batch', 'terms');
            }
        }

        if (!empty($result['errors'])) {
            $message .= '<div class="notice notice-error"><p><strong>Some terms could not be created:</strong></p><ul>';
            foreach ($result['errors'] as $error) {
                $message .= '<li>' . esc_html($error) . '</li>';
            }
            $message .= '</ul></div>';
        }
    }

    $taxonomies = bcm_get_taxonomy_options(false);
    $assignable_posts = bcm_get_assignable_posts($taxonomy, $selected_post_ids);
    $styles = [
        'generic' => 'Generic',
        'places' => 'Places',
        'commerce' => 'Commerce',
        'topics' => 'Topics',
    ];

    bcm_render_tool_page_open('Generate Terms', 'Generate flat or nested taxonomy terms locally, then optionally attach them to posts.', 'dashicons-randomize', 'bcm-generate-terms-wrap');
    echo $message;
    bcm_render_panel_open('Term Generator', 'dashicons-category');
    echo '<form method="post" id="bcm-generate-terms-form" data-bcm-ajax-generator="terms" data-bcm-ajax-action="bcm_generate_terms_batch" data-bcm-offset-field="bcm_batch_offset" data-bcm-submit-name="bcm_generate_terms_submit">';
    wp_nonce_field('bcm_generate_terms');
    echo '<table class="form-table">
        <tr>
            <th scope="row"><label for="bcm_generator_prompt">Prompt</label></th>
            <td><textarea name="bcm_generator_prompt" id="bcm_generator_prompt" rows="4" class="large-text code" placeholder="Describe the terms you want to create, or use the fields below">' . esc_textarea($prompt) . '</textarea>
            <p class="description">Optional. The prompt can fill in count, taxonomy, structure, depth, style hints, post IDs such as <code>#18</code>, or quoted post titles. The fields below are the final values used.</p></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_generator_taxonomy">Taxonomy</label></th>
            <td><select name="bcm_generator_taxonomy" id="bcm_generator_taxonomy">';
    foreach ($taxonomies as $tax) {
        $taxonomy_type = $tax->hierarchical ? 'hierarchical' : 'flat';
        echo '<option value="' . esc_attr($tax->name) . '"' . selected($taxonomy, $tax->name, false) . '>' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ', ' . esc_html($taxonomy_type) . ')</option>';
    }
    echo '</select>
            <p class="description">Choose where the new terms should be created. Each option shows whether that taxonomy supports nesting.</p></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_generator_structure">Term Structure</label></th>
            <td><select name="bcm_generator_structure" id="bcm_generator_structure">
                <option value="auto"' . selected($structure, 'auto', false) . '>Auto</option>
                <option value="hierarchical"' . selected($structure, 'hierarchical', false) . '>Hierarchical / nested</option>
                <option value="flat"' . selected($structure, 'flat', false) . '>Flat / no parent terms</option>
            </select></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_generator_count">Number of Terms</label></th>
            <td><input name="bcm_generator_count" type="number" id="bcm_generator_count" value="' . esc_attr($count) . '" min="1" max="10000" class="small-text"></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_generator_depth">Maximum Depth</label></th>
            <td><input name="bcm_generator_depth" type="number" id="bcm_generator_depth" value="' . esc_attr($max_depth) . '" min="1" max="10" class="small-text">
            <p class="description">Used only when the final term structure is nested.</p></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_generator_style">Generator Style</label></th>
            <td><select name="bcm_generator_style" id="bcm_generator_style">';
    foreach ($styles as $style_key => $style_label) {
        echo '<option value="' . esc_attr($style_key) . '"' . selected($style, $style_key, false) . '>' . esc_html($style_label) . '</option>';
    }
    echo '</select></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_assign_posts">Add Terms to Posts</label></th>
            <td><input type="search" id="bcm_assign_posts_search" class="regular-text bcm-token-search" data-bcm-token-target="bcm_assign_posts" placeholder="Search available posts by title, ID, or post type">
            <br><br>
            <select name="bcm_assign_posts[]" id="bcm_assign_posts" multiple size="10" class="regular-text bcm-token-select" data-placeholder="Search and choose posts">';
    if (empty($assignable_posts)) {
        echo '<option value="" disabled>No compatible posts found for this taxonomy</option>';
    }
    foreach ($assignable_posts as $post) {
        $post_type = get_post_type_object($post->post_type);
        $post_type_label = $post_type ? $post_type->labels->singular_name : $post->post_type;
        $post_title = get_the_title($post);
        $post_title = $post_title ? $post_title : '(no title)';
        echo '<option value="' . esc_attr($post->ID) . '"' . selected(in_array((int) $post->ID, $selected_post_ids, true), true, false) . '>' . esc_html($post_title) . ' #' . esc_html($post->ID) . ' - ' . esc_html($post_type_label) . '</option>';
    }
    echo '</select>
            <p class="description">Optional. Selected posts receive the generated flat or nested terms in addition to their existing terms.</p></td>
        </tr>
    </table>';
    submit_button('Generate Terms', 'primary', 'bcm_generate_terms_submit');
    echo '</form>';
    bcm_render_panel_close();
    bcm_render_tool_page_close();
}

function bcm_render_post_generator_page() {
    if (!current_user_can('manage_options')) return;

    $message = bcm_render_generation_summary_from_query('posts');
    $prompt = isset($_POST['bcm_post_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['bcm_post_prompt'])) : '';
    $parsed = bcm_parse_post_generator_prompt($prompt);
    $submitted_post_type = isset($_POST['bcm_post_type']) ? sanitize_key(wp_unslash($_POST['bcm_post_type'])) : 'post';
    $submitted_status = isset($_POST['bcm_post_status']) ? sanitize_key(wp_unslash($_POST['bcm_post_status'])) : 'draft';
    $submitted_count = isset($_POST['bcm_post_count']) ? absint($_POST['bcm_post_count']) : 10;
    $submitted_style = isset($_POST['bcm_post_style']) ? sanitize_key(wp_unslash($_POST['bcm_post_style'])) : 'generic';
    $submitted_content = isset($_POST['bcm_post_content']) ? wp_kses_post(wp_unslash($_POST['bcm_post_content'])) : bcm_get_default_generated_post_content();
    $submitted_taxonomy = isset($_POST['bcm_post_taxonomy']) ? sanitize_key(wp_unslash($_POST['bcm_post_taxonomy'])) : 'category';
    $submitted_term_mode = isset($_POST['bcm_post_term_mode']) ? sanitize_key(wp_unslash($_POST['bcm_post_term_mode'])) : 'none';
    $submitted_terms_per_post = isset($_POST['bcm_terms_per_post']) ? absint($_POST['bcm_terms_per_post']) : 3;
    $selected_term_ids = isset($_POST['bcm_post_terms']) ? bcm_sanitize_ids(wp_unslash($_POST['bcm_post_terms'])) : [];
    $batch_offset = isset($_POST['bcm_post_batch_offset']) ? absint($_POST['bcm_post_batch_offset']) : 0;

    $post_type = $parsed['post_type'] ?: $submitted_post_type;
    $status = $parsed['status'] ?: $submitted_status;
    $count = $parsed['count'] ?: $submitted_count;
    $style = $parsed['style'] ?: $submitted_style;
    $content = $submitted_content;
    $taxonomy = $parsed['taxonomy'] ?: $submitted_taxonomy;
    $term_mode = $parsed['term_mode'] ?: $submitted_term_mode;
    $terms_per_post = $parsed['terms_per_post'] ?: $submitted_terms_per_post;

    $post_types = bcm_get_public_post_type_options();
    if (!isset($post_types[$post_type])) {
        $post_type = 'post';
    }

    $taxonomies = bcm_get_taxonomies_for_post_type($post_type);
    if (!isset($taxonomies[$taxonomy]) && !empty($taxonomies)) {
        $taxonomy_keys = array_keys($taxonomies);
        $taxonomy = reset($taxonomy_keys);
    }

    if (empty($taxonomies)) {
        $term_mode = 'none';
    }

    $count = max(1, min(10000, $count));
    $terms_per_post = max(1, min(100, $terms_per_post));
    $term_mode = in_array($term_mode, ['none', 'selected_all', 'random_existing', 'random_selected'], true) ? $term_mode : 'none';
    $batch = bcm_get_batch_context($count, $batch_offset);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bcm_generate_posts_submit']) && check_admin_referer('bcm_generate_posts')) {
        $result = bcm_generate_posts($post_type, $status, $batch['batch_count'], $style, $taxonomy, $term_mode, $selected_term_ids, $terms_per_post, $batch['offset'], $content);

        if (!empty($result['created'])) {
            $message .= '<div class="notice notice-success"><p>Created ' . esc_html(count($result['created'])) . ' ' . esc_html($post_types[$post_type]->labels->name) . '.</p></div>';
            $post_items = [];

            foreach ($result['created'] as $created) {
                $post_items[] = esc_html($created['title']) . ' #' . esc_html($created['post_id']);
            }

            $message .= bcm_render_collapsible_items('Created posts:', $post_items, 'bcm-created-posts');

            if ($batch['remaining'] > 0) {
                $message .= bcm_render_auto_batch_notice($batch, [
                    'bcm_post_prompt' => $prompt,
                    'bcm_post_type' => $post_type,
                    'bcm_post_status' => $status,
                    'bcm_post_count' => $count,
                    'bcm_post_style' => $style,
                    'bcm_post_content' => $content,
                    'bcm_post_taxonomy' => $taxonomy,
                    'bcm_post_term_mode' => $term_mode,
                    'bcm_terms_per_post' => $terms_per_post,
                    'bcm_post_terms' => $selected_term_ids,
                    'bcm_post_batch_offset' => $batch['processed'],
                    'bcm_generate_posts_submit' => '1',
                ], 'bcm_generate_posts', 'bcm-auto-posts-batch', 'posts');
            }
        }

        if (!empty($result['errors'])) {
            $message .= '<div class="notice notice-error"><p><strong>Some posts could not be created or updated:</strong></p><ul>';
            foreach ($result['errors'] as $error) {
                $message .= '<li>' . esc_html($error) . '</li>';
            }
            $message .= '</ul></div>';
        }
    }

    $terms = taxonomy_exists($taxonomy) ? bcm_get_terms_for_assignment($taxonomy, $selected_term_ids) : [];
    $styles = [
        'generic' => 'Generic',
        'places' => 'Places',
        'commerce' => 'Commerce',
        'topics' => 'Topics',
    ];
    $statuses = [
        'draft' => 'Draft',
        'publish' => 'Published',
        'private' => 'Private',
        'pending' => 'Pending Review',
    ];

    bcm_render_tool_page_open('Generate Posts', 'Create posts in controlled batches and optionally assign existing terms.', 'dashicons-admin-page', 'bcm-generate-posts-wrap');
    echo $message;
    bcm_render_panel_open('Post Generator', 'dashicons-admin-post');
    echo '<form method="post" id="bcm-generate-posts-form" data-bcm-ajax-generator="posts" data-bcm-ajax-action="bcm_generate_posts_batch" data-bcm-offset-field="bcm_post_batch_offset" data-bcm-submit-name="bcm_generate_posts_submit">';
    wp_nonce_field('bcm_generate_posts');
    echo '<table class="form-table">
        <tr>
            <th scope="row"><label for="bcm_post_prompt">Prompt</label></th>
            <td><textarea name="bcm_post_prompt" id="bcm_post_prompt" rows="4" class="large-text code" placeholder="Describe the posts you want to create, or use the fields below">' . esc_textarea($prompt) . '</textarea>
            <p class="description">Optional. The prompt can fill in count, post type, status, style, taxonomy, and random existing term assignment.</p></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_type">Post Type</label></th>
            <td><select name="bcm_post_type" id="bcm_post_type">';
    foreach ($post_types as $type) {
        echo '<option value="' . esc_attr($type->name) . '"' . selected($post_type, $type->name, false) . '>' . esc_html($type->labels->singular_name) . ' (' . esc_html($type->name) . ')</option>';
    }
    echo '</select></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_status">Post Status</label></th>
            <td><select name="bcm_post_status" id="bcm_post_status">';
    foreach ($statuses as $status_key => $status_label) {
        echo '<option value="' . esc_attr($status_key) . '"' . selected($status, $status_key, false) . '>' . esc_html($status_label) . '</option>';
    }
    echo '</select></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_count">Number of Posts</label></th>
            <td><input name="bcm_post_count" type="number" id="bcm_post_count" value="' . esc_attr($count) . '" min="1" max="10000" class="small-text"></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_style">Generator Style</label></th>
            <td><select name="bcm_post_style" id="bcm_post_style">';
    foreach ($styles as $style_key => $style_label) {
        echo '<option value="' . esc_attr($style_key) . '"' . selected($style, $style_key, false) . '>' . esc_html($style_label) . '</option>';
    }
    echo '</select></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_content">Post Content</label></th>
            <td><textarea name="bcm_post_content" id="bcm_post_content" rows="6" class="large-text" placeholder="This post is auto generated by Bulk Content Management.">' . esc_textarea($content) . '</textarea>
            <p class="description">Used as the body for each generated post. You can use <code>{title}</code> and <code>{index}</code> as placeholders.</p></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_taxonomy">Terms Taxonomy</label></th>
            <td><select name="bcm_post_taxonomy" id="bcm_post_taxonomy">';
    if (empty($taxonomies)) {
        echo '<option value="">No taxonomies available for this post type</option>';
    }
    foreach ($taxonomies as $tax) {
        echo '<option value="' . esc_attr($tax->name) . '"' . selected($taxonomy, $tax->name, false) . '>' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
    }
    echo '</select>
            <p class="description">Used only when term assignment is enabled.</p></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_term_mode">Term Assignment</label></th>
            <td><select name="bcm_post_term_mode" id="bcm_post_term_mode">
                <option value="none"' . selected($term_mode, 'none', false) . '>Do not assign terms</option>
                <option value="selected_all"' . selected($term_mode, 'selected_all', false) . '>Assign all selected terms to each post</option>
                <option value="random_existing"' . selected($term_mode, 'random_existing', false) . '>Assign random existing terms to each post</option>
                <option value="random_selected"' . selected($term_mode, 'random_selected', false) . '>Assign random selected terms to each post</option>
            </select></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_terms_per_post">Terms Per Post</label></th>
            <td><input name="bcm_terms_per_post" type="number" id="bcm_terms_per_post" value="' . esc_attr($terms_per_post) . '" min="1" max="100" class="small-text">
            <p class="description">Used by the random assignment modes.</p></td>
        </tr>
        <tr>
            <th scope="row"><label for="bcm_post_terms">Available Terms</label></th>
            <td><input type="search" id="bcm_post_terms_search" class="regular-text bcm-token-search" data-bcm-token-target="bcm_post_terms" placeholder="Search available terms by name or ID">
            <br><br>
            <select name="bcm_post_terms[]" id="bcm_post_terms" multiple size="10" class="regular-text bcm-token-select" data-placeholder="Search and choose terms">';
    if (empty($terms)) {
        echo '<option value="" disabled>No terms found for this taxonomy</option>';
    }
    foreach ($terms as $term) {
        echo '<option value="' . esc_attr($term->term_id) . '"' . selected(in_array((int) $term->term_id, $selected_term_ids, true), true, false) . '>' . esc_html($term->name) . ' #' . esc_html($term->term_id) . '</option>';
    }
    echo '</select>
            <p class="description">Optional. Select terms to assign to each generated post.</p></td>
        </tr>
    </table>';
    submit_button('Generate Posts', 'primary', 'bcm_generate_posts_submit');
    echo '</form>';
    bcm_render_panel_close();
    bcm_render_tool_page_close();
}
