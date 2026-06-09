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
        // Default: skip non-builder posts. The theme's single-{type}.php
        // template hierarchy almost universally renders the_title() as <h1>,
        // so the H1 lives outside post_content for normal posts, pages, and
        // custom post types. The rule would otherwise flag every non-builder
        // post as missing an H1, which is a false positive.
        //
        // Builder-driven posts (Elementor, etc.) always get their rendered
        // content checked — the builder owns the H1, and there's no way to
        // know whether the builder template includes it without inspecting.
        //
        // Site owners can force the in-content H1 check for specific post
        // types — useful for custom landing-page post types whose templates
        // intentionally don't render the title — via:
        //   apply_filters('wpllms_missing_h1_force_check_post_types', ...)
        if (!$this->is_elementor_post($post->ID)) {
            $force_check = (array) apply_filters('wpllms_missing_h1_force_check_post_types', []);
            if (!in_array($post->post_type, $force_check, true)) {
                return null;
            }
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
