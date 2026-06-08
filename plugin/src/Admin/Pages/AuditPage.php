<?php
declare(strict_types=1);

namespace WPLlms\Admin\Pages;

use WPLlms\Audit\Auditor;
use WPLlms\Audit\IssuesRepository;

final class AuditPage {
    public const FORM_ACTION = 'wpllms_run_audit';
    public const NONCE_ACTION = 'wpllms_run_audit';
    public const NONCE_NAME = 'wpllms_audit_nonce';

    public static function render(): void {
        $repo = new IssuesRepository();
        $counts = $repo->counts_by_severity();
        $last = get_option('wpllms_last_audit', null);
        $issues = $repo->unresolved(200);
        $progress = \WPLlms\Audit\Auditor::get_progress();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Content Audit', 'llms-txt') . '</h1>';

        if (isset($_GET['audit_done'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Audit complete.', 'llms-txt') . '</p></div>';
        }

        if (isset($_GET['audit_chunk'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Audit paused for this request to keep the server responsive. Click Continue to process the next batch.', 'llms-txt') . '</p></div>';
        }

        ?>
        <p>
            <?php esc_html_e('Quality issues we found across your published content.', 'llms-txt'); ?>
            <?php if (is_array($last) && !empty($last['completed_at'])) : ?>
                <em><?php
                    /* translators: 1: timestamp, 2: posts audited count */
                    printf(esc_html__('Last full audit: %1$s (%2$d posts).', 'llms-txt'),
                        esc_html((string) $last['completed_at']),
                        (int) ($last['posts_audited'] ?? 0));
                ?></em>
            <?php endif; ?>
        </p>

        <?php if (is_array($progress)) :
            $total = max(1, (int) ($progress['total_posts'] ?? 0));
            $done = (int) ($progress['posts_audited'] ?? 0);
            $pct = (int) min(100, round(($done / $total) * 100));
            $phase = (string) ($progress['phase'] ?? 'per_post');
            $phase_label = $phase === 'site_context'
                ? __('Running site-wide rules (duplicate titles, generic H1s)', 'llms-txt')
                : __('Auditing posts', 'llms-txt');
            ?>
            <div class="notice notice-info" style="padding:12px 16px">
                <p><strong><?php esc_html_e('Audit in progress.', 'llms-txt'); ?></strong>
                    <?php
                    /* translators: 1: number audited, 2: total, 3: percent */
                    printf(esc_html__('%1$d of %2$d posts processed (%3$d%%). Phase: %4$s.', 'llms-txt'),
                        $done, $total, $pct, esc_html($phase_label));
                    ?>
                </p>
                <p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Continue audit', 'llms-txt'); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px">
                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
                        <input type="hidden" name="cancel" value="1">
                        <button type="submit" class="button"><?php esc_html_e('Cancel audit', 'llms-txt'); ?></button>
                    </form>
                </p>
            </div>
        <?php endif; ?>

        <table class="widefat" style="max-width:600px">
            <thead><tr>
                <th><?php esc_html_e('Severity', 'llms-txt'); ?></th>
                <th><?php esc_html_e('Open issues', 'llms-txt'); ?></th>
            </tr></thead>
            <tbody>
                <tr><td><strong style="color:#b32d2e"><?php esc_html_e('Critical', 'llms-txt'); ?></strong></td><td><?php echo esc_html((string) $counts['critical']); ?></td></tr>
                <tr><td><strong style="color:#b07d00"><?php esc_html_e('Warning', 'llms-txt'); ?></strong></td><td><?php echo esc_html((string) $counts['warning']); ?></td></tr>
                <tr><td><?php esc_html_e('Info', 'llms-txt'); ?></td><td><?php echo esc_html((string) $counts['info']); ?></td></tr>
            </tbody>
        </table>

        <?php if (!is_array($progress)) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
                <button type="submit" class="button"><?php esc_html_e('Run full-site audit now', 'llms-txt'); ?></button>
            </form>
        <?php endif; ?>

        <h2><?php esc_html_e('Open issues', 'llms-txt'); ?></h2>
        <?php if (count($issues) === 0) : ?>
            <p><?php esc_html_e('No issues. Run the audit to populate.', 'llms-txt'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Severity', 'llms-txt'); ?></th>
                    <th><?php esc_html_e('Rule', 'llms-txt'); ?></th>
                    <th><?php esc_html_e('Post', 'llms-txt'); ?></th>
                    <th><?php esc_html_e('Message', 'llms-txt'); ?></th>
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
            wp_die(esc_html__('Insufficient permissions.', 'llms-txt'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (!empty($_POST['cancel'])) {
            Auditor::clear_progress();
            wp_safe_redirect(admin_url('admin.php?page=wpllms-audit'));
            exit;
        }

        $is_complete = false;
        try {
            $result = (new Auditor())->audit_all();
            $is_complete = (bool) ($result['is_complete'] ?? false);
            // Note: wpllms_last_audit is now written by Auditor::audit_all when
            // the run completes, not here. Avoids overwriting it mid-chunk.
        } catch (\Throwable $e) {
            // Swallow; user will see the empty state or the progress row.
        }

        $arg = $is_complete ? 'audit_done' : 'audit_chunk';
        wp_safe_redirect(add_query_arg($arg, '1', admin_url('admin.php?page=wpllms-audit')));
        exit;
    }
}
