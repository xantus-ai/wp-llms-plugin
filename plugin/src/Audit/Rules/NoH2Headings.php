<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WP_Post;

final class NoH2Headings extends AbstractRule {
    private const LONG_CONTENT_WORDS = 800;

    public function key(): string {
        return 'no_h2_headings';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_INFO;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        $words = $this->word_count($post);
        if ($words < self::LONG_CONTENT_WORDS) {
            return null;
        }

        $rendered = $this->rendered_content($post);
        if (preg_match('/<h2[\s>]/i', $rendered)) {
            return null;
        }

        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            sprintf(
                /* translators: %d: word count */
                __('This page has %d words but no H2 subheadings. AI systems use heading structure to chunk and understand long content.', 'wp-llms'),
                $words
            ),
            __('Break the content into sections with descriptive H2 subheadings.', 'wp-llms')
        );
    }
}
