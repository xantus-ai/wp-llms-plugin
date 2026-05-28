<?php
declare(strict_types=1);

namespace WPLlms\Admin\Pages;

use WPLlms\Frontend\FileServer;
use WPLlms\Plugin;
use WPLlms\Storage\Options;

final class SettingsPage {
    public const FORM_ACTION = 'wpllms_settings';
    public const NONCE_ACTION = 'wpllms_settings';
    public const NONCE_NAME = 'wpllms_settings_nonce';

    public static function render(): void {
        $settings = Options::get_settings();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Settings', 'llms-txt') . '</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'llms-txt') . '</p></div>';
        }

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">

            <h2><?php esc_html_e('Brand voice', 'llms-txt'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="site_h1"><?php esc_html_e('Site name (H1)', 'llms-txt'); ?></label></th>
                    <td><input type="text" id="site_h1" name="site_h1" value="<?php echo esc_attr((string) $settings['site_h1']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="site_summary"><?php esc_html_e('Summary', 'llms-txt'); ?></label></th>
                    <td><textarea id="site_summary" name="site_summary" rows="3" class="large-text" maxlength="500"><?php echo esc_textarea((string) $settings['site_summary']); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="site_context"><?php esc_html_e('Context', 'llms-txt'); ?></label></th>
                    <td><textarea id="site_context" name="site_context" rows="6" class="large-text"><?php echo esc_textarea((string) $settings['site_context']); ?></textarea></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Integrations', 'llms-txt'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('robots.txt', 'llms-txt'); ?></th>
                    <td><label><input type="checkbox" name="update_robots_txt" value="1" <?php checked(!empty($settings['update_robots_txt'])); ?>> <?php esc_html_e('Reference llms.txt in robots.txt', 'llms-txt'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('<link> tag', 'llms-txt'); ?></th>
                    <td><label><input type="checkbox" name="inject_link_tag" value="1" <?php checked(!empty($settings['inject_link_tag'])); ?>> <?php esc_html_e('Inject <link rel="llms"> into <head>', 'llms-txt'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('.md endpoints', 'llms-txt'); ?></th>
                    <td><label><input type="checkbox" name="serve_md_variants" value="1" <?php checked(!empty($settings['serve_md_variants'])); ?>> <?php esc_html_e('Serve /{slug}.md per-page markdown', 'llms-txt'); ?></label></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Audit', 'llms-txt'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="stale_threshold_months"><?php esc_html_e('Stale threshold (months)', 'llms-txt'); ?></label></th>
                    <td>
                        <input type="number" min="1" max="60" id="stale_threshold_months" name="stale_threshold_months" value="<?php echo esc_attr((string) $settings['stale_threshold_months']); ?>" class="small-text">
                        <p class="description"><?php esc_html_e('Posts not modified in this many months are flagged as stale.', 'llms-txt'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Data', 'llms-txt'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('On uninstall', 'llms-txt'); ?></th>
                    <td><label><input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked(!empty($settings['remove_data_on_uninstall'])); ?>> <?php esc_html_e('Remove all plugin data when the plugin is uninstalled', 'llms-txt'); ?></label></td>
                </tr>
            </table>

            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Save', 'llms-txt'); ?></button></p>
        </form>
        <?php

        echo '</div>';
    }

    public static function handle_post(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'llms-txt'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $current = Options::get_settings();
        $current['site_h1'] = sanitize_text_field((string) ($_POST['site_h1'] ?? ''));
        $current['site_summary'] = sanitize_textarea_field((string) ($_POST['site_summary'] ?? ''));
        $current['site_context'] = wp_kses_post((string) ($_POST['site_context'] ?? ''));
        $current['update_robots_txt'] = !empty($_POST['update_robots_txt']);
        $current['inject_link_tag'] = !empty($_POST['inject_link_tag']);
        $current['serve_md_variants'] = !empty($_POST['serve_md_variants']);
        $current['stale_threshold_months'] = max(1, min(60, (int) ($_POST['stale_threshold_months'] ?? 24)));
        $current['remove_data_on_uninstall'] = !empty($_POST['remove_data_on_uninstall']);

        update_option(Options::SETTINGS_KEY, $current);
        FileServer::invalidate_cache();
        Plugin::maybe_write_static_files();

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=wpllms-settings')));
        exit;
    }
}
