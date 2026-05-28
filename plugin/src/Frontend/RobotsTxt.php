<?php
declare(strict_types=1);

namespace WPSearch\Frontend;

use WPSearch\Storage\Options;

final class RobotsTxt {
    public function register_hooks(): void {
        add_filter('robots_txt', [$this, 'maybe_append_llms_reference'], 10, 2);
    }

    public function maybe_append_llms_reference(string $output, bool $public): string {
        $settings = Options::get_settings();
        if (empty($settings['update_robots_txt'])) {
            return $output;
        }

        if (!$public) {
            return $output;
        }

        $llms_url = home_url('/llms.txt');
        if (stripos($output, 'llms.txt') !== false) {
            return $output;
        }

        $output = rtrim($output) . "\n\n# llms.txt: " . $llms_url . "\n";

        // If no Sitemap is referenced and a sitemap exists, add it too.
        if (stripos($output, 'Sitemap:') === false) {
            $sitemap_url = $this->detect_sitemap_url();
            if ($sitemap_url !== null) {
                $output .= "Sitemap: " . $sitemap_url . "\n";
            }
        }

        return $output;
    }

    private function detect_sitemap_url(): ?string {
        // Yoast and core WP both expose /sitemap_index.xml or /wp-sitemap.xml.
        $candidates = [
            home_url('/sitemap_index.xml'),
            home_url('/wp-sitemap.xml'),
            home_url('/sitemap.xml'),
        ];
        foreach ($candidates as $url) {
            $response = wp_remote_head($url, ['timeout' => 3, 'redirection' => 1]);
            if (is_wp_error($response)) continue;
            if ((int) wp_remote_retrieve_response_code($response) === 200) {
                return $url;
            }
        }
        return null;
    }
}
