<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\DashboardComponents;

/**
 * Welcome State Component Renderer
 *
 * @package OrderDaemon\CompletionManager\View\DashboardComponents
 * @since   1.0.0
 */
class WelcomeStateRenderer extends DashboardComponentRenderer
{
    /** @var callable|null */
    private $delegate;

    public function __construct(?callable $delegate = null)
    {
        $this->delegate = $delegate;
    }

    protected function getComponentId(): string
    {
        return 'welcome_state';
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
