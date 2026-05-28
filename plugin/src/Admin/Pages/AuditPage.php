<?php
declare(strict_types=1);

namespace WPSearch\Admin\Pages;

use WPSearch\Audit\Auditor;
use WPSearch\Audit\IssuesRepository;

final class AuditPage {
    public const FORM_ACTION = 'wpsearch_run_audit';
    public const NONCE_ACTION = 'wpsearch_run_audit';
    public const NONCE_NAME = 'wpsearch_audit_nonce';

    public static function render(): void {
        $repo = new IssuesRepository();
        $counts = $repo->counts_by_severity();
        $last = get_option('wpsearch_last_audit', null);
        $issues = $repo->unresolved(200);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Content Audit', 'wpsearch-ai') . '</h1>';

        if (isset($_GET['audit_done'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Audit complete.', 'wpsearch-ai') . '</p></div>';
        }

        ?>
        <p>
            <?php esc_html_e('Quality issues we found across your published content.', 'wpsearch-ai'); ?>
            <?php if (is_array($last) && !empty($last['completed_at'])) : ?>
                <em><?php
                    /* translators: 1: timestamp, 2: posts audited count */
                    printf(esc_html__('Last full audit: %1$s (%2$d posts).', 'wpsearch-ai'),
                        esc_html((string) $last['completed_at']),
                        (int) ($last['posts_audited'] ?? 0));
                ?></em>
            <?php endif; ?>
        </p>

        <table class="widefat" style="max-width:600px">
            <thead><tr>
                <th><?php esc_html_e('Severity', 'wpsearch-ai'); ?></th>
                <th><?php esc_html_e('Open issues', 'wpsearch-ai'); ?></th>
            </tr></thead>
            <tbody>
                <tr><td><strong style="color:#b32d2e"><?php esc_html_e('Critical', 'wpsearch-ai'); ?></strong></td><td><?php echo esc_html((string) $counts['critical']); ?></td></tr>
                <tr><td><strong style="color:#b07d00"><?php esc_html_e('Warning', 'wpsearch-ai'); ?></strong></td><td><?php echo esc_html((string) $counts['warning']); ?></td></tr>
                <tr><td><?php esc_html_e('Info', 'wpsearch-ai'); ?></td><td><?php echo esc_html((string) $counts['info']); ?></td></tr>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
            <button type="submit" class="button"><?php esc_html_e('Run full-site audit now', 'wpsearch-ai'); ?></button>
        </form>

        <h2><?php esc_html_e('Open issues', 'wpsearch-ai'); ?></h2>
        <?php if (count($issues) === 0) : ?>
            <p><?php esc_html_e('No issues. Run the audit to populate.', 'wpsearch-ai'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Severity', 'wpsearch-ai'); ?></th>
                    <th><?php esc_html_e('Rule', 'wpsearch-ai'); ?></th>
                    <th><?php esc_html_e('Post', 'wpsearch-ai'); ?></th>
                    <th><?php esc_html_e('Message', 'wpsearch-ai'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($issues as $issue) :
                        $post = get_post((int) $issue['post_id']);
                        $title = $post ? get_the_title($post) : '(deleted)';
                        $edit = $post ? get_edit_post_link($post) : null;
                        $color = match ($issue['severity']) {
                            'critical' => '#b32d2e',
                            'warning' => '#b07d00',
                            default => '#646970',
                        };
                        ?>
                        <tr>
                            <td><span style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html((string) $issue['severity']); ?></span></td>
                            <td><code><?php echo esc_html((string) $issue['rule']); ?></code></td>
                            <td>
                                <?php if ($edit) : ?>
                                    <a href="<?php echo esc_url((string) $edit); ?>" target="_blank"><?php echo esc_html((string) $title); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html((string) $title); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html((string) $issue['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;

        echo '</div>';
    }

    public static function handle_run(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'wpsearch-ai'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        try {
            $result = (new Auditor())->audit_all();
            update_option('wpsearch_last_audit', [
                'completed_at' => current_time('mysql', true),
                'issues_found' => $result['issues_found'],
                'posts_audited' => $result['posts_audited'],
            ], false);
        } catch (\Throwable $e) {
            // Swallow; user will see the empty state.
        }

        wp_safe_redirect(add_query_arg('audit_done', '1', admin_url('admin.php?page=wpsearch-audit')));
        exit;
    }
}
