<?php
declare(strict_types=1);

namespace WPLlms\Audit;

use WPLlms\Audit\Rules\BoilerplateIntro;
use WPLlms\Audit\Rules\DuplicateTitle;
use WPLlms\Audit\Rules\GenericH1;
use WPLlms\Audit\Rules\LongTitle;
use WPLlms\Audit\Rules\MissingH1;
use WPLlms\Audit\Rules\MultipleH1;
use WPLlms\Audit\Rules\NoH2Headings;
use WPLlms\Audit\Rules\NoInternalLinks;
use WPLlms\Audit\Rules\RuleInterface;
use WPLlms\Audit\Rules\ShortTitle;
use WPLlms\Audit\Rules\StaleContent;
use WPLlms\Audit\Rules\ThinContent;
use WPLlms\Audit\Rules\ThinMeta;
use WPLlms\Generator\Extractor;
use WP_Post;

final class Auditor {
    public const PROGRESS_OPTION = 'wpllms_audit_progress';
    public const DEFAULT_MAX_SECONDS = 20;

    private const PHASE_PER_POST = 'per_post';
    private const PHASE_SITE_CONTEXT = 'site_context';
    private const PHASE_COMPLETE = 'complete';

    /** @var RuleInterface[] */
    private array $rules;

    private IssuesRepository $repo;
    private Extractor $extractor;

    public function __construct(?array $rules = null, ?IssuesRepository $repo = null, ?Extractor $extractor = null) {
        $this->rules = $rules ?? self::default_rules();
        $this->repo = $repo ?? new IssuesRepository();
        $this->extractor = $extractor ?? new Extractor();
    }

    /** @return RuleInterface[] */
    public static function default_rules(): array {
        return [
            new MissingH1(),
            new MultipleH1(),
            new GenericH1(),
            new ShortTitle(),
            new LongTitle(),
            new ThinMeta(),
            new DuplicateTitle(),
            new ThinContent(),
            new BoilerplateIntro(),
            new StaleContent(),
            new NoInternalLinks(),
            new NoH2Headings(),
        ];
    }

    /**
     * Audit a single post. Site-context rules are skipped if no context provided.
     *
     * @return AuditIssue[]
     */
    public function audit_post(WP_Post $post, ?AuditContext $context = null): array {
        $this->repo->clear_for_post($post->ID);

        $issues = [];
        foreach ($this->rules as $rule) {
            if ($rule->needs_site_context() && $context === null) {
                continue;
            }
            try {
                $issue = $rule->check($post, $context);
                if ($issue !== null) {
                    $this->repo->save($issue);
                    $issues[] = $issue;
                }
            } catch (\Throwable $e) {
                // Don't let one rule failure abort the whole audit.
                continue;
            }
        }
        return $issues;
    }

    /**
     * Run a full-site audit in chunks, bounded by a time budget. Persists
     * progress in the PROGRESS_OPTION so subsequent calls resume from where
     * the previous one left off.
     *
     * Two phases:
     *
     * 1. per_post: runs all post-local rules (10 of 12) on each eligible
     *    post. Site-context rules (generic_h1, duplicate_title) are skipped
     *    here because they need the full corpus before deciding.
     *
     * 2. site_context: with the per-post phase done and rendered content
     *    cached by RenderedContentCache, builds AuditContext (cheap from
     *    cache) and runs the site-context rules across all posts.
     *
     * Both phases respect the time budget and will resume in the next call.
     *
     * @return array{issues_found:int,posts_audited:int,total_posts:int,is_complete:bool,phase:string}
     */
    public function audit_all(int $max_seconds = self::DEFAULT_MAX_SECONDS): array {
        $progress = get_option(self::PROGRESS_OPTION, null);
        $is_resume = is_array($progress) && isset($progress['phase']);

        if (!$is_resume) {
            $post_ids = $this->eligible_post_ids();
            $progress = [
                'phase' => self::PHASE_PER_POST,
                'remaining_post_ids' => array_values($post_ids),
                'all_post_ids' => array_values($post_ids),
                'site_context_remaining' => [],
                'site_context_cleared' => false,
                'total_posts' => count($post_ids),
                'posts_audited' => 0,
                'issues_found' => 0,
                'started_at' => current_time('mysql', true),
            ];
        }

        $deadline = microtime(true) + max(1, $max_seconds);

        if ($progress['phase'] === self::PHASE_PER_POST) {
            $progress = $this->run_per_post_phase($progress, $deadline);
        }

        if ($progress['phase'] === self::PHASE_SITE_CONTEXT && microtime(true) < $deadline) {
            $progress = $this->run_site_context_phase($progress, $deadline);
        }

        $is_complete = $progress['phase'] === self::PHASE_COMPLETE;

        if ($is_complete) {
            delete_option(self::PROGRESS_OPTION);
            update_option('wpllms_last_audit', [
                'completed_at' => current_time('mysql', true),
                'issues_found' => $progress['issues_found'],
                'posts_audited' => $progress['posts_audited'],
            ], false);
        } else {
            update_option(self::PROGRESS_OPTION, $progress, false);
        }

        return [
            'issues_found' => $progress['issues_found'],
            'posts_audited' => $progress['posts_audited'],
            'total_posts' => $progress['total_posts'],
            'is_complete' => $is_complete,
            'phase' => $progress['phase'],
        ];
    }

    /**
     * @param array<string,mixed> $progress
     * @return array<string,mixed>
     */
    private function run_per_post_phase(array $progress, float $deadline): array {
        $remaining = $progress['remaining_post_ids'];

        while (count($remaining) > 0 && microtime(true) < $deadline) {
            $post_id = (int) array_shift($remaining);
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) {
                $progress['posts_audited']++;
                continue;
            }
            // Context intentionally null — site-context rules run in phase 2.
            $issues = $this->audit_post($post, null);
            $progress['posts_audited']++;
            $progress['issues_found'] += count($issues);
        }

        $progress['remaining_post_ids'] = array_values($remaining);

        if (count($remaining) === 0) {
            $progress['phase'] = self::PHASE_SITE_CONTEXT;
            $progress['site_context_remaining'] = $progress['all_post_ids'];
        }

        return $progress;
    }

    /**
     * @param array<string,mixed> $progress
     * @return array<string,mixed>
     */
    private function run_site_context_phase(array $progress, float $deadline): array {
        // Clear stale site-context issues once, before starting the loop. We
        // can't use audit_post's clear_for_post here because that wipes the
        // post-local issues we just stored in phase 1.
        if (!$progress['site_context_cleared']) {
            foreach ($this->rules as $rule) {
                if ($rule->needs_site_context()) {
                    $this->repo->clear_rule($rule->key());
                }
            }
            $progress['site_context_cleared'] = true;
        }

        // Context build is cheap here because RenderedContentCache was warmed
        // in phase 1 — every extract_h1 call is now a cache hit + regex.
        $context = new AuditContext($this->extractor);
        $context->build($progress['all_post_ids']);

        $site_rules = array_filter($this->rules, fn(RuleInterface $r) => $r->needs_site_context());
        $remaining = $progress['site_context_remaining'];

        while (count($remaining) > 0 && microtime(true) < $deadline) {
            $post_id = (int) array_shift($remaining);
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) continue;

            foreach ($site_rules as $rule) {
                try {
                    $issue = $rule->check($post, $context);
                    if ($issue !== null) {
                        $this->repo->save($issue);
                        $progress['issues_found']++;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        $progress['site_context_remaining'] = array_values($remaining);

        if (count($remaining) === 0) {
            $progress['phase'] = self::PHASE_COMPLETE;
        }

        return $progress;
    }

    public static function get_progress(): ?array {
        $value = get_option(self::PROGRESS_OPTION, null);
        return is_array($value) ? $value : null;
    }

    public static function clear_progress(): void {
        delete_option(self::PROGRESS_OPTION);
    }

    /**
     * @return int[]
     */
    private function eligible_post_ids(): array {
        global $wpdb;

        $eligible_types = $this->eligible_post_types();
        if (count($eligible_types) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eligible_types), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
            ...$eligible_types
        );
        $ids = $wpdb->get_col($sql);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * @return string[]
     */
    private function eligible_post_types(): array {
        $public = get_post_types(['public' => true], 'names');
        $excluded = ['attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'];
        $filtered = array_diff(array_values($public), $excluded);

        /** @var string[] $allowed */
        $allowed = apply_filters('wpllms_eligible_post_types', $filtered);
        return array_values(array_filter(array_map('strval', $allowed)));
    }

    public function repo(): IssuesRepository {
        return $this->repo;
    }
}
