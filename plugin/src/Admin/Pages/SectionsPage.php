<?php
declare(strict_types=1);

namespace WPSearch\Admin\Pages;

use WPSearch\Admin\Pages\SectionEditPage;
use WPSearch\Frontend\FileServer;
use WPSearch\Plugin;
use WPSearch\Storage\SectionsRepository;

final class SectionsPage {
    public const FORM_ACTION = 'wpsearch_sections';
    public const NONCE_ACTION = 'wpsearch_sections';
    public const NONCE_NAME = 'wpsearch_sections_nonce';

    public static function render(): void {
        $repo = new SectionsRepository();
        $sections = $repo->all();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Sections', 'wpsearch-ai') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=' . SectionEditPage::PAGE_SLUG)) . '" class="page-title-action">' . esc_html__('Add new', 'wpsearch-ai') . '</a>';
        echo '<hr class="wp-header-end">';
        echo '<p>' . esc_html__('Sections become H2 headings in your llms.txt. Each section pulls posts via an inclusion rule.', 'wpsearch-ai') . '</p>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Section saved.', 'wpsearch-ai') . '</p></div>';
        }

        if (count($sections) === 0) {
            ?>
            <p><?php esc_html_e('No sections configured yet. Run the setup wizard or add a section manually.', 'wpsearch-ai'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wpsearch-wizard')); ?>"><?php esc_html_e('Run setup wizard', 'wpsearch-ai'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . SectionEditPage::PAGE_SLUG)); ?>"><?php esc_html_e('Add a section manually', 'wpsearch-ai'); ?></a>
            </p>
            <?php
        } else {
            ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Sort', 'wpsearch-ai'); ?></th>
                    <th><?php esc_html_e('Name', 'wpsearch-ai'); ?></th>
                    <th><?php esc_html_e('Optional', 'wpsearch-ai'); ?></th>
                    <th><?php esc_html_e('Inclusion rule', 'wpsearch-ai'); ?></th>
                    <th><?php esc_html_e('Actions', 'wpsearch-ai'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($sections as $section) :
                        $rule = json_decode((string) ($section['inclusion_rule_json'] ?? ''), true);
                        $rule_label = is_array($rule) && isset($rule['type']) ? (string) $rule['type'] : 'unknown';
                        if (is_array($rule) && ($rule['type'] ?? '') === 'post_type') {
                            $rule_label .= ': ' . (string) ($rule['post_type'] ?? '');
                        } elseif (is_array($rule) && ($rule['type'] ?? '') === 'manual') {
                            $rule_label .= ': ' . count($rule['post_ids'] ?? []) . ' posts';
                        }
                        $delete_url = wp_nonce_url(
                            add_query_arg([
                                'action' => self::FORM_ACTION,
                                'op' => 'delete',
                                'id' => (int) $section['id'],
                            ], admin_url('admin-post.php')),
                            self::NONCE_ACTION,
                            self::NONCE_NAME
                        );
                        $edit_url = add_query_arg([
                            'page' => SectionEditPage::PAGE_SLUG,
                            'id' => (int) $section['id'],
                        ], admin_url('admin.php'));
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) $section['sort_order']); ?></td>
                            <td><strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html((string) $section['name']); ?></a></strong></td>
                            <td><?php echo !empty($section['is_optional']) ? esc_html__('Yes', 'wpsearch-ai') : '—'; ?></td>
                            <td><code><?php echo esc_html($rule_label); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'wpsearch-ai'); ?></a>
                                |
                                <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Delete this section?')" style="color:#b32d2e"><?php esc_html_e('Delete', 'wpsearch-ai'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        echo '</div>';
    }

    public static function handle_post(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'wpsearch-ai'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $op = isset($_REQUEST['op']) ? sanitize_key((string) $_REQUEST['op']) : '';
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

        if ($op === 'delete' && $id > 0) {
            (new SectionsRepository())->delete($id);
            FileServer::invalidate_cache();
            Plugin::maybe_write_static_files();
        }

        wp_safe_redirect(admin_url('admin.php?page=wpsearch-sections'));
        exit;
    }
}
