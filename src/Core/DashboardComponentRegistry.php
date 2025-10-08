<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use OrderDaemon\CompletionManager\Includes\Odcm_Config;

/**
 * Dashboard Component Registry
 *
 * Central metadata registry for Insight Dashboard components. Mirrors the
 * concept used by PayloadComponentRegistry but simplified for admin UI.
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   2.1.0
 */
class DashboardComponentRegistry
{
    /**
     * Get all dashboard component types metadata.
     *
     * @return array<string, array{label:string, css_class?:string, priority?:int}>
     */
    public static function get_component_types(): array
    {
        // Note: Keep minimal to avoid unnecessary complexity. This can be extended later.
        return [
            'unified_header' => [
                'label' => __('Unified Header', Odcm_Config::$text_domain),
                'css_class' => 'odcm-unified-header',
                'priority' => 5,
            ],
            'filter_pane' => [
                'label' => __('Filter Pane', Odcm_Config::$text_domain),
                'css_class' => 'odcm-filter-pane',
                'priority' => 10,
            ],
            'log_stream' => [
                'label' => __('Log Stream', Odcm_Config::$text_domain),
                'css_class' => 'odcm-log-stream',
                'priority' => 20,
            ],
            'detail_pane' => [
                'label' => __('Detail Pane', Odcm_Config::$text_domain),
                'css_class' => 'odcm-detail-pane',
                'priority' => 30,
            ],
            'filters_tab' => [
                'label' => __('Filters Tab', Odcm_Config::$text_domain),
                'css_class' => 'odcm-filters-tab',
                'priority' => 12,
            ],
            'settings_tab' => [
                'label' => __('Settings Tab', Odcm_Config::$text_domain),
                'css_class' => 'odcm-settings-tab',
                'priority' => 13,
            ],
            'welcome_state' => [
                'label' => __('Welcome State', Odcm_Config::$text_domain),
                'css_class' => 'odcm-welcome-state',
                'priority' => 1,
            ],
            'empty_state' => [
                'label' => __('Empty State', Odcm_Config::$text_domain),
                'css_class' => 'odcm-empty-state',
                'priority' => 1,
            ],
            'pagination' => [
                'label' => __('Pagination', Odcm_Config::$text_domain),
                'css_class' => 'odcm-pagination',
                'priority' => 100,
            ],
        ];
    }

    /**
     * Get metadata for a single component ID.
     *
     * @param string $component_id
     * @return array{label:string, css_class?:string, priority?:int}
     */
    public static function get_component_metadata(string $component_id): array
    {
        $types = self::get_component_types();
        if (isset($types[$component_id])) {
            return $types[$component_id];
        }
        // Safe defaults
        return [
            'label' => $component_id,
            'css_class' => 'odcm-dashboard-component',
            'priority' => 10,
        ];
    }
}
