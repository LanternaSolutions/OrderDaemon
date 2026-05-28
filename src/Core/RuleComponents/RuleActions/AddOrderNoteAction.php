<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleActions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ActionInterface;
use WC_Order;

class AddOrderNoteAction implements ActionInterface
{
    public function get_priority(): int
    {
        return 40;
    }

    public function get_id(): string
    {
        return 'add_order_note';
    }

    public function get_label(): string
    {
        return __('rule_component.action.add_order_note.label', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('rule_component.action.add_order_note.description', 'order-daemon');
    }

    public function get_icon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" x2="15" y1="10" y2="10"/><line x1="12" x2="12" y1="7" y2="13"/></svg>';
    }

    public function is_default(): bool
    {
        return false;
    }

    public function get_settings_schema(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note_text' => [
                    'type' => 'string',
                    'title' => __('rule_component.action.add_order_note.settings.note_text.label', 'order-daemon'),
                    'description' => __('rule_component.action.add_order_note.settings.note_text.description', 'order-daemon'),
                    'default' => __('rule_component.action.add_order_note.settings.note_text.default', 'order-daemon'),
                    'ui:widget' => 'textarea',
                ],
                'is_customer_note' => [
                    'type' => 'boolean',
                    'title' => __('rule_component.action.add_order_note.settings.is_customer_note.label', 'order-daemon'),
                    'description' => __('rule_component.action.add_order_note.settings.is_customer_note.description', 'order-daemon'),
                    'default' => false,
                ],
                'add_timestamp' => [
                    'type' => 'boolean',
                    'title' => __('rule_component.action.add_order_note.settings.add_timestamp.label', 'order-daemon'),
                    'description' => __('rule_component.action.add_order_note.settings.add_timestamp.description', 'order-daemon'),
                    'default' => true,
                ],
            ],
        ];
    }

    public function execute(WC_Order $order, array $settings): void
    {
        try {
            $note_text        = $settings['note_text'] ?? __('rule_component.action.add_order_note.settings.note_text.default', 'order-daemon');
            $is_customer_note = $settings['is_customer_note'] ?? false;
            $add_timestamp    = $settings['add_timestamp'] ?? true;

            if ($add_timestamp) {
                $note_text = sprintf('[%s] %s', current_time('Y-m-d H:i:s'), $note_text);
            }

            $order->add_order_note(
                $note_text,
                $is_customer_note ? 1 : 0,
                false
            );

            if (function_exists('odcm_log_event')) {
                odcm_log_event('order_note_added', [
                    'order_id'         => $order->get_id(),
                    'action_type'      => 'add_order_note',
                    'customer_visible' => $is_customer_note,
                    'timestamped'      => $add_timestamp,
                ]);
            }
        } catch (\Exception $e) {
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    'Add Order Note Failed',
                    ['order_id' => $order->get_id(), 'error' => $e->getMessage()],
                    $order->get_id(),
                    'error',
                    'add_order_note_action'
                );
            }
        }
    }
}