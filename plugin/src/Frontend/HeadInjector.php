<?php
declare(strict_types=1);

namespace WPSearch\Frontend;

use WPSearch\Storage\Options;

final class HeadInjector {
    public function register_hooks(): void {
        add_action('wp_head', [$this, 'maybe_inject_link_tag'], 5);
    }

    public function maybe_inject_link_tag(): void {
        $settings = Options::get_settings();
        if (empty($settings['inject_link_tag'])) {
            return;
        }

        $url = home_url('/llms.txt');
        printf(
            '<link rel="llms" href="%s" type="text/markdown">' . "\n",
            esc_url($url)
        );
    }
}
