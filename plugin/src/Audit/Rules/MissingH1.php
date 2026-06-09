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
        // Default: skip every post type. The H1 on a rendered page can come
        // from many sources we cannot see from the audit context:
        //
        //   - The theme's single-{type}.php template (the_title() wrapped in <h1>)
        //   - WooCommerce single-product/title.php
        //   - Elementor Theme Builder Single-Page / Single-Post templates
        //   - Elementor Theme Builder header sections
        //   - Custom hooks injecting content into wp_head / the_content
        //
        // The audit can only inspect post_content and the post's own builder
        // widgets via get_builder_content_for_display() — a strict subset of
        // the actual rendered HTML. Any rule of the form "is there an H1 in
        // this subset" produces false positives whenever the H1 lives in any
        // of the sources above, which is the vast majority of real sites.
        //
        // The right way to know whether the rendered page actually has an
        // H1 is to fetch the front-end URL and parse it. That's an opt-in
        // future "accurate mode" rather than a default.
        //
        // For now: skip by default. Opt specific post types in via the
        // filter when you control the entire layout (e.g., a "landing_page"
        // CPT where the rendered output is exactly post_content / builder
        // widgets and nothing else):
        //   apply_filters('wpllms_missing_h1_force_check_post_types', ['landing_page'])
        $force_check = (array) apply_filters('wpllms_missing_h1_force_check_post_types', []);
        if (!in_array($post->post_type, $force_check, true)) {
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
