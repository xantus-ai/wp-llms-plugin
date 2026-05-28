<?php
declare(strict_types=1);

namespace WPSearch\Audit\Rules;

use WPSearch\Audit\AuditContext;
use WPSearch\Audit\AuditIssue;
use WP_Post;

final class MultipleH1 extends AbstractRule {
    public function key(): string {
        return 'multiple_h1';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_WARNING;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        $rendered = $this->rendered_content($post);
        $count = $this->count_h1s($rendered);
        if ($count <= 1) {
            return null;
        }

        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            sprintf(
                /* translators: %d: count of h1 headings */
                __('Found %d <h1> headings on this page. There should be exactly one.', 'wpsearch-ai'),
                $count
            ),
            __('Demote extra H1s to H2 or lower. Multiple H1s confuse AI systems trying to identify the page topic.', 'wpsearch-ai')
        );
    }
}
