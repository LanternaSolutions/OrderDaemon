# Renderer Consolidation Plan

Based on our comprehensive analysis of event types, data structures, business value, and UI design patterns, this document outlines a plan to consolidate renderers into a minimal, maintainable set that directly maps to event types without unnecessary abstraction.

## Current Issues with Renderer System

1. **Excessive Abstraction**: The current three-tier lookup system (registry → capability → fallback) adds unnecessary complexity
2. **Indirect Mapping**: Event types don't directly map to renderers, requiring aliases and parent-child relationships
3. **Inconsistent Naming**: Event types in the database don't always match component IDs in the registry
4. **Redundant Renderers**: Many renderers have overlapping functionality and similar UI patterns
5. **Maintenance Burden**: Each new event type potentially requires registry updates in multiple places

## Consolidated Renderer Architecture

Based on our analysis, we can consolidate the 25+ event types into just 5 core renderers:

### 1. RuleRenderer

**Handles Events:**
- rule_matched
- rule_no_match
- rule_evaluated
- rule_evaluation
- condition_passed
- condition_failed
- action_executed
- decision

**Rationale:**
- All rule-related events share similar data structures
- They all represent stages of the rule evaluation process
- They use the same UI patterns (key-value lists, status pills)
- The business value is closely related (rule evaluation transparency)

**Implementation Approach:**
- Create a single `RuleRenderer` class
- Use the event_type to determine specific rendering behavior
- Maintain specific methods for different rule event subtypes
- Share common helper functions across all rule event types

### 2. PaymentRenderer

**Handles Events:**
- payment_completed
- payment_failed
- refund_created
- stripe_event
- paypal_event
- order_partially_refunded
- order_fully_refunded

**Rationale:**
- All payment-related events share financial data structures
- They require currency formatting and transaction details
- They have high business value and similar UI requirements
- They're all related to financial transactions

**Implementation Approach:**
- Create a single `PaymentRenderer` class
- Use helper methods for currency formatting and financial displays
- Implement event_type-specific sections for different payment events
- Share transaction and financial formatting code

### 3. OrderRenderer

**Handles Events:**
- status_changed
- order_loaded
- block_checkout_processed
- meta_updated
- woocommerce_data

**Rationale:**
- All WooCommerce order events share order data structures
- They operate on similar order-related data
- They have consistent UI requirements for order display
- They're all related to order lifecycle

**Implementation Approach:**
- Create a single `OrderRenderer` class
- Implement status-specific handling for status_changed
- Share common order data formatting and display
- Use consistent WooCommerce styling

### 4. SystemRenderer

**Handles Events:**
- info
- warning
- error
- metrics
- admin_action
- process_started
- process_event
- lifecycle_event
- custom_event
- action_scheduled

**Rationale:**
- System events are all general-purpose informational events
- They share simple message-based data structures
- They have similar UI patterns for message display
- They require consistent severity indicators

**Implementation Approach:**
- Create a single `SystemRenderer` class
- Use event_type to determine severity and styling
- Implement level-specific rendering (info vs. warning vs. error)
- Share message formatting and key-value display logic

### 5. AnalysisRenderer

**Handles Events:**
- refund_analysis
- woocommerce_analysis
- metrics (complex)
- dedup

**Rationale:**
- Analysis events contain detailed data for investigation
- They share complex, potentially nested data structures
- They require expandable sections and code blocks
- They're used for debugging and detailed analysis

**Implementation Approach:**
- Create a single `AnalysisRenderer` class
- Implement domain-specific sections for different analysis types
- Share code formatting and expandable section generation
- Focus on progressive disclosure of complex data

## Event Type to Renderer Mapping

Instead of the current complex registry with parent-child relationships, we'll create a direct mapping from event_type to renderer:

```php
private static $event_type_renderers = [
    // Rule events
    'rule_matched' => RuleRenderer::class,
    'rule_no_match' => RuleRenderer::class,
    'rule_evaluated' => RuleRenderer::class,
    'rule_evaluation' => RuleRenderer::class,
    'condition_passed' => RuleRenderer::class,
    'condition_failed' => RuleRenderer::class,
    'action_executed' => RuleRenderer::class,
    'decision' => RuleRenderer::class,
    
    // Payment events
    'payment_completed' => PaymentRenderer::class,
    'payment_failed' => PaymentRenderer::class,
    'refund_created' => PaymentRenderer::class,
    'stripe_event' => PaymentRenderer::class,
    'paypal_event' => PaymentRenderer::class,
    'order_partially_refunded' => PaymentRenderer::class,
    'order_fully_refunded' => PaymentRenderer::class,
    
    // Order events
    'status_changed' => OrderRenderer::class,
    'order_loaded' => OrderRenderer::class,
    'block_checkout_processed' => OrderRenderer::class,
    'meta_updated' => OrderRenderer::class,
    'woocommerce_data' => OrderRenderer::class,
    
    // System events
    'info' => SystemRenderer::class,
    'warning' => SystemRenderer::class,
    'error' => SystemRenderer::class,
    'admin_action' => SystemRenderer::class,
    'process_started' => SystemRenderer::class,
    'process_event' => SystemRenderer::class,
    'lifecycle_event' => SystemRenderer::class,
    'custom_event' => SystemRenderer::class,
    'action_scheduled' => SystemRenderer::class,
    
    // Analysis events
    'refund_analysis' => AnalysisRenderer::class,
    'woocommerce_analysis' => AnalysisRenderer::class,
    'dedup' => AnalysisRenderer::class,
    
    // Fallback
    'fallback' => FallbackRenderer::class
];
```

## Renderer Class Structure

Each renderer will follow a consistent pattern with a base abstract class:

```php
abstract class BaseRenderer
{
    // Shared helper methods
    protected function formatCurrency($amount, $currency) { /* ... */ }
    protected function formatBytes($bytes) { /* ... */ }
    protected function getUserName($user_id) { /* ... */ }
    
    // Core rendering method (Template Method Pattern)
    public function render(array $data, string $event_type): string
    {
        // Extract common data
        $label = $this->getLabel($data, $event_type);
        $content = $this->renderContent($data, $event_type);
        $statusPill = $this->getStatusPill($data, $event_type);
        $theme = $this->getTheme($event_type);
        
        // Create component shell
        $toolkit = new PayloadComponentUIToolkit();
        return $toolkit->render_component_shell(
            $label,
            $theme,
            $content,
            ['status_pill' => $statusPill]
        );
    }
    
    // Abstract methods to be implemented by specific renderers
    abstract protected function renderContent(array $data, string $event_type): string;
    abstract protected function getLabel(array $data, string $event_type): string;
    abstract protected function getStatusPill(array $data, string $event_type): ?array;
    abstract protected function getTheme(string $event_type): string;
}
```

Specific renderers will then implement these methods with event_type-specific logic:

```php
class RuleRenderer extends BaseRenderer
{
    protected function renderContent(array $data, string $event_type): string
    {
        switch ($event_type) {
            case 'condition_passed':
            case 'condition_failed':
                return $this->renderConditionContent($data, $event_type);
            
            case 'rule_matched':
            case 'rule_evaluated':
                return $this->renderRuleMatchContent($data);
                
            case 'rule_no_match':
                return $this->renderRuleNoMatchContent($data);
                
            case 'action_executed':
                return $this->renderActionContent($data);
                
            case 'decision':
                return $this->renderDecisionContent($data);
                
            default:
                return $this->renderGenericRuleContent($data);
        }
    }
    
    protected function getLabel(array $data, string $event_type): string
    {
        switch ($event_type) {
            case 'condition_passed':
            case 'condition_failed':
                return $data['condition_label'] ?? 'Condition Evaluation';
                
            case 'rule_matched':
            case 'rule_evaluated':
            case 'rule_no_match':
                return $data['rule_name'] ?? 'Rule Evaluation';
                
            case 'action_executed':
                return 'Action: ' . ($data['action_id'] ?? 'Executed');
                
            case 'decision':
                return 'Decision';
                
            default:
                return 'Rule Evaluation';
        }
    }
    
    // Other method implementations...
}
```

## Registry Updates

The current `PayloadComponentRegistry.php` would be simplified to just map event_type directly to renderer class:

```php
function odcm_get_renderer_for_event_type(string $event_type): string
{
    $renderers = [
        // Direct mapping from event_type to renderer class
        // (all mappings from above)
    ];
    
    return $renderers[$event_type] ?? FallbackRenderer::class;
}
```

## RegistryTimelineRenderer Updates

The `RegistryTimelineRenderer.php` file would be simplified to use the direct mapping:

```php
private function renderComponent(array $component): string
{
    $event_type = $component['event_type'] ?? 'info';
    $data = $component['data'] ?? [];
    $label = $component['label'] ?? ucfirst($event_type);
    $ts = $component['ts'] ?? null;
    $level = $component['level'] ?? 'info';
    
    // Skip components with empty data
    if (empty($data)) {
        return '';
    }
    
    // Get renderer class directly from event_type
    $renderer_class = odcm_get_renderer_for_event_type($event_type);
    
    try {
        $renderer = new $renderer_class();
        return $renderer->render($data, $event_type);
    } catch (\Throwable $e) {
        // Log error and use fallback
        error_log("ODCM Timeline Renderer Error for {$renderer_class}: " . $e->getMessage());
        $fallback = new FallbackRenderer();
        return $fallback->render($data, $event_type);
    }
}
```

## Implementation Steps

1. **Create BaseRenderer Class**
   - Implement shared helper methods
   - Define abstract methods
   - Set up Template Method Pattern

2. **Implement Specific Renderers**
   - Create the 5 core renderer classes extending BaseRenderer
   - Implement event_type-specific rendering logic in each

3. **Update Registry**
   - Simplify PayloadComponentRegistry.php
   - Create direct event_type to renderer mapping
   - Remove redundant parent-child relationships

4. **Update RegistryTimelineRenderer**
   - Simplify component rendering process
   - Remove multi-tier lookup
   - Use direct renderer lookup from event_type

5. **Migrate Renderer Styles**
   - Update CSS classes for consistency
   - No need to ensure backward compatibility for existing styles, as the plugin has not been published yet; just don't break anything still in use!
   - Document theme mapping in code

6. **Testing**
   - Create test fixtures for all event types
   - Verify rendering of all event types
   - Compare new renderer outputs with expected designs

## Benefits of This Approach

1. **Simplicity**: Direct mapping from event_type to renderer, no complex lookup logic
2. **Maintainability**: Centralized renderer logic with clear event_type handling
3. **Consistency**: Uniform styling and structure across all event types
4. **Extensibility**: Easy to add new event types by simply adding them to the mapping
5. **Performance**: Reduced overhead from simpler lookup process
6. **Understandability**: Clear, straightforward code that's easier for developers to work with

## Potential Challenges

1. **Migration**: Need to ensure all existing events still render correctly
2. **Backward Compatibility**: NO need to maintain legacy support. Plugin is new. Let's ship it clean.
3. **Special Cases**: Some event types may require unique handling
4. **Testing**: Need comprehensive testing for all event types

## Conclusion

The consolidated renderer approach reduces the total number of renderers from 14+ to just 6 (5 specialized + 1 fallback), while improving maintainability and creating a direct mapping between event_type and renderer class. This simplification will make the system easier to understand, maintain, and extend while improving the user experience through consistent, well-designed UI components.
