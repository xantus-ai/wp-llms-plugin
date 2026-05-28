<?php
declare(strict_types=1);

namespace WPSearch\Setup;

/**
 * Generates suggested sections from a SiteDetector report.
 * Each suggestion is shaped like the data accepted by SectionsRepository::create().
 */
final class SectionSuggester {
    private const STALE_THRESHOLD_MONTHS = 24;
    private const MIN_ITEMS_FOR_SECTION = 3;

    public function suggest(array $report): array {
        $suggestions = [];

        // About section from sample pages.
        if (!empty($report['sample_pages']['about'])) {
            $suggestions[] = [
                'suggestion_id' => 'about',
                'name' => 'About',
                'is_optional' => false,
                'sort_order' => 10,
                'inclusion_rule_json' => [
                    'type' => 'manual',
                    'post_ids' => $report['sample_pages']['about'],
                ],
                'preview_count' => count($report['sample_pages']['about']),
            ];
        }

        // One section per non-stale custom post type with enough items.
        $sort_order = 20;
        foreach ($report['post_types'] as $type) {
            if ($type['name'] === 'page' || $type['name'] === 'post') continue;
            if ($type['count'] < self::MIN_ITEMS_FOR_SECTION) continue;
            if (!empty($type['is_stale'])) continue;

            $suggestions[] = [
                'suggestion_id' => 'cpt_' . $type['name'],
                'name' => $type['label'],
                'is_optional' => false,
                'sort_order' => $sort_order,
                'inclusion_rule_json' => [
                    'type' => 'post_type',
                    'post_type' => $type['name'],
                    'limit' => 50,
                    'order_by' => 'date_desc',
                ],
                'preview_count' => $type['count'],
            ];
            $sort_order += 10;
        }

        // Blog highlights if there are many posts.
        $blog_count = $this->blog_post_count($report);
        if ($blog_count >= 30) {
            $suggestions[] = [
                'suggestion_id' => 'blog_highlights',
                'name' => 'Blog Highlights',
                'is_optional' => false,
                'sort_order' => $sort_order,
                'inclusion_rule_json' => [
                    'type' => 'manual',
                    'post_ids' => [],
                ],
                'preview_count' => 0,
                'requires_curation' => true,
                'note' => __('You\'ll need to hand-pick 20-30 evergreen posts in the Sections admin. Auto-including all blog posts would bloat the file.', 'wpsearch-ai'),
            ];
            $sort_order += 10;
        }

        // FAQ from sample pages.
        if (!empty($report['sample_pages']['faq'])) {
            $suggestions[] = [
                'suggestion_id' => 'faq',
                'name' => 'FAQ',
                'is_optional' => true,
                'sort_order' => $sort_order,
                'inclusion_rule_json' => [
                    'type' => 'manual',
                    'post_ids' => $report['sample_pages']['faq'],
                ],
                'preview_count' => count($report['sample_pages']['faq']),
            ];
            $sort_order += 10;
        }

        // Legal & Contact section.
        $legal_ids = array_merge(
            $report['sample_pages']['legal'] ?? [],
            $report['sample_pages']['contact'] ?? []
        );
        if (count($legal_ids) > 0) {
            $suggestions[] = [
                'suggestion_id' => 'legal',
                'name' => 'Legal & Contact',
                'is_optional' => true,
                'sort_order' => $sort_order,
                'inclusion_rule_json' => [
                    'type' => 'manual',
                    'post_ids' => $legal_ids,
                ],
                'preview_count' => count($legal_ids),
            ];
            $sort_order += 10;
        }

        // Events section.
        if (!empty($report['sample_pages']['events'])) {
            $suggestions[] = [
                'suggestion_id' => 'events',
                'name' => 'Events',
                'is_optional' => true,
                'sort_order' => $sort_order,
                'inclusion_rule_json' => [
                    'type' => 'manual',
                    'post_ids' => $report['sample_pages']['events'],
                ],
                'preview_count' => count($report['sample_pages']['events']),
            ];
        }

        return $suggestions;
    }

    private function blog_post_count(array $report): int {
        foreach ($report['post_types'] as $type) {
            if ($type['name'] === 'post') {
                return (int) $type['count'];
            }
        }
        return 0;
    }
}
