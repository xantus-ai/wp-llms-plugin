<?php
declare(strict_types=1);

namespace WPSearch\Frontend;

/**
 * Detects the hosting environment so the plugin can apply
 * host-specific defaults and surface relevant warnings.
 */
final class HostDetector {
    public static function is_wp_engine(): bool {
        return function_exists('is_wpe')
            || defined('WPE_APIKEY')
            || defined('WPE_LARGEFS')
            || (isset($_SERVER['HTTP_HOST']) && str_contains((string) $_SERVER['HTTP_HOST'], '.wpengine.com'));
    }

    public static function is_kinsta(): bool {
        return defined('KINSTAMU_VERSION') || (isset($_SERVER['HTTP_HOST']) && str_contains((string) $_SERVER['HTTP_HOST'], '.kinsta.cloud'));
    }

    public static function is_pantheon(): bool {
        return defined('PANTHEON_ENVIRONMENT') || isset($_SERVER['PANTHEON_ENVIRONMENT']);
    }

    /**
     * True for hosts known to block dynamic serving of .txt URLs via nginx
     * rules that don't fall through to index.php. These hosts require the
     * physical-file fallback.
     */
    public static function blocks_dynamic_txt(): bool {
        return self::is_wp_engine() || self::is_kinsta();
    }

    public static function name(): string {
        if (self::is_wp_engine()) return 'WP Engine';
        if (self::is_kinsta()) return 'Kinsta';
        if (self::is_pantheon()) return 'Pantheon';
        return 'unknown';
    }
}
