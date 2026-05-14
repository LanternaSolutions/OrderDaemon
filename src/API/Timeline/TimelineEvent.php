<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Core timeline event data structure with parent-child relationship support
 *
 * This class implements the dual relationship model described in the timeline redesign:
 * 1. Process ID System (existing) - Groups related events in a workflow
 * 2. Parent-Child Relationships (new) - Creates direct relationships between specific events
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
final class TimelineEvent
{
    // Identity
    public ?int $id;                // Unique event ID
    public string $type;             // 'business_event' or 'rule_execution'
    public string $event_type;       // Specific event type (e.g., 'payment_completed', 'rule_matched')

    // Dual Relationships
    public string $process_id;       // Groups related events in a workflow (existing)
    public ?int $parent_id;          // For child events, references parent event (new)
    public array $children = [];     // For parent events, array of child IDs

    // Context
    public ?int $order_id;           // Associated order ID
    public string $timestamp;        // When the event occurred

    // Display Info
    public ?string $label;           // User-friendly label
    public ?string $summary;         // Brief description for timeline
    public array $display_sections = []; // Structured display data (key-value pairs)
    public array $detail_sections = []; // Expandable detail sections

    // Actions (for rule executions)
    public array $actions_taken = []; // Array of actions taken by the rule, not separate events

    // Status
    public ?string $status = null;   // Event status (e.g., 'success', 'error', 'failed')

    // Technical Info
    public array $tech_data = [];    // Hidden by default, available in debug mode

    // Complete original data
    public array $raw_payload;       // Original unmodified data

    /**
     * Constructor
     */
    public function __construct(
        ?int $id = null,
        string $type = 'business_event',
        string $event_type = 'unknown',
        string $process_id = '',
        ?int $parent_id = null,
        array $children = [],
        ?int $order_id = null,
        string $timestamp = '',
        ?string $label = null,
        ?string $summary = null,
        array $display_sections = [],
        array $detail_sections = [],
        array $actions_taken = [],
        ?string $status = null,
        array $tech_data = [],
        array $raw_payload = []
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->event_type = $event_type;
        $this->process_id = $process_id;
        $this->parent_id = $parent_id;
        $this->children = $children;
        $this->order_id = $order_id;
        $this->timestamp = $timestamp;
        $this->label = $label;
        $this->summary = $summary;
        $this->display_sections = $display_sections;
        $this->detail_sections = $detail_sections;
        $this->actions_taken = $actions_taken;
        $this->status = $status;
        $this->tech_data = $tech_data;
        $this->raw_payload = $raw_payload;
    }

    /**
     * Check if this event has a parent
     */
    public function hasParent(): bool
    {
        return $this->parent_id !== null && $this->parent_id > 0;
    }

    /**
     * Check if this event has children
     */
    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    /**
     * Check if this is a business event
     */
    public function isBusinessEvent(): bool
    {
        return $this->type === 'business_event';
    }

    /**
     * Check if this is a rule execution event
     */
    public function isRuleExecution(): bool
    {
        return $this->type === 'rule_execution';
    }

    /**
     * Add a child event ID
     */
    public function addChild(int $child_id): void
    {
        if (!in_array($child_id, $this->children, true)) {
            $this->children[] = $child_id;
        }
    }

    /**
     * Add display section data
     */
    public function addDisplaySection(string $key, string $label, string $value): void
    {
        $this->display_sections[$key] = [
            'label' => $label,
            'value' => $value
        ];
    }

    /**
     * Add detail section data
     */
    public function addDetailSection(string $key, string $label, array $data): void
    {
        $this->detail_sections[$key] = [
            'label' => $label,
            'data' => $data
        ];
    }

    /**
     * Add action taken
     */
    public function addActionTaken(string $action_label, string $result = 'success'): void
    {
        $this->actions_taken[] = [
            'action_label' => $action_label,
            'result' => $result,
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * Add technical data
     */
    public function addTechData(string $key, $value): void
    {
        $this->tech_data[$key] = $value;
    }

    /**
     * Create from legacy log entry (backward compatibility)
     */
    public static function fromLegacyLogEntry(array $logEntry): self
    {
        $order_id = isset($logEntry['order_id']) && $logEntry['order_id'] !== null
            ? (int) $logEntry['order_id']
            : null;
        $event_type = $logEntry['event_type'] ?? 'unknown';
        $process_id = $logEntry['process_id'] ?? '';
        $timestamp = $logEntry['timestamp'] ?? current_time('mysql');

        // Determine event type
        $type = 'business_event';
        if (strpos($event_type, 'rule_') === 0 || $event_type === 'rule_execution') {
            $type = 'rule_execution';
        }

        $parent_id = isset($logEntry['parent_id']) && $logEntry['parent_id'] !== null
            ? (int) $logEntry['parent_id']
            : null;

        return new self(
            isset($logEntry['log_id']) ? (int) $logEntry['log_id'] : null,
            $type,
            $event_type,
            $process_id,
            $parent_id,
            [], // children
            $order_id,
            $timestamp,
            null, // label
            null, // summary
            [], // display_sections
            [], // detail_sections
            [], // actions_taken
            $logEntry['status'] ?? null,
            [], // tech_data
            $logEntry // raw_payload
        );
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'event_type' => $this->event_type,
            'process_id' => $this->process_id,
            'parent_id' => $this->parent_id,
            'children' => $this->children,
            'order_id' => $this->order_id,
            'timestamp' => $this->timestamp,
            'label' => $this->label,
            'summary' => $this->summary,
            'display_sections' => $this->display_sections,
            'detail_sections' => $this->detail_sections,
            'actions_taken' => $this->actions_taken,
            'status' => $this->status,
            'tech_data' => $this->tech_data,
            'raw_payload' => $this->raw_payload
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            $data['type'] ?? 'business_event',
            $data['event_type'] ?? 'unknown',
            $data['process_id'] ?? '',
            $data['parent_id'] ?? null,
            $data['children'] ?? [],
            $data['order_id'] ?? null,
            $data['timestamp'] ?? current_time('mysql'),
            $data['label'] ?? null,
            $data['summary'] ?? null,
            $data['display_sections'] ?? [],
            $data['detail_sections'] ?? [],
            $data['actions_taken'] ?? [],
            $data['status'] ?? null,
            $data['tech_data'] ?? [],
            $data['raw_payload'] ?? []
        );
    }
}
