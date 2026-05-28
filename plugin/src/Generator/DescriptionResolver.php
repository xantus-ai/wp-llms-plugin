<?php
declare(strict_types=1);

namespace WPSearch\Generator;

use WPSearch\Storage\OverridesRepository;
use WP_Post;

/**
 * Implements the 10-priority description resolution chain from
 * generator-spec.md §3, with quality gates.
 */
final class DescriptionResolver {
    private const MIN_LENGTH = 50;
    private const MAX_LENGTH = 500;
    private const TRIM_LENGTH = 200;

    private const CTA_PHRASES = [
        'subscribe', 'buy now', 'click here', 'join today',
        'sign up', 'sign-up', 'download now', 'get started',
        'learn more',
    ];

    private const BOILERPLATE_PREFIXES = [
        'Read more about ', 'Learn how to ', 'In this post, ',
        'In this article, ', 'Discover how ', 'Find out ',
        'Click here to ',
    ];

    public function __construct(
        private Extractor $extractor,
        private OverridesRepository $overrides
    ) {}

    /**
     * Resolve the best description for a post.
     * Returns null if all priorities fail their quality gates.
     */
    public function resolve(WP_Post $post, ?int $section_id = null): ?string {
        $sources = [
            fn() => $this->from_manual_override($post, $section_id),
            fn() => $this->from_postmeta($post, '_yoast_wpseo_metadesc'),
            fn() => $this->from_postmeta($post, 'rank_math_description'),
            fn() => $this->from_postmeta($post, '_aioseo_description'),
            fn() => $this->from_postmeta($post, '_seopress_titles_desc'),
            fn() => $this->from_excerpt($post),
            fn() => $this->from_first_sentence($post),
            fn() => $this->from_first_chars($post),
        ];

        foreach ($sources as $source) {
            $candidate = $source();
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $normalized = $this->normalize($candidate, $post);
            if ($this->passes_quality_gate($normalized, $post)) {
                return $normalized;
            }
        }

        return null;
    }

    private function from_manual_override(WP_Post $post, ?int $section_id): ?string {
        return $this->overrides->get_custom_description($post->ID, $section_id);
    }

    private function from_postmeta(WP_Post $post, string $meta_key): ?string {
        $value = get_post_meta($post->ID, $meta_key, true);
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function from_excerpt(WP_Post $post): ?string {
        $excerpt = (string) $post->post_excerpt;
        if (trim($excerpt) === '') {
            return null;
        }
        return wp_strip_all_tags($excerpt);
    }

    private function from_first_sentence(WP_Post $post): ?string {
        $text = $this->extractor->extract_clean_text($post);
        if ($text === '') {
            return null;
        }

        // Split on sentence boundary. Conservative: period followed by space and capital.
        if (preg_match('/^(.{40,}?[.!?])(\s+[A-Z]|$)/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function from_first_chars(WP_Post $post): ?string {
        $text = $this->extractor->extract_clean_text($post);
        if ($text === '') {
            return null;
        }
        return mb_substr($text, 0, 250);
    }

    private function normalize(string $value, WP_Post $post): string {
        $value = wp_strip_all_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        foreach (self::BOILERPLATE_PREFIXES as $prefix) {
            if (stripos($value, $prefix) === 0) {
                $value = substr($value, strlen($prefix));
                $value = trim($value);
                break;
            }
        }

        if (mb_strlen($value) > self::TRIM_LENGTH) {
            $truncated = mb_substr($value, 0, self::TRIM_LENGTH);
            $last_space = mb_strrpos($truncated, ' ');
            if ($last_space !== false && $last_space > 100) {
                $truncated = mb_substr($truncated, 0, $last_space);
            }
            $value = rtrim($truncated, " \t\n\r\0\x0B,;:") . '…';
        }

        return $value;
    }

    private function passes_quality_gate(string $value, WP_Post $post): bool {
        $length = mb_strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        $title = (string) $post->post_title;
        if (strcasecmp($value, $title) === 0) {
            return false;
        }

        $tagline = (string) get_bloginfo('description');
        if ($tagline !== '' && strcasecmp($value, $tagline) === 0) {
            return false;
        }

        $cta_hits = 0;
        $lower = mb_strtolower($value);
        foreach (self::CTA_PHRASES as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                $cta_hits++;
            }
        }
        if ($cta_hits >= 2) {
            return false;
        }

        if (preg_match('/\$\d+/', $value) && $cta_hits >= 1) {
            return false;
        }

        $word_count = str_word_count($value);
        if ($word_count < 6) {
            return false;
        }

        return true;
    }
}
