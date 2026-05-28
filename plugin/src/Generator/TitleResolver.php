<?php
declare(strict_types=1);

namespace WPLlms\Generator;

use WPLlms\Storage\OverridesRepository;
use WP_Post;

/**
 * Title resolution per generator-spec.md §4.
 * Strips site-name suffix patterns to keep titles tight.
 */
final class TitleResolver {
    private const MAX_LENGTH = 90;

    public function __construct(
        private OverridesRepository $overrides
    ) {}

    public function resolve(WP_Post $post, ?int $section_id = null): string {
        $override = $this->overrides->get_custom_title($post->ID, $section_id);
        if ($override !== null) {
            return $this->normalize($override);
        }

        $yoast_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        if (is_string($yoast_title) && trim($yoast_title) !== '') {
            $resolved = $this->resolve_yoast_template($yoast_title, $post);
            if ($resolved !== '') {
                return $this->normalize($resolved);
            }
        }

        $title = (string) get_the_title($post);
        return $this->normalize($title);
    }

    private function resolve_yoast_template(string $template, WP_Post $post): string {
        // Yoast templates can contain placeholders like %%title%%. We don't reimplement
        // the full Yoast templating here - just substitute %%title%% if present, else
        // fall through to post_title.
        if (str_contains($template, '%%')) {
            $template = str_replace('%%title%%', (string) $post->post_title, $template);
            $template = str_replace('%%sitename%%', (string) get_bloginfo('name'), $template);
            // Strip any remaining %% placeholders we don't handle.
            $template = preg_replace('/%%[a-z_]+%%/i', '', $template) ?? $template;
        }
        return trim($template);
    }

    private function normalize(string $value): string {
        $value = wp_strip_all_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        $site_name = (string) get_bloginfo('name');
        if ($site_name !== '') {
            $patterns = [
                ' | ' . $site_name,
                ' - ' . $site_name,
                ' — ' . $site_name,
                ' · ' . $site_name,
            ];
            foreach ($patterns as $suffix) {
                if (str_ends_with($value, $suffix)) {
                    $value = substr($value, 0, -strlen($suffix));
                    break;
                }
            }
            // Also strip site name with registered/trademark symbols.
            $value = preg_replace('/\s*[|\-—·]\s*' . preg_quote($site_name, '/') . '[®™©]?\s*$/u', '', $value) ?? $value;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $truncated = mb_substr($value, 0, self::MAX_LENGTH);
            $last_space = mb_strrpos($truncated, ' ');
            if ($last_space !== false && $last_space > 50) {
                $truncated = mb_substr($truncated, 0, $last_space);
            }
            $value = rtrim($truncated) . '…';
        }

        return $value;
    }
}
