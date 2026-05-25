<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

/**
 * Custom admin page for listing order rules with the new design system UI.
 */
class RulesListPage
{
    private const PAGE_SLUG = 'odcm-rules-list';

    public function init(): void
    {
        add_action('admin_post_odcm_delete_rule', [$this, 'handle_delete']);
        add_action('admin_post_odcm_bulk_rules',  [$this, 'handle_bulk']);
        add_action('admin_enqueue_scripts',        [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'order-daemon_page_' . self::PAGE_SLUG) {
            return;
        }

        $version    = defined('ODCM_VERSION') ? ODCM_VERSION : '1.0.0';
        $assets_url = defined('ODCM_PLUGIN_URL') ? ODCM_PLUGIN_URL . 'assets/' : '';
        $ds_path    = defined('ODCM_PLUGIN_DIR') ? ODCM_PLUGIN_DIR . 'assets/css/odcm-design-system.css' : '';
        $ds_version = file_exists($ds_path) ? filemtime($ds_path) : $version;

        wp_enqueue_style('odcm-design-system', $assets_url . 'css/odcm-design-system.css', [], $ds_version);
        wp_enqueue_style('odcm-admin-styles',   $assets_url . 'css/admin.css', ['odcm-design-system'], $version);
    }

    public function render(): void
    {
        global $wpdb;

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('security.no_action_permission', 'order-daemon'));
        }

        $search         = isset($_GET['s'])      ? sanitize_text_field(wp_unslash($_GET['s']))      : '';
        $status_filter  = isset($_GET['status'])  ? sanitize_key(wp_unslash($_GET['status']))         : '';
        $trigger_filter = isset($_GET['trigger']) ? sanitize_text_field(wp_unslash($_GET['trigger'])) : '';
        $paged          = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page       = 20;

        $query_args = [
            'post_type'      => 'odcm_order_rule',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ];

        if ($search !== '') {
            $query_args['s'] = $search;
        }

        if ($status_filter === 'active') {
            $query_args['post_status'] = ['publish'];
        } elseif ($status_filter === 'inactive') {
            $query_args['post_status'] = ['draft'];
        }

        $query = new \WP_Query($query_args);
        $rules = $query->posts;
        $total = $query->found_posts;

        // Collect rule IDs for batch execution stats query
        $rule_ids = wp_list_pluck($rules, 'ID');

        $stats_by_rule = $this->get_execution_stats($rule_ids);

        // Collect distinct trigger IDs for filter dropdown
        $all_trigger_ids = $this->get_all_trigger_ids();

        $add_new_url  = admin_url('post-new.php?post_type=odcm_order_rule');
        $page_url     = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $bulk_nonce   = wp_create_nonce('odcm_bulk_rules');
        ?>
        <div class="odcm-rules-page odcm-scope">

        <!-- Unified brand header -->
        <div class="odcm-unified-header">
          <div class="odcm-unified-header__brand">
            <svg width="22" height="22" viewBox="0 0 128 128" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" clip-rule="evenodd" d="M115.575 38.0075C116.022 37.9874 116.467 38.0076 116.911 38.0682C117.074 38.4076 117.013 38.7113 116.728 38.9793C110.372 46.5109 104.341 54.2856 98.6351 62.3033C96.4699 65.5014 94.365 68.7407 92.3207 72.0217C91.9153 72.364 91.4499 72.4652 90.9242 72.3254C90.2886 71.689 89.7422 70.9803 89.2849 70.1995C86.957 66.4332 84.6903 62.6269 82.4847 58.7804C82.0502 57.6476 82.4348 57.2225 83.6383 57.5049C84.8632 58.3251 85.9359 59.3171 86.8562 60.4811C88.0448 62.0349 89.2794 63.5533 90.5599 65.0366C90.8192 65.1137 91.062 65.0732 91.2885 64.9151C93.8523 61.257 96.4631 57.6329 99.1209 54.0427C102.193 50.037 105.492 46.2104 109.018 42.563C110.486 41.1616 112.064 39.9062 113.753 38.7971C114.358 38.4955 114.965 38.2322 115.575 38.0075Z"/>
              <path fill-rule="evenodd" clip-rule="evenodd" d="M35.5513 43.3525C42.0969 42.9637 47.8042 44.9275 52.6732 49.2443C55.272 51.6805 57.5994 54.3328 59.6555 57.2011C59.9332 57.5996 60.1558 58.0248 60.3234 58.4767C59.7453 59.6333 59.0167 60.6861 58.1376 61.6351C57.9623 61.73 57.8004 61.7097 57.6519 61.5744C55.3649 57.9691 52.5719 54.7904 49.2731 52.0383C45.2311 48.7875 40.6167 47.3702 35.4299 47.7865C28.6765 48.9939 24.2036 52.8611 22.0117 59.3878C20.1255 67.2545 22.4934 73.4702 29.1154 78.0348C36.2941 81.8873 42.9729 81.1584 49.1517 75.8482C51.201 73.9197 53.1236 71.8747 54.9197 69.7135C57.8808 65.82 60.7952 61.8921 63.6628 57.93C66.5835 53.9957 69.9633 50.4931 73.8023 47.4221C79.2353 43.7409 85.1045 42.85 91.41 44.7495C93.6407 45.4694 95.5633 46.6639 97.178 48.3332C97.8828 49.5333 97.5387 50.0395 96.1458 49.8517C88.3096 45.9676 81.0641 46.818 74.4095 52.4027C71.581 55.1104 68.9702 58.0056 66.5771 61.0885C63.2644 65.6579 59.9047 70.1931 56.4983 74.6941C53.4913 78.5538 49.7472 81.449 45.2659 83.3799C36.2004 86.3776 28.4086 84.4137 21.8902 77.4882C17.7008 72.3713 16.2032 66.5403 17.3973 59.9952C19.2203 52.0575 23.9764 46.7731 31.6655 44.1421C32.9642 43.8115 34.2594 43.5482 35.5513 43.3525Z"/>
              <path fill-rule="evenodd" clip-rule="evenodd" d="M103.31 62.6677C103.51 62.6417 103.692 62.6823 103.857 62.7892C104.012 63.0585 104.133 63.3421 104.221 63.6396C104.425 70.9849 101.692 76.9577 96.0244 81.5578C89.3454 85.7966 82.4643 86.1206 75.381 82.5296C70.5663 79.657 66.6198 75.8708 63.5414 71.1713C63.4421 70.7505 63.4827 70.3455 63.6628 69.9565C63.8724 69.8258 64.095 69.8055 64.3307 69.8958C67.5089 73.4036 71.1114 76.3595 75.1381 78.7637C79.4853 81.2028 84.0998 81.9317 88.9813 80.9504C93.9758 79.5176 97.7605 76.5414 100.335 72.0216C101.837 69.3362 102.728 66.4613 103.007 63.3966C103.076 63.1365 103.177 62.8936 103.31 62.6677Z"/>
            </svg>
            <span class="odcm-unified-header__title">Order Daemon</span>
          </div>
          <span class="odcm-unified-header__sep" aria-hidden="true">/</span>
          <span class="odcm-unified-header__crumb"><?php esc_html_e('admin.list_table.page_title', 'order-daemon'); ?></span>
        </div>

        <div class="rl odcm-page-body">

          <!-- Header -->
          <div class="rl__head">
            <div class="rl__head-text">
              <h1 class="rl__title odcm-page-title"><?php esc_html_e('admin.list_table.page_title', 'order-daemon'); ?></h1>
              <p class="rl__sub odcm-page-sub"><?php echo esc_html(number_format_i18n($total)); ?> <?php esc_html_e('admin.list_table.rules_count_label', 'order-daemon'); ?></p>
            </div>
            <div class="rl__head-actions">
              <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=odcm_export_rules'), 'odcm_export_rules')); ?>" class="odcm-btn odcm-btn--ghost odcm-btn--sm">
                <?php esc_html_e('admin.list_table.action.export', 'order-daemon'); ?>
              </a>
              <a href="<?php echo esc_url($add_new_url); ?>" class="odcm-btn odcm-btn--primary odcm-btn--sm">
                + <?php esc_html_e('admin.list_table.action.add_rule', 'order-daemon'); ?>
              </a>
            </div>
          </div>

          <!-- Filters -->
          <form method="get" action="<?php echo esc_url($page_url); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <div class="rl__filters">
              <input class="odcm-input" name="s" value="<?php echo esc_attr($search); ?>"
                     placeholder="<?php esc_attr_e('admin.list_table.search_placeholder', 'order-daemon'); ?>"
                     style="flex: 1; min-width: 200px;" />
              <select class="odcm-select" name="status" style="width: 140px;"
                      onchange="this.form.submit()">
                <option value=""><?php esc_html_e('admin.list_table.filter.all_statuses', 'order-daemon'); ?></option>
                <option value="active"  <?php selected($status_filter, 'active'); ?>><?php esc_html_e('admin.ui.active', 'order-daemon'); ?></option>
                <option value="inactive" <?php selected($status_filter, 'inactive'); ?>><?php esc_html_e('admin.ui.inactive', 'order-daemon'); ?></option>
              </select>
              <?php if (!empty($all_trigger_ids)) : ?>
              <select class="odcm-select" name="trigger" style="width: 160px;"
                      onchange="this.form.submit()">
                <option value=""><?php esc_html_e('admin.list_table.filter.all_triggers', 'order-daemon'); ?></option>
                <?php foreach ($all_trigger_ids as $tid) : ?>
                  <option value="<?php echo esc_attr($tid); ?>" <?php selected($trigger_filter, $tid); ?>><?php echo esc_html($tid); ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
              <button type="submit" class="odcm-btn odcm-btn--ghost odcm-btn--sm">
                <?php esc_html_e('admin.ui.search', 'order-daemon'); ?>
              </button>
            </div>
          </form>

          <!-- Bulk action form -->
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="odcm_bulk_rules" />
            <?php wp_nonce_field('odcm_bulk_rules', '_wpnonce'); ?>

            <!-- Table -->
            <div class="rl__table">
              <!-- Header row -->
              <div class="rl__th">
                <span><input type="checkbox" id="odcm-select-all" /></span>
                <span><?php esc_html_e('admin.list_table.column.title', 'order-daemon'); ?></span>
                <span><?php esc_html_e('admin.list_table.column.active', 'order-daemon'); ?></span>
                <span><?php esc_html_e('admin.list_table.column.trigger', 'order-daemon'); ?></span>
                <span><?php esc_html_e('admin.list_table.column.last_fired', 'order-daemon'); ?></span>
                <span style="text-align:right;"><?php esc_html_e('admin.list_table.column.executed', 'order-daemon'); ?></span>
                <span></span>
              </div>

              <?php if (empty($rules)) : ?>
                <div style="padding: 24px 16px; color: var(--odcm-muted); font-size: var(--odcm-text-sm);">
                  <?php esc_html_e('admin.list_table.empty.no_rules_found', 'order-daemon'); ?>
                </div>
              <?php else : ?>
                <?php foreach ($rules as $rule) :
                    $rule_data   = json_decode((string) get_post_meta($rule->ID, '_odcm_rule_data', true), true);
                    $trigger_id  = $rule_data['trigger']['id'] ?? '';
                    $is_active   = $rule->post_status === 'publish';
                    $edit_url    = admin_url('post.php?post=' . absint($rule->ID) . '&action=edit');
                    $delete_url  = wp_nonce_url(
                        add_query_arg(['action' => 'odcm_delete_rule', 'rule_id' => $rule->ID], admin_url('admin-post.php')),
                        'odcm_delete_rule_' . $rule->ID
                    );
                    $rule_stats  = $stats_by_rule[$rule->ID] ?? ['count' => 0, 'last_fired' => null];

                    if ($is_active) {
                        $pill_class = 'odcm-pill odcm-pill--success';
                        $status_label = __('admin.ui.active', 'order-daemon');
                    } elseif ($rule->post_status === 'draft') {
                        $pill_class = 'odcm-pill';
                        $status_label = __('admin.ui.inactive', 'order-daemon');
                    } else {
                        $pill_class = 'odcm-pill';
                        $status_label = __('admin.ui.draft', 'order-daemon');
                    }

                    $last_fired_display = $rule_stats['last_fired']
                        ? date_i18n('j M, H:i:s', strtotime($rule_stats['last_fired']))
                        : __('admin.list_table.never', 'order-daemon');
                    $exec_count = number_format_i18n($rule_stats['count']);

                    // Apply trigger filter client-side (already filtered server-side if meta query possible)
                    if ($trigger_filter !== '' && $trigger_id !== $trigger_filter) {
                        continue;
                    }
                ?>
                <div class="rl__tr">
                  <input type="checkbox" name="rule[]" value="<?php echo esc_attr($rule->ID); ?>"
                         style="accent-color: var(--odcm-accent);" />
                  <span class="rl__name">
                    <a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($rule->post_title); ?></a>
                  </span>
                  <span class="<?php echo esc_attr($pill_class); ?>">
                    <span class="odcm-pill__dot"></span><?php echo esc_html($status_label); ?>
                  </span>
                  <div class="rl__meta">
                    <span class="rl__trigger">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
                      <span><?php echo esc_html($trigger_id ?: '—'); ?></span>
                    </span>
                    <span class="rl__last">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>
                      <span><?php echo esc_html($last_fired_display); ?></span>
                    </span>
                    <span class="rl__exec">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h12"/><path d="M15 6l6 6-6 6"/></svg>
                      <span><?php echo esc_html($exec_count); ?> <?php esc_html_e('admin.list_table.fires_label', 'order-daemon'); ?></span>
                    </span>
                  </div>
                  <span class="rl__actions">
                    <a href="<?php echo esc_url($edit_url); ?>" class="odcm-btn odcm-btn--ghost odcm-btn--sm">
                      <?php esc_html_e('admin.ui.edit', 'order-daemon'); ?>
                    </a>
                    <a href="<?php echo esc_url($delete_url); ?>"
                       class="odcm-btn odcm-btn--ghost odcm-btn--sm"
                       style="color: var(--odcm-red);"
                       onclick="return confirm('<?php echo esc_js(__('admin.list_table.confirm.delete_rule', 'order-daemon')); ?>')">
                      <?php esc_html_e('admin.ui.delete', 'order-daemon'); ?>
                    </a>
                  </span>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div><!-- /rl__table -->

            <!-- Bulk actions bar -->
            <?php if (!empty($rules)) : ?>
            <div style="display:flex; gap:8px; align-items:center; margin-top:12px; flex-wrap:wrap;">
              <select name="bulk_action" class="odcm-select" style="width:auto;">
                <option value=""><?php esc_html_e('admin.list_table.bulk.choose_action', 'order-daemon'); ?></option>
                <option value="activate"><?php esc_html_e('admin.list_table.action.activate', 'order-daemon'); ?></option>
                <option value="deactivate"><?php esc_html_e('admin.list_table.action.deactivate', 'order-daemon'); ?></option>
                <option value="trash"><?php esc_html_e('admin.list_table.action.move_to_trash', 'order-daemon'); ?></option>
              </select>
              <button type="submit" class="odcm-btn odcm-btn--ghost odcm-btn--sm">
                <?php esc_html_e('admin.ui.apply', 'order-daemon'); ?>
              </button>
              <?php if ($total > $per_page) : ?>
                <span style="margin-left:auto; font-size:var(--odcm-text-xs); color:var(--odcm-muted);">
                  <?php
                  $start = ($paged - 1) * $per_page + 1;
                  $end   = min($paged * $per_page, $total);
                  echo esc_html(sprintf('%d–%d of %d', $start, $end, $total));
                  ?>
                </span>
                <?php if ($paged > 1) : ?>
                  <a href="<?php echo esc_url(add_query_arg('paged', $paged - 1, $page_url)); ?>"
                     class="odcm-btn odcm-btn--ghost odcm-btn--sm">‹</a>
                <?php endif; ?>
                <?php if ($paged * $per_page < $total) : ?>
                  <a href="<?php echo esc_url(add_query_arg('paged', $paged + 1, $page_url)); ?>"
                     class="odcm-btn odcm-btn--ghost odcm-btn--sm">›</a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          </form>

        </div><!-- /rl -->
        </div><!-- /odcm-rules-page -->

        <script>
        (function() {
          var selectAll = document.getElementById('odcm-select-all');
          if (!selectAll) return;
          selectAll.addEventListener('change', function() {
            document.querySelectorAll('.rl__tr input[type="checkbox"]').forEach(function(cb) {
              cb.checked = selectAll.checked;
            });
          });
        })();
        </script>
        <?php
    }

    public function handle_delete(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('security.no_action_permission', 'order-daemon'));
        }

        $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
        if (!$rule_id || get_post_type($rule_id) !== 'odcm_order_rule') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
            exit;
        }

        check_admin_referer('odcm_delete_rule_' . $rule_id);
        wp_trash_post($rule_id);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function handle_bulk(): void
    {
        check_admin_referer('odcm_bulk_rules', '_wpnonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('security.no_action_permission', 'order-daemon'));
        }

        $bulk_action = isset($_POST['bulk_action']) ? sanitize_key($_POST['bulk_action']) : '';
        $rule_ids    = isset($_POST['rule']) ? array_map('absint', (array) $_POST['rule']) : [];

        if ($bulk_action && !empty($rule_ids)) {
            foreach ($rule_ids as $rule_id) {
                if (get_post_type($rule_id) !== 'odcm_order_rule') {
                    continue;
                }
                switch ($bulk_action) {
                    case 'activate':
                        wp_update_post(['ID' => $rule_id, 'post_status' => 'publish']);
                        break;
                    case 'deactivate':
                        wp_update_post(['ID' => $rule_id, 'post_status' => 'draft']);
                        break;
                    case 'trash':
                        wp_trash_post($rule_id);
                        break;
                }
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * Get execution count and last fired timestamp per rule from the audit log.
     *
     * @param int[] $rule_ids
     * @return array<int, array{count: int, last_fired: string|null}>
     */
    private function get_execution_stats(array $rule_ids): array
    {
        global $wpdb;

        if (empty($rule_ids)) {
            return [];
        }

        $table = esc_sql($wpdb->prefix . 'odcm_audit_log');
        $placeholders = implode(',', array_fill(0, count($rule_ids), '%s'));

        // Execution events store rule_id in the details JSON field.
        // Build per-rule stats in a single query.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT
                JSON_UNQUOTE(JSON_EXTRACT(details, '$.rule_id')) AS rule_id,
                COUNT(*)                                          AS exec_count,
                MAX(timestamp)                                    AS last_fired
             FROM `{$table}`
             WHERE event_type = 'rule_execution'
               AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.rule_id')) IN ({$placeholders})
             GROUP BY JSON_UNQUOTE(JSON_EXTRACT(details, '$.rule_id'))",
            array_map('strval', $rule_ids)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($sql);

        $stats = [];
        foreach ((array) $rows as $row) {
            $stats[(int) $row->rule_id] = [
                'count'      => (int) $row->exec_count,
                'last_fired' => $row->last_fired,
            ];
        }

        // Fill missing rule IDs with zero stats
        foreach ($rule_ids as $id) {
            if (!isset($stats[$id])) {
                $stats[$id] = ['count' => 0, 'last_fired' => null];
            }
        }

        return $stats;
    }

    /**
     * Get all distinct trigger IDs from published/draft rules.
     *
     * @return string[]
     */
    private function get_all_trigger_ids(): array
    {
        $rules = get_posts([
            'post_type'      => 'odcm_order_rule',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $trigger_ids = [];
        foreach ($rules as $rule_id) {
            $data = json_decode((string) get_post_meta($rule_id, '_odcm_rule_data', true), true);
            $tid  = $data['trigger']['id'] ?? '';
            if ($tid !== '' && !in_array($tid, $trigger_ids, true)) {
                $trigger_ids[] = $tid;
            }
        }

        sort($trigger_ids);
        return $trigger_ids;
    }
}
