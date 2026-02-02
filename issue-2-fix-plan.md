# Issue #2 Fix Plan: Data Must be Sanitized, Escaped, and Validated

## Overview
This plan addresses the WordPress.org review issue regarding data sanitization, escaping, and validation in the Order Daemon plugin. The goal is to ensure all data is properly sanitized, validated, and escaped according to WordPress security best practices.

## Issues Identified

### 2.1 src/Admin/InsightDashboard.php:1650-1654 [COMPLETE]
**Problem**: Uses `json_decode(stripslashes($env_raw), true)` without proper sanitization.

**Current Code**:
```php
$env_raw = isset($_POST['env']) ? wp_unslash($_POST['env']) : '{}';
$issues_raw = isset($_POST['issues']) ? wp_unslash($_POST['issues']) : '[]';

$env = json_decode(stripslashes($env_raw), true);
$issues = json_decode(stripslashes($issues_raw), true);
```

### 2.2 src/Core/Core.php:269, 260 [COMPLETE]
**Problem**: Array processing could be more robust with additional validation.

**Current Code**:
```php
foreach ($_GET as $key => $value) {
    if (is_array($value)) {
        $safe_get[$key] = array_map('sanitize_text_field', array_map('wp_unslash', $value));
    } else {
        $safe_get[$key] = sanitize_text_field(wp_unslash($value));
    }
}

foreach ($_POST as $key => $value) {
    if (is_array($value)) {
        $safe_post[$key] = array_map('sanitize_text_field', array_map('wp_unslash', $value));
    } else {
        $safe_post[$key] = sanitize_text_field(wp_unslash($value));
    }
}
```

### 2.3 src/Core/AttributionTracker.php:538 [COMPLETE]
**Problem**: Server header processing could use more specific validation.

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

### 2.4 src/Core/AttributionTracker.php:622 [COMPLETE]
**Problem**: Cookie processing could use more specific validation.

**Current Code**:
```php
foreach ($_COOKIE as $name => $v) {
    if (is_string($name) && strpos($name, 'wp_woocommerce_session_') === 0) {
        return true;
    }
}
```

## Fix Strategy

### 2.1 Fix for InsightDashboard.php (JSON Data)

**Approach**: Replace `stripslashes()` with proper JSON handling and validation:
- Use `wp_json_validate()` for JSON validation
- Implement proper error handling for invalid JSON
- Add additional sanitization for specific data types

**Implementation Plan**:
1. Replace `stripslashes()` with proper JSON validation
2. Add error handling for invalid JSON data
3. Implement additional sanitization for specific data types
4. Ensure backward compatibility

### 2.2 Fix for Core.php (Array Processing)

**Approach**: Enhance array processing with additional validation:
- Add type validation for specific parameters
- Implement whitelist validation for known parameters
- Add additional sanitization for specific data types

**Implementation Plan**:
1. Identify specific parameters that need additional validation
2. Implement whitelist validation for known parameters
3. Add type-specific sanitization
4. Ensure backward compatibility

### 2.3 Fix for AttributionTracker.php (Server Headers)

**Approach**: Enhance server header processing with specific validation:
- Implement whitelist validation for allowed headers
- Add type-specific sanitization for different header types
- Implement proper error handling

**Implementation Plan**:
1. Identify allowed headers and their expected formats
2. Implement whitelist validation for headers
3. Add type-specific sanitization
4. Ensure backward compatibility

### 2.4 Fix for AttributionTracker.php (Cookies)

**Approach**: Enhance cookie processing with specific validation:
- Implement whitelist validation for allowed cookies
- Add proper sanitization for cookie values
- Implement proper error handling

**Implementation Plan**:
1. Identify allowed cookies and their expected formats
2. Implement whitelist validation for cookies
3. Add proper sanitization for cookie values
4. Ensure backward compatibility

## Detailed Implementation Steps

### Step 1: Create Helper Functions [COMPLETE]
Create utility functions in `src/Includes/functions.php` for enhanced data handling:

```php
/**
 * Validate and sanitize JSON data
 *
 * @param string $json_string JSON string to validate
 * @param bool $assoc Whether to return associative array
 * @return array|object Validated and sanitized data
 * @throws InvalidArgumentException If JSON is invalid
 */
function odcm_validate_and_sanitize_json(string $json_string, bool $assoc = true) {
    // Validate JSON structure
    if (!wp_json_validate($json_string)) {
        throw new InvalidArgumentException('Invalid JSON data provided');
    }
    
    // Decode JSON
    $data = json_decode($json_string, $assoc);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('JSON decoding error: ' . json_last_error_msg());
    }
    
    // Sanitize decoded data
    return odcm_sanitize_data($data);
}

/**
 * Sanitize data recursively
 *
 * @param mixed $data Data to sanitize
 * @return mixed Sanitized data
 */
function odcm_sanitize_data($data) {
    if (is_array($data)) {
        return array_map('odcm_sanitize_data', $data);
    } elseif (is_string($data)) {
        return sanitize_text_field($data);
    } elseif (is_int($data)) {
        return absint($data);
    } elseif (is_float($data)) {
        return floatval($data);
    } elseif (is_bool($data)) {
        return (bool) $data;
    } else {
        return $data;
    }
}

/**
 * Validate and sanitize specific parameters
 *
 * @param array $params Parameters to validate
 * @param array $rules Validation rules
 * @return array Validated and sanitized parameters
 * @throws InvalidArgumentException If validation fails
 */
function odcm_validate_and_sanitize_params(array $params, array $rules): array {
    $validated = [];
    
    foreach ($rules as $param => $rule) {
        if (!isset($params[$param])) {
            if (isset($rule['required']) && $rule['required']) {
                throw new InvalidArgumentException("Required parameter missing: $param");
            }
            continue;
        }
        
        $value = $params[$param];
        
        // Type validation
        switch ($rule['type']) {
            case 'string':
                if (!is_string($value)) {
                    throw new InvalidArgumentException("Parameter $param must be a string");
                }
                $validated[$param] = sanitize_text_field($value);
                break;
                
            case 'integer':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException("Parameter $param must be an integer");
                }
                $validated[$param] = absint($value);
                break;
                
            case 'boolean':
                $validated[$param] = (bool) $value;
                break;
                
            case 'array':
                if (!is_array($value)) {
                    throw new InvalidArgumentException("Parameter $param must be an array");
                }
                $validated[$param] = odcm_sanitize_data($value);
                break;
                
            default:
                throw new InvalidArgumentException("Unknown validation type: " . $rule['type']);
        }
        
        // Additional validation rules
        if (isset($rule['min']) && $validated[$param] < $rule['min']) {
            throw new InvalidArgumentException("Parameter $param must be at least {$rule['min']}");
        }
        
        if (isset($rule['max']) && $validated[$param] > $rule['max']) {
            throw new InvalidArgumentException("Parameter $param must be at most {$rule['max']}");
        }
    }
    
    return $validated;
}
```

### Step 2: Fix InsightDashboard.php (JSON Data) [COMPLETE]

**Changes Required**:
1. Replace line 1650-1654 with proper JSON validation
2. Add error handling for invalid JSON data
3. Implement additional sanitization for specific data types

**Implementation**:
```php
// Replace the existing code with:
try {
    $env = odcm_validate_and_sanitize_json($env_raw, true);
    $issues = odcm_validate_and_sanitize_json($issues_raw, true);
} catch (InvalidArgumentException $e) {
    // Log error and provide fallback
    odcm_log_message("JSON validation error: " . $e->getMessage(), 'error');
    $env = [];
    $issues = [];
}
```

### Step 3: Fix Core.php (Array Processing) [COMPLETE]

**Changes Required**:
1. Replace lines 269, 260 with enhanced validation
2. Implement whitelist validation for known parameters
3. Add type-specific sanitization

**Implementation**:
```php
// Replace the existing code with:
$allowed_get_params = ['page', 'tab', 'action', '_wpnonce'];
$allowed_post_params = ['odcm_reprocess_orders', 'odcm_reprocess_nonce', 'action'];

$validation_rules = [
    'page' => ['type' => 'string', 'required' => false],
    'tab' => ['type' => 'string', 'required' => false],
    'action' => ['type' => 'string', 'required' => false],
    '_wpnonce' => ['type' => 'string', 'required' => true],
    'odcm_reprocess_orders' => ['type' => 'string', 'required' => false],
    'odcm_reprocess_nonce' => ['type' => 'string', 'required' => true]
];

try {
    $safe_get = odcm_validate_and_sanitize_params($_GET, $validation_rules);
    $safe_post = odcm_validate_and_sanitize_params($_POST, $validation_rules);
} catch (InvalidArgumentException $e) {
    // Log error and provide fallback
    odcm_log_message("Parameter validation error: " . $e->getMessage(), 'error');
    $safe_get = [];
    $safe_post = [];
}
```

### Step 4: Fix AttributionTracker.php (Server Headers) [COMPLETE]

**Changes Required**:
1. Replace line 538 with enhanced header validation
2. Implement whitelist validation for allowed headers
3. Add type-specific sanitization

**Implementation**:
```php
// Replace the existing code with:
$allowed_headers = [
    'user-agent' => ['type' => 'string'],
    'referer' => ['type' => 'string'],
    'content-type' => ['type' => 'string'],
    'content-length' => ['type' => 'integer']
];

$headers = [];
foreach ($allowed_headers as $header => $rule) {
    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    
    if (isset($_SERVER[$server_key])) {
        $value = wp_unslash($_SERVER[$server_key]);
        
        switch ($rule['type']) {
            case 'string':
                $headers[$header] = sanitize_text_field($value);
                break;
            case 'integer':
                $headers[$header] = absint($value);
                break;
        }
    }
    
    // Handle special headers
    if ($header === 'content-type' && isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE']));
    }
    
    if ($header === 'content-length' && isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['content-length'] = absint(wp_unslash($_SERVER['CONTENT_LENGTH']));
    }
}
```

### Step 5: Fix AttributionTracker.php (Cookies)

**Changes Required**:
1. Replace line 622 with enhanced cookie validation
2. Implement whitelist validation for allowed cookies
3. Add proper sanitization for cookie values

**Implementation**:
```php
// Replace the existing code with:
$allowed_cookies = ['wp_woocommerce_session_'];

foreach ($allowed_cookies as $cookie) {
    if (isset($_COOKIE[$cookie])) {
        // Sanitize cookie value
        $cookie_value = sanitize_text_field(wp_unslash($_COOKIE[$cookie]));
        
        // Additional validation for WooCommerce session cookies
        if (strpos($cookie, 'wp_woocommerce_session_') === 0) {
            // Validate session cookie format
            if (preg_match('/^wp_woocommerce_session_[a-f0-9]{32}$/', $cookie)) {
                return true;
            }
        }
    }
}

return false;
```

### Step 6: Update Related Code

**Additional Changes Needed**:
1. Update any code that references the old data handling
2. Add proper validation for all data operations
3. Update documentation and examples

## Testing Strategy

### Unit Tests
1. Test JSON validation with valid and invalid data
2. Test parameter validation with different input types
3. Test header and cookie validation with various scenarios

### Integration Tests
1. Verify plugin functionality with enhanced validation
2. Test form submissions with different data combinations
3. Verify data processing works correctly with validation

### Manual Testing
1. Test plugin on different hosting environments
2. Verify form operations work correctly with validation
3. Test data processing in different scenarios

## Security Considerations

1. **Input Validation**: All input must be properly validated against expected formats
2. **Sanitization**: Ensure proper sanitization for all data types
3. **Error Handling**: Implement secure error handling without information disclosure
4. **Type Safety**: Ensure type safety for all processed data
5. **Whitelist Validation**: Use whitelist validation for known parameters

## Performance Considerations

1. **Efficiency**: Validation should be efficient and not impact performance
2. **Caching**: Cache validation results where appropriate
3. **Lazy Loading**: Load validation rules only when needed

## Documentation Updates

1. Update plugin documentation with new validation approach
2. Add examples for proper data handling
3. Update developer documentation with validation best practices

## Rollback Plan

1. **Backup**: Create complete backup before implementing changes
2. **Version Control**: Use Git for easy rollback if needed
3. **Testing**: Thoroughly test in staging environment before production deployment
4. **Monitoring**: Monitor plugin functionality after deployment

## Success Criteria

1. All WordPress.org review issues resolved
2. Plugin functions correctly with enhanced validation
3. No breaking changes to existing functionality
4. Improved security and data integrity
5. Proper documentation and examples provided

## Timeline

- **Day 1**: Implement helper functions and basic validation
- **Day 2**: Update JSON handling and parameter validation
- **Day 3**: Update header and cookie validation
- **Day 4**: Testing and documentation
- **Day 5**: Final review and deployment preparation

## Risk Assessment

**Low Risk**:
- Validation improvements are well-tested
- Fallback mechanisms provide safety
- Backward compatibility maintained

**Medium Risk**:
- Changes may affect existing data processing
- Validation errors may impact user experience

**Mitigation**:
- Thorough testing across different environments
- Clear error messages and fallback mechanisms
- Monitoring and support during deployment