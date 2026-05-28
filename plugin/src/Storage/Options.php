<?php
declare(strict_types=1);

namespace WPSearch\Storage;

final class Options {
    public const SETTINGS_KEY = 'wpsearch_settings';
    public const LICENSE_KEY = 'wpsearch_license';
    public const DIRTY_KEY = 'wpsearch_dirty';
    public const SETUP_COMPLETED_KEY = 'wpsearch_setup_completed';

    public static function seed_defaults(): void {
        if (get_option(self::SETTINGS_KEY) === false) {
            update_option(self::SETTINGS_KEY, self::default_settings());
        }
        if (get_option(self::LICENSE_KEY) === false) {
            update_option(self::LICENSE_KEY, self::default_license());
        }
        if (get_option(self::SETUP_COMPLETED_KEY) === false) {
            update_option(self::SETUP_COMPLETED_KEY, false);
        }
    }

    public static function get_settings(): array {
        $value = get_option(self::SETTINGS_KEY, []);
        return is_array($value) ? array_merge(self::default_settings(), $value) : self::default_settings();
    }

    public static function get_license(): array {
        $value = get_option(self::LICENSE_KEY, []);
        return is_array($value) ? array_merge(self::default_license(), $value) : self::default_license();
    }

    public static function is_setup_completed(): bool {
        return (bool) get_option(self::SETUP_COMPLETED_KEY, false);
    }

    private static function default_settings(): array {
        return [
            'site_h1' => '',
            'site_summary' => '',
            'site_context' => '',
            'auto_regenerate' => true,
            'regenerate_on_publish' => true,
            'cron_frequency' => 'daily',
            'include_optional_section' => true,
            'serve_md_variants' => true,
            'update_robots_txt' => false,
            'inject_link_tag' => false,
            'csp_header' => null,
            'stale_threshold_months' => 24,
            'remove_data_on_uninstall' => false,
            // null means "auto-detect by host"; true/false explicitly override.
            'serve_via_static_file' => null,
        ];
    }

    private static function default_license(): array {
        return [
            'key' => null,
            'tier' => 'free',
            'last_validated' => null,
            'expires_at' => null,
        ];
    }
}
