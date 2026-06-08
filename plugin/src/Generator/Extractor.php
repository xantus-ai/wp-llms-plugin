<?php
declare(strict_types=1);

namespace WPLlms\Generator;

use DOMDocument;
use DOMNode;
use DOMXPath;
use WP_Post;

/**
 * Content extraction pipeline. Implements the 6-stage pipeline from
 * generator-spec.md §5.
 *
 * Stage 1: Resolve content source (Elementor render or post_content)
 * Stage 2: Render HTML via the_content filter (with noise filters suppressed)
 * Stage 3: Sanitize HTML via wp_kses with strict allowlist
 * Stage 4: Strip builder chrome (Elementor popups, forms, empty wrappers)
 * Stage 5: Convert to markdown (league/html-to-markdown if available)
 * Stage 6: Post-process markdown (collapse newlines, absolute URLs, etc.)
 */
final class Extractor {
    private const ALLOWED_TAGS = [
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'p' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [], 'a' => ['href' => true], 'strong' => [], 'b' => [],
        'em' => [], 'i' => [], 'code' => [], 'pre' => [],
        'img' => ['src' => true, 'alt' => true],
        'br' => [], 'hr' => [],
        'table' => [], 'tr' => [], 'td' => [], 'th' => [], 'thead' => [], 'tbody' => [],
    ];

    /**
     * Extract clean plain text from a post.
     * Used by description resolution priorities 8-9 (first sentence / first chars).
     * Cheaper than full markdown extraction.
     */
    public function extract_clean_text(WP_Post $post): string {
        $cache_key = $this->cache_key('text', $post);
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        $html = $this->stage_1_resolve_source($post);
        $html = $this->stage_2_render($post, $html);
        $html = $this->stage_3_sanitize($html);
        $html = $this->stage_4_strip_chrome($html);

        $text = trim((string) wp_strip_all_tags($html, true));
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = is_string($text) ? trim($text) : '';

        set_transient($cache_key, $text, DAY_IN_SECONDS);
        return $text;
    }

    /**
     * Extract markdown content from a post. Full pipeline.
     * Used for llms-full.txt and .md endpoints.
     */
    public function extract_markdown(WP_Post $post): string {
        $cache_key = $this->cache_key('md', $post);
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        $html = $this->stage_1_resolve_source($post);
        $html = $this->stage_2_render($post, $html);
        $html = $this->stage_3_sanitize($html);
        $html = $this->stage_4_strip_chrome($html);
        $markdown = $this->stage_5_to_markdown($html);
        $markdown = $this->stage_6_postprocess($markdown);

        set_transient($cache_key, $markdown, DAY_IN_SECONDS);
        return $markdown;
    }

    private function cache_key(string $kind, WP_Post $post): string {
        $modified = strtotime($post->post_modified_gmt) ?: 0;
        return sprintf('wpllms_extracted_%s_%d_%d', $kind, $post->ID, $modified);
    }

    private function stage_1_resolve_source(WP_Post $post): string {
        // Elementor stores the page design in _elementor_data meta, not post_content.
        // Always prefer the Elementor frontend render for builder posts; only fall back
        // to post_content if Elementor's frontend renderer is unavailable.
        if ($this->is_elementor_post($post->ID)) {
            $rendered = $this->render_elementor($post->ID);
            if ($rendered !== '') {
                return $rendered;
            }
        }

        return (string) $post->post_content;
    }

    private function is_elementor_post(int $post_id): bool {
        $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
        return $edit_mode === 'builder';
    }

    private function render_elementor(int $post_id): string {
        if (!class_exists('\\Elementor\\Plugin')) {
            return '';
        }

        try {
            $plugin = \Elementor\Plugin::$instance;
            if (!$plugin || !isset($plugin->frontend)) {
                return '';
            }
            return (string) $plugin->frontend->get_builder_content_for_display($post_id);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function stage_2_render(WP_Post $post, string $html): string {
        $noise_shortcodes = ['gravityform', 'gform_shortcode', 'gravityforms'];
        $removed = [];
        foreach ($noise_shortcodes as $tag) {
            if (shortcode_exists($tag)) {
                global $shortcode_tags;
                if (isset($shortcode_tags[$tag])) {
                    $removed[$tag] = $shortcode_tags[$tag];
                    remove_shortcode($tag);
                }
            }
        }

        $rendered = apply_filters('the_content', $html);

        foreach ($removed as $tag => $callback) {
            add_shortcode($tag, $callback);
        }

        return is_string($rendered) ? $rendered : '';
    }

    private function stage_3_sanitize(string $html): string {
        return wp_kses($html, self::ALLOWED_TAGS);
    }

    private function stage_4_strip_chrome(string $html): string {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="wpllms-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        // Drop popup / theme-builder / form widgets.
        $drop_attr_queries = [
            "//*[@data-elementor-type='popup']",
            "//*[@data-elementor-type='header']",
            "//*[@data-elementor-type='footer']",
            "//*[@data-widget_type='form.default']",
        ];
        foreach ($drop_attr_queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false) {
                foreach (iterator_to_array($nodes) as $node) {
                    if ($node->parentNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }

        // Drop links that are Elementor popup triggers (href starts with #elementor-action).
        $popup_links = $xpath->query("//a[starts-with(@href, '#elementor-action')]");
        if ($popup_links !== false) {
            foreach (iterator_to_array($popup_links) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // Demote any in-content h1 to h2 (the post title is the document h1).
        $h1s = $xpath->query("//h1");
        if ($h1s !== false) {
            foreach (iterator_to_array($h1s) as $h1) {
                $this->rename_node($dom, $h1, 'h2');
            }
        }

        // Drop empty paragraphs and divs (no text, no images).
        $candidates = $xpath->query("//p[not(normalize-space())] | //div[not(normalize-space())]");
        if ($candidates !== false) {
            foreach (iterator_to_array($candidates) as $node) {
                if (!$node instanceof DOMNode) continue;
                $has_image = $xpath->evaluate('count(.//img)', $node);
                if ((float) $has_image === 0.0 && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $root = $dom->getElementById('wpllms-root');
        if (!$root) {
            return '';
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    private function rename_node(DOMDocument $dom, DOMNode $node, string $new_name): void {
        if (!$node->parentNode) {
            return;
        }
        $new_node = $dom->createElement($new_name);
        if ($node->attributes) {
            foreach (iterator_to_array($node->attributes) as $attr) {
                $new_node->setAttribute($attr->nodeName, $attr->nodeValue);
            }
        }
        while ($node->firstChild) {
            $new_node->appendChild($node->firstChild);
        }
        $node->parentNode->replaceChild($new_node, $node);
    }

    private function stage_5_to_markdown(string $html): string {
        if (class_exists('\\League\\HTMLToMarkdown\\HtmlConverter')) {
            try {
                $converter = new \League\HTMLToMarkdown\HtmlConverter([
                    'strip_tags' => true,
                    'remove_nodes' => 'script style iframe form nav footer aside',
                    'hard_break' => false,
                    'header_style' => 'atx',
                    'italic_style' => '*',
                    'bold_style' => '**',
                    'list_item_style' => '-',
                ]);
                return (string) $converter->convert($html);
            } catch (\Throwable $e) {
                // Fall through to fallback.
            }
        }

        // Degraded fallback: strip tags. Loses structure but still shippable.
        return (string) wp_strip_all_tags($html);
    }

    private function stage_6_postprocess(string $markdown): string {
        // Collapse 3+ newlines to 2.
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        // Strip whitespace-only lines.
        $markdown = preg_replace("/^[ \t]+$/m", '', $markdown) ?? $markdown;

        // Trim trailing whitespace per line.
        $markdown = preg_replace("/[ \t]+\n/", "\n", $markdown) ?? $markdown;

        // Convert relative URLs to absolute.
        $home = rtrim((string) home_url(), '/');
        $markdown = preg_replace_callback(
            '/\]\((\/[^\)]*)\)/',
            static fn(array $m): string => '](' . $home . $m[1] . ')',
            $markdown
        ) ?? $markdown;

        // Drop links to wp-admin.
        $markdown = preg_replace('/\[[^\]]*\]\([^)]*\/wp-admin\/[^)]*\)/', '', $markdown) ?? $markdown;

        return rtrim($markdown) . "\n";
    }
}
