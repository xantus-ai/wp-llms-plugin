<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WPLlms\Storage\Options;
use WP_Post;

final class StaleContent extends AbstractRule {
    private const DEFAULT_THRESHOLD_MONTHS = 24;

    public function key(): string {
        return 'stale_content';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_INFO;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        $threshold = $this->threshold_months();
        $modified = strtotime((string) $post->post_modified_gmt);
        if ($modified === false) {
            return null;
        }

        $age_seconds = time() - $modified;
        $age_months = (int) floor($age_seconds / (30 * DAY_IN_SECONDS));

        if ($age_months < $threshold) {
            return null;
        }

        return new AuditIssue(
            $post->ID,
            $this->key(),
            $this->severity(),
            sprintf(
                /* translators: 1: age in months, 2: threshold in months */
                __('This content was last updated %1$d months ago (threshold: %2$d months). Stale content signals to AI systems that the site may not be actively maintained.', 'llms-txt'),
                $age_months,
                $threshold
            ),
            __('Either update the content with current information, or exclude this page from llms.txt if it is no longer maintained.', 'llms-txt')
        );
    }

    private function threshold_months(): int {
        $settings = Options::get_settings();
        $value = (int) ($settings['stale_threshold_months'] ?? self::DEFAULT_THRESHOLD_MONTHS);
        return max(1, $value);
    }
}
