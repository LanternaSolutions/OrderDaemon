# Task: Remove Caching Mechanisms from AuditLogEndpoint.php

## Objective
Completely remove all caching mechanisms from the `AuditLogEndpoint.php` file to resolve the consolidated view issue and improve data reliability in the insight dashboard.

## Background
The current implementation uses WordPress object caching (`wp_cache_get`, `wp_cache_set`) to cache audit log data, but this is causing issues with the consolidated/grouped view in the insight dashboard. Since the dashboard is meant to show live data, caching is inappropriate and causes more problems than it solves.

## Scope
This task involves removing ALL caching-related code from `src/API/AuditLogEndpoint.php` while maintaining all existing functionality.

## Detailed Implementation Plan

### 1. Cache Removal Targets

#### A. Remove Cache Versioning System
- **Location**: Multiple methods throughout the file
- **Components to remove**:
  - All references to `odcm_logs_cache_version`
  - Cache version initialization and increment logic
  - `invalidate_logs_cache()` method (entire method)
  - `clear_specific_cache_keys()` method (entire method)

#### B. Remove Cached Data Retrieval
- **Location**: `get_all_filtered_logs()`, `get_filtered_logs()`, `get_filtered_log_count()`
- **Components to remove**:
  - All `wp_cache_get()` calls
  - Cache key generation logic
  - Cache hit logging
  - Conditional logic that checks for cached data

#### C. Remove Cache Storage
- **Location**: `get_all_filtered_logs()`, `get_filtered_logs()`, `get_filtered_log_count()`
- **Components to remove**:
  - All `wp_cache_set()` calls
  - Cache TTL constants (2 * MINUTE_IN_SECONDS, etc.)
  - Cache storage logic

#### D. Remove Cache Status Tracking
- **Location**: `get_cache_status()` method
- **Action**: Remove or simplify this method since caching will be disabled

### 2. Specific Code Changes Required

#### In `get_all_filtered_logs()` method:
```php
// REMOVE these lines:
$cache_version = wp_cache_get('odcm_logs_cache_version');
if ($cache_version === false) {
    $cache_version = 1; // default version
}
$cache_key = 'odcm_v' . $cache_version . '_filtered_logs_' . md5(json_encode($filter_params));

// Check if we have a cached result
$cached_logs = wp_cache_get($cache_key);
if (false !== $cached_logs) {
    $this->logDebugMessage("Cache hit for all filtered logs: " . $cache_key, 'debug');
    return $cached_logs;
}

// REMOVE this line at the end:
wp_cache_set($cache_key, $result, '', 2 * MINUTE_IN_SECONDS);
```

#### In `get_filtered_logs()` method:
```php
// REMOVE these lines:
$cache_version = wp_cache_get('odcm_logs_cache_version');
if ($cache_version === false) {
    $cache_version = 1; // default version
}
$cache_key = 'odcm_v' . $cache_version . '_filtered_logs_page_' . md5(json_encode($filter_params) . "_p{$page}_pp{$per_page}");

// Check if we have a cached result
$cached_logs = wp_cache_get($cache_key);
if (false !== $cached_logs) {
    $this->logDebugMessage("Cache hit for filtered logs page: " . $cache_key, 'debug');
    return $cached_logs;
}

// REMOVE this line at the end:
wp_cache_set($cache_key, $result, '', 2 * MINUTE_IN_SECONDS);
```

#### In `get_filtered_log_count()` method:
```php
// REMOVE these lines:
$cache_version = wp_cache_get('odcm_logs_cache_version');
if ($cache_version === false) {
    $cache_version = 1; // default version
}
$cache_key = 'odcm_v' . $cache_version . '_filtered_log_count_' . md5(json_encode($filter_params));

// Check if we have a cached result
$cached_count = wp_cache_get($cache_key);
if (false !== $cached_count) {
    $this->logDebugMessage("Cache hit for filtered log count: " . $cache_key, 'debug');
    return (int) $cached_count;
}

// REMOVE this line at the end:
wp_cache_set($cache_key, $result, '', 2 * MINUTE_IN_SECONDS);
```

#### In `validate_log_ids_for_deletion()` method:
```php
// REMOVE these lines:
static $validation_cache = [];
$cache_key = 'odcm_valid_log_ids_' . md5(implode(',', $log_ids));

if (isset($validation_cache[$cache_key])) {
    return $validation_cache[$cache_key];
}

// Also check persistent cache
$cached_valid_ids = wp_cache_get($cache_key);
if (false !== $cached_valid_ids) {
    // Store in static cache and return
    $validation_cache[$cache_key] = $cached_valid_ids;
    return $cached_valid_ids;
}

// REMOVE this line:
$validation_cache[$cache_key] = $result;
wp_cache_set($cache_key, $result, '', 5 * MINUTE_IN_SECONDS);
```

#### In `get_filter_options()` method:
```php
// REMOVE these lines:
$cache_key = 'odcm_filter_options';
$cached_options = wp_cache_get($cache_key);

if (false !== $cached_options) {
    return new WP_REST_Response(
        array_merge($cached_options, [
            'meta' => [
                'execution_time' => microtime(true) - $start_time,
                'timestamp' => current_time('mysql'),
                'max_results' => 100,
                'cache_hit' => true,
            ],
        ]),
        200
    );
}

// REMOVE these lines at the end:
$cache_data = [
    'statuses' => $response_data['statuses'],
    'event_types' => $response_data['event_types'],
    'order_ids' => $response_data['order_ids'],
];
wp_cache_set($cache_key, $cache_data, '', 10 * MINUTE_IN_SECONDS);
```

### 3. Methods to Remove Entirely

#### Remove `invalidate_logs_cache()` method:
```php
// REMOVE entire method
private function invalidate_logs_cache(): void
{
    // ... entire method content ...
}
```

#### Remove `clear_specific_cache_keys()` method:
```php
// REMOVE entire method
private function clear_specific_cache_keys(): void
{
    // ... entire method content ...
}
```

### 4. Simplify `get_cache_status()` method

```php
// SIMPLIFY to:
private function get_cache_status(WP_REST_Request $request): array
{
    return [
        'enabled' => false,
        'hit' => false,
    ];
}
```

### 5. Remove Cache-Related Debug Logging

Remove any debug messages that specifically mention caching, such as:
- `"Cache hit for all filtered logs: " . $cache_key`
- `"Cache hit for filtered logs page: " . $cache_key`
- `"Cache hit for filtered log count: " . $cache_key`
- `"ODCM Cache: Set initial cache version to 1"`
- `"ODCM Cache: Incremented cache version to " . $new_version`
- `"ODCM Cache: Set cache version to " . $new_version . " (fallback method)"`
- `"ODCM Cache: Cleared " . count($keys_to_clear) . " specific cache keys"`

### 6. Update Cache-Related Comments

Remove or update comments that mention caching performance benefits, such as:
- `"// Cache the result for 2 minutes (filtered lists change often)"`
- `"// Cache the result for 2 minutes (filtered counts change often)"`
- `"// Cache the filter options for 10 minutes"`

## Testing Requirements

After implementing these changes, the following should be tested:

1. **Consolidated View Functionality**: Verify that the consolidated/grouped view now works correctly
2. **Individual View Functionality**: Ensure individual view still works as expected
3. **Pagination**: Test that pagination works correctly without caching
4. **Filtering**: Verify all filter options work properly
5. **Performance**: Check that performance is still acceptable without caching
6. **Real-time Updates**: Confirm that new logs appear immediately without cache delays

## Expected Benefits

1. **Resolved Consolidated View Issue**: The main problem should be fixed
2. **Improved Data Accuracy**: Users will always see current data
3. **Simpler Codebase**: Reduced complexity makes the code easier to maintain
4. **Better Debugging**: Issues will be easier to diagnose without caching layers
5. **More Reliable**: No more cache invalidation or stale data problems

## Implementation Notes

- **Backup First**: Always create a backup of the original file before making changes
- **Incremental Changes**: Consider making changes incrementally and testing after each major section
- **Debug Mode**: Enable `ODCM_DEBUG` during testing to verify proper operation
- **Error Handling**: Ensure all error handling remains intact after cache removal

## Verification Steps

1. Check that all `wp_cache_get()` calls have been removed
2. Check that all `wp_cache_set()` calls have been removed
3. Check that cache versioning logic has been removed
4. Check that cache-related methods have been removed
5. Verify that the consolidated view now displays data correctly
6. Test with various filter combinations to ensure functionality

This comprehensive plan should completely remove caching from the AuditLogEndpoint while maintaining all existing functionality and resolving the consolidated view issue.
