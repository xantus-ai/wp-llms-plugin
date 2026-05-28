<?php
declare(strict_types=1);

namespace WPLlms\Generator;

use WPLlms\Storage\SectionsRepository;
use WP_Post;

/**
 * Assembles llms.txt from sections, descriptions, and titles per
 * generator-spec.md §2 and §6-7.
 */
final class LlmsTxtGenerator {
    public function __construct(
        private SectionsRepository $sections,
        private SectionResolver $section_resolver,
        private TitleResolver $title_resolver,
        private DescriptionResolver $description_resolver,
        private HeaderRenderer $header_renderer
    ) {}

    public function generate(): string {
        $parts = [];
        $parts[] = $this->header_renderer->render();

        $sections = $this->sections->all();
        $required = array_filter($sections, static fn(array $s) => empty($s['is_optional']));
        $optional = array_filter($sections, static fn(array $s) => !empty($s['is_optional']));

        foreach ($required as $section) {
            $rendered = $this->render_section($section);
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        if (count($optional) > 0) {
            $rendered = $this->render_optional_block($optional);
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        return implode("\n\n", array_filter($parts, static fn(string $p) => $p !== '')) . "\n";
    }

    private function render_section(array $section): string {
        $posts = $this->section_resolver->resolve($section);
        if (count($posts) === 0) {
            return '';
        }

        $section_id = isset($section['id']) ? (int) $section['id'] : null;
        $entries = [];
        foreach ($posts as $post) {
            $entry = $this->render_entry($post, $section_id);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if (count($entries) === 0) {
            return '';
        }

        $lines = [];
        $lines[] = '## ' . trim((string) $section['name']);

        $intro = trim((string) ($section['intro_text'] ?? ''));
        if ($intro !== '') {
            $lines[] = '';
            $lines[] = $intro;
        }

        $lines[] = '';
        foreach ($entries as $entry) {
            $lines[] = $entry;
        }

        return implode("\n", $lines);
    }

    private function render_optional_block(array $optional_sections): string {
        $lines = ['## ' . __('Optional', 'llms-txt')];

        $any_rendered = false;
        foreach ($optional_sections as $section) {
            $posts = $this->section_resolver->resolve($section);
            if (count($posts) === 0) continue;

            $section_id = isset($section['id']) ? (int) $section['id'] : null;
            $entries = [];
            foreach ($posts as $post) {
                $entry = $this->render_entry($post, $section_id);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
            if (count($entries) === 0) continue;

            $lines[] = '';
            $lines[] = '### ' . trim((string) $section['name']);

            $intro = trim((string) ($section['intro_text'] ?? ''));
            if ($intro !== '') {
                $lines[] = '';
                $lines[] = $intro;
            }

            $lines[] = '';
            foreach ($entries as $entry) {
                $lines[] = $entry;
            }
            $any_rendered = true;
        }

        return $any_rendered ? implode("\n", $lines) : '';
    }

    private function render_entry(WP_Post $post, ?int $section_id): ?string {
        $title = $this->title_resolver->resolve($post, $section_id);
        if ($title === '') {
            return null;
        }

        $description = $this->description_resolver->resolve($post, $section_id);
        if ($description === null) {
            return null;
        }

        $url = (string) get_permalink($post);
        if ($url === '') {
            return null;
        }

        return sprintf('- [%s](%s): %s', $title, $url, $description);
    }
}
