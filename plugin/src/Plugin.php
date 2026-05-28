<?php
declare(strict_types=1);

namespace WPSearch;

use WPSearch\Admin\Menu;
use WPSearch\Admin\Pages\AuditPage;
use WPSearch\Admin\Pages\SectionEditPage;
use WPSearch\Admin\Pages\SectionsPage;
use WPSearch\Admin\Pages\SettingsPage;
use WPSearch\Admin\Pages\WizardPage;
use WPSearch\Audit\Auditor;
use WPSearch\Audit\IssuesRepository;
use WPSearch\Cron\Scheduler;
use WPSearch\Frontend\FileServer;
use WPSearch\Frontend\HeadInjector;
use WPSearch\Frontend\PhysicalFileWriter;
use WPSearch\Frontend\RobotsTxt;

final class Plugin {
    private static ?Plugin $instance = null;

    private FileServer $file_server;
    private HeadInjector $head_injector;
    private RobotsTxt $robots_txt;

    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->file_server = new FileServer();
        $this->head_injector = new HeadInjector();
        $this->robots_txt = new RobotsTxt();
        $this->register_hooks();
    }

    private function __clone() {}

    public function __wakeup(): void {
        throw new \RuntimeException('Cannot unserialize singleton.');
    }

    private function register_hooks(): void {
        add_action('admin_menu', [Menu::class, 'register']);
        add_action(Scheduler::DAILY_HOOK, [Scheduler::class, 'on_daily_tick']);

        $this->file_server->register_hooks();
        $this->head_injector->register_hooks();
        $this->robots_txt->register_hooks();

        add_action('save_post', [self::class, 'on_save_post'], 10, 2);
        add_action('deleted_post', [self::class, 'on_deleted_post']);

        add_action('admin_post_' . WizardPage::FORM_ACTION, [WizardPage::class, 'handle_post']);
        add_action('admin_post_' . AuditPage::FORM_ACTION, [AuditPage::class, 'handle_run']);
        add_action('admin_post_' . SectionsPage::FORM_ACTION, [SectionsPage::class, 'handle_post']);
        add_action('admin_post_' . SectionEditPage::FORM_ACTION, [SectionEditPage::class, 'handle_post']);
        add_action('wp_ajax_wpsearch_search_posts', [SectionEditPage::class, 'handle_search']);
        add_action('admin_post_' . SettingsPage::FORM_ACTION, [SettingsPage::class, 'handle_post']);
        add_action('admin_post_' . Menu::REFRESH_REWRITES_ACTION, [Menu::class, 'handle_refresh_rewrites']);
        add_action('admin_post_' . Menu::WRITE_STATIC_ACTION, [Menu::class, 'handle_write_static']);
    }

    public static function on_save_post(int $post_id, \WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($post->post_status !== 'publish' && $post->post_status !== 'private') {
            return;
        }

        FileServer::invalidate_cache();
        self::maybe_write_static_files();

        // Per-post audit (no site context - duplicate_title and generic_h1 skipped here;
        // they run during full-site audits via cron).
        try {
            (new Auditor())->audit_post($post);
        } catch (\Throwable $e) {
            // Audit failures should never break post save.
        }
    }

    public static function on_deleted_post(int $post_id): void {
        FileServer::invalidate_cache();
        self::maybe_write_static_files();
        (new IssuesRepository())->clear_for_post($post_id);
    }

    /**
     * If physical-file mode is enabled, regenerate the llms.txt file on disk.
     * Called after content changes so nginx serves a fresh file.
     */
    public static function maybe_write_static_files(): void {
        try {
            $writer = new PhysicalFileWriter();
            $file_exists = $writer->exists(PhysicalFileWriter::LLMS_TXT_FILENAME);
            if (!$writer->is_enabled() && !$file_exists) {
                return;
            }
            $writer->write_llms_txt();
        } catch (\Throwable $e) {
            // Silent fail; the dynamic FileServer is the fallback.
        }
    }

    public function audit_all(): array {
        return (new Auditor())->audit_all();
    }

    public function version(): string {
        return WPSEARCH_VERSION;
    }
}
