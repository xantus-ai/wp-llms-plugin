<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WP_Post;

final class LongTitle extends AbstractRule {
    private const MAX_LENGTH = 65;

    public function key(): string {
        return 'long_title';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_INFO;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        $title = trim((string) $post->post_title);
        if ($title === '' || mb_strlen($title) <= self::MAX_LENGTH) {
            return null;
        }

        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            sprintf(
                /* translators: %d: character count */
                __('Page title is %d characters. Long titles get truncated in search results.', 'llms-txt'),
                mb_strlen($title)
            ),
            __('Trim the title to under 65 characters while keeping the primary topic.', 'llms-txt')
        );
    }
}
