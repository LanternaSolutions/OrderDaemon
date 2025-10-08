/**
 * ODCM Shared Toast Notification System
 * 
 * Provides a consistent, reusable toast notification system for all
 * Order Daemon admin interfaces. Eliminates the need for browser alerts
 * and provides professional, in-app feedback.
 * 
 * Features:
 * - Multiple toast types (success, error, warning, info)
 * - Auto-dismiss with configurable duration
 * - Manual dismiss capability
 * - Consistent styling across all interfaces
 * - Accessible design with proper ARIA labels
 * - Queue management for multiple toasts
 * 
 * @package OrderDaemon\CompletionManager
 * @since   2.0.2
 */

(function() {
    'use strict';

    // Local debug flag resolver for gated logs
    function odcmIsDebug() {
        try {
            const w = window || {};
            let v = (typeof w.ODCM_DEBUG !== 'undefined') ? w.ODCM_DEBUG : undefined;
            if (typeof v === 'undefined' && w.odcmInsightConfig && typeof w.odcmInsightConfig.debug !== 'undefined') {
                v = w.odcmInsightConfig.debug;
            }
            if (typeof v === 'undefined' && w.odcmRuleBuilderConfig && typeof w.odcmRuleBuilderConfig.debug !== 'undefined') {
                v = w.odcmRuleBuilderConfig.debug;
            }
            if (typeof v === 'string') {
                return v.toLowerCase() === 'true';
            }
            if (v && typeof v === 'object') {
                if (Object.prototype.hasOwnProperty.call(v, 'enabled')) {
                    return !!v.enabled;
                }
            }
            return !!v;
        } catch (e) {
            return false;
        }
    }

    // Ensure we don't initialize multiple times
    if (window.ODCMToasts) {
        return;
    }

    /**
     * ODCM Toast Notification System
     */
    window.ODCMToasts = {
        // Toast storage
        toasts: [],
        
        // Configuration
        config: {
            defaultDuration: 5000,
            maxToasts: 5,
            containerClass: 'odcm-toast-container',
            toastClass: 'odcm-toast',
            positions: {
                'top-right': { top: '32px', right: '20px' },
                'top-left': { top: '32px', left: '20px' },
                'bottom-right': { bottom: '20px', right: '20px' },
                'bottom-left': { bottom: '20px', left: '20px' }
            },
            defaultPosition: 'top-right'
        },
        
        // Container element
        container: null,
        
        /**
         * Initialize the toast system
         */
        init() {
            if (this.container) {
                return; // Already initialized
            }
            
            this.createContainer();
            try { if (typeof window !== 'undefined' && (window.ODCM_DEBUG === true || (window.odcmInsightConfig && window.odcmInsightConfig.debug === true))) { console.log('ODCM: Toast system initialized'); } } catch(e) {}
        },
        
        /**
         * Create the toast container element
         */
        createContainer() {
            this.container = document.createElement('div');
            this.container.className = this.config.containerClass;
            this.container.setAttribute('aria-live', 'polite');
            this.container.setAttribute('aria-label', 'Notifications');
            
            // Set necessary positioning styles inline to ensure proper placement
            const position = this.config.positions[this.config.defaultPosition];
            Object.assign(this.container.style, {
                position: 'fixed',
                zIndex: '1000',
                display: 'flex',
                flexDirection: 'column',
                gap: '8px',
                maxWidth: '400px',
                pointerEvents: 'none', // Allow clicks through container
                ...position
            });
            
            document.body.appendChild(this.container);
        },
        
        /**
         * Show a toast notification
         * 
         * @param {string} message - The message to display
         * @param {string} type - Toast type: 'success', 'error', 'warning', 'info'
         * @param {number} duration - Auto-dismiss duration in milliseconds (0 = no auto-dismiss)
         * @param {Object} options - Additional options
         * @returns {string} Toast ID for manual removal
         */
        show(message, type = 'info', duration = null, options = {}) {
            // Ensure container exists
            this.init();
            
            // Validate parameters
            if (!message || typeof message !== 'string') {
                if (odcmIsDebug()) { console.warn('ODCM Toast: Invalid message provided'); }
                return null;
            }
            
            // Validate type
            const validTypes = ['success', 'error', 'warning', 'info'];
            if (!validTypes.includes(type)) {
                if (odcmIsDebug()) { console.warn(`ODCM Toast: Invalid type "${type}", defaulting to "info"`); }
                type = 'info';
            }
            
            // Use default duration if not specified
            if (duration === null) {
                duration = this.config.defaultDuration;
            }
            
            // Create toast object
            const toast = {
                id: this.generateId(),
                message: message,
                type: type,
                duration: duration,
                timestamp: Date.now(),
                element: null,
                timeoutId: null,
                ...options
            };
            
            // Limit number of toasts
            if (this.toasts.length >= this.config.maxToasts) {
                this.remove(this.toasts[0].id);
            }
            
            // Create and show toast element
            this.createToastElement(toast);
            this.toasts.push(toast);
            
            // Auto-dismiss if duration is set
            if (duration > 0) {
                toast.timeoutId = setTimeout(() => {
                    this.remove(toast.id);
                }, duration);
            }
            
            if (odcmIsDebug()) { console.log(`ODCM Toast: Showing ${type} toast - "${message}"`); }
            return toast.id;
        },
        
        /**
         * Create the DOM element for a toast
         * 
         * @param {Object} toast - Toast object
         */
        createToastElement(toast) {
            const element = document.createElement('div');
            element.className = `${this.config.toastClass} ${this.config.toastClass}--${toast.type}`;
            element.setAttribute('role', 'alert');
            element.setAttribute('aria-live', 'assertive');
            
            // Force the horizontal layout with flexbox
            element.style.display = 'flex';
            element.style.justifyContent = 'space-between';
            element.style.alignItems = 'center';
            
            // Create toast content
            const content = document.createElement('div');
            content.className = 'odcm-toast-content';
            content.textContent = toast.message;
            
            // Create close button
            const closeButton = document.createElement('button');
            closeButton.className = 'odcm-toast-close';
            closeButton.setAttribute('aria-label', 'Close notification');
            closeButton.innerHTML = '&times;';
            closeButton.onclick = () => this.remove(toast.id);
            
            // Assemble toast
            element.appendChild(content);
            element.appendChild(closeButton);
            
            // Only apply minimal inline styles needed for animation
            // Let the CSS handle the rest
            element.style.opacity = '0';
            element.style.transform = 'translateX(100%)';
            element.style.transition = 'all 0.3s ease';
            
            // Force additional background styling if missing
            element.style.backgroundColor = this.getBackgroundColorForType(toast.type);
            element.style.borderLeft = `4px solid ${this.getBorderColorForType(toast.type)}`;
            element.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)';
            element.style.borderRadius = '3px';
            element.style.padding = '12px';
            
            this.container.appendChild(element);
            
            // Trigger animation
            requestAnimationFrame(() => {
                element.style.opacity = '1';
                element.style.transform = 'translateX(0)';
            });
            
            toast.element = element;
        },
        
        /**
         * Get background color for toast type
         * 
         * @param {string} type - Toast type
         * @returns {string} CSS color value
         */
        getBackgroundColorForType(type) {
            const bgColors = {
                'success': '#E7F9EB', // --odcm-theme-green-200
                'error': '#FBEBED',   // --odcm-theme-red-200
                'warning': '#FEFAEF', // --odcm-theme-yellow-200
                'info': '#DEF4FF'     // --odcm-theme-blue-200
            };
            
            return bgColors[type] || bgColors.info;
        },
        
        /**
         * Get border color for toast type
         * 
         * @param {string} type - Toast type
         * @returns {string} CSS color value
         */
        getBorderColorForType(type) {
            const borderColors = {
                'success': '#29A847', // --odcm-theme-green-700
                'error': '#dc3545',   // --odcm-theme-red-700
                'warning': '#F4C95D', // --odcm-theme-yellow-700
                'info': '#007cba'     // --odcm-theme-blue-700
            };
            
            return borderColors[type] || borderColors.info;
        },
        
        /**
         * Remove a toast by ID
         * 
         * @param {string} toastId - Toast ID to remove
         */
        remove(toastId) {
            const toastIndex = this.toasts.findIndex(t => t.id === toastId);
            if (toastIndex === -1) {
                return;
            }
            
            const toast = this.toasts[toastIndex];
            
            // Clear timeout if exists
            if (toast.timeoutId) {
                clearTimeout(toast.timeoutId);
            }
            
            // Animate out - keep only necessary inline styles for animation
            if (toast.element) {
                toast.element.style.opacity = '0';
                toast.element.style.transform = 'translateX(100%)';
                
                setTimeout(() => {
                    if (toast.element && toast.element.parentNode) {
                        toast.element.parentNode.removeChild(toast.element);
                    }
                }, 300);
            }
            
            // Remove from array
            this.toasts.splice(toastIndex, 1);
            
            if (odcmIsDebug()) { console.log(`ODCM Toast: Removed toast ${toastId}`); }
        },
        
        /**
         * Remove all toasts
         */
        clear() {
            const toastIds = this.toasts.map(t => t.id);
            toastIds.forEach(id => this.remove(id));
        },
        
        /**
         * Generate a unique ID for toasts
         * 
         * @returns {string} Unique ID
         */
        generateId() {
            return `toast_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
        },
        
        /**
         * Convenience methods for different toast types
         */
        success(message, duration = null, options = {}) {
            return this.show(message, 'success', duration, options);
        },
        
        error(message, duration = null, options = {}) {
            return this.show(message, 'error', duration, options);
        },
        
        warning(message, duration = null, options = {}) {
            return this.show(message, 'warning', duration, options);
        },
        
        info(message, duration = null, options = {}) {
            return this.show(message, 'info', duration, options);
        },
        
        /**
         * Get current toast count
         * 
         * @returns {number} Number of active toasts
         */
        count() {
            return this.toasts.length;
        },
        
        /**
         * Check if toast system is initialized
         * 
         * @returns {boolean} True if initialized
         */
        isInitialized() {
            return this.container !== null;
        }
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.ODCMToasts.init();
        });
    } else {
        window.ODCMToasts.init();
    }

    if (odcmIsDebug()) { console.log('ODCM: Shared toast system loaded'); }

})();
