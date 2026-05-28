<?php
declare(strict_types=1);

namespace WPSearch\Frontend;

use WPSearch\Generator\DescriptionResolver;
use WPSearch\Generator\Extractor;
use WPSearch\Generator\HeaderRenderer;
use WPSearch\Generator\LlmsFullTxtGenerator;
use WPSearch\Generator\LlmsTxtGenerator;
use WPSearch\Generator\MdEndpointRenderer;
use WPSearch\Generator\SectionResolver;
use WPSearch\Generator\TitleResolver;
use WPSearch\Storage\Options;
use WPSearch\Storage\OverridesRepository;
use WPSearch\Storage\SectionsRepository;
use WP_Post;

/**
 * Serves /llms.txt, /llms-full.txt, and /{slug}.md per spec §8.
 */
final class FileServer {
    public const QUERY_VAR = 'wpsearch_file';
    public const MD_QUERY_VAR = 'wpsearch_md_slug';
    public const CACHE_KEY_LLMS = 'wpsearch_llms_txt_cache';
    public const CACHE_KEY_LLMS_FULL = 'wpsearch_llms_full_txt_cache';
    public const CACHE_KEY_MD_PREFIX = 'wpsearch_md_';

    public function register_hooks(): void {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve']);
    }

    public function register_rewrite_rules(): void {
        add_rewrite_rule(
            '^llms\.txt$',
            'index.php?' . self::QUERY_VAR . '=llms',
            'top'
        );
        add_rewrite_rule(
            '^llms-full\.txt$',
            'index.php?' . self::QUERY_VAR . '=full',
            'top'
        );
        // Per-page .md - slug must start with a letter or digit and may contain
        // hyphens and slashes (for nested page paths). Excludes leading dots,
        // wp- prefix, and feed-like patterns.
        add_rewrite_rule(
            '^([a-z0-9][a-z0-9\-/]*)\.md$',
            'index.php?' . self::MD_QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    public function register_query_vars(array $vars): array {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::MD_QUERY_VAR;
        return $vars;
    }

    public function maybe_serve(): void {
        $file = get_query_var(self::QUERY_VAR);
        if ($file === 'llms') {
            $this->serve_llms_txt();
            return;
        }
        if ($file === 'full') {
            $this->serve_llms_full_txt();
            return;
        }

        $md_slug = (string) get_query_var(self::MD_QUERY_VAR);
        if ($md_slug !== '') {
            $this->serve_md($md_slug);
        }
    }

    private function serve_llms_txt(): void {
        $cached = get_transient(self::CACHE_KEY_LLMS);
        if (is_string($cached) && $cached !== '') {
            $this->emit($cached);
            return;
        }

        $content = $this->build_llms_generator()->generate();
        set_transient(self::CACHE_KEY_LLMS, $content, 5 * MINUTE_IN_SECONDS);
        $this->emit($content);
    }

    private function serve_llms_full_txt(): void {
        $cached = get_transient(self::CACHE_KEY_LLMS_FULL);
        if (is_string($cached) && $cached !== '') {
            $this->emit($cached);
            return;
        }

        try {
            $content = $this->build_llms_full_generator()->generate();
        } catch (\Throwable $e) {
            $this->emit("# llms-full.txt\n\n_(Generator error.)_\n", 500);
            return;
        }

        // Cache for longer than llms.txt because regeneration is expensive.
        set_transient(self::CACHE_KEY_LLMS_FULL, $content, HOUR_IN_SECONDS);
        $this->emit($content);
    }

    private function serve_md(string $slug): void {
        $settings = Options::get_settings();
        if (empty($settings['serve_md_variants'])) {
            $this->emit_404();
            return;
        }

        // Strip trailing/leading slashes that might leak through rewrites.
        $slug = trim($slug, '/');
        if ($slug === '' || str_contains($slug, '..')) {
            $this->emit_404();
            return;
        }

        $cache_key = self::CACHE_KEY_MD_PREFIX . md5($slug);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            $this->emit($cached);
            return;
        }

        $post = $this->find_post_by_slug($slug);
        if ($post === null) {
            $this->emit_404();
            return;
        }

        $renderer = $this->build_md_renderer();
        $content = $renderer->render($post);
        set_transient($cache_key, $content, HOUR_IN_SECONDS);
        $this->emit($content);
    }

    private function find_post_by_slug(string $slug): ?WP_Post {
        // Try as a page path first (handles nested pages like parent/child).
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page instanceof WP_Post && $this->is_publicly_servable($page)) {
            return $page;
        }

        // Try other public post types via direct slug lookup.
        $eligible = $this->eligible_post_types();
        $last_segment = basename($slug);
        foreach ($eligible as $post_type) {
            if ($post_type === 'page') continue;
            $found = get_page_by_path($last_segment, OBJECT, $post_type);
            if ($found instanceof WP_Post && $this->is_publicly_servable($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function eligible_post_types(): array {
        $public = get_post_types(['public' => true], 'names');
        $excluded = ['attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'];
        return array_values(array_diff(array_values($public), $excluded));
    }

    private function is_publicly_servable(WP_Post $post): bool {
        if ($post->post_status !== 'publish') return false;
        if ($post->post_password !== '') return false;
        // Respect noindex.
        if (get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true) === '1') return false;
        $rm = get_post_meta($post->ID, 'rank_math_robots', true);
        if (is_array($rm) && in_array('noindex', $rm, true)) return false;
        return true;
    }

    private function emit(string $content, int $status = 200): void {
        $etag = '"' . md5($content) . '"';
        $last_modified = gmdate('D, d M Y H:i:s') . ' GMT';

        if (function_exists('status_header')) {
            status_header($status);
        }

        header('Content-Type: text/markdown; charset=UTF-8');
        header('Cache-Control: public, max-age=300, must-revalidate');
        header('Last-Modified: ' . $last_modified);
        header('ETag: ' . $etag);
        header('X-Robots-Tag: all');
        header('X-Generated-By: WPSearch/' . WPSEARCH_VERSION);

        $if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($if_none_match) && $if_none_match === $etag) {
            if (function_exists('status_header')) {
                status_header(304);
            }
            exit;
        }

        echo $content;
        exit;
    }

    private function emit_404(): void {
        if (function_exists('status_header')) {
            status_header(404);
        }
        header('Content-Type: text/markdown; charset=UTF-8');
        echo (new MdEndpointRenderer(
            new TitleResolver(new OverridesRepository()),
            new DescriptionResolver(new Extractor(), new OverridesRepository()),
            new Extractor()
        ))->render_404();
        exit;
    }

    public static function invalidate_cache(): void {
        delete_transient(self::CACHE_KEY_LLMS);
        delete_transient(self::CACHE_KEY_LLMS_FULL);

        // Best-effort flush of all per-slug .md transients. WordPress doesn't
        // expose a wildcard-delete for transients, so we do it via direct
        // option-table cleanup.
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . self::CACHE_KEY_MD_PREFIX . '%',
            '_transient_timeout_' . self::CACHE_KEY_MD_PREFIX . '%'
        ));
    }

    private function build_llms_generator(): LlmsTxtGenerator {
        $sections = new SectionsRepository();
        $overrides = new OverridesRepository();
        $extractor = new Extractor();

        return new LlmsTxtGenerator(
            $sections,
            new SectionResolver($overrides),
            new TitleResolver($overrides),
            new DescriptionResolver($extractor, $overrides),
            new HeaderRenderer()
        );
    }

    private function build_llms_full_generator(): LlmsFullTxtGenerator {
        $sections = new SectionsRepository();
        $overrides = new OverridesRepository();
        $extractor = new Extractor();

        return new LlmsFullTxtGenerator(
            $sections,
            new SectionResolver($overrides),
            new TitleResolver($overrides),
            new DescriptionResolver($extractor, $overrides),
            $extractor,
            new HeaderRenderer()
        );
    }

    private function build_md_renderer(): MdEndpointRenderer {
        $overrides = new OverridesRepository();
        $extractor = new Extractor();

        return new MdEndpointRenderer(
            new TitleResolver($overrides),
            new DescriptionResolver($extractor, $overrides),
            $extractor
        );
    }
}
