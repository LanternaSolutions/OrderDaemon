## Enhanced escaping with Alpine.js support implementation strategy

### Phase 1: Enhanced Escaping Functions with Alpine.js Support

Create comprehensive escaping functions that align with WordPress security best practices:

```php
/**
 * Escape HTML content while preserving Alpine.js attributes
 *
 * @param string $content HTML content to escape
 * @param array $allowed_alpine_attributes List of allowed Alpine.js attributes
 * @return string Escaped content with Alpine.js attributes preserved
 */
function odcm_escape_alpine_html(string $content, array $allowed_alpine_attributes = []): string {
    // Default allowed Alpine.js attributes
    $default_alpine = [
        'x-data', 'x-init', 'x-show', 'x-bind', 'x-model', 'x-on', 'x-text', 'x-html',
        'x-ref', 'x-cloak', 'x-transition', 'x-effect', 'x-ignore', 'x-modelable', 'x-teleport'
    ];
    
    $allowed = array_merge($default_alpine, $allowed_alpine_attributes);
    
    // Use wp_kses with custom allowed HTML and Alpine.js attributes
    $allowed_html = [
        'div' => array_merge(['class' => [], 'id' => [], 'style' => []], array_fill_keys($allowed, [])),
        'span' => array_merge(['class' => [], 'id' => [], 'style' => []], array_fill_keys($allowed, [])),
        'p' => array_merge(['class' => [], 'id' => [], 'style' => []], array_fill_keys($allowed, [])),
        'a' => array_merge(['href' => [], 'class' => [], 'id' => [], 'target' => [], 'rel' => []], array_fill_keys($allowed, [])),
        'button' => array_merge(
            [
                'type' => [],
                'class' => [],
                'id' => [],
                'data-filter' => [],
                'data-target' => [],
            ],
            array_fill_keys($allowed, [])
        ),
        'input' => array_merge(['type' => [], 'name' => [], 'value' => [], 'class' => [], 'id' => [], 'checked' => []], array_fill_keys($allowed, [])),
        'form' => array_merge(['method' => [], 'action' => [], 'class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'label' => array_merge(['for' => [], 'class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'img' => array_merge(['src' => [], 'alt' => [], 'class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'ul' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'ol' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'li' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'h1' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'h2' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'h3' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'h4' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'h5' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'h6' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
        'strong' => array_fill_keys($allowed, []),
        'em' => array_fill_keys($allowed, []),
        'code' => array_fill_keys($allowed, []),
        'pre' => array_fill_keys($allowed, []),
        'br' => array_fill_keys($allowed, []),
        'hr' => array_fill_keys($allowed, [])
    ];
    
    return wp_kses($content, $allowed_html);
}

/**
 * Create Alpine.js data attribute with proper escaping
 *
 * @param array $data Data to include in x-data
 * @return string Escaped x-data attribute
 */
function odcm_create_alpine_data_attribute(array $data): string {
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
function odcm_create_alpine_event_binding(string $event, string $handler): string {
    return sprintf('@%s="%s"', esc_attr($event), esc_attr($handler));
}

/**
 * Create dynamic class attribute with proper escaping
 *
 * @param array $classes Array of classes to include
 * @return string Escaped class attribute
 */
function odcm_create_class_attribute(array $classes): string {
    $class_string = implode(' ', array_filter($classes));
    return 'class="' . esc_attr($class_string) . '"';
}
```

### Phase 2: Update DashboardComponentRenderer.php

Replace the current `// phpcs:ignore` approach with proper escaping:

```php
/**
 * Output HTML rendered by concrete component renderers.
 *
 * Security implementation and WordPress.org compliance rationale:
 * 1. SECURITY MODEL: All concrete renderers escape their output using proper escaping functions
 *    that preserve Alpine.js functionality while ensuring security.
 * 2. CONTEXT: This is an admin-only context protected by capability checks (manage_woocommerce),
 *    with additional nonce verification on all AJAX endpoints.
 * 3. TECHNICAL CONSTRAINT: Alpine.js attributes are preserved while all other content is properly escaped.
 * 4. MAINTENANCE: Uses WordPress core functions with minimal custom configuration.
 * 5. ALTERNATIVE: This approach maintains both security and functionality without creating maintenance burden.
 *
 * WordPress Core Compliance:
 * - This follows WordPress Core patterns for complex admin UIs
 * - Security is handled at output time rather than construction time
 * - All text content uses esc_html(), esc_attr(), or esc_js() as appropriate
 * - All dynamic data is properly sanitized before use
 * - Capability checks and nonce verification protect all entry points
 *
 * @see DashboardComponentUIToolkit - All HTML construction uses proper escaping
 * @see FiltersTabRenderer, SettingsTabRenderer, LogStreamRenderer - Delegate to escaped templates
 * @see WordPress Core admin patterns - Complex UIs handle escaping at construction
 */
echo odcm_escape_alpine_html($rendered_html);
```

### Phase 3: Add Alpine.js Support to wp_kses

Add Alpine.js support to WordPress's `wp_kses` function:

```php
/**
 * Add Alpine.js attributes to wp_kses allowed HTML
 *
 * This filter extends the allowed HTML to include Alpine.js directives
 * while maintaining WordPress security standards.
 */
add_filter('wp_kses_allowed_html', function($tags, $context) {
    // Only modify the 'odcm_admin' context
    if ($context !== 'odcm_admin') {
        return $tags;
    }
    
    $alpinized_tags = ['div', 'section', 'template', 'span', 'button', 'input', 'form', 'label', 'img', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    
    $alpine_directives = [
        'x-data'  => true,
        'x-init'  => true,
        'x-show'  => true,
        'x-bind'  => true,
        'x-model' => true,
        'x-on'    => true,
        'x-text'  => true,
        'x-html'  => true,
        'x-ref'   => true,
        'x-cloak' => true,
        'x-transition' => true,
        'x-effect' => true,
        'x-ignore' => true,
        'x-modelable' => true,
        'x-teleport' => true
    ];

    foreach ($alpinized_tags as $tag) {
        if (!isset($tags[$tag])) {
            $tags[$tag] = [];
        }
        $tags[$tag] = array_merge($tags[$tag], $alpine_directives);
    }

    return $tags;
}, 10, 2);
```

### Phase 4: Update Delegate Functions

Update the actual HTML generation methods in InsightDashboard.php to use proper escaping:

```php
private function render_unified_header(): void
{
    ?>
    <div class="odcm-unified-header-content">
        <!-- Filter Header with Icon Buttons -->
        <div class="odcm-unified-header-section odcm-unified-header-filters">
            <div class="odcm-filter-pane-header-actions">
                <!-- Static Controls: Always visible in the same order -->
                <div class="odcm-pane-icon-buttons">
                    <!-- Left arrow: close current pane (visible only when pane is open) -->
                    <button type="button"
                            class="odcm-pane-icon-button"
                            x-show="filterPaneVisible"
                            @click="closeFilterPane()"
                            title="<?php echo esc_attr__('admin.insight_dashboard.pane.close', 'order-daemon'); ?>">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                    </button>
                    
                    <!-- Right arrow: open last opened pane (visible only when pane is closed) -->
                    <button type="button"
                            class="odcm-pane-icon-button"
                            x-show="!filterPaneVisible"
                            @click="openLastOpenedPane()"
                            title="<?php echo esc_attr__('admin.insight_dashboard.pane.open_last', 'order-daemon'); ?>">
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                    </button>
                    
                    <!-- Filters tab button -->
                    <button type="button"
                            class="odcm-pane-icon-button"
                            @click="showFiltersPane()"
                            :aria-pressed="activeFilterTab === 'filters' && filterPaneVisible"
                            title="<?php echo esc_attr__('admin.insight_dashboard.filters', 'order-daemon'); ?>">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                    
                    <!-- Settings tab button -->
                    <button type="button"
                            class="odcm-pane-icon-button"
                            @click="showSettingsPane()"
                            :aria-pressed="activeFilterTab === 'settings' && filterPaneVisible"
                            title="<?php echo esc_attr__('admin.insight_dashboard.settings.title', 'order-daemon'); ?>">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </button>
                </div>

                <!-- Documentation link -->
                <a href="<?php echo esc_url(ODCM_DOCS_URL); ?>"
                   target="_blank"
                   class="odcm-docs-link"
                   title="<?php echo esc_attr__('admin.insight_dashboard.docs.view_documentation', 'order-daemon'); ?>">
                    Docs&nbsp;
                    <span class="dashicons dashicons-external"></span>
                </a>
            </div>
        </div>
    </div>
    <?php
}
```

### Phase 5: Update DashboardComponentUIToolkit

Replace the class with the new helper functions:

```php
class DashboardComponentUIToolkit
{
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

## Final Key Improvement

The only remaining fix is in the `button` tag configuration in `odcm_escape_alpine_html()`:

- Removed the non-functional `'data-' => []` wildcard
- Added specific `data-filter` and `data-target` attributes that are actually used in the codebase

## Security Validation

This approach:
1. **Aligns with WordPress security best practices**: All dynamic data is escaped in PHP at the point of output
2. **Maintains Alpine.js functionality**: Alpine.js attributes are preserved while all other content is properly escaped
3. **Uses WordPress core functions**: Leverages `wp_kses` with minimal custom configuration
4. **Doesn't create maintenance burden**: Uses standard WordPress patterns
5. **Follows the documentation**: Treats the admin like any other WordPress output

## Testing Strategy

1. **Unit Tests**: Test escaping functions with various input types
2. **Integration Tests**: Verify dashboard components work correctly with escaped content
3. **Manual Testing**: Test dashboard components on different browsers
4. **Security Testing**: Verify no XSS vulnerabilities are introduced
5. **Alpine.js Testing**: Ensure all Alpine.js functionality works as expected
