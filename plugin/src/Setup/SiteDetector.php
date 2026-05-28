<?php
declare(strict_types=1);

namespace WPLlms\Setup;

/**
 * Inspects the WordPress installation and returns a structured report
 * of what's present. Drives the setup wizard's site-detection step.
 */
final class SiteDetector {
    public function detect(): array {
        return [
            'site_name' => (string) get_bloginfo('name'),
            'site_description' => (string) get_bloginfo('description'),
            'home_url' => (string) home_url(),
            'post_types' => $this->detect_post_types(),
            'taxonomies' => $this->detect_taxonomies(),
            'seo_plugin' => $this->detect_seo_plugin(),
            'meta_coverage' => $this->meta_description_coverage(),
            'builder' => $this->detect_builder(),
            'woocommerce' => class_exists('WooCommerce'),
            'robots_txt_references_llms' => $this->robots_txt_references_llms(),
            'sample_pages' => $this->detect_sample_pages(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detect_post_types(): array {
        $excluded = ['attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'];
        $public = get_post_types(['public' => true], 'objects');

        $out = [];
        foreach ($public as $type) {
            if (in_array($type->name, $excluded, true)) continue;

            $count = (int) wp_count_posts($type->name)->publish;
            if ($count === 0) continue;

            $newest = $this->newest_post_date($type->name);
            $age_months = $this->months_ago($newest);

            $out[] = [
                'name' => $type->name,
                'label' => (string) ($type->labels->name ?? $type->name),
                'count' => $count,
                'newest_modified_gmt' => $newest,
                'age_months' => $age_months,
                'is_stale' => $age_months !== null && $age_months >= 24,
            ];
        }

        usort($out, static fn(array $a, array $b) => $b['count'] <=> $a['count']);
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detect_taxonomies(): array {
        $tax = get_taxonomies(['public' => true], 'objects');
        $excluded = ['post_format', 'nav_menu', 'link_category'];
        $out = [];
        foreach ($tax as $taxonomy) {
            if (in_array($taxonomy->name, $excluded, true)) continue;
            $term_count = wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => true]);
            $term_count = is_wp_error($term_count) ? 0 : (int) $term_count;
            if ($term_count === 0) continue;

            $out[] = [
                'name' => $taxonomy->name,
                'label' => (string) ($taxonomy->labels->name ?? $taxonomy->name),
                'term_count' => $term_count,
            ];
        }
        return $out;
    }

    private function detect_seo_plugin(): ?string {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            return 'yoast';
        }
        if (class_exists('RankMath') || defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }
        if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) {
            return 'aioseo';
        }
        if (defined('SEOPRESS_VERSION')) {
            return 'seopress';
        }
        return null;
    }

    /**
     * @return array{set:int,total:int,coverage_pct:float}
     */
    private function meta_description_coverage(): array {
        global $wpdb;
        $seo = $this->detect_seo_plugin();
        $meta_key = match ($seo) {
            'yoast' => '_yoast_wpseo_metadesc',
            'rankmath' => 'rank_math_description',
            'aioseo' => '_aioseo_description',
            'seopress' => '_seopress_titles_desc',
            default => null,
        };

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')"
        );

        if ($meta_key === null || $total === 0) {
            return ['set' => 0, 'total' => $total, 'coverage_pct' => 0.0];
        }

        $set = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_status = 'publish' AND p.post_type IN ('post', 'page')
             AND m.meta_key = %s AND m.meta_value != ''",
            $meta_key
        ));

        return [
            'set' => $set,
            'total' => $total,
            'coverage_pct' => $total > 0 ? round(($set / $total) * 100, 1) : 0.0,
        ];
    }

    private function detect_builder(): ?string {
        if (class_exists('\\Elementor\\Plugin')) {
            return 'elementor';
        }
        if (defined('ET_BUILDER_VERSION')) {
            return 'divi';
        }
        if (defined('FL_BUILDER_VERSION')) {
            return 'beaver';
        }
        if (defined('BRICKS_VERSION')) {
            return 'bricks';
        }
        return null;
    }

    private function robots_txt_references_llms(): bool {
        $content = $this->fetch_robots_txt();
        if ($content === null) return false;
        return stripos($content, 'llms.txt') !== false || stripos($content, 'llms-full.txt') !== false;
    }

    private function fetch_robots_txt(): ?string {
        $url = home_url('/robots.txt');
        $response = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response)) return null;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) return null;
        $body = wp_remote_retrieve_body($response);
        return is_string($body) ? $body : null;
    }

    /**
     * @return array<string,int[]>
     */
    private function detect_sample_pages(): array {
        $slugs = [
            'about' => ['about', 'about-us', 'our-story', 'story', 'team', 'our-team'],
            'faq' => ['faq', 'faqs', 'frequently-asked-questions'],
            'contact' => ['contact', 'contact-us'],
            'legal' => ['privacy', 'privacy-policy', 'terms', 'terms-of-service', 'tos'],
            'events' => ['events', 'event', 'retreats', 'workshops'],
            'pricing' => ['pricing', 'plans', 'membership'],
        ];

        $out = [];
        foreach ($slugs as $key => $patterns) {
            $matches = [];
            foreach ($patterns as $slug) {
                $page = get_page_by_path($slug);
                if ($page && $page->post_status === 'publish') {
                    $matches[] = (int) $page->ID;
                }
            }
            if (count($matches) > 0) {
                $out[$key] = $matches;
            }
        }
        return $out;
    }

    private function newest_post_date(string $post_type): ?string {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = %s",
            $post_type
        ));
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function months_ago(?string $date_gmt): ?int {
        if ($date_gmt === null) return null;
        $ts = strtotime($date_gmt);
        if ($ts === false) return null;
        $age_seconds = time() - $ts;
        return (int) floor($age_seconds / (30 * DAY_IN_SECONDS));
    }
}
