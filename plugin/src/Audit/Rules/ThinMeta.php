<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WP_Post;

final class ThinMeta extends AbstractRule {
    private const MIN_LENGTH = 70;

    public function key(): string {
        return 'thin_meta';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_WARNING;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        $meta = $this->get_meta_description($post->ID);

        if ($meta === '') {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                __('No meta description set. AI systems will fall back to extracting from page content, which is less reliable.', 'wp-llms'),
                __('Add a meta description in your SEO plugin (Yoast, RankMath, etc.) of 70-160 characters that describes the page.', 'wp-llms')
            );
        }

        if (mb_strlen($meta) < self::MIN_LENGTH) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                sprintf(
                    /* translators: %d: character count */
                    __('Meta description is only %d characters. Aim for 70-160 characters for full context.', 'wp-llms'),
                    mb_strlen($meta)
                ),
                __('Expand the meta description to give AI systems a clearer summary of the page.', 'wp-llms')
            );
        }

        return null;
    }
}
