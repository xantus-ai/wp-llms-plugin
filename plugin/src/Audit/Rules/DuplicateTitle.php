<?php
declare(strict_types=1);

namespace WPSearch\Audit\Rules;

use WPSearch\Audit\AuditContext;
use WPSearch\Audit\AuditIssue;
use WP_Post;

final class DuplicateTitle extends AbstractRule {
    public function needs_site_context(): bool {
        return true;
    }

    public function key(): string {
        return 'duplicate_title';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_WARNING;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        if ($context === null) {
            return null;
        }

        $title = trim((string) $post->post_title);
        if ($title === '') {
            return null;
        }

        if ($context->title_count($title) > 1) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                sprintf(
                    /* translators: %d: number of pages sharing this exact title */
                    __('The page title "%1$s" is used by %2$d pages on your site.', 'wpsearch-ai'),
                    $title,
                    $context->title_count($title)
                ),
                __('Make each page title unique. Duplicates prevent AI systems from distinguishing your pages.', 'wpsearch-ai')
            );
        }

        // Approximate-match check via Levenshtein for close-but-not-identical duplicates.
        $similar = $this->find_similar_title($title, $context, $post);
        if ($similar !== null) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                sprintf(
                    /* translators: %s: similar title */
                    __('Title is very similar to another page: "%s"', 'wpsearch-ai'),
                    $similar
                ),
                __('Differentiate the titles to clarify what each page covers.', 'wpsearch-ai')
            );
        }

        return null;
    }

    private function find_similar_title(string $title, AuditContext $context, WP_Post $self): ?string {
        $needle = mb_strtolower($title);
        $needle_len = mb_strlen($needle);
        if ($needle_len < 15) {
            return null; // Too short for useful Levenshtein.
        }

        foreach ($context->all_titles() as $other => $count) {
            if ($other === $needle) continue;

            $other_len = mb_strlen($other);
            if (abs($other_len - $needle_len) > max(5, (int) ($needle_len * 0.2))) {
                continue;
            }

            // levenshtein() works on bytes; cap input to avoid PHP's 255-char limit.
            $a = substr($needle, 0, 250);
            $b = substr($other, 0, 250);
            $distance = levenshtein($a, $b);
            $max_len = max(strlen($a), strlen($b));
            if ($max_len === 0) continue;
            $similarity = 1.0 - ($distance / $max_len);
            if ($similarity >= 0.85) {
                return $other;
            }
        }

        return null;
    }
}
