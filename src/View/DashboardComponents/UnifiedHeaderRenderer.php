<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\DashboardComponents;

/**
 * Unified Header Component Renderer
 *
 * @package OrderDaemon\CompletionManager\View\DashboardComponents
 * @since   1.0.0
 */
class UnifiedHeaderRenderer extends DashboardComponentRenderer
{
    /** @var callable|null */
    private $delegate;

    /**
     * @param callable|null $delegate A callable that echoes the legacy header HTML.
     */
    public function __construct(?callable $delegate = null)
    {
        $this->delegate = $delegate;
    }

    /**
     * @inheritDoc
     */
    protected function getComponentId(): string
    {
        return 'unified_header';
    }

    /**
     * @inheritDoc
     */
    public function canHandle(array $context): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function render(array $data = []): string
    {
        if ($this->delegate === null) {
            return '';
        }
        ob_start();
        call_user_func($this->delegate, $data);
        return (string) ob_get_clean();
    }
}
