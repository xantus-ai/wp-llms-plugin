<?php
declare(strict_types=1);

namespace WPLlms\Generator;

use WPLlms\Storage\OverridesRepository;
use WP_Post;
use WP_Query;

/**
 * Resolves a section's inclusion_rule_json into an ordered list of WP_Post.
 * Per generator-spec.md §6.
 */
final class SectionResolver {
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 1000;

    public function __construct(
        private OverridesRepository $overrides
    ) {}

    /**
     * @return WP_Post[]
     */
    public function resolve(array $section): array {
        $rule = $this->parse_rule($section['inclusion_rule_json'] ?? '');
        $type = $rule['type'] ?? 'manual';

        $posts = match ($type) {
            'manual' => $this->resolve_manual($rule),
            'post_type' => $this->resolve_post_type($rule),
            'taxonomy' => $this->resolve_taxonomy($rule),
            'query' => $this->resolve_query($rule),
            default => [],
        };

        $section_id = isset($section['id']) ? (int) $section['id'] : null;
        return $this->apply_filters($posts, $section_id);
    }

    private function parse_rule(string|array $raw): array {
        if (is_array($raw)) {
            return $raw;
        }
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return WP_Post[]
     */
    private function resolve_manual(array $rule): array {
        $ids = array_map('intval', $rule['post_ids'] ?? []);
        $ids = array_filter($ids, static fn(int $id) => $id > 0);
        if (count($ids) === 0) {
            return [];
        }

        $query = new WP_Query([
            'post__in' => $ids,
            'orderby' => 'post__in',
            'posts_per_page' => count($ids),
            'post_status' => 'publish',
            'post_type' => 'any',
            'no_found_rows' => true,
        ]);

        return $query->posts;
    }

    /**
     * @return WP_Post[]
     */
    private function resolve_post_type(array $rule): array {
        $post_type = (string) ($rule['post_type'] ?? '');
        if ($post_type === '') {
            return [];
        }

        $limit = $this->normalize_limit($rule['limit'] ?? self::DEFAULT_LIMIT);
        $orderby = $this->parse_orderby($rule['order_by'] ?? 'date_desc');

        $query = new WP_Query([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => $orderby['orderby'],
            'order' => $orderby['order'],
            'no_found_rows' => true,
        ]);

        return $query->posts;
    }

    /**
     * @return WP_Post[]
     */
    private function resolve_taxonomy(array $rule): array {
        $taxonomy = (string) ($rule['taxonomy'] ?? '');
        $term_ids = array_map('intval', $rule['term_ids'] ?? []);
        $term_ids = array_filter($term_ids, static fn(int $id) => $id > 0);

        if ($taxonomy === '' || count($term_ids) === 0) {
            return [];
        }

        $limit = $this->normalize_limit($rule['limit'] ?? self::DEFAULT_LIMIT);
        $orderby = $this->parse_orderby($rule['order_by'] ?? 'date_desc');

        $query = new WP_Query([
            'post_type' => $rule['post_type'] ?? 'any',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => $orderby['orderby'],
            'order' => $orderby['order'],
            'tax_query' => [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_ids,
                ],
            ],
            'no_found_rows' => true,
        ]);

        return $query->posts;
    }

    /**
     * @return WP_Post[]
     */
    private function resolve_query(array $rule): array {
        $limit = $this->normalize_limit($rule['limit'] ?? self::DEFAULT_LIMIT);
        $orderby = $this->parse_orderby($rule['order_by'] ?? 'date_desc');

        $args = [
            'post_type' => $rule['post_type'] ?? 'any',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => $orderby['orderby'],
            'order' => $orderby['order'],
            'no_found_rows' => true,
        ];

        if (!empty($rule['tax_query']) && is_array($rule['tax_query'])) {
            $args['tax_query'] = $rule['tax_query'];
        }
        if (!empty($rule['meta_query']) && is_array($rule['meta_query'])) {
            $args['meta_query'] = $rule['meta_query'];
        }

        $query = new WP_Query($args);
        return $query->posts;
    }

    private function normalize_limit(mixed $limit): int {
        $value = (int) $limit;
        if ($value <= 0) {
            return self::DEFAULT_LIMIT;
        }
        return min($value, self::MAX_LIMIT);
    }

    /**
     * @return array{orderby:string,order:string}
     */
    private function parse_orderby(string $value): array {
        return match ($value) {
            'date_asc' => ['orderby' => 'date', 'order' => 'ASC'],
            'title_asc' => ['orderby' => 'title', 'order' => 'ASC'],
            'title_desc' => ['orderby' => 'title', 'order' => 'DESC'],
            'modified_desc' => ['orderby' => 'modified', 'order' => 'DESC'],
            'menu_order' => ['orderby' => 'menu_order', 'order' => 'ASC'],
            default => ['orderby' => 'date', 'order' => 'DESC'],
        };
    }

    /**
     * Apply visibility, exclusion, and override-sort filters per spec §6.
     *
     * @param WP_Post[] $posts
     * @return WP_Post[]
     */
    private function apply_filters(array $posts, ?int $section_id): array {
        $filtered = [];
        foreach ($posts as $post) {
            if (!$post instanceof WP_Post) continue;
            if ($post->post_status !== 'publish') continue;
            if ($post->post_password !== '') continue;
            if ($this->is_noindex($post)) continue;
            if ($section_id !== null && $this->overrides->is_excluded($post->ID, $section_id)) continue;

            $filtered[] = $post;
        }
        return $filtered;
    }

    private function is_noindex(WP_Post $post): bool {
        $yoast = get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true);
        if ($yoast === '1') {
            return true;
        }
        $rankmath = get_post_meta($post->ID, 'rank_math_robots', true);
        if (is_array($rankmath) && in_array('noindex', $rankmath, true)) {
            return true;
        }
        return false;
    }
}
