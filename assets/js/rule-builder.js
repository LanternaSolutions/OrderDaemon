/**
 * Modern Rule Builder Alpine.js Application
 *
 * Implements a compact, accessible, and modern interface for building completion rules.
 * Features:
 * - Compact draggable accordion-style layout
 * - Inline expander component selection (no dropdowns)
 * - Natural language rule summaries
 * - Full keyboard navigation and accessibility
 * - Design system integration
 *
 * @package OrderDaemon\CompletionManager
 * @since   1.0.0
 */

/**
 * Modern Alpine.js component for the Rule Builder
 */
// Lightweight debug flag resolver for gated logs
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

function ruleBuilder() {
    return {
        // Core State
        loading: true,
        saving: false,
        components: {
            triggers: [],
            conditions: [],
            primaryActions: [],
            secondaryActions: []
        },
        rule: {
            trigger: null,
            conditions: [],
            primaryAction: null,
            secondaryActions: []
        },
        
        // License state detection
        get isLicensed() {
            return (window.odcmRuleBuilderConfig && window.odcmRuleBuilderConfig.license && !!window.odcmRuleBuilderConfig.license.active) || false;
        },
        
        
        // Modern UI State for Inline Expanders
        isAddingTrigger: false,
        isAddingCondition: false,
        isAddingPrimaryAction: false,
        isAddingAction: false,
        
        // Search Terms for Inline Selectors
        triggerSearchTerm: '',
        conditionSearchTerm: '',
        primaryActionSearchTerm: '',
        actionSearchTerm: '',
        
        // Accordion State Management
        editingTriggerIndex: null,
        editingConditionIndex: null,
        editingPrimaryAction: false,
        editingActionIndex: null,
        
        // Drag & Drop State
        draggedCondition: null,
        dragOverIndex: null,

        // Computed Properties for Filtered Components
        get filteredTriggers() {
            if (!this.triggerSearchTerm) return this.components.triggers;
            const term = this.triggerSearchTerm.toLowerCase();
            return this.components.triggers.filter(trigger => 
                trigger.label.toLowerCase().includes(term) || 
                trigger.description.toLowerCase().includes(term)
            );
        },

        get filteredConditions() {
            if (!this.conditionSearchTerm) return this.components.conditions;
            const term = this.conditionSearchTerm.toLowerCase();
            return this.components.conditions.filter(condition => 
                condition.label.toLowerCase().includes(term) || 
                condition.description.toLowerCase().includes(term)
            );
        },

        get filteredPrimaryActions() {
            if (!this.primaryActionSearchTerm) return this.components.primaryActions || [];
            const term = this.primaryActionSearchTerm.toLowerCase();
            return (this.components.primaryActions || []).filter(action => 
                action.label.toLowerCase().includes(term) || 
                action.description.toLowerCase().includes(term)
            );
        },

        get filteredSecondaryActions() {
            if (!this.actionSearchTerm) return this.components.secondaryActions || [];
            const term = this.actionSearchTerm.toLowerCase();
            return (this.components.secondaryActions || []).filter(action => 
                action.label.toLowerCase().includes(term) || 
                action.description.toLowerCase().includes(term)
            );
        },

        // Legacy compatibility
        get filteredActions() {
            return this.filteredSecondaryActions;
        },

        // Initialization - PHP-FIRST ARCHITECTURE
        // All data is pre-loaded from PHP, no API calls needed
        init() {
            
            // Check if configuration is available
            if (typeof window.odcmRuleBuilderConfig === 'undefined') {
                console.error('🔄 INIT ERROR: Configuration object not found! Rule Builder cannot initialize.');
                this.loading = false;
                return;
            }
            
            
            // Make this instance globally available for widgets
            window.ruleBuilderInstance = this;
            
            // Load pre-prepared data from PHP (no API calls!)
            this.components = window.odcmRuleBuilderConfig.components || {
                triggers: [],
                conditions: [],
                primaryActions: [],
                secondaryActions: [],
                actions: []
            };
            
            this.rule = window.odcmRuleBuilderConfig.rule || {
                trigger: null,
                conditions: [],
                primaryAction: {
                    id: 'change_status_to_completed',
                    settings: {}
                },
                secondaryActions: []
            };
            
            // Store prepared fields for immediate use
            this.preparedFields = window.odcmRuleBuilderConfig.preparedFields || {};
            // Pre-generated summaries from PHP (used for initial render)
            this.summaries = window.odcmRuleBuilderConfig.summaries || {};
            
            // DEBUG: Log summaries loading
            
            // Initialize last save data for auto-save comparison
            this.lastSaveData = JSON.stringify(this.rule);
            
            // Setup watcher to update hidden form field when rule changes
            this.$watch('rule', value => {
                const hiddenField = document.getElementById('odcm_rule_data_field');
                if (hiddenField) {
                    hiddenField.value = JSON.stringify(value);
                }
            });
            
            // Start silent auto-save cycle
            this.autoSave();
            
            this.loading = false;
        },

        /**
         * Get component summary using client-side generation (no API calls).
         *
         * @param {object|null} component - Component object
         * @param {string} type - Component type
         * @param {number|null} index - Index for array components
         * @return {string} HTML summary or empty string
         */
        getComponentSummary(component, type, index = null) {
            if (!component || !component.id) {
                return '';
            }

            // Use client-side generation instead of cached summaries
            return this.generateClientSideSummary(component, type, index);
        },

        /**
         * Generate complete component summary client-side (no API calls).
         *
         * @param {object} component - Component object with id and settings
         * @param {string} componentType - Component type (trigger, condition, action, primaryAction)
         * @param {number|null} index - Index for array components
         * @return {string} HTML summary
         */
        generateClientSideSummary(component, componentType, index) {
            if (!component || !component.id) {
                return this.getFallbackSummary(componentType);
            }

            const componentDef = this.getComponentDefinition(componentType, component.id);
            const settings = component.settings || {};
            
            // Get base label
            const label = componentDef?.label || component.id;
            
            // For triggers and actions, just show the label
            if (componentType === 'trigger') {
                return `<span class="odcm-summary-title">${this.escapeHtml(label)}</span>`;
            }
            
            if (componentType === 'action' || componentType === 'primaryAction') {
                return `<span class="odcm-summary-title">${this.escapeHtml(label)}</span>`;
            }
            
            // For conditions, build complete summary with all information
            return this.buildConditionSummary(label, settings, componentDef, component.id);
        },

        /**
         * Build comprehensive condition summary with all essential information.
         *
         * @param {string} label - Component label
         * @param {object} settings - Component settings
         * @param {object} componentDef - Component definition from registry
         * @param {string} componentId - Component ID for specific logic
         * @return {string} HTML summary
         */
        buildConditionSummary(label, settings, componentDef, componentId) {
            // Use component-specific logic when available
            const specificSummary = this.getComponentSpecificSummary(componentId, settings, componentDef);
            if (specificSummary) {
                return specificSummary;
            }

            // Generic condition summary builder
            const parts = {
                label: label,
                values: this.extractValues(settings, componentDef),
                operator: this.extractOperator(settings),
                matchMode: this.extractMatchMode(settings)
            };
            
            // Format: "Label: Values Operator (Match Mode)"
            let summary = `<span class="odcm-summary-title">${this.escapeHtml(parts.label)}</span>`;
            
            if (parts.values) {
                summary += `: <span class="odcm-summary-values">${this.escapeHtml(parts.values)}</span>`;
            }
            
            if (parts.operator) {
                summary += ` <span class="odcm-summary-operator">${this.escapeHtml(parts.operator)}</span>`;
            }
            
            if (parts.matchMode) {
                summary += ` <span class="odcm-summary-match">(${this.escapeHtml(parts.matchMode)})</span>`;
            }
            
            return summary;
        },

        /**
         * Get component-specific summary for known components.
         *
         * @param {string} componentId - Component ID
         * @param {object} settings - Component settings
         * @param {object} componentDef - Component definition
         * @return {string|null} HTML summary or null for generic handling
         */
        getComponentSpecificSummary(componentId, settings, componentDef) {
            switch (componentId) {
                case 'product_category':
                    return this.buildProductCategorySummary(settings, componentDef);
                
                case 'order_item_count':
                    return this.buildOrderItemCountSummary(settings, componentDef);
                
                case 'order_total_amount':
                    return this.buildOrderTotalSummary(settings, componentDef);
                
                case 'customer_role':
                    return this.buildCustomerRoleSummary(settings, componentDef);
                
                case 'payment_method':
                    return this.buildPaymentMethodSummary(settings, componentDef);
                
                case 'product_selection':
                    return this.buildProductSelectionSummary(settings, componentDef);
                
                default:
                    return null; // Use generic builder
            }
        },

        /**
         * Build Product Category condition summary.
         */
        buildProductCategorySummary(settings, componentDef) {
            const categories = this.formatValue(settings.categories || [], componentDef?.schema?.properties?.categories);
            const matchType = settings.match_type === 'all' ? 'All match' : 'Any match';
            
            return `<span class="odcm-summary-title">Product Category</span>: <span class="odcm-summary-values">${this.escapeHtml(categories)}</span> <span class="odcm-summary-match">(${matchType})</span>`;
        },

        /**
         * Build Order Item Count condition summary.
         */
        buildOrderItemCountSummary(settings, componentDef) {
            const operator = this.mapOperatorToSymbol(settings.operator || 'gte');
            const countType = settings.count_type === 'total_quantity' ? 'total quantity' : 'unique products';
            
            // Get the actual count value based on operator
            const valueKey = `${settings.operator}_value`;
            const count = settings[valueKey] || settings.count || 0;
            
            return `<span class="odcm-summary-title">Order Items</span>: <span class="odcm-summary-operator">${this.escapeHtml(operator)}</span> <span class="odcm-summary-values">${count} ${countType}</span>`;
        },

        /**
         * Build Order Total Amount condition summary.
         */
        buildOrderTotalSummary(settings, componentDef) {
            const operator = this.mapOperatorToSymbol(settings.operator || 'gte');
            
            // Get the actual amount value based on operator
            const valueKey = `${settings.operator}_value`;
            const amount = settings[valueKey] || settings.amount || 0;
            
            return `<span class="odcm-summary-title">Order Total</span>: <span class="odcm-summary-operator">${this.escapeHtml(operator)}</span> <span class="odcm-summary-values">$${amount}</span>`;
        },

        /**
         * Build Customer Role condition summary.
         */
        buildCustomerRoleSummary(settings, componentDef) {
            const roles = this.formatValue(settings.roles || [], componentDef?.schema?.properties?.roles);
            const includeGuests = settings.include_guests ? 'includes guests' : 'excludes guests';
            
            return `<span class="odcm-summary-title">Customer Role</span>: <span class="odcm-summary-values">${this.escapeHtml(roles)}</span> <span class="odcm-summary-match">(${includeGuests})</span>`;
        },

        /**
         * Build Payment Method condition summary.
         */
        buildPaymentMethodSummary(settings, componentDef) {
            const methods = this.formatValue(settings.methods || [], componentDef?.schema?.properties?.methods);
            const matchMode = settings.match_mode === 'none' ? 'None match' : 'Any match';
            
            return `<span class="odcm-summary-title">Payment Method</span>: <span class="odcm-summary-values">${this.escapeHtml(methods)}</span> <span class="odcm-summary-match">(${matchMode})</span>`;
        },

        /**
         * Build Product Selection condition summary.
         */
        buildProductSelectionSummary(settings, componentDef) {
            const products = this.formatValue(settings.products || [], componentDef?.schema?.properties?.products);
            const includeVariations = settings.include_variations ? 'with variations' : '';
            const matchMode = settings.match_mode === 'all' ? 'All match' : 'Any match';
            
            let summary = `<span class="odcm-summary-title">Product Selection</span>: <span class="odcm-summary-values">${this.escapeHtml(products)}</span> <span class="odcm-summary-match">(${matchMode}`;
            if (includeVariations) {
                summary += `, ${includeVariations}`;
            }
            summary += ')</span>';
            
            return summary;
        },

        /**
         * Extract and format values from settings.
         *
         * @param {object} settings - Component settings
         * @param {object} componentDef - Component definition
         * @return {string|null} Formatted values string
         */
        extractValues(settings, componentDef) {
            const schema = componentDef?.schema?.properties || {};
            
            // Priority order for value extraction
            const valueKeys = ['categories', 'products', 'roles', 'methods', 'types', 'amount', 'count'];
            
            for (const key of valueKeys) {
                if (settings[key] !== undefined && settings[key] !== null) {
                    return this.formatValue(settings[key], schema[key]);
                }
            }
            
            return null;
        },

        /**
         * Format a value for display in summary.
         *
         * @param {*} value - The value to format
         * @param {object} propertySchema - Schema for the property
         * @return {string} Formatted value
         */
        formatValue(value, propertySchema) {
            if (Array.isArray(value)) {
                if (value.length === 0) return 'none selected';
                
                // Map enum values to labels if available
                const enumOptions = propertySchema?.items?.enum || propertySchema?.enum || {};
                const labels = value.map(v => enumOptions[v] || v);
                
                // Truncate long lists
                if (labels.length > 3) {
                    return `${labels.slice(0, 3).join(', ')} (+${labels.length - 3} more)`;
                }
                return labels.join(', ');
            }
            
            // Single values - map through enum if available
            const enumOptions = propertySchema?.enum || {};
            return enumOptions[value] || value;
        },

        /**
         * Extract operator from settings.
         *
         * @param {object} settings - Component settings
         * @return {string|null} Operator symbol or null
         */
        extractOperator(settings) {
            // Check various operator keys
            const operatorKeys = ['operator', 'match_mode', 'comparison', 'condition'];
            
            for (const key of operatorKeys) {
                if (settings[key]) {
                    return this.mapOperatorToSymbol(settings[key]);
                }
            }
            
            return null;
        },

        /**
         * Map operator strings to symbols.
         *
         * @param {string} operator - Operator string
         * @return {string} Operator symbol
         */
        mapOperatorToSymbol(operator) {
            const operatorMap = {
                // Comparison operators
                'greater_than': '>',
                'less_than': '<',
                'greater_than_or_equal': '≥',
                'less_than_or_equal': '≤',
                'equals': '=',
                'not_equals': '≠',
                'gte': '≥',
                'lte': '≤',
                'gt': '>',
                'lt': '<',
                'eq': '=',
                'neq': '≠',
                
                // Amount/count specific
                'amount_gt': '>',
                'amount_lt': '<',
                'amount_gte': '≥',
                'amount_lte': '≤',
                'amount_eq': '=',
                'count_gt': '>',
                'count_lt': '<',
                'count_gte': '≥',
                'count_lte': '≤',
                'count_eq': '=',
                
                // Inclusion operators
                'includes': '∈',
                'excludes': '∉',
                'contains': '∈',
                'not_contains': '∉'
            };
            
            return operatorMap[operator] || operator;
        },

        /**
         * Extract match mode from settings.
         *
         * @param {object} settings - Component settings
         * @return {string|null} Match mode description
         */
        extractMatchMode(settings) {
            // Check for match mode indicators
            if (settings.match_type) {
                const matchTypeMap = {
                    'any': 'Any match',
                    'all': 'All match',
                    'none': 'None match'
                };
                return matchTypeMap[settings.match_type] || settings.match_type;
            }
            
            if (settings.match_mode) {
                const matchModeMap = {
                    'any': 'Any match',
                    'all': 'All match',
                    'none': 'None match',
                    'only': 'Only'
                };
                return matchModeMap[settings.match_mode] || settings.match_mode;
            }
            
            // Check for boolean flags
            const booleanFlags = [];
            if (settings.include_guests) booleanFlags.push('includes guests');
            if (settings.include_variations) booleanFlags.push('with variations');
            if (settings.exclude_guests) booleanFlags.push('excludes guests');
            
            return booleanFlags.length > 0 ? booleanFlags.join(', ') : null;
        },

        /**
         * Get fallback summary for invalid/missing components.
         *
         * @param {string} componentType - Component type for context
         * @return {string} HTML fallback summary
         */
        getFallbackSummary(componentType) {
            const fallbackLabels = {
                'trigger': 'Trigger',
                'condition': 'Condition',
                'action': 'Action',
                'primaryAction': 'Primary Action'
            };

            const label = fallbackLabels[componentType] || 'Component';
            
            return `<span class="odcm-summary-title">${this.escapeHtml(label)}</span>`;
        },

        /**
         * Escape HTML for safe display.
         *
         * @param {string} text - Text to escape
         * @return {string} Escaped text
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Get minimal title-only fallback summary.
         *
         * @param {object} component - Component object
         * @param {string} type - Component type
         * @return {string} HTML fallback summary
         */
        getComponentFallbackSummary(component, type) {
            // Try to get component definition for label
            let label = 'Component';
            
            try {
                const componentDef = this.getComponentDefinition(type, component.id);
                if (componentDef && componentDef.label) {
                    label = componentDef.label;
                }
            } catch (e) {
                // Use type-based fallback
                const fallbackLabels = {
                    'trigger': 'Trigger',
                    'condition': 'Condition',
                    'action': 'Action',
                    'primaryAction': 'Primary Action'
                };
                label = fallbackLabels[type] || 'Component';
            }

            // Return minimal HTML (server uses sanitized segments; labels come from registry)
            return `<span class="odcm-summary-title">${label}</span>`;
        },

        // API Methods
        async loadComponents() {
            try {
                if (odcmIsDebug()) { console.log('ODCM: Starting loadComponents...'); }
                if (odcmIsDebug()) { console.log('ODCM: API URL:', window.odcmRuleBuilderConfig.apiUrl); }
                if (odcmIsDebug()) { console.log('ODCM: Nonce:', window.odcmRuleBuilderConfig.nonce ? 'Present' : 'Missing'); }
                
                // Build URL with rule_id parameter for component state detection
                const apiUrl = new URL(window.odcmRuleBuilderConfig.apiUrl);
                if (window.odcmRuleBuilderConfig.postId) {
                    apiUrl.searchParams.set('rule_id', window.odcmRuleBuilderConfig.postId);
                }
                
                // Use direct fetch with nonce header (same pattern as InsightDashboard)
                const response = await fetch(apiUrl.toString(), {
                    headers: {
                        'X-WP-Nonce': window.odcmRuleBuilderConfig.nonce
                    }
                });
                
                if (odcmIsDebug()) { console.log('ODCM: Response status:', response.status); }
                if (odcmIsDebug()) { console.log('ODCM: Response ok:', response.ok); }
                
                if (!response.ok) {
                    const errorText = await response.text();
                    if (odcmIsDebug()) { console.error('ODCM: Response error text:', errorText); }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                if (odcmIsDebug()) { console.log('ODCM: Raw API response:', data); }
                
                this.components = data;
                if (odcmIsDebug()) { console.log('ODCM: Components stored:', this.components); }
                if (odcmIsDebug()) { console.log('ODCM: Triggers count:', this.components.triggers ? this.components.triggers.length : 'undefined'); }
                if (odcmIsDebug()) { console.log('ODCM: Conditions count:', this.components.conditions ? this.components.conditions.length : 'undefined'); }
                if (odcmIsDebug()) { console.log('ODCM: Actions count:', this.components.actions ? this.components.actions.length : 'undefined'); }
                
                // Test filtered components
                if (odcmIsDebug()) { console.log('ODCM: Testing filtered components...'); }
                if (odcmIsDebug()) { console.log('ODCM: filteredTriggers:', this.filteredTriggers.length); }
                if (odcmIsDebug()) { console.log('ODCM: filteredConditions:', this.filteredConditions.length); }
                if (odcmIsDebug()) { console.log('ODCM: filteredActions:', this.filteredActions.length); }
                
            } catch (error) {
                if (odcmIsDebug()) { console.error('ODCM: Failed to load components:', error); }
                throw error;
            }
        },

        async loadRule() {
            try {
                const loadedRule = await wp.apiFetch({
                    path: `/odcm/v1/rule/${odcmRuleBuilderConfig.postId}`
                });
                
                // Ensure rule structure matches our modern format
                this.rule = {
                    trigger: loadedRule.trigger || null,
                    conditions: loadedRule.conditions || [],
                    primaryAction: loadedRule.primaryAction || this.getDefaultPrimaryAction(),
                    secondaryActions: loadedRule.secondaryActions || []
                };
                
                if (odcmIsDebug()) { console.log('ODCM: Loaded rule:', this.rule); }
            } catch (error) {
                if (odcmIsDebug()) { console.error('ODCM: Failed to load rule:', error); }
                throw error;
            }
        },

        // Get default primary action based on freemium model
        getDefaultPrimaryAction() {
            // For free users, default to "Complete Order" action
            // For premium users, they can choose any primary action
            const freeAction = this.components.primaryActions?.find(action => 
                action.id === 'change_status_to_completed' && action.accessible
            );
            
            if (freeAction) {
                return {
                    id: 'change_status_to_completed',
                    settings: {}
                };
            }
            
            return null;
        },

        // Check if user has access to multiple primary actions (premium feature)
        canChangePrimaryAction() {
            const accessiblePrimaryActions = this.components.primaryActions?.filter(action => action.accessible) || [];
            return accessiblePrimaryActions.length > 1;
        },

    async saveRule() {
        this.saving = true;
        if (odcmIsDebug()) { console.log('🔄 SAVE: Starting rule save process'); }

        try {
            // CRITICAL: Always update the hidden form field directly
            // This ensures WordPress native save works even if API save fails
            const hiddenField = document.getElementById('odcm_rule_data_field');
            if (hiddenField) {
                hiddenField.value = JSON.stringify(this.rule);
                if (odcmIsDebug()) { console.log('🔄 SAVE: Updated hidden form field with latest rule data'); }
            } else {
                if (odcmIsDebug()) { console.error('🔄 SAVE ERROR: Hidden form field not found - WordPress native save may fail'); }
            }

            // Ensure we have a valid postId from the config
            if (!odcmRuleBuilderConfig.postId) {
                if (odcmIsDebug()) { console.error('🔄 SAVE ERROR: Missing postId in configuration'); }
                this.showSaveStatus('error');
                return false;
            }

            // Log what we're about to save for debugging
            if (odcmIsDebug()) { console.log('🔄 SAVE: Saving rule data:', JSON.stringify(this.rule)); }
            if (odcmIsDebug()) { console.log('🔄 SAVE: Using API URL:', odcmRuleBuilderConfig.api.rule); }
            
            // Make sure the nonce is present
            if (!odcmRuleBuilderConfig.api.nonce) {
                if (odcmIsDebug()) { console.error('🔄 SAVE ERROR: Missing nonce in configuration'); }
                this.showSaveStatus('error');
                return false;
            }
            
            // Log the nonce status and API endpoint
            if (odcmIsDebug()) { console.log('🔄 SAVE: Nonce present:', !!odcmRuleBuilderConfig.api.nonce); }
            if (odcmIsDebug()) { console.log('🔄 SAVE: API endpoint:', odcmRuleBuilderConfig.api.rule); }
            
            // Make direct fetch request with proper API URL from configuration
            const response = await fetch(odcmRuleBuilderConfig.api.rule, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': odcmRuleBuilderConfig.api.nonce
                },
                body: JSON.stringify(this.rule),
                credentials: 'same-origin'
            });
            
            if (odcmIsDebug()) { console.log('ODCM: Response status:', response.status); }
            
            // Parse the JSON response
            const responseData = await response.json();
            if (odcmIsDebug()) { console.log('🔄 SAVE: API response:', responseData); }
            
            if (response.ok && responseData.success) {
                this.showSaveStatus('saved');
                if (odcmIsDebug()) { console.log('✅ SAVE SUCCESS: Rule saved successfully via API'); }
                
                // Update last save data to prevent redundant auto-saves
                this.lastSaveData = JSON.stringify(this.rule);
                
                // Trigger WordPress admin notification (for the publish button feedback)
                if (window.parent.document) {
                    // Get the publish button and dispatch a click event to trigger WordPress notification
                    const publishButton = window.parent.document.getElementById('publish');
                    if (publishButton) {
                        if (odcmIsDebug()) { console.log('ODCM: Triggering WordPress publish notification'); }
                        const event = new Event('odcm-publish-success', { bubbles: true });
                        publishButton.dispatchEvent(event);
                    }
                }
                
                return true;
            } else {
                if (odcmIsDebug()) { console.error('❌ SAVE ERROR: API response indicated failure:', responseData); }
                if (odcmIsDebug()) { console.error('❌ SAVE ERROR: Response status:', response.status); }
                this.showToast(
                    `Failed to save rule via API: ${responseData.message || 'Unknown error'}. Try using the Publish button instead.`, 
                    'error', 
                    7000
                );
                return false;
            }
        } catch (error) {
            if (odcmIsDebug()) { console.error('❌ SAVE ERROR: Exception while saving rule:', error); }
            this.showToast(
                `Error saving rule via API: ${error.message}. Try using the WordPress Publish button instead.`, 
                'error', 
                7000
            );
            return false;
        } finally {
            this.saving = false;
        }
    },

        // Toast Notification System
        showToast(message, type = 'info', duration = null) {
            if (window.ODCMToasts) {
                return window.ODCMToasts.show(message, type, duration);
            } else {
                // Fallback to console if toast system not available
                if (odcmIsDebug()) { console.log(`ODCM Toast (${type}): ${message}`); }
                return null;
            }
        },

        // Save Status Management (Uses shared toast system only)
        showSaveStatus(type) {
            if (type === 'saved') {
                this.showToast(
                    odcmRuleBuilderConfig.i18n.saved || 'Rule saved successfully!', 
                    'success', 
                    3000
                );
            } else if (type === 'error') {
                this.showToast(
                    odcmRuleBuilderConfig.i18n.error || 'Failed to save rule. Please try again.', 
                    'error', 
                    7000
                );
            }
        },

        // Accordion Management
        toggleEdit(type, index) {
            if (type === 'trigger') {
                this.editingTriggerIndex = this.editingTriggerIndex === index ? null : index;
            } else if (type === 'condition') {
                this.editingConditionIndex = this.editingConditionIndex === index ? null : index;
            } else if (type === 'action') {
                this.editingActionIndex = this.editingActionIndex === index ? null : index;
            }
        },

        
        announceToScreenReader(message) {
            try {
                // Create or reuse a hidden aria-live region for screen readers only
                let sr = document.getElementById('odcm-sr-live');
                if (!sr) {
                    sr = document.createElement('div');
                    sr.id = 'odcm-sr-live';
                    sr.setAttribute('role', 'status');
                    sr.setAttribute('aria-live', 'polite');
                    sr.setAttribute('aria-atomic', 'true');
                    // Visually hidden styles
                    sr.style.position = 'absolute';
                    sr.style.width = '1px';
                    sr.style.height = '1px';
                    sr.style.margin = '-1px';
                    sr.style.border = '0';
                    sr.style.padding = '0';
                    sr.style.clip = 'rect(0 0 0 0)';
                    sr.style.overflow = 'hidden';
                    sr.style.whiteSpace = 'nowrap';
                    document.body.appendChild(sr);
                }
                // Update message for assistive tech without creating a visible toast
                sr.textContent = message || '';
            } catch (e) {
                // Fallback: do nothing; avoid showing duplicate visible toasts
                if (odcmIsDebug()) { console.warn('ODCM SR announce failed:', e); }
            }
        },
        
        // Component Selection (Inline Expander Pattern)
        selectComponent(type, id) {
            // Get component definition
            const component = this.getComponentDefinition(type, id);
            if (!component) {
                this.showToast('Component not found. Please refresh the page and try again.', 'error', 5000);
                return;
            }
            
            // Guard: Block selection of inaccessible components.
            if (!component.accessible) {
                const msg = (window.odcmRuleBuilderConfig && window.odcmRuleBuilderConfig.upgrade && window.odcmRuleBuilderConfig.upgrade.message) ? window.odcmRuleBuilderConfig.upgrade.message : 'This feature is available in the pro version. Learn more in the documentation.';
                this.showToast(msg, 'info');
                return;
            }

            if (type === 'trigger') {
                this.rule.trigger = { id: id, settings: {} };
                this.isAddingTrigger = false;
                this.triggerSearchTerm = '';
                // Only auto-expand if component has settings
                if (component.schema && component.schema.properties && Object.keys(component.schema.properties).length > 0) {
                    // Use $nextTick to ensure rule state is updated before settings panel initializes
                    this.$nextTick(() => {
                        this.editingTriggerIndex = 0;
                    });
                }
            } else if (type === 'condition') {
                this.rule.conditions.push({ id: id, settings: {} });
                this.isAddingCondition = false;
                this.conditionSearchTerm = '';
                // Only auto-expand if component has settings
                if (component.schema && component.schema.properties && Object.keys(component.schema.properties).length > 0) {
                    this.editingConditionIndex = this.rule.conditions.length - 1;
                }
            } else if (type === 'primaryAction') {
                this.rule.primaryAction = { id: id, settings: {} };
                this.isAddingPrimaryAction = false;
                this.primaryActionSearchTerm = '';
                // Only auto-expand if component has settings
                if (component.schema && component.schema.properties && Object.keys(component.schema.properties).length > 0) {
                    this.editingPrimaryAction = true;
                }
                // No need to update summaries - they're generated on-demand client-side
            } else if (type === 'action') {
                this.rule.secondaryActions.push({ id: id, settings: {} });
                this.isAddingAction = false;
                this.actionSearchTerm = '';
                // Only auto-expand if component has settings
                if (component.schema && component.schema.properties && Object.keys(component.schema.properties).length > 0) {
                    this.editingActionIndex = this.rule.secondaryActions.length - 1;
                }
                // No need to update summaries - they're generated on-demand client-side
            }
            
            this.autoSave();
        },

        // Validation Methods
        validateTrigger() {
            if (!this.rule.trigger || !this.rule.trigger.id) {
                return [];
            }

            const component = this.getComponentDefinition('trigger', this.rule.trigger.id);
            if (!component) {
                return [];
            }

            // Special validation for AnyStatusChangeTrigger
            if (this.rule.trigger.id === 'order_status_any_change') {
                const settings = this.rule.trigger.settings || {};
                const fromStatuses = settings.from_statuses || [];
                const toStatuses = settings.to_statuses || [];
                
                if (fromStatuses.length === 0 && toStatuses.length === 0) {
                    return ['Please select at least one status transition. Choose from/to statuses to define when this trigger should activate.'];
                }
            }

            return [];
        },

        validateRule() {
            const errors = [];
            
            // Validate trigger
            const triggerErrors = this.validateTrigger();
            errors.push(...triggerErrors);
            
            // Add other validation as needed
            
            return errors;
        },

        get hasValidationErrors() {
            return this.validateRule().length > 0;
        },

        get triggerValidationErrors() {
            return this.validateTrigger();
        },

        // Component sorting with proper priority handling
        get sortedTriggers() {
            return this.sortComponents(this.components.triggers || []);
        },

        get sortedConditions() {
            return this.sortComponents(this.components.conditions || []);
        },

        get sortedPrimaryActions() {
            return this.sortComponents(this.components.primaryActions || []);
        },

        get sortedSecondaryActions() {
            return this.sortComponents(this.components.secondaryActions || []);
        },

        // Sort components by priority and accessibility
        sortComponents(components) {
            return [...components].sort((a, b) => {
                // First sort by accessibility (available first)
                if (a.accessible !== b.accessible) {
                    return b.accessible - a.accessible;
                }
                
                // Then by priority (lower number = higher priority)
                const aPriority = a.priority || 999;
                const bPriority = b.priority || 999;
                if (aPriority !== bPriority) {
                    return aPriority - bPriority;
                }
                
                // Finally by label alphabetically
                return a.label.localeCompare(b.label);
            });
        },

        // Hide current selector
        hideSelector() {
            this.isAddingTrigger = false;
            this.isAddingCondition = false;
            this.isAddingPrimaryAction = false;
            this.isAddingAction = false;
            
            // Clear search terms
            this.triggerSearchTerm = '';
            this.conditionSearchTerm = '';
            this.primaryActionSearchTerm = '';
            this.actionSearchTerm = '';
        },

        // Row Click Handlers (Make entire row clickable)
        handleRowClick(type, index, event) {
            // Don't trigger if clicking on remove button
            if (event.target.closest('.odcm-remove-button')) {
                return;
            }
            
            // Don't expand if component has no settings
            if (!this.componentHasSettings(type, index)) {
                return;
            }
            
            this.toggleEdit(type, index);
        },

        // Check if a component has configurable settings
        componentHasSettings(type, index) {
            let component;
            
            if (type === 'trigger') {
                component = this.rule.trigger;
            } else if (type === 'condition') {
                component = this.rule.conditions[index];
            } else if (type === 'primaryAction') {
                component = this.rule.primaryAction;
            } else if (type === 'action') {
                component = this.rule.secondaryActions[index];
            }
            
            if (!component || !component.id) {
                return false;
            }
            
            const componentDef = this.getComponentDefinition(type, component.id);
            return componentDef && componentDef.schema && componentDef.schema.properties && 
                   Object.keys(componentDef.schema.properties).length > 0;
        },

        // Component Removal
        removeTrigger() {
            this.rule.trigger = null;
            this.editingTriggerIndex = null;
            // Clear trigger summary
            if (this.summaries) delete this.summaries['trigger'];
            this.autoSave();
        },

        removeCondition(index) {
            this.rule.conditions.splice(index, 1);
            // Adjust editing index if necessary
            if (this.editingConditionIndex === index) {
                this.editingConditionIndex = null;
            } else if (this.editingConditionIndex > index) {
                this.editingConditionIndex--;
            }
            // Rebuild summaries to reflect new indices
            this.rebuildAllSummaries();
            this.autoSave();
        },

        removePrimaryAction() {
            this.rule.primaryAction = null;
            this.editingPrimaryAction = false;
            if (this.summaries) delete this.summaries['primaryAction'];
            this.autoSave();
        },

        removeAction(index) {
            this.rule.secondaryActions.splice(index, 1);
            // Adjust editing index if necessary
            if (this.editingActionIndex === index) {
                this.editingActionIndex = null;
            } else if (this.editingActionIndex > index) {
                this.editingActionIndex--;
            }
            // Rebuild summaries to reflect new indices
            this.rebuildAllSummaries();
            this.autoSave();
        },

        // Component Getters with enhanced debugging
        getTriggerComponent(triggerId) {
            if (!triggerId) {
                return null;
            }
            
            const component = this.components.triggers?.find(t => t.id === triggerId);
            
            
            return component;
        },

        getConditionComponent(conditionId) {
            if (!conditionId) return null;
            return this.components.conditions?.find(c => c.id === conditionId);
        },

        getPrimaryActionComponent(actionId) {
            if (!actionId) return null;
            return this.components.primaryActions?.find(a => a.id === actionId);
        },

        getActionComponent(actionId) {
            if (!actionId) return null;
            // For backward compatibility, check both secondary actions and legacy actions
            return this.components.secondaryActions?.find(a => a.id === actionId) || 
                   this.components.actions?.find(a => a.id === actionId);
        },


        getComponentDefinition(type, id) {
            if (!id) return null;
            const componentList = this.components[type + 's'] || [];
            const component = componentList.find(c => c.id === id);
            return component;
        },

        // Centralized helper method to determine if a component should show a premium badge
        // This ensures consistent premium badge display logic across the entire rule builder
        shouldShowPremiumBadge(componentDef) {
            return componentDef && 
                   !componentDef.accessible && 
                   componentDef.capability && 
                   componentDef.capability !== 'free';
        },



        // Drag & Drop for Conditions
        startDragCondition(index, event) {
            this.draggedCondition = index;
            event.dataTransfer.effectAllowed = 'move';
            event.target.classList.add('odcm-dragging');
        },

        dragOverCondition(index, event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this.dragOverIndex = index;
        },

        dropCondition(index, event) {
            event.preventDefault();
            
            if (this.draggedCondition !== null && this.draggedCondition !== index) {
                // Reorder conditions
                const draggedItem = this.rule.conditions[this.draggedCondition];
                this.rule.conditions.splice(this.draggedCondition, 1);
                
                // Adjust index if dragging from before the drop position
                const newIndex = this.draggedCondition < index ? index - 1 : index;
                this.rule.conditions.splice(newIndex, 0, draggedItem);
                
                // Rebuild summaries after reordering to realign keys
                this.rebuildAllSummaries();
                this.autoSave();
            }
            
            this.endDrag();
        },

        endDrag() {
            this.draggedCondition = null;
            this.dragOverIndex = null;
            document.querySelectorAll('.odcm-dragging').forEach(el => {
                el.classList.remove('odcm-dragging');
            });
        },

        // Back-compat: No-op method retained to avoid runtime errors from legacy call sites.
        // Summaries are now generated on-demand (generateClientSideSummary), so rebuilding is unnecessary.
        rebuildAllSummaries() {
            try {
                // Intentionally empty. Previously rebuilt cached summaries after reorder/remove.
                // If specific future cleanup is needed (e.g., clearing any cached map), it can be added here safely.
                return;
            } catch (e) {
                // Swallow any unexpected error to protect UX.
            }
        },

        // Settings Form Rendering
        renderSettingsForm(schema, currentSettings, componentType, index = null) {
            if (!schema || !schema.properties) {
                return `<p class="odcm-no-settings">${odcmRuleBuilderConfig.i18n.noSettings}</p>`;
            }

            let html = '<div class="odcm-settings-form">';
            for (const [key, property] of Object.entries(schema.properties)) {
                html += this.renderFormField(key, property, currentSettings[key], componentType, index);
            }
            html += '</div>';
            return html;
        },

        renderFormField(key, property, value, componentType, index) {
            const fieldId = `${componentType}_${index !== null ? index + '_' : ''}${key}`;
            
            let html = '<div class="odcm-form-group">';
            
            if (property.title) {
                html += `<label for="${fieldId}" class="odcm-form-label">${property.title}</label>`;
            }
            
            if (property.description) {
                html += `<div class="odcm-form-description">${property.description}</div>`;
            }

            if (property.type === 'boolean') {
                html += this.renderCheckbox(key, property, value, fieldId, componentType, index);
            } else if (property['ui:widget'] === 'tiered_checkboxes') {
                html += this.renderTieredCheckboxes(key, property, value, fieldId, componentType, index);
            } else if (property['ui:widget'] === 'searchable_checkboxes') {
                html += this.renderSearchableCheckboxes(key, property, value, fieldId, componentType, index);
            } else if (property['ui:widget'] === 'checkboxes') {
                html += this.renderCheckboxGroup(key, property, value, fieldId, componentType, index);
            } else if (property.type === 'string' && property.enum) {
                html += this.renderRadioGroup(key, property, value, fieldId, componentType, index);
            } else if (property['ui:widget'] === 'textarea') {
                // DEBUG: Simple console log to see if we get here
                console.log('🔍 TEXTAREA DEBUG: About to render textarea for key:', key);
                html += this.renderTextarea(key, property, value, fieldId, componentType, index);
            } else {
                html += this.renderTextInput(key, property, value, fieldId, componentType, index);
            }

            html += '</div>';
            return html;
        },

        renderCheckbox(key, property, value, fieldId, componentType, index) {
            const checked = value ? 'checked' : '';
            return `
                <label class="odcm-checkbox-label">
                    <input type="checkbox" 
                           id="${fieldId}" 
                           ${checked}
                           @change="updateSetting('${key}', $event.target.checked, '${componentType}', ${index})">
                    <span class="odcm-checkbox-text">${property.title || key}</span>
                </label>
            `;
        },

        renderRadioGroup(key, property, value, fieldId, componentType, index) {
            const radioInputs = property['ui:radio_inputs'] || {};
            let html = `<div class="odcm-radio-group" role="radiogroup" aria-labelledby="${fieldId}_label">`;

            Object.entries(property.enum).forEach(([optionValue, optionLabel]) => {
                const isChecked = value === optionValue;
                const radioId = `${fieldId}_${optionValue}`;
                const siblingKey = radioInputs[optionValue];

                html += `
                    <label class="odcm-radio-label" for="${radioId}" role="radio" aria-checked="${String(isChecked)}" tabindex="0"
                           @keydown.enter.prevent="$event.currentTarget.querySelector('input')?.click()"
                           @keydown.space.prevent="$event.currentTarget.querySelector('input')?.click()">
                        <input type="radio" 
                               id="${radioId}"
                               name="${fieldId}" 
                               value="${optionValue}" 
                               ${isChecked ? 'checked' : ''}
                               @change="updateRadioSetting('${key}', $event.target.value, '${componentType}', ${index})">
                        <span class="odcm-radio-text">${optionLabel}</span>`;

                if (siblingKey) {
                    const minAttr = property.minimum !== undefined ? ` :min=\"${property.minimum}\"` : '';
                    const maxAttr = property.maximum !== undefined ? ` :max=\"${property.maximum}\"` : '';
                    const stepAttr = property.step !== undefined ? ` :step=\"${property.step}\"` : (property.type === 'integer' ? ' :step="1"' : '');
                    html += `
                        <input type="number"
                               class="odcm-inline-number-input"
                               ${minAttr}${maxAttr}${stepAttr}
                               :value="(${componentType === 'trigger' ? 'rule.trigger?.settings' : (componentType === 'condition' ? `rule.conditions[${index}]?.settings` : `rule.secondaryActions[${index}]?.settings`)})['${siblingKey}'] ?? ''"
                               :disabled="${isChecked ? 'false' : 'true'}"
                               @input="updateSiblingField('${key}', '${siblingKey}', $event.target.value, '${componentType}', ${index})">
                    `;
                }

                html += `</label>`;
            });

            html += '</div>';
            return html;
        },

        renderTextarea(key, property, value, fieldId, componentType, index) {
            const textValue = value || property.default || '';
            return `<textarea id="${fieldId}" 
                              class="odcm-form-textarea"
                              rows="4"
                              placeholder="${property.description || ''}"
                              @input="updateSetting('${key}', $event.target.value, '${componentType}', ${index})">${textValue}</textarea>`;
        },

        renderTextInput(key, property, value, fieldId, componentType, index) {
            const inputValue = value || property.default || '';
            const inputType = property.type === 'number' || property.type === 'integer' ? 'number' :
                property.format === 'email' ? 'email' : 'text';

            let attributes = '';
            if (property.type === 'number' || property.type === 'integer') {
                if (property.minimum !== undefined) attributes += ` min="${property.minimum}"`;
                if (property.maximum !== undefined) attributes += ` max="${property.maximum}"`;
                if (property.step !== undefined) attributes += ` step="${property.step}"`;
                else if (property.type === 'integer') attributes += ' step="1"';
            }

            return `<input type="${inputType}" 
                           id="${fieldId}" 
                           class="odcm-form-input"
                           value="${this.escapeHtml(String(inputValue))}"
                           placeholder="${property.description || ''}"
                           ${attributes}
                           @input="updateSetting('${key}', $event.target.value, '${componentType}', ${index})">`;
        },

        renderCheckboxGroup(key, property, value, fieldId, componentType, index) {
            const selectedValues = Array.isArray(value) ? value : [];
            let html = '<div class="odcm-checkbox-group">';

            Object.entries(property.items?.enum || {}).forEach(([optionValue, optionLabel]) => {
                const isChecked = selectedValues.includes(optionValue);
                const checkboxId = `${fieldId}_${optionValue}`;

                html += `
                    <label class="odcm-checkbox-label" for="${checkboxId}">
                        <input type="checkbox" 
                               id="${checkboxId}"
                               value="${optionValue}" 
                               ${isChecked ? 'checked' : ''}
                               @change="updateArraySetting('${key}', '${optionValue}', $event.target.checked, '${componentType}', ${index})">
                        <span class="odcm-checkbox-text">${optionLabel}</span>
                    </label>
                `;
            });

            html += '</div>';
            return html;
        },

        renderSearchableCheckboxes(key, property, value, fieldId, componentType, index) {
            const selectedValues = Array.isArray(value) ? value : [];
            const searchId = `${fieldId}_search`;

            let html = `
                <div x-data="{ searchTerm: '', showClearAll: ${selectedValues.length > 0} }" class="odcm-searchable-checkboxes">
                    <div class="odcm-search-header">
                        <input type="text"
                               id="${searchId}"
                               placeholder="Search options..."
                               x-model="searchTerm"
                               class="odcm-search-input">
                        <button type="button"
                                class="odcm-clear-all-button"
                                x-show="showClearAll"
                                @click="$root.updateSetting('${key}', [], '${componentType}', ${index}); showClearAll = false">
                            Clear All
                        </button>
                    </div>
                    <div class="odcm-checkbox-list">
            `;

            Object.entries(property.items?.enum || {}).forEach(([optionValue, optionLabel]) => {
                const isChecked = selectedValues.includes(optionValue);
                const checkboxId = `${fieldId}_${optionValue}`;

                html += `
                    <label class="odcm-checkbox-label" 
                           for="${checkboxId}"
                           x-show="searchTerm === '' || '${this.escapeHtml(optionLabel).toLowerCase()}'.includes(searchTerm.toLowerCase())">
                        <input type="checkbox" 
                               id="${checkboxId}"
                               value="${optionValue}" 
                               ${isChecked ? 'checked' : ''}
                               @change="updateArraySetting('${key}', '${optionValue}', $event.target.checked, '${componentType}', ${index}); showClearAll = ${selectedValues.length > 0} || $event.target.checked">
                        <span class="odcm-checkbox-text">${optionLabel}</span>
                    </label>
                `;
            });

            html += '</div></div>';
            return html;
        },

        renderTieredCheckboxes(key, property, value, fieldId, componentType, index) {
            const selectedValues = Array.isArray(value) ? value : [];
            let html = '<div class="odcm-tiered-checkboxes">';

            if (property.groups) {
                Object.entries(property.groups).forEach(([groupName, groupOptions]) => {
                    html += `<div class="odcm-checkbox-group"><h4 class="odcm-group-title">${groupName}</h4>`;

                    Object.entries(groupOptions).forEach(([optionValue, optionLabel]) => {
                        const isChecked = selectedValues.includes(optionValue);
                        const checkboxId = `${fieldId}_${optionValue}`;

                        html += `
                            <label class="odcm-checkbox-label" for="${checkboxId}">
                                <input type="checkbox" 
                                       id="${checkboxId}"
                                       value="${optionValue}" 
                                       ${isChecked ? 'checked' : ''}
                                       @change="updateArraySetting('${key}', '${optionValue}', $event.target.checked, '${componentType}', ${index})">
                                <span class="odcm-checkbox-text">${optionLabel}</span>
                            </label>
                        `;
                    });

                    html += '</div>';
                });
            }

            html += '</div>';
            return html;
        },





        // Enhanced settings update methods
        updateSetting(key, value, componentType, index) {
            console.log(`🔧 UPDATE: Setting ${key} = ${JSON.stringify(value)} for ${componentType}[${index}]`);
            
            if (componentType === 'trigger') {
                if (!this.rule.trigger) this.rule.trigger = { id: '', settings: {} };
                this.rule.trigger.settings[key] = value;
            } else if (componentType === 'condition') {
                if (this.rule.conditions[index]) {
                    this.rule.conditions[index].settings[key] = value;
                }
            } else if (componentType === 'primaryAction') {
                if (!this.rule.primaryAction) this.rule.primaryAction = { id: '', settings: {} };
                this.rule.primaryAction.settings[key] = value;
            } else if (componentType === 'action') {
                if (this.rule.secondaryActions[index]) {
                    this.rule.secondaryActions[index].settings[key] = value;
                }
            }
            
            this.autoSave();
        },

        updateArraySetting(key, value, checked, componentType, index) {
            console.log(`🔧 UPDATE ARRAY: ${key}[${value}] = ${checked} for ${componentType}[${index}]`);

            let currentArray;

            if (componentType === 'trigger') {
                if (!this.rule.trigger) this.rule.trigger = { id: '', settings: {} };
                currentArray = this.rule.trigger.settings[key] || [];
            } else if (componentType === 'condition') {
                if (this.rule.conditions[index]) {
                    currentArray = this.rule.conditions[index].settings[key] || [];
                }
            } else if (componentType === 'primaryAction') {
                if (!this.rule.primaryAction) this.rule.primaryAction = { id: '', settings: {} };
                currentArray = this.rule.primaryAction.settings[key] || [];
            } else if (componentType === 'action') {
                if (this.rule.secondaryActions[index]) {
                    currentArray = this.rule.secondaryActions[index].settings[key] || [];
                }
            }

            if (!Array.isArray(currentArray)) {
                currentArray = [];
            }

            if (checked && !currentArray.includes(value)) {
                currentArray.push(value);
            } else if (!checked && currentArray.includes(value)) {
                const valueIndex = currentArray.indexOf(value);
                currentArray.splice(valueIndex, 1);
            }

            this.updateSetting(key, currentArray, componentType, index);
        },

        // Select All functionality for regular checkbox groups
        selectAllCheckboxes(key, enumOptions, componentType, index) {
            console.log(`🔧 SELECT ALL: ${key} for ${componentType}[${index}]`);
            
            // Get all available option values
            const allValues = Object.keys(enumOptions || {});
            this.updateSetting(key, allValues, componentType, index);
        },

        // Clear All functionality for regular checkbox groups
        clearAllCheckboxes(key, componentType, index) {
            console.log(`🔧 CLEAR ALL: ${key} for ${componentType}[${index}]`);
            this.updateSetting(key, [], componentType, index);
        },

        // Check if all options are selected for regular checkbox groups
        areAllCheckboxesSelected(key, enumOptions, componentType, index) {
            let currentArray;

            if (componentType === 'trigger') {
                currentArray = this.rule.trigger?.settings[key] || [];
            } else if (componentType === 'condition') {
                currentArray = this.rule.conditions[index]?.settings[key] || [];
            } else if (componentType === 'primaryAction') {
                currentArray = this.rule.primaryAction?.settings[key] || [];
            } else if (componentType === 'action') {
                currentArray = this.rule.secondaryActions[index]?.settings[key] || [];
            }

            if (!Array.isArray(currentArray)) {
                return false;
            }

            const allValues = Object.keys(enumOptions || {});
            return allValues.length > 0 && allValues.every(value => currentArray.includes(value));
        },

        // Check if any options are selected for regular checkbox groups
        areAnyCheckboxesSelected(key, componentType, index) {
            let currentArray;

            if (componentType === 'trigger') {
                currentArray = this.rule.trigger?.settings[key] || [];
            } else if (componentType === 'condition') {
                currentArray = this.rule.conditions[index]?.settings[key] || [];
            } else if (componentType === 'primaryAction') {
                currentArray = this.rule.primaryAction?.settings[key] || [];
            } else if (componentType === 'action') {
                currentArray = this.rule.secondaryActions[index]?.settings[key] || [];
            }

            return Array.isArray(currentArray) && currentArray.length > 0;
        },

        updateRadioSetting(key, value, componentType, index) {
            console.log(`🔧 UPDATE RADIO: ${key} = ${value} for ${componentType}[${index}]`);
            this.updateSetting(key, value, componentType, index);
        },

        // Handle radio-with-inline-number sibling field updates
        updateSiblingField(radioKey, siblingKey, value, componentType, index) {
            console.log(`🔧 UPDATE SIBLING: ${siblingKey} = ${value} (radio: ${radioKey}) for ${componentType}[${index}]`);

            // Convert to appropriate type
            const numericValue = value === '' ? null : (isNaN(Number(value)) ? value : Number(value));

            this.updateSetting(siblingKey, numericValue, componentType, index);
        },


        // Utilities mirroring server truncation
        truncateList(items) {
            if (!Array.isArray(items)) return '';
            if (items.length <= 3) return items.join(', ');
            const rest = items.length - 3;
            return items.slice(0, 3).join(', ') + ` ... and ${rest} more`;
        },
        truncateString(text, max) {
            if (!text || text.length <= max) return text;
            const slice = text.slice(0, Math.max(0, max - 3));
            const i = slice.lastIndexOf(' ');
            const cut = (i > Math.floor(max * 0.6)) ? slice.slice(0, i) : slice;
            return cut.trim() + '...';
        },



        // Auto-save functionality
        async autoSave() {
            // Only auto-save if rule data has actually changed
            const currentData = JSON.stringify(this.rule);
            if (currentData === this.lastSaveData) {
                return;
            }

            // Update hidden form field for WordPress standard save
            const hiddenField = document.getElementById('odcm_rule_data_field');
            if (hiddenField) {
                hiddenField.value = currentData;
            }

            // Set up next auto-save cycle
            setTimeout(() => this.autoSave(), 30000); // 30 seconds
        },
    };
}

// Settings panel Alpine component factory
function settingsPanel(componentType, index) {
    return {
        // Expose parent rule state so template bindings like rule.trigger work in this nested scope
        get rule() { return window.ruleBuilderInstance?.rule || {}; },
        // Expose validation errors from parent component
        get triggerValidationErrors() { return window.ruleBuilderInstance?.triggerValidationErrors || []; },
        fields: {},
        initSettings(schema, currentSettings) {
            try {
                
                // For triggers, we need to look up the component ID to get the right prepared fields
                let key = null;
                if (componentType === 'trigger') {
                    const triggerId = window.ruleBuilderInstance?.rule?.trigger?.id;
                    if (triggerId) {
                        key = `trigger_0_${triggerId}`;
                    }
                } else if (componentType === 'primaryAction') {
                    const actionId = window.ruleBuilderInstance?.rule?.primaryAction?.id;
                    if (actionId) {
                        key = `primaryAction_${actionId}`;
                    }
                } else if (componentType === 'condition') {
                    key = `condition_${index}`;
                } else if (componentType === 'action') {
                    key = `action_${index}`;
                }
                
                const prepared = (window.ruleBuilderInstance && window.ruleBuilderInstance.preparedFields)
                    ? window.ruleBuilderInstance.preparedFields
                    : ((window.odcmRuleBuilderConfig && window.odcmRuleBuilderConfig.preparedFields)
                        ? window.odcmRuleBuilderConfig.preparedFields
                        : null);
                
                
                if (key && prepared && prepared[key] && Object.keys(prepared[key]).length > 0) {
                    this.fields = prepared[key];
                } else {
                    // Fallback: derive fields from schema using registry as single source of truth
                    this.fields = {};
                    
                    // Get schema from registry directly if not provided
                    let actualSchema = schema;
                    if (!actualSchema && componentType === 'trigger') {
                        const triggerId = window.ruleBuilderInstance?.rule?.trigger?.id;
                        if (triggerId) {
                            const triggerComponent = window.ruleBuilderInstance?.getTriggerComponent(triggerId);
                            actualSchema = triggerComponent?.schema;
                        }
                    }
                    
                    if (actualSchema && actualSchema.properties) {
                        
                        Object.entries(actualSchema.properties).forEach(([propKey, prop]) => {
                            const enumOptions = prop.items?.enum || prop.enum || {};
                            
                            this.fields[propKey] = {
                                id: `${componentType}_${index !== null ? index + '_' : ''}${propKey}`,
                                key: propKey,
                                title: prop.title || '',
                                description: prop.description || '',
                                widget: (function(p){
                                    if (typeof p['ui:widget'] === 'string') {
                                        return p['ui:widget'] === 'select' ? 'radio_group' : p['ui:widget'];
                                    }
                                    if (p.type === 'boolean') return 'checkbox';
                                    if (p.type === 'array' && p.items && p.items.enum) return p['ui:searchable'] ? 'searchable_checkboxes' : 'checkboxes';
                                    if (p.type === 'string' && p.enum) return 'radio_group';
                                    if (p.type === 'number' || p.type === 'integer') return 'number';
                                    return 'text';
                                })(prop),
                                value: (currentSettings && currentSettings[propKey] !== undefined) ? currentSettings[propKey] : (prop.default ?? ''),
                                enumOptions: enumOptions,
                                selectedValues: Array.isArray(currentSettings?.[propKey]) ? currentSettings[propKey] : Array.isArray(prop.default) ? prop.default : [],
                                premiumOptions: prop['ui:premium_options'] || [],
                                placeholder: prop['ui:placeholder'] || 'Search options...',
                                minimum: prop.minimum ?? null,
                                maximum: prop.maximum ?? null,
                                step: prop.step ?? (prop.type === 'integer' ? 1 : null),
                                default: prop.default ?? (prop.type === 'integer' || prop.type === 'number' ? 0 : (prop.type === 'array' ? [] : '')),
                                radioInputs: prop['ui:radio_inputs'] || {}
                            };
                            
                        });
                    } else {
                    }
                }
                
            } catch (e) {
                console.error('ODCM settingsPanel.initSettings error:', e);
                this.fields = {};
            }
        },
        // Bridge update helpers into main component instance
        updateSetting(key, value, type, idx) { window.ruleBuilderInstance?.updateSetting(key, value, type, idx); },
        updateArraySetting(key, value, checked, type, idx) { window.ruleBuilderInstance?.updateArraySetting(key, value, checked, type, idx); },
        updateRadioSetting(key, value, type, idx) { window.ruleBuilderInstance?.updateRadioSetting(key, value, type, idx); },
        updateSiblingField(parentKey, siblingKey, value, type, idx) { window.ruleBuilderInstance?.updateSiblingField(parentKey, siblingKey, value, type, idx); }
    };
}

// Searchable checkbox widget helper
// Global proxies to access component definitions from templates (Alpine evaluates globals)
window.getTriggerComponent = (id) => window.ruleBuilderInstance?.getTriggerComponent(id);
window.getConditionComponent = (id) => window.ruleBuilderInstance?.getConditionComponent(id);
window.getPrimaryActionComponent = (id) => window.ruleBuilderInstance?.getPrimaryActionComponent(id);
window.getActionComponent = (id) => window.ruleBuilderInstance?.getActionComponent(id);

function searchableWidget(fieldId) {
    return {
        options: [],
        filteredOptions: [],
        selectedValues: [],
        premiumOptions: [],
        key: '',
        searchTerm: '',
        showAll: false,
        init(enumOptions, selectedValues, premiumOptions, key) {
            this.options = Object.entries(enumOptions || {}).map(([value, label]) => ({ value, label }));
            this.filteredOptions = this.options;
            this.selectedValues = Array.isArray(selectedValues) ? selectedValues : [];
            this.premiumOptions = Array.isArray(premiumOptions) ? premiumOptions : [];
            this.key = key || '';
        },
        filterOptions() {
            const term = (this.searchTerm || '').toLowerCase();
            this.filteredOptions = this.options.filter(opt => this.showAll || opt.label.toLowerCase().includes(term));
        },
        getOptionClasses(value) {
            const isSelected = this.selectedValues.includes(value);
            const isPremium = this.premiumOptions.includes(value);
            return `odcm-checkbox-label${isSelected ? ' is-selected' : ''}${isPremium ? ' is-premium' : ''}`;
        },
        shouldShowOption(value, label) {
            if (this.showAll) return true;
            const term = (this.searchTerm || '').toLowerCase();
            return label.toLowerCase().includes(term);
        },
        shouldDisableOption(value) {
            // Do not allow selecting premium options when not available; UI-level hint only
            return this.premiumOptions.includes(value) && !(window.odcmRuleBuilderConfig?.uiCapabilities?.canAccessPremiumComponents);
        },
        handleCheckboxChange(key, value, checked, componentType, index) {
            if (checked && !this.selectedValues.includes(value)) this.selectedValues.push(value);
            if (!checked && this.selectedValues.includes(value)) this.selectedValues = this.selectedValues.filter(v => v !== value);
            window.ruleBuilderInstance?.updateArraySetting(key, value, checked, componentType, index);
        },
        clearAll(key, componentType, index) {
            this.selectedValues = [];
            window.ruleBuilderInstance?.updateSetting(key, [], componentType, index);
        },
        selectAll(key, componentType, index) {
            // Get all available (non-premium or accessible premium) options
            const availableOptions = this.options.filter(option => 
                !this.shouldDisableOption(option.value)
            ).map(option => option.value);
            
            this.selectedValues = [...availableOptions];
            window.ruleBuilderInstance?.updateSetting(key, availableOptions, componentType, index);
        },
        get canSelectAll() {
            // Check if there are any unselected available options
            const availableOptions = this.options.filter(option => 
                !this.shouldDisableOption(option.value)
            ).map(option => option.value);
            
            return availableOptions.some(value => !this.selectedValues.includes(value));
        },
        get hasSelectableOptions() {
            // Check if there are any options that can be selected
            return this.options.some(option => !this.shouldDisableOption(option.value));
        }
    };
}
