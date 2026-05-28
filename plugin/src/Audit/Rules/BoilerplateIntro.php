<?php
declare(strict_types=1);

namespace WPLlms\Audit\Rules;

use WPLlms\Audit\AuditContext;
use WPLlms\Audit\AuditIssue;
use WP_Post;

final class BoilerplateIntro extends AbstractRule {
    private const SAMPLE_LENGTH = 200;
    private const CTA_PHRASES = [
        'subscribe', 'buy now', 'click here', 'join today',
        'sign up', 'sign-up', 'download now', 'get started',
        'no subscription required',
    ];

    public function key(): string {
        return 'boilerplate_intro';
    }

    public function severity(): string {
        return AuditIssue::SEVERITY_WARNING;
    }

    public function check(WP_Post $post, ?AuditContext $context = null): ?AuditIssue {
        $text = $this->plain_text($post);
        if ($text === '') {
            return null;
        }

        $sample = mb_substr($text, 0, self::SAMPLE_LENGTH);
        $sample_lower = mb_strtolower($sample);

        $cta_hits = 0;
        foreach (self::CTA_PHRASES as $phrase) {
            if (mb_strpos($sample_lower, $phrase) !== false) {
                $cta_hits++;
            }
        }

        $tagline = (string) get_bloginfo('description');
        if ($tagline !== '' && mb_stripos($sample, $tagline) !== false) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                __('The page opens with the site tagline as its first content. AI systems will read this as boilerplate, not unique value.', 'wp-llms'),
                __('Open with content specific to this page - the topic, the audience, the value.', 'wp-llms')
            );
        }

        if ($cta_hits >= 2) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                sprintf(
                    /* translators: %d: number of CTA phrases found */
                    __('The first %1$d characters contain %2$d call-to-action phrases. AI systems treat sales-pitch openings as low-value content.', 'wp-llms'),
                    self::SAMPLE_LENGTH,
                    $cta_hits
                ),
                __('Lead with descriptive content. Move CTAs further down the page.', 'wp-llms')
            );
        }

        if (preg_match('/\$\d+/', $sample) && $cta_hits >= 1) {
            return new AuditIssue(
                $post->ID,
                $this->key(),
                $this->severity(),
                __('The page opens with pricing and a call-to-action. AI systems will skip past this looking for descriptive content.', 'wp-llms'),
                __('Add a descriptive intro before pricing or CTAs.', 'wp-llms')
            );
        }

        return null;
    }
}
