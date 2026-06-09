<?php
declare(strict_types=1);

namespace WPLlms\Audit;

use WPLlms\Storage\Schema;

final class IssuesRepository {
    public function save(AuditIssue $issue): void {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $now = current_time('mysql', true);

        // Upsert: if an unresolved issue for this post+rule exists, update it; else insert.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND rule = %s AND resolved_at IS NULL LIMIT 1",
            $issue->post_id,
            $issue->rule
        ));

        if ($existing !== null) {
            $wpdb->update($table, [
                'severity' => $issue->severity,
                'message' => $issue->message,
                'suggested_fix' => $issue->suggested_fix,
                'detected_at' => $now,
            ], ['id' => (int) $existing]);
            return;
        }

        $wpdb->insert($table, [
            'post_id' => $issue->post_id,
            'rule' => $issue->rule,
            'severity' => $issue->severity,
            'message' => $issue->message,
            'suggested_fix' => $issue->suggested_fix,
            'detected_at' => $now,
            'resolved_at' => null,
        ]);
    }

    public function clear_for_post(int $post_id): void {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $wpdb->delete($table, ['post_id' => $post_id, 'resolved_at' => null]);
    }

    /**
     * Clear all unresolved issues across all posts for a single rule. Used
     * during the chunked audit's site-context phase to wipe stale findings
     * before re-running site-context rules with fresh frequency data.
     */
    public function clear_rule(string $rule): void {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $wpdb->delete($table, ['rule' => $rule, 'resolved_at' => null]);
    }

    public function clear_for_post_and_rule(int $post_id, string $rule): void {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE post_id = %d AND rule = %s AND resolved_at IS NULL",
            $post_id,
            $rule
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function unresolved(int $limit = 100, int $offset = 0): array {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE resolved_at IS NULL ORDER BY FIELD(severity, 'critical', 'warning', 'info'), detected_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Paginated/filtered query for the audit page table.
     *
     * Joined against wp_posts so only issues for currently-published posts
     * are returned — drafts, pending, trash, and deleted posts are hidden
     * even if their issue rows are still in the table.
     *
     * @param string|null $severity One of 'critical', 'warning', 'info', or null for all.
     * @param string|null $rule Rule key like 'missing_h1', or null for all.
     * @return array<int,array<string,mixed>>
     */
    public function unresolved_filtered(?string $severity = null, ?string $rule = null, int $limit = 50, int $offset = 0): array {
        global $wpdb;
        $table = Schema::table_name('audit_issues');

        [$where_sql, $values] = $this->build_filter_where($severity, $rule, 'i');
        $values[] = $limit;
        $values[] = $offset;

        $sql = "SELECT i.* FROM {$table} i
                INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
                WHERE {$where_sql} AND p.post_status = 'publish'
                ORDER BY FIELD(i.severity, 'critical', 'warning', 'info'), i.detected_at DESC
                LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function unresolved_count_filtered(?string $severity = null, ?string $rule = null): int {
        global $wpdb;
        $table = Schema::table_name('audit_issues');

        [$where_sql, $values] = $this->build_filter_where($severity, $rule, 'i');
        $sql = "SELECT COUNT(*) FROM {$table} i
                INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
                WHERE {$where_sql} AND p.post_status = 'publish'";
        if (count($values) === 0) {
            return (int) $wpdb->get_var($sql);
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$values));
    }

    /**
     * Same filter set as unresolved_filtered() but without LIMIT/OFFSET.
     * Used by the CSV export. Memory-safe up to ~100k rows on default
     * WordPress hosting; chunk if you expect more.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unresolved_filtered_all(?string $severity = null, ?string $rule = null): array {
        global $wpdb;
        $table = Schema::table_name('audit_issues');

        [$where_sql, $values] = $this->build_filter_where($severity, $rule, 'i');
        $sql = "SELECT i.* FROM {$table} i
                INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
                WHERE {$where_sql} AND p.post_status = 'publish'
                ORDER BY FIELD(i.severity, 'critical', 'warning', 'info'), i.detected_at DESC";
        if (count($values) === 0) {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A);
        }
        return is_array($rows) ? $rows : [];
    }

    /**
     * Distinct rule keys currently present in the unresolved set for
     * currently-published posts. Used to populate the rule filter dropdown.
     *
     * @return string[]
     */
    public function distinct_unresolved_rules(): array {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $rows = $wpdb->get_col(
            "SELECT DISTINCT i.rule FROM {$table} i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
             WHERE i.resolved_at IS NULL AND p.post_status = 'publish'
             ORDER BY i.rule ASC"
        );
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function build_filter_where(?string $severity, ?string $rule, string $alias = ''): array {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $where = [$prefix . 'resolved_at IS NULL'];
        $values = [];

        if ($severity !== null && in_array($severity, ['critical', 'warning', 'info'], true)) {
            $where[] = $prefix . 'severity = %s';
            $values[] = $severity;
        }
        if ($rule !== null && $rule !== '') {
            $where[] = $prefix . 'rule = %s';
            $values[] = $rule;
        }

        return [implode(' AND ', $where), $values];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function for_post(int $post_id): array {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d AND resolved_at IS NULL",
            $post_id
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Open-issue counts grouped by severity, scoped to currently-published
     * posts only. Used by the dashboard summary and the audit page tabs.
     *
     * @return array<string,int>
     */
    public function counts_by_severity(): array {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $rows = $wpdb->get_results(
            "SELECT i.severity, COUNT(*) AS cnt FROM {$table} i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
             WHERE i.resolved_at IS NULL AND p.post_status = 'publish'
             GROUP BY i.severity",
            ARRAY_A
        );
        $out = ['critical' => 0, 'warning' => 0, 'info' => 0];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[$row['severity']] = (int) $row['cnt'];
            }
        }
        return $out;
    }
}
