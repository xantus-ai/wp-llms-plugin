<?php
declare(strict_types=1);

namespace WPLlms\Admin;

use WPLlms\Admin\Pages\AuditPage;
use WPLlms\Admin\Pages\SectionEditPage;
use WPLlms\Admin\Pages\SectionsPage;
use WPLlms\Admin\Pages\SettingsPage;
use WPLlms\Admin\Pages\WizardPage;
use WPLlms\Audit\IssuesRepository;
use WPLlms\Frontend\FileServer;
use WPLlms\Frontend\HostDetector;
use WPLlms\Frontend\PhysicalFileWriter;
use WPLlms\Storage\Options;
use WPLlms\Storage\Schema;

final class Menu {
    public const SLUG = 'wpllms';
    public const CAPABILITY = 'manage_options';
    public const REFRESH_REWRITES_ACTION = 'wpllms_refresh_rewrites';
    public const REFRESH_REWRITES_NONCE = 'wpllms_refresh_rewrites_nonce';
    public const WRITE_STATIC_ACTION = 'wpllms_write_static';
    public const WRITE_STATIC_NONCE = 'wpllms_write_static_nonce';

    public static function register(): void {
        add_menu_page(
            __('llms.txt for WordPress', 'llms-txt'),
            __('llms.txt', 'llms-txt'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render_dashboard'],
            'dashicons-media-text',
            80
        );

        add_submenu_page(
            self::SLUG,
            __('Dashboard', 'llms-txt'),
            __('Dashboard', 'llms-txt'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render_dashboard']
        );

        add_submenu_page(
            self::SLUG,
            __('Setup Wizard', 'llms-txt'),
            __('Setup Wizard', 'llms-txt'),
            self::CAPABILITY,
            'wpllms-wizard',
            [WizardPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('Sections', 'llms-txt'),
            __('Sections', 'llms-txt'),
            self::CAPABILITY,
            'wpllms-sections',
            [SectionsPage::class, 'render']
        );

        // Hidden sub-page (no menu item, accessed via list page links).
        add_submenu_page(
            '',
            __('Add Section', 'llms-txt'),
            __('Add Section', 'llms-txt'),
            self::CAPABILITY,
            SectionEditPage::PAGE_SLUG,
            [SectionEditPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('Audit', 'llms-txt'),
            __('Audit', 'llms-txt'),
            self::CAPABILITY,
            'wpllms-audit',
            [AuditPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('Settings', 'llms-txt'),
            __('Settings', 'llms-txt'),
            self::CAPABILITY,
            'wpllms-settings',
            [SettingsPage::class, 'render']
        );
    }

    public static function render_dashboard(): void {
        $setup_done = Options::is_setup_completed();
        $issue_counts = (new IssuesRepository())->counts_by_severity();
        $last_audit = get_option('wpllms_last_audit', null);
        $rewrites_ok = self::rewrite_rules_active();
        $permalinks_plain = (string) get_option('permalink_structure', '') === '';

        $writer = new PhysicalFileWriter();
        $static_mode = $writer->is_enabled();
        $static_exists = $writer->exists(PhysicalFileWriter::LLMS_TXT_FILENAME);
        $static_age = $writer->age_seconds(PhysicalFileWriter::LLMS_TXT_FILENAME);
        $static_size = $writer->size_bytes(PhysicalFileWriter::LLMS_TXT_FILENAME);
        $can_write_root = PhysicalFileWriter::can_write();
        $host_name = HostDetector::name();
        $host_blocks_txt = HostDetector::blocks_dynamic_txt();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('llms.txt for WordPress', 'llms-txt'); ?></h1>
            <p><?php esc_html_e('AI Discoverability for WordPress.', 'llms-txt'); ?></p>

            <?php if (isset($_GET['setup_done'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e('Setup complete.', 'llms-txt'); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: link to llms.txt */
                            esc_html__('Visit %s to see your generated file.', 'llms-txt'),
                            '<a href="' . esc_url(home_url('/llms.txt')) . '" target="_blank">' . esc_html(home_url('/llms.txt')) . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['rewrites_refreshed'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Permalink rules refreshed. /llms.txt should now resolve.', 'llms-txt'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($host_blocks_txt && $setup_done && !$static_exists) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php
                        /* translators: %s: host name */
                        printf(esc_html__('%s detected.', 'llms-txt'), esc_html($host_name));
                        ?></strong>
                        <?php esc_html_e('This host blocks dynamic .txt URLs at the nginx layer, so /llms.txt only works if a physical file exists at the document root. We need to write that file. Click below to generate it.', 'llms-txt'); ?>
                    </p>
                    <?php if (!$can_write_root) : ?>
                        <p style="color:#b32d2e"><strong><?php esc_html_e('Warning: the WordPress root directory is not writable. Contact your host to enable write permissions on the document root, or contact WP Engine support to whitelist /llms.txt.', 'llms-txt'); ?></strong></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::WRITE_STATIC_ACTION, self::WRITE_STATIC_NONCE); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::WRITE_STATIC_ACTION); ?>">
                        <p><button class="button button-primary" <?php disabled(!$can_write_root); ?>><?php esc_html_e('Write llms.txt to disk', 'llms-txt'); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['static_written'])) :
                $w = get_transient('wpllms_static_result');
                delete_transient('wpllms_static_result');
                if (is_array($w) && !empty($w['ok'])) : ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php
                        /* translators: 1: path, 2: byte count */
                        printf(esc_html__('Wrote %1$s (%2$s bytes). /llms.txt should now resolve.', 'llms-txt'),
                            '<code>' . esc_html((string) $w['path']) . '</code>',
                            esc_html(number_format((int) $w['bytes'])));
                        ?></p>
                    </div>
                <?php elseif (is_array($w)) : ?>
                    <div class="notice notice-error">
                        <p><?php
                        /* translators: %s: error code */
                        printf(esc_html__('Could not write file: %s', 'llms-txt'), esc_html((string) ($w['error'] ?? 'unknown')));
                        ?></p>
                    </div>
                <?php endif;
            endif; ?>

            <?php if ($permalinks_plain) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Plain permalinks detected.', 'llms-txt'); ?></strong>
                        <?php esc_html_e('This plugin requires pretty permalinks to serve /llms.txt. Go to Settings -> Permalinks and choose any option other than "Plain", then save.', 'llms-txt'); ?>
                    </p>
                    <p><a class="button" href="<?php echo esc_url(admin_url('options-permalink.php')); ?>"><?php esc_html_e('Open Permalinks settings', 'llms-txt'); ?></a></p>
                </div>
            <?php elseif (!$rewrites_ok && !$static_mode) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e('Permalink rules need refreshing.', 'llms-txt'); ?></strong>
                        <?php esc_html_e('The rewrite rules that serve /llms.txt aren\'t active yet. This is a known WordPress quirk that can happen on first activation. Click below to fix it.', 'llms-txt'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::REFRESH_REWRITES_ACTION, self::REFRESH_REWRITES_NONCE); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::REFRESH_REWRITES_ACTION); ?>">
                        <p><button class="button button-primary"><?php esc_html_e('Refresh permalinks', 'llms-txt'); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!$setup_done) : ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php esc_html_e('Welcome to llms.txt for WordPress.', 'llms-txt'); ?></strong>
                        <?php esc_html_e('Run the setup wizard to configure your llms.txt in 4 steps.', 'llms-txt'); ?>
                    </p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wpllms-wizard')); ?>"><?php esc_html_e('Run setup wizard', 'llms-txt'); ?></a></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Status', 'llms-txt'); ?></h2>
            <table class="widefat striped" style="max-width:700px">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Plugin version', 'llms-txt'); ?></th>
                        <td><code><?php echo esc_html(WPLLMS_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Schema version', 'llms-txt'); ?></th>
                        <td><code><?php echo esc_html((string) get_option(Schema::VERSION_OPTION, 'not installed')); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Setup completed', 'llms-txt'); ?></th>
                        <td><?php echo $setup_done ? esc_html__('Yes', 'llms-txt') : esc_html__('No', 'llms-txt'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('llms.txt URL', 'llms-txt'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank"><?php echo esc_html(home_url('/llms.txt')); ?></a>
                            <?php if ($static_exists) : ?>
                                <span style="color:#00a32a">&nbsp;&#x2713; <?php
                                /* translators: 1: size in bytes, 2: age in seconds */
                                printf(esc_html__('static file (%1$s bytes, written %2$s ago)', 'llms-txt'),
                                    esc_html(number_format((int) $static_size)),
                                    esc_html(human_time_diff(time() - (int) $static_age, time())));
                                ?></span>
                            <?php elseif ($rewrites_ok) : ?>
                                <span style="color:#00a32a">&nbsp;&#x2713; <?php esc_html_e('dynamic via rewrite rule', 'llms-txt'); ?></span>
                            <?php else : ?>
                                <span style="color:#b32d2e">&nbsp;&#x2717; <?php esc_html_e('not serving - check warnings above', 'llms-txt'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Serving mode', 'llms-txt'); ?></th>
                        <td>
                            <?php if ($static_mode) : ?>
                                <?php esc_html_e('Static file', 'llms-txt'); ?>
                                <em>(<?php
                                /* translators: %s: host name */
                                printf(esc_html__('auto-enabled for %s', 'llms-txt'), esc_html($host_name));
                                ?>)</em>
                            <?php else : ?>
                                <?php esc_html_e('Dynamic (served by WordPress on each request)', 'llms-txt'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Audit issues', 'llms-txt'); ?></th>
                        <td>
                            <span style="color:#b32d2e"><?php echo esc_html((string) $issue_counts['critical']); ?> <?php esc_html_e('critical', 'llms-txt'); ?></span> ·
                            <span style="color:#b07d00"><?php echo esc_html((string) $issue_counts['warning']); ?> <?php esc_html_e('warning', 'llms-txt'); ?></span> ·
                            <?php echo esc_html((string) $issue_counts['info']); ?> <?php esc_html_e('info', 'llms-txt'); ?>
                            ·
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpllms-audit')); ?>"><?php esc_html_e('View all', 'llms-txt'); ?></a>
                        </td>
                    </tr>
                    <?php if (is_array($last_audit) && !empty($last_audit['completed_at'])) : ?>
                        <tr>
                            <th><?php esc_html_e('Last full audit', 'llms-txt'); ?></th>
                            <td><?php echo esc_html((string) $last_audit['completed_at']); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Diagnostic: are our rewrite rules in the cached rule set?
     */
    public static function rewrite_rules_active(): bool {
        $rules = get_option('rewrite_rules');
        if (!is_array($rules)) {
            // No cached rules - WordPress regenerates on next request.
            // Treat as "needs refresh" since we can't verify our rule is there.
            return false;
        }
        return isset($rules['^llms\.txt$']);
    }

    public static function handle_refresh_rewrites(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'llms-txt'));
        }
        check_admin_referer(self::REFRESH_REWRITES_ACTION, self::REFRESH_REWRITES_NONCE);

        (new FileServer())->register_rewrite_rules();
        flush_rewrite_rules();

        wp_safe_redirect(add_query_arg('rewrites_refreshed', '1', admin_url('admin.php?page=wpllms')));
        exit;
    }

    public static function handle_write_static(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'llms-txt'));
        }
        check_admin_referer(self::WRITE_STATIC_ACTION, self::WRITE_STATIC_NONCE);

        $result = (new PhysicalFileWriter())->write_llms_txt();
        set_transient('wpllms_static_result', $result, 60);

        wp_safe_redirect(add_query_arg('static_written', '1', admin_url('admin.php?page=wpllms')));
        exit;
    }
}
