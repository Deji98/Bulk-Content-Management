<?php
/**
 * Home page template.
 *
 * @package BulkContentManagement
 *
 * @var array $cards Home feature cards.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap bcm-home-wrap">
    <section class="bcm-home" aria-label="Bulk Content Management home">
        <div class="bcm-home-hero">
            <div>
                <p class="bcm-home-eyebrow"><span class="dashicons dashicons-admin-tools"></span> Bulk content operations</p>
                <h1>Bulk Content Management</h1>
                <p class="bcm-home-lede">Generate, import, export, and assign WordPress posts and taxonomy terms without relying on an external AI service. Built for careful batch work, quick test data, and repeatable site setup.</p>
                <div class="bcm-home-actions">
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=bcm-generate-posts')); ?>">Generate Posts</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=bcm-generate-terms')); ?>">Generate Terms</a>
                </div>
            </div>
            <div class="bcm-home-panel" aria-label="Feature summary">
                <div class="bcm-home-stat"><span class="dashicons dashicons-controls-repeat"></span><span><strong>Automatic batching</strong><span>Large jobs run in controlled batches.</span></span></div>
                <div class="bcm-home-stat"><span class="dashicons dashicons-category"></span><span><strong>Taxonomy-aware</strong><span>Flat and nested terms are handled separately.</span></span></div>
                <div class="bcm-home-stat"><span class="dashicons dashicons-media-spreadsheet"></span><span><strong>CSV workflows</strong><span>Focused import and export tabs for posts and terms.</span></span></div>
            </div>
        </div>
        <div class="bcm-home-grid">
            <?php foreach ($cards as $card) : ?>
                <article class="bcm-home-card">
                    <div class="bcm-home-card-icon"><span class="dashicons <?php echo esc_attr($card['icon']); ?>"></span></div>
                    <h2><?php echo esc_html($card['title']); ?></h2>
                    <p><?php echo esc_html($card['text']); ?></p>
                    <a class="button" href="<?php echo esc_url($card['url']); ?>"><?php echo esc_html($card['label']); ?></a>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="bcm-home-note"><strong>Plugin directory note:</strong> this screen uses WordPress-native admin UI patterns, local generation, non-destructive batching controls, and focused CSV tools. Before submission, the remaining work should be a dedicated standards pass for escaping, sanitization, internationalization, readme metadata, and uninstall behavior.</p>
    </section>
</div>
