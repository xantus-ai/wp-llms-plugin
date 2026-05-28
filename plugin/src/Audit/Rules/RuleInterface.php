<?php
declare(strict_types=1);

namespace WPSearch\Audit\Rules;

use WPSearch\Audit\AuditContext;
use WPSearch\Audit\AuditIssue;
use WP_Post;

interface RuleInterface {
    public function key(): string;

    public function severity(): string;

    /**
     * Whether this rule needs site-wide pre-computed context (frequency maps).
     * Site-context rules are skipped during per-post (save_post) audits and
     * only run during full-site audits.
     */
    public function needs_site_context(): bool;

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue;
}
