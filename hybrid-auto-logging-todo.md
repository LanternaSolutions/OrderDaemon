# Hybrid Audit Logging Implementation Plan

## **Architectural Overview**

Implement a two-tier logging system that provides focused production logging with optional debug verbosity:

**Production Mode (Default):**
- Log rule matches and actions taken
- Log plugin management activities
- Log system events and errors

**Debug Mode (ODCM_DEBUG=true):**
- ALSO log rule evaluations that don't match
- ALSO log status changes being evaluated
- ALSO log detailed attribution tracking

## **Implementation Plan**

### **Phase 1: Remove Temporary Fix**
```php
// Delete the catch-all rule created as temporary fix
// Rule #48: "Audit Logging Rule (Catch All Status Changes)"
wp_delete_post(48, true);
```

### **Phase 2: Core Logic Refactoring**

**File: `src/Core/Core.php`**

**Current Problem:**
```php
public function handle_general_order_status_change(...) {
    // This blocks ALL logging when no rules match
    if (!$this->should_trigger_any_status_change_rules($from_slug, $to_slug)) {
        return; // ← BLOCKS LOGGING
    }
}
```

**New Implementation:**
```php
public function handle_general_order_status_change(int $order_id, string $from_status, string $to_status, $order): void {
    if ($order_id <= 0) return;
    
    $from_slug = sanitize_key($from_status);
    $to_slug = sanitize_key($to_status);
    
    // ALWAYS log in debug mode
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        $this->log_status_change_evaluation($order_id, $from_slug, $to_slug);
    }
    
    // Check if rules should be triggered
    $matching_rules = $this->get_matching_rules_for_status_change($from_slug, $to_slug);
    
    if (empty($matching_rules)) {
        // No rules match - log only in debug mode
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->log_no_rules_matched($order_id, $from_slug, $to_slug);
        }
        return; // Exit early - no rule processing needed
    }
    
    // Rules match - ALWAYS log this (production + debug)
    $this->log_rule_evaluation_started($order_id, $from_slug, $to_slug, $matching_rules);
    
    // Process rules and log results
    foreach ($matching_rules as $rule) {
        $result = $this->evaluate_rule_for_order($rule, $order);
        $this->log_rule_evaluation_result($order_id, $rule, $result);
    }
    
    // Continue with existing rule processing logic...
}
```

### **Phase 3: New Logging Methods**

**Add these methods to `Core.php`:**

```php
private function log_status_change_evaluation(int $order_id, string $from, string $to): void {
    odcm_log_event(
        "Status change evaluation: Order #{$order_id} ({$from} → {$to})",
        ['from' => $from, 'to' => $to, 'debug_mode' => true],
        $order_id,
        'info',
        'status_evaluation'
    );
}

private function log_no_rules_matched(int $order_id, string $from, string $to): void {
    odcm_log_event(
        "No rules matched for Order #{$order_id} status change ({$from} → {$to})",
        ['from' => $from, 'to' => $to, 'rules_checked' => $this->count_active_rules()],
        $order_id,
        'info',
        'no_rules_matched'
    );
}

private function log_rule_evaluation_started(int $order_id, string $from, string $to, array $rules): void {
    odcm_log_event(
        "Evaluating " . count($rules) . " rule(s) for Order #{$order_id}",
        [
            'from' => $from, 
            'to' => $to, 
            'rule_count' => count($rules),
            'rule_ids' => array_column($rules, 'id')
        ],
        $order_id,
        'info',
        'rule_evaluation_started'
    );
}

private function log_rule_evaluation_result(int $order_id, array $rule, array $result): void {
    $status = $result['matched'] ? 'success' : 'info';
    $action = $result['matched'] ? 'matched and executed' : 'evaluated but did not match';
    
    odcm_log_event(
        "Rule '{$rule['name']}' {$action} for Order #{$order_id}",
        [
            'rule_id' => $rule['id'],
            'rule_name' => $rule['name'],
            'matched' => $result['matched'],
            'conditions_met' => $result['conditions_met'] ?? null,
            'actions_taken' => $result['actions_taken'] ?? []
        ],
        $order_id,
        $status,
        'rule_evaluation_result'
    );
}
```

### **Phase 4: Enhanced Rule Matching Logic**

**Replace `should_trigger_any_status_change_rules()` with:**

```php
private function get_matching_rules_for_status_change(string $from_slug, string $to_slug): array {
    $rules = get_posts([
        'post_type' => 'odcm_order_rule',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_odcm_rule_active',
                'value' => '1',
                'compare' => '='
            ]
        ]
    ]);
    
    $matching_rules = [];
    
    foreach ($rules as $rule) {
        $rule_data = json_decode(get_post_meta($rule->ID, '_odcm_rule_data', true), true);
        
        if ($this->rule_matches_status_change($rule_data, $from_slug, $to_slug)) {
            $matching_rules[] = [
                'id' => $rule->ID,
                'name' => $rule->post_title,
                'data' => $rule_data
            ];
        }
    }
    
    return $matching_rules;
}

private function rule_matches_status_change(array $rule_data, string $from, string $to): bool {
    $trigger = $rule_data['trigger'] ?? [];
    $trigger_id = $trigger['id'] ?? '';
    
    // Check different trigger types
    switch ($trigger_id) {
        case 'order_status_any_change':
            return $this->evaluate_any_status_change_trigger($trigger, $from, $to);
        case 'order_processing':
            return $to === 'processing';
        case 'order_completed':
            return $to === 'completed';
        // Add other trigger types...
        default:
            return false;
    }
}
```

### **Phase 5: UI Enhancements**

**File: `src/View/DashboardComponents/LogStreamRenderer.php`**

Add debug mode indicator and filtering:

```php
public function render_log_entry($log) {
    $is_debug = ($log['event_type'] === 'status_evaluation' || 
                 $log['event_type'] === 'no_rules_matched');
    
    $class = $is_debug ? 'log-entry debug-entry' : 'log-entry';
    
    echo "<div class='{$class}' data-debug='{$is_debug}'>";
    // ... existing rendering logic
    echo "</div>";
}

public function render_debug_toggle() {
    echo '<label class="debug-toggle">
        <input type="checkbox" id="show-debug-logs" />
        Show Debug Entries
    </label>';
}
```

**File: `assets/css/insight-dashboard.css`**

```css
.log-entry.debug-entry {
    opacity: 0.7;
    border-left: 3px solid #666;
}

.debug-toggle {
    margin-bottom: 10px;
    font-size: 12px;
}

/* Hide debug entries by default */
.log-entry.debug-entry {
    display: none;
}

/* Show when toggle is checked */
body.show-debug .log-entry.debug-entry {
    display: block;
}
```

### **Phase 6: Configuration Options**

**File: `src/Admin/Admin.php`**

Add debug logging option to settings:

```php
public function render_debug_logging_setting() {
    $enabled = defined('ODCM_DEBUG') && ODCM_DEBUG;
    
    echo '<label>';
    echo '<input type="checkbox" name="odcm_debug_logging" value="1" ' . checked(1, $enabled, false) . ' />';
    echo ' Enable debug logging (shows rule evaluations for all orders)';
    echo '</label>';
    echo '<p class="description">When enabled, logs all rule evaluations, even for orders that don\'t match any rules. Useful for debugging but increases log volume.</p>';
}
```

### **Phase 7: Testing Strategy**

1. **Create test orders with different statuses**
2. **Verify production mode only logs rule matches**
3. **Enable debug mode and verify evaluation logging**
4. **Test UI toggle for debug entry visibility**
5. **Verify Action Scheduler automation still works**
6. **Performance test with high order volume**

### **Phase 8: Migration from Temporary Fix**

1. **Remove catch-all rule created as temporary fix**
2. **Clear any backlog of unnecessary audit entries**
3. **Test with existing rules to ensure they still work**
4. **Verify Orders #44, #45, #47 show up correctly**

## **Expected Outcomes**

**Production Mode:**
- Clean, focused audit log showing only meaningful plugin activity
- Minimal database growth
- Clear visibility into what rules are doing

**Debug Mode:**
- Full visibility into rule evaluation process
- Ability to debug "why didn't this order trigger anything?"
- Detailed attribution and timing information

**UI Experience:**
- Professional audit log by default
- Optional debug visibility for troubleshooting
- Clear distinction between production and debug entries

This approach provides the best of both worlds: focused production logging with comprehensive debug capabilities when needed.