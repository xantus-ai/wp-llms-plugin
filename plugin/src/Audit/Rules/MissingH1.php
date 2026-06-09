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
        // Some post types are reliably rendered with the title as <h1> by the
        // theme or a plugin template — blog posts (theme single template),
        // WooCommerce products (single-product/title.php), etc. — and the H1
        // lives outside post_content. Skip the in-content H1 check for these,
        // unless the post is builder-driven (then the builder owns the H1).
        //
        // Filterable so site owners can add custom post types whose templates
        // render the title as H1 (apply_filters('wpllms_missing_h1_template_post_types', ...)).
        $template_post_types = (array) apply_filters('wpllms_missing_h1_template_post_types', ['post', 'product']);
        if (in_array($post->post_type, $template_post_types, true) && !$this->is_elementor_post($post->ID)) {
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
