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

            // ── Form-submit safety net ─────────────────────────────────────────────
            // WordPress's Publish/Update button submits the classic-editor form via a
            // standard HTML POST.  Intercept the submit event to forcefully write the
            // current rule into the hidden field as the very last step, BEFORE the
            // browser POSTs the data.  This guarantees the latest in-memory state is
            // always sent even if Alpine's $watch or autoSave skipped an update.
            const self = this;
            document.addEventListener('submit', function onFormSubmit() {
                self._syncHiddenField();
            }, true /* capture phase - fires before the form is actually submitted */);

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
         * Generate client-side summary for a component.
         * This builds a human-readable summary from component settings.
         *
         * @param {object} component - Component object with id and settings
         * @param {string} type - Component type (trigger, condition, action, primaryAction)
         * @param {number|null} index - Index for array components
         * @return {string} HTML summary string
         */
        generateClientSideSummary(component, type, index = null) {
            if (!component || !component.id) {
                return this.getFallbackSummary(type);
            }

            // Get component definition
            const componentDef = this.getComponentDefinition(type, component.id);
            if (!componentDef) {
                return this.getComponentFallbackSummary(component, type);
            }

            const settings = component.settings || {};

            // Try component-specific summary first
            const specificSummary = this.getComponentSpecificSummary(component.id, settings, componentDef);
            if (specificSummary) {
                return specificSummary;
            }

            // Fall back to generic summary builder
            const parts = {
                title: componentDef.label || component.id,
                values: this.extractValues(settings, componentDef),
                operator: this.extractOperator(settings),
                matchMode: this.extractMatchMode(settings)
            };

            // Build summary HTML
            let summary = `<span class="odcm-summary-title">${this.escapeHtml(parts.title)}</span>`;
            
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
            // Allow external plugins (e.g. pro) to register component-specific builders.
            // Called with this = ruleBuilderInstance so builders can use formatValue, escapeHtml, etc.
            if (window.odcmSummaryBuilders?.[componentId]) {
                return window.odcmSummaryBuilders[componentId].call(this, settings, componentDef);
            }

            switch (componentId) {
                case 'product_category':
                    return this.buildProductCategorySummary(settings, componentDef);
                
                case 'product_type':
                    return this.buildProductTypeSummary(settings, componentDef);
                
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
            const selected = settings.categories || [];
            if (selected.length === 0) {
                return `<span class="odcm-summary-title">Product Category</span>: <span class="odcm-summary-values">Any category</span>`;
            }

            const categories = this.formatValue(selected, componentDef?.schema?.properties?.categories);
            const operatorEnum = componentDef?.schema?.properties?.operator?.enum || {};
            const operatorLabel = operatorEnum[settings.operator || 'in'] || settings.operator || 'In';

            return `<span class="odcm-summary-title">Product Category</span>: <span class="odcm-summary-operator">${this.escapeHtml(operatorLabel)}</span> <span class="odcm-summary-values">${this.escapeHtml(categories)}</span>`;
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
         * Build Product Type condition summary.
         */
        buildProductTypeSummary(settings, componentDef) {
            const types = this.formatValue(settings.types || [], componentDef?.schema?.properties?.types);
            const matchMode = settings.match_mode === 'all' ? 'All match' : 
                              settings.match_mode === 'any' ? 'Any match' : 
                              settings.match_mode === 'none' ? 'None match' : 'All match';
            
            return `<span class="odcm-summary-title">Product Type</span>: <span class="odcm-summary-values">${this.escapeHtml(types)}</span> <span class="odcm-summary-match">(${matchMode})</span>`;
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
            // Check various operator keys (match_mode is intentionally excluded —
            // it is already handled by extractMatchMode and is not an operator symbol)
            const operatorKeys = ['operator', 'comparison', 'condition'];
            
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
                'amount_ne': '≠',
                'count_gt': '>',
                'count_lt': '<',
                'count_gte': '≥',
                'count_lte': '≤',
                'count_eq': '=',
                'count_ne': '≠',
                
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

        // Get default primary action
        getDefaultPrimaryAction() {
            // Default to "Complete Order" action
            const defaultAction = this.components.primaryActions?.find(action => 
                action.id === 'change_status_to_completed'
            );

            if (defaultAction) {
                return {
                    id: 'change_status_to_completed',
                    settings: {}
                };
            }

            return null;
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
        
        // Build a settings object pre-populated with schema defaults.
        // Called when a new component is added so the summary immediately
        // reflects the same values that the settings panel UI shows.
        buildDefaultSettings(schema) {
            const settings = {};
            if (schema?.properties) {
                Object.entries(schema.properties).forEach(([key, prop]) => {
                    if (prop.default !== undefined) {
                        settings[key] = Array.isArray(prop.default) ? [...prop.default] : prop.default;
                    }
                });
            }
            return settings;
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
                this.showToast('This component is not available.', 'info');
                return;
            }

            if (type === 'trigger') {
                this.rule.trigger = { id: id, settings: this.buildDefaultSettings(component.schema) };
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
                this.rule.conditions.push({ id: id, settings: this.buildDefaultSettings(component.schema) });
                this.isAddingCondition = false;
                this.conditionSearchTerm = '';
                // Only auto-expand if component has settings
                if (component.schema && component.schema.properties && Object.keys(component.schema.properties).length > 0) {
                    this.editingConditionIndex = this.rule.conditions.length - 1;
                }
            } else if (type === 'primaryAction') {
                this.rule.primaryAction = { id: id, settings: this.buildDefaultSettings(component.schema) };
                this.isAddingPrimaryAction = false;
                this.primaryActionSearchTerm = '';
                // Only auto-expand if component has settings
                if (component.schema && component.schema.properties && Object.keys(component.schema.properties).length > 0) {
                    this.editingPrimaryAction = true;
                }
                // No need to update summaries - they're generated on-demand client-side
            } else if (type === 'action') {
                this.rule.secondaryActions.push({ id: id, settings: this.buildDefaultSettings(component.schema) });
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

            // Check if this component uses conditional groups
            const hasConditionalGroups = schema.properties.comparison_type?.['ui:conditional_groups'];

            // Debug logging to trace execution path
            if (odcmIsDebug()) {
                console.log('🔍 RENDERING SETTINGS FORM for component:', componentType, index);
                console.log('🔍 Schema properties:', Object.keys(schema.properties));
                console.log('🔍 Has comparison_type:', !!schema.properties.comparison_type);
                console.log('🔍 Has conditional groups:', hasConditionalGroups);
            }

            // Get the current comparison type for Alpine.js reactive binding
            const comparisonType = hasConditionalGroups 
                ? (currentSettings?.comparison_type || schema.properties.comparison_type.default || 'relative')
                : null;

            let html = '<div class="odcm-settings-form"';
            html += ` data-component-type="${componentType}"`;
            html += ` data-index="${index !== null ? index : ''}"`;
            
            // If using conditional groups, wrap the entire form in x-data for Alpine.js reactivity
            if (hasConditionalGroups) {
                html += ` x-data="{ activeGroup: '${comparisonType}' }"`;
            }
            html += '>';

            if (hasConditionalGroups) {
                if (odcmIsDebug()) {console.log('🔍 USING CONDITIONAL RENDERING PATH with activeGroup:', comparisonType);}
                // Render non-conditional fields (includes radio buttons that control visibility)
                html += '<div class="odcm-non-conditional-fields">';
                const nonConditionalFields = this.getNonConditionalFieldsForSchema(schema);
                for (const [key, property] of Object.entries(nonConditionalFields)) {
                    html += this.renderFormField(key, property, currentSettings[key], componentType, index);
                }
                html += '</div>';

                // Render conditional field groups (these will use x-show to toggle visibility)
                html += this.renderConditionalFieldGroups(schema, currentSettings, componentType, index);
            } else {
                if (odcmIsDebug()) {console.log('🔍 USING LEGACY RENDERING PATH');}
                // Original behavior for backward compatibility
                for (const [key, property] of Object.entries(schema.properties)) {
                    html += this.renderFormField(key, property, currentSettings[key], componentType, index);
                }
            }

            html += '</div>';
            return html;
        },

        /**
         * Render conditional field groups
         * Note: This method relies on the parent container (renderSettingsForm) having
         * x-data="{ activeGroup: '...' }" for Alpine.js reactivity.
         */
        renderConditionalFieldGroups(schema, currentSettings, componentType, index) {
            const comparisonType = currentSettings?.comparison_type || schema.properties.comparison_type.default;
            const conditionalGroups = schema.properties.comparison_type['ui:conditional_groups'];
            let html = '';

            // Debug log to verify this method is being called
            if (odcmIsDebug()) {
                console.log('🔧 RENDERING CONDITIONAL FIELD GROUPS for comparison type:', comparisonType);
                console.log('🔧 Conditional groups found:', Object.keys(conditionalGroups));
                console.log('🔧 Current settings:', currentSettings);
            }

            // Container for conditional field groups (no x-data here - parent form already has it)
            html += `<div class="odcm-conditional-field-groups">`;

            // Create a container for each possible group
            for (const [groupKey, fieldKeys] of Object.entries(conditionalGroups)) {
                if (odcmIsDebug()) {console.log(`🔧 Processing group: ${groupKey} with fields:`, fieldKeys);}

                html += `<div class="odcm-field-group odcm-field-group-${groupKey}"`;
                html += ` x-show="activeGroup === '${groupKey}'"`;
                html += `>`;

                // Add group header
                const groupLabel = schema.properties.comparison_type.enum[groupKey] || groupKey;
                html += `<div class="odcm-field-group-header">${groupLabel}</div>`;

                // Group fields by their ui:inline_group values for proper horizontal layout
                const inlineGroups = {};

                // First, organize fields by their inline group
                for (const fieldKey of fieldKeys) {
                    if (schema.properties[fieldKey]) {
                        const field = schema.properties[fieldKey];
                        const inlineGroup = field['ui:inline_group'] || 'default';

                        if (!inlineGroups[inlineGroup]) {
                            inlineGroups[inlineGroup] = [];
                        }
                        inlineGroups[inlineGroup].push({
                            key: fieldKey,
                            field: field,
                            value: currentSettings[fieldKey]
                        });

                        if (odcmIsDebug()) {console.log(`🔧 Field ${fieldKey} assigned to inline group: ${inlineGroup}`);}
                    }
                }

                if (odcmIsDebug()) {console.log(`🔧 Inline groups organized:`, Object.keys(inlineGroups));}

                // Render each inline group
                for (const [inlineGroupName, fields] of Object.entries(inlineGroups)) {
                    if (odcmIsDebug()) {console.log(`🔧 Rendering inline group: ${inlineGroupName} with ${fields.length} fields`);}

                    if (inlineGroupName === 'default') {
                        // Render default fields individually
                        for (const fieldData of fields) {
                            html += `<div class="odcm-form-group">`;
                            html += this.renderFormField(
                                fieldData.key,
                                fieldData.field,
                                fieldData.value,
                                componentType,
                                index
                            );
                            html += `</div>`;
                        }
                    } else {
                        // Render inline group fields together in a horizontal container
                        if (odcmIsDebug()) {console.log(`🔧 Creating horizontal container for inline group: ${inlineGroupName}`);}
                        html += `<div class="odcm-form-group odcm-inline-group odcm-inline-group--${inlineGroupName}">`;
                        html += `<div class="odcm-horizontal-field-group">`;

                        for (const fieldData of fields) {
                            if (odcmIsDebug()) {console.log(`🔧 Rendering field ${fieldData.key} with skipWrapper=true`);}
                            html += this.renderFormField(
                                fieldData.key,
                                fieldData.field,
                                fieldData.value,
                                componentType,
                                index,
                                true  // Skip wrapper for inline group fields
                            );
                        }

                        html += `</div>`;
                        html += `</div>`;
                    }
                }

                html += '</div>';
            }

            html += '</div>';
            return html;
        },

        /**
         * Get non-conditional fields from schema
         */
        getNonConditionalFieldsForSchema(schema) {
            if (!schema.properties.comparison_type?.['ui:conditional_groups']) {
                return schema.properties;
            }

            const conditionalFields = new Set(
                Object.values(schema.properties.comparison_type['ui:conditional_groups']).flat()
            );

            return Object.fromEntries(
                Object.entries(schema.properties).filter(
                    ([key]) => !conditionalFields.has(key)
                )
            );
        },

        /**
         * Check if a component uses conditional field groups
         * @param {string} componentType - Type of component (trigger, condition, action)
         * @param {string} componentId - ID of the component
         * @return {boolean} True if component uses conditional groups
         */
        hasConditionalGroups(componentType, componentId) {
            const component = this.getComponentDefinition(componentType, componentId);
            return component?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
        },

        /**
         * Get conditional field groups for the current selection
         * @param {string} componentType - Type of component
         * @param {number} index - Component index
         * @return {Array|null} Array of field keys for current group, or null if no conditional groups
         */
        getConditionalFieldGroups(componentType, index) {
            const component = this.getComponentDefinition(componentType, this.rule[componentType + 's'][index]?.id);
            if (!component || !component.schema?.properties?.comparison_type?.['ui:conditional_groups']) {
                return null;
            }

            const comparisonType = this.rule[componentType + 's'][index]?.settings?.comparison_type;
            const conditionalGroups = component.schema.properties.comparison_type['ui:conditional_groups'];

            return conditionalGroups[comparisonType] || [];
        },

        /**
         * Get fields that should always be visible (not part of conditional groups)
         * @param {string} componentType - Type of component
         * @param {number} index - Component index
         * @return {Object} Fields that are not in any conditional group
         */
        getNonConditionalFields(componentType, index) {
            const component = this.getComponentDefinition(componentType, this.rule[componentType + 's'][index]?.id);
            if (!component || !component.schema?.properties?.comparison_type?.['ui:conditional_groups']) {
                return this.fields; // No conditional groups, show all fields
            }

            const conditionalGroups = component.schema.properties.comparison_type['ui:conditional_groups'];
            const allConditionalFields = new Set(Object.values(conditionalGroups).flat());

            return Object.fromEntries(
                Object.entries(this.fields).filter(
                    ([key, field]) => !allConditionalFields.has(key)
                )
            );
        },

        renderFormField(key, property, value, componentType, index, skipWrapper = false) {
            const fieldId = `${componentType}_${index !== null ? index + '_' : ''}${key}`;

            let html = skipWrapper ? '' : '<div class="odcm-form-group">';
            
            if (property.title && !skipWrapper) {
                html += `<label for="${fieldId}" class="odcm-form-label">${property.title}</label>`;
            }
            
            if (property.description && !skipWrapper) {
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
                if (odcmIsDebug()) {console.log('🔍 TEXTAREA DEBUG: About to render textarea for key:', key);}
                html += this.renderTextarea(key, property, value, fieldId, componentType, index);
            } else {
                html += this.renderTextInput(key, property, value, fieldId, componentType, index);
            }

            html += skipWrapper ? '' : '</div>';
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

                // Check if this radio group controls conditional field groups
                const isConditionalController = key === 'comparison_type' && componentType === 'condition' && index !== null;

                // Build the @change handler - combine both handlers into one attribute
                let changeHandler = `updateRadioSetting('${key}', $event.target.value, '${componentType}', ${index})`;
                if (isConditionalController) {
                    changeHandler += `; activeGroup = $event.target.value`;
                }

                html += `
                    <label class="odcm-radio-label" for="${radioId}" role="radio" aria-checked="${String(isChecked)}" tabindex="0"
                           @keydown.enter.prevent="$event.currentTarget.querySelector('input')?.click()"
                           @keydown.space.prevent="$event.currentTarget.querySelector('input')?.click()">
                        <input type="radio" 
                               id="${radioId}"
                               name="${fieldId}" 
                               value="${optionValue}" 
                               ${isChecked ? 'checked' : ''}
                               @change="${changeHandler}">
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
            const placeholder = property['ui:placeholder'] || '';
            return `<textarea id="${fieldId}" 
                              class="odcm-form-textarea"
                              rows="4"
                              placeholder="${placeholder}"
                              @input="updateSetting('${key}', $event.target.value, '${componentType}', ${index})">${textValue}</textarea>`;
        },

        renderTextInput(key, property, value, fieldId, componentType, index) {
            const inputValue = value || property.default || '';
            const inputType = property.type === 'number' || property.type === 'integer' ? 'number' :
                property.format === 'email' ? 'email' : 'text';
            const placeholder = property['ui:placeholder'] || '';

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
                           placeholder="${placeholder}"
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

        /*
        *
        * Settings update methods
        * 
        */

        updateSetting(key, value, componentType, index) {
            if (odcmIsDebug()) {console.log(`🔧 UPDATE: Setting ${key} = ${JSON.stringify(value)} for ${componentType}[${index}]`);}
            
            if (componentType === 'trigger') {
                if (!this.rule.trigger) this.rule.trigger = { id: '', settings: {} };
                // Guard: PHP json_decode(true) turns {} into [], so settings may arrive as
                // a JS Array. JSON.stringify silently drops string-keyed array properties,
                // so we must convert it back to a plain object before writing.
                if (Array.isArray(this.rule.trigger.settings)) { this.rule.trigger.settings = {}; }
                this.rule.trigger.settings[key] = value;
            } else if (componentType === 'condition') {
                if (this.rule.conditions[index]) {
                    if (Array.isArray(this.rule.conditions[index].settings)) { this.rule.conditions[index].settings = {}; }
                    this.rule.conditions[index].settings[key] = value;
                }
            } else if (componentType === 'primaryAction') {
                if (!this.rule.primaryAction) this.rule.primaryAction = { id: '', settings: {} };
                if (Array.isArray(this.rule.primaryAction.settings)) { this.rule.primaryAction.settings = {}; }
                this.rule.primaryAction.settings[key] = value;
            } else if (componentType === 'action') {
                if (this.rule.secondaryActions[index]) {
                    if (Array.isArray(this.rule.secondaryActions[index].settings)) { this.rule.secondaryActions[index].settings = {}; }
                    this.rule.secondaryActions[index].settings[key] = value;
                }
            }

            // CRITICAL: Directly write the current rule to the hidden form field on
            // every setting change.  Alpine's $watch('rule', ...) in the PHP template
            // only fires when the rule *reference* is replaced, NOT on deep property
            // mutations. autoSave() has a staleness guard that may also skip the
            // update. Writing here unconditionally guarantees the hidden field is
            // always in sync before the WordPress Publish/Update form is submitted.
            this._syncHiddenField();
            
            this.autoSave();
        },

        // Writes the current rule object to the hidden WordPress form field.
        // Called unconditionally on every setting mutation to guarantee the form
        // always submits the latest data regardless of Alpine $watch behaviour.
        _syncHiddenField() {
            try {
                const hiddenField = document.getElementById('odcm_rule_data_field');
                if (hiddenField) {
                    hiddenField.value = JSON.stringify(this.rule);
                }
            } catch (e) {
                if (odcmIsDebug()) { console.error('ODCM _syncHiddenField error:', e); }
            }
        },

        updateArraySetting(key, value, checked, componentType, index) {
            if (odcmIsDebug()) {console.log(`🔧 UPDATE ARRAY: ${key}[${value}] = ${checked} for ${componentType}[${index}]`);}

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
            if (odcmIsDebug()) {console.log(`🔧 SELECT ALL: ${key} for ${componentType}[${index}]`);}
            
            // Get all available option values
            const allValues = Object.keys(enumOptions || {});
            this.updateSetting(key, allValues, componentType, index);
        },

        // Clear All functionality for regular checkbox groups
        clearAllCheckboxes(key, componentType, index) {
            if (odcmIsDebug()) {console.log(`🔧 CLEAR ALL: ${key} for ${componentType}[${index}]`);}
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
            if (odcmIsDebug()) {console.log(`🔧 UPDATE RADIO: ${key} = ${value} for ${componentType}[${index}]`);}

            // Special handling for comparison_type changes to trigger re-render
            if (key === 'comparison_type' && componentType === 'condition' && index !== null) {
                if (odcmIsDebug()) {console.log(`🔧 TRIGGERING RE-RENDER FOR COMPARISON TYPE CHANGE: ${value}`);}

                // Force a re-render by toggling the editing state
                const currentEditingIndex = this.editingConditionIndex;
                if (currentEditingIndex === index) {
                    // Briefly close and re-open the settings panel to force re-render
                    this.editingConditionIndex = null;
                    setTimeout(() => {
                        this.editingConditionIndex = index;
                    }, 50);
                }
            }

            this.updateSetting(key, value, componentType, index);
        },

        // Handle radio-with-inline-number sibling field updates
        updateSiblingField(radioKey, siblingKey, value, componentType, index) {
            if (odcmIsDebug()) {console.log(`🔧 UPDATE SIBLING: ${siblingKey} = ${value} (radio: ${radioKey}) for ${componentType}[${index}]`);}

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

            // Mark current data as saved so the next cycle doesn't re-save unnecessarily
            this.lastSaveData = currentData;

            // Update hidden form field for WordPress standard save
            // (also updated unconditionally by _syncHiddenField on every updateSetting call)
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
                // Check if this component uses the new conditional groups system
                this.usesConditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];

                if (this.usesConditionalGroups) {
                    // New conditional rendering logic
                    this.initConditionalFields(schema, currentSettings);
                } else {
                    // Original behavior for backward compatibility
                    this.initLegacyFields(schema, currentSettings);
                }
            } catch (e) {
                console.error('ODCM settingsPanel.initSettings error:', e);
                this.fields = {};
            }
        },

        initConditionalFields(schema, currentSettings) {
            // Initialize fields with conditional rendering support
            this.fields = {};
            const nonConditionalFields = window.ruleBuilderInstance.getNonConditionalFieldsForSchema(schema);

            // Process non-conditional fields
            Object.entries(nonConditionalFields).forEach(([propKey, prop]) => {
                this.fields[propKey] = this.createFieldDefinition(propKey, prop, currentSettings);
            });

            // Process conditional fields (they'll be handled by conditional groups)
            const conditionalFields = this.getConditionalFieldsFromSchema(schema);
            Object.entries(conditionalFields).forEach(([propKey, prop]) => {
                this.fields[propKey] = this.createFieldDefinition(propKey, prop, currentSettings);
            });
        },

        initLegacyFields(schema, currentSettings) {
            // Original field initialization logic
            if (schema && schema.properties) {
                Object.entries(schema.properties).forEach(([propKey, prop]) => {
                    this.fields[propKey] = this.createFieldDefinition(propKey, prop, currentSettings);
                });
            }
        },

        createFieldDefinition(propKey, prop, currentSettings) {
            const enumOptions = prop.items?.enum || prop.enum || {};

            // Resolve initial value from live settings, falling back to schema default.
            // We use a plain property (not Object.defineProperty getter) so that Alpine.js
            // tracks mutations to fieldDef.value as plain reactive data. Reactivity is
            // maintained by the bridge methods (updateSetting etc.) which write both to
            // rule.x.settings AND to this.fields[key].value in the Alpine reactive scope.
            let initialValue;
            if (currentSettings && currentSettings[propKey] !== undefined) {
                initialValue = currentSettings[propKey];
            } else if (prop.default !== undefined) {
                initialValue = prop.default;
            } else if (prop.type === 'integer' || prop.type === 'number') {
                initialValue = 0;
            } else if (prop.type === 'array') {
                initialValue = [];
            } else {
                initialValue = '';
            }

            const fieldDef = {
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
                // Plain reactive value property - kept in sync by bridge methods (updateSetting, etc.)
                value: initialValue,
                enumOptions: enumOptions,
                selectedValues: Array.isArray(currentSettings?.[propKey]) ? currentSettings[propKey] : Array.isArray(prop.default) ? prop.default : [],
                placeholder: prop['ui:placeholder'] || '',
                minimum: prop.minimum ?? null,
                maximum: prop.maximum ?? null,
                step: prop.step ?? (prop.type === 'integer' ? 1 : null),
                default: prop.default ?? (prop.type === 'integer' || prop.type === 'number' ? 0 : (prop.type === 'array' ? [] : '')),
                radioInputs: prop['ui:radio_inputs'] || {},
                inlineGroup: prop['ui:inline_group'] ?? null
            };

            return fieldDef;
        },

        getConditionalFieldsFromSchema(schema) {
            if (!schema.properties.comparison_type?.['ui:conditional_groups']) {
                return {};
            }

            const conditionalGroups = schema.properties.comparison_type['ui:conditional_groups'];
            const conditionalFields = {};

            // Collect all fields that are in conditional groups
            Object.values(conditionalGroups).forEach(fieldKeys => {
                fieldKeys.forEach(fieldKey => {
                    if (schema.properties[fieldKey]) {
                        conditionalFields[fieldKey] = schema.properties[fieldKey];
                    }
                });
            });

            return conditionalFields;
        },

        // Add conditional rendering support
        getConditionalFieldGroups() {
            try {
                // Only apply conditional rendering for conditions with proper index
                if (componentType !== 'condition' || index === null || index === undefined) {
                    return null;
                }

                const condition = window.ruleBuilderInstance?.rule?.conditions?.[index];
                if (!condition?.id) {
                    return null;
                }

                const component = window.getConditionComponent(condition.id);
                if (!component?.schema?.properties?.comparison_type?.['ui:conditional_groups']) {
                    return null;
                }

                const comparisonType = condition.settings?.comparison_type;
                const conditionalGroups = component.schema.properties.comparison_type['ui:conditional_groups'];

                return conditionalGroups[comparisonType] || [];
            } catch (e) {
                console.error('ODCM getConditionalFieldGroups error:', e);
                return null;
            }
        },

        // Check if a field should be visible based on current selection
        shouldShowField(fieldKey) {
            try {
                const conditionalGroups = this.getConditionalFieldGroups();
                if (!conditionalGroups) return true;

                return conditionalGroups.includes(fieldKey);
            } catch (e) {
                console.error('ODCM shouldShowField error:', e);
                return true; // Fail safe: show the field if there's an error
            }
        },

        // Get the current comparison type
        getCurrentComparisonType() {
            try {
                if (componentType !== 'condition' || index === null || index === undefined) {
                    return null;
                }

                return window.ruleBuilderInstance?.rule?.conditions?.[index]?.settings?.comparison_type;
            } catch (e) {
                console.error('ODCM getCurrentComparisonType error:', e);
                return null;
            }
        },

        // ─── Bridge update helpers ────────────────────────────────────────────
        // Each bridge writes to the authoritative rule object via ruleBuilderInstance
        // AND keeps this.fields[key].value in sync so Alpine's reactive `:value`
        // bindings re-render immediately without relying on deep-proxy tracking of
        // cross-component state.  The activeGroup sync ensures button_radio_group
        // widgets (like TimingCondition's comparison_type) correctly show/hide
        // conditional field groups without depending on $watch bracket-notation paths.

        updateSetting(key, value, type, idx) {
            // 1. Write to the authoritative rule object
            window.ruleBuilderInstance?.updateSetting(key, value, type, idx);

            // 2. Mirror the new value into the local Alpine-reactive fields object so
            //    `:value="field.value"` and `:checked` bindings re-render immediately.
            if (this.fields && key in this.fields) {
                this.fields[key].value = value;
                // Keep selectedValues in sync for array-backed fields
                if (Array.isArray(value)) {
                    this.fields[key].selectedValues = [...value];
                }
            }

            // 3. If this is the comparison_type controller field, update activeGroup
            //    directly so conditional field groups show/hide without needing $watch
            //    (which may not support bracket notation in all Alpine.js v3 builds).
            if (key === 'comparison_type' && typeof this.activeGroup !== 'undefined') {
                this.activeGroup = value;
                if (odcmIsDebug()) { console.log(`🔧 BRIDGE: activeGroup updated to "${value}"`); }
            }
        },

        updateArraySetting(key, value, checked, type, idx) {
            // 1. Write to the authoritative rule object
            window.ruleBuilderInstance?.updateArraySetting(key, value, checked, type, idx);

            // 2. Mirror the updated array back into fields so `:checked` bindings
            //    reflect the change without waiting for deep-proxy re-evaluation.
            if (this.fields && key in this.fields) {
                // Work from the current selectedValues to avoid async mismatch
                let arr = Array.isArray(this.fields[key].selectedValues)
                    ? [...this.fields[key].selectedValues]
                    : [];
                if (checked && !arr.includes(value)) {
                    arr.push(value);
                } else if (!checked) {
                    arr = arr.filter(v => v !== value);
                }
                this.fields[key].selectedValues = arr;
                this.fields[key].value = arr;
            }
        },

        updateRadioSetting(key, value, type, idx) {
            // 1. Write to the authoritative rule object
            window.ruleBuilderInstance?.updateRadioSetting(key, value, type, idx);

            // 2. Mirror into local fields
            if (this.fields && key in this.fields) {
                this.fields[key].value = value;
            }

            // 3. Sync activeGroup for comparison_type controller
            if (key === 'comparison_type' && typeof this.activeGroup !== 'undefined') {
                this.activeGroup = value;
                if (odcmIsDebug()) { console.log(`🔧 BRIDGE: activeGroup updated to "${value}" via updateRadioSetting`); }
            }
        },

        updateSiblingField(parentKey, siblingKey, value, type, idx) {
            // 1. Write to the authoritative rule object
            window.ruleBuilderInstance?.updateSiblingField(parentKey, siblingKey, value, type, idx);

            // 2. Mirror sibling value into local fields
            if (this.fields && siblingKey in this.fields) {
                const numericValue = value === '' ? null : (isNaN(Number(value)) ? value : Number(value));
                this.fields[siblingKey].value = numericValue;
            }
        }
    };
}

// Expose ruleBuilder to global scope for Alpine.js
window.ruleBuilder = ruleBuilder;

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
        key: '',
        searchTerm: '',
        showAll: false,
        init(enumOptions, selectedValues, key) {
            this.options = Object.entries(enumOptions || {}).map(([value, label]) => ({ value, label }));
            this.filteredOptions = this.options;
            this.selectedValues = Array.isArray(selectedValues) ? selectedValues : [];
            this.key = key || '';
        },
        filterOptions() {
            const term = (this.searchTerm || '').toLowerCase();
            this.filteredOptions = this.options.filter(opt => this.showAll || opt.label.toLowerCase().includes(term));
        },
        getOptionClasses(value) {
            const isSelected = this.selectedValues.includes(value);
            return `odcm-checkbox-label${isSelected ? ' is-selected' : ''}`;
        },
        shouldShowOption(value, label) {
            if (this.showAll) return true;
            const term = (this.searchTerm || '').toLowerCase();
            return label.toLowerCase().includes(term);
        },
        shouldDisableOption(value) {
            return false;
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
            // Get all available options
            const availableOptions = this.options.map(option => option.value);
            
            this.selectedValues = [...availableOptions];
            window.ruleBuilderInstance?.updateSetting(key, availableOptions, componentType, index);
        },
        get canSelectAll() {
            // Check if there are any unselected options
            const availableOptions = this.options.map(option => option.value);
            
            return availableOptions.some(value => !this.selectedValues.includes(value));
        },
        get hasSelectableOptions() {
            // Check if there are any options that can be selected
            return this.options.length > 0;
        }
    };
}
