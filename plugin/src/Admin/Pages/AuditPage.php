<?php
declare(strict_types=1);

namespace WPLlms\Admin\Pages;

use WPLlms\Audit\Auditor;
use WPLlms\Audit\IssuesRepository;

final class AuditPage {
    public const FORM_ACTION = 'wpllms_run_audit';
    public const NONCE_ACTION = 'wpllms_run_audit';
    public const NONCE_NAME = 'wpllms_audit_nonce';

    private const PER_PAGE = 50;
    private const ALLOWED_SEVERITIES = ['critical', 'warning', 'info'];

    public static function render(): void {
        $repo = new IssuesRepository();
        $counts = $repo->counts_by_severity();
        $last = get_option('wpllms_last_audit', null);
        $progress = Auditor::get_progress();

        // Filter + paging state from query args.
        $severity_filter = null;
        if (isset($_GET['severity'])) {
            $candidate = (string) $_GET['severity'];
            if (in_array($candidate, self::ALLOWED_SEVERITIES, true)) {
                $severity_filter = $candidate;
            }
        }
        $rule_filter = isset($_GET['rule']) ? sanitize_key((string) $_GET['rule']) : '';
        $rule_filter = $rule_filter !== '' ? $rule_filter : null;
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($paged - 1) * self::PER_PAGE;

        $total_filtered = $repo->unresolved_count_filtered($severity_filter, $rule_filter);
        $issues = $repo->unresolved_filtered($severity_filter, $rule_filter, self::PER_PAGE, $offset);
        $total_pages = (int) max(1, (int) ceil($total_filtered / self::PER_PAGE));
        $distinct_rules = $repo->distinct_unresolved_rules();
        $total_all = array_sum($counts);
        $base_url = admin_url('admin.php?page=wpllms-audit');

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
            $progress_total = max(1, (int) ($progress['total_posts'] ?? 0));
            $done = (int) ($progress['posts_audited'] ?? 0);
            $pct = (int) min(100, round(($done / $progress_total) * 100));
            $phase = (string) ($progress['phase'] ?? 'per_post');
            $phase_label = $phase === 'site_context'
                ? __('Running site-wide rules (duplicate titles, generic H1s)', 'llms-txt')
                : __('Auditing posts', 'llms-txt');
            ?>
            <div class="notice notice-info" style="padding:12px 16px">
                <p><strong><?php esc_html_e('Audit in progress.', 'llms-txt'); ?></strong>
                    <?php
                    /* translators: 1: number audited, 2: total, 3: percent, 4: phase label */
                    printf(esc_html__('%1$d of %2$d posts processed (%3$d%%). Phase: %4$s.', 'llms-txt'),
                        $done, $progress_total, $pct, esc_html($phase_label));
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

        <?php if (!is_array($progress)) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
                <button type="submit" class="button"><?php esc_html_e('Run full-site audit now', 'llms-txt'); ?></button>
            </form>
        <?php endif; ?>

        <?php
        // Severity filter tabs (WP-standard subsubsub list).
        $tabs = [
            ['key' => null, 'label' => __('All', 'llms-txt'), 'count' => $total_all, 'color' => ''],
            ['key' => 'critical', 'label' => __('Critical', 'llms-txt'), 'count' => $counts['critical'], 'color' => '#b32d2e'],
            ['key' => 'warning', 'label' => __('Warning', 'llms-txt'), 'count' => $counts['warning'], 'color' => '#b07d00'],
            ['key' => 'info', 'label' => __('Info', 'llms-txt'), 'count' => $counts['info'], 'color' => '#646970'],
        ];
        ?>
        <ul class="subsubsub" style="margin-top:16px">
            <?php foreach ($tabs as $i => $tab) :
                $is_current = $severity_filter === $tab['key'];
                $url = $tab['key'] === null
                    ? remove_query_arg(['severity', 'rule', 'paged'], $base_url)
                    : add_query_arg(['severity' => $tab['key']], remove_query_arg(['rule', 'paged'], $base_url));
                $style = $tab['color'] !== '' ? 'color:' . $tab['color'] : '';
                ?>
                <li>
                    <a href="<?php echo esc_url($url); ?>"
                       class="<?php echo $is_current ? 'current' : ''; ?>"
                       style="<?php echo esc_attr($style); ?>"
                       <?php echo $is_current ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html($tab['label']); ?>
                        <span class="count">(<?php echo esc_html(number_format_i18n($tab['count'])); ?>)</span>
                    </a><?php echo $i < count($tabs) - 1 ? ' |' : ''; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <br class="clear">

        <?php if (count($distinct_rules) > 0) : ?>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:8px 0 16px">
                <input type="hidden" name="page" value="wpllms-audit">
                <?php if ($severity_filter !== null) : ?>
                    <input type="hidden" name="severity" value="<?php echo esc_attr($severity_filter); ?>">
                <?php endif; ?>
                <label for="wpllms-rule-filter" style="margin-right:4px"><?php esc_html_e('Rule:', 'llms-txt'); ?></label>
                <select name="rule" id="wpllms-rule-filter" onchange="this.form.submit()">
                    <option value=""><?php esc_html_e('All rules', 'llms-txt'); ?></option>
                    <?php foreach ($distinct_rules as $r) : ?>
                        <option value="<?php echo esc_attr($r); ?>" <?php selected($rule_filter, $r); ?>>
                            <?php echo esc_html($r); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="button" style="margin-left:6px"><?php esc_html_e('Apply', 'llms-txt'); ?></button></noscript>
                <?php if ($rule_filter !== null) : ?>
                    <?php $clear_url = remove_query_arg(['rule', 'paged'], add_query_arg([], $base_url)); ?>
                    <?php if ($severity_filter !== null) {
                        $clear_url = add_query_arg('severity', $severity_filter, $clear_url);
                    } ?>
                    <a href="<?php echo esc_url($clear_url); ?>" style="margin-left:8px"><?php esc_html_e('Clear rule filter', 'llms-txt'); ?></a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($total_filtered === 0) : ?>
            <p><?php
                if ($total_all === 0) {
                    esc_html_e('No issues. Run the audit to populate.', 'llms-txt');
                } else {
                    esc_html_e('No issues match the current filter.', 'llms-txt');
                }
            ?></p>
        <?php else : ?>
            <?php self::render_pagination_bar($total_filtered, $total_pages, $paged, $base_url, $severity_filter, $rule_filter, 'top'); ?>

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

            <?php self::render_pagination_bar($total_filtered, $total_pages, $paged, $base_url, $severity_filter, $rule_filter, 'bottom'); ?>
        <?php endif;

        echo '</div>';
    }

    private static function render_pagination_bar(int $total, int $total_pages, int $paged, string $base_url, ?string $severity, ?string $rule, string $which): void {
        $query_args = [];
        if ($severity !== null) $query_args['severity'] = $severity;
        if ($rule !== null) $query_args['rule'] = $rule;

        $links = [];
        if ($total_pages > 1) {
            $base_for_links = add_query_arg(array_merge($query_args, ['paged' => '%#%']), $base_url);
            $rendered = paginate_links([
                'base' => $base_for_links,
                'format' => '',
                'current' => $paged,
                'total' => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'type' => 'array',
            ]);
            if (is_array($rendered)) {
                $links = $rendered;
            }
        }
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <div class="tablenav-pages" style="float:none">
                <span class="displaying-num">
                    <?php
                    /* translators: %s: total filtered issue count */
                    printf(esc_html(_n('%s item', '%s items', $total, 'llms-txt')),
                        esc_html(number_format_i18n($total)));
                    ?>
                </span>
                <?php if (count($links) > 0) : ?>
                    <span class="pagination-links" style="margin-left:12px">
                        <?php echo implode(' ', $links); // paginate_links output is already safe ?>
                    </span>
                <?php endif; ?>
            </div>
            <br class="clear">
        </div>
        <?php
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
