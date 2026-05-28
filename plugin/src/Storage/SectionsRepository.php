<?php
declare(strict_types=1);

namespace WPSearch\Storage;

final class SectionsRepository {
    public function all(): array {
        global $wpdb;
        $table = Schema::table_name('sections');
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function find(int $id): ?array {
        global $wpdb;
        $table = Schema::table_name('sections');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function create(array $data): int {
        global $wpdb;
        $table = Schema::table_name('sections');
        $now = current_time('mysql', true);

        $rule = $data['inclusion_rule_json'] ?? [];
        if (!is_string($rule)) {
            $rule = wp_json_encode($rule);
        }

        $wpdb->insert($table, [
            'name' => (string) ($data['name'] ?? ''),
            'slug' => (string) ($data['slug'] ?? sanitize_title((string) ($data['name'] ?? ''))),
            'intro_text' => $data['intro_text'] ?? null,
            'is_optional' => !empty($data['is_optional']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'inclusion_rule_json' => $rule,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool {
        global $wpdb;
        $table = Schema::table_name('sections');

        $allowed = array_intersect_key(
            $data,
            array_flip(['name', 'slug', 'intro_text', 'is_optional', 'sort_order', 'inclusion_rule_json'])
        );

        if (isset($allowed['inclusion_rule_json']) && !is_string($allowed['inclusion_rule_json'])) {
            $allowed['inclusion_rule_json'] = wp_json_encode($allowed['inclusion_rule_json']);
        }

        if (isset($allowed['is_optional'])) {
            $allowed['is_optional'] = !empty($allowed['is_optional']) ? 1 : 0;
        }

        $allowed['updated_at'] = current_time('mysql', true);

        $result = $wpdb->update($table, $allowed, ['id' => $id]);
        return $result !== false;
    }

    public function delete(int $id): bool {
        global $wpdb;
        $table = Schema::table_name('sections');
        return $wpdb->delete($table, ['id' => $id]) !== false;
    }
}
