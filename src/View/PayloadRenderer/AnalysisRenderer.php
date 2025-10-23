<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Analysis Renderer
 *
 * Handles rendering of all analysis-related events:
 * - refund_analysis
 * - woocommerce_analysis
 * - dedup
 *
 * These events contain rich data structures that need detailed presentation
 * with expandable sections for deep analysis.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class AnalysisRenderer extends BaseRenderer
{
    /**
     * Constructor
     *
     * Sets the default theme as 'system'. This will be overridden in renderContent
     * based on the specific event type.
     */
    public function __construct()
    {
        parent::__construct();
        $this->theme = 'system';
    }

    /**
     * Render Content
     *
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    protected function renderContent(array $data, string $event_type): string
    {
        $toolkit = new PayloadComponentUIToolkit();

        // Set theme based on event type
        switch ($event_type) {
            case 'refund_analysis':
                $this->theme = 'payment';
                return $this->renderRefundAnalysis($data, $toolkit);

            case 'woocommerce_analysis':
                $this->theme = 'woocommerce';
                return $this->renderWooCommerceAnalysis($data, $toolkit);

            case 'dedup':
            default:
                $this->theme = 'system';
                return $this->renderDedupAnalysis($data, $toolkit);
        }
    }

    /**
     * Get Label
     *
     * Provides event-specific labels based on event type and data.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $data, string $event_type): string
    {
        switch ($event_type) {
            case 'refund_analysis':
                return isset($data['refund_id'])
                    ? "Refund Analysis #" . $data['refund_id']
                    : 'Refund Analysis';

            case 'woocommerce_analysis':
                return isset($data['order_id'])
                    ? "Order Impact #" . $data['order_id']
                    : 'Order Analysis';

            case 'dedup':
                return 'Deduplication Analysis';

            default:
                return parent::getLabel($data, $event_type);
        }
    }

    /**
     * Get Status Pill
     *
     * Provides event-specific status pills based on event type and outcome.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
        switch ($event_type) {
            case 'refund_analysis':
                return ['label' => 'ANALYSIS', 'type' => 'warning'];

            case 'woocommerce_analysis':
                return ['label' => 'IMPACT', 'type' => 'woocommerce'];

            case 'dedup':
                return ['label' => 'DEDUP', 'type' => 'info'];

            default:
                return null;
        }
    }

    /**
     * Get Theme
     *
     * Analysis events use appropriate themes based on their context.
     *
     * @param string $event_type The type of event
     * @return string Theme identifier
     */
    /**
     * Render Refund Analysis
     *
     * Renders detailed refund analysis with impact assessment.
     *
     * @param array                    $data    The refund analysis data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderRefundAnalysis(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Core refund information
        $refund_data = [
            'Refund ID' => isset($data['refund_id']) ? '#' . $data['refund_id'] : '',
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Amount' => isset($data['amount'], $data['currency'])
                ? $this->formatCurrency($data['amount'], $data['currency'])
                : '',
            'Type' => isset($data['refund_type']) ? ucfirst($data['refund_type']) : '',
        ];

        // Add percentage if available
        if (isset($data['percentage'])) {
            $refund_data['Percentage'] = $data['percentage'] . '%';
        }

        $content = $toolkit->render_key_value_list($refund_data, 'Refund Analysis');

        // Add refund details in expandable section
        if (!empty($data['refund_details'])) {
            $details_json = json_encode($data['refund_details'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($details_json, 'json');
            $content .= $toolkit->render_expandable_section('Refund Details', $code_block);
        }

        // Add order impact in expandable section
        if (!empty($data['order_impact'])) {
            $impact_json = json_encode($data['order_impact'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($impact_json, 'json');
            $content .= $toolkit->render_expandable_section('Order Impact', $code_block);
        }

        return $content;
    }

    /**
     * Render WooCommerce Analysis
     *
     * Renders WooCommerce-specific analysis with order impact.
     *
     * @param array                    $data    The WooCommerce analysis data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderWooCommerceAnalysis(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Core order information
        $order_data = [
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Status' => ucfirst($data['status'] ?? ''),
            'Total' => isset($data['total'], $data['currency'])
                ? $this->formatCurrency($data['total'], $data['currency'])
                : '',
        ];

        $content = $toolkit->render_key_value_list($order_data, 'Order Analysis');

        // Add order items in expandable section
        if (!empty($data['items'])) {
            $items_json = json_encode($data['items'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($items_json, 'json');
            $content .= $toolkit->render_expandable_section('Order Items', $code_block);
        }

        // Add order changes in expandable section
        if (!empty($data['changes'])) {
            $changes_json = json_encode($data['changes'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($changes_json, 'json');
            $content .= $toolkit->render_expandable_section('Order Changes', $code_block);
        }

        // Add impact analysis in expandable section
        if (!empty($data['impact'])) {
            $impact_json = json_encode($data['impact'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($impact_json, 'json');
            $content .= $toolkit->render_expandable_section('Impact Analysis', $code_block);
        }

        return $content;
    }

    /**
     * Render Dedup Analysis
     *
     * Renders deduplication analysis results.
     *
     * @param array                    $data    The dedup analysis data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderDedupAnalysis(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Core dedup information
        $dedup_data = [
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Status' => ucfirst($data['status'] ?? ''),
            'Hook' => $data['hook'] ?? '',
            'Specific Hook' => isset($data['specific_hook']) ? ($data['specific_hook'] ? 'Yes' : 'No') : '',
        ];

        $content = $toolkit->render_key_value_list($dedup_data, 'Deduplication Analysis');

        // Add check results in expandable section
        if (!empty($data['check_results'])) {
            $results_json = json_encode($data['check_results'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($results_json, 'json');
            $content .= $toolkit->render_expandable_section('Check Results', $code_block);
        }

        // Add historical data in expandable section
        if (!empty($data['history'])) {
            $history_json = json_encode($data['history'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($history_json, 'json');
            $content .= $toolkit->render_expandable_section('Historical Data', $code_block);
        }

        return $content;
    }

    /**
     * Render Generic Analysis
     *
     * Fallback renderer for unrecognized analysis events.
     *
     * @param array                    $data    The analysis data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderGenericAnalysis(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        return $toolkit->render_code_block(
            json_encode($data, JSON_PRETTY_PRINT),
            'json'
        );
    }
}
