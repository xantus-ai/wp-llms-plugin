<?php
declare(strict_types=1);

namespace WPSearch\Admin;

use WPSearch\Admin\Pages\AuditPage;
use WPSearch\Admin\Pages\SectionEditPage;
use WPSearch\Admin\Pages\SectionsPage;
use WPSearch\Admin\Pages\SettingsPage;
use WPSearch\Admin\Pages\WizardPage;
use WPSearch\Audit\IssuesRepository;
use WPSearch\Frontend\FileServer;
use WPSearch\Frontend\HostDetector;
use WPSearch\Frontend\PhysicalFileWriter;
use WPSearch\Storage\Options;
use WPSearch\Storage\Schema;

final class Menu {
    public const SLUG = 'wpsearch';
    public const CAPABILITY = 'manage_options';
    public const REFRESH_REWRITES_ACTION = 'wpsearch_refresh_rewrites';
    public const REFRESH_REWRITES_NONCE = 'wpsearch_refresh_rewrites_nonce';
    public const WRITE_STATIC_ACTION = 'wpsearch_write_static';
    public const WRITE_STATIC_NONCE = 'wpsearch_write_static_nonce';

    public static function register(): void {
        add_menu_page(
            __('WPSearch', 'wpsearch-ai'),
            __('WPSearch', 'wpsearch-ai'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render_dashboard'],
            'dashicons-search',
            80
        );

        add_submenu_page(
            self::SLUG,
            __('Dashboard', 'wpsearch-ai'),
            __('Dashboard', 'wpsearch-ai'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render_dashboard']
        );

        add_submenu_page(
            self::SLUG,
            __('Setup Wizard', 'wpsearch-ai'),
            __('Setup Wizard', 'wpsearch-ai'),
            self::CAPABILITY,
            'wpsearch-wizard',
            [WizardPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('Sections', 'wpsearch-ai'),
            __('Sections', 'wpsearch-ai'),
            self::CAPABILITY,
            'wpsearch-sections',
            [SectionsPage::class, 'render']
        );

        // Hidden sub-page (no menu item, accessed via list page links).
        add_submenu_page(
            '',
            __('Add Section', 'wpsearch-ai'),
            __('Add Section', 'wpsearch-ai'),
            self::CAPABILITY,
            SectionEditPage::PAGE_SLUG,
            [SectionEditPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('Audit', 'wpsearch-ai'),
            __('Audit', 'wpsearch-ai'),
            self::CAPABILITY,
            'wpsearch-audit',
            [AuditPage::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('Settings', 'wpsearch-ai'),
            __('Settings', 'wpsearch-ai'),
            self::CAPABILITY,
            'wpsearch-settings',
            [SettingsPage::class, 'render']
        );
    }

    public static function render_dashboard(): void {
        $setup_done = Options::is_setup_completed();
        $issue_counts = (new IssuesRepository())->counts_by_severity();
        $last_audit = get_option('wpsearch_last_audit', null);
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
            <h1><?php esc_html_e('WPSearch', 'wpsearch-ai'); ?></h1>
            <p><?php esc_html_e('AI Search Optimization for WordPress.', 'wpsearch-ai'); ?></p>

            <?php if (isset($_GET['setup_done'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e('Setup complete.', 'wpsearch-ai'); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: link to llms.txt */
                            esc_html__('Visit %s to see your generated file.', 'wpsearch-ai'),
                            '<a href="' . esc_url(home_url('/llms.txt')) . '" target="_blank">' . esc_html(home_url('/llms.txt')) . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['rewrites_refreshed'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Permalink rules refreshed. /llms.txt should now resolve.', 'wpsearch-ai'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($host_blocks_txt && $setup_done && !$static_exists) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php
                        /* translators: %s: host name */
                        printf(esc_html__('%s detected.', 'wpsearch-ai'), esc_html($host_name));
                        ?></strong>
                        <?php esc_html_e('This host blocks dynamic .txt URLs at the nginx layer, so /llms.txt only works if a physical file exists at the document root. We need to write that file. Click below to generate it.', 'wpsearch-ai'); ?>
                    </p>
                    <?php if (!$can_write_root) : ?>
                        <p style="color:#b32d2e"><strong><?php esc_html_e('Warning: the WordPress root directory is not writable. Contact your host to enable write permissions on the document root, or contact WP Engine support to whitelist /llms.txt.', 'wpsearch-ai'); ?></strong></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::WRITE_STATIC_ACTION, self::WRITE_STATIC_NONCE); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::WRITE_STATIC_ACTION); ?>">
                        <p><button class="button button-primary" <?php disabled(!$can_write_root); ?>><?php esc_html_e('Write llms.txt to disk', 'wpsearch-ai'); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['static_written'])) :
                $w = get_transient('wpsearch_static_result');
                delete_transient('wpsearch_static_result');
                if (is_array($w) && !empty($w['ok'])) : ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php
                        /* translators: 1: path, 2: byte count */
                        printf(esc_html__('Wrote %1$s (%2$s bytes). /llms.txt should now resolve.', 'wpsearch-ai'),
                            '<code>' . esc_html((string) $w['path']) . '</code>',
                            esc_html(number_format((int) $w['bytes'])));
                        ?></p>
                    </div>
                <?php elseif (is_array($w)) : ?>
                    <div class="notice notice-error">
                        <p><?php
                        /* translators: %s: error code */
                        printf(esc_html__('Could not write file: %s', 'wpsearch-ai'), esc_html((string) ($w['error'] ?? 'unknown')));
                        ?></p>
                    </div>
                <?php endif;
            endif; ?>

            <?php if ($permalinks_plain) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Plain permalinks detected.', 'wpsearch-ai'); ?></strong>
                        <?php esc_html_e('WPSearch requires pretty permalinks to serve /llms.txt. Go to Settings -> Permalinks and choose any option other than "Plain", then save.', 'wpsearch-ai'); ?>
                    </p>
                    <p><a class="button" href="<?php echo esc_url(admin_url('options-permalink.php')); ?>"><?php esc_html_e('Open Permalinks settings', 'wpsearch-ai'); ?></a></p>
                </div>
            <?php elseif (!$rewrites_ok && !$static_mode) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e('Permalink rules need refreshing.', 'wpsearch-ai'); ?></strong>
                        <?php esc_html_e('The rewrite rules that serve /llms.txt aren\'t active yet. This is a known WordPress quirk that can happen on first activation. Click below to fix it.', 'wpsearch-ai'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::REFRESH_REWRITES_ACTION, self::REFRESH_REWRITES_NONCE); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::REFRESH_REWRITES_ACTION); ?>">
                        <p><button class="button button-primary"><?php esc_html_e('Refresh permalinks', 'wpsearch-ai'); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!$setup_done) : ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php esc_html_e('Welcome to WPSearch.', 'wpsearch-ai'); ?></strong>
                        <?php esc_html_e('Run the setup wizard to configure your llms.txt in 4 steps.', 'wpsearch-ai'); ?>
                    </p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wpsearch-wizard')); ?>"><?php esc_html_e('Run setup wizard', 'wpsearch-ai'); ?></a></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Status', 'wpsearch-ai'); ?></h2>
            <table class="widefat striped" style="max-width:700px">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Plugin version', 'wpsearch-ai'); ?></th>
                        <td><code><?php echo esc_html(WPSEARCH_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Schema version', 'wpsearch-ai'); ?></th>
                        <td><code><?php echo esc_html((string) get_option(Schema::VERSION_OPTION, 'not installed')); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('License tier', 'wpsearch-ai'); ?></th>
                        <td><code><?php echo esc_html((string) Options::get_license()['tier']); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Setup completed', 'wpsearch-ai'); ?></th>
                        <td><?php echo $setup_done ? esc_html__('Yes', 'wpsearch-ai') : esc_html__('No', 'wpsearch-ai'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('llms.txt URL', 'wpsearch-ai'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank"><?php echo esc_html(home_url('/llms.txt')); ?></a>
                            <?php if ($static_exists) : ?>
                                <span style="color:#00a32a">&nbsp;&#x2713; <?php
                                /* translators: 1: size in bytes, 2: age in seconds */
                                printf(esc_html__('static file (%1$s bytes, written %2$s ago)', 'wpsearch-ai'),
                                    esc_html(number_format((int) $static_size)),
                                    esc_html(human_time_diff(time() - (int) $static_age, time())));
                                ?></span>
                            <?php elseif ($rewrites_ok) : ?>
                                <span style="color:#00a32a">&nbsp;&#x2713; <?php esc_html_e('dynamic via rewrite rule', 'wpsearch-ai'); ?></span>
                            <?php else : ?>
                                <span style="color:#b32d2e">&nbsp;&#x2717; <?php esc_html_e('not serving - check warnings above', 'wpsearch-ai'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Serving mode', 'wpsearch-ai'); ?></th>
                        <td>
                            <?php if ($static_mode) : ?>
                                <?php esc_html_e('Static file', 'wpsearch-ai'); ?>
                                <em>(<?php
                                /* translators: %s: host name */
                                printf(esc_html__('auto-enabled for %s', 'wpsearch-ai'), esc_html($host_name));
                                ?>)</em>
                            <?php else : ?>
                                <?php esc_html_e('Dynamic (served by WordPress on each request)', 'wpsearch-ai'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Audit issues', 'wpsearch-ai'); ?></th>
                        <td>
                            <span style="color:#b32d2e"><?php echo esc_html((string) $issue_counts['critical']); ?> <?php esc_html_e('critical', 'wpsearch-ai'); ?></span> ·
                            <span style="color:#b07d00"><?php echo esc_html((string) $issue_counts['warning']); ?> <?php esc_html_e('warning', 'wpsearch-ai'); ?></span> ·
                            <?php echo esc_html((string) $issue_counts['info']); ?> <?php esc_html_e('info', 'wpsearch-ai'); ?>
                            ·
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpsearch-audit')); ?>"><?php esc_html_e('View all', 'wpsearch-ai'); ?></a>
                        </td>
                    </tr>
                    <?php if (is_array($last_audit) && !empty($last_audit['completed_at'])) : ?>
                        <tr>
                            <th><?php esc_html_e('Last full audit', 'wpsearch-ai'); ?></th>
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
            wp_die(esc_html__('Insufficient permissions.', 'wpsearch-ai'));
        }
        check_admin_referer(self::REFRESH_REWRITES_ACTION, self::REFRESH_REWRITES_NONCE);

        (new FileServer())->register_rewrite_rules();
        flush_rewrite_rules();

        wp_safe_redirect(add_query_arg('rewrites_refreshed', '1', admin_url('admin.php?page=wpsearch')));
        exit;
    }

    public static function handle_write_static(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'wpsearch-ai'));
        }
        check_admin_referer(self::WRITE_STATIC_ACTION, self::WRITE_STATIC_NONCE);

        $result = (new PhysicalFileWriter())->write_llms_txt();
        set_transient('wpsearch_static_result', $result, 60);

        wp_safe_redirect(add_query_arg('static_written', '1', admin_url('admin.php?page=wpsearch')));
        exit;
    }
}
