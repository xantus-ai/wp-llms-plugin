<?php
declare(strict_types=1);

namespace WPSearch\Audit;

use WPSearch\Audit\Rules\BoilerplateIntro;
use WPSearch\Audit\Rules\DuplicateTitle;
use WPSearch\Audit\Rules\GenericH1;
use WPSearch\Audit\Rules\LongTitle;
use WPSearch\Audit\Rules\MissingH1;
use WPSearch\Audit\Rules\MultipleH1;
use WPSearch\Audit\Rules\NoH2Headings;
use WPSearch\Audit\Rules\NoInternalLinks;
use WPSearch\Audit\Rules\RuleInterface;
use WPSearch\Audit\Rules\ShortTitle;
use WPSearch\Audit\Rules\StaleContent;
use WPSearch\Audit\Rules\ThinContent;
use WPSearch\Audit\Rules\ThinMeta;
use WPSearch\Generator\Extractor;
use WP_Post;

final class Auditor {
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
     * Run a full-site audit. Builds context, then audits each eligible post.
     *
     * @return array{issues_found:int,posts_audited:int}
     */
    public function audit_all(): array {
        $post_ids = $this->eligible_post_ids();
        $context = new AuditContext($this->extractor);
        $context->build($post_ids);

        $total_issues = 0;
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) continue;
            $total_issues += count($this->audit_post($post, $context));
        }

        return [
            'issues_found' => $total_issues,
            'posts_audited' => count($post_ids),
        ];
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
        $allowed = apply_filters('wpsearch_eligible_post_types', $filtered);
        return array_values(array_filter(array_map('strval', $allowed)));
    }

    public function repo(): IssuesRepository {
        return $this->repo;
    }
}
