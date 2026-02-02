# Issue #3 Fix Plan: Processing the whole input

## Overview
This plan addresses the WordPress.org review issue regarding processing entire input arrays in the Order Daemon plugin. The goal is to refactor the code to only process necessary data, improving performance and security.

## Issues Identified

### 3.1 src/Core/AttributionTracker.php:538
**Problem**: Processes entire `$_SERVER` array to extract HTTP headers.

**Current Code**:
```php
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    } elseif ($key === 'CONTENT_TYPE') {
        $headers['content-type'] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    } elseif ($key === 'CONTENT_LENGTH') {
        $headers['content-length'] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }
}
```

### 3.2 src/Core/Core.php:269
**Problem**: Processes entire `$_GET` array for admin form handling.

**Current Code**:
```php
foreach ($_GET as $key => $value) {
    if (is_array($value)) {
        $safe_get[$key] = array_map('sanitize_text_field', array_map('wp_unslash', $value));
    } else {
        $safe_get[$key] = sanitize_text_field(wp_unslash($value));
    }
}
```

### 3.3 src/Core/Core.php:260
**Problem**: Processes entire `$_POST` array for admin form handling.

**Current Code**:
```php
foreach ($_POST as $key => $value) {
    if (is_array($value)) {
        $safe_post[$key] = array_map('sanitize_text_field', array_map('wp_unslash', $value));
    } else {
        $safe_post[$key] = sanitize_text_field(wp_unslash($value));
    }
}
```

### 3.4 src/Core/AttributionTracker.php:622
**Problem**: Processes entire `$_COOKIE` array to find WooCommerce session cookies.

**Current Code**:
```php
foreach ($_COOKIE as $name => $v) {
    if (is_string($name) && strpos($name, 'wp_woocommerce_session_') === 0) {
        return true;
    }
}
```

## Fix Strategy

### 3.1 Fix for AttributionTracker.php (HTTP Headers)

**Approach**: Replace full `$_SERVER` processing with targeted header extraction:
- Use `$_SERVER` only for specific headers needed
- Implement direct access to required headers
- Add proper validation and sanitization

**Implementation Plan**:
1. Identify specific headers actually needed
2. Replace loop with direct `$_SERVER` access for required headers
3. Add proper validation and sanitization
4. Ensure backward compatibility

### 3.2 Fix for Core.php (GET Parameters)

**Approach**: Replace full `$_GET` processing with targeted parameter extraction:
- Identify specific GET parameters actually needed
- Replace loop with direct `$_GET` access for required parameters
- Add proper validation and sanitization

**Implementation Plan**:
1. Identify specific GET parameters actually needed
2. Replace loop with direct `$_GET` access for required parameters
3. Add proper validation and sanitization
4. Ensure backward compatibility

### 3.3 Fix for Core.php (POST Parameters)

**Approach**: Replace full `$_POST` processing with targeted parameter extraction:
- Identify specific POST parameters actually needed
- Replace loop with direct `$_POST` access for required parameters
- Add proper validation and sanitization

**Implementation Plan**:
1. Identify specific POST parameters actually needed
2. Replace loop with direct `$_POST` access for required parameters
3. Add proper validation and sanitization
4. Ensure backward compatibility

### 3.4 Fix for AttributionTracker.php (Cookies)

**Approach**: Replace full `$_COOKIE` processing with targeted cookie checking:
- Use `$_COOKIE` only for specific WooCommerce session cookies
- Implement direct access to required cookies
- Add proper validation and sanitization

**Implementation Plan**:
1. Identify specific cookies actually needed
2. Replace loop with direct `$_COOKIE` access for required cookies
3. Add proper validation and sanitization
4. Ensure backward compatibility

## Detailed Implementation Steps

### Step 1: Create Helper Functions
Create utility functions in `src/Includes/functions.php` for targeted input handling:

```php
/**
 * Get specific HTTP headers from $_SERVER
 *
 * @param array $headers List of headers to extract
 * @return array Extracted headers with proper sanitization
 */
function odcm_get_specific_headers(array $headers): array {
    $extracted = [];
    
    foreach ($headers as $header) {
        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        
        if (isset($_SERVER[$server_key])) {
            $extracted[$header] = sanitize_text_field(wp_unslash($_SERVER[$server_key]));
        }
    }
    
    // Handle special headers
    if (in_array('content-type', $headers) && isset($_SERVER['CONTENT_TYPE'])) {
        $extracted['content-type'] = sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE']));
    }
    
    if (in_array('content-length', $headers) && isset($_SERVER['CONTENT_LENGTH'])) {
        $extracted['content-length'] = sanitize_text_field(wp_unslash($_SERVER['CONTENT_LENGTH']));
    }
    
    return $extracted;
}

/**
 * Get specific GET parameters with validation
 *
 * @param array $params List of parameters to extract
 * @return array Extracted parameters with proper validation
 */
function odcm_get_specific_get_params(array $params): array {
    $extracted = [];
    
    foreach ($params as $param) {
        if (isset($_GET[$param])) {
            $value = wp_unslash($_GET[$param]);
            $extracted[$param] = is_array($value) 
                ? array_map('sanitize_text_field', $value) 
                : sanitize_text_field($value);
        }
    }
    
    return $extracted;
}

/**
 * Get specific POST parameters with validation
 *
 * @param array $params List of parameters to extract
 * @return array Extracted parameters with proper validation
 */
function odcm_get_specific_post_params(array $params): array {
    $extracted = [];
    
    foreach ($params as $param) {
        if (isset($_POST[$param])) {
            $value = wp_unslash($_POST[$param]);
            $extracted[$param] = is_array($value) 
                ? array_map('sanitize_text_field', $value) 
                : sanitize_text_field($value);
        }
    }
    
    return $extracted;
}

/**
 * Check for specific cookies
 *
 * @param array $cookies List of cookies to check
 * @return bool True if any specified cookie exists
 */
function odcm_check_specific_cookies(array $cookies): bool {
    foreach ($cookies as $cookie) {
        if (isset($_COOKIE[$cookie])) {
            return true;
        }
    }
    return false;
}
```

### Step 2: Fix AttributionTracker.php (HTTP Headers)

**Changes Required**:
1. Replace line 538 with targeted header extraction
2. Identify specific headers actually needed
3. Add proper validation and sanitization

**Implementation**:
```php
// Replace the existing loop with:
$required_headers = ['HTTP_USER_AGENT', 'HTTP_REFERER', 'CONTENT_TYPE', 'CONTENT_LENGTH'];
$headers = odcm_get_specific_headers($required_headers);
```

### Step 3: Fix Core.php (GET Parameters)

**Changes Required**:
1. Replace line 269 with targeted GET parameter extraction
2. Identify specific GET parameters actually needed
3. Add proper validation and sanitization

**Implementation**:
```php
// Replace the existing loop with:
$required_get_params = ['page', 'tab', 'action', '_wpnonce'];
$safe_get = odcm_get_specific_get_params($required_get_params);
```

### Step 4: Fix Core.php (POST Parameters)

**Changes Required**:
1. Replace line 260 with targeted POST parameter extraction
2. Identify specific POST parameters actually needed
3. Add proper validation and sanitization

**Implementation**:
```php
// Replace the existing loop with:
$required_post_params = ['odcm_reprocess_orders', 'odcm_reprocess_nonce', 'action'];
$safe_post = odcm_get_specific_post_params($required_post_params);
```

### Step 5: Fix AttributionTracker.php (Cookies)

**Changes Required**:
1. Replace line 622 with targeted cookie checking
2. Identify specific cookies actually needed
3. Add proper validation and sanitization

**Implementation**:
```php
// Replace the existing loop with:
$required_cookies = ['wp_woocommerce_session_'];
if (odcm_check_specific_cookies($required_cookies)) {
    return true;
}
```

### Step 6: Update Related Code

**Additional Changes Needed**:
1. Update any code that references the old input handling
2. Add proper validation for all input operations
3. Update documentation and examples

## Testing Strategy

### Unit Tests
1. Test helper functions with different input scenarios
2. Test targeted extraction with valid and invalid data
3. Test fallback behavior when parameters are missing

### Integration Tests
1. Verify plugin functionality with targeted input handling
2. Test form submissions with different parameter combinations
3. Verify header processing works correctly

### Manual Testing
1. Test plugin on different hosting environments
2. Verify form operations work correctly
3. Test header processing in different scenarios

## Security Considerations

1. **Input Validation**: All extracted parameters must be properly validated
2. **Sanitization**: Ensure proper sanitization for all input types
3. **Error Handling**: Implement secure error handling without information disclosure
4. **Backward Compatibility**: Maintain compatibility with existing functionality

## Performance Considerations

1. **Efficiency**: Targeted extraction is more efficient than processing entire arrays
2. **Memory Usage**: Reduced memory usage by avoiding unnecessary processing
3. **Speed**: Faster execution by only processing needed data

## Documentation Updates

1. Update plugin documentation with new input handling approach
2. Add examples for proper input parameter usage
3. Update developer documentation with best practices

## Rollback Plan

1. **Backup**: Create complete backup before implementing changes
2. **Version Control**: Use Git for easy rollback if needed
3. **Testing**: Thoroughly test in staging environment before production deployment
4. **Monitoring**: Monitor plugin functionality after deployment

## Success Criteria

1. All WordPress.org review issues resolved
2. Plugin functions correctly with targeted input handling
3. No breaking changes to existing functionality
4. Improved performance and security
5. Proper documentation and examples provided

## Timeline

- **Day 1**: Implement helper functions and basic fixes
- **Day 2**: Update related code and add validation
- **Day 3**: Testing and documentation
- **Day 4**: Final review and deployment preparation

## Risk Assessment

**Low Risk**:
- Targeted extraction is well-tested approach
- Fallback mechanisms provide safety
- Backward compatibility maintained

**Medium Risk**:
- Changes may affect existing form handling
- Header processing changes may impact custom integrations

**Mitigation**:
- Thorough testing across different environments
- Clear documentation and upgrade instructions
- Monitoring and support during deployment