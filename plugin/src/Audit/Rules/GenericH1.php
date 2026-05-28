<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WP_Post;

final class GenericH1 extends AbstractRule {
    public function needs_site_context(): bool {
        return true;
    }

    public function key(): string {
        return 'generic_h1';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_WARNING;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        if ($context === null) {
            return null;
        }

        $rendered = $this->rendered_content($post);
        $h1 = $this->first_h1($rendered);
        if ($h1 === null) {
            return null;
        }

        $tagline = $context->tagline();
        if ($tagline !== '' && strcasecmp($h1, $tagline) === 0) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                __('The H1 on this page matches your site tagline, which is generic boilerplate.', 'wp-llms'),
                __('Replace the H1 with text that describes what THIS specific page is about.', 'wp-llms')
            );
        }

        if ($context->h1_count($h1) > 3) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                sprintf(
                    /* translators: %d: count of pages sharing this H1 */
                    __('The H1 "%1$s" appears on %2$d pages across your site. Generic H1s prevent AI systems from differentiating your pages.', 'wp-llms'),
                    $h1,
                    $context->h1_count($h1)
                ),
                __('Make this page\'s H1 unique - describe what is on THIS page.', 'wp-llms')
            );
        }

        // Short H1 with no overlap with the post title.
        $word_count = str_word_count($h1);
        if ($word_count > 0 && $word_count < 4) {
            $title_words = $this->extract_meaningful_words((string) $post->post_title);
            $h1_words = $this->extract_meaningful_words($h1);
            $overlap = array_intersect($title_words, $h1_words);
            if (count($overlap) === 0) {
                return new AuditIssue(
                    $post->ID,
                    $this->key(),
                    $this->severity(),
                    __('The H1 is short and doesn\'t share any keywords with the page title. AI systems may not understand what this page covers.', 'wp-llms'),
                    __('Use an H1 that includes the key topic of the page.', 'wp-llms')
                );
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extract_meaningful_words(string $text): array {
        $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'at', 'with', 'is', 'are', 'be', 'your', 'our', 'my'];
        $words = preg_split('/[\s\-,;.:!?\'"()]+/u', mb_strtolower($text)) ?: [];
        return array_values(array_filter(
            $words,
            static fn(string $w) => $w !== '' && !in_array($w, $stop, true) && mb_strlen($w) > 2
        ));
    }
}
