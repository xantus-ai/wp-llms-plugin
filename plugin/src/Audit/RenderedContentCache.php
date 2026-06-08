<?php
declare(strict_types=1);

namespace WPLlms\Audit;

use WP_Post;

/**
 * Shared cache for rendered post HTML, used by audit rules and AuditContext.
 *
 * Builder rendering (Elementor's get_builder_content_for_display() and the
 * the_content filter chain) is expensive — measurable seconds per Elementor
 * post on real sites. The audit reads rendered HTML from every post and every
 * rule, so an uncached audit on a builder-heavy site multiplies that cost
 * across ~12 rules × N posts.
 *
 * Cache key is keyed on post_modified_gmt, so it auto-invalidates when the
 * post is saved. Lifetime is one day for the rare case where post_modified
 * doesn't change but a related option does (e.g., theme change affecting
 * the_content filter output).
 */
final class RenderedContentCache {
    public static function get(WP_Post $post): string {
        $cache_key = self::cache_key($post);
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        $rendered = self::render($post);
        set_transient($cache_key, $rendered, DAY_IN_SECONDS);
        return $rendered;
    }

    private static function render(WP_Post $post): string {
        if (self::is_elementor_post($post->ID)) {
            $rendered = self::render_elementor($post->ID);
            if ($rendered !== '') {
                return $rendered;
            }
        }
        $filtered = apply_filters('the_content', (string) $post->post_content);
        return is_string($filtered) ? $filtered : '';
    }

    private static function cache_key(WP_Post $post): string {
        $modified = strtotime($post->post_modified_gmt) ?: 0;
        return sprintf('wpllms_rendered_%d_%d', $post->ID, $modified);
    }

    private static function is_elementor_post(int $post_id): bool {
        return get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
    }

    private static function render_elementor(int $post_id): string {
        if (!class_exists('\\Elementor\\Plugin')) {
            return '';
        }
        try {
            $plugin = \Elementor\Plugin::$instance;
            if ($plugin && isset($plugin->frontend)) {
                return (string) $plugin->frontend->get_builder_content_for_display($post_id);
            }
        } catch (\Throwable $e) {
            // Fall through.
        }
        return '';
    }
}
