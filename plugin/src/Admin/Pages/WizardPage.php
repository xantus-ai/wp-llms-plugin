<?php
declare(strict_types=1);

namespace WPSearch\Admin\Pages;

use WPSearch\Setup\Wizard;

final class WizardPage {
    public const PAGE_SLUG = 'wpsearch-wizard';
    public const FORM_ACTION = 'wpsearch_wizard';
    public const NONCE_ACTION = 'wpsearch_wizard_step';
    public const NONCE_NAME = 'wpsearch_wizard_nonce';

    public static function render(): void {
        $state = Wizard::get_state();
        $current = (string) ($state['current_step'] ?? Wizard::STEP_BRAND_VOICE);

        echo '<div class="wrap wpsearch-wizard">';
        echo '<h1>' . esc_html__('WPSearch Setup', 'wpsearch-ai') . '</h1>';
        self::render_step_indicator($current);

        switch ($current) {
            case Wizard::STEP_BRAND_VOICE:
                self::render_brand_voice($state);
                break;
            case Wizard::STEP_DETECT:
                self::render_detect($state);
                break;
            case Wizard::STEP_SECTIONS:
                self::render_sections($state);
                break;
            case Wizard::STEP_FINALIZE:
                self::render_finalize($state);
                break;
            default:
                self::render_done();
                break;
        }

        echo '</div>';
    }

    public static function handle_post(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'wpsearch-ai'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $step = isset($_POST['step']) ? sanitize_key((string) $_POST['step']) : '';
        $wizard = new Wizard();

        $result = match ($step) {
            Wizard::STEP_BRAND_VOICE => $wizard->handle_brand_voice($_POST),
            Wizard::STEP_DETECT => $wizard->handle_detect_step($_POST),
            Wizard::STEP_SECTIONS => $wizard->handle_sections_step($_POST),
            Wizard::STEP_FINALIZE => $wizard->finalize(),
            default => ['ok' => false, 'errors' => ['step' => __('Unknown step.', 'wpsearch-ai')]],
        };

        $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG);
        if (!empty($result['errors'])) {
            set_transient('wpsearch_wizard_errors', $result['errors'], 60);
            $redirect = add_query_arg('errors', '1', $redirect);
        }
        if ($step === Wizard::STEP_FINALIZE && !empty($result['ok'])) {
            $redirect = admin_url('admin.php?page=wpsearch&setup_done=1');
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private static function render_step_indicator(string $current): void {
        $steps = [
            Wizard::STEP_BRAND_VOICE => __('1. Brand voice', 'wpsearch-ai'),
            Wizard::STEP_DETECT => __('2. Detect site', 'wpsearch-ai'),
            Wizard::STEP_SECTIONS => __('3. Choose sections', 'wpsearch-ai'),
            Wizard::STEP_FINALIZE => __('4. Finalize', 'wpsearch-ai'),
        ];
        echo '<ol class="wpsearch-wizard-steps">';
        foreach ($steps as $key => $label) {
            $class = ($key === $current) ? 'is-current' : '';
            printf('<li class="%s">%s</li>', esc_attr($class), esc_html($label));
        }
        echo '</ol>';
    }

    private static function render_brand_voice(array $state): void {
        $errors = self::pop_errors();
        $voice = $state['brand_voice'];
        $defaults = [
            'site_h1' => $voice['site_h1'] !== '' ? $voice['site_h1'] : (string) get_bloginfo('name'),
            'site_summary' => $voice['site_summary'],
            'site_context' => $voice['site_context'],
        ];
        ?>
        <p><?php esc_html_e('Tell AI systems who you are and what your site does. Be specific - this is the most important text in your llms.txt.', 'wpsearch-ai'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
            <input type="hidden" name="step" value="<?php echo esc_attr(Wizard::STEP_BRAND_VOICE); ?>">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="site_h1"><?php esc_html_e('Site name (H1)', 'wpsearch-ai'); ?></label></th>
                    <td>
                        <input type="text" id="site_h1" name="site_h1" value="<?php echo esc_attr($defaults['site_h1']); ?>" class="regular-text" required>
                        <?php if (!empty($errors['site_h1'])) printf('<p class="description" style="color:#b32d2e">%s</p>', esc_html($errors['site_h1'])); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="site_summary"><?php esc_html_e('Summary (blockquote)', 'wpsearch-ai'); ?></label></th>
                    <td>
                        <textarea id="site_summary" name="site_summary" rows="3" class="large-text" maxlength="500" required><?php echo esc_textarea($defaults['site_summary']); ?></textarea>
                        <p class="description"><?php esc_html_e('1-3 sentences. Lead with what you do, who it\'s for, and what makes you specific. Up to 500 characters.', 'wpsearch-ai'); ?></p>
                        <?php if (!empty($errors['site_summary'])) printf('<p class="description" style="color:#b32d2e">%s</p>', esc_html($errors['site_summary'])); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="site_context"><?php esc_html_e('Context (optional)', 'wpsearch-ai'); ?></label></th>
                    <td>
                        <textarea id="site_context" name="site_context" rows="6" class="large-text"><?php echo esc_textarea($defaults['site_context']); ?></textarea>
                        <p class="description"><?php esc_html_e('Optional longer-form context: founder story, methodology, scope. Markdown allowed.', 'wpsearch-ai'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Continue →', 'wpsearch-ai'); ?></button>
            </p>
        </form>
        <?php
    }

    private static function render_detect(array $state): void {
        // Run detection inline so user sees report immediately. Persisted on submit.
        $detector = new \WPSearch\Setup\SiteDetector();
        $report = $detector->detect();
        ?>
        <p><?php esc_html_e('We scanned your site. Review what we found and choose which integrations to enable.', 'wpsearch-ai'); ?></p>

        <h2><?php esc_html_e('Site detection', 'wpsearch-ai'); ?></h2>
        <table class="widefat striped">
            <tbody>
                <tr><th><?php esc_html_e('Site name', 'wpsearch-ai'); ?></th><td><?php echo esc_html($report['site_name']); ?></td></tr>
                <tr><th><?php esc_html_e('SEO plugin', 'wpsearch-ai'); ?></th><td><?php echo $report['seo_plugin'] ? esc_html(ucfirst($report['seo_plugin'])) : esc_html__('None detected', 'wpsearch-ai'); ?></td></tr>
                <tr><th><?php esc_html_e('Page builder', 'wpsearch-ai'); ?></th><td><?php echo $report['builder'] ? esc_html(ucfirst($report['builder'])) : esc_html__('None / Gutenberg', 'wpsearch-ai'); ?></td></tr>
                <tr><th><?php esc_html_e('WooCommerce', 'wpsearch-ai'); ?></th><td><?php echo $report['woocommerce'] ? esc_html__('Active (products excluded by default)', 'wpsearch-ai') : esc_html__('Not active', 'wpsearch-ai'); ?></td></tr>
                <tr><th><?php esc_html_e('Meta description coverage', 'wpsearch-ai'); ?></th><td>
                    <?php
                    $cov = $report['meta_coverage'];
                    printf(
                        /* translators: 1: count set, 2: total, 3: percentage */
                        esc_html__('%1$d of %2$d posts/pages (%3$s%%)', 'wpsearch-ai'),
                        (int) $cov['set'],
                        (int) $cov['total'],
                        esc_html((string) $cov['coverage_pct'])
                    );
                    ?>
                </td></tr>
                <tr><th><?php esc_html_e('robots.txt mentions llms.txt', 'wpsearch-ai'); ?></th><td><?php echo $report['robots_txt_references_llms'] ? esc_html__('Yes', 'wpsearch-ai') : esc_html__('No', 'wpsearch-ai'); ?></td></tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Content types', 'wpsearch-ai'); ?></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Type', 'wpsearch-ai'); ?></th>
                <th><?php esc_html_e('Count', 'wpsearch-ai'); ?></th>
                <th><?php esc_html_e('Last update', 'wpsearch-ai'); ?></th>
                <th><?php esc_html_e('Status', 'wpsearch-ai'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($report['post_types'] as $type) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($type['label']); ?></strong> <code><?php echo esc_html($type['name']); ?></code></td>
                        <td><?php echo esc_html((string) $type['count']); ?></td>
                        <td><?php echo $type['age_months'] !== null ? sprintf(esc_html__('%d months ago', 'wpsearch-ai'), (int) $type['age_months']) : '—'; ?></td>
                        <td><?php echo !empty($type['is_stale']) ? '<span style="color:#b32d2e">' . esc_html__('Stale (≥24mo)', 'wpsearch-ai') . '</span>' : esc_html__('Active', 'wpsearch-ai'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:24px">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
            <input type="hidden" name="step" value="<?php echo esc_attr(Wizard::STEP_DETECT); ?>">

            <h2><?php esc_html_e('Integrations', 'wpsearch-ai'); ?></h2>
            <p>
                <label><input type="checkbox" name="update_robots_txt" value="1" checked>
                    <?php esc_html_e('Update robots.txt to reference /llms.txt', 'wpsearch-ai'); ?></label>
            </p>
            <p>
                <label><input type="checkbox" name="inject_link_tag" value="1" checked>
                    <?php esc_html_e('Inject <link rel="llms"> tag into <head>', 'wpsearch-ai'); ?></label>
            </p>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Continue →', 'wpsearch-ai'); ?></button>
            </p>
        </form>
        <?php
    }

    private static function render_sections(array $state): void {
        $suggestions = $state['suggestions'] ?? [];
        if (count($suggestions) === 0) {
            ?>
            <p><?php esc_html_e('We didn\'t find enough content to suggest sections automatically. You can add sections manually after setup.', 'wpsearch-ai'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
                <input type="hidden" name="step" value="<?php echo esc_attr(Wizard::STEP_SECTIONS); ?>">
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Continue →', 'wpsearch-ai'); ?></button>
                </p>
            </form>
            <?php
            return;
        }
        ?>
        <p><?php esc_html_e('Based on your content, here are sections we suggest. Uncheck any you don\'t want.', 'wpsearch-ai'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
            <input type="hidden" name="step" value="<?php echo esc_attr(Wizard::STEP_SECTIONS); ?>">

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th><?php esc_html_e('Section name', 'wpsearch-ai'); ?></th>
                        <th><?php esc_html_e('Items', 'wpsearch-ai'); ?></th>
                        <th><?php esc_html_e('Optional', 'wpsearch-ai'); ?></th>
                        <th><?php esc_html_e('Notes', 'wpsearch-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suggestions as $s) : ?>
                        <tr>
                            <td><input type="checkbox" name="accepted[]" value="<?php echo esc_attr((string) $s['suggestion_id']); ?>" checked></td>
                            <td><strong><?php echo esc_html((string) $s['name']); ?></strong></td>
                            <td><?php echo esc_html((string) ($s['preview_count'] ?? 0)); ?></td>
                            <td><?php echo !empty($s['is_optional']) ? esc_html__('Optional', 'wpsearch-ai') : esc_html__('Required', 'wpsearch-ai'); ?></td>
                            <td><?php echo !empty($s['note']) ? esc_html((string) $s['note']) : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Continue →', 'wpsearch-ai'); ?></button>
            </p>
        </form>
        <?php
    }

    private static function render_finalize(array $state): void {
        $accepted = $state['accepted_suggestion_ids'] ?? [];
        $suggestions = $state['suggestions'] ?? [];
        $accepted_suggestions = array_filter($suggestions, static fn(array $s) => in_array($s['suggestion_id'] ?? '', $accepted, true));
        ?>
        <p><?php esc_html_e('Ready to generate your llms.txt with the configuration below.', 'wpsearch-ai'); ?></p>

        <h2><?php esc_html_e('Brand voice', 'wpsearch-ai'); ?></h2>
        <table class="widefat">
            <tbody>
                <tr><th><?php esc_html_e('H1', 'wpsearch-ai'); ?></th><td><?php echo esc_html((string) $state['brand_voice']['site_h1']); ?></td></tr>
                <tr><th><?php esc_html_e('Summary', 'wpsearch-ai'); ?></th><td><?php echo esc_html((string) $state['brand_voice']['site_summary']); ?></td></tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Sections to create', 'wpsearch-ai'); ?> (<?php echo count($accepted_suggestions); ?>)</h2>
        <ul>
            <?php foreach ($accepted_suggestions as $s) : ?>
                <li><strong><?php echo esc_html((string) $s['name']); ?></strong> — <?php echo esc_html((string) ($s['preview_count'] ?? 0)); ?> <?php esc_html_e('items', 'wpsearch-ai'); ?></li>
            <?php endforeach; ?>
        </ul>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
            <input type="hidden" name="step" value="<?php echo esc_attr(Wizard::STEP_FINALIZE); ?>">

            <p class="submit">
                <button type="submit" class="button button-primary button-hero"><?php esc_html_e('Generate llms.txt', 'wpsearch-ai'); ?></button>
            </p>
        </form>
        <?php
    }

    private static function render_done(): void {
        ?>
        <p><?php esc_html_e('Setup is complete. Visit /llms.txt to see the generated file.', 'wpsearch-ai'); ?></p>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=wpsearch')); ?>" class="button"><?php esc_html_e('Back to dashboard', 'wpsearch-ai'); ?></a></p>
        <?php
    }

    private static function pop_errors(): array {
        $errors = get_transient('wpsearch_wizard_errors');
        delete_transient('wpsearch_wizard_errors');
        return is_array($errors) ? $errors : [];
    }
}
