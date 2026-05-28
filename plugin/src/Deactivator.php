<?php
declare(strict_types=1);

namespace WPSearch;

use WPSearch\Cron\Scheduler;
use WPSearch\Frontend\PhysicalFileWriter;

final class Deactivator {
    public static function deactivate(): void {
        Scheduler::unschedule_events();

        // Remove physical files so deactivating the plugin doesn't leave a
        // stale llms.txt that nginx keeps serving forever.
        try {
            (new PhysicalFileWriter())->delete_all();
        } catch (\Throwable $e) {
            // Best-effort.
        }

        flush_rewrite_rules();
    }
}
