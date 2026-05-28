<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WP_Post;

final class NoInternalLinks extends AbstractRule {
    public function key(): string {
        return 'no_internal_links';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_INFO;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        if ($post->post_type === 'attachment') {
            return null;
        }

        $rendered = $this->rendered_content($post);
        if ($rendered === '') {
            return null;
        }

        $home = (string) home_url();
        $home_host = (string) parse_url($home, PHP_URL_HOST);
        if ($home_host === '') {
            return null;
        }

        if (!preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $rendered, $matches)) {
            return $this->build_issue($post);
        }

        foreach ($matches[1] as $href) {
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            // Relative paths count as internal.
            if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
                return null;
            }
            $host = (string) parse_url($href, PHP_URL_HOST);
            if ($host === $home_host) {
                return null;
            }
        }

        return $this->build_issue($post);
    }

    private function build_issue(WP_Post $post): AuditIssue {
        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            __('No internal links found in content. Internal links help AI systems understand how pages relate.', 'llms-txt'),
            __('Link to related pages on your site within the content.', 'llms-txt')
        );
    }
}
