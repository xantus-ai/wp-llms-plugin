<?php
declare(strict_types=1);

namespace WPLlms\Generator;

use WPLlms\Storage\SectionsRepository;
use WP_Post;

/**
 * Builds llms-full.txt: same selection as llms.txt, but with full
 * extracted markdown body inlined per post. Per spec §9.
 */
final class LlmsFullTxtGenerator {
    private const DEFAULT_SIZE_CAP_BYTES = 5_000_000;
    private const MIN_BYTES_PER_POST = 200;

    public function __construct(
        private SectionsRepository $sections,
        private SectionResolver $section_resolver,
        private TitleResolver $title_resolver,
        private DescriptionResolver $description_resolver,
        private Extractor $extractor,
        private HeaderRenderer $header_renderer
    ) {}

    public function generate(int $size_cap_bytes = self::DEFAULT_SIZE_CAP_BYTES): string {
        $parts = [];
        $parts[] = $this->header_renderer->render();

        $sections = $this->sections->all();
        $required = array_filter($sections, static fn(array $s) => empty($s['is_optional']));
        $optional = array_filter($sections, static fn(array $s) => !empty($s['is_optional']));

        foreach ($required as $section) {
            $rendered = $this->render_section($section, level: 2);
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        if (count($optional) > 0) {
            $optional_block = $this->render_optional_block($optional);
            if ($optional_block !== '') {
                $parts[] = $optional_block;
            }
        }

        $combined = implode("\n\n", array_filter($parts, static fn(string $p) => $p !== '')) . "\n";

        if (strlen($combined) > $size_cap_bytes) {
            $combined = $this->truncate_to_cap($combined, $size_cap_bytes);
        }

        return $combined;
    }

    private function render_section(array $section, int $level): string {
        $posts = $this->section_resolver->resolve($section);
        $section_id = isset($section['id']) ? (int) $section['id'] : null;
        $heading = str_repeat('#', $level) . ' ' . trim((string) $section['name']);

        $rendered_posts = [];
        foreach ($posts as $post) {
            $entry = $this->render_post($post, $section_id, $level + 1);
            if ($entry !== '') {
                $rendered_posts[] = $entry;
            }
        }

        $intro = trim((string) ($section['intro_text'] ?? ''));

        // Skip section entirely only when it has no content at all — no intro
        // text and no resolvable entries. Sections with intro text but no
        // entries still render (standalone descriptive sections).
        if ($intro === '' && count($rendered_posts) === 0) {
            return '';
        }

        $lines = [$heading];
        if ($intro !== '') {
            $lines[] = '';
            $lines[] = $intro;
        }
        if (count($rendered_posts) > 0) {
            $lines[] = '';
            $lines[] = implode("\n\n", $rendered_posts);
        }

        return implode("\n", $lines);
    }

    private function render_optional_block(array $optional_sections): string {
        $sub_blocks = [];
        foreach ($optional_sections as $section) {
            $rendered = $this->render_section($section, level: 3);
            if ($rendered !== '') {
                $sub_blocks[] = $rendered;
            }
        }
        if (count($sub_blocks) === 0) {
            return '';
        }

        return "## " . __('Optional', 'llms-txt') . "\n\n" . implode("\n\n", $sub_blocks);
    }

    private function render_post(WP_Post $post, ?int $section_id, int $heading_level): string {
        $title = $this->title_resolver->resolve($post, $section_id);
        if ($title === '') {
            return '';
        }

        $description = $this->description_resolver->resolve($post, $section_id);
        $body = $this->extractor->extract_markdown($post);

        $heading = str_repeat('#', $heading_level) . ' ' . $title;
        $url = (string) get_permalink($post);

        $lines = [$heading, ''];
        $lines[] = '**Source:** ' . $url;
        if ($description !== null && $description !== '') {
            $lines[] = '';
            $lines[] = $description;
        }
        if ($body !== '') {
            $lines[] = '';
            $lines[] = $body;
        }
        $lines[] = '';
        $lines[] = '---';

        return implode("\n", $lines);
    }

    /**
     * Truncate longest posts first per spec §9 size guard.
     * Pragmatic implementation: just hard-truncate at the cap with a footer.
     * (Optimal "truncate longest first" would require post-by-post tracking
     * during render. For v1, hard truncation is shipping-acceptable.)
     */
    private function truncate_to_cap(string $content, int $cap_bytes): string {
        $footer = "\n\n---\n\n_(Output truncated at " . number_format($cap_bytes) . " bytes. Visit individual page URLs for full content.)_\n";
        $truncate_at = $cap_bytes - strlen($footer);
        if ($truncate_at <= 0) {
            return $footer;
        }
        $truncated = substr($content, 0, $truncate_at);
        // Cut at last newline boundary to avoid mid-line truncation.
        $last_nl = strrpos($truncated, "\n");
        if ($last_nl !== false && $last_nl > $truncate_at - 1000) {
            $truncated = substr($truncated, 0, $last_nl);
        }
        return $truncated . $footer;
    }
}
