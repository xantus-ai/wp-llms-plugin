<?php
declare(strict_types=1);

namespace WPSearch\Audit\Rules;

use WPSearch\Audit\AuditContext;
use WPSearch\Audit\AuditIssue;
use WP_Post;

final class ThinContent extends AbstractRule {
    private const MIN_WORDS = 300;

    public function key(): string {
        return 'thin_content';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_WARNING;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        // Spec §13: applies to Page post type. Other custom types may legitimately
        // be short (e.g., FAQ items).
        if ($post->post_type !== 'page') {
            return null;
        }

        $words = $this->word_count($post);
        if ($words >= self::MIN_WORDS) {
            return null;
        }

        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            sprintf(
                /* translators: %d: word count */
                __('This page has only %1$d words. Pages with under %2$d words give AI systems little context.', 'wpsearch-ai'),
                $words,
                self::MIN_WORDS
            ),
            __('Expand the content to clearly explain the topic, who it\'s for, and what action to take.', 'wpsearch-ai')
        );
    }
}
