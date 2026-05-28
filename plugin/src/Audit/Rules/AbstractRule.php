<?php
declare(strict_types=1);

namespace WPSearch\Audit\Rules;

use WP_Post;

abstract class AbstractRule implements RuleInterface {
    public function needs_site_context(): bool {
        return false;
    }

    protected function rendered_content(WP_Post $post): string {
        $content = (string) $post->post_content;
        if ($content === '' && $this->is_elementor_post($post->ID)) {
            $content = $this->render_elementor($post->ID);
        }
        $rendered = apply_filters('the_content', $content);
        return is_string($rendered) ? $rendered : '';
    }

    protected function is_elementor_post(int $post_id): bool {
        return get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
    }

    protected function render_elementor(int $post_id): string {
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

    protected function plain_text(WP_Post $post): string {
        $rendered = $this->rendered_content($post);
        $text = wp_strip_all_tags($rendered, true);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    protected function word_count(WP_Post $post): int {
        return str_word_count($this->plain_text($post));
    }

    protected function get_meta_description(int $post_id): string {
        $sources = [
            '_yoast_wpseo_metadesc',
            'rank_math_description',
            '_aioseo_description',
            '_seopress_titles_desc',
        ];
        foreach ($sources as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    protected function count_h1s(string $rendered): int {
        return preg_match_all('/<h1[\s>]/i', $rendered);
    }

    protected function first_h1(string $rendered): ?string {
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $rendered, $m)) {
            $text = wp_strip_all_tags($m[1]);
            $text = trim((string) $text);
            return $text !== '' ? $text : null;
        }
        return null;
    }
}
