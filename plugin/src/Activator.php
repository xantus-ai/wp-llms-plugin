<?php
declare(strict_types=1);

namespace WPSearch;

use WPSearch\Cron\Scheduler;
use WPSearch\Frontend\FileServer;
use WPSearch\Storage\Options;
use WPSearch\Storage\Schema;

final class Activator {
    public static function activate(): void {
        Schema::install();
        Options::seed_defaults();
        Scheduler::schedule_events();

        // During activation, the `init` action has already fired (with our plugin
        // not yet loaded), so our normal init-hooked rule registration didn't run.
        // We must explicitly register before flushing so the cache rebuild
        // includes our rules - otherwise /llms.txt 404s until something else
        // triggers a flush.
        (new FileServer())->register_rewrite_rules();
        flush_rewrite_rules();
    }
}
