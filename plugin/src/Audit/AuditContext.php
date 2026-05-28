<?php
declare(strict_types=1);

namespace WPSearch\Audit;

use WPSearch\Generator\Extractor;
use WP_Post;

/**
 * Site-wide pre-computed data needed by rules that compare across posts
 * (generic_h1, duplicate_title). Built once per full-site audit run.
 */
final class AuditContext {
    /** @var array<string,int> */
    private array $title_frequency = [];

    /** @var array<string,int> */
    private array $h1_frequency = [];

    private string $tagline = '';
    private bool $built = false;

    public function __construct(private Extractor $extractor) {}

    /**
     * @param int[] $post_ids
     */
    public function build(array $post_ids): void {
        $this->tagline = (string) get_bloginfo('description');

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) continue;

            $title = $this->normalize_for_comparison((string) $post->post_title);
            if ($title !== '') {
                $this->title_frequency[$title] = ($this->title_frequency[$title] ?? 0) + 1;
            }

            $h1 = $this->extract_h1((string) $post->post_content);
            if ($h1 !== null && $h1 !== '') {
                $key = $this->normalize_for_comparison($h1);
                $this->h1_frequency[$key] = ($this->h1_frequency[$key] ?? 0) + 1;
            }
        }

        $this->built = true;
    }

    public function title_count(string $title): int {
        return $this->title_frequency[$this->normalize_for_comparison($title)] ?? 0;
    }

    public function h1_count(string $h1): int {
        return $this->h1_frequency[$this->normalize_for_comparison($h1)] ?? 0;
    }

    /**
     * @return array<string,int>
     */
    public function all_titles(): array {
        return $this->title_frequency;
    }

    public function tagline(): string {
        return $this->tagline;
    }

    public function is_built(): bool {
        return $this->built;
    }

    private function extract_h1(string $content): ?string {
        $rendered = apply_filters('the_content', $content);
        if (!is_string($rendered)) {
            return null;
        }
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $rendered, $m)) {
            $text = wp_strip_all_tags($m[1]);
            return trim((string) $text);
        }
        return null;
    }

    private function normalize_for_comparison(string $value): string {
        $value = wp_strip_all_tags($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }
}
