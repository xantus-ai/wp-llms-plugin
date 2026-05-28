<?php
/**
 * Plugin Name:       WPSearch - AI Search Optimization for WordPress
 * Plugin URI:        https://wpsear.ch
 * Description:       Make your WordPress site discoverable by AI. Auto-generate llms.txt, audit content for AI-search readiness, generate schema, and track AI bot activity.
 * Version:           0.1.6
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Xantus AI
 * Author URI:        https://xant.us
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpsearch-ai
 * Domain Path:       /languages
 *
 * @package WPSearch
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WPSEARCH_VERSION', '0.1.6');
define('WPSEARCH_FILE', __FILE__);
define('WPSEARCH_PATH', plugin_dir_path(__FILE__));
define('WPSEARCH_URL', plugin_dir_url(__FILE__));
define('WPSEARCH_BASENAME', plugin_basename(__FILE__));
define('WPSEARCH_MIN_PHP', '8.1');
define('WPSEARCH_MIN_WP', '6.0');

if (version_compare(PHP_VERSION, WPSEARCH_MIN_PHP, '<')) {
    add_action('admin_notices', function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __('WPSearch requires PHP %1$s or higher. You are running PHP %2$s.', 'wpsearch-ai'),
                WPSEARCH_MIN_PHP,
                PHP_VERSION
            ))
        );
    });
    return;
}

global $wp_version;
if (version_compare($wp_version, WPSEARCH_MIN_WP, '<')) {
    add_action('admin_notices', function () use ($wp_version): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: required WP version, 2: current WP version */
                __('WPSearch requires WordPress %1$s or higher. You are running WordPress %2$s.', 'wpsearch-ai'),
                WPSEARCH_MIN_WP,
                $wp_version
            ))
        );
    });
    return;
}

$wpsearch_autoload = WPSEARCH_PATH . 'vendor/autoload.php';
if (file_exists($wpsearch_autoload)) {
    require_once $wpsearch_autoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'WPSearch\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = WPSEARCH_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

register_activation_hook(__FILE__, ['WPSearch\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WPSearch\\Deactivator', 'deactivate']);

add_action('plugins_loaded', function (): void {
    load_plugin_textdomain('wpsearch-ai', false, dirname(WPSEARCH_BASENAME) . '/languages');
    \WPSearch\Plugin::instance();
});
