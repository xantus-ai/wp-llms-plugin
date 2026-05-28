<?php
declare(strict_types=1);

namespace WPLlms\Generator;

use WPLlms\Storage\Options;

/**
 * Renders the H1 + blockquote + context paragraphs that lead every
 * llms.txt and llms-full.txt file. Per generator-spec.md §7.
 */
final class HeaderRenderer {
    public function render(): string {
        $settings = Options::get_settings();

        $h1 = trim((string) ($settings['site_h1'] ?? ''));
        if ($h1 === '') {
            $h1 = (string) get_bloginfo('name');
        }

        $summary = trim((string) ($settings['site_summary'] ?? ''));
        if ($summary === '') {
            $summary = (string) get_bloginfo('description');
        }

        $context = trim((string) ($settings['site_context'] ?? ''));

        $lines = [];
        $lines[] = '# ' . $h1;
        $lines[] = '';
        if ($summary !== '') {
            $lines[] = '> ' . $this->collapse_blockquote($summary);
        } else {
            $lines[] = '> ' . __('No summary set. Configure in WP LLMS settings.', 'wp-llms');
        }
        if ($context !== '') {
            $lines[] = '';
            $lines[] = $context;
        }

        return implode("\n", $lines);
    }

    private function collapse_blockquote(string $text): string {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
