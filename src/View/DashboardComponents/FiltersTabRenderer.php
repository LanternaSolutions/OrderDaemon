<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\DashboardComponents;

/**
 * Filters Tab Component Renderer
 *
 * @package OrderDaemon\CompletionManager\View\DashboardComponents
 * @since   2.1.0
 */
class FiltersTabRenderer extends DashboardComponentRenderer
{
    /** @var callable|null */
    private $delegate;

    public function __construct(?callable $delegate = null)
    {
        $this->delegate = $delegate;
    }

    protected function getComponentId(): string
    {
        return 'filters_tab';
    }

    public function canHandle(array $context): bool
    {
        return true;
    }

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
