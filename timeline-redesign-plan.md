````markdown
# Order Daemon Timeline System Redesign

## Overview

This document outlines a comprehensive redesign of the Order Daemon timeline system to solve several fundamental issues:

1. Missing or incorrect order IDs ("Order #0" issue) in rule execution events
2. Duplicate rule execution events in the timeline
3. Unclear relationship between events and rule executions
4. Inconsistent data display across different event types
5. Difficulty handling unexpected data from gateways

The redesign adopts a dual relationship model (process_id + parent-child) with improved data organization and display strategies that maintain extensibility for unexpected data.

## Core Issues

### Event-Rule-Action Conceptual Model

The current system mixes "business events" (things that happen) with "rule executions" (decisions/actions taken). This makes it difficult to understand which event triggered which rule, especially when multiple events can trigger the same rule.

### Duplicate Rule Execution Events

The current deduplication attempts rely on detecting rule+order combinations, but the system struggles to properly identify and update existing events, leading to duplicate rule execution events in the timeline. **This is complicated by the asynchronous logging architecture where events are queued and processed later, making simple in-memory deduplication ineffective.**

### Order ID Extraction Issues

The `RuleRenderer.php` file's `extractOrderId()` method misses checking several paths in the payload structure, leading to the "Order #0" display issue when rule execution events cannot properly identify their associated order.

### Inconsistent Timeline Display

Different renderers have varying approaches to displaying event data, making the timeline feel inconsistent. There's no standardized way to extract and display data from different event types.

### Handling Unexpected Data

While preserving all original data, the system needs better organization of fields to maintain clarity while supporting unexpected fields from payment gateways. Currently, there's no systematic way to handle unexpected fields.

## Refined Solution Approach

### 1. Dual Relationship Model

We will implement a dual relationship model that maintains both:

1. **Process ID System** (existing)
   - Groups related events in a process/workflow
   - Provides chronological grouping of events related to a single order's lifecycle
   - Consolidates events into coherent timeline views
   - Spans across multiple event types (checkout, payment, rule execution, etc.)

2. **Parent-Child Relationships** (new)
   - Creates direct relationships between specific events
   - Shows explicit cause-effect between business events and rule executions
   - Enables clear indication of which event triggered which rule
   - Provides a hierarchy for UI presentation

This dual approach gives us both broad workflow grouping and specific causal relationships.

### 2. Simplified Timeline Event Structure

```php
/**
 * Timeline Event Base Structure
 */
class TimelineEvent {
    // Identity
    public $id;                // Unique event ID
    public $type;              // 'business_event' or 'rule_execution'
    public $event_type;        // Specific event type (e.g., 'payment_completed', 'rule_matched')
    
    // Dual Relationships
    public $process_id;        // Groups related events in a workflow (existing)
    public $parent_id;         // For child events, references parent event (new)
    public $children = [];     // For parent events, array of child IDs
    
    // Context
    public $order_id;          // Associated order ID
    public $timestamp;         // When the event occurred
    
    // Display Info
    public $label;             // User-friendly label
    public $summary;           // Brief description for timeline
    public $display_sections = []; // Structured display data (key-value pairs)
    public $detail_sections = []; // Expandable detail sections
    
    // Actions (for rule executions)
    public $actions_taken = []; // Array of actions taken by the rule, not separate events
    
    // Technical Info
    public $tech_data = [];    // Hidden by default, available in debug mode
    
    // Complete original data
    public $raw_payload;       // Original unmodified data
}
````

### 3. Two-Layer Data Storage

Implement a dual-layer approach to event data:

- __Display Layer__: Structured, organized data optimized for display
- __Raw Layer__: Complete, unfiltered data payload (nothing is discarded)

This ensures both user-friendly presentation and complete data preservation.

### 4. Database Schema Updates

```sql
-- Add parent-child relationship to audit log table
ALTER TABLE `wp_odcm_audit_log` 
ADD COLUMN `parent_id` INT UNSIGNED NULL DEFAULT NULL AFTER `log_id`,
ADD COLUMN `display_data` TEXT NULL DEFAULT NULL AFTER `details`,
ADD INDEX `idx_parent` (`parent_id`),
-- Index for efficiently retrieving both relationships
ADD INDEX `idx_process_parent` (`process_id`, `parent_id`);

-- Update payload table to support display data caching
ALTER TABLE `wp_odcm_audit_log_payloads` 
ADD COLUMN `processed_display_data` TEXT NULL DEFAULT NULL COMMENT 'Cached display sections in JSON format',
ADD COLUMN `last_processed` TIMESTAMP NULL DEFAULT NULL;
```

### 5. Adapter-Based Display System

We'll introduce a display adapter system that evolves the current renderer architecture:

```php
/**
 * Base display adapter for extracting structured data
 */
abstract class DisplayAdapter {
    /**
     * Extract display data from payload
     */
    public function extractDisplayData(array $payload): array {
        // Extract standard fields
        $standardFields = $this->extractStandardFields($payload);
        
        // Extract adapter-specific fields
        $specializedFields = $this->extractSpecializedFields($payload);
        
        // Look for any additional interesting fields
        $additionalFields = $this->detectAdditionalFields($payload);
        
        // Organize into display sections
        return $this->organizeIntoSections($standardFields, $specializedFields, $additionalFields);
    }
    
    /**
     * Extract standard fields common to all events 
     */
    protected function extractStandardFields(array $payload): array {
        // Implementation...
    }
    
    /**
     * Extract specialized fields - to be implemented by specific adapters
     */
    abstract protected function extractSpecializedFields(array $payload): array;
    
    /**
     * Auto-detect potentially useful fields
     */
    protected function detectAdditionalFields(array $payload): array {
        $fields = [];
        $interestingPatterns = [
            'id', 'code', 'reference', 'number', 'email', 'address', 
            'status', 'state', 'type', 'method', 'error', 'message',
            'amount', 'total', 'fee', 'price', 'currency'
        ];
        
        $this->recursiveScan($payload, $fields, $interestingPatterns);
        
        return $fields;
    }
    
    // Other helper methods...
}
```

### 6. Gateway-Specific Adapters

Create optimized adapters for known payment gateways:

```php
/**
 * Stripe-specific adapter
 */
class StripeAdapter extends DisplayAdapter {
    protected $knownFields = [
        'charge_id' => ['label' => 'Charge ID', 'section' => 'main'],
        'payment_intent_id' => ['label' => 'Payment Intent', 'section' => 'main'],
        'payment_method' => ['label' => 'Payment Method', 'section' => 'main'],
        'payment_method_details.type' => ['label' => 'Method Type', 'section' => 'details'],
        'payment_method_details.card.brand' => ['label' => 'Card Brand', 'section' => 'details'],
        'payment_method_details.card.last4' => ['label' => 'Card Last 4', 'section' => 'details'],
        'receipt_url' => ['label' => 'Receipt URL', 'section' => 'details'],
    ];
    
    /**
     * Extract Stripe-specific fields
     */
    protected function extractSpecializedFields(array $payload): array {
        $fields = [];
        
        // Look for Stripe data in various common locations
        $stripeData = $payload['stripe'] ?? 
                      $payload['gateway_data'] ?? 
                      $payload['raw_response'] ?? 
                      $payload;
        
        // Extract known fields using dot notation paths
        foreach ($this->knownFields as $path => $config) {
            $value = $this->extractValueByPath($stripeData, $path);
            if ($value !== null) {
                $fields[$config['section']][$path] = [
                    'label' => $config['label'],
                    'value' => $value
                ];
            }
        }
        
        return $fields;
    }
}
```

### 7. Integration with Existing Renderer System

The adapters will integrate with the existing renderer system:

```php
/**
 * Updated base renderer that uses the display adapter system
 */
abstract class BaseRenderer {
    /**
     * @var AdapterRegistry
     */
    protected AdapterRegistry $adapterRegistry;
    
    /**
     * Constructor
     */
    public function __construct(AdapterRegistry $adapterRegistry) {
        $this->adapterRegistry = $adapterRegistry;
    }
    
    /**
     * Render Payload
     */
    public function render(array $payload, string $event_type): string {
        // Get appropriate adapter for this event type
        $adapter = $this->adapterRegistry->getAdapterForEvent($event_type, $payload);
        
        // Extract structured display data
        $displayData = $adapter->extractDisplayData($payload);
        
        // Render using adapter-provided data
        return $this->renderWithDisplayData($displayData, $event_type, $payload);
    }
    
    /**
     * Render with display data
     */
    abstract protected function renderWithDisplayData(array $displayData, string $event_type, array $rawPayload): string;
}
```

### 8. Two-Level Timeline Hierarchy

We'll implement a two-level hierarchy that clearly shows which events triggered which rules:

```javascript
ORDER #123 (process_id: xyz123)
├─ Order Created (10:15:25)
├─ Checkout Completed (10:16:30)
├─ Payment Completed via Stripe (10:16:32)
│  └─ Rule "Auto-Complete Virtual Products" executed: Changed status to Completed (10:16:34)
└─ Status Changed: Pending → Completed (10:16:35)
```

This approach:

1. Maintains clear parent-child relationships between events and rule executions
2. Shows actions as part of rule executions, not as separate events
3. Preserves chronological ordering while showing causal relationships

### 9. Asynchronous Logging and Deduplication Strategy

**Core Problem**: The current logging system is asynchronous - events are queued and processed later by Action Scheduler. This means traditional deduplication approaches (in-memory caches, checking for existing log_id) fail because:

1. Multiple requests can queue the "same" logical event before any are processed
2. The returned event ID from logging is just a queue status, not the final audit log ID
3. Race conditions occur when multiple workers process similar events simultaneously

**Solution: Two-Stage Idempotency with Deterministic Keys**

#### Option A: Consolidated Rule Execution Records (CHOSEN)

Based on Order Daemon's "first match wins" rule priority system, we implement:

- **One rule execution record per** `(order_id, rule_id, process_id)`
- **First rule match executes**, additional triggers only enrich the existing record
- **Deterministic key** computed before any async operations

```php
/**
 * Deterministic rule execution key
 */
private function generateRuleExecutionKey(int $order_id, int $rule_id, string $process_id): string {
    return hash('sha256', sprintf('odcm:rule_exec:v1:%d:%d:%s', $order_id, $rule_id, $process_id));
}
```

#### Two-Layer Deduplication Architecture

**Layer 1: Queue Table Deduplication (Fast "Claim")**
```sql
-- Add dedupe_key column to queue table
ALTER TABLE `wp_odcm_audit_log_queue` 
ADD COLUMN `dedupe_key` VARCHAR(255) NULL DEFAULT NULL AFTER `payload`,
ADD UNIQUE INDEX `idx_dedupe_key` (`dedupe_key`);
```

**Layer 2: Audit Log Deduplication (Final Authority)**
```sql
-- Add dedupe_key column to final audit log
ALTER TABLE `wp_odcm_audit_log` 
ADD COLUMN `dedupe_key` VARCHAR(255) NULL DEFAULT NULL AFTER `details`,
ADD UNIQUE INDEX `idx_dedupe_key` (`dedupe_key`);
```

#### Implementation Flow

```php
/**
 * Create or update rule execution with async-safe deduplication
 */
private function createRuleExecutionEvent(int $order_id, int $rule_id, EvaluationContext $context, string $process_id): void {
    // Generate deterministic key before any async operations
    $ruleExecutionKey = $this->generateRuleExecutionKey($order_id, $rule_id, $process_id);
    
    // Get triggering business event ID
    $triggeringEvent = $this->getTriggeringEventId($context->event->eventType, $order_id);
    
    // Prepare event data with deduplication key
    $eventData = [
        'order_id' => $order_id,
        'rule_id' => $rule_id,
        'rule_name' => $this->matched_rule_data['rule']->post_title ?? 'unnamed rule',
        'parent_id' => $triggeringEvent,
        'process_id' => $process_id,
        'primary_trigger' => $context->event->eventType,
        'execution_status' => 'EXECUTED',
        'actions_taken' => $this->formatActionsTaken($this->matched_rule_data),
        'first_seen_at' => current_time('mysql'),
        'last_seen_at' => current_time('mysql'),
        'dedupe_key' => $ruleExecutionKey, // Critical: deterministic key
    ];
    
    // Attempt to enqueue with deduplication
    $this->enqueueWithDeduplication($ruleExecutionKey, $eventData, $order_id, $process_id);
}

/**
 * Enqueue with queue-level deduplication
 */
private function enqueueWithDeduplication(string $dedupeKey, array $eventData, int $order_id, string $process_id): void {
    global $wpdb;
    
    // Try to insert into queue with unique constraint
    $result = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}odcm_audit_log_queue 
         (dedupe_key, payload, order_id, process_id, status, created_at) 
         VALUES (%s, %s, %d, %s, 'pending', NOW())
         ON DUPLICATE KEY UPDATE 
         payload = VALUES(payload),
         updated_at = NOW()",
        $dedupeKey,
        json_encode($eventData),
        $order_id,
        $process_id
    ));
    
    // Only schedule Action Scheduler job if this is a new entry
    if ($wpdb->rows_affected === 1) {
        as_schedule_single_action(time(), 'odcm_process_audit_log_item', [$dedupeKey]);
    }
}

/**
 * Queue processor with final audit log deduplication
 */
public function processQueueItem(string $dedupeKey): void {
    global $wpdb;
    
    // Get queued item
    $queueItem = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue WHERE dedupe_key = %s",
        $dedupeKey
    ));
    
    if (!$queueItem) {
        return; // Already processed or doesn't exist
    }
    
    $eventData = json_decode($queueItem->payload, true);
    
    // Insert or update in final audit log with deduplication
    $existingEvent = $wpdb->get_row($wpdb->prepare(
        "SELECT log_id, details FROM {$wpdb->prefix}odcm_audit_log WHERE dedupe_key = %s",
        $dedupeKey
    ));
    
    if ($existingEvent) {
        // Update existing record (enrich with new data)
        $this->enrichExistingRuleExecution($existingEvent, $eventData);
    } else {
        // Create new audit log entry
        $this->insertNewRuleExecution($eventData, $dedupeKey);
    }
    
    // Mark queue item as processed
    $wpdb->update(
        "{$wpdb->prefix}odcm_audit_log_queue",
        ['status' => 'processed', 'processed_at' => current_time('mysql')],
        ['dedupe_key' => $dedupeKey]
    );
}

/**
 * Enrich existing rule execution with additional data
 */
private function enrichExistingRuleExecution($existingEvent, array $newEventData): void {
    $existingDetails = json_decode($existingEvent->details, true) ?: [];
    
    // Merge enrichment data (actions, triggers, status updates)
    $enrichedDetails = $this->mergeRuleExecutionData($existingDetails, $newEventData);
    
    // Update with precedence rules (ERROR > PARTIAL > EXECUTED)
    $newStatus = $this->determineStatusPrecedence(
        $existingDetails['execution_status'] ?? 'UNKNOWN',
        $newEventData['execution_status'] ?? 'EXECUTED'
    );
    
    $enrichedDetails['execution_status'] = $newStatus;
    $enrichedDetails['last_seen_at'] = current_time('mysql');
    
    global $wpdb;
    $wpdb->update(
        "{$wpdb->prefix}odcm_audit_log",
        ['details' => json_encode($enrichedDetails)],
        ['log_id' => $existingEvent->log_id]
    );
}
```

### 10. Display Adapter Evolution from Existing Renderers

**Key Insight**: The display adapter system is an evolution of the existing renderer architecture, not a replacement.

**Current Renderer System**:
- Chooses renderer based on `event_type`
- Each renderer knows how to interpret specific payloads
- Mixes data extraction with HTML generation

**New Adapter System**:
- **Separation of Concerns**: Extract/normalize data separately from presentation
- **Incremental Evolution**: Existing renderers can be gradually updated
- **Backward Compatibility**: No immediate breaking changes required

```php
/**
 * Evolution path: Add normalize() method to existing renderers
 */
abstract class BaseRenderer {
    /**
     * New: Extract normalized data structure (pure data)
     */
    public function normalize(array $payload): array {
        return [
            'summary' => $this->extractSummary($payload),
            'main_sections' => $this->extractMainFields($payload),
            'detail_sections' => $this->extractDetailFields($payload),
            'actions_taken' => $this->extractActions($payload),
        ];
    }
    
    /**
     * Updated: Use normalized data for rendering (gradual migration)
     */
    public function render(array $payload, string $event_type): string {
        // Option 1: Use new normalize() method
        $normalizedData = $this->normalize($payload);
        return $this->renderFromNormalized($normalizedData, $payload);
        
        // Option 2: Keep existing render logic (during transition)
        // return $this->legacyRender($payload, $event_type);
    }
    
    /**
     * Legacy render method (preserved during migration)
     */
    protected function legacyRender(array $payload, string $event_type): string {
        // Existing implementation unchanged
    }
}
```

### 11. Minimal UI Expansion Strategy

**Philosophy**: Keep display minimal and curated, with comprehensive fallback for edge cases.

**Above-the-fold Display** (always visible):
- Event summary (what happened)
- Key identifiers (order ID, gateway, amount)
- 1-3 most relevant fields for that event type
- Actions taken (for rule executions)

**Expandable Sections**:
- **"Details"** - Additional structured fields (when relevant)
- **"Raw Payload"** - Complete JSON (pretty-printed, behind expand/collapse)

**Implementation Rules**:
```php
/**
 * Display section strategy
 */
class DisplaySectionStrategy {
    /**
     * Some events may not need any expanded sections
     */
    public function shouldShowExpandedSections(string $eventType, array $payload): bool {
        $minimalEvents = ['order_created', 'status_changed', 'simple_rule_execution'];
        return !in_array($eventType, $minimalEvents);
    }
    
    /**
     * Auto-detected fields go in constrained "Additional Details" section
     */
    public function constrainAutoDetectedFields(array $autoFields): array {
        return [
            'max_fields' => 10,
            'max_depth' => 3,
            'redact_patterns' => ['email', 'address', 'phone'],
            'collapse_by_default' => true,
        ];
    }
}
```

## Revised Implementation Plan - Risk-Minimized Milestones

**Philosophy**: Ship fast value with low risk, then add complexity incrementally. Based on analysis that the biggest pain points are Order #0 and duplicate events.

### Milestone 1: Core Fixes (Fast Value, Low Risk)

**Goal**: Eliminate the most user-visible pain points without schema changes.

1. **Fix Order ID Extraction Robustly**:
   - Update `RuleRenderer.extractOrderId()` method with comprehensive payload path checks
   - Add fallback extraction strategies for different gateway data structures  
   - Add validation to prevent "Order #0" display issues
   - Improve error logging for debugging failed extractions

2. **Implement Deterministic Deduplication**:
   - Add `dedupe_key` column to existing queue and audit log tables
   - Implement deterministic rule execution key generation: `hash(order_id, rule_id, process_id)`
   - Add unique constraints and INSERT...ON DUPLICATE KEY UPDATE logic
   - Handle async logging race conditions with database-enforced uniqueness

3. **Enhanced Rule Execution Display**:
   - Show rule execution as normal event with consistent data display
   - Ensure unique representation per rule execution entity
   - Keep existing UI structure, improve data quality

**Outcome**: ~70-80% reduction in user frustration (no more Order #0, no more obvious duplicates) without DB schema complexity or UI changes.

**Risk Level**: **LOW** - No breaking changes, incremental improvements to existing systems.

### Milestone 2: Relationships and Hierarchy (Medium Risk)

**Goal**: Add parent-child relationships while maintaining backward compatibility.

1. **Database Schema Updates**:
   - Add `parent_id` column to `odcm_audit_log` with proper indexes
   - Add backward compatibility for existing events (parent_id = null)
   - No backfill required - new events start using parent relationships

2. **Parent-Child Relationship Logic**:
   - Update rule execution creation to establish parent links to triggering events
   - Build timeline hierarchy when `parent_id` exists, fallback gracefully when it doesn't
   - Timeline displays two-level hierarchy for new events, flat display for legacy events

3. **Timeline Builder Enhancement**:
   - Support both relationship types (process_id + parent-child)
   - Implement hierarchical timeline rendering
   - Maintain chronological ordering within hierarchy

**Outcome**: Clear causal relationships between events and rules, better UX for understanding workflow.

**Risk Level**: **MEDIUM** - Schema changes but with graceful backward compatibility.

### Milestone 3: Display Normalization (Medium Risk)

**Goal**: Consistent, extensible display system with performance optimization.

1. **Display Adapter Framework**:
   - Introduce base `DisplayAdapter` class that evolves existing renderer system
   - Add `normalize()` method to existing renderers (data extraction separate from HTML)
   - Gradual migration path - existing renderers keep working

2. **Gateway-Specific Adapters**:
   - Implement adapters for major gateways (Stripe, PayPal, etc.)
   - Auto-discovery for unknown fields with constraints (max fields, redaction)
   - Curated "above-the-fold" display + expandable "Raw JSON" section

3. **Performance Optimizations** (Only if profiling shows need):
   - Add `processed_display_data` caching in payload table
   - Implement lazy loading for heavy payloads
   - Background processing for display data extraction

**Outcome**: Consistent display across all event types, better presentation of gateway data, extensible system.

**Risk Level**: **MEDIUM** - New adapter system but backward compatible renderer evolution.

### Alternative "Emergency" Milestone (If Milestone 1 Takes Too Long)

**Super Minimal Fix**: If Order #0 and deduplication fixes prove more complex than expected:

1. **Order ID Extraction Only**: Fix just the order ID extraction paths
2. **Simple Time-Window Deduplication**: 5-minute window for same (order_id, rule_id) combinations  
3. **Basic UI Improvements**: Better error messages and debug info

**Goal**: Ship *something* useful in 1-2 weeks maximum.

## Risk Assessment and Mitigation

### Low Risk Elements (Keep)
- **Dual relationship concept** (process_id + parent-child) - solid architectural foundation
- **Two-layer data storage** (display + raw) - preserves extensibility
- **Display adapter evolution** - builds on existing renderer patterns
- **Incremental migration path** - no breaking changes required

### High Risk Elements (De-scoped or Delayed)
- **Complex adapter registry system** → Start with simple adapter selection 
- **Extensive auto-field discovery** → Constrain to minimal, safe patterns
- **Heavy display data caching** → Only implement if performance profiling shows need
- **Full backfill of parent relationships** → Not required, new events use new system

### Mitigation Strategies

1. **Backward Compatibility First**: Every change must work with existing data
2. **Feature Flags**: Use WordPress options to enable/disable new features
3. **Graceful Degradation**: New features fail safely to existing behavior
4. **Incremental Testing**: Each milestone can be deployed and tested independently
5. **Rollback Plan**: Database changes are additive-only, can be rolled back

## Critical Implementation Details

### Async-Safe Deduplication Contract

```php
/**
 * The deterministic key that prevents duplicates across async boundaries
 */
function generateRuleExecutionKey(int $order_id, int $rule_id, string $process_id): string {
    return hash('sha256', "odcm:rule_exec:v1:{$order_id}:{$rule_id}:{$process_id}");
}

/**
 * Two-phase deduplication: queue table + audit log table
 */
function enqueueRuleExecution(array $eventData): bool {
    // Phase 1: Atomic queue insertion with unique constraint
    // Phase 2: Action Scheduler processes queue → audit log with unique constraint
    // Result: No duplicates possible, even with retries and race conditions
}
```

### Backward Compatibility Rules

- **Legacy events** (parent_id = null): Display in flat timeline, no hierarchy
- **Mixed processes**: Some events with parent_id, some without → render hybrid timeline  
- **Display adapters**: Always fall back to raw JSON if adapter fails
- **Schema changes**: All additive, no data migration required

### Success Criteria

**Milestone 1 Success**:
- Zero "Order #0" events in normal operation
- Zero duplicate rule executions for same (order, rule, process)  
- No regressions in existing timeline functionality

**Milestone 2 Success**:
- Clear parent → child relationships visible in UI
- Legacy events still display correctly
- No performance degradation with relationship queries

**Milestone 3 Success**:
- Consistent display format across all event types
- Gateway data presented clearly and predictably
- Raw data always accessible for debugging

## WordPress Plugin Checker Compliance

**Critical Requirement**: All timeline redesign implementations must pass WordPress Plugin Checker validation. This section outlines specific compliance considerations for each component.

### 1. Direct Database Queries (Our Biggest Risk)

The deduplication and relationship implementations use extensive raw SQL that Plugin Checker heavily scrutinizes.

#### **Required for Every Direct Query**:

```php
// Direct query required for atomic upsert with unique constraint enforcement
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$result = $wpdb->query($wpdb->prepare(
    "INSERT INTO {$wpdb->prefix}odcm_audit_log_queue 
     (dedupe_key, payload, order_id, process_id, status, created_at) 
     VALUES (%s, %s, %d, %s, 'pending', NOW())
     ON DUPLICATE KEY UPDATE 
     payload = VALUES(payload), updated_at = NOW()",
    $dedupeKey,
    json_encode($eventData),
    $order_id,
    $process_id
));
```

#### **Mandatory Comment Format**:
- **Line 1**: Explanation of why direct query is required
- **Line 2**: `// phpcs:ignore` directive with specific rules
- **Line 3**: Actual query using `$wpdb->prepare()`

#### **Common Justifications**:
- "Direct query required for INSERT...ON DUPLICATE KEY UPDATE performance"
- "Complex upsert operation not available in WordPress core"
- "Atomic operation required for race condition prevention"
- "Performance-critical bulk operation"

### 2. Prepared Statements (SQL Injection Prevention)

**Absolute Rule**: Every dynamic value in SQL MUST use placeholders.

#### **Correct Patterns**:
```php
// ✅ CORRECT
$events = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}odcm_audit_log WHERE order_id = %d AND rule_id = %d",
    $order_id,
    $rule_id
));

// ✅ CORRECT - Multiple conditions
$existing = $wpdb->get_row($wpdb->prepare(
    "SELECT log_id, details FROM {$wpdb->prefix}odcm_audit_log 
     WHERE dedupe_key = %s AND process_id = %s",
    $dedupe_key,
    $process_id
));
```

#### **Forbidden Patterns**:
```php
// ❌ FORBIDDEN - Direct concatenation
$query = "SELECT * WHERE order_id = " . $order_id;

// ❌ FORBIDDEN - String interpolation
$query = "SELECT * WHERE dedupe_key = '{$dedupe_key}'";
```

### 3. Database Schema Changes

#### **Required Practices**:

```php
// Use dbDelta() for table alterations when possible
function odcm_add_timeline_columns() {
    global $wpdb;
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Preferred: Use dbDelta for standard operations
    $sql = "ALTER TABLE {$wpdb->prefix}odcm_audit_log 
            ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL,
            ADD COLUMN dedupe_key VARCHAR(255) NULL DEFAULT NULL";
    
    // Direct ALTER for complex operations (with proper justification)
    // Complex schema modification requires direct query for atomic execution
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($sql);
    
    // Always add indexes separately with error handling
    // Index creation requires direct query for IF NOT EXISTS functionality  
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("CREATE INDEX idx_parent ON {$wpdb->prefix}odcm_audit_log (parent_id)");
}
```

#### **Table Prefixing**:
- ✅ Always use `{$wpdb->prefix}tablename` or `$wpdb->tablename`
- ❌ Never hardcode table names: `wp_odcm_audit_log`

### 4. Output Escaping (Timeline Display)

**Critical for Display Adapters and Renderers**:

#### **Required Escaping by Context**:

```php
/**
 * Safe timeline event rendering
 */
public function renderEvent(TimelineEvent $event): string {
    $html = '<div class="timeline-event">';
    
    // Text content - always escape
    $html .= '<h3>' . esc_html($event->label) . '</h3>';
    $html .= '<p>' . esc_html($event->summary) . '</p>';
    
    // Attributes - use esc_attr()
    $html .= '<div data-order-id="' . esc_attr($event->order_id) . '">';
    
    // URLs - use esc_url()
    if (!empty($event->receipt_url)) {
        $html .= '<a href="' . esc_url($event->receipt_url) . '">Receipt</a>';
    }
    
    // JSON data - escape for HTML context
    $html .= '<script type="application/json">';
    $html .= wp_json_encode($event->tech_data); // Already safe
    $html .= '</script>';
    
    return $html;
}
```

#### **Special Considerations for Gateway Data**:
```php
/**
 * Gateway data is user-controlled and requires careful escaping
 */
public function renderGatewayData(array $gatewayData): string {
    $safe_data = [];
    foreach ($gatewayData as $key => $value) {
        // Sanitize keys and values
        $safe_key = sanitize_key($key);
        $safe_value = is_string($value) ? esc_html($value) : $value;
        $safe_data[$safe_key] = $safe_value;
    }
    
    return wp_json_encode($safe_data);
}
```

### 5. Input Sanitization

#### **For Timeline Filtering/Controls**:

```php
/**
 * Timeline filter endpoint with proper sanitization
 */
public function handleTimelineFilter(): void {
    // Verify nonce first
    if (!wp_verify_nonce($_POST['nonce'], 'odcm_timeline_filter')) {
        wp_die('Invalid nonce');
    }
    
    // Sanitize all inputs
    $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    $event_type = isset($_POST['event_type']) ? sanitize_key($_POST['event_type']) : '';
    $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
    
    // Validate sanitized inputs
    if ($order_id <= 0 || empty($event_type)) {
        wp_send_json_error('Invalid parameters');
        return;
    }
    
    // Proceed with validated, sanitized data
    $results = $this->getFilteredTimeline($order_id, $event_type, $date_from);
    wp_send_json_success($results);
}
```

### 6. Nonce Verification

#### **Required for Any User-Facing Endpoints**:

```php
/**
 * Timeline action endpoint with nonce verification
 */
public function processTimelineAction(): void {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    // Verify nonce BEFORE processing any data
    if (!wp_verify_nonce($_POST['_wpnonce'], 'odcm_timeline_action')) {
        wp_die('Security check failed');
    }
    
    // Now safe to process the action
    $action = sanitize_key($_POST['action']);
    // ... rest of logic
}

/**
 * Generate nonce in forms/AJAX
 */
public function renderTimelineControls(): string {
    $nonce = wp_create_nonce('odcm_timeline_action');
    return '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
}
```

### 7. Performance and Caching Compliance

#### **When to Add Caching Ignore Comments**:
```php
// Real-time deduplication check cannot be cached
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
$existing = $wpdb->get_row($wpdb->prepare(
    "SELECT log_id FROM {$wpdb->prefix}odcm_audit_log WHERE dedupe_key = %s",
    $dedupe_key
));

// Use transients for cacheable timeline queries
$cache_key = "odcm_timeline_{$order_id}_{$process_id}";
$timeline = get_transient($cache_key);

if (false === $timeline) {
    $timeline = $this->buildTimelineFromDatabase($order_id, $process_id);
    set_transient($cache_key, $timeline, HOUR_IN_SECONDS);
}
```

## Compliance Implementation Strategy

### **Per-Milestone Compliance Checklist**

#### **Milestone 1: Core Fixes**
- [ ] Update `RuleRenderer.extractOrderId()` with prepared statements for any new queries
- [ ] Add `// phpcs:ignore` comments to unavoidable direct queries with explanations
- [ ] Remove any leftover `error_log()` or debugging functions
- [ ] Ensure dedupe key queries use `$wpdb->prepare()`
- [ ] Add output escaping to any new error messages

#### **Milestone 2: Relationships and Hierarchy**  
- [ ] Use `dbDelta()` for schema migrations where possible
- [ ] Prepare all parent-child relationship queries
- [ ] Add proper `// phpcs:ignore` for schema change operations
- [ ] Test migration with different table prefixes
- [ ] Document rollback procedures

#### **Milestone 3: Display Normalization**
- [ ] Escape ALL rendered output (especially gateway data)
- [ ] Add nonce verification to any new AJAX endpoints
- [ ] Sanitize filter inputs (order ID, event types, date ranges)
- [ ] Use `wp_json_encode()` for JSON responses
- [ ] Implement proper capability checks for timeline controls

### **Pre-Commit Testing Requirements**

1. **Run Plugin Checker**:
   ```bash
   # Test against WordPress coding standards
   phpcs --standard=WordPress-Core,WordPress-Docs,WordPress-Extra src/
   
   # Check for security issues
   phpcs --standard=WordPress-Security src/
   ```

2. **Database Security Test**:
   ```php
   // Verify no string concatenation in queries
   grep -r "SELECT.*\." src/ && echo "FAIL: Possible unsanitized queries"
   
   // Verify prepare() usage
   grep -r "\$wpdb->" src/ | grep -v "prepare" && echo "CHECK: Manual review required"
   ```

3. **Output Security Test**:
   ```php
   // Check for unescaped output
   grep -r "echo \$" src/ && echo "CHECK: Manual review required"
   grep -r "print.*\$" src/ && echo "CHECK: Manual review required"
   ```

### **Common Plugin Checker Failure Prevention**

#### **Database Category**:
- ❌ `WordPress.DB.DirectDatabaseQuery.DirectQuery` → Add `// phpcs:ignore` with justification
- ❌ `WordPress.DB.PreparedSQL.NotPrepared` → Use `$wpdb->prepare()` for all dynamic values
- ❌ `WordPress.DB.DirectDatabaseQuery.NoCaching` → Add caching or ignore with justification

#### **Security Category**:
- ❌ `WordPress.Security.NonceVerification.Recommended` → Add nonce checks to forms/AJAX
- ❌ `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` → Use sanitize functions
- ❌ `WordPress.Security.EscapeOutput.OutputNotEscaped` → Use esc_html(), esc_attr(), esc_url()

#### **Performance Category**:
- ❌ `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` → Optimize meta queries or ignore
- ❌ `WordPress.PHP.DevelopmentFunctions.error_log_error_log` → Remove all debugging code

### **Emergency Compliance Fixes**

If Plugin Checker fails during submission:

1. **Critical Security Issues**: Fix immediately, no exceptions
2. **Database Warnings**: Add `// phpcs:ignore` comments with proper justifications
3. **Output Escaping**: Wrap all dynamic content with appropriate esc_*() functions
4. **Performance Warnings**: Document why optimizations aren't applicable or implement caching

**Success Metric**: Zero Plugin Checker errors, minimal justified warnings only.

## Relationship Between Current and New Systems

The new system builds on the strengths of the current one while addressing its limitations:

1. __Data Structure__:

   - __Current__: Events are linked only by process_id
   - __New__: Events have both process_id and direct parent-child relationships

2. __Display Logic__:

   - __Current__: Renderers directly extract and format data in inconsistent ways
   - __New__: Adapters extract and structure data consistently, renderers focus on presentation

3. __Extensibility__:

   - __Current__: Requires new renderer classes for new event types
   - __New__: Uses adapters that can automatically handle new event types

4. __Event Relationships__:

   - __Current__: Events are chronologically ordered but relationships are unclear
   - __New__: Clear parent-child relationships while maintaining chronological ordering

## Example: Before and After

### Before

```javascript
Rule evaluation completed for Order #0
EXECUTED

Technical Execution Details

Correlation ID
  odcm:lifecyc...ae1.62159247
```

### After

```javascript
Rule "Auto-Complete Virtual Products" executed
EXECUTED

Execution Summary
  ✓ Changed Order Status: Pending → Completed
  
Triggered By
  ⚡️ Payment Completed via Stripe (PRIMARY TRIGGER)
  
Conditions Evaluated (1/1 passed)
  ✓ Product Type: Virtual
  
Actions Taken
  ✓ Changed order status to Completed
  
[Execution Details ▼]
  Rule Position: #1 (First match)
  Execution Time: 230ms
  Rule ID: #13
```

## Technical Implementation Details

### TimelineEvent Class Implementation

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Core timeline event data structure with parent-child relationship support
 */
final class TimelineEvent
{
    public ?int $id;                 // Unique event ID
    public string $type;             // 'business_event' or 'rule_execution' 
    public string $event_type;       // Specific event type (e.g., 'payment_completed')
    
    // Dual Relationships
    public string $process_id;       // Process grouping (existing)
    public ?int $parent_id;          // Direct parent event (new)
    public array $children = [];     // Direct child events
    
    public ?int $order_id;           // Associated order ID
    public string $timestamp;        // Event timestamp
    
    public ?string $label;           // User-friendly label
    public ?string $summary;         // Brief description for timeline
    public array $display_sections = []; // Structured display data
    public array $detail_sections = []; // Expandable detail sections
    public array $actions_taken = []; // Actions (for rule executions)
    
    public array $tech_data = [];    // Hidden by default, for debug mode
    
    public array $raw_payload;       // Original unmodified data
    
    // Constructor and methods...
}
```

### DisplayAdapter Implementation

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\Display;

/**
 * Base adapter for extracting display data from event payloads
 */
abstract class DisplayAdapter
{
    /**
     * Extract display data from payload
     */
    public function extractDisplayData(array $payload): array
    {
        // Implementation...
    }
    
    /**
     * Extract standard fields common to all events 
     */
    protected function extractStandardFields(array $payload): array
    {
        // Implementation...
    }
    
    /**
     * Extract adapter-specific fields - to be implemented by specific adapters
     */
    abstract protected function extractSpecializedFields(array $payload): array;
    
    /**
     * Auto-detect potentially useful fields
     */
    protected function detectAdditionalFields(array $payload): array
    {
        // Implementation...
    }
    
    /**
     * Recursively scan payload for interesting fields
     */
    protected function recursiveScan(array $data, array &$result, array $patterns, string $prefix = ''): void
    {
        // Implementation...
    }
}
```

### Timeline Builder with Dual Relationships

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Timeline builder that supports both relationship types
 */
class TimelineBuilder implements TimelineBuilderInterface
{
    /**
     * Build timeline with both relationship types
     */
    public function buildTimeline(TimelineRequest $request): TimelineData
    {
        // If process view mode, get all events with the same process_id
        if ($request->viewMode === 'consolidated') {
            $events = $this->loadEventsForProcess($request->processId);
        } else {
            // Otherwise just get the individual event
            $events = [$this->loadEventById($request->logId)];
        }
        
        // Then establish parent-child hierarchy within these events
        $hierarchicalEvents = $this->establishHierarchy($events);
        
        // Sort chronologically while maintaining hierarchy
        $sortedEvents = $this->sortChronologically($hierarchicalEvents);
        
        return $this->createTimelineData($sortedEvents, $request);
    }
    
    /**
     * Load all events for a process
     */
    private function loadEventsForProcess(string $processId): array
    {
        // Implementation...
    }
    
    /**
     * Establish parent-child hierarchy
     */
    private function establishHierarchy(array $events): array
    {
        // Implementation...
    }
}
```

## Key Architectural Components

### TimelineEvent Class

Core data structure with support for both process_id and parent-child relationships.

### DisplayAdapter System

Handles extracting and organizing data from event payloads for consistent display.

### Renderer Integration

Updates existing renderers to use the adapter system for better consistency.

### RuleExecutionManager

Handles rule execution events with proper parent relationships and deduplication.

## Benefits

This redesign will:

1. Completely solve the "Order #0" issue by properly extracting order IDs
2. Eliminate duplicate rule execution events
3. Create a clear, dual-relationship timeline that shows both process flow and cause-effect
4. Present event data in a consistent, user-friendly way with adapters
5. Maintain extensibility for unexpected data from payment gateways
6. Provide a more robust foundation for future enhancements

## Technical Challenges and Solutions

### Challenge: Maintaining Dual Relationships

__Solution__: The TimelineEvent class will support both relationship types, and the database will have indexes for efficient querying with both relationships.

### Challenge: Handling Unexpected Gateway Data

__Solution__: The adapter system will include auto-discovery for unknown fields, ensuring all data is accessible even if not explicitly mapped.

### Challenge: Consistent Display Across Event Types

__Solution__: Standardized DisplaySection structure with templates will ensure all events are presented consistently regardless of their source.

### Challenge: Rule Execution Deduplication

__Solution__: Implement deterministic idempotency using unique keys that work across async boundaries, with database-enforced uniqueness constraints to prevent race conditions.

## Next Steps

1. Implement the core TimelineEvent class
2. Update the database schema
3. Create the basic adapter framework
4. Fix the immediate order ID extraction issues
5. Implement the parent-child relationship logic
6. Develop specialized adapters for known gateways
7. Update the renderers to use the adapter system
8. Comprehensive testing with various payment scenarios
