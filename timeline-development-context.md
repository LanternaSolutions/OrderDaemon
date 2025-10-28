# Timeline Development Context - Developer Guide

## Quick Start for New Coding Agents

This document provides essential context for implementing the timeline optimizations outlined in `order-74-timeline-analysis.md`. It covers the codebase architecture, development environment, and key implementation patterns.

## 🐳 Docker Environment

### Container Setup
```bash
# Main WordPress container
Container: order-daemon-devtools-wordpress-1

# Cron / WP-CLI container:
Container: order-daemon-devtools-cron-1

# Execute PHP scripts in container
docker exec -i order-daemon-devtools-wordpress-1 php /var/www/html/wp-content/plugins/order-daemon-core/[script].php

# Access WordPress admin
URL: http://localhost:8080/wp-admin
```

### Development Workflow
```bash
# 1. Make code changes in local files
# 2. Test immediately (files are mounted, no rebuild needed)
# 3. Run debug scripts to verify changes
# 4. Check timeline in WordPress admin
```

## 🏗️ Timeline Architecture Overview

### Data Flow Pipeline
```
1. Event Occurs → UniversalEventProcessor.php
2. Data Logged → odcm_log_event() in functions.php  
3. Database Storage → wp_odcm_audit_log + wp_odcm_audit_log_payloads
4. Data Retrieval → DatabaseTimelineBuilder.php
5. Component Extraction → ProcessLoggerComponentExtractor.php
6. Rendering → RegistryTimelineRenderer.php → Specific Renderers
7. UI Display → PayloadComponentUIToolkit.php
```

### Key Directories
```
src/
├── API/Timeline/          # Timeline data processing
├── View/PayloadRenderer/  # Event rendering classes  
├── Core/Events/          # Event processing logic
├── Includes/             # Global functions (odcm_log_event)
└── Core/Logging/         # ProcessLogger utilities
```

## 📁 Critical Files for Timeline Work

### 1. Event Processing
```php
// Entry point for all events
src/Core/Events/UniversalEventProcessor.php
- processEvent() - Main event processing
- logProcessingResult() - Creates timeline entries
- Key: Preserves rawData in $payload_for_storage

// Global logging function  
src/Includes/functions.php
- odcm_log_event() - Creates envelope structure
- Key: Preserves top-level rawData in envelope
```

### 2. Data Retrieval & Extraction
```php
// Fetches events from database
src/API/Timeline/DatabaseTimelineBuilder.php
- fetchLogEntry() - Gets single event with payload join
- buildIndividualTimeline() - Processes single events

// Extracts components from payloads
src/API/Timeline/ProcessLoggerComponentExtractor.php  
- extractComponents() - Main extraction entry point
- normalizeComponent() - Injects rawData into components
- Key: Checks both top-level and component-level rawData
```

### 3. Rendering System
```php
// Routes events to specific renderers
src/API/Timeline/RegistryTimelineRenderer.php
- render() - Main rendering coordinator

// Handles order/checkout events
src/View/PayloadRenderer/OrderRenderer.php
- renderBlockCheckout() - ✅ Working correctly
- renderStatusChange() - ❌ Needs "Unknown" status fix
- Key: Uses renderSpecificContent() template method

// Handles payment events  
src/View/PayloadRenderer/PaymentRenderer.php
- ❌ Missing transaction data extraction
- ❌ Needs rawData.payment_context parsing

// Handles system events
src/View/PayloadRenderer/SystemRenderer.php
- ❌ Shows "Array" instead of data
- ❌ Needs debug event filtering

// UI components and expandable sections
src/View/PayloadRenderer/PayloadComponentUIToolkit.php
- render_expandable_key_value_section() - ✅ Working
- render_key_value_list() - ✅ Working
```

## 🔍 Database Structure

### Main Tables
```sql
-- Event metadata
wp_odcm_audit_log
- log_id (PK)
- order_id, event_type, summary, status
- payload_id (FK to payloads table)
- process_id (groups related events)

-- Event payloads (JSON data)
wp_odcm_audit_log_payloads  
- payload_id (PK)
- payload (JSON with rawData)
```

### Data Extraction Pattern
```php
// Standard pattern for getting event with payload
$sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
               COALESCE(p.payload, l.details, '') as payload 
        FROM {$logTable} l 
        LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id
        WHERE l.log_id = %d";
```

## 🛠️ Development Patterns

### 1. Adding New Renderer Logic
```php
// In specific renderer (e.g., PaymentRenderer.php)
protected function renderSpecificContent(array $payload, string $event_type, PayloadComponentUIToolkit $toolkit): string
{
    $data = $payload['data'] ?? $payload;
    $rawData = $payload['rawData'] ?? [];
    
    // Extract business data for main display
    $payment_data = [
        'Amount' => $this->formatAmount($rawData),
        'Transaction ID' => $rawData['transaction_id'] ?? '',
        'Gateway' => $rawData['gateway'] ?? '',
    ];
    
    $content = $toolkit->render_key_value_list($payment_data, 'Payment Details');
    
    // Add expandable technical details
    if (!empty($rawData)) {
        $content .= $toolkit->render_expandable_key_value_section('Technical Details', $rawData);
    }
    
    return $content;
}
```

### 2. Debug Event Filtering
```php
// Hide debug events unless ODCM_DEBUG is true
if ($this->isDebugEvent($data) && (!defined('ODCM_DEBUG') || !ODCM_DEBUG)) {
    return ''; // Don't render debug events in production
}

private function isDebugEvent(array $data): bool 
{
    $debug_event_types = [
        'order_created',
        'order_check_scheduled', 
        'order_loaded',
        'attribution_context',
        'automation_bypass_context'
    ];
    
    return in_array($data['event_type'] ?? '', $debug_event_types);
}
```

### 3. Preventing Event Duplication
```php
// In UniversalEventProcessor.php - only log when not duplicate
if (!$this->isDuplicateEvent($universal_event)) {
    $this->logProcessingResult($context, $result, $execution_time, $process_id);
}

// Check idempotency keys or correlation IDs
private function isDuplicateEvent(UniversalEvent $event): bool
{
    // Implementation depends on event type and context
}
```

## 🧪 Testing & Debugging

### 1. Debug Scripts (Already Created)
```bash
# Examine specific events with rawData
docker exec -i order-daemon-devtools-wordpress-1 php /var/www/html/wp-content/plugins/order-daemon-core/examine-rawdata-event.php

# Check database payload structures
docker exec -i order-daemon-devtools-wordpress-1 php /var/www/html/wp-content/plugins/order-daemon-core/debug-real-payloads.php

# Find events containing rawData  
docker exec -i order-daemon-devtools-wordpress-1 php /var/www/html/wp-content/plugins/order-daemon-core/find-rawdata-events.php
```

### 2. Creating Test Orders
```bash
# Access WordPress admin to place test orders
URL: http://localhost:8080/wp-admin

# Timeline visible at:
WooCommerce → Orders → [Order] → Order Timeline tab
```

### 3. Debug Logging Patterns
```php
// Add debug logging in renderers
if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
    error_log("ODCM DEBUG - PaymentRenderer: rawData keys: " . implode(', ', array_keys($rawData)));
}

// Check extracted data
error_log("ODCM DEBUG - Component extraction: " . json_encode($normalizedComponent));
```

### 4. Component Extraction Testing
```php
// Test component extraction in isolation
$extractor = new ProcessLoggerComponentExtractor();
$components = $extractor->extractComponents($payloadData, true);

foreach ($components as $component) {
    echo "Event type: " . ($component['event_type'] ?? 'MISSING') . "\n";
    echo "Has rawData: " . (isset($component['rawData']) ? 'YES' : 'NO') . "\n";
}
```

## 🎯 Implementation Priorities (From Analysis)

### Phase 1: Critical Renderer Fixes
```php
// 1. Fix PaymentRenderer - Extract payment data
// File: src/View/PayloadRenderer/PaymentRenderer.php
// Goal: Show transaction ID, amount, gateway from rawData

// 2. Fix OrderRenderer - Previous status detection  
// File: src/View/PayloadRenderer/OrderRenderer.php
// Goal: Replace "Unknown" with actual previous status

// 3. Add debug event filtering
// Files: All renderer classes
// Goal: Hide debug events unless ODCM_DEBUG is true
```

### Phase 2: Event Deduplication
```php
// 1. Add deduplication logic to UniversalEventProcessor
// File: src/Core/Events/UniversalEventProcessor.php
// Goal: Prevent duplicate payment/status events

// 2. Improve correlation ID tracking
// File: src/Includes/functions.php  
// Goal: Better process grouping and duplicate detection
```

### Phase 3: UX Enhancements
```php
// 1. Add expandable sections to all business events
// Files: All renderer classes
// Goal: Rich context data available on demand

// 2. Implement debug events toggle in UI
// Files: Admin dashboard components
// Goal: User-controlled debug event visibility
```

## 🚨 Common Pitfalls

### 1. rawData Location Issues
```php
// ❌ Wrong - rawData might be nested
$rawData = $payload['rawData'];

// ✅ Correct - Check multiple locations
$rawData = $payload['rawData'] ?? $payload['data']['rawData'] ?? [];
```

### 2. Missing Event Type Checks
```php
// ❌ Wrong - Assumes event type exists
switch ($event_type) {

// ✅ Correct - Validate first
if (empty($event_type) && isset($data['event_type'])) {
    $event_type = $data['event_type'];
}
```

### 3. Poor Error Handling
```php
// ❌ Wrong - Can break timeline rendering
return $this->renderPaymentData($rawData['payment']);

// ✅ Correct - Graceful degradation
if (isset($rawData['payment']) && is_array($rawData['payment'])) {
    return $this->renderPaymentData($rawData['payment']);
}
return $this->renderFallbackContent($data);
```

## 📝 Code Style Guidelines

### Renderer Method Pattern
```php
protected function renderSpecificContent(array $payload, string $event_type, PayloadComponentUIToolkit $toolkit): string
{
    // 1. Extract data safely
    $data = $payload['data'] ?? $payload;
    $rawData = $payload['rawData'] ?? [];
    
    // 2. Build business-focused display
    $main_data = $this->extractBusinessData($data, $rawData);
    $content = $toolkit->render_key_value_list($main_data, $this->getEventLabel($event_type));
    
    // 3. Add expandable technical details
    if (!empty($rawData)) {
        $content .= $toolkit->render_expandable_key_value_section('Technical Details', $rawData);
    }
    
    return $content;
}
```

### Logging Pattern
```php
// Always use debug guards for verbose logging
if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
    error_log("ODCM DEBUG - RendererName: Specific debug message");
}
```

This development context provides everything needed to implement the timeline optimizations efficiently while following established patterns and avoiding common pitfalls.
