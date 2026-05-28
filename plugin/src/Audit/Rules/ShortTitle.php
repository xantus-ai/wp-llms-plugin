<?php
declare(strict_types=1);

namespace WPSearch\Audit\Rules;

use WPSearch\Audit\AuditContext;
use WPSearch\Audit\AuditIssue;
use WP_Post;

final class ShortTitle extends AbstractRule {
    private const MIN_LENGTH = 30;

    public function key(): string {
        return 'short_title';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_WARNING;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        $title = trim((string) $post->post_title);
        if ($title === '' || mb_strlen($title) >= self::MIN_LENGTH) {
            return null;
        }

        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            sprintf(
                /* translators: 1: title, 2: char count */
                __('The page title "%1$s" is only %2$d characters. Short titles give AI systems less context.', 'wpsearch-ai'),
                $title,
                mb_strlen($title)
            ),
            __('Expand the title to be more descriptive. Aim for 30-65 characters that include the page\'s primary topic.', 'wpsearch-ai')
        );
    }
}
