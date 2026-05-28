<?php
declare(strict_types=1);

namespace WPLlms\Generator;

use WPLlms\Storage\Options;
use WP_Post;

/**
 * Renders per-page .md endpoint content per spec §8.
 */
final class MdEndpointRenderer {
    public function __construct(
        private TitleResolver $title_resolver,
        private DescriptionResolver $description_resolver,
        private Extractor $extractor
    ) {}

    public function render(WP_Post $post): string {
        $title = $this->title_resolver->resolve($post);
        if ($title === '') {
            $title = (string) get_the_title($post);
        }

        $description = $this->description_resolver->resolve($post);
        $body = $this->extractor->extract_markdown($post);

        $settings = Options::get_settings();
        $include_metadata = !empty($settings['serve_md_variants']);

        $lines = ['# ' . $title, ''];

        if ($description !== null && $description !== '') {
            $lines[] = $description;
            $lines[] = '';
        }

        if ($include_metadata) {
            $lines[] = $this->render_metadata_block($post);
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }

        if ($body !== '') {
            $lines[] = $body;
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    public function render_404(): string {
        return "# Not Found\n\nThis page does not exist or is not publicly available.\n";
    }

    private function render_metadata_block(WP_Post $post): string {
        $published = mysql2date('Y-m-d', $post->post_date_gmt, false);
        $modified = mysql2date('Y-m-d', $post->post_modified_gmt, false);
        $author = get_the_author_meta('display_name', (int) $post->post_author);
        $url = (string) get_permalink($post);

        $categories = '';
        $cats = get_the_category((int) $post->ID);
        if (is_array($cats) && count($cats) > 0) {
            $names = array_map(static fn($c) => $c->name, $cats);
            $categories = implode(', ', $names);
        }

        $lines = [];
        if ($published) $lines[] = '**Published:** ' . $published;
        if ($modified && $modified !== $published) $lines[] = '**Updated:** ' . $modified;
        if ($categories !== '') $lines[] = '**Categories:** ' . $categories;
        if ($author !== '') $lines[] = '**Author:** ' . $author;
        if ($url !== '') $lines[] = '**Source:** ' . $url;

        return implode("\n", $lines);
    }
}
