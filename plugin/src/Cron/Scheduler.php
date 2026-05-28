<?php
declare(strict_types=1);

namespace WPSearch\Cron;

use WPSearch\Audit\Auditor;
use WPSearch\Frontend\FileServer;
use WPSearch\Frontend\PhysicalFileWriter;

final class Scheduler {
    public const DAILY_HOOK = 'wpsearch_daily_regen';
    public const REGEN_LLMS_TXT_HOOK = 'wpsearch_regen_llms_txt';
    public const REGEN_LLMS_FULL_HOOK = 'wpsearch_regen_llms_full';

    public static function schedule_events(): void {
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK);
        }
    }

    public static function unschedule_events(): void {
        wp_clear_scheduled_hook(self::DAILY_HOOK);
        wp_clear_scheduled_hook(self::REGEN_LLMS_TXT_HOOK);
        wp_clear_scheduled_hook(self::REGEN_LLMS_FULL_HOOK);
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

        // Run full-site audit (with site context for cross-post rules).
        try {
            $result = (new Auditor())->audit_all();
            update_option('wpsearch_last_audit', [
                'completed_at' => current_time('mysql', true),
                'issues_found' => $result['issues_found'],
                'posts_audited' => $result['posts_audited'],
            ], false);
        } catch (\Throwable $e) {
            // Don't crash cron on audit failure.
        }
    }
}
