<?php
declare(strict_types=1);

namespace WPLlms\Cron;

use WPLlms\Audit\Auditor;
use WPLlms\Frontend\FileServer;
use WPLlms\Frontend\PhysicalFileWriter;

final class Scheduler {
    public const DAILY_HOOK = 'wpllms_daily_regen';
    public const REGEN_LLMS_TXT_HOOK = 'wpllms_regen_llms_txt';
    public const REGEN_LLMS_FULL_HOOK = 'wpllms_regen_llms_full';
    public const AUDIT_RESUME_HOOK = 'wpllms_audit_resume';

    // Cron context has a longer PHP timeout than admin-post requests on most
    // hosts, so we can do more per tick. Stays well under the typical 5-min
    // wp-cron limit on managed hosts.
    private const CRON_AUDIT_MAX_SECONDS = 60;

    public static function schedule_events(): void {
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK);
        }
    }

    public static function unschedule_events(): void {
        wp_clear_scheduled_hook(self::DAILY_HOOK);
        wp_clear_scheduled_hook(self::REGEN_LLMS_TXT_HOOK);
        wp_clear_scheduled_hook(self::REGEN_LLMS_FULL_HOOK);
        wp_clear_scheduled_hook(self::AUDIT_RESUME_HOOK);
    }

    public static function on_daily_tick(): void {
        // Invalidate llms.txt cache so next request regenerates fresh.
        FileServer::invalidate_cache();

        // If physical-file mode is enabled, rewrite both files (full version
        // only updates on daily cron since it's expensive).
        try {
            $writer = new PhysicalFileWriter();
            if ($writer->is_enabled()) {
                $writer->write_all();
            }
        } catch (\Throwable $e) {
            // Continue with audit even if writer fails.
        }

        self::run_audit_chunk();
    }

    /**
     * Run a chunked audit pass. If the audit doesn't complete in this tick,
     * schedule a follow-up tick a few minutes out so the audit continues
     * without waiting for tomorrow's daily run.
     */
    public static function run_audit_chunk(): void {
        try {
            $result = (new Auditor())->audit_all(self::CRON_AUDIT_MAX_SECONDS);
            if (empty($result['is_complete'])) {
                // Schedule a single follow-up tick to continue.
                if (!wp_next_scheduled(self::AUDIT_RESUME_HOOK)) {
                    wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), self::AUDIT_RESUME_HOOK);
                }
            }
        } catch (\Throwable $e) {
            // Don't crash cron on audit failure.
        }
    }
}
