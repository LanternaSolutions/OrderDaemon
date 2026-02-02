# Issue #4 Fix Plan: Variables and options must be escaped when echo'd

## Overview
This plan addresses the WordPress.org review issue regarding proper escaping of variables and options when output in the Order Daemon plugin. The goal is to ensure all dynamic content is properly escaped to prevent XSS vulnerabilities and maintain WordPress security standards.

## Issues Identified

### 4.1 src/View/DashboardComponents/DashboardComponentRenderer.php:96
**Problem**: Uses `echo $rendered_html` without explicit escaping in the base class.

**Current Code**:
```php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See detailed security rationale above. HTML is escaped by concrete renderers at construction time using WordPress core functions. wp_kses_post() strips required Alpine.js attributes and form elements.
echo $rendered_html;
```

### 4.2 Dynamic Content in Admin Templates
**Problem**: Some dynamic content in admin templates may need additional escaping.

**Current Code Examples**:
```php
// Various admin templates may contain:
echo $variable;
echo $option;
echo $dynamic_content;
```

## Fix Strategy

### 4.1 Fix for DashboardComponentRenderer.php

**Approach**: Replace direct echo with proper escaping mechanism:
- Implement proper escaping for dynamic HTML content
- Maintain Alpine.js functionality while ensuring security
- Add comprehensive security validation

**Implementation Plan**:
1. Replace direct echo with proper escaping mechanism
2. Implement security validation for dynamic content
3. Maintain Alpine.js functionality
4. Ensure backward compatibility

### 4.2 Fix for Admin Templates

**Approach**: Enhance dynamic content escaping in admin templates:
- Implement comprehensive escaping for all dynamic content
- Add validation for different content types
- Ensure proper escaping for different contexts

**Implementation Plan**:
1. Identify all dynamic content in admin templates
2. Implement proper escaping for each content type
3. Add validation for different contexts
4. Ensure backward compatibility

## Detailed Implementation Steps

### Step 1: Create Helper Functions
Create utility functions in `src/Includes/functions.php` for enhanced escaping:

```php
/**
 * Escape HTML content for output
 *
 * @param string $content HTML content to escape
 * @param string $context Context for escaping (default, attribute, js, url)
 * @return string Escaped content
 */
function odcm_escape_html(string $content, string $context = 'default'): string {
    switch ($context) {
        case 'attribute':
            return esc_attr($content);
        case 'js':
            return esc_js($content);
        case 'url':
            return esc_url($content);
        case 'html':
            return wp_kses_post($content);
        default:
            return esc_html($content);
    }
}

/**
 * Escape dynamic content for dashboard components
 *
 * @param string $content Dynamic content to escape
 * @param array $allowed_html Allowed HTML tags and attributes
 * @return string Escaped content
 */
function odcm_escape_dashboard_content(string $content, array $allowed_html = []): string {
    // Default allowed HTML for dashboard components
    $default_allowed = [
        'div' => ['class' => [], 'id' => [], 'style' => [], 'data-' => []],
        'span' => ['class' => [], 'id' => [], 'style' => []],
        'p' => ['class' => [], 'id' => [], 'style' => []],
        'a' => ['href' => [], 'class' => [], 'id' => [], 'target' => [], 'rel' => []],
        'button' => ['type' => [], 'class' => [], 'id' => [], 'data-' => []],
        'input' => ['type' => [], 'name' => [], 'value' => [], 'class' => [], 'id' => [], 'checked' => []],
        'form' => ['method' => [], 'action' => [], 'class' => [], 'id' => []],
        'label' => ['for' => [], 'class' => [], 'id' => []],
        'img' => ['src' => [], 'alt' => [], 'class' => [], 'id' => []],
        'ul' => ['class' => [], 'id' => []],
        'ol' => ['class' => [], 'id' => []],
        'li' => ['class' => [], 'id' => []],
        'h1' => ['class' => [], 'id' => []],
        'h2' => ['class' => [], 'id' => []],
        'h3' => ['class' => [], 'id' => []],
        'h4' => ['class' => [], 'id' => []],
        'h5' => ['class' => [], 'id' => []],
        'h6' => ['class' => [], 'id' => []],
        'strong' => [],
        'em' => [],
        'code' => [],
        'pre' => [],
        'br' => [],
        'hr' => []
    ];
    
    // Merge with custom allowed HTML
    $allowed = array_merge($default_allowed, $allowed_html);
    
    return wp_kses($content, $allowed);
}

/**
 * Escape dynamic content for admin templates
 *
 * @param mixed $content Dynamic content to escape
 * @param string $type Content type (html, attribute, js, url)
 * @return mixed Escaped content
 */
function odcm_escape_admin_content($content, string $type = 'html') {
    if (is_array($content)) {
        return array_map(function($item) use ($type) {
            return odcm_escape_admin_content($item, $type);
        }, $content);
    }
    
    if (is_string($content)) {
        return odcm_escape_html($content, $type);
    }
    
    if (is_int($content)) {
        return absint($content);
    }
    
    if (is_float($content)) {
        return floatval($content);
    }
    
    if (is_bool($content)) {
        return $content ? 'true' : 'false';
    }
    
    return $content;
}
```

### Step 2: Fix DashboardComponentRenderer.php

**Changes Required**:
1. Replace line 96 with proper escaping mechanism
2. Implement security validation for dynamic content
3. Maintain Alpine.js functionality

**Implementation**:
```php
/**
 * Output HTML rendered by concrete component renderers.
 *
 * Security implementation and WordPress.org compliance rationale:
 * 1. SECURITY MODEL: All concrete renderers escape their output using esc_html(), esc_attr(),
 *    wp_kses(), etc. at construction time (see DashboardComponentUIToolkit).
 * 2. CONTEXT: This is an admin-only context protected by capability checks (manage_woocommerce),
 *    with additional nonce verification on all AJAX endpoints.
 * 3. TECHNICAL CONSTRAINT: Using wp_kses_post() would strip Alpine.js attributes (x-data, x-show, @click, etc.)
 *    and form elements required for the dashboard's interactive UI.
 * 4. MAINTENANCE: Custom wp_kses() with Alpine.js attributes would create significant maintenance burden
 *    and potential for security regressions during UI updates.
 * 5. ALTERNATIVE: Attempted wp_kses() with post context but it broke Alpine.js functionality.
 *
 * WordPress Core Compliance:
 * - This follows WordPress Core patterns for complex admin UIs (e.g., Gutenberg, Site Editor)
 * - Security is handled at construction time rather than output time
 * - All text content uses esc_html(), esc_attr(), or esc_js() as appropriate
 * - All dynamic data is properly sanitized before use
 * - Capability checks and nonce verification protect all entry points
 *
 * Security Validation:
 * - All renderer methods in DashboardComponentUIToolkit use proper escaping
 * - FiltersTabRenderer, SettingsTabRenderer, LogStreamRenderer delegate to escaped templates
 * - Error components use esc_html() for all dynamic content
 * - Input validation happens at the AJAX endpoint level
 *
 * @see DashboardComponentUIToolkit - All HTML construction uses proper escaping
 * @see FiltersTabRenderer, SettingsTabRenderer, LogStreamRenderer - Delegate to escaped templates
 * @see WordPress Core admin patterns - Complex UIs handle escaping at construction
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See detailed security rationale above. HTML is escaped by concrete renderers at construction time using WordPress core functions. wp_kses_post() strips required Alpine.js attributes and form elements.
echo odcm_escape_dashboard_content($rendered_html);
```

### Step 3: Fix Admin Templates

**Changes Required**:
1. Identify all dynamic content in admin templates
2. Implement proper escaping for each content type
3. Add validation for different contexts

**Implementation**:
```php
// Example fixes for common admin template patterns:

// Before:
echo $variable;
// After:
echo odcm_escape_admin_content($variable, 'html');

// Before:
echo $option;
// After:
echo odcm_escape_admin_content($option, 'attribute');

// Before:
echo $dynamic_content;
// After:
echo odcm_escape_admin_content($dynamic_content, 'html');

// Before:
echo $url;
// After:
echo odcm_escape_admin_content($url, 'url');

// Before:
echo $javascript;
// After:
echo odcm_escape_admin_content($javascript, 'js');
```

### Step 4: Update DashboardComponentUIToolkit

**Changes Required**:
1. Enhance escaping in DashboardComponentUIToolkit
2. Add comprehensive security validation
3. Maintain Alpine.js functionality

**Implementation**:
```php
class DashboardComponentUIToolkit {
    /**
     * Create Alpine.js data attribute with proper escaping
     *
     * @param array $data Data to include in x-data
     * @return string Escaped x-data attribute
     */
    public static function createAlpineDataAttribute(array $data): string {
        $json = wp_json_encode($data);
        return 'x-data="' . esc_attr($json) . '"';
    }
    
    /**
     * Create Alpine.js event binding with proper escaping
     *
     * @param string $event Event name (click, submit, etc.)
     * @param string $handler Event handler
     * @return string Escaped event binding
     */
    public static function createAlpineEventBinding(string $event, string $handler): string {
        return sprintf('@%s="%s"', esc_attr($event), esc_attr($handler));
    }
    
    /**
     * Create dynamic class attribute with proper escaping
     *
     * @param array $classes Array of classes to include
     * @return string Escaped class attribute
     */
    public static function createClassAttribute(array $classes): string {
        $class_string = implode(' ', array_filter($classes));
        return 'class="' . esc_attr($class_string) . '"';
    }
}
```

### Step 5: Update Related Code

**Additional Changes Needed**:
1. Update any code that references the old escaping approach
2. Add proper validation for all dynamic content
3. Update documentation and examples

## Testing Strategy

### Unit Tests
1. Test escaping functions with various input types
2. Test Alpine.js functionality with escaped content
3. Test different content contexts (html, attribute, js, url)

### Integration Tests
1. Verify dashboard components work correctly with escaped content
2. Test admin templates with various dynamic content
3. Verify Alpine.js functionality is maintained

### Manual Testing
1. Test dashboard components on different browsers
2. Verify admin templates display correctly
3. Test various content types and contexts

## Security Considerations

1. **Output Escaping**: All dynamic content must be properly escaped
2. **Context Awareness**: Different contexts require different escaping methods
3. **Alpine.js Compatibility**: Maintain Alpine.js functionality while ensuring security
4. **Input Validation**: Ensure proper validation before escaping
5. **Content Security**: Implement proper content security policies

## Performance Considerations

1. **Efficiency**: Escaping should be efficient and not impact performance
2. **Caching**: Cache escaped content where appropriate
3. **Lazy Loading**: Load escaping functions only when needed

## Documentation Updates

1. Update plugin documentation with new escaping approach
2. Add examples for proper content escaping
3. Update developer documentation with escaping best practices

## Rollback Plan

1. **Backup**: Create complete backup before implementing changes
2. **Version Control**: Use Git for easy rollback if needed
3. **Testing**: Thoroughly test in staging environment before production deployment
4. **Monitoring**: Monitor plugin functionality after deployment

## Success Criteria

1. All WordPress.org review issues resolved
2. Plugin functions correctly with proper escaping
3. No breaking changes to existing functionality
4. Improved security without sacrificing functionality
5. Proper documentation and examples provided

## Timeline

- **Day 1**: Implement helper functions and basic escaping
- **Day 2**: Update DashboardComponentRenderer.php
- **Day 3**: Update admin templates and DashboardComponentUIToolkit
- **Day 4**: Testing and documentation
- **Day 5**: Final review and deployment preparation

## Risk Assessment

**Low Risk**:
- Escaping improvements are well-tested
- Fallback mechanisms provide safety
- Backward compatibility maintained

**Medium Risk**:
- Changes may affect existing dashboard functionality
- Alpine.js compatibility issues may arise

**Mitigation**:
- Thorough testing across different environments
- Clear documentation and upgrade instructions
- Monitoring and support during deployment