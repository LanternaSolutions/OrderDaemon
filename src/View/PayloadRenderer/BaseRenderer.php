<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Base Renderer Class
 *
 * Clean, simple base class implementing Template Method pattern for payload rendering.
 * Provides shared functionality while allowing specialized renderers to focus on their
 * specific event types.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class BaseRenderer
{
    /**
     * Component theme
     *
     * @var string|null
     */
    protected $theme = null;

    /**
     * Constructor
     *
     * Initializes the renderer with theme as null. Specialized renderers will set their
     * specific themes in their constructors, or it will fall back to 'default' in render().
     */
    public function __construct() {}

    /**
     * Render Component
     *
     * Template Method that defines the rendering algorithm. Specialized renderers
     * implement the abstract methods to provide their specific behavior.
     *
     * This method handles both regular rendering and timeline rendering, using the
     * same code path for maintainability and consistency.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @param array  $timeline   Optional timeline data (label, ts, level)
     * @return string Complete HTML component
     */
    public function render(array $data, string $event_type, array $timeline = []): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        
        // Debug log the event type and renderer class
        error_log(sprintf(
            "ODCM Debug - Rendering event: type=%s, renderer=%s",
            $event_type,
            get_class($this)
        ));
        
        // Get renderer-specific content
        $content = $this->renderContent($data, $event_type);
        
        // Get component metadata - use provided label but allow override
        $label = $timeline['label'] ?? null;
        $label = $this->getLabel($data, $event_type) ?: $label;
        
        $statusPill = $this->getStatusPill($data, $event_type);
        
        // Theme resolution logging and fallback
        error_log(sprintf(
            "ODCM Debug - Theme resolution start: event_type=%s, current_theme=%s, renderer=%s",
            $event_type,
            $this->theme ?? 'null',
            get_class($this)
        ));

        if ($this->theme === null) {
            $this->theme = 'default';
            error_log(sprintf(
                "ODCM Debug - Using default theme: event_type=%s, renderer=%s",
                $event_type,
                get_class($this)
            ));
        } else {
            error_log(sprintf(
                "ODCM Debug - Using specialized theme: event_type=%s, theme=%s, renderer=%s",
                $event_type,
                $this->theme,
                get_class($this)
            ));
        }
        
        // Build options array
        $options = [];
        
        // Add timeline-specific options if provided
        if (!empty($timeline)) {
            $options['timestamp'] = $timeline['ts'] ?? null;
            $options['level'] = $timeline['level'] ?? null;
            
            // Debug log timeline data
            error_log(sprintf(
                "ODCM Debug - Timeline data: ts=%s, level=%s",
                $options['timestamp'] ?? 'null',
                $options['level'] ?? 'null'
            ));
        }
        
        if ($statusPill !== null) {
            $options['status_pill'] = $statusPill;
        }
        
        // Debug log the final HTML classes that will be used
        $finalClasses = sprintf('odcm-component odcm-component--%s', esc_attr($this->theme));
        error_log(sprintf(
            "ODCM Debug - Final HTML classes: %s",
            $finalClasses
        ));
        
        // Assemble final component
        $result = $toolkit->render_component_shell(
            $label,
            $this->theme,
            $content,
            $options
        );
        
        // Debug log the final rendered HTML (truncated for log readability)
        error_log(sprintf(
            "ODCM Debug - Rendered HTML (truncated): %s",
            substr($result, 0, 150) . (strlen($result) > 150 ? '...' : '')
        ));
        
        return $result;
    }

    /**
     * Render Content
     *
     * Renders the inner content of the component. Must be implemented by specialized
     * renderers to handle their specific event types.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    protected function renderContent(array $data, string $event_type): string
    {
        // Create a default text block for base renderer
        $toolkit = new PayloadComponentUIToolkit();
        return $toolkit->render_text_block(
            sprintf('Event type: %s', $event_type)
        );
    }

    /**
     * Get Label
     *
     * Gets the component label/title. Can be overridden by specialized renderers
     * to provide event-specific labels.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $data, string $event_type): string
    {
        return ucwords(str_replace('_', ' ', $event_type));
    }

    /**
     * Get Status Pill
     *
     * Gets status pill configuration. Can be overridden by specialized renderers
     * to provide event-specific status pills.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config with 'label' and 'type', or null for no pill
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
        return null;
    }

    /**
     * Format Currency
     *
     * Formats currency values consistently.
     *
     * @param float|string $amount   Amount to format
     * @param string       $currency Currency code
     * @return string Formatted amount with currency
     */
    protected function formatCurrency($amount, string $currency): string
    {
        // Use WooCommerce formatting if available
        if (function_exists('wc_price')) {
            return wc_price($amount, ['currency' => $currency]);
        }
        
        // Basic fallback formatting
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            // TODO: Use WooCommerce' currencies directly. 
        ];
        
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format((float)$amount, 2);
    }

    /**
     * Format Bytes
     *
     * Formats byte values into human-readable format.
     *
     * @param int $bytes Number of bytes
     * @return string Formatted size
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format Metric Value
     *
     * Formats metric values with appropriate units.
     *
     * @param float|int $value Metric value
     * @param string    $unit  Unit of measurement
     * @return string Formatted value with unit
     */
    protected function formatMetricValue($value, string $unit = ''): string
    {
        switch ($unit) {
            case 'ms':
            case 'milliseconds':
                return number_format((float)$value, 2) . ' ms';
                
            case 's':
            case 'seconds':
                return number_format((float)$value, 2) . ' s';
                
            case 'bytes':
            case 'b':
                return $this->formatBytes((int)$value);
                
            case '%':
            case 'percent':
                return number_format((float)$value, 2) . '%';
                
            case 'count':
            case 'items':
                return number_format((int)$value);
                
            default:
                // If unit provided but not special-cased
                if (!empty($unit)) {
                    return number_format((float)$value, 2) . ' ' . $unit;
                }
                
                // No unit, just format the number
                if (is_int($value) || $value == (int)$value) {
                    return number_format((int)$value);
                } else {
                    return number_format((float)$value, 2);
                }
        }
    }

    /**
     * Get User Name
     *
     * Gets user display name from ID.
     *
     * @param int $user_id WordPress user ID
     * @return string User display name
     */
    protected function getUserName(int $user_id): string
    {
        $user = get_userdata($user_id);
        if ($user) {
            return $user->display_name;
        }
        return 'User #' . $user_id;
    }

    /**
     * Get Status Type
     *
     * Maps WooCommerce status to appropriate status type.
     *
     * @param string $status WooCommerce status
     * @return string UI status type
     */
    protected function getStatusType(string $status): string
    {
        $status_map = [
            'completed' => 'success',
            'processing' => 'info',
            'on-hold' => 'warning',
            'cancelled' => 'error',
            'refunded' => 'warning',
            'failed' => 'error',
            'pending' => 'notice',
        ];
        
        return $status_map[strtolower($status)] ?? 'info';
    }
}
