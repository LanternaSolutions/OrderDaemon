<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\DashboardComponents;

use Exception;
use InvalidArgumentException;

/**
 * Abstract base class for Insight Dashboard component renderers.
 *
 * Mirrors the template method pattern used by PayloadComponentRenderer but
 * simplified for admin dashboard UI fragments. Concrete components should
 * override getComponentId(), canHandle(), and render().
 *
 * Security & Standards:
 * - All output should be properly escaped by the concrete component or by the
 *   delegated legacy renderer that already performs escaping.
 * - All files declare(strict_types=1) and follow WordPress coding standards.
 *
 * @package OrderDaemon\CompletionManager\View\DashboardComponents
 * @since   1.0.0
 */
abstract class DashboardComponentRenderer
{
    /**
     * Render the component to HTML.
     *
     * Concrete implementations can either build the HTML directly or
     * delegate to a legacy method via an injected callable. Must return a
     * string of HTML (do not echo here).
     *
     * @param array $data Optional data for rendering.
     * @return string HTML string.
     */
    abstract public function render(array $data = []): string;

    /**
     * Unique component ID used for registry lookup and metadata.
     *
     * @return string
     */
    abstract protected function getComponentId(): string;

    /**
     * Whether this component can handle the given context (dashboard state).
     *
     * @param array $context
     * @return bool
     */
    abstract public function canHandle(array $context): bool;

    /**
     * Render with context using a safe template method that ensures validation
     * and error handling.
     *
     * @param array $context Arbitrary context (user caps, i18n, settings, etc.)
     * @param array $data Optional rendering data
     */
    final public function renderWithContext(array $context, array $data = []): void
    {
        $rendered_html = '';

        try {
            $this->validateData($data);
            // Retrieve metadata to ensure the component is registered. Not used directly
            // here but validates availability and can drive theming if needed.
            $this->getMetadataFromRegistry();

            if ($this->canHandle($context)) {
                $rendered_html = $this->render($data);
            }
        } catch (Exception $e) {
            $rendered_html = $this->renderErrorComponent($e, $data);
        }

        _e($rendered_html, 'order-daemon');;
    }

    /**
     * Validate input data for renderers. Subclasses can override to enforce
     * stricter schemas.
     *
     * @param array $data
     * @return void
     */
    protected function validateData(array $data): void
    {
        // Base implementation accepts arrays only. Subclasses may override.
        if (!is_array($data)) {
            throw new InvalidArgumentException('Dashboard component data must be an array.');
        }
    }

    /**
     * Fetch component metadata from the DashboardComponentRegistry.
     *
     * @return array{label:string,css_class?:string,priority?:int}
     */
    protected function getMetadataFromRegistry(): array
    {
        // Lazy load to avoid hard dependency loops at plugin bootstrap time.
        $registryClass = '\\OrderDaemon\\CompletionManager\\Core\\DashboardComponentRegistry';
        if (!class_exists($registryClass)) {
            // Attempt to load via Composer autoload. If unavailable, return a minimal stub.
            return [
                'label' => $this->getComponentId(),
                'css_class' => 'odcm-dashboard-component',
                'priority' => 10,
            ];
        }

        /** @var class-string $registryClass */
        $metadata = $registryClass::get_component_metadata($this->getComponentId());
        if (empty($metadata['label'])) {
            // Provide reasonable defaults if not registered yet.
            $metadata['label'] = $this->getComponentId();
        }
        if (!isset($metadata['css_class'])) {
            $metadata['css_class'] = 'odcm-dashboard-component';
        }
        if (!isset($metadata['priority'])) {
            $metadata['priority'] = 10;
        }
        return $metadata;
    }

    /**
     * Render a minimal error placeholder so the dashboard remains usable.
     *
     * @param Exception $e
     * @param array $data
     * @return string
     */
    private function renderErrorComponent(Exception $e, array $data): string
    {
        $message = esc_html($e->getMessage());
        $component_id = esc_attr($this->getComponentId());
        return '<div class="odcm-dashboard-component odcm-error" data-component="' . $component_id . '">' .
            '<p>' . $message . '</p>' .
            '</div>';
    }
}
