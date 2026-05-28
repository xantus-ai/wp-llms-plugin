<?php
declare(strict_types=1);

namespace WPLlms\Admin\Pages;

use WPLlms\Frontend\FileServer;
use WPLlms\Plugin;
use WPLlms\Storage\SectionsRepository;

/**
 * Add/edit form for a single section. Single page handles both via ?id= param.
 */
final class SectionEditPage {
    public const PAGE_SLUG = 'wpllms-section-edit';
    public const FORM_ACTION = 'wpllms_section_save';
    public const NONCE_ACTION = 'wpllms_section_save';
    public const NONCE_NAME = 'wpllms_section_save_nonce';

    public static function render(): void {
        $repo = new SectionsRepository();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $section = $id > 0 ? $repo->find($id) : null;
        $is_new = $section === null;

        $defaults = [
            'name' => '',
            'intro_text' => '',
            'is_optional' => 0,
            'sort_order' => 100,
            'rule_type' => 'manual',
            'rule_post_type' => 'post',
            'rule_post_ids' => '',
            'rule_limit' => 50,
            'rule_order_by' => 'date_desc',
        ];

        if ($section !== null) {
            $defaults['name'] = (string) $section['name'];
            $defaults['intro_text'] = (string) ($section['intro_text'] ?? '');
            $defaults['is_optional'] = !empty($section['is_optional']) ? 1 : 0;
            $defaults['sort_order'] = (int) $section['sort_order'];

            $rule = json_decode((string) ($section['inclusion_rule_json'] ?? ''), true);
            if (is_array($rule)) {
                $defaults['rule_type'] = (string) ($rule['type'] ?? 'manual');
                if ($defaults['rule_type'] === 'manual') {
                    $defaults['rule_post_ids'] = implode("\n", array_map('strval', $rule['post_ids'] ?? []));
                } elseif ($defaults['rule_type'] === 'post_type') {
                    $defaults['rule_post_type'] = (string) ($rule['post_type'] ?? 'post');
                    $defaults['rule_limit'] = (int) ($rule['limit'] ?? 50);
                    $defaults['rule_order_by'] = (string) ($rule['order_by'] ?? 'date_desc');
                }
            }
        }

        $errors = self::pop_errors();
        $eligible_types = self::eligible_post_types();

        $selected_posts = [];
        if ($defaults['rule_type'] === 'manual' && $defaults['rule_post_ids'] !== '') {
            $sel_ids = array_filter(array_map('intval', explode("\n", $defaults['rule_post_ids'])));
            if (!empty($sel_ids)) {
                $sel_query = new \WP_Query([
                    'post__in' => $sel_ids,
                    'orderby' => 'post__in',
                    'posts_per_page' => count($sel_ids),
                    'post_status' => 'any',
                    'post_type' => 'any',
                    'no_found_rows' => true,
                ]);
                foreach ($sel_query->posts as $p) {
                    $type_obj = get_post_type_object($p->post_type);
                    $selected_posts[] = [
                        'id' => $p->ID,
                        'title' => $p->post_title ?: '(no title)',
                        'type' => $type_obj ? $type_obj->labels->singular_name : $p->post_type,
                    ];
                }
            }
        }
        $search_nonce = wp_create_nonce('wpllms_post_search');

        ?>
        <style>
        #wpllms-search-results {
            position: absolute;
            z-index: 100;
            background: #fff;
            border: 1px solid #8c8f94;
            box-shadow: 0 2px 8px rgba(0,0,0,.12);
            max-height: 250px;
            overflow-y: auto;
            width: 25em;
            margin-top: -1px;
        }
        .wpllms-sr-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f1;
        }
        .wpllms-sr-item:last-child { border-bottom: none; }
        .wpllms-sr-item:hover { background: #2271b1; color: #fff; }
        .wpllms-sr-item:hover small { color: rgba(255,255,255,.7); }
        .wpllms-sr-msg { padding: 8px 12px; color: #50575e; }
        #wpllms-selected-posts {
            list-style: none;
            padding: 0;
            margin: 8px 0 0;
            max-width: 40em;
        }
        #wpllms-selected-posts li {
            display: flex;
            align-items: center;
            padding: 6px 10px;
            margin-bottom: 4px;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
        }
        .wpllms-sel-title { font-weight: 500; }
        .wpllms-sel-meta {
            color: #50575e;
            font-size: 12px;
            margin-left: 8px;
        }
        .wpllms-sel-remove {
            margin-left: auto;
            color: #b32d2e;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 18px;
            line-height: 1;
            padding: 0 4px;
        }
        .wpllms-sel-remove:hover { color: #a00; }
        </style>
        <div class="wrap">
            <h1><?php echo $is_new ? esc_html__('Add Section', 'wp-llms') : esc_html__('Edit Section', 'wp-llms'); ?></h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr((string) $id); ?>">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="name"><?php esc_html_e('Section name (H2)', 'wp-llms'); ?></label></th>
                        <td>
                            <input type="text" id="name" name="name" value="<?php echo esc_attr((string) $defaults['name']); ?>" class="regular-text" required>
                            <?php if (!empty($errors['name'])) printf('<p class="description" style="color:#b32d2e">%s</p>', esc_html($errors['name'])); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="intro_text"><?php esc_html_e('Intro text', 'wp-llms'); ?></label></th>
                        <td>
                            <textarea id="intro_text" name="intro_text" rows="3" class="large-text"><?php echo esc_textarea((string) $defaults['intro_text']); ?></textarea>
                            <p class="description"><?php esc_html_e('Optional paragraph that appears under the H2 before the link list.', 'wp-llms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Optional flag', 'wp-llms'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_optional" value="1" <?php checked($defaults['is_optional'], 1); ?>>
                                <?php esc_html_e('Render under "## Optional" instead of as a top-level section', 'wp-llms'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sort_order"><?php esc_html_e('Sort order', 'wp-llms'); ?></label></th>
                        <td>
                            <input type="number" id="sort_order" name="sort_order" value="<?php echo esc_attr((string) $defaults['sort_order']); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Lower numbers come first.', 'wp-llms'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Inclusion rule', 'wp-llms'); ?></h2>
                <p><?php esc_html_e('How posts are selected for this section.', 'wp-llms'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="rule_type"><?php esc_html_e('Rule type', 'wp-llms'); ?></label></th>
                        <td>
                            <select id="rule_type" name="rule_type" onchange="document.getElementById('rule-fields').dataset.type = this.value">
                                <option value="manual" <?php selected($defaults['rule_type'], 'manual'); ?>><?php esc_html_e('Manual selection', 'wp-llms'); ?></option>
                                <option value="post_type" <?php selected($defaults['rule_type'], 'post_type'); ?>><?php esc_html_e('All posts of a type', 'wp-llms'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <div id="rule-fields" data-type="<?php echo esc_attr((string) $defaults['rule_type']); ?>">
                    <table class="form-table rule-manual" style="<?php echo $defaults['rule_type'] === 'manual' ? '' : 'display:none'; ?>">
                        <tr>
                            <th scope="row"><label for="wpllms-post-search"><?php esc_html_e('Select posts', 'wp-llms'); ?></label></th>
                            <td>
                                <div id="wpllms-post-picker">
                                    <div style="position:relative; display:inline-block">
                                        <input type="text" id="wpllms-post-search" class="regular-text" placeholder="<?php esc_attr_e('Type to search by title...', 'wp-llms'); ?>" autocomplete="off">
                                        <div id="wpllms-search-results" style="display:none"></div>
                                    </div>
                                    <ul id="wpllms-selected-posts"></ul>
                                    <textarea id="rule_post_ids" name="rule_post_ids" style="display:none"><?php echo esc_textarea((string) $defaults['rule_post_ids']); ?></textarea>
                                </div>
                                <p class="description"><?php esc_html_e('Search for posts by title, then click to add them to this section.', 'wp-llms'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <table class="form-table rule-post_type" style="<?php echo $defaults['rule_type'] === 'post_type' ? '' : 'display:none'; ?>">
                        <tr>
                            <th scope="row"><label for="rule_post_type"><?php esc_html_e('Post type', 'wp-llms'); ?></label></th>
                            <td>
                                <select id="rule_post_type" name="rule_post_type">
                                    <?php foreach ($eligible_types as $type_name => $type_label) : ?>
                                        <option value="<?php echo esc_attr($type_name); ?>" <?php selected($defaults['rule_post_type'], $type_name); ?>>
                                            <?php echo esc_html((string) $type_label); ?> (<?php echo esc_html($type_name); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rule_limit"><?php esc_html_e('Max items', 'wp-llms'); ?></label></th>
                            <td>
                                <input type="number" id="rule_limit" name="rule_limit" value="<?php echo esc_attr((string) $defaults['rule_limit']); ?>" min="1" max="1000" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rule_order_by"><?php esc_html_e('Order', 'wp-llms'); ?></label></th>
                            <td>
                                <select id="rule_order_by" name="rule_order_by">
                                    <option value="date_desc" <?php selected($defaults['rule_order_by'], 'date_desc'); ?>><?php esc_html_e('Newest first', 'wp-llms'); ?></option>
                                    <option value="date_asc" <?php selected($defaults['rule_order_by'], 'date_asc'); ?>><?php esc_html_e('Oldest first', 'wp-llms'); ?></option>
                                    <option value="title_asc" <?php selected($defaults['rule_order_by'], 'title_asc'); ?>><?php esc_html_e('Title A→Z', 'wp-llms'); ?></option>
                                    <option value="modified_desc" <?php selected($defaults['rule_order_by'], 'modified_desc'); ?>><?php esc_html_e('Recently updated first', 'wp-llms'); ?></option>
                                    <option value="menu_order" <?php selected($defaults['rule_order_by'], 'menu_order'); ?>><?php esc_html_e('Menu order', 'wp-llms'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <script>
                (function() {
                    document.getElementById('rule_type').addEventListener('change', function() {
                        document.querySelectorAll('#rule-fields .form-table').forEach(function(t) { t.style.display = 'none'; });
                        var match = document.querySelector('#rule-fields .rule-' + this.value);
                        if (match) match.style.display = '';
                    });

                    var searchInput = document.getElementById('wpllms-post-search');
                    if (!searchInput) return;
                    var resultsBox = document.getElementById('wpllms-search-results');
                    var selectedList = document.getElementById('wpllms-selected-posts');
                    var hiddenField = document.getElementById('rule_post_ids');
                    var nonce = <?php echo wp_json_encode($search_nonce); ?>;
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var selected = <?php echo wp_json_encode($selected_posts); ?>;
                    var lastResults = [];
                    var debounceTimer;

                    function escHtml(str) {
                        var el = document.createElement('span');
                        el.textContent = str;
                        return el.innerHTML;
                    }

                    function syncHidden() {
                        hiddenField.value = selected.map(function(p) { return p.id; }).join("\n");
                    }

                    function renderSelected() {
                        selectedList.innerHTML = '';
                        selected.forEach(function(post, idx) {
                            var li = document.createElement('li');
                            li.innerHTML =
                                '<span class="wpllms-sel-title">' + escHtml(post.title) + '</span>' +
                                '<span class="wpllms-sel-meta">' + escHtml(post.type) + ' #' + post.id + '</span>' +
                                '<button type="button" class="wpllms-sel-remove" data-idx="' + idx + '" title="Remove">&times;</button>';
                            selectedList.appendChild(li);
                        });
                        syncHidden();
                    }

                    function isSelected(id) {
                        return selected.some(function(p) { return p.id === id; });
                    }

                    function doSearch() {
                        var term = searchInput.value.trim();
                        if (term.length < 2) {
                            resultsBox.style.display = 'none';
                            return;
                        }
                        resultsBox.innerHTML = '<div class="wpllms-sr-msg">Searching&hellip;</div>';
                        resultsBox.style.display = 'block';
                        fetch(ajaxUrl + '?action=wpllms_search_posts&nonce=' + encodeURIComponent(nonce) + '&term=' + encodeURIComponent(term))
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data.success || !data.data.length) {
                                    resultsBox.innerHTML = '<div class="wpllms-sr-msg">No results found.</div>';
                                    return;
                                }
                                lastResults = data.data;
                                var html = '';
                                data.data.forEach(function(post) {
                                    if (isSelected(post.id)) return;
                                    html += '<div class="wpllms-sr-item" data-id="' + post.id + '">' +
                                        escHtml(post.title) +
                                        ' <small>(' + escHtml(post.type) + ' #' + post.id + ')</small></div>';
                                });
                                resultsBox.innerHTML = html || '<div class="wpllms-sr-msg">All matches already selected.</div>';
                            })
                            .catch(function() {
                                resultsBox.innerHTML = '<div class="wpllms-sr-msg">Search failed. Please try again.</div>';
                            });
                    }

                    searchInput.addEventListener('input', function() {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(doSearch, 300);
                    });

                    resultsBox.addEventListener('click', function(e) {
                        var item = e.target.closest('.wpllms-sr-item');
                        if (!item) return;
                        var id = parseInt(item.dataset.id, 10);
                        var post = lastResults.find(function(p) { return p.id === id; });
                        if (post && !isSelected(id)) {
                            selected.push({ id: post.id, title: post.title, type: post.type });
                            renderSelected();
                            item.remove();
                            if (!resultsBox.querySelector('.wpllms-sr-item')) {
                                resultsBox.innerHTML = '<div class="wpllms-sr-msg">All matches already selected.</div>';
                            }
                        }
                    });

                    selectedList.addEventListener('click', function(e) {
                        var btn = e.target.closest('.wpllms-sel-remove');
                        if (!btn) return;
                        selected.splice(parseInt(btn.dataset.idx, 10), 1);
                        renderSelected();
                    });

                    document.addEventListener('click', function(e) {
                        if (!e.target.closest('#wpllms-post-picker')) {
                            resultsBox.style.display = 'none';
                        }
                    });

                    searchInput.addEventListener('focus', function() {
                        if (resultsBox.innerHTML && searchInput.value.trim().length >= 2) {
                            resultsBox.style.display = 'block';
                        }
                    });

                    renderSelected();
                })();
                </script>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo $is_new ? esc_html__('Create section', 'wp-llms') : esc_html__('Save changes', 'wp-llms'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wpllms-sections')); ?>" class="button"><?php esc_html_e('Cancel', 'wp-llms'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_post(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'wp-llms'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $repo = new SectionsRepository();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        $errors = [];
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = __('Section name is required.', 'wp-llms');
        }

        if (count($errors) > 0) {
            set_transient('wpllms_section_errors', $errors, 60);
            $back = admin_url('admin.php?page=' . self::PAGE_SLUG);
            if ($id > 0) $back = add_query_arg('id', (string) $id, $back);
            wp_safe_redirect($back);
            exit;
        }

        $rule = self::build_rule_from_post($_POST);

        $data = [
            'name' => $name,
            'intro_text' => sanitize_textarea_field((string) ($_POST['intro_text'] ?? '')),
            'is_optional' => !empty($_POST['is_optional']),
            'sort_order' => (int) ($_POST['sort_order'] ?? 100),
            'inclusion_rule_json' => $rule,
        ];

        if ($id > 0) {
            $repo->update($id, $data);
        } else {
            $data['slug'] = sanitize_title($name) . '-' . wp_generate_password(6, false, false);
            $repo->create($data);
        }

        FileServer::invalidate_cache();
        Plugin::maybe_write_static_files();

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=wpllms-sections')));
        exit;
    }

    public static function handle_search(): void {
        check_ajax_referer('wpllms_post_search', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $term = sanitize_text_field((string) ($_GET['term'] ?? ''));
        if (strlen($term) < 2) {
            wp_send_json_success([]);
        }

        $query = new \WP_Query([
            's' => $term,
            'post_type' => array_keys(self::eligible_post_types()),
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'no_found_rows' => true,
            'orderby' => 'relevance',
        ]);

        $results = [];
        foreach ($query->posts as $post) {
            $type_obj = get_post_type_object($post->post_type);
            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title ?: '(no title)',
                'type' => $type_obj ? $type_obj->labels->singular_name : $post->post_type,
            ];
        }

        wp_send_json_success($results);
    }

    private static function build_rule_from_post(array $post): array {
        $type = sanitize_key((string) ($post['rule_type'] ?? 'manual'));

        if ($type === 'post_type') {
            return [
                'type' => 'post_type',
                'post_type' => sanitize_key((string) ($post['rule_post_type'] ?? 'post')),
                'limit' => max(1, min(1000, (int) ($post['rule_limit'] ?? 50))),
                'order_by' => sanitize_key((string) ($post['rule_order_by'] ?? 'date_desc')),
            ];
        }

        $raw_ids = (string) ($post['rule_post_ids'] ?? '');
        $ids = array_filter(array_map(static fn(string $line): int => (int) trim($line), preg_split('/\r?\n/', $raw_ids) ?: []));

        return [
            'type' => 'manual',
            'post_ids' => array_values($ids),
        ];
    }

    private static function pop_errors(): array {
        $errors = get_transient('wpllms_section_errors');
        delete_transient('wpllms_section_errors');
        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string,string>
     */
    private static function eligible_post_types(): array {
        $excluded = ['attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'];
        $public = get_post_types(['public' => true], 'objects');
        $out = [];
        foreach ($public as $type) {
            if (in_array($type->name, $excluded, true)) continue;
            $out[$type->name] = (string) ($type->labels->singular_name ?? $type->name);
        }
        return $out;
    }
}
