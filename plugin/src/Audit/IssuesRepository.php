<?php
declare(strict_types=1);

namespace WPSearch\Audit;

use WPSearch\Storage\Schema;

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
     * @return array<string,int>
     */
    public function counts_by_severity(): array {
        global $wpdb;
        $table = Schema::table_name('audit_issues');
        $rows = $wpdb->get_results(
            "SELECT severity, COUNT(*) AS cnt FROM {$table} WHERE resolved_at IS NULL GROUP BY severity",
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
