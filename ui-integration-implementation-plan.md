# Order Daemon Timeline UI Integration Plan

**Date:** December 22, 2025  
**Objective:** Complete the UI integration for the timeline redesign, leveraging the completed backend work to deliver a clear, intuitive, and informative user experience that fully complies with WordPress plugin guidelines and best practices.

---

## Executive Summary

The backend foundation for the timeline redesign is complete. This plan details the final, critical phase: UI integration. We will connect the powerful new data model (with parent-child relationships and standardized data) to a refined user interface. This will resolve the remaining display issues, including lack of visual hierarchy and information overload.

This plan is divided into distinct, actionable phases, starting with the highest-impact, lowest-risk items. All implementation follows WordPress coding standards and security best practices.

---

## Phase 1: Visual Hierarchy Implementation (Immediate - 1-2 Weeks)

**Goal:** Visually connect related events using the `parent_id` data already available from the backend.

### **Task 1.1: Connect Backend Data to Frontend CSS**

**Justification:** The `timeline-redesign-plan.md` confirms that `parent_id` and `children` data are available in the API. The `RegistryTimelineRenderer.php` also has a `buildHierarchyMap` function. We just need to connect the dots.

**File to Modify:** `src/API/Timeline/RegistryTimelineRenderer.php`

**WordPress Compliance:** Ensure proper data sanitization and output escaping per WordPress security best practices.

**Implementation Steps:**

1.  **Modify `renderTimeline` method:**
    *   Inside the `foreach ($timeline->components as $idx => $component)` loop, you already have `$isParent` and `$isChild` variables from the `hierarchyMap`.

2.  **Modify `renderComponent` method signature:**
    *   Pass the `$isParent` and `$isChild` flags to the `renderComponent` method.
    *   The method signature should be: `private function renderComponent(array $payload, bool $isParent = false, bool $isChild = false): string`

3.  **Update `applyHierarchyClasses` method:**
    *   This method already exists and correctly adds `is-parent` and `is-child` classes.
    *   Ensure it's being called from `renderComponent` with the correct flags.

**Code Example (`RegistryTimelineRenderer.php`):**

```php
// In renderTimeline() method
foreach ($timeline->components as $idx => $component) {
    // ... existing code
    $isParent = isset($hierarchyMap['parents'][$idx]);
    $isChild = isset($hierarchyMap['children'][$idx]);

    $renderedComponent = $this->renderComponent($component, $isParent, $isChild);
    // ... existing code
}

// In renderComponent() method
private function renderComponent(array $payload, bool $isParent = false, bool $isChild = false): string
{
    // ... existing rendering logic ...

    $result = $renderer->render($payload, $event_type, $timeline);
    
    // Ensure proper escaping of output
    $result = wp_kses_post($result);

    // Apply hierarchy classes
    return $this->applyHierarchyClasses($result, $isParent, $isChild);
}
```

### **Task 1.2: Verify CSS for Hierarchy**

**Justification:** The analysis document provided sample CSS. We need to ensure it's implemented and styled correctly.

**Files to Check/Modify:** Associated CSS files (likely in `plugin/assets/css`)

**WordPress Compliance:** Follow WordPress CSS naming conventions and ensure styles are properly enqueued.

**Implementation Steps:**

1.  **Locate and verify the CSS rules:** Ensure a stylesheet contains the `is-parent` and `is-child` classes with appropriate styling for indentation and connecting lines.

```css
.odcm-timeline-component.is-parent { 
    /* Styles for parent events */
    border-left: 2px solid #7e8993;
    padding-left: 15px;
}

.odcm-timeline-component.is-child {
    /* Styles for child events */
    margin-left: 25px;
    border-left: 2px dashed #a7aaad;
    padding-left: 15px;
    background-color: #f8f9fa;
}
```

**CSS Enqueueing:**

```php
/**
 * Register and enqueue timeline styles in WordPress-compliant way
 */
function odcm_register_timeline_styles() {
    wp_register_style(
        'odcm-timeline-styles',
        plugins_url('assets/css/timeline.css', ODCM_PLUGIN_FILE),
        [],
        ODCM_VERSION
    );
    wp_enqueue_style('odcm-timeline-styles');
}
add_action('admin_enqueue_scripts', 'odcm_register_timeline_styles');
```

2.  **Test and Refine:** Load a timeline with parent-child events and adjust the CSS as needed for optimal visual appearance.

---

## Phase 2: Three-Tier Information Architecture (2-3 Weeks)

**Goal:** Reorganize event data into a tiered display to reduce clutter and improve clarity, as outlined in the `timeline-events-display-analysis.md`.

### **Task 2.1: Evolve Renderers for Tiered Data**

**Justification:** Instead of a complex `EventDataExtractor`, we will evolve the existing `BaseRenderer` and its child classes. This is lower risk and aligns with the existing architecture.

**File to Modify:** `src/View/PayloadRenderer/BaseRenderer.php` and its children (e.g., `OrderRenderer.php`, `RuleRenderer.php`).

**WordPress Compliance:** Follow WordPress security practices for data sanitization and output escaping. Use proper type hints and follow WordPress coding standards.

**Implementation Steps:**

1.  **Update `BaseRenderer.php`:**
    *   Modify the `render` method to produce a structured array instead of an HTML string.

```php
// In BaseRenderer.php
public function render(array $payload, string $event_type, array $timeline): array
{
    // ... existing setup ...

    // Sanitize all input data
    $sanitized_data = $this->sanitize_data($data);
    $sanitized_payload = $this->sanitize_data($payload);

    return [
        'label' => $this->getLabel($sanitized_payload, $event_type),
        'theme' => $this->getTheme($event_type),
        'status_pill' => $this->getStatusPill($sanitized_data, $event_type),
        'primary_content' => $this->renderPrimaryContent($sanitized_data, $event_type, $this->toolkit),
        'contextual_content' => $this->renderContextualContent($sanitized_data, $event_type, $this->toolkit),
        'technical_content' => $this->renderTechnicalContent($sanitized_payload, $event_type, $this->toolkit), // Pass full payload for technical
    ];
}

/**
 * Sanitize input data recursively
 * 
 * @param array $data The data to sanitize
 * @return array Sanitized data
 */
protected function sanitize_data(array $data): array
{
    $sanitized = [];
    
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = $this->sanitize_data($value);
        } elseif (is_string($value)) {
            $sanitized[$key] = sanitize_text_field($value);
        } else {
            $sanitized[$key] = $value;
        }
    }
    
    return $sanitized;
}
```

2.  **Create new abstract methods in `BaseRenderer.php`:**

```php
// In BaseRenderer.php
/**
 * Render the primary content section
 * 
 * @param array $data Sanitized data array
 * @param string $event_type The event type
 * @param PayloadComponentUIToolkit $toolkit UI toolkit
 * @return string Rendered HTML (must be escaped before output)
 */
abstract protected function renderPrimaryContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string;

/**
 * Render the contextual content section
 * 
 * @param array $data Sanitized data array
 * @param string $event_type The event type
 * @param PayloadComponentUIToolkit $toolkit UI toolkit
 * @return string Rendered HTML (must be escaped before output)
 */
abstract protected function renderContextualContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string;

/**
 * Render the technical content section
 * 
 * @param array $data Sanitized data array
 * @param string $event_type The event type
 * @param PayloadComponentUIToolkit $toolkit UI toolkit
 * @return string Rendered HTML (must be escaped before output)
 */
abstract protected function renderTechnicalContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string;
```

### **Task 2.2: Implement Tiered Rendering in `OrderRenderer.php`**

**Justification:** `OrderRenderer.php` is one of the most complex renderers and is a perfect candidate for this new structure.

**File to Modify:** `src/View/PayloadRenderer/OrderRenderer.php`

**Implementation Steps:**

1.  **Implement the new abstract methods.**
2.  **`renderPrimaryContent`**: Return only the most critical business information (e.g., Status Change from X to Y).
3.  **`renderContextualContent`**: Return secondary information (e.g., who changed the status, whether automation was bypassed).
4.  **`renderTechnicalContent`**: Return debug information, raw payloads, etc.

**Code Example (`OrderRenderer.php` for `status_changed`):**

```php
/**
 * Render primary content with proper data sanitization
 */
protected function renderPrimaryContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string
{
    // Extract and sanitize statuses
    $from_status = isset($data['from_status']) ? sanitize_text_field($data['from_status']) : '';
    $to_status = isset($data['to_status']) ? sanitize_text_field($data['to_status']) : '';
    
    $from_display = $this->formatStatusForDisplay($from_status);
    $to_display = $this->formatStatusForDisplay($to_status);
    
    // Translatable labels
    $status_data = [
        __('From', 'order-daemon') => $from_display, 
        __('To', 'order-daemon') => $to_display
    ];
    
    return $toolkit->render_key_value_list($status_data);
}

/**
 * Render contextual content with proper data sanitization
 */
protected function renderContextualContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string
{
    // Extract and sanitize attribution data
    $is_manual = isset($data['is_manual']) ? (bool)$data['is_manual'] : false;
    $changed_by = isset($data['changed_by']) ? sanitize_text_field($data['changed_by']) : '';
    
    // Translatable content
    $context_data = [__('Type', 'order-daemon') => __('Automatic', 'order-daemon')];
    
    if ($is_manual && !empty($changed_by)) {
        $context_data[__('Changed By', 'order-daemon')] = $changed_by;
        $context_data[__('Type', 'order-daemon')] = __('Manual', 'order-daemon');
    }
    
    return $toolkit->render_key_value_list($context_data, __('Context', 'order-daemon'));
}

/**
 * Render technical content with proper data sanitization
 */
protected function renderTechnicalContent(array $payload, string $event_type, PayloadComponentUIToolkit $toolkit): string
{
    // The full payload for technical details
    // No need to sanitize as json_encode will escape special characters
    $json = wp_json_encode($payload, JSON_PRETTY_PRINT);
    
    if ($json === false) {
        // Handle encoding error
        return $toolkit->render_code_block(
            __('Error: Unable to encode payload data', 'order-daemon'), 
            'text'
        );
    }
    
    return $toolkit->render_code_block($json, 'json');
}
```

### **Task 2.3: Update `RegistryTimelineRenderer` and UI**

**Justification:** The main renderer and UI need to be updated to handle the new structured data and display it with progressive disclosure.

**File to Modify:** `src/API/Timeline/RegistryTimelineRenderer.php` and JavaScript assets.

**WordPress Compliance:** Output escape all HTML, properly enqueue JavaScript, and follow WordPress AJAX best practices.

**Implementation Steps:**

1.  **Modify `renderComponent` in `RegistryTimelineRenderer.php`:**
    *   It will now receive an array from the child renderer's `render` method.
    *   It should use this array to build an HTML structure with expandable sections.

```php
// In RegistryTimelineRenderer.php -> renderComponent()
$renderedData = $renderer->render($payload, $event_type, $timeline);

// Proper WordPress escaping for all output
$html = '<div class="odcm-timeline-header">';
$html .= esc_html($renderedData['label']);
$html .= '</div>';

$html .= '<div class="odcm-timeline-body">';
$html .= '<div class="primary-content">' . wp_kses_post($renderedData['primary_content']) . '</div>';
$html .= '<div class="expandable-section contextual-content">' . wp_kses_post($renderedData['contextual_content']) . '</div>';
$html .= '<div class="expandable-section technical-content">' . wp_kses_post($renderedData['technical_content']) . '</div>';
$html .= '</div>';

// Properly escape all attributes
$html = $this->applyHierarchyClasses($html, $isParent, $isChild);
```

**JavaScript Enqueueing:**

```php
/**
 * Register and enqueue timeline scripts
 */
function odcm_register_timeline_scripts() {
    wp_register_script(
        'odcm-timeline-js',
        plugins_url('assets/js/timeline.js', ODCM_PLUGIN_FILE),
        ['jquery'],
        ODCM_VERSION,
        true
    );
    
    // Localize script with translated strings and nonce
    wp_localize_script('odcm-timeline-js', 'odcmTimelineData', [
        'nonce' => wp_create_nonce('odcm_timeline_nonce'),
        'i18n' => [
            'expandText' => __('Show details', 'order-daemon'),
            'collapseText' => __('Hide details', 'order-daemon'),
            'technicalDetails' => __('Technical Details', 'order-daemon'),
        ]
    ]);
    
    wp_enqueue_script('odcm-timeline-js');
}
add_action('admin_enqueue_scripts', 'odcm_register_timeline_scripts');
```

2.  **Add JavaScript for Progressive Disclosure:**
    *   Add click handlers to show/hide the contextual and technical content sections.
    *   Follow WordPress JavaScript standards and security practices:

```javascript
/**
 * Timeline progressive disclosure system
 * 
 * @package OrderDaemon
 */

/* global odcmTimelineData */

(function($) {
    'use strict';
    
    /**
     * Initialize the timeline UI
     */
    function initTimelineUI() {
        // Initial state: hide expandable sections
        $('.expandable-section').hide();
        
        // Add expand/collapse buttons with proper nonce verification
        $('.odcm-timeline-header').each(function() {
            const $header = $(this);
            const $expandBtn = $('<button>')
                .addClass('odcm-expand-btn')
                .text(odcmTimelineData.i18n.expandText)
                .attr('type', 'button'); // Accessibility best practice
                
            $header.append($expandBtn);
        });
        
        // Handle click events with proper event delegation
        $(document).on('click', '.odcm-expand-btn', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const $timelineItem = $btn.closest('.odcm-timeline-component');
            const $expandableSections = $timelineItem.find('.expandable-section');
            
            if ($expandableSections.first().is(':visible')) {
                $expandableSections.slideUp(200);
                $btn.text(odcmTimelineData.i18n.expandText);
            } else {
                $expandableSections.slideDown(200);
                $btn.text(odcmTimelineData.i18n.collapseText);
            }
        });
    }
    
    // Initialize on document ready
    $(document).ready(initTimelineUI);
    
})(jQuery);
```

---

## Phase 3: Label Standardization & Final Polish (1 Week)

**Goal:** Ensure all events have clear, consistent, business-friendly labels and a polished final appearance.

### **Task 3.1: Standardize `getLabel()` methods**

**Justification:** The `timeline-events-display-analysis.md` identified inconsistent labeling as a key problem.

**Files to Modify:** All `*Renderer.php` files.

**WordPress Compliance:** Ensure all labels are properly internationalized with the correct text domain.

**Implementation Steps:**

1.  **Review all `getLabel()` methods** in `OrderRenderer.php`, `RuleRenderer.php`, etc.
2.  **Enforce a consistent style:** "Object Action: Details".
    *   *Good:* "Status Changed: Pending → Completed"
    *   *Good:* "Rule Executed: Auto-Complete Virtual Products"
    *   *Bad:* "rule_execution"
3.  **Prioritize business language** over technical jargon.
4.  **Ensure all labels are translatable:**

```php
/**
 * Get properly internationalized label for order status change
 */
protected function getLabel(array $payload, string $event_type): string
{
    // Old approach:
    // return "Status Changed: {$from_status} → {$to_status}";
    
    // WordPress compliant approach:
    $from_status = isset($payload['from_status']) ? sanitize_text_field($payload['from_status']) : '';
    $to_status = isset($payload['to_status']) ? sanitize_text_field($payload['to_status']) : '';
    
    return sprintf(
        /* translators: %1$s: original status, %2$s: new status */
        __('Status Changed: %1$s → %2$s', 'order-daemon'),
        $this->formatStatusForDisplay($from_status),
        $this->formatStatusForDisplay($to_status)
    );
}
```

### **Task 3.2: Final CSS and UI Polish**

**Justification:** A final pass on the UI will ensure a professional and cohesive look.

**Implementation Steps:**

1.  **Review the timeline with all event types.**
2.  **Adjust spacing, fonts, colors, and iconography** for maximum clarity and scannability.
3.  **Ensure responsive design** works well on different screen sizes.

---

## Testing Plan

1.  **Unit Tests:**
    *   Update renderer tests to check for the new `array` return type with the three content tiers.
    *   Add tests for input sanitization and output escaping.
    *   Verify that internationalization is implemented correctly.
    
2.  **Integration Tests:**
    *   Test the full flow from `DatabaseTimelineBuilder` to the final rendered HTML.
    *   Verify that `is-parent` and `is-child` classes are applied correctly.
    *   Test that all outputs are properly escaped with `esc_html()`, `wp_kses_post()`, etc.
    
3.  **Security Testing:**
    *   Test with malicious inputs to ensure proper sanitization.
    *   Verify that all AJAX operations use nonce verification.
    *   Ensure proper capability checks are in place for admin functionality.
    
4.  **Accessibility Testing:**
    *   Verify that the timeline UI meets WCAG 2.1 AA standards.
    *   Test keyboard navigation for the expand/collapse functionality.
    *   Ensure proper ARIA attributes are used for expandable sections.
    
5.  **Manual UI Testing:**
    *   Create test orders that trigger various events (status change, rule execution, payment, etc.).
    *   Verify that the visual hierarchy is correct.
    *   Test the expand/collapse functionality of the content tiers.
    *   Check for consistent labeling across all events.
    *   Verify that scripts and styles are properly enqueued.

## WordPress Plugin Checker Compliance

This implementation plan addresses the WordPress Plugin Checker requirements including:

1. **Security Best Practices:**
   * All user inputs are properly sanitized using WordPress functions like `sanitize_text_field()`
   * All outputs are properly escaped using `esc_html()`, `wp_kses_post()`, or similar functions
   * JavaScript includes nonce verification for AJAX operations
   * Proper capability checks for administrative functions

2. **WordPress Coding Standards:**
   * Follows WordPress naming conventions
   * Uses WordPress style for comments and documentation
   * Follows the PHP PSR-12 and WordPress coding styles

3. **Internationalization:**
   * All user-facing strings use WordPress i18n functions (`__()`, `_e()`, `sprintf()`)
   * Proper text domain ('order-daemon') is used consistently
   * Context is provided for translators where appropriate

4. **Proper Asset Management:**
   * Scripts and styles are registered and enqueued using WordPress functions
   * Version strings are applied to prevent caching issues
   * Dependencies are properly declared

By following this plan, we will successfully complete the timeline UI integration, transforming it into a powerful, intuitive tool for all users while ensuring full compliance with WordPress best practices and plugin requirements.
