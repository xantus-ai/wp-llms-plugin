<?php
declare(strict_types=1);

namespace WPLlms\Content;

/**
 * Extracts text content from Advanced Custom Fields for inclusion in the
 * audit's rendered content and the generator's content pipeline.
 *
 * For ACF-heavy custom post types, the "real" content lives in custom fields,
 * not in post_content. Without this, the audit reads an empty post_content
 * and reports false positives for missing_h1, thin_content, boilerplate_intro,
 * etc. The llms.txt generator and per-page .md endpoints have the same blind
 * spot — descriptions fall back to title-only and per-page content is empty.
 *
 * Recursively walks all fields returned by get_fields(), concatenating any
 * string values. Handles repeater, group, and flexible-content fields by
 * recursion. Non-string leaves (image IDs, file metadata arrays, taxonomy
 * term arrays) are skipped.
 *
 * Field name exclusions: wpllms_acf_content_excluded_fields filter, default [].
 *
 * Cache key: not cached here — callers wrap this in their own caches that are
 * keyed on post_modified_gmt. ACF field updates made through the editor bump
 * post_modified; programmatic update_field() calls do not, so the cache
 * survives those (acceptable — explicit cache flush or post resave fixes it).
 */
final class AcfContent {
    public static function for_post(int $post_id): string {
        if (!function_exists('get_fields')) {
            return '';
        }

        $fields = get_fields($post_id);
        if (!is_array($fields)) {
            return '';
        }

        $excluded = (array) apply_filters('wpllms_acf_content_excluded_fields', []);
        $excluded = array_values(array_filter(array_map('strval', $excluded)));

        $parts = self::collect($fields, $excluded);
        return implode("\n\n", $parts);
    }

    /**
     * @param array<string|int,mixed> $fields
     * @param string[] $excluded
     * @return string[]
     */
    private static function collect(array $fields, array $excluded): array {
        $parts = [];
        foreach ($fields as $key => $value) {
            if (is_string($key) && in_array($key, $excluded, true)) {
                continue;
            }
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
            } elseif (is_array($value)) {
                $nested = self::collect($value, $excluded);
                if (count($nested) > 0) {
                    array_push($parts, ...$nested);
                }
            }
            // ints, floats, bools, nulls, objects: skipped
        }
        return $parts;
    }
}
