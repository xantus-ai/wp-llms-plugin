<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WP_Post;

final class MissingH1 extends AbstractRule {
    public function key(): string {
        return 'missing_h1';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_CRITICAL;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        // Classic blog posts typically rely on theme-rendered title h1.
        // Most useful for builder-based or page post types.
        if ($post->post_type === 'post' && !$this->is_elementor_post($post->ID)) {
            return null;
        }

        $rendered = $this->rendered_content($post);
        if ($this->count_h1s($rendered) > 0) {
            return null;
        }

        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            __('No <h1> heading found in page content. AI search systems rely on the H1 to understand what a page is about.', 'llms-txt'),
            __('Add an H1 heading near the top of the page that clearly states the topic.', 'llms-txt')
        );
    }
}
