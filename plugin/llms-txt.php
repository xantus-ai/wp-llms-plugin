<?php
/**
 * Plugin Name:       llms.txt for WordPress
 * Plugin URI:        https://github.com/xantus-ai/wp-llms-plugin
 * Description:       Make your WordPress site discoverable by AI. Auto-generate and maintain llms.txt, audit content for AI-search readiness, and serve per-page markdown variants.
 * Version:           0.1.16
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Xantus AI
 * Author URI:        https://xant.us
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       llms-txt
 * Domain Path:       /languages
 *
 * @package WPLlms
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WPLLMS_VERSION', '0.1.16');
define('WPLLMS_FILE', __FILE__);
define('WPLLMS_PATH', plugin_dir_path(__FILE__));
define('WPLLMS_URL', plugin_dir_url(__FILE__));
define('WPLLMS_BASENAME', plugin_basename(__FILE__));
define('WPLLMS_MIN_PHP', '8.1');
define('WPLLMS_MIN_WP', '6.0');

if (version_compare(PHP_VERSION, WPLLMS_MIN_PHP, '<')) {
    add_action('admin_notices', function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __('llms.txt for WordPress requires PHP %1$s or higher. You are running PHP %2$s.', 'llms-txt'),
                WPLLMS_MIN_PHP,
                PHP_VERSION
            ))
        );
    });
    return;
}

global $wp_version;
if (version_compare($wp_version, WPLLMS_MIN_WP, '<')) {
    add_action('admin_notices', function () use ($wp_version): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: required WP version, 2: current WP version */
                __('llms.txt for WordPress requires WordPress %1$s or higher. You are running WordPress %2$s.', 'llms-txt'),
                WPLLMS_MIN_WP,
                $wp_version
            ))
        );
    });
    return;
}

$wpllms_autoload = WPLLMS_PATH . 'vendor/autoload.php';
if (file_exists($wpllms_autoload)) {
    require_once $wpllms_autoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'WPLlms\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = WPLLMS_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

register_activation_hook(__FILE__, ['WPLlms\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WPLlms\\Deactivator', 'deactivate']);

add_action('plugins_loaded', function (): void {
    load_plugin_textdomain('llms-txt', false, dirname(WPLLMS_BASENAME) . '/languages');
    \WPLlms\Plugin::instance();
});
