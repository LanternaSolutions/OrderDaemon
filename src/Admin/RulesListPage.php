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
        <div class="rl">

          <!-- Header -->
          <div class="rl__head">
            <div class="rl__head-text">
              <h1 class="rl__title"><?php esc_html_e('admin.list_table.page_title', 'order-daemon'); ?></h1>
              <p class="rl__sub"><?php echo esc_html(number_format_i18n($total)); ?> <?php esc_html_e('admin.list_table.rules_count_label', 'order-daemon'); ?></p>
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
