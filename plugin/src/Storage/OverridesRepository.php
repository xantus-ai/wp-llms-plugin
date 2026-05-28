<?php
declare(strict_types=1);

namespace WPSearch\Storage;

final class OverridesRepository {
    public function find_for_post(int $post_id, ?int $section_id = null): ?array {
        global $wpdb;
        $table = Schema::table_name('overrides');

        if ($section_id !== null) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE post_id = %d AND section_id = %d LIMIT 1",
                    $post_id,
                    $section_id
                ),
                ARRAY_A
            );
            if (is_array($row)) {
                return $row;
            }
        }

        // Fallback: any override for this post (section_id NULL = applies anywhere).
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE post_id = %d AND section_id IS NULL LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function is_excluded(int $post_id, ?int $section_id = null): bool {
        $override = $this->find_for_post($post_id, $section_id);
        return $override !== null && !empty($override['is_excluded']);
    }

    public function get_custom_title(int $post_id, ?int $section_id = null): ?string {
        $override = $this->find_for_post($post_id, $section_id);
        if ($override === null) {
            return null;
        }
        $title = $override['custom_title'] ?? null;
        return is_string($title) && $title !== '' ? $title : null;
    }

    public function get_custom_description(int $post_id, ?int $section_id = null): ?string {
        $override = $this->find_for_post($post_id, $section_id);
        if ($override === null) {
            return null;
        }
        $desc = $override['custom_description'] ?? null;
        return is_string($desc) && $desc !== '' ? $desc : null;
    }
}
