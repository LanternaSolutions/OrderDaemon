# Order Daemon Core: Completion Rules Architecture Implementation

## **Overview**

The Order Daemon for WooCommerce plugin has completion rules that need a clean separation between **WordPress admin UI** and **high-performance rule evaluation**. Currently, rules exist as Custom Post Types but the rule evaluation system expects them in a dedicated database table for optimal performance. The disconnect between these systems is causing completion events to not appear in the insight dashboard.

## **Current System State**

### **✅ Working Components**
- **Checkout functionality**: Fully operational, no blocking issues
- **Action Scheduler**: Background tasks executing successfully  
- **Logging pipeline**: ProcessLogger working, audit log entries being created
- **Frontend rule management**: Users can create/edit completion rules via WordPress admin
- **Rule evaluation system**: UniversalEventProcessor exists and functions

### **❌ Missing Components**
- **Database table**: `wp_odcm_completion_rules` table doesn't exist
- **Proper data separation**: CPT meta holds functional data instead of DB table
- **Rule evaluation**: UniversalEventProcessor can't load rules from missing DB table

### **Database Current State**
```sql
-- Existing tables:
wp_odcm_audit_log              (0 rows - explains empty dashboard)
wp_odcm_audit_log_payloads     (9022 rows - some logging occurred)

-- Missing table:
wp_odcm_completion_rules       (MISSING - core issue)
```

## **Architecture Decision: Clean Separation of Concerns**

We will implement a **clean architecture** that separates WordPress UI concerns from functional automation concerns:

1. **CPT as WordPress Shell** - Minimal data for native WordPress admin integration
2. **Database Table as Functional Engine** - All rule logic and performance-critical data
3. **Direct Save Mechanism** - Admin UI metabox saves directly to DB table (no sync)
4. **Single Source of Truth** - Each data element lives in exactly one optimal location

### **Data Separation Strategy**

**CPT Layer (WordPress Admin Shell):**
- `post_title` → Rule display name for admin lists
- `post_status` → Enable/disable rule in WordPress UI
- `post_date` → Created/modified timestamps for WordPress features
- `rule_id` meta → Primary key reference to functional DB table
- **That's it!** - No functional rule data in CPT

**Database Table (Functional Engine):**
- `rule_id` (PK) → Links back to CPT post ID  
- `trigger_type` → When to evaluate rule (performance filtering)
- `conditions` → JSON rule logic for order matching
- `actions` → JSON actions to execute on match
- `priority` → Execution order for multiple matching rules
- `status` → Active/inactive (functional status, not WordPress status)
- All automation and performance-critical data

### **Benefits**
- 🚀 **Performance**: Fast SQL-based rule evaluation with optimized indexes
- 👤 **Usability**: Native WordPress admin interface (list views, permissions, etc.)
- 📈 **Scalability**: Rule evaluation performance independent of WordPress overhead
- 🔧 **WordPress Integration**: Leverages built-in features without performance penalty
- ⚡ **No Sync Complexity**: Each data element has exactly one home
- 🎯 **Clean Architecture**: UI concerns completely separated from business logic

## **Technical Context**

### **Key Files & Classes**
- **Rule Processing**: `src/Core/Events/UniversalEventProcessor.php`
- **Action Handlers**: `src/Includes/actions.php` (contains `odcm_handle_order_check_processing`)
- **Database Installer**: `src/Includes/Installer.php`  
- **Core Logic**: `src/Core/Core.php`

### **Current Processing Flow**
1. Order placed → Action Scheduler creates `odcm_process_order_check` task
2. `odcm_handle_order_check_processing()` processes order through `UniversalEventProcessor`
3. **UniversalEventProcessor tries to load rules from database table (MISSING)**
4. No rules found → No events generated → Empty insight dashboard

### **CPT Details**
- Completion rules exist as Custom Post Types visible in WordPress admin
- Exact CPT slug needs to be identified (likely `completion_rule` or similar)
- Rules likely stored in `wp_posts` table with specific `post_type`

## **Implementation Tasks**

### **Phase 0: Quick Database Table Creation (Dev Environment)**

**Objective**: Enable immediate table creation via plugin deactivation → reactivation for dev testing.

**Implementation Steps:**

1. **Edit `src/Includes/Installer.php`**:
   - Add the completion rules table creation to the `install()` method
   - Ensure the table is created during plugin activation
   - Add proper error handling and version checking

2. **Database Schema (Hybrid Approach - JSON + Indexing)**:
```sql
CREATE TABLE wp_odcm_completion_rules (
    rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    cpt_post_id bigint(20) unsigned NOT NULL,
    name varchar(255) NOT NULL,
    trigger_types varchar(500) NOT NULL, -- Comma-separated for fast filtering
    conditions JSON NOT NULL,
    actions JSON NOT NULL,
    priority int(11) NOT NULL DEFAULT 10,
    status enum('active','inactive') NOT NULL DEFAULT 'active',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_id),
    UNIQUE KEY idx_cpt_post_id (cpt_post_id),
    KEY idx_status_priority (status, priority),
    KEY idx_trigger_types (trigger_types),
    KEY idx_status_triggers (status, trigger_types)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

3. **Implementation Details**:
   - Add table creation SQL to existing `install()` method in `src/Includes/Installer.php`
   - Use `$wpdb->query()` with proper error checking
   - Update database version number to trigger creation
   - Add table existence check to prevent duplicate creation errors

4. **Testing Process**:
   - Navigate to WordPress admin → Plugins
   - Deactivate "Order Daemon Core" plugin
   - Reactivate "Order Daemon Core" plugin
   - Check database for new `wp_odcm_completion_rules` table
   - Verify table structure matches schema above

5. **Verification Commands** (via wp-cli in cron container):
```bash
wp db query "SHOW TABLES LIKE 'wp_odcm_completion_rules'"
wp db query "DESCRIBE wp_odcm_completion_rules"
```

**Expected Outcome**: After reactivation, the `wp_odcm_completion_rules` table should exist with the proper schema, enabling immediate development and testing of the rule system.

### **Phase 1: Database Schema Integration**
- [ ] **Update Database Installer**: Ensure table creation is properly integrated
  - Add version checking for database migrations
  - Handle existing installations vs. fresh installs
  - Test table creation across different environments

### **Phase 2: CPT Investigation & Mapping**
- [ ] **Identify CPT Registration**: Find where completion rules CPT is registered
  - Search for `register_post_type` calls
  - Identify post type slug, capabilities, meta fields

- [ ] **Query Existing Rules**: Identify current completion rules in `wp_posts` table
  - Query by `post_type` to find existing rules
  - Analyze `post_meta` structure for rule conditions/actions
  - Document current rule format

- [ ] **Design Data Mapping**: Define how CPT fields map to database table columns
  - `post_title` → `name`
  - `post_status` → `status` 
  - `post_meta` fields → `conditions`, `actions`, `trigger_type`, `priority`

### **Phase 3: Sync Mechanism Implementation**
- [ ] **CPT Save Hooks**: Hook into WordPress post save/update
  - Hook: `save_post_{post_type}` 
  - Extract rule data from CPT and insert/update in database table
  - Handle rule validation and sanitization

- [ ] **CPT Delete Hooks**: Hook into post deletion
  - Hook: `before_delete_post`
  - Remove corresponding rule from database table
  - Handle cleanup of related data

- [ ] **Status Change Hooks**: Handle publish/unpublish events
  - Hook: `transition_post_status`
  - Update rule `status` field in database table
  - Ensure only published rules are marked as active

- [ ] **Bulk Sync Function**: Create function to sync all existing CPT rules to database
  - Query all existing completion rule CPTs
  - Process each rule and insert into database table
  - Handle duplicates and conflicts

### **Phase 4: Rule Loading Integration**  
- [ ] **Verify UniversalEventProcessor**: Confirm it loads rules from database table
  - Check if `UniversalEventProcessor::processEvent()` queries database
  - Verify rule matching logic works with database format
  - Test rule condition evaluation

- [ ] **Update Rule Queries**: Ensure all rule loading uses database table
  - Search codebase for any direct CPT queries
  - Replace with database table queries for performance
  - Maintain backward compatibility if needed

### **Phase 5: Testing & Verification**
- [ ] **Test Rule Creation**: Verify new CPT rules sync to database
  - Create rule via WordPress admin
  - Confirm it appears in database table
  - Check all fields are mapped correctly

- [ ] **Test Order Processing**: Verify orders trigger synced rules  
  - Process Order #40 (virtual product, COD, $10) 
  - Confirm rule evaluation occurs
  - Verify completion events are generated

- [ ] **Test Dashboard Display**: Confirm events appear in insight dashboard
  - Check audit log entries are created
  - Verify dashboard shows completion events
  - Test filtering and search functionality

## **Specific Test Case: Order #40**

**Order Details:**
- ID: 40
- Status: completed  
- Payment Method: cod (Cash on Delivery)
- Total: $10.00 USD
- Product: "virtual product" (ID: 12, Type: simple, Categories: Uncategorized)

**Expected Behavior After Implementation:**
1. Order #40 should trigger completion rule evaluation
2. If matching rules exist, completion events should be generated
3. Events should appear in insight dashboard with appropriate details

## **Success Criteria**

### **✅ Implementation Complete When:**
- [ ] `wp_odcm_completion_rules` database table exists with proper schema
- [ ] CPT rules automatically sync to database table on save/delete/status change
- [ ] All existing CPT rules are migrated to database table
- [ ] UniversalEventProcessor successfully loads and evaluates rules from database
- [ ] Order processing generates completion events for matching rules
- [ ] Insight dashboard displays completion events with full details
- [ ] New rule creation via WordPress admin immediately works for order processing

### **🧪 Verification Tests**
1. **Create new rule** via WordPress admin → **Rule appears in database table**
2. **Process test order** matching rule conditions → **Completion event generated**
3. **Check insight dashboard** → **Event visible with correct details**
4. **Delete rule** via WordPress admin → **Rule removed from database table**
5. **Process same order again** → **No event generated (rule deleted)**

## **Development Environment**

- **Location**: `/mnt/lab/order-daemon-core`  
- **Docker**: WordPress in containers, wp-cli available in `cron` container
- **Database**: MySQL with WordPress tables, prefix `wp_`
- **Debug Mode**: `ODCM_DEBUG` currently false (production mode)

## **Priority**

**HIGH PRIORITY** - This issue prevents the core functionality (completion rule processing) from working, making the insight dashboard empty and rules ineffective for order automation.

---

*This document provides complete context for implementing the CPT-to-database sync mechanism that will restore completion rule functionality to the Order Daemon plugin.*
