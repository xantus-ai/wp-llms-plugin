<?php
declare(strict_types=1);

namespace WPSearch\Storage;

final class Schema {
    public const VERSION = '1';
    public const VERSION_OPTION = 'wpsearch_schema_version';

    public static function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        \dbDelta(self::sections_sql($charset));
        \dbDelta(self::overrides_sql($charset));
        \dbDelta(self::audit_issues_sql($charset));
        \dbDelta(self::bot_hits_sql($charset));

        update_option(self::VERSION_OPTION, self::VERSION);
    }

    public static function table_name(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'wpsearch_' . $name;
    }

    private static function sections_sql(string $charset): string {
        $table = self::table_name('sections');
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            intro_text TEXT NULL,
            is_optional TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            inclusion_rule_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY sort_order (sort_order)
        ) {$charset};";
    }

    private static function overrides_sql(string $charset): string {
        $table = self::table_name('overrides');
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            section_id BIGINT UNSIGNED NULL,
            custom_title VARCHAR(255) NULL,
            custom_description TEXT NULL,
            is_excluded TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY post_section (post_id, section_id),
            KEY post_id (post_id),
            KEY section_id (section_id)
        ) {$charset};";
    }

    private static function audit_issues_sql(string $charset): string {
        $table = self::table_name('audit_issues');
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            rule VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'warning',
            message TEXT NULL,
            suggested_fix TEXT NULL,
            detected_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY post_id_rule (post_id, rule),
            KEY unresolved (resolved_at),
            KEY severity (severity)
        ) {$charset};";
    }

    private static function bot_hits_sql(string $charset): string {
        $table = self::table_name('bot_hits');
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_name VARCHAR(50) NOT NULL,
            path VARCHAR(500) NOT NULL,
            status_code INT NOT NULL,
            user_agent VARCHAR(500) NULL,
            ip_hash CHAR(64) NULL,
            occurred_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY bot_time (bot_name, occurred_at),
            KEY occurred_at (occurred_at)
        ) {$charset};";
    }
}
