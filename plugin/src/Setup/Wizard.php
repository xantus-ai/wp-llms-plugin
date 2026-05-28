<?php
declare(strict_types=1);

namespace WPSearch\Setup;

use WPSearch\Frontend\FileServer;
use WPSearch\Frontend\PhysicalFileWriter;
use WPSearch\Storage\Options;
use WPSearch\Storage\SectionsRepository;

/**
 * Orchestrates the multi-step setup wizard. Persists wizard state in
 * the wpsearch_wizard_state option until the user completes setup.
 */
final class Wizard {
    public const STATE_KEY = 'wpsearch_wizard_state';

    public const STEP_BRAND_VOICE = 'brand_voice';
    public const STEP_DETECT = 'detect';
    public const STEP_SECTIONS = 'sections';
    public const STEP_FINALIZE = 'finalize';
    public const STEP_DONE = 'done';

    public static function steps(): array {
        return [
            self::STEP_BRAND_VOICE,
            self::STEP_DETECT,
            self::STEP_SECTIONS,
            self::STEP_FINALIZE,
        ];
    }

    public static function get_state(): array {
        $state = get_option(self::STATE_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }
        return wp_parse_args($state, [
            'current_step' => self::STEP_BRAND_VOICE,
            'brand_voice' => ['site_h1' => '', 'site_summary' => '', 'site_context' => ''],
            'integrations' => ['update_robots_txt' => true, 'inject_link_tag' => true],
            'detection' => null,
            'suggestions' => null,
            'accepted_suggestion_ids' => [],
        ]);
    }

    public static function set_state(array $state): void {
        update_option(self::STATE_KEY, $state, false);
    }

    public static function clear_state(): void {
        delete_option(self::STATE_KEY);
    }

    public static function is_completed(): bool {
        return Options::is_setup_completed();
    }

    public function handle_brand_voice(array $input): array {
        $state = self::get_state();
        $errors = [];

        $h1 = trim((string) ($input['site_h1'] ?? ''));
        $summary = trim((string) ($input['site_summary'] ?? ''));
        $context = trim((string) ($input['site_context'] ?? ''));

        if ($h1 === '') {
            $errors['site_h1'] = __('Required.', 'wpsearch-ai');
        }
        if ($summary === '') {
            $errors['site_summary'] = __('Required. Write 1-3 sentences describing what your site does.', 'wpsearch-ai');
        } elseif (mb_strlen($summary) > 500) {
            $errors['site_summary'] = __('Keep the summary under 500 characters.', 'wpsearch-ai');
        }

        if (count($errors) > 0) {
            return ['ok' => false, 'errors' => $errors];
        }

        $state['brand_voice'] = [
            'site_h1' => $h1,
            'site_summary' => $summary,
            'site_context' => $context,
        ];
        $state['current_step'] = self::STEP_DETECT;
        self::set_state($state);

        return ['ok' => true];
    }

    public function handle_detect_step(array $input): array {
        $state = self::get_state();
        $detection = (new SiteDetector())->detect();

        $state['detection'] = $detection;
        $state['suggestions'] = (new SectionSuggester())->suggest($detection);
        $state['integrations'] = [
            'update_robots_txt' => !empty($input['update_robots_txt']),
            'inject_link_tag' => !empty($input['inject_link_tag']),
        ];
        $state['current_step'] = self::STEP_SECTIONS;

        self::set_state($state);
        return ['ok' => true];
    }

    public function handle_sections_step(array $input): array {
        $state = self::get_state();
        $accepted = isset($input['accepted']) && is_array($input['accepted'])
            ? array_values(array_map('strval', $input['accepted']))
            : [];

        $state['accepted_suggestion_ids'] = $accepted;
        $state['current_step'] = self::STEP_FINALIZE;
        self::set_state($state);

        return ['ok' => true];
    }

    public function finalize(): array {
        $state = self::get_state();

        // Persist brand voice + integrations to settings.
        $settings = Options::get_settings();
        $settings['site_h1'] = (string) ($state['brand_voice']['site_h1'] ?? '');
        $settings['site_summary'] = (string) ($state['brand_voice']['site_summary'] ?? '');
        $settings['site_context'] = (string) ($state['brand_voice']['site_context'] ?? '');
        $settings['update_robots_txt'] = !empty($state['integrations']['update_robots_txt']);
        $settings['inject_link_tag'] = !empty($state['integrations']['inject_link_tag']);
        update_option(Options::SETTINGS_KEY, $settings);

        // Create accepted sections.
        $sections_repo = new SectionsRepository();
        $created = 0;
        $accepted = $state['accepted_suggestion_ids'] ?? [];
        $suggestions = $state['suggestions'] ?? [];

        foreach ($suggestions as $suggestion) {
            $sid = (string) ($suggestion['suggestion_id'] ?? '');
            if (!in_array($sid, $accepted, true)) continue;

            $sections_repo->create([
                'name' => $suggestion['name'],
                'slug' => sanitize_title($suggestion['name'] . '-' . $sid),
                'is_optional' => $suggestion['is_optional'] ?? false,
                'sort_order' => $suggestion['sort_order'] ?? 0,
                'inclusion_rule_json' => $suggestion['inclusion_rule_json'] ?? [],
            ]);
            $created++;
        }

        update_option(Options::SETUP_COMPLETED_KEY, true);
        self::clear_state();

        // Defensively re-register rewrite rules before flushing - the init hook
        // earlier in this request should already have done this, but explicit
        // registration here makes the wizard self-healing even if some earlier
        // hook prevented it.
        (new FileServer())->register_rewrite_rules();
        flush_rewrite_rules();

        // Write the physical file immediately if we're on a host that needs it.
        // This makes /llms.txt resolve right after setup completes on WP Engine,
        // Kinsta, etc. - without waiting for the next save_post or cron tick.
        $write_result = null;
        try {
            $writer = new PhysicalFileWriter();
            if ($writer->is_enabled()) {
                $write_result = $writer->write_llms_txt();
            }
        } catch (\Throwable $e) {
            // Surface in result; don't block setup completion.
            $write_result = ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'sections_created' => $created,
            'static_file_write' => $write_result,
        ];
    }
}
