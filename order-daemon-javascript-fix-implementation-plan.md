# Order Daemon JavaScript Loading Fix - Implementation Plan

## Executive Summary

This document provides a comprehensive implementation plan to fix the intermittent JavaScript loading failures in the Order Daemon insight dashboard. The issues are caused by network connectivity problems that prevent the dashboard from fetching log details via REST API endpoints.

## Problem Analysis

### Root Cause
The primary issue is **network connectivity problems** causing intermittent failures when the dashboard tries to fetch log details from REST API endpoints. The errors manifest as:

1. `ODCM: Error fetching log details: TypeError: NetworkError when attempting to fetch resource.`
2. `ODCM Toast (Error): Failed to load log details`
3. `ODCM: Alpine.js failed to load. Dashboard interactivity will be limited.`

### Technical Context

**Current Architecture:**
- Dashboard uses Alpine.js 3.14.9 for reactive state management
- Auto-refresh feature polls API every 5 seconds via `fetchLogs()`
- Log details are fetched via `fetchLogDetails()` method making POST requests to `/wp-json/odcm/v1/audit-log/render-components/`
- No retry logic or robust error handling for network failures
- Alpine.js failure causes complete dashboard breakage

**Key Files Involved:**
- `assets/js/insight-dashboard.js` - Main dashboard JavaScript (lines 754, 1586)
- `src/API/AuditLogEndpoint.php` - REST API endpoint implementation
- `src/Admin/InsightDashboard.php` - PHP class that enqueues JavaScript

## Implementation Plan

### Phase 1: Network Error Recovery in fetchLogDetails()

**File:** `assets/js/insight-dashboard.js`
**Location:** `fetchLogDetails()` method (around line 754)

**Changes Required:**

```javascript
// REPLACE the existing fetchLogDetails method with this enhanced version
async fetchLogDetails(logId, viewMode = 'consolidated') {
    this.detailLoading = true;
    const maxRetries = 3;
    const baseDelay = 1000; // Start with 1 second delay
    let lastError = null;

    // Track this request for cleanup
    const requestId = `fetchDetails_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    if (window.odcmActiveRequests) {
        window.odcmActiveRequests.add(requestId);
    }

    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            // Debug logging
            if (odcmIsDebug()) {
                console.log(`ODCM: fetchLogDetails attempt ${attempt}/${maxRetries} for logId: ${logId}`);
            }

            const renderEndpoint = this.config.renderUrl || `${this.config.apiUrl}render-components/`;
            const requestPayload = {
                log_id: logId,
                include_debug: this.filters.include_debug,
                view_mode: viewMode
            };

            // Create abort controller for timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 15000); // 15 second timeout

            const response = await fetch(renderEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.config.nonce
                },
                body: JSON.stringify(requestPayload),
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                // Handle specific HTTP errors
                if (response.status === 404) {
                    return this.getNotFoundTemplate(logId);
                }

                if (response.status === 403) {
                    return this.getPermissionDeniedTemplate();
                }

                if (response.status >= 500 && attempt < maxRetries) {
                    // Server error - retry with exponential backoff
                    const delay = baseDelay * Math.pow(2, attempt - 1);
                    if (odcmIsDebug()) {
                        console.log(`ODCM: Server error ${response.status}, retrying in ${delay}ms (attempt ${attempt}/${maxRetries})`);
                    }
                    await new Promise(resolve => setTimeout(resolve, delay));
                    continue;
                }

                // For other HTTP errors, try to extract meaningful error message
                try {
                    const errorData = await response.json();
                    if (errorData && errorData.message) {
                        throw new Error(`API Error: ${errorData.message}`);
                    }
                } catch (e) {
                    // Ignore JSON parse errors
                }

                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            // Validate response structure
            if (!data || typeof data !== 'object') {
                throw new Error('Invalid API response format');
            }

            // Check for error response from server
            if (data.error) {
                if (data.html) {
                    // Server provided an error template
                    return data.html;
                }
                throw new Error(data.error || 'Server returned an error');
            }

            return data.html || this.getEmptyTemplate();

        } catch (error) {
            lastError = error;

            // Determine if we should retry
            const isNetworkError = error.name === 'TypeError' ||
                                 error.name === 'AbortError' ||
                                 error.name === 'TimeoutError' ||
                                 (error.message && (
                                     error.message.includes('NetworkError') ||
                                     error.message.includes('network') ||
                                     error.message.includes('Failed to fetch')
                                 ));

            const isServerError = error.message && (
                error.message.includes('500') ||
                error.message.includes('502') ||
                error.message.includes('503') ||
                error.message.includes('504')
            );

            const shouldRetry = (isNetworkError || isServerError) && attempt < maxRetries;

            if (shouldRetry) {
                const delay = baseDelay * Math.pow(2, attempt - 1);
                if (odcmIsDebug()) {
                    console.warn(`ODCM: Retry ${attempt + 1}/${maxRetries} for log ${logId} after ${delay}ms. Error: ${error.message}`);
                }
                await new Promise(resolve => setTimeout(resolve, delay));
                continue;
            }

            // Don't retry for other errors (404, 403, validation errors, etc.)
            if (odcmIsDebug()) {
                console.error(`ODCM: Final error for log ${logId} (no retry):`, error);
            }
            break;

        } finally {
            // Cleanup for this attempt
            clearTimeout(timeoutId);
        }
    }

    // Cleanup request tracking
    if (window.odcmActiveRequests) {
        window.odcmActiveRequests.delete(requestId);
    }

    // Handle final error state
    if (lastError) {
        console.error('ODCM: Error fetching log details after retries:', lastError);

        // Show appropriate error message based on error type
        if (lastError.name === 'AbortError') {
            this.showToast('Request timed out. Please try again.', 'error');
        } else if (lastError.message.includes('NetworkError')) {
            this.showToast('Network error occurred. Please check your connection.', 'error');
        } else {
            this.showToast('Failed to load log details. Please try again.', 'error');
        }

        // Return user-friendly error template
        return this.getErrorTemplate(lastError, logId);
    }

    this.detailLoading = false;
}
```

**New Helper Methods to Add:**

```javascript
/**
 * Get template for not found log entries
 */
getNotFoundTemplate(logId) {
    return `<div class="odcm-error-template">
        <div class="odcm-error-icon">
            <span class="dashicons dashicons-warning"></span>
        </div>
        <h3>Log Entry Not Found</h3>
        <p>Log entry #${logId} could not be found.</p>
        <p>It may have been deleted or the ID is incorrect.</p>
        <div class="odcm-error-actions">
            <button class="button button-primary" onclick="this.fetchLogs()">
                <span class="dashicons dashicons-update"></span> Refresh Log List
            </button>
            <button class="button" onclick="this.closeDetails()">
                <span class="dashicons dashicons-no-alt"></span> Close
            </button>
        </div>
        <div class="odcm-error-hint">
            <p><strong>Troubleshooting tips:</strong></p>
            <ul>
                <li>Check if the log was recently deleted</li>
                <li>Verify the log ID is correct</li>
                <li>Try refreshing the entire page</li>
                <li>Check if filters are hiding this log</li>
            </ul>
        </div>
    </div>`;
}

/**
 * Get template for permission denied errors
 */
getPermissionDeniedTemplate() {
    return `<div class="odcm-error-template">
        <div class="odcm-error-icon">
            <span class="dashicons dashicons-lock"></span>
        </div>
        <h3>Access Denied</h3>
        <p>You don't have permission to view this log entry.</p>
        <p>Please contact your administrator if you believe this is an error.</p>
        <div class="odcm-error-actions">
            <button class="button" onclick="this.closeDetails()">
                <span class="dashicons dashicons-no-alt"></span> Close
            </button>
        </div>
    </div>`;
}

/**
 * Get template for empty responses
 */
getEmptyTemplate() {
    return `<div class="odcm-empty-template">
        <div class="odcm-empty-icon">
            <span class="dashicons dashicons-info"></span>
        </div>
        <h3>No Details Available</h3>
        <p>This log entry doesn't have additional details.</p>
    </div>`;
}

/**
 * Get template for error states
 */
getErrorTemplate(error, logId) {
    const errorType = error.name || 'unknown';
    const errorMessage = error.message || 'Unknown error';

    // Sanitize error message for display
    const displayMessage = errorMessage
        .replace(/^Error:\s*/, '')
        .replace(/HTTP \d+: /, '')
        .replace(/\[.*?\]\s*/g, '')
        .trim();

    return `<div class="odcm-error-template">
        <div class="odcm-error-icon">
            <span class="dashicons dashicons-dismiss"></span>
        </div>
        <h3>Failed to Load Details</h3>
        <p><strong>Error:</strong> ${displayMessage}</p>

        <div class="odcm-error-actions">
            <button class="button button-primary" onclick="this.selectLog(this.selectedLog)">
                <span class="dashicons dashicons-update"></span> Retry
            </button>
            <button class="button" onclick="this.closeDetails()">
                <span class="dashicons dashicons-no-alt"></span> Close
            </button>
        </div>

        <div class="odcm-error-hint">
            <p><strong>Troubleshooting steps:</strong></p>
            <ol>
                <li><strong>Check your connection:</strong> Ensure you have stable internet</li>
                <li><strong>Refresh the page:</strong> Sometimes a full refresh helps</li>
                <li><strong>Try a different browser:</strong> Browser extensions can cause issues</li>
                <li><strong>Check console logs:</strong> Press F12 for technical details</li>
                <li><strong>Contact support:</strong> If the issue persists, provide the error details</li>
            </ol>
        </div>

        ${odcmIsDebug() ? `
        <div class="odcm-error-debug" style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px; font-size: 12px; font-family: monospace;">
            <strong>Debug Information:</strong><br>
            <pre style="margin: 5px 0; white-space: pre-wrap;">${errorType}: ${errorMessage}\n\n${error.stack ? error.stack : 'No stack trace available'}</pre>
        </div>
        ` : ''}
    </div>`;
}
```

### Phase 2: Enhanced Error Handling and User Feedback

**File:** `assets/js/insight-dashboard.js`
**Location:** `showToast()` method and related error handling

**Changes Required:**

```javascript
// REPLACE the existing showToast method
showToast(message, type = 'info', options = {}) {
    try {
        // Default options
        const defaultOptions = {
            persistent: false,
            timeout: 5000,
            action: null,
            actionLabel: null
        };

        const mergedOptions = { ...defaultOptions, ...options };

        // Enhanced message based on error type
        let enhancedMessage = message;
        let enhancedType = type;

        // Network-specific error handling
        if (type === 'error' && message) {
            if (message.includes('load log details') || message.includes('network') || message.includes('fetch')) {
                enhancedMessage = 'Network error: Could not load log details';
                enhancedType = 'network-error';

                // Add recovery action
                if (!mergedOptions.action) {
                    mergedOptions.action = () => {
                        if (this.selectedLog) {
                            this.selectLog(this.selectedLog);
                        } else {
                            this.fetchLogs();
                        }
                    };
                    mergedOptions.actionLabel = 'Retry';
                }
            }

            if (message.includes('timeout') || message.includes('timed out')) {
                enhancedMessage = 'Request timed out. The server may be busy.';
                enhancedType = 'timeout-error';

                if (!mergedOptions.action) {
                    mergedOptions.action = () => {
                        if (this.selectedLog) {
                            this.selectLog(this.selectedLog);
                        }
                    };
                    mergedOptions.actionLabel = 'Retry';
                }
            }
        }

        // Add network status awareness
        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            enhancedMessage = '⚠️ You are offline. ' + enhancedMessage;
            enhancedType = 'offline-error';
        }

        // Use the toast system if available
        if (typeof window !== 'undefined' && window.ODCMToasts && typeof window.ODCMToasts.addToast === 'function') {
            window.ODCMToasts.addToast(enhancedMessage, enhancedType, mergedOptions);
        } else {
            // Enhanced fallback toast with actions
            this.createFallbackToast(enhancedMessage, enhancedType, mergedOptions);
        }

        // Log to console for debugging
        if (type === 'error' || type === 'network-error' || type === 'timeout-error') {
            console.error('ODCM Toast (Error):', message);
            if (odcmIsDebug() && error) {
                console.error('Error details:', error);
            }
        } else if (odcmIsDebug()) {
            console.log('ODCM Toast (' + type + '):', message);
        }

    } catch (e) {
        console.error('ODCM: Toast system error:', e);
        // Ultimate fallback - just log to console
        if (type === 'error') {
            console.error('ODCM (Fallback Error):', message);
        } else if (odcmIsDebug()) {
            console.log('ODCM (Fallback):', message);
        }
    }
},

// ENHANCE the createFallbackToast method to support actions
createFallbackToast(message, type = 'info', options = {}) {
    try {
        const toastContainer = document.getElementById('odcm-toast-container') || this.createToastContainer();

        const toast = document.createElement('div');
        toast.className = `odcm-fallback-toast odcm-toast-${type}`;

        // Base styles
        const baseStyles = {
            background: this.getToastBackground(type),
            color: 'white',
            padding: '12px 16px',
            margin: '8px 0',
            borderRadius: '4px',
            boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
            animation: 'slideInRight 0.3s ease',
            cursor: 'pointer',
            position: 'relative',
            zIndex: '10000',
            display: 'flex',
            alignItems: 'center',
            maxWidth: '400px',
            minWidth: '250px'
        };

        // Apply styles
        Object.assign(toast.style, baseStyles);

        // Toast content container
        const contentContainer = document.createElement('div');
        contentContainer.style.flex = '1';
        contentContainer.style.minWidth = '0'; // Prevent flex item overflow

        // Message element
        const messageElement = document.createElement('span');
        messageElement.textContent = message;
        messageElement.style.display = 'block';
        messageElement.style.marginRight = '12px';
        messageElement.style.whiteSpace = 'normal';
        messageElement.style.wordBreak = 'break-word';

        contentContainer.appendChild(messageElement);

        // Action button if provided
        if (options.action && options.actionLabel) {
            const actionButton = document.createElement('button');
            actionButton.textContent = options.actionLabel;
            actionButton.style.cssText = `
                background: rgba(255,255,255,0.2);
                color: white;
                border: 1px solid rgba(255,255,255,0.3);
                borderRadius: 3px;
                padding: 4px 8px;
                marginLeft: 8px;
                cursor: pointer;
                fontSize: 12px;
                whiteSpace: nowrap;
            `;

            actionButton.addEventListener('click', (e) => {
                e.stopPropagation();
                options.action();
                this.removeFallbackToast(toast);
            });

            contentContainer.appendChild(actionButton);
        }

        // Close button
        const closeButton = document.createElement('button');
        closeButton.innerHTML = '×';
        closeButton.style.cssText = `
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            fontSize: 16px;
            lineHeight: 1;
            marginLeft: 8px;
            opacity: 0.7;
            padding: 0;
            width: 20px;
            height: 20px;
        `;

        closeButton.addEventListener('click', (e) => {
            e.stopPropagation();
            this.removeFallbackToast(toast);
        });

        toast.appendChild(contentContainer);
        toast.appendChild(closeButton);

        // Auto-remove unless persistent
        if (!options.persistent) {
            const timeoutId = setTimeout(() => {
                this.removeFallbackToast(toast);
            }, options.timeout);

            // Store timeout ID for cleanup
            toast._odcmTimeoutId = timeoutId;
        }

        // Click to dismiss (unless it has an action)
        if (!options.action) {
            toast.addEventListener('click', () => {
                this.removeFallbackToast(toast);
            });
        }

        toastContainer.appendChild(toast);

    } catch (e) {
        console.error('ODCM: Fallback toast creation failed:', e);
    }
},

// ADD this helper method
getToastBackground(type) {
    const backgrounds = {
        'info': '#28a745',
        'success': '#28a745',
        'error': '#dc3545',
        'warning': '#ffc107',
        'network-error': '#6c757d',
        'timeout-error': '#6c757d',
        'offline-error': '#6c757d'
    };
    return backgrounds[type] || backgrounds.info;
},

// ADD this method for toast removal
removeFallbackToast(toastElement) {
    try {
        if (toastElement && toastElement.parentNode) {
            // Clear timeout if it exists
            if (toastElement._odcmTimeoutId) {
                clearTimeout(toastElement._odcmTimeoutId);
            }

            // Add exit animation
            toastElement.style.animation = 'slideOutRight 0.3s ease';

            // Remove after animation completes
            setTimeout(() => {
                if (toastElement.parentNode) {
                    toastElement.parentNode.removeChild(toastElement);
                }
            }, 300);
        }
    } catch (e) {
        console.error('ODCM: Error removing toast:', e);
    }
}
```

### Phase 3: Alpine.js Fallback Mechanism

**File:** `src/Admin/InsightDashboard.php`
**Location:** `enqueue_assets()` method

**Changes Required:**

```php
// ADD this after the Alpine.js script enqueue (around line 200-250)
wp_add_inline_script(
    'alpine-js',
    "
    /**
     * Order Daemon Alpine.js Fallback System
     * Provides graceful degradation when Alpine.js fails to load
     */
    (function setupODCMAlpineFallback() {
        // Only run once
        if (window.__odcmAlpineFallbackInstalled) return;
        window.__odcmAlpineFallbackInstalled = true;

        // Check if Alpine.js is available
        function checkAlpineAvailability() {
            try {
                // Check for Alpine global
                if (typeof Alpine !== 'undefined' && typeof Alpine.data === 'function') {
                    return true;
                }

                // Check if Alpine is still loading by looking for script
                const alpineScript = document.querySelector('script[src*=\"alpine\"]');
                if (alpineScript && !alpineScript.hasAttribute('data-loaded')) {
                    return 'loading';
                }

                return false;
            } catch (e) {
                return false;
            }
        }

        // Enhanced Alpine.js detection with multiple checks
        function checkAlpineJS() {
            const alpineStatus = checkAlpineAvailability();

            if (alpineStatus === true) {
                // Alpine is available
                if (typeof window.ODCM_DEBUG !== 'undefined' && window.ODCM_DEBUG) {
                    console.log('ODCM: Alpine.js loaded successfully');
                }
                return true;
            }

            if (alpineStatus === 'loading') {
                // Alpine is still loading, check again later
                setTimeout(checkAlpineJS, 500);
                return false;
            }

            // Alpine failed to load
            console.error('ODCM: Alpine.js failed to load. Dashboard interactivity will be limited.');

            // Show user-friendly error message
            showAlpineFallbackUI();

            // Log detailed error information
            logAlpineLoadFailure();

            return false;
        }

        // Show user-friendly fallback UI
        function showAlpineFallbackUI() {
            try {
                // Check if dashboard container exists
                const dashboard = document.getElementById('odcm-insight-dashboard');
                if (!dashboard) {
                    console.warn('ODCM: Dashboard container not found for fallback UI');
                    return;
                }

                // Check if fallback was already shown
                if (dashboard.querySelector('.odcm-alpine-fallback')) {
                    return;
                }

                // Create fallback notice
                const fallbackNotice = document.createElement('div');
                fallbackNotice.className = 'odcm-alpine-fallback';
                fallbackNotice.setAttribute('role', 'alert');
                fallbackNotice.setAttribute('aria-live', 'assertive');

                // Apply styles
                fallbackNotice.style.cssText = `
                    background: #fff8e5;
                    border: 2px solid #f0c36d;
                    border-radius: 6px;
                    padding: 20px;
                    margin: 20px;
                    position: relative;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                `;

                // Create content
                fallbackNotice.innerHTML = `
                    <div style="display: flex; align-items: start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <span class="dashicons dashicons-warning" style="color: #d63638; font-size: 32px;"></span>
                        </div>
                        <div style="flex: 1;">
                            <h3 style="margin: 0 0 10px 0; color: #d63638;">Dashboard Loading Issue</h3>
                            <p style="margin: 0 0 15px 0; line-height: 1.5;">
                                The dashboard framework failed to load. This prevents interactive features from working properly.
                            </p>

                            <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 15px;">
                                <strong>Common causes:</strong>
                                <ul style="margin: 8px 0 0 20px; padding: 0;">
                                    <li>Browser extensions blocking scripts</li>
                                    <li>Content Security Policy (CSP) restrictions</li>
                                    <li>Network connectivity issues</li>
                                    <li>JavaScript errors from other plugins</li>
                                    <li>Corrupted browser cache</li>
                                </ul>
                            </div>

                            <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 15px;">
                                <strong>Try these solutions:</strong>
                                <ol style="margin: 8px 0 0 20px; padding: 0;">
                                    <li><strong>Refresh the page</strong> - Sometimes a simple refresh helps</li>
                                    <li><strong>Disable browser extensions</strong> - Try in incognito mode</li>
                                    <li><strong>Clear browser cache</strong> - Especially if you recently updated</li>
                                    <li><strong>Try a different browser</strong> - Chrome, Firefox, or Safari</li>
                                    <li><strong>Check browser console</strong> - Press F12 for technical details</li>
                                </ol>
                            </div>

                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <button onclick="window.location.reload()"
                                        style="padding: 8px 16px; background: #2271b1; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">
                                    🔄 Refresh Page
                                </button>
                                <button onclick="this.parentNode.parentNode.parentNode.style.display='none'"
                                        style="padding: 8px 16px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">
                                    Hide This Message
                                </button>
                            </div>
                        </div>
                    </div>
                `;

                // Insert at the beginning of dashboard
                dashboard.insertBefore(fallbackNotice, dashboard.firstChild);

                // Add CSS for better styling if design system is available
                addFallbackCSS();

            } catch (error) {
                console.error('ODCM: Error showing Alpine.js fallback UI:', error);
                // Fallback to simpler error message
                if (typeof window.ODCM_DEBUG !== 'undefined' && window.ODCM_DEBUG) {
                    console.error('ODCM Alpine.js Fallback Error:', error);
                }
            }
        }

        // Add additional CSS for fallback UI
        function addFallbackCSS() {
            try {
                const styleId = 'odcm-alpine-fallback-css';
                if (document.getElementById(styleId)) return;

                const style = document.createElement('style');
                style.id = styleId;
                style.textContent = `
                    /* Alpine.js Fallback Specific Styles */
                    .odcm-alpine-fallback {
                        animation: odcm-fadeIn 0.5s ease;
                    }

                    .odcm-alpine-fallback h3 {
                        font-size: 18px;
                        font-weight: 600;
                    }

                    .odcm-alpine-fallback p {
                        color: #50575e;
                    }

                    .odcm-alpine-fallback ul, .odcm-alpine-fallback ol {
                        color: #50575e;
                    }

                    .odcm-alpine-fallback button {
                        transition: all 0.2s ease;
                    }

                    .odcm-alpine-fallback button:hover {
                        opacity: 0.9;
                        transform: translateY(-1px);
                    }

                    @keyframes odcm-fadeIn {
                        from { opacity: 0; transform: translateY(-10px); }
                        to { opacity: 1; transform: translateY(0); }
                    }

                    /* Make non-interactive dashboard more usable */
                    .odcm-alpine-fallback ~ .odcm-unified-header,
                    .odcm-alpine-fallback ~ .odcm-content-grid {
                        opacity: 0.7;
                        pointer-events: none;
                        user-select: none;
                    }

                    .odcm-alpine-fallback ~ .odcm-unified-header::after,
                    .odcm-alpine-fallback ~ .odcm-content-grid::after {
                        content: "Interactive features disabled";
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(255,255,255,0.9);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-weight: 500;
                        color: #6c757d;
                        z-index: 10;
                        pointer-events: none;
                    }
                `;

                document.head.appendChild(style);

            } catch (error) {
                console.error('ODCM: Error adding fallback CSS:', error);
            }
        }

        // Log detailed information about Alpine.js load failure
        function logAlpineLoadFailure() {
            try {
                // Basic environment information
                const envInfo = {
                    userAgent: navigator.userAgent,
                    platform: navigator.platform,
                    language: navigator.language,
                    cookiesEnabled: navigator.cookieEnabled,
                    online: navigator.onLine,
                    timestamp: new Date().toISOString()
                };

                // Check for common issues
                const issues = [];

                // Check if other scripts are loading
                const scripts = document.querySelectorAll('script');
                let alpineScriptFound = false;
                let alpineScriptLoaded = false;

                scripts.forEach(script => {
                    if (script.src && script.src.includes('alpine')) {
                        alpineScriptFound = true;
                        if (script.hasAttribute('data-loaded') || script.hasAttribute('data-status')) {
                            alpineScriptLoaded = true;
                        }
                    }
                });

                if (!alpineScriptFound) {
                    issues.push('Alpine.js script tag not found');
                } else if (!alpineScriptLoaded) {
                    issues.push('Alpine.js script found but not marked as loaded');
                }

                // Check for Content Security Policy issues
                try {
                    const metaCSP = document.querySelector('meta[http-equiv=\"Content-Security-Policy\"]');
                    if (metaCSP) {
                        issues.push('Content Security Policy (CSP) meta tag found - may be blocking Alpine.js');
                    }
                } catch (e) {
                    // Ignore CSP check errors
                }

                // Log to console
                console.groupCollapsed('ODCM Alpine.js Load Failure Details');
                console.log('Environment:', envInfo);
                console.log('Potential Issues:', issues.length > 0 ? issues : ['None detected']);
                console.log('Available Globals:', {
                    Alpine: typeof Alpine,
                    window: typeof window,
                    document: typeof document,
                    navigator: typeof navigator
                });

                // Check for script errors
                if (window.onerror) {
                    console.log('Window error handler:', 'Custom error handler detected');
                }

                console.groupEnd();

                // Send to server if debugging is enabled
                if (typeof odcmInsightConfig !== 'undefined' && odcmInsightConfig.debug) {
                    try {
                        fetch(odcmInsightConfig.ajaxUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'odcm_log_alpine_failure',
                                _wpnonce: odcmInsightConfig.nonce,
                                env: JSON.stringify(envInfo),
                                issues: JSON.stringify(issues)
                            })
                        }).catch(() => {
                            // Silent failure - don't want to cause more issues
                        });
                    } catch (e) {
                        console.error('ODCM: Error logging Alpine.js failure:', e);
                    }
                }

            } catch (error) {
                console.error('ODCM: Error in Alpine.js failure logging:', error);
            }
        }

        // Check Alpine.js availability after a delay to allow for loading
        setTimeout(checkAlpineJS, 2000);

        // Also check on DOMContentLoaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', checkAlpineJS);
        } else {
            checkAlpineJS();
        }

    })();
    ",
    'after'
);
```

**Also add AJAX handler in the same file:**

```php
// ADD this method to handle Alpine.js failure logging
public function handle_log_alpine_failure_ajax(): void {
    // Verify nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
        wp_send_json_error(['message' => __('Security check failed', 'order-daemon')]);
    }

    // Get and validate input
    $env = isset($_POST['env']) ? json_decode(stripcslashes($_POST['env']), true) : [];
    $issues = isset($_POST['issues']) ? json_decode(stripcslashes($_POST['issues']), true) : [];

    // Log the failure for debugging
    $log_data = [
        'type' => 'alpine_js_failure',
        'timestamp' => current_time('mysql'),
        'user_id' => get_current_user_id(),
        'environment' => is_array($env) ? $env : [],
        'potential_issues' => is_array($issues) ? $issues : [],
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown',
        'referer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : 'unknown'
    ];

    // Log using the plugin's logging system if available
    if (function_exists('odcm_log_event')) {
        odcm_log_event(
            'Alpine.js framework failed to load',
            $log_data,
            null,
            'error',
            'frontend_error'
        );
    }

    // Also log to WordPress debug log
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('ODCM Alpine.js Load Failure: ' . json_encode($log_data));
    }

    wp_send_json_success([
        'message' => 'Alpine.js failure logged',
        'logged' => true
    ]);
}
```

**Register the AJAX handler in the init() method:**

```php
// ADD this to the existing AJAX handler registrations
add_action('wp_ajax_odcm_log_alpine_failure', [$this, 'handle_log_alpine_failure_ajax']);
```

### Phase 4: Network Status Monitoring

**File:** `assets/js/insight-dashboard.js`
**Location:** Add to the `insightDashboard()` function

**Changes Required:**

```javascript
// ADD to the insightDashboard function's return object
{
    // ... existing properties ...

    // Network status monitoring
    networkOnline: true,
    lastNetworkCheck: null,
    networkIssues: [],

    // ... existing methods ...

    // ADD network monitoring initialization
    init() {
        // ... existing init code ...

        // Initialize network monitoring
        this.setupNetworkMonitoring();

        // ... rest of existing init code ...
    },

    // ADD network monitoring setup
    setupNetworkMonitoring() {
        try {
            // Set initial network status
            this.networkOnline = navigator.onLine;

            // Listen for online/offline events
            window.addEventListener('online', () => this.handleNetworkOnline());
            window.addEventListener('offline', () => this.handleNetworkOffline());

            // Periodic network health checks
            this.startNetworkHealthMonitoring();

            // Add network status indicator to UI
            this.$nextTick(() => {
                this.createNetworkIndicator();
            });

            if (odcmIsDebug()) {
                console.log('ODCM: Network monitoring initialized');
            }

        } catch (error) {
            console.error('ODCM: Error setting up network monitoring:', error);
        }
    },

    // ADD network event handlers
    handleNetworkOnline() {
        if (this.networkOnline) return; // Already online

        this.networkOnline = true;
        this.lastNetworkCheck = new Date().toISOString();

        if (odcmIsDebug()) {
            console.log('ODCM: Network connection restored');
        }

        this.showToast('Network connection restored. Refreshing data...', 'success', {
            timeout: 3000
        });

        // Clear any network issues
        this.networkIssues = [];

        // Update UI indicator
        this.updateNetworkIndicator();

        // Auto-refresh data when connection is restored
        if (this.selectedLog) {
            this.selectLog(this.selectedLog);
        } else {
            this.fetchLogs();
        }
    },

    handleNetworkOffline() {
        if (!this.networkOnline) return; // Already offline

        this.networkOnline = false;
        this.lastNetworkCheck = new Date().toISOString();

        if (odcmIsDebug()) {
            console.log('ODCM: Network connection lost');
        }

        this.showToast('Network connection lost. Some features may be limited.', 'warning', {
            persistent: true,
            action: () => window.location.reload(),
            actionLabel: 'Retry'
        });

        // Update UI indicator
        this.updateNetworkIndicator();
    },

    // ADD network health monitoring
    startNetworkHealthMonitoring() {
        // Check network health periodically
        setInterval(() => {
            this.checkNetworkHealth();
        }, 30000); // Every 30 seconds
    },

    checkNetworkHealth() {
        try {
            // Simple health check - try to fetch a small resource
            const healthCheckUrl = this.config.apiUrl + '?healthcheck=1';

            // Use a short timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            fetch(healthCheckUrl, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': this.config.nonce
                },
                signal: controller.signal,
                cache: 'no-store'
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    this.recordNetworkIssue('healthcheck_failed', response.status);
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                if (error.name !== 'AbortError') {
                    this.recordNetworkIssue('healthcheck_error', error.message);
                }
            });

        } catch (error) {
            console.error('ODCM: Network health check error:', error);
        }
    },

    recordNetworkIssue(type, details) {
        // Avoid duplicate issues
        const existingIssue = this.networkIssues.find(issue => issue.type === type);
        if (existingIssue) {
            existingIssue.count = (existingIssue.count || 1) + 1;
            existingIssue.lastOccurrence = new Date().toISOString();
            return;
        }

        this.networkIssues.push({
            type: type,
            details: details,
            firstOccurrence: new Date().toISOString(),
            lastOccurrence: new Date().toISOString(),
            count: 1
        });

        // Update UI indicator
        this.updateNetworkIndicator();

        if (odcmIsDebug()) {
            console.warn('ODCM: Network issue recorded:', type, details);
        }
    },

    // ADD network indicator UI
    createNetworkIndicator() {
        try {
            // Check if indicator already exists
            if (document.getElementById('odcm-network-indicator')) {
                return;
            }

            const indicator = document.createElement('div');
            indicator.id = 'odcm-network-indicator';
            indicator.setAttribute('aria-live', 'polite');
            indicator.setAttribute('role', 'status');

            // Apply styles
            Object.assign(indicator.style, {
                position: 'fixed',
                bottom: '20px',
                right: '20px',
                padding: '10px 15px',
                borderRadius: '20px',
                fontSize: '13px',
                fontWeight: '500',
                zIndex: '10000',
                display: 'none',
                boxShadow: '0 2px 10px rgba(0,0,0,0.2)',
                color: 'white',
                transition: 'all 0.3s ease',
                cursor: 'pointer',
                textAlign: 'center',
                minWidth: '120px'
            });

            // Click to show details
            indicator.addEventListener('click', () => {
                this.showNetworkDetails();
            });

            document.body.appendChild(indicator);
            this.updateNetworkIndicator();

        } catch (error) {
            console.error('ODCM: Error creating network indicator:', error);
        }
    },

    updateNetworkIndicator() {
        try {
            const indicator = document.getElementById('odcm-network-indicator');
            if (!indicator) return;

            if (this.networkOnline && this.networkIssues.length === 0) {
                // Hide indicator when everything is fine
                indicator.style.display = 'none';
                return;
            }

            // Show indicator
            indicator.style.display = 'block';

            if (!this.networkOnline) {
                // Offline state
                Object.assign(indicator.style, {
                    backgroundColor: '#dc3545'
                });
                indicator.innerHTML = `
                    <span class="dashicons dashicons-dismiss" style="margin-right: 8px;"></span>
                    Offline Mode
                `;
            } else if (this.networkIssues.length > 0) {
                // Network issues detected
                Object.assign(indicator.style, {
                    backgroundColor: '#ffc107',
                    color: '#212529'
                });
                indicator.innerHTML = `
                    <span class="dashicons dashicons-warning" style="margin-right: 8px;"></span>
                    Network Issues (${this.networkIssues.length})
                `;
            }

        } catch (error) {
            console.error('ODCM: Error updating network indicator:', error);
        }
    },

    showNetworkDetails() {
        try {
            const issues = this.networkIssues;

            if (issues.length === 0) {
                this.showToast('Network status: Connected', 'info');
                return;
            }

            // Create detailed network issues modal
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10001;
                padding: 20px;
            `;

            const content = document.createElement('div');
            content.style.cssText = `
                background: white;
                border-radius: 8px;
                padding: 25px;
                max-width: 600px;
                width: 100%;
                max-height: 80vh;
                overflow-y: auto;
                box-shadow: 0 5px 20px rgba(0,0,0,0.3);
            `;

            content.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; color: #dc3545;">Network Issues Detected</h2>
                    <button id="odcm-network-modal-close" style="background: none; border: none; font-size: 24px; cursor: pointer; padding: 0;">×</button>
                </div>

                <p style="margin: 0 0 20px 0; color: #6c757d;">
                    The dashboard has detected network connectivity issues that may affect performance.
                </p>

                <div style="background: #f8d7da; border: 1px solid #f1b0b7; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
                    <strong>Current Status:</strong> ${this.networkOnline ? 'Connected (with issues)' : 'Disconnected'}
                </div>

                <h3 style="margin: 20px 0 10px 0; font-size: 16px;">Detected Issues:</h3>
                <div id="odcm-network-issues-list" style="margin-bottom: 20px;"></div>

                <h3 style="margin: 20px 0 10px 0; font-size: 16px;">Troubleshooting Steps:</h3>
                <ol style="margin: 0 0 20px 20px; padding: 0;">
                    <li><strong>Check your connection:</strong> Ensure you have a stable internet connection</li>
                    <li><strong>Refresh the page:</strong> Sometimes a full page refresh helps</li>
                    <li><strong>Try a different network:</strong> Switch from Wi-Fi to mobile data or vice versa</li>
                    <li><strong>Disable VPN/proxy:</strong> These can sometimes interfere with connections</li>
                    <li><strong>Check browser console:</strong> Press F12 for technical details to share with support</li>
                    <li><strong>Contact your administrator:</strong> There may be network restrictions in your environment</li>
                </ol>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button id="odcm-network-modal-retry" class="button button-primary">
                        <span class="dashicons dashicons-update"></span> Retry Connection
                    </button>
                    <button id="odcm-network-modal-close-btn" class="button">
                        Close
                    </button>
                </div>
            `;

            modal.appendChild(content);
            document.body.appendChild(modal);

            // Populate issues list
            const issuesList = document.getElementById('odcm-network-issues-list');
            issues.forEach((issue, index) => {
                const issueElement = document.createElement('div');
                issueElement.style.cssText = 'background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 10px;';

                issueElement.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <strong style="color: #dc3545;">Issue #${index + 1}: ${issue.type}</strong>
                        <span style="background: #6c757d; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">Occurred ${issue.count} time${issue.count > 1 ? 's' : ''}</span>
                    </div>
                    <div style="color: #6c757d; font-size: 13px; margin-bottom: 8px;">
                        ${issue.details}
                    </div>
                    <div style="font-size: 12px; color: #a0a5aa;">
                        First: ${this.formatRelativeTime(issue.firstOccurrence)} • Last: ${this.formatRelativeTime(issue.lastOccurrence)}
                    </div>
                `;

                issuesList.appendChild(issueElement);
            });

            // Add event listeners
            document.getElementById('odcm-network-modal-close').addEventListener('click', () => {
                document.body.removeChild(modal);
            });

            document.getElementById('odcm-network-modal-close-btn').addEventListener('click', () => {
                document.body.removeChild(modal);
            });

            document.getElementById('odcm-network-modal-retry').addEventListener('click', () => {
                document.body.removeChild(modal);
                window.location.reload();
            });

            // Close on escape key
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    document.body.removeChild(modal);
                    document.removeEventListener('keydown', handleEscape);
                }
            };

            document.addEventListener('keydown', handleEscape);

        } catch (error) {
            console.error('ODCM: Error showing network details:', error);
            this.showToast('Error showing network details', 'error');
        }
    },

    // ADD helper method for relative time formatting
    formatRelativeTime(isoString) {
        try {
            const date = new Date(isoString);
            const now = new Date();
            const diff = now - date;

            if (diff < 60000) { // Less than 1 minute
                return 'just now';
            } else if (diff < 3600000) { // Less than 1 hour
                const minutes = Math.floor(diff / 60000);
                return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
            } else if (diff < 86400000) { // Less than 1 day
                const hours = Math.floor(diff / 3600000);
                return `${hours} hour${hours > 1 ? 's' : ''} ago`;
            } else {
                return date.toLocaleString();
            }
        } catch (error) {
            console.error('ODCM: Error formatting relative time:', error);
            return isoString;
        }
    },

    // MODIFY existing fetch methods to check network status
    async fetchLogs() {
        if (!this.networkOnline) {
            if (odcmIsDebug()) {
                console.log('ODCM: Skipping fetchLogs - network is offline');
            }
            this.showToast('Cannot fetch logs - network is offline', 'warning');
            return;
        }

        // ... rest of existing fetchLogs method ...
    },

    async fetchNewLogs() {
        if (!this.isRefreshing && this.loading || !this.lastFetchTime || !this.networkOnline) {
            return;
        }

        // ... rest of existing fetchNewLogs method ...
    },

    async manualRefresh() {
        if (!this.networkOnline) {
            this.showToast('Cannot refresh - network is offline', 'warning');
            return;
        }

        // ... rest of existing manualRefresh method ...
    }
}
```

### Phase 5: Request Management and Cleanup

**File:** `assets/js/insight-dashboard.js`
**Location:** Add global request tracking

**Changes Required:**

```javascript
// ADD this at the beginning of the insight-dashboard.js file, after the initial comments
/**
 * Global request tracking for cleanup and monitoring
 */
if (typeof window.odcmActiveRequests === 'undefined') {
    window.odcmActiveRequests = new Set();
    window.odcmRequestCounter = 0;

    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
        // Abort active requests if possible
        // Note: This is limited since we don't have direct access to the fetch controllers
        window.odcmActiveRequests.clear();
    });
}

/**
 * Request cleanup utility
 */
function cleanupODCMRequests() {
    try {
        if (window.odcmActiveRequests && window.odcmActiveRequests.size > 0) {
            console.warn(`ODCM: Cleaning up ${window.odcmActiveRequests.size} active requests`);
            window.odcmActiveRequests.clear();
        }
    } catch (error) {
        console.error('ODCM: Error cleaning up requests:', error);
    }
}

// Add to window for global access
window.cleanupODCMRequests = cleanupODCMRequests;
```

**Modify the insightDashboard function to include request tracking:**

```javascript
// ADD to the cleanup method in insightDashboard
cleanup() {
    try {
        // Clean up active requests
        if (window.odcmActiveRequests) {
            window.odcmActiveRequests.forEach(requestId => {
                // Note: We can't directly abort requests here, but we can track them
                console.debug(`ODCM: Cleaning up request ${requestId}`);
            });
            window.odcmActiveRequests.clear();
        }

        // Stop auto-refresh
        this.stopAutoRefresh();

        // Clean up any timers
        if (this.debouncedFetchLogs) {
            clearTimeout(this.debouncedFetchLogs.timer);
        }

        // Clean up network monitoring
        window.removeEventListener('online', this.handleNetworkOnline);
        window.removeEventListener('offline', this.handleNetworkOffline);

    } catch (error) {
        console.error('ODCM: Error during cleanup:', error);
    }
}
```

## Implementation Checklist

### JavaScript Changes (`assets/js/insight-dashboard.js`)

- [ ] ✅ Add global request tracking at the top of the file
- [ ] ✅ Replace `fetchLogDetails()` method with enhanced version
- [ ] ✅ Add helper methods: `getNotFoundTemplate()`, `getPermissionDeniedTemplate()`, `getEmptyTemplate()`, `getErrorTemplate()`
- [ ] ✅ Replace `showToast()` method with enhanced version
- [ ] ✅ Enhance `createFallbackToast()` method
- [ ] ✅ Add `getToastBackground()` and `removeFallbackToast()` methods
- [ ] ✅ Add network monitoring methods to insightDashboard
- [ ] ✅ Add `formatRelativeTime()` helper method
- [ ] ✅ Modify fetch methods to check network status
- [ ] ✅ Add cleanup method

### PHP Changes (`src/Admin/InsightDashboard.php`)

- [ ] ✅ Add Alpine.js fallback script after Alpine.js enqueue
- [ ] ✅ Add `handle_log_alpine_failure_ajax()` method
- [ ] ✅ Register AJAX handler in `init()` method

## Testing Plan

### Unit Testing

1. **Network Error Recovery**
   - Simulate network failures and verify retry logic
   - Test exponential backoff timing
   - Verify error templates are shown appropriately

2. **Alpine.js Fallback**
   - Test with Alpine.js loading blocked
   - Verify fallback UI is shown
   - Test error logging functionality

3. **Network Monitoring**
   - Test online/offline event handling
   - Verify network indicator UI
   - Test health check functionality

### Integration Testing

1. **End-to-End Workflow**
   - Test complete dashboard workflow with simulated network issues
   - Verify graceful degradation
   - Test recovery when network is restored

2. **Cross-Browser Testing**
   - Test in Chrome, Firefox, Safari, Edge
   - Verify fallback mechanisms work consistently

3. **Performance Testing**
   - Test with slow network connections
   - Verify no memory leaks with request cleanup
   - Test auto-refresh behavior under network stress

### User Acceptance Testing

1. **Error Scenarios**
   - Network disconnect during log loading
   - Server errors (500, 502, 503)
   - Timeout scenarios
   - Alpine.js loading failure

2. **Recovery Scenarios**
   - Network reconnection
   - Page refresh
   - Manual retry

3. **Usability Testing**
   - Error message clarity
   - Recovery option effectiveness
   - Overall user experience with failures

## Rollback Plan

If issues are encountered:

1. **Feature Flags**
   - Add feature flags to enable/disable new functionality:
   ```javascript
   // In insight-dashboard.js
   window.ODCM_FEATURE_FLAGS = {
       network_retry: true,
       alpine_fallback: true,
       network_monitoring: true
   };
   ```

2. **Fallback to Original Behavior**
   - Wrap new functionality in feature flag checks:
   ```javascript
   async fetchLogDetails(logId, viewMode) {
       if (window.ODCM_FEATURE_FLAGS?.network_retry === false) {
           // Original implementation
           return this.fetchLogDetailsOriginal(logId, viewMode);
       }
       // New implementation
       // ...
   }
   ```

3. **Server-Side Control**
   - Add WordPress option to control feature flags:
   ```php
   // In InsightDashboard.php
   function get_feature_flags() {
       return [
           'network_retry' => get_option('odcm_enable_network_retry', true),
           'alpine_fallback' => get_option('odcm_enable_alpine_fallback', true),
           'network_monitoring' => get_option('odcm_enable_network_monitoring', true)
       ];
   }
   ```

## Monitoring and Analytics

Add monitoring for the new features:

```javascript
// Add to insightDashboard
logFeatureUsage(feature, details = {}) {
    try {
        if (!this.config.debug) return;

        const data = {
            feature: feature,
            timestamp: new Date().toISOString(),
            user_id: this.config.userId || 'unknown',
            ...details
        };

        // Send to server
        fetch(this.config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'odcm_log_feature_usage',
                _wpnonce: this.config.nonce,
                data: JSON.stringify(data)
            })
        }).catch(() => {
            // Silent failure
        });

    } catch (error) {
        console.error('ODCM: Error logging feature usage:', error);
    }
}
```

## Documentation Updates

Update user documentation to include:

1. **Troubleshooting Guide**
   - Network connectivity issues
   - Alpine.js loading problems
   - Error recovery procedures

2. **Administrator Guide**
   - Feature flag configuration
   - Debugging network issues
   - Monitoring dashboard health

3. **Developer Guide**
   - Extending error handling
   - Adding new fallback mechanisms
   - Customizing network monitoring

## Success Metrics

1. **Reduction in Support Tickets**
   - Measure decrease in "dashboard not working" tickets
   - Track specific error-related support requests

2. **Improved User Experience**
   - User satisfaction surveys
   - Session duration and engagement metrics

3. **Technical Metrics**
   - Error rate reduction
   - Successful retry rate
   - Network recovery success rate

4. **Performance Metrics**
   - No increase in page load time
   - Minimal memory overhead
   - Efficient network usage

## Implementation Timeline

| Phase | Duration | Tasks |
|-------|----------|-------|
| 1. Preparation | 1 day | Code review, setup development environment |
| 2. Core Implementation | 3 days | Network retry logic, error handling |
| 3. Fallback Systems | 2 days | Alpine.js fallback, network monitoring |
| 4. Testing | 2 days | Unit tests, integration tests |
| 5. Deployment | 1 day | Staging deployment, final checks |
| 6. Monitoring | Ongoing | Performance monitoring, error tracking |

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Increased JS bundle size | Medium | Low | Code splitting, lazy loading |
| Performance degradation | Low | Medium | Performance testing, optimization |
| Compatibility issues | Medium | High | Cross-browser testing, polyfills |
| User confusion | Low | Medium | Clear error messages, documentation |
| Implementation delays | Medium | Medium | Agile approach, prioritization |

## Conclusion

This implementation plan provides a comprehensive approach to fixing the JavaScript loading issues in the Order Daemon insight dashboard. By implementing robust network error recovery, enhanced error handling, Alpine.js fallback mechanisms, and network status monitoring, the dashboard will become significantly more resilient to network connectivity problems while maintaining a good user experience even under adverse conditions.

The changes are designed to be backward compatible and can be gradually rolled out with feature flags to minimize risk. Comprehensive testing and monitoring will ensure that the new functionality works reliably across different browsers and network conditions.
