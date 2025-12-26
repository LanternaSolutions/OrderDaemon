/**
 * Insight Dashboard Alpine.js Application
 *
 * Modern, reactive dashboard for audit log analysis with real-time updates,
 * advanced filtering, and detailed log inspection capabilities.
 *
 * Features:
 * - Alpine.js 3.14.9 reactive state management
 * - Auto-refreshing log stream (5-second intervals)
 * - Filter persistence with localStorage
 * - Mobile-responsive slide-out panes
 * - Toast notification system
 * - WordPress standard pagination
 *
 * @package OrderDaemon\CompletionManager
 * @since   1.0.0
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

// Ensure global ODCM_DEBUG is set from localized config when available (non-destructive)
(function(){
    try {
        // Only set ODCM_DEBUG if it's truly undefined (not just falsy)
        // This prevents overriding a user-defined false value
        if (typeof window !== 'undefined' &&
            typeof window.ODCM_DEBUG === 'undefined' &&
            window.odcmInsightConfig &&
            typeof window.odcmInsightConfig.debug !== 'undefined') {
            // Check if ODCM_DEBUG was defined in wp-config.php by looking for it in the global scope
            // If it's not defined anywhere, we can safely set it
            if (typeof ODCM_DEBUG_from_wp_config === 'undefined') {
                window.ODCM_DEBUG = !!window.odcmInsightConfig.debug;
            }
        }
    } catch (e) {}
})();

// Define the insight dashboard function
function insightDashboard() {
    return {
        // =================================================================
        // REACTIVE STATE
        // =================================================================

        // New installation
        isWelcomeScenario: false,

        // Log data
        logs: [],
        selectedLog: null,
        loading: false,
        error: null,
        initialLoad: true, // Track if this is the initial page load

        // Batch selection
        selectedLogIds: [],
        selectAll: false,
        isDeleting: false,

        // Pagination
        currentPage: 1,
        totalPages: 1,
        total: 0,
        perPage: 20,

        // Filters with persistence (will be set up in init)
        filters: {
            search: '',
            status: '',
            event_type: '',
            source: '',
            order_id: '',
            date_start: '',
            date_end: '',
            include_tests: false,
            include_debug: false
        },

        // UI state
        filterPaneVisible: true, // Start with filter pane visible by default
        detailPaneExpanded: false,
        activeFilterTab: 'filters', // 'filters' or 'settings'
        lastOpenedTab: 'filters', // remembers last opened tab for reopen action

        // View mode: 'consolidated' (default) or 'flat'
        viewMode: 'consolidated',

        // Detail pane
        detailHtml: '',
        detailLoading: false,

        // Auto-refresh
        autoRefreshEnabled: true,
        refreshInterval: 5, // User-configurable interval in seconds
        autoRefreshTimer: null,
        lastFetchTime: null,
        isRefreshing: false, // Tracks active API calls for accurate spinner

        // Date/time display settings
        timestampDisplayMode: 'dateTime', // 'timeOnly', 'dateTime', 'relative'

        // Toast notifications (using shared system)

        // Premium access
        canUsePremiumFilters: false,

        // Dynamic filter options and client-side caching (5 minutes)
        filterOptions: {
            sources: [],
            event_types: [],
            statuses: []
        },
        filterOptionsCache: null,
        filterOptionsCacheExpiry: null,

        // Configuration from PHP
        config: window.odcmInsightConfig || {},
        i18n: window.odcmInsightConfig?.i18n || {},

        // Server state tracking for retention policy
        serverRetentionState: null,

        // Settings accordion state
        settingsAccordionState: {
            display: true,          // Display Options expanded by default
            orderProcessing: false, // Order Processing collapsed by default
            education: false,       // Education collapsed by default
            debug: false,           // Debug Settings collapsed by default
            dataManagement: false   // Data Management collapsed by default
        },

        // Reprocess pending orders state
        isReprocessing: false,

        // Export logs state (premium feature)
        isExporting: false,
        exportFormat: null,
        // Export logs state (premium feature)
        isExporting: false,
        exportFormat: null,

        // Generate sample logs state

        // =================================================================
        // INITIALIZATION
        // =================================================================

        init() {
            try {
                if (odcmIsDebug()) { console.log('ODCM Insight Dashboard: Initializing...'); }

                // Set up configuration
                this.perPage = this.config.perPage || 20;

                // Load persistent settings
                this.loadSettings();

                // Load view mode from localStorage
                try {
                    const savedView = localStorage.getItem('odcm_view_mode');
                    if (savedView === 'flat' || savedView === 'consolidated') {
                        this.viewMode = savedView;
                    }
                } catch (e) { /* ignore */ }

                // Debug initial state
                if (odcmIsDebug()) { console.log('ODCM: Initial state - filterPaneVisible:', this.filterPaneVisible, 'detailsOpen:', this.detailsOpen); }
                if (odcmIsDebug()) { console.log('ODCM: Dashboard classes:', this.dashboardClasses); }

                // Initialize Prism.js highlighting
                this.initializePrismHighlighting();

                // Initialize three-tier toggles early for immediate functionality
                this.initThreeTierToggles();

                // Load initial data in parallel, now including the welcome scenario check
                const start = performance.now();
                Promise.all([
                    this.fetchLogs(),
                    this.fetchFilterOptionsWithCache(),
                    this.checkWelcomeScenario() // Moved here to run in parallel
                ]).then(() => {
                    const elapsed = performance.now() - start;
                    if (odcmIsDebug() && elapsed > 500) {
                        console.warn(`ODCM: Slow initial load - ${Math.round(elapsed)}ms`);
                    }

                    // Start auto-refresh only AFTER all initial data is loaded
                    if (this.autoRefreshEnabled) {
                        this.startAutoRefresh();
                    }
                }).catch((e) => {
                    console.error('ODCM: Initialization encountered errors:', e);
                    this.showToast(this.i18n?.error || 'An error occurred while initializing the dashboard', 'error');
                });

                // Set up watchers and other synchronous tasks
                this.setupFilterWatchers();
                this.setupDebouncedFetch();
                this.setupSettingsWatchers();
                this.initializeServerRetentionState();
                this.setupPrismWatchers();

                if (odcmIsDebug()) { console.log('ODCM Insight Dashboard: Initialized successfully'); }
            } catch (e) {
                console.error('ODCM: init() failed:', e);
                this.showToast(this.i18n?.error || 'Dashboard failed to initialize. Please refresh the page.', 'error');
                // Disable auto refresh on hard failure
                this.autoRefreshEnabled = false;
            }
        },

        async checkWelcomeScenario() {
            try {
                // Check if any order rules exist
                const response = await fetch(`${this.config.ajaxUrl}?action=odcm_check_welcome_scenario`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        _wpnonce: this.config.nonce
                    })
                });

                if (response.ok) {
                    const data = await response.json();
                    this.isWelcomeScenario = data.success && data.data.is_welcome_scenario;
                    if (odcmIsDebug()) {
                        console.log('ODCM: Welcome scenario check:', this.isWelcomeScenario);
                    }
                }
            } catch (error) {
                if (odcmIsDebug()) {
                    console.warn('ODCM: Could not check welcome scenario:', error);
                }
                // Default to false if we can't determine
                this.isWelcomeScenario = false;
            }
        },

        setupFilterWatchers() {
            // Watch for filter changes and reset pagination
            this.$watch('filters', () => {
                this.currentPage = 1;
                this.debouncedFetchLogs();
            });
        },

        // =================================================================
        // PRISM.JS INTEGRATION
        // =================================================================

        /**
         * Initialize Prism.js highlighting for any existing code blocks
         */
        initializePrismHighlighting() {
            if (typeof Prism !== 'undefined') {
                try {
                    Prism.highlightAll();
                    if (odcmIsDebug()) { console.log('ODCM: Prism.js initial highlighting completed'); }
                } catch (error) {
                    if (odcmIsDebug()) { console.warn('ODCM: Prism.js initial highlighting failed:', error); }
                }
            } else {
                if (odcmIsDebug()) { console.warn('ODCM: Prism.js not available for initial highlighting'); }
            }
        },

        /**
         * Highlight code blocks in a specific container or document
         */
        highlightCodeBlocks(container = document) {
            if (typeof Prism !== 'undefined') {
                try {
                    const codeBlocks = container.querySelectorAll('pre[class*="language-"] code, code[class*="language-"]');
                    codeBlocks.forEach(block => {
                        Prism.highlightElement(block);
                    });

                    if (codeBlocks.length > 0) {
                        if (odcmIsDebug()) { console.log(`ODCM: Prism.js highlighted ${codeBlocks.length} code blocks`); }
                    }
                } catch (error) {
                    if (odcmIsDebug()) { console.warn('ODCM: Prism.js highlighting failed:', error); }
                }
            } else {
                if (odcmIsDebug()) { console.warn('ODCM: Prism.js not available for code block highlighting'); }
            }
        },

        /**
         * Set up watchers for dynamic content that needs Prism.js highlighting
         */
        setupPrismWatchers() {
            // Watch for detail content changes and re-highlight
            this.$watch('detailHtml', () => {
                this.$nextTick(() => {
                    const detailPane = document.querySelector('.odcm-detail-content');
                    if (detailPane) {
                        this.highlightCodeBlocks(detailPane);
                    }
                });
            });
        },

        // =================================================================
        // DATA FETCHING
        // =================================================================

        async fetchLogs() {
            if (this.loading) return;

            this.loading = true;
            this.error = null;

            const maxRetries = 2;
            let attempt = 0;
            let lastError = null;
            while (attempt <= maxRetries) {
                try {
                    const params = new URLSearchParams({
                        page: this.currentPage,
                        per_page: this.perPage,
                        view: this.viewMode || 'consolidated',
                        ...this.getActiveFilters()
                    });

                    const response = await fetch(`${this.config.apiUrl}?${params}`, {
                        headers: {
                            'X-WP-Nonce': this.config.nonce
                        }
                    });

                    if (!response.ok) {
                        // Enhanced error handling with more context for 404 troubleshooting
                        const errorText = await response.text().catch(() => 'Unable to read error response');

                        if (odcmIsDebug()) {
                            console.error('ODCM API Error Details:', {
                                status: response.status,
                                statusText: response.statusText,
                                url: response.url,
                                headers: Object.fromEntries(response.headers.entries()),
                                body: errorText,
                                timestamp: new Date().toISOString()
                            });
                        }

                        // Handle 404 properly - don't override welcome scenario
                        if (response.status === 404) {
                            if (odcmIsDebug()) {
                                console.log('ODCM: API returned 404, clearing logs but preserving welcome scenario state');
                            }

                            // Clear logs but don't override welcome scenario state
                            this.logs = [];
                            this.total = 0;
                            this.totalPages = 1;
                            this.currentPage = 1;
                            // Don't change this.isWelcomeScenario here - let it be determined by the backend
                            this.error = null;
                            this.initialLoad = false;
                            this.loading = false;
                            this.lastFetchTime = new Date().toISOString();

                            // success; break loop
                            lastError = null;
                            break;
                        }

                        // Handle specific HTTP errors
                        if (response.status >= 500 && attempt < maxRetries) {
                            // transient server error - retry
                            attempt++;
                            if (odcmIsDebug()) console.warn(`ODCM: fetchLogs retry ${attempt} after server error ${response.status}`);
                            await new Promise(r => setTimeout(r, attempt * 400));
                            continue;
                        }
                        if (response.status === 500) {
                            throw new Error('Server error - please check the include test/debug log settings');
                        }
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();

                    // Validate response structure
                    if (!data || typeof data !== 'object') {
                        throw new Error('Invalid response format from server');
                    }

                    // Validate and filter log data before setting state
                    const rawLogs = Array.isArray(data.logs) ? data.logs : [];

                    // Debug raw data structure
                    if (odcmIsDebug()) {
                        console.log('ODCM: Raw logs received:', rawLogs.length, 'entries');
                        rawLogs.forEach((log, index) => {
                            if (!log || typeof log !== 'object') {
                                console.warn(`ODCM: Non-object at index ${index}:`, log);
                            } else if (log.id === undefined || log.id === null) {
                                console.warn(`ODCM: Missing ID at index ${index}:`, log);
                            } else if (typeof log.summary !== 'string') {
                                console.warn(`ODCM: Invalid summary at index ${index}:`, log);
                            }
                        });
                    }

                    const validLogs = rawLogs.filter((log, index) => {
                        // Validate each log entry
                        const isValid = log &&
                                       typeof log === 'object' &&
                                       log.id !== undefined &&
                                       log.id !== null &&
                                       typeof log.summary === 'string';

                        return isValid;
                    });

                    // Check for duplicate IDs and make them unique
                    const seenIds = new Set();
                    const uniqueLogs = validLogs.map((log, index) => {
                        let uniqueId = log.id;
                        let counter = 1;

                        // If we've seen this ID before, make it unique
                        while (seenIds.has(uniqueId)) {
                            uniqueId = `${log.id}_dup_${counter}`;
                            counter++;
                        }

                        seenIds.add(uniqueId);

                        // If we had to change the ID, log it
                        if (uniqueId !== log.id && odcmIsDebug()) {
                            console.warn(`ODCM: Duplicate ID ${log.id} changed to ${uniqueId}`);
                        }

                        return {
                            ...log,
                            id: uniqueId,
                            original_id: log.id // Keep track of original ID
                        };
                    });

                    // Log validation results
                    if (odcmIsDebug()) {
                        console.log(`ODCM: Processed ${rawLogs.length} raw logs -> ${validLogs.length} valid -> ${uniqueLogs.length} unique`);
                        if (rawLogs.length !== validLogs.length) {
                            console.warn(`ODCM: Filtered out ${rawLogs.length - validLogs.length} invalid log entries`);
                        }
                    }

                    // Update state with validated and deduplicated data
                    this.logs = uniqueLogs;
                    this.total = data.pagination?.total || 0;
                    this.totalPages = data.pagination?.total_pages || 1;
                    this.currentPage = data.pagination?.current_page || 1;
                    this.lastFetchTime = new Date().toISOString();

                    // Exit welcome scenario if we received logs
                    if (this.isWelcomeScenario && uniqueLogs.length > 0) {
                        this.isWelcomeScenario = false;
                        if (odcmIsDebug()) { console.log('ODCM: Exiting welcome scenario - logs received'); }
                    }

                    // Don't override the welcome scenario detection here
                    // The proper welcome scenario check happens in checkWelcomeScenario()
                    // which runs in parallel during initialization

                        // Debug: consolidation diagnostics
                        if (odcmIsDebug()) {
                            if (data.meta?.consolidation_diag) {
                                console.debug('ODCM Consolidation Diag:', data.meta.consolidation_diag);
                                if (data.meta?.consolidation_diag?.enabled === false) {
                                    console.warn('ODCM: Consolidation disabled reason:', data.meta.consolidation_diag?.reason);
                                }
                            } else {
                                console.debug('ODCM: No consolidation diagnostics present in response meta');
                            }
                        }

                    // Log performance if debug mode
                    if (data.meta?.execution_time > 1) {
                        if (odcmIsDebug()) { console.warn(`ODCM: Slow API call - ${data.meta.execution_time}s`); }
                    }

                    // success; break loop
                    lastError = null;
                    break;

                } catch (error) {
                    lastError = error;
                    // Retry network errors (TypeError) or explicit retries handled above; otherwise break
                    const isNetwork = (error && (error.name === 'TypeError' || /NetworkError/i.test(error.message || '')));
                    if (isNetwork && attempt < maxRetries) {
                        attempt++;
                        if (odcmIsDebug()) console.warn(`ODCM: fetchLogs network retry ${attempt}`, error);
                        await new Promise(r => setTimeout(r, attempt * 400));
                        continue;
                    }
                    break;
                }
            }

            if (lastError) {
                console.error('ODCM: Error fetching logs:', lastError);
                this.error = lastError.message || this.i18n.error;
                this.showToast(this.error, 'error');
            }

            this.loading = false;
            // Mark initial load as complete after first fetch
            if (this.initialLoad) {
                this.initialLoad = false;
            }
        },

        async fetchFilterOptions() {
            const t0 = performance.now();
            try {
                const response = await fetch(`${this.config.apiUrl}filter-options/`, {
                    headers: {
                        'X-WP-Nonce': this.config.nonce
                    }
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();

                // Validate response structure
                if (!data || typeof data !== 'object') {
                    throw new Error('Invalid response format from server');
                }

                // Check premium access from PHP config (set by pro plugin if licensed)
                this.canUsePremiumFilters = !!(this.config && this.config.premium_access);

                // Dynamic filter options
                const fo = data.filter_options || { sources: [], event_types: [], statuses: [] };
                this.filterOptions = {
                    sources: Array.isArray(fo.sources) ? fo.sources : [],
                    event_types: Array.isArray(fo.event_types) ? fo.event_types : [],
                    statuses: Array.isArray(fo.statuses) ? fo.statuses : []
                };

                // Populate DOM selects dynamically for backward-compatible templates
                this.applyFilterOptionsToDOM();

                // Performance logging
                const t1 = performance.now();
                const elapsed = t1 - t0;
                if (odcmIsDebug()) {
                    const serverExec = data.meta && typeof data.meta.execution_time === 'number' ? data.meta.execution_time : null;
                    if (elapsed > 500) {
                        console.warn(`ODCM: Slow filter-options fetch - ${Math.round(elapsed)}ms`, { serverExec });
                    }
                }

                return data;
            } catch (error) {
                if (odcmIsDebug()) { console.warn('ODCM: Could not fetch filter options:', error); }
                // Graceful degradation: keep hardcoded defaults
                return null;
            }
        },

        async fetchFilterOptionsWithCache() {
            try {
                const now = Date.now();
                if (this.filterOptionsCache && this.filterOptionsCacheExpiry && now < this.filterOptionsCacheExpiry) {
                    if (odcmIsDebug()) { console.log('ODCM: Filter options served from cache'); }
                    // Apply cached options to DOM in case of re-entry
                    this.filterOptions = this.filterOptionsCache;
                    this.applyFilterOptionsToDOM();
                    return { cached: true, data: this.filterOptionsCache };
                }

                const data = await this.fetchFilterOptions();
                if (data && data.filter_options) {
                    this.filterOptionsCache = this.filterOptions;
                    const ttl = (data.meta && Number.isFinite(data.meta.cache_ttl)) ? data.meta.cache_ttl * 1000 : 300000; // default 5 min
                    this.filterOptionsCacheExpiry = Date.now() + ttl;
                    if (odcmIsDebug()) { console.log('ODCM: Filter options cached for', Math.round(ttl/1000), 's'); }
                }
                return { cached: false, data };
            } catch (e) {
                if (odcmIsDebug()) { console.warn('ODCM: Filter options cache fetch failed:', e); }
                return { cached: false, data: null };
            }
        },

        async refreshFilterOptions() {
            // Force refresh: invalidate cache and fetch
            this.filterOptionsCache = null;
            this.filterOptionsCacheExpiry = null;
            return this.fetchFilterOptionsWithCache();
        },

        applyFilterOptionsToDOM() {
            // Update the three selects if present; keep the first option (All ...)
            const statusSelect = document.getElementById('filter-status');
            const eventTypeSelect = document.getElementById('filter-event-type');
            const sourceSelect = document.getElementById('filter-source');

            const repopulate = (selectEl, items, allLabel) => {
                if (!selectEl || !Array.isArray(items)) return;
                const current = selectEl.value;
                const first = selectEl.options.length > 0 ? selectEl.options[0] : null;
                selectEl.innerHTML = '';
                if (first) {
                    // Preserve the first "All ..." option
                    selectEl.appendChild(first);
                } else {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = allLabel;
                    selectEl.appendChild(opt);
                }
                items.forEach(item => {
                    if (!item || typeof item.value !== 'string') return;
                    const opt = document.createElement('option');
                    opt.value = item.value;
                    opt.textContent = item.label || item.value;
                    selectEl.appendChild(opt);
                });
                // Restore selection if still available
                if (current && Array.isArray(items) && items.some(i => i.value === current)) {
                    selectEl.value = current;
                }
            };

            // Only populate for premium users for status/event_type/source
            const allLabels = {
                statuses: this.i18n?.allStatuses || 'All Statuses',
                eventTypes: this.i18n?.allEventTypes || 'All Event Types',
                sources: this.i18n?.allSources || 'All Sources'
            };

            if (this.canUsePremiumFilters) {
                repopulate(statusSelect, this.filterOptions.statuses, allLabels.statuses);
                repopulate(eventTypeSelect, this.filterOptions.event_types, allLabels.eventTypes);
                repopulate(sourceSelect, this.filterOptions.sources, allLabels.sources);
            }
        },

        async fetchLogDetails(logId, viewMode = 'consolidated') {
            this.detailLoading = true;

            try {
                // Debug logging to track the complete request pipeline
                if (odcmIsDebug()) {
                    console.log('ODCM: fetchLogDetails called with logId:', logId);
                    console.log('ODCM: fetchLogDetails called with viewMode:', viewMode);
                    console.log('ODCM: this.config.apiUrl:', this.config.apiUrl);
                    console.log('ODCM: this.config.nonce:', this.config.nonce ? 'present' : 'missing');
                    console.log('ODCM: this.filters.include_debug:', this.filters.include_debug);
                }

                // Use the localized renderUrl when available to avoid path mismatches
                const renderEndpoint = this.config.renderUrl || `${this.config.apiUrl}render-components/`;
                const requestPayload = {
                    log_id: logId,
                    include_debug: this.filters.include_debug,
                    view_mode: viewMode
                };

                if (odcmIsDebug()) {
                    console.log('ODCM: Making POST request to:', renderEndpoint);
                    console.log('ODCM: Request payload:', requestPayload);
                }

                const response = await fetch(renderEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.nonce
                    },
                    body: JSON.stringify(requestPayload)
                });

                if (odcmIsDebug()) {
                    console.log('ODCM: Response status:', response.status);
                    console.log('ODCM: Response headers:', Object.fromEntries(response.headers.entries()));
                }

                if (!response.ok) {
                    // Handle debug-filtered entries gracefully
                    if (response.status === 403) {
                        if (odcmIsDebug()) {
                            console.log('ODCM: Entry filtered due to debug settings');
                        }
                        return '<div class="odcm-debug-filtered">This log entry is only visible when "Include Debug Logs" is enabled.</div>';
                    }

                    if (odcmIsDebug()) {
                        const responseText = await response.text().catch(() => 'Unable to read response');
                        console.error('ODCM: API Error Response:', responseText);
                    }

                    // Try to parse structured error to surface any helpful message
                    try {
                        const errData = await response.clone().json();
                        if (errData && typeof errData === 'object') {
                            if (errData.html) {
                                return errData.html;
                            }
                            if (errData.message) {
                                return `<div class="odcm-error">${errData.message}</div>`;
                            }
                        }
                    } catch (e) {
                        // Ignore JSON parse errors
                    }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();

                if (odcmIsDebug()) {
                    console.log('ODCM: Response data:', data);
                    console.log('ODCM: HTML length:', (data.html || '').length);
                }

                // Handle special case for error templates that should be displayed directly
                if (data.error === 'odcm_render_error' && data.html &&
                    (data.use_error_template === true ||
                     (data.meta && data.meta.render_directly === true))) {

                    if (odcmIsDebug()) {
                        console.log('ODCM: Using error template directly from response');
                    }

                    return data.html;
                }

                return data.html || '';

            } catch (error) {
                console.error('ODCM: Error fetching log details:', error);
                if (odcmIsDebug()) {
                    console.error('ODCM: Full error details:', {
                        name: error.name,
                        message: error.message,
                        stack: error.stack
                    });
                }
                this.showToast('Failed to load log details', 'error');

                // Check for error details in the error object to provide more context
                let errorDetails = '';
                if (odcmIsDebug() && error && error.message) {
                    errorDetails = `<p class="odcm-error-details">${error.message}</p>`;
                }

                return `<div class="odcm-error">
                    <h3>Failed to load details</h3>
                    ${errorDetails}
                    <p>Please try again or check the debug log for more information.</p>
                </div>`;
            } finally {
                this.detailLoading = false;
            }
        },

        // =================================================================
        // SETTINGS PERSISTENCE
        // =================================================================

        loadSettings() {
            try {
                // Load auto-refresh enabled state (default: true)
                const savedEnabled = localStorage.getItem('odcm_auto_refresh_enabled');
                this.autoRefreshEnabled = savedEnabled !== null ? savedEnabled === 'true' : true;

                // Load refresh interval (default: 5, range: 1-60)
                const savedInterval = localStorage.getItem('odcm_refresh_interval');
                this.refreshInterval = savedInterval ? Math.max(1, Math.min(60, parseInt(savedInterval))) : 5;

                // Load timestamp display mode (default: 'dateTime')
                const savedDisplayMode = localStorage.getItem('odcm_timestamp_display_mode');
                this.timestampDisplayMode = savedDisplayMode || 'dateTime';

                // Load active filter tab (default: 'filters')
                const savedTab = localStorage.getItem('odcm_active_filter_tab');
                this.activeFilterTab = (savedTab === 'filters' || savedTab === 'settings') ? savedTab : 'filters';

                // Load filter pane visibility state (default: true)
                const savedPaneVisible = localStorage.getItem('odcm_filter_pane_visible');
                this.filterPaneVisible = savedPaneVisible !== null ? savedPaneVisible === 'true' : true;

                // Load settings accordion state
                const savedAccordionState = localStorage.getItem('odcm_settings_accordion_state');
                if (savedAccordionState) {
                    try {
                        const parsedState = JSON.parse(savedAccordionState);
                        // Ensure all required accordion sections exist
                        this.settingsAccordionState = {
                            display: true,
                            orderProcessing: false,
                            education: false,
                            webhooks: false,
                            debug: false,
                            dataManagement: false,
                            ...parsedState
                        };
                    } catch (error) {
                        if (odcmIsDebug()) { console.warn('ODCM: Could not parse saved accordion state:', error); }
                    }
                }

                // Load filter checkbox states (default: false for both)
                const savedIncludeTests = localStorage.getItem('odcm_include_tests');
                this.filters.include_tests = savedIncludeTests !== null ? savedIncludeTests === 'true' : false;

                const savedIncludeDebug = localStorage.getItem('odcm_include_debug');
                this.filters.include_debug = savedIncludeDebug !== null ? savedIncludeDebug === 'true' : false;

                // Load detail pane expansion state (default: false)
                const savedDetailPaneExpanded = localStorage.getItem('odcm_detail_pane_expanded');
                this.detailPaneExpanded = savedDetailPaneExpanded !== null ? savedDetailPaneExpanded === 'true' : false;

                if (odcmIsDebug()) { console.log('ODCM: Settings loaded - Auto-refresh:', this.autoRefreshEnabled, 'Interval:', this.refreshInterval, 'Display mode:', this.timestampDisplayMode, 'Active tab:', this.activeFilterTab, 'Pane visible:', this.filterPaneVisible, 'Include tests:', this.filters.include_tests, 'Include debug:', this.filters.include_debug, 'Detail pane expanded:', this.detailPaneExpanded, 'Accordion state:', this.settingsAccordionState); }
            } catch (error) {
                if (odcmIsDebug()) { console.warn('ODCM: Could not load settings from localStorage:', error); }
                // Use defaults
                this.autoRefreshEnabled = true;
                this.refreshInterval = 5;
                this.timestampDisplayMode = 'dateTime';
                this.activeFilterTab = 'filters';
                this.filterPaneVisible = true;
                this.filters.include_tests = false;
                this.filters.include_debug = false;
                this.detailPaneExpanded = false;
            }
        },

        saveSettings() {
            try {
                localStorage.setItem('odcm_auto_refresh_enabled', this.autoRefreshEnabled.toString());
                localStorage.setItem('odcm_refresh_interval', this.refreshInterval.toString());
                localStorage.setItem('odcm_timestamp_display_mode', this.timestampDisplayMode);
                localStorage.setItem('odcm_filter_pane_visible', this.filterPaneVisible.toString());
                localStorage.setItem('odcm_active_filter_tab', this.activeFilterTab);
                localStorage.setItem('odcm_include_tests', this.filters.include_tests.toString());
                localStorage.setItem('odcm_include_debug', this.filters.include_debug.toString());
                localStorage.setItem('odcm_detail_pane_expanded', this.detailPaneExpanded.toString());
                if (odcmIsDebug()) { console.log('ODCM: Settings saved - Auto-refresh:', this.autoRefreshEnabled, 'Interval:', this.refreshInterval, 'Display mode:', this.timestampDisplayMode, 'Pane visible:', this.filterPaneVisible, 'Active tab:', this.activeFilterTab, 'Include tests:', this.filters.include_tests, 'Include debug:', this.filters.include_debug, 'Detail pane expanded:', this.detailPaneExpanded); }
            } catch (error) {
                if (odcmIsDebug()) { console.warn('ODCM: Could not save settings to localStorage:', error); }
            }
        },

        setupSettingsWatchers() {
            // Watch auto-refresh enabled state
            this.$watch('autoRefreshEnabled', (enabled) => {
                this.saveSettings();
                if (enabled) {
                    this.startAutoRefresh();
                } else {
                    this.stopAutoRefresh();
                }
            });

            // Watch refresh interval changes
            this.$watch('refreshInterval', (interval) => {
                // Validate interval (1-60 seconds)
                const validInterval = Math.max(1, Math.min(60, parseInt(interval) || 5));
                if (validInterval !== interval) {
                    this.refreshInterval = validInterval;
                    return;
                }

                this.saveSettings();

                // Restart auto-refresh with new interval if enabled
                if (this.autoRefreshEnabled) {
                    this.startAutoRefresh();
                }
            });

            // Watch filter pane visibility state
            this.$watch('filterPaneVisible', (visible) => {
                this.saveSettings();
                if (odcmIsDebug()) { console.log('ODCM: Filter pane visibility changed to:', visible); }
            });

            // Watch active filter tab changes
            this.$watch('activeFilterTab', (tab) => {
                this.saveSettings();
                if (odcmIsDebug()) { console.log('ODCM: Active filter tab changed to:', tab); }
            });

            // Watch timestamp display mode changes
            this.$watch('timestampDisplayMode', (mode) => {
                this.saveSettings();
                if (odcmIsDebug()) { console.log('ODCM: Timestamp display mode changed to:', mode); }
            });

            // Watch per page changes
            this.$watch('perPage', (newPerPage) => {
                // Validate per page (10-200)
                const validPerPage = Math.max(10, Math.min(200, parseInt(newPerPage) || 20));
                if (validPerPage !== newPerPage) {
                    this.perPage = validPerPage;
                    return;
                }

                if (odcmIsDebug()) { console.log('ODCM: Per page changed to:', newPerPage); }
                // Note: perPage is saved to user_meta via updatePerPageSetting(), not localStorage
            });

            // Watch filter checkbox changes for immediate persistence
            this.$watch('filters.include_tests', (value) => {
                this.saveSettings();
                if (odcmIsDebug()) { console.log('ODCM: Include tests setting changed to:', value); }
            });

            this.$watch('filters.include_debug', (value) => {
                this.saveSettings();
                if (odcmIsDebug()) { console.log('ODCM: Include debug setting changed to:', value); }
            });

            // Watch detail pane expansion changes for immediate persistence
            this.$watch('detailPaneExpanded', (expanded) => {
                this.saveSettings();
                if (odcmIsDebug()) { console.log('ODCM: Detail pane expansion changed to:', expanded); }
            });
        },

        // =================================================================
        // SETTINGS HELPERS (Accordion, Timestamp, Per-Page, Debug, Retention, Reprocess)
        // =================================================================
        toggleSettingsSection(section) {
            try {
                if (odcmIsDebug()) {
                    console.log('ODCM: toggleSettingsSection called with section:', section);
                    console.log('ODCM: Current settingsAccordionState:', this.settingsAccordionState);
                }

                if (!this.settingsAccordionState || typeof this.settingsAccordionState[section] === 'undefined') {
                    if (odcmIsDebug()) {
                        console.warn('ODCM: Section not found in settingsAccordionState:', section);
                    }
                    return;
                }

                this.settingsAccordionState[section] = !this.settingsAccordionState[section];

                if (odcmIsDebug()) {
                    console.log('ODCM: After toggle, settingsAccordionState:', this.settingsAccordionState);
                }

                // Persist accordion state
                try {
                    localStorage.setItem('odcm_settings_accordion_state', JSON.stringify(this.settingsAccordionState));
                } catch (e) {}
            } catch (e) {
                if (odcmIsDebug()) { console.warn('ODCM: toggleSettingsSection failed:', e); }
            }
        },
        toggleTimestampMode() {
            const order = ['dateTime', 'timeOnly', 'relative'];
            const idx = Math.max(0, order.indexOf(this.timestampDisplayMode));
            const next = order[(idx + 1) % order.length];
            this.timestampDisplayMode = next;
            this.saveSettings();
        },
        async updatePerPageSetting() {
            try {
                const per = Math.max(10, Math.min(200, parseInt(this.perPage) || 20));
                this.perPage = per;
                const resp = await fetch(`${this.config.ajaxUrl}?action=odcm_update_per_page`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ _wpnonce: this.config.nonce, per_page: String(per) })
                });
                const data = await resp.json().catch(() => null);
                if (resp.ok && data && data.success) {
                    this.showToast(data.data?.message || 'Per page updated', 'success');
                    // Refetch logs from first page to reflect change
                    this.currentPage = 1;
                    await this.fetchLogs();
                } else {
                    const msg = (data && (data.data?.message || data.message)) || `HTTP ${resp.status}`;
                    this.showToast(msg || 'Failed to update setting', 'error');
                }
            } catch (e) {
                this.showToast('Failed to update setting', 'error');
            }
        },
        async reprocessPendingOrders() {
            if (this.isReprocessing) return;
            this.isReprocessing = true;
            try {
                const resp = await fetch(`${this.config.ajaxUrl}?action=odcm_reprocess_pending_orders`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ _wpnonce: this.config.nonce })
                });
                const data = await resp.json().catch(() => null);
                if (resp.ok && data && data.success) {
                    const count = (data.data && data.data.count) || 0;
                    const msg = (data.data && data.data.message) || `Scheduled ${count} orders for reprocessing.`;
                    this.showToast(msg, 'success');
                } else {
                    const msg = (data && (data.data?.message || data.message)) || `HTTP ${resp.status}`;
                    this.showToast(msg || 'Failed to reprocess orders', 'error');
                }
            } catch (e) {
                this.showToast('Failed to reprocess orders', 'error');
            } finally {
                this.isReprocessing = false;
            }
        },
        async saveDebugSetting(key, checked) {
            try {
                const payload = new URLSearchParams({ _wpnonce: this.config.nonce });
                payload.append(key, checked ? '1' : '0');
                const resp = await fetch(`${this.config.ajaxUrl}?action=odcm_save_debug_settings`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload
                });
                const data = await resp.json().catch(() => null);
                if (resp.ok && data && data.success) {
                    this.showToast(data.data?.message || 'Debug setting saved', 'success');
                } else {
                    const msg = (data && (data.data?.message || data.message)) || `HTTP ${resp.status}`;
                    this.showToast(msg || 'Failed to save debug setting', 'error');
                }
            } catch (e) {
                this.showToast('Failed to save debug setting', 'error');
            }
        },
        async saveRetentionSetting() {
            try {
                const selected = (document.querySelector('input[name="odcm_log_retention_days"]:checked') || {}).value || '0';
                const daysInput = document.querySelector('input[name="odcm_custom_retention_days"]');
                const customDays = daysInput ? Math.max(1, Math.min(365, parseInt(daysInput.value) || 30)) : 30;
                const payload = new URLSearchParams({ _wpnonce: this.config.nonce });
                payload.append('odcm_log_retention_days', selected);
                if (selected === 'custom') {
                    payload.append('odcm_custom_retention_days', String(customDays));
                }
                const resp = await fetch(`${this.config.ajaxUrl}?action=odcm_save_retention_policy`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload
                });
                const data = await resp.json().catch(() => null);
                if (resp.ok && data && data.success) {
                    this.showToast(data.data?.message || 'Retention policy updated', 'success');
                    this.serverRetentionState = { mode: selected === '0' ? 'forever' : 'custom', days: selected === '0' ? 0 : customDays };
                } else {
                    const msg = (data && (data.data?.message || data.message)) || `HTTP ${resp.status}`;
                    this.showToast(msg || 'Failed to update retention policy', 'error');
                }
            } catch (e) {
                this.showToast('Failed to update retention policy', 'error');
            }
        },
        initializeServerRetentionState() {
            try {
                const selected = (document.querySelector('input[name="odcm_log_retention_days"]:checked') || {}).value || '0';
                const daysInput = document.querySelector('input[name="odcm_custom_retention_days"]');
                const customDays = daysInput ? Math.max(1, Math.min(365, parseInt(daysInput.value) || 30)) : 30;
                this.serverRetentionState = { mode: selected === '0' ? 'forever' : 'custom', days: selected === '0' ? 0 : customDays };
                if (odcmIsDebug()) { console.log('ODCM: Initialized serverRetentionState:', this.serverRetentionState); }
            } catch (e) {
                if (odcmIsDebug()) { console.warn('ODCM: initializeServerRetentionState failed:', e); }
                this.serverRetentionState = { mode: 'unknown', days: null };
            }
        },

        // =================================================================
        // AUTO-REFRESH
        // =================================================================

        startAutoRefresh() {
            this.stopAutoRefresh(); // Clear any existing timer

            if (!this.autoRefreshEnabled) return;

            const intervalMs = this.refreshInterval * 1000;
            this.autoRefreshTimer = setInterval(() => {
                this.fetchNewLogs();
            }, intervalMs);

            if (odcmIsDebug()) { console.log(`ODCM: Auto-refresh started with ${this.refreshInterval}s interval`); }
        },

        stopAutoRefresh() {
            if (this.autoRefreshTimer) {
                clearInterval(this.autoRefreshTimer);
                this.autoRefreshTimer = null;
                if (odcmIsDebug()) { console.log('ODCM: Auto-refresh stopped'); }
            }
        },

        async manualRefresh() {
            // Trigger immediate refresh regardless of auto-refresh state
            this.isRefreshing = true;

            try {
                await this.fetchLogs();
            } finally {
                this.isRefreshing = false;
            }
        },

        async fetchNewLogs() {
            if (this.isRefreshing || this.loading || !this.lastFetchTime) return;

            this.isRefreshing = true;

            try {
                const params = new URLSearchParams({
                    page: this.currentPage,
                    per_page: this.perPage,
                    since: this.lastFetchTime,
                    view: this.viewMode || 'consolidated',
                    ...this.getActiveFilters()
                });

                const response = await fetch(`${this.config.apiUrl}?${params}`, {
                    headers: {
                        'X-WP-Nonce': this.config.nonce
                    }
                });

                if (response.ok) {
                    const data = await response.json();

                    // Check if we have new logs
                    if (data.logs && data.logs.length > 0) {
                        if (odcmIsDebug()) { console.log(`ODCM: Auto-refresh found ${data.logs.length} new logs`); }

                        // If this is the first logs in a welcome scenario, exit welcome mode
                        if (this.isWelcomeScenario && this.logs.length === 0) {
                            this.isWelcomeScenario = false;
                            if (odcmIsDebug()) { console.log('ODCM: Exiting welcome scenario - first logs received'); }
                        }

                        // Merge new logs with proper deduplication
                        this.mergeLogs(data.logs);

                        // Update pagination if total changed
                        if (data.pagination?.total !== this.total) {
                            this.total = data.pagination.total;
                            this.totalPages = data.pagination.total_pages;
                            if (odcmIsDebug()) { console.log(`ODCM: Updated pagination - Total: ${this.total}, Pages: ${this.totalPages}`); }
                        }
                    } else {
                        // No new logs, but still ensure proper ordering in flat view
                        if (this.viewMode === 'flat' && this.logs.length > 0) {
                            if (odcmIsDebug()) {
                                console.log('ODCM: No new logs from auto-refresh, but ensuring proper ordering');
                            }
                            this.ensureChronologicalOrder();
                        }
                    }

                    this.lastFetchTime = new Date().toISOString();
                } else if (response.status === 404) {
                    // Handle 404 gracefully - API might not be available yet
                    // Keep auto-refresh running for welcome scenario
                    if (odcmIsDebug() && this.isWelcomeScenario) {
                        console.log('ODCM: Auto-refresh 404 in welcome scenario - API not ready yet');
                    }
                } else {
                    // Only log non-404 errors to reduce console noise
                    console.warn(`ODCM: Auto-refresh API error: ${response.status} ${response.statusText}`);
                }
            } catch (error) {
                // Only log network errors if debug mode
                if (odcmIsDebug()) {
                    console.warn('ODCM: Auto-refresh failed:', error);
                }
                // Don't show toast for auto-refresh failures to avoid spam
            } finally {
                this.isRefreshing = false;
            }
        },

        // =================================================================
        // LOG DEDUPLICATION AND MERGING
        // =================================================================

        mergeLogs(newLogs) {
            if (!newLogs || newLogs.length === 0) return;

            // Validate incoming logs before processing
            const validNewLogs = newLogs.filter((log, index) => {
                const isValid = log &&
                               typeof log === 'object' &&
                               log.id !== undefined &&
                               log.id !== null &&
                               typeof log.summary === 'string';

                // Debug invalid entries in auto-refresh
                if (!isValid && odcmIsDebug()) {
                    console.warn(`ODCM: Invalid log entry in auto-refresh at index ${index}:`, log);
                }

                return isValid;
            });

            // Log validation results for auto-refresh
            if (odcmIsDebug() && newLogs.length !== validNewLogs.length) {
                console.warn(`ODCM: Auto-refresh filtered out ${newLogs.length - validNewLogs.length} invalid log entries`);
            }

            if (validNewLogs.length === 0) return;

            // Create a map of existing logs by ID for fast lookup
            const existingLogsMap = new Map();
            this.logs.forEach((log, index) => {
                existingLogsMap.set(log.id, { log, index });
            });

            // Filter out logs that already exist
            const trulyNewLogs = validNewLogs.filter(newLog => !existingLogsMap.has(newLog.id));

            if (trulyNewLogs.length === 0) {
                if (odcmIsDebug()) { console.log('ODCM: No truly new logs to add (all were duplicates)'); }
                return;
            }

            if (odcmIsDebug()) { console.log(`ODCM: Adding ${trulyNewLogs.length} truly new logs (filtered ${newLogs.length - trulyNewLogs.length} duplicates)`); }

            // Mark new logs with animation flag and unique timestamp
            const logsWithAnimation = trulyNewLogs.map(log => ({
                ...log,
                isNew: true,
                animationId: `new_${Date.now()}_${Math.random()}`
            }));

            // Prepend truly new logs to the beginning
            this.logs = [...logsWithAnimation, ...this.logs];

            // Only sort in flat view mode to maintain chronological order
            // In consolidated view, the API handles the ordering
            if (this.viewMode === 'flat') {
                if (odcmIsDebug()) {
                    console.log('ODCM: Sorting logs chronologically in flat view mode');
                    console.log('ODCM: Before sort - first few timestamps:', this.logs.slice(0, 5).map(l => l.timestamp));
                }

                // Sort all logs by timestamp in descending order (newest first)
                // Using a stable sort with proper comparison
                this.logs.sort((a, b) => {
                    const result = this.compareLogTimestamps(a, b);
                    // For descending order (newest first), we want newer items to come first
                    // So if a is newer than b, it should come first (return -1)
                    // If a is older than b, it should come after (return 1)
                    return result;
                });

                if (odcmIsDebug()) {
                    console.log('ODCM: After sort - first few timestamps:', this.logs.slice(0, 5).map(l => l.timestamp));
                }
            }

            // Schedule individual animation cleanup (more efficient than bulk update)
            logsWithAnimation.forEach(log => {
                setTimeout(() => {
                    this.removeNewFlag(log.animationId);
                }, 600);
            });
        },

        /**
         * Compare two log timestamps for sorting
         * Handles various timestamp formats and ensures consistent chronological ordering
         *
         * @param {Object} logA - First log entry
         * @param {Object} logB - Second log entry
         * @return {number} Comparison result (-1, 0, 1)
         */
        compareLogTimestamps(logA, logB) {
            try {
                // Handle missing timestamps
                if (!logA.timestamp && !logB.timestamp) return 0;
                if (!logA.timestamp) return 1; // A should come after B if A has no timestamp
                if (!logB.timestamp) return -1; // A should come before B if B has no timestamp

                // Convert timestamps to Date objects for reliable comparison
                const dateA = new Date(logA.timestamp);
                const dateB = new Date(logB.timestamp);

                // Handle invalid dates
                if (isNaN(dateA.getTime()) && isNaN(dateB.getTime())) return 0;
                if (isNaN(dateA.getTime())) return 1;
                if (isNaN(dateB.getTime())) return -1;

                // Compare timestamps for DESCENDING order (newest first)
                // If dateA is newer than dateB, A should come BEFORE B (return -1)
                // If dateA is older than dateB, A should come AFTER B (return 1)
                if (dateA.getTime() > dateB.getTime()) return -1; // A is newer, comes first
                if (dateA.getTime() < dateB.getTime()) return 1;  // A is older, comes later
                return 0; // Same timestamp

            } catch (error) {
                if (odcmIsDebug()) {
                    console.warn('ODCM: Error comparing timestamps, falling back to ID comparison:', error);
                    console.log('ODCM: Problematic logs:', { logA, logB });
                }
                // Fallback: compare by ID to ensure stable sorting (descending)
                return logB.id - logA.id;
            }
        },

        removeNewFlag(animationId) {
            // Find and update only the specific log with this animation ID
            const logIndex = this.logs.findIndex(log => log.animationId === animationId);
            if (logIndex !== -1) {
                // Create a new log object without the animation flags
                const updatedLog = { ...this.logs[logIndex] };
                delete updatedLog.isNew;
                delete updatedLog.animationId;

                // Update only this specific log (more efficient than full array update)
                this.logs[logIndex] = updatedLog;
            }
        },

        /**
         * Ensure logs are in proper chronological order
         * This is called when no new logs are received but we still want to verify ordering
         */
        ensureChronologicalOrder() {
            if (this.viewMode !== 'flat' || this.logs.length <= 1) {
                return; // Only needed for flat view with multiple logs
            }

            if (odcmIsDebug()) {
                console.log('ODCM: Ensuring chronological order for existing logs');
                console.log('ODCM: Current order - first few timestamps:', this.logs.slice(0, 5).map(l => l.timestamp));
            }

            // Check if logs are already in correct order
            let needsSorting = false;
            for (let i = 0; i < this.logs.length - 1; i++) {
                const currentDate = new Date(this.logs[i].timestamp);
                const nextDate = new Date(this.logs[i + 1].timestamp);

                if (isNaN(currentDate.getTime()) || isNaN(nextDate.getTime())) {
                    continue; // Skip invalid dates
                }

                if (currentDate < nextDate) {
                    needsSorting = true;
                    if (odcmIsDebug()) {
                        console.log(`ODCM: Found out-of-order logs at index ${i}: ${this.logs[i].timestamp} < ${this.logs[i + 1].timestamp}`);
                    }
                    break;
                }
            }

            if (needsSorting) {
                if (odcmIsDebug()) {
                    console.log('ODCM: Logs need reordering, applying sort...');
                }

                // Sort all logs by timestamp in descending order (newest first)
                this.logs.sort((a, b) => this.compareLogTimestamps(b, a));

                if (odcmIsDebug()) {
                    console.log('ODCM: After reordering - first few timestamps:', this.logs.slice(0, 5).map(l => l.timestamp));
                }
            } else {
                if (odcmIsDebug()) {
                    console.log('ODCM: Logs are already in correct chronological order');
                }
            }
        },

        // =================================================================
        // FILTER MANAGEMENT
        // =================================================================

        getActiveFilters() {
            const activeFilters = {};

            // Basic search (always available)
            if (this.filters.search) {
                activeFilters.s = this.filters.search;
            }

            // Premium filters (only if user has access)
            if (this.canUsePremiumFilters) {
                if (this.filters.status) activeFilters.status = this.filters.status;
                if (this.filters.event_type) activeFilters.event_type = this.filters.event_type;
                if (this.filters.source) activeFilters.source = this.filters.source;
                if (this.filters.order_id) activeFilters.order_id = this.filters.order_id;
                if (this.filters.date_start) activeFilters.date_start = this.filters.date_start;
                if (this.filters.date_end) activeFilters.date_end = this.filters.date_end;
            }

            // Include tests (always available)
            if (this.filters.include_tests === true) {
                activeFilters.include_tests = '1';
            }

            // Include debug logs (always available)
            if (this.filters.include_debug === true) {
                activeFilters.include_debug = '1';
            }

            return activeFilters;
        },
        // =================================================================
        // VIEW MODE
        // =================================================================
        async setViewMode(mode) {
            if (mode !== 'consolidated' && mode !== 'flat') return;
            if (this.viewMode === mode) return;

            // Store the currently selected log before changing view mode
            const previouslySelectedLog = this.selectedLog;

            this.viewMode = mode;
            try { localStorage.setItem('odcm_view_mode', mode); } catch (e) {}
            this.currentPage = 1;

            // Refresh the log list
            await this.fetchLogs();

            // If there was a selected log before the view mode change, re-select it
            // to ensure the timeline is refreshed with the new view mode
            if (previouslySelectedLog) {
                const logStillExists = this.logs.find(log => log.id === previouslySelectedLog.id);
                if (logStillExists) {
                    await this.selectLog(logStillExists);
                }
            }
        },

        // =================================================================
        // UI AND LAYOUT HELPERS
        // =================================================================
        get dashboardClasses() {
            const classes = [];
            if (this.filterPaneVisible) classes.push('filter-pane-visible');
            if (this.selectedLog) classes.push('details-pane-visible');
            if (this.detailPaneExpanded) classes.push('detail-pane-expanded');
            return classes.join(' ');
        },

        // Returns true when any filter is active (including search and toggles)
        get hasActiveFilters() {
            try {
                const f = this.filters || {};
                // Basic search
                if (typeof f.search === 'string' && f.search.trim() !== '') return true;
                // Premium filters if available
                if (this.canUsePremiumFilters) {
                    if (f.status) return true;
                    if (f.event_type) return true;
                    if (f.source) return true;
                    if (f.order_id) return true;
                    if (f.date_start) return true;
                    if (f.date_end) return true;
                }
                // Toggles available for all
                if (f.include_tests === true) return true;
                if (f.include_debug === true) return true;
                return false;
            } catch (e) {
                return false;
            }
        },
        closeFilterPane() {
            this.filterPaneVisible = false;
        },
        openLastOpenedPane() {
            this.filterPaneVisible = true;
            this.activeFilterTab = this.lastOpenedTab || 'filters';
        },
        showFiltersPane() {
            this.activeFilterTab = 'filters';
            this.lastOpenedTab = 'filters';
            this.filterPaneVisible = true;
        },
        showSettingsPane() {
            this.activeFilterTab = 'settings';
            this.lastOpenedTab = 'settings';
            this.filterPaneVisible = true;
        },
        toggleDetailPaneExpansion() {
            this.detailPaneExpanded = !this.detailPaneExpanded;
        },
        closeDetails() {
            this.selectedLog = null;
            this.detailHtml = '';
            this.detailPaneExpanded = false;
        },

        // =================================================================
        // DEBOUNCED FETCH
        // =================================================================
        setupDebouncedFetch() {
            let timer = null;
            this.debouncedFetchLogs = () => {
                if (timer) clearTimeout(timer);
                timer = setTimeout(() => this.fetchLogs(), 300);
            };
        },

        // =================================================================
        // FILTER APPLICATION
        // =================================================================
        applyFilters() {
            this.currentPage = 1;
            this.fetchLogs();
        },

        clearFilters() {
            this.filters = {
                search: '',
                status: '',
                event_type: '',
                source: '',
                order_id: '',
                date_start: '',
                date_end: '',
                include_tests: false,
                include_debug: false
            };
            this.currentPage = 1;
            this.fetchLogs();
        },

        // =================================================================
        // TOAST HELPERS
        // =================================================================
        showToast(message, type = 'info') {
            try {
                // Debug toast system availability
                if (odcmIsDebug()) {
                    console.log('ODCM: showToast called:', { message, type });
                    console.log('ODCM: Toast system check:', {
                        windowExists: typeof window !== 'undefined',
                        ODCMToastsExists: !!window.ODCMToasts,
                        addToastExists: window.ODCMToasts && typeof window.ODCMToasts.addToast === 'function'
                    });
                }

                if (typeof window !== 'undefined' && window.ODCMToasts && typeof window.ODCMToasts.addToast === 'function') {
                    window.ODCMToasts.addToast(message, type);
                    if (odcmIsDebug()) {
                        console.log('ODCM: Toast added via ODCMToasts system');
                    }
                } else {
                    // Enhanced fallback: try to create a simple toast notification
                    if (odcmIsDebug()) {
                        console.log('ODCM: ODCMToasts not available, using fallback');
                    }

                    this.createFallbackToast(message, type);

                    // Also log to console as backup
                    if (type === 'error') {
                        console.error('ODCM Toast (Error):', message);
                    } else {
                        console.log('ODCM Toast (' + type + '):', message);
                    }
                }
            } catch (e) {
                console.error('ODCM: Toast system error:', e);
                if (type === 'error') {
                    console.error('ODCM Toast (Fallback Error):', message);
                } else if (odcmIsDebug()) {
                    console.log('ODCM Toast (Fallback):', message);
                }
            }
        },

        createFallbackToast(message, type = 'info') {
            try {
                // Create a simple toast notification as fallback
                const toastContainer = document.getElementById('odcm-toast-container') || this.createToastContainer();

                const toast = document.createElement('div');
                toast.className = `odcm-fallback-toast odcm-toast-${type}`;
                toast.style.cssText = `
                    background: ${type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#28a745'};
                    color: white;
                    padding: 12px 16px;
                    margin: 8px 0;
                    border-radius: 4px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                    animation: slideInRight 0.3s ease;
                    cursor: pointer;
                    position: relative;
                    z-index: 10000;
                `;
                toast.textContent = message;

                // Auto-remove after 4 seconds
                const timeoutId = setTimeout(() => {
                    if (toast.parentNode) {
                        toast.style.animation = 'slideOutRight 0.3s ease';
                        setTimeout(() => {
                            if (toast.parentNode) {
                                toast.parentNode.removeChild(toast);
                            }
                        }, 300);
                    }
                }, 4000);

                // Allow manual dismissal
                toast.addEventListener('click', () => {
                    clearTimeout(timeoutId);
                    if (toast.parentNode) {
                        toast.style.animation = 'slideOutRight 0.3s ease';
                        setTimeout(() => {
                            if (toast.parentNode) {
                                toast.parentNode.removeChild(toast);
                            }
                        }, 300);
                    }
                });

                toastContainer.appendChild(toast);

                if (odcmIsDebug()) {
                    console.log('ODCM: Fallback toast created');
                }

            } catch (e) {
                console.error('ODCM: Fallback toast creation failed:', e);
            }
        },

        createToastContainer() {
            try {
                const container = document.createElement('div');
                container.id = 'odcm-toast-container';
                container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 10001;
                    max-width: 400px;
                    pointer-events: none;
                `;

                // Add CSS animations
                const style = document.createElement('style');
                style.textContent = `
                    @keyframes slideInRight {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes slideOutRight {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                    .odcm-fallback-toast { pointer-events: auto; }
                `;

                if (!document.head.querySelector('#odcm-toast-animations')) {
                    style.id = 'odcm-toast-animations';
                    document.head.appendChild(style);
                }

                document.body.appendChild(container);
                return container;
            } catch (e) {
                console.error('ODCM: Toast container creation failed:', e);
                return null;
            }
        },
        removeToast(id) {
            if (typeof window !== 'undefined' && window.ODCMToasts && typeof window.ODCMToasts.removeToast === 'function') {
                window.ODCMToasts.removeToast(id);
            }
        },

        // =================================================================
        // LOG EXPORT (PREMIUM FEATURE)
        // =================================================================
        exportLogs(format) {
            try {
                // Prevent multiple simultaneous exports
                if (this.isExporting) {
                    if (odcmIsDebug()) {
                        console.log('ODCM: Export already in progress');
                    }
                    return;
                }

                // Validate format
                if (format !== 'csv' && format !== 'json') {
                    this.showToast('Invalid export format', 'error');
                    return;
                }

                // Set exporting state
                this.isExporting = true;
                this.exportFormat = format;

                if (odcmIsDebug()) {
                    console.log('ODCM: Starting export via admin-post.php:', {
                        format: format,
                        adminPostUrl: this.config.adminPostUrl,
                        nonce: this.config.nonce ? 'present' : 'missing',
                        filters: this.getActiveFilters()
                    });
                }

                // Create a hidden form to submit the export request with current filters
                // Use admin-post.php (WordPress way) for clean file downloads without admin UI
                const form = document.createElement('form');
                form.method = 'POST';

                // Use configured URL or fail gracefully
                if (!this.config.adminPostUrl) {
                    console.error('ODCM: Admin Post URL not configured');
                    this.showToast('Export configuration missing. Please refresh the page.', 'error');
                    this.isExporting = false;
                    return;
                }
                form.action = this.config.adminPostUrl;

                form.target = '_self'; // Ensure it doesn't open in new window
                form.style.display = 'none';

                // Add action parameter
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = format === 'csv' ? 'odcm_export_logs_csv' : 'odcm_export_logs_json';
                form.appendChild(actionInput);

                // Add nonce
                const nonceInput = document.createElement('input');
                nonceInput.type = 'hidden';
                nonceInput.name = '_wpnonce';
                nonceInput.value = this.config.nonce;
                form.appendChild(nonceInput);

                // Add current filter parameters so export respects active filters
                const activeFilters = this.getActiveFilters();
                Object.keys(activeFilters).forEach(key => {
                    const filterInput = document.createElement('input');
                    filterInput.type = 'hidden';
                    filterInput.name = key;
                    filterInput.value = activeFilters[key];
                    form.appendChild(filterInput);
                });

                // Add sorting parameters
                const orderbyInput = document.createElement('input');
                orderbyInput.type = 'hidden';
                orderbyInput.name = 'orderby';
                orderbyInput.value = 'timestamp'; // Default sorting
                form.appendChild(orderbyInput);

                const orderInput = document.createElement('input');
                orderInput.type = 'hidden';
                orderInput.name = 'order';
                orderInput.value = 'DESC'; // Default order
                form.appendChild(orderInput);

                if (odcmIsDebug()) {
                    console.log('ODCM: Form created with fields:', {
                        action: actionInput.value,
                        nonce: nonceInput.value ? 'present' : 'missing',
                        filterCount: Object.keys(activeFilters).length
                    });
                }

                // Append form to body and submit
                document.body.appendChild(form);

                if (odcmIsDebug()) {
                    console.log('ODCM: Submitting form to:', form.action);
                }

                form.submit();

                // Show info toast
                this.showToast(
                    format === 'csv'
                        ? 'Preparing CSV download...'
                        : 'Preparing JSON download...',
                    'info'
                );

                // Reset button state immediately - the form submission is complete
                // The browser handles the download independently
                this.$nextTick(() => {
                    this.isExporting = false;
                    this.exportFormat = null;

                    if (odcmIsDebug()) {
                        console.log('ODCM: Export initiated, button state reset');
                    }
                });

                // Clean up form after a brief delay
                setTimeout(() => {
                    try {
                        if (form.parentNode) {
                            document.body.removeChild(form);
                        }
                    } catch (e) {
                        if (odcmIsDebug()) {
                            console.warn('ODCM: Error removing form:', e);
                        }
                    }
                }, 100);

            } catch (error) {
                console.error('ODCM: Error during export:', error);
                this.showToast('Failed to initiate export', 'error');
                this.isExporting = false;
                this.exportFormat = null;
            }
        },

        // =================================================================
        // SELECTION MANAGEMENT
        // =================================================================
        toggleSelectAll() {
            if (this.selectAll) {
                this.selectedLogIds = this.logs.map(l => l.id);
            } else {
                this.selectedLogIds = [];
            }
        },
        toggleLogSelection(id) {
            const i = this.selectedLogIds.indexOf(id);
            if (i === -1) this.selectedLogIds.push(id); else this.selectedLogIds.splice(i, 1);
            this.selectAll = this.selectedLogIds.length > 0 && this.selectedLogIds.length === this.logs.length;
        },
        isLogSelected(id) {
            return this.selectedLogIds.includes(id);
        },
        get hasSelection() {
            return this.selectedLogIds.length > 0;
        },
        get selectedCount() {
            return this.selectedLogIds.length;
        },
        async deleteSelectedLogs() {
            if (!this.selectedLogIds.length) return;

            // Store the count and create copy for processing
            const selectedCount = this.selectedLogIds.length;
            const selectedIds = [...this.selectedLogIds];

            // Clear selection immediately to prevent double-clicks
            this.selectedLogIds = [];
            this.selectAll = false;

            this.isDeleting = true;

            // EXPAND CONSOLIDATED ENTRIES TO THEIR CONSTITUENT LOG IDS
            const expandedIds = [];
            let consolidatedEntriesExpanded = 0;

            for (const selectedId of selectedIds) {
                // Find the log entry in this.logs
                const log = this.logs.find(l => l.id === selectedId);

                if (log && log.constituent_log_ids && Array.isArray(log.constituent_log_ids) && log.constituent_log_ids.length > 0) {
                    // This is a consolidated entry - add all constituent IDs
                    expandedIds.push(...log.constituent_log_ids);
                    consolidatedEntriesExpanded++;
                    console.log(`ODCM: Expanded consolidated entry ${selectedId} to ${log.constituent_log_ids.length} constituent IDs:`, log.constituent_log_ids);
                } else {
                    // Regular individual entry - add its ID
                    expandedIds.push(selectedId);
                }
            }

            // Remove duplicates (in case of overlapping selections)
            const uniqueIds = [...new Set(expandedIds)];

            console.log('ODCM: Starting batch delete:', {
                selectedCount,
                originalIds: selectedIds.length,
                expandedIds: expandedIds.length,
                uniqueIds: uniqueIds.length,
                consolidatedEntriesExpanded,
                apiUrl: this.config.apiUrl,
                nonce: this.config.nonce ? 'present' : 'missing'
            });

            // Split into chunks of 100 for server compatibility
            const CHUNK_SIZE = 100;
            const chunks = [];
            for (let i = 0; i < uniqueIds.length; i += CHUNK_SIZE) {
                chunks.push(uniqueIds.slice(i, i + CHUNK_SIZE));
            }

            console.log(`ODCM: Processing ${chunks.length} chunks of max ${CHUNK_SIZE} items each`);

            let totalDeleted = 0;
            let totalFailed = 0;
            let failedChunks = [];

            try {
                // Process chunks sequentially for stability
                for (let chunkIndex = 0; chunkIndex < chunks.length; chunkIndex++) {
                    const chunk = chunks[chunkIndex];
                    const chunkNumber = chunkIndex + 1;

                    console.log(`ODCM: Processing chunk ${chunkNumber}/${chunks.length} (${chunk.length} items)`);

                    try {
                        const requestUrl = `${this.config.apiUrl}batch-delete/`;
                        const response = await fetch(requestUrl, {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce
                            },
                            body: JSON.stringify({
                                log_ids: chunk
                            })
                        });

                        const responseText = await response.text();

                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${responseText}`);
                        }

                        const data = JSON.parse(responseText);

                        if (data && data.success) {
                            const deletedCount = data.deleted_count || chunk.length;
                            totalDeleted += deletedCount;

                            // Remove deleted items from UI immediately
                            const toDelete = new Set(chunk);
                            this.logs = this.logs.filter(l => !toDelete.has(l.id));

                            // Show progress toast for each batch
                            const progressMessage = chunks.length > 1
                                ? `Batch ${chunkNumber}/${chunks.length} completed (${deletedCount} items deleted)`
                                : `Successfully deleted ${deletedCount} log entries`;

                            this.showToast(progressMessage, 'success');

                            console.log(`ODCM: Chunk ${chunkNumber} completed successfully:`, {
                                deleted_count: deletedCount,
                                chunk_size: chunk.length
                            });
                        } else {
                            throw new Error(data.message || 'Unexpected response format');
                        }

                    } catch (chunkError) {
                        console.error(`ODCM: Chunk ${chunkNumber} failed:`, chunkError);
                        totalFailed += chunk.length;
                        failedChunks.push({ chunk: chunkNumber, size: chunk.length, error: chunkError.message });

                        // Show error toast for failed chunk
                        this.showToast(`Batch ${chunkNumber} failed: ${chunkError.message}`, 'error');
                    }
                }

                // Show final summary
                if (chunks.length > 1) {
                    if (totalDeleted > 0 && totalFailed === 0) {
                        this.showToast(`All batches completed! Successfully deleted ${totalDeleted} log entries`, 'success');
                    } else if (totalDeleted > 0 && totalFailed > 0) {
                        this.showToast(`Partially completed: ${totalDeleted} deleted, ${totalFailed} failed`, 'warning');
                    } else if (totalFailed > 0) {
                        this.showToast(`All batches failed: ${totalFailed} entries could not be deleted`, 'error');
                    }
                }

                console.log('ODCM: Batch delete process completed:', {
                    total_selected: selectedCount,
                    total_expanded: uniqueIds.length,
                    total_deleted: totalDeleted,
                    total_failed: totalFailed,
                    chunks_processed: chunks.length,
                    failed_chunks: failedChunks.length,
                    consolidated_entries_expanded: consolidatedEntriesExpanded
                });

                // Refresh log stream to show remaining entries and prevent empty state
                if (totalDeleted > 0) {
                    await this.fetchLogs();
                }

            } catch (error) {
                console.error('ODCM: Fatal error in batch delete process:', error);
                this.showToast('Fatal error occurred during batch deletion', 'error');
            } finally {
                this.isDeleting = false;
            }
        },

        // =================================================================
        // PAGINATION AND TIMESTAMP
        // =================================================================
        get paginationText() {
            const total = this.total || 0;
            const per = this.perPage || 20;
            const page = this.currentPage || 1;
            const from = total === 0 ? 0 : ((page - 1) * per) + 1;
            const to = Math.min(page * per, total);
            return `${from}–${to} of ${total}`;
        },
        goToPage(page) {
            const p = Math.max(1, Math.min(this.totalPages || 1, parseInt(page) || 1));
            if (p === this.currentPage) return;
            this.currentPage = p;
            this.fetchLogs();
        },
        formatTimestamp(ts) {
            try {
                const cfg = this.config.dateTimeConfig || {};
                const d = new Date(ts);
                const mode = this.timestampDisplayMode || 'dateTime';
                if (mode === 'timeOnly') {
                    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
                }
                if (mode === 'relative') {
                    const diff = (Date.now() - d.getTime()) / 1000;
                    if (diff < 60) return `${Math.floor(diff)}s ago`;
                    if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
                    if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
                    return `${Math.floor(diff/86400)}d ago`;
                }
                // date & time
                return d.toLocaleString(undefined, {
                    year: 'numeric', month: 'short', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                });
            } catch (e) {
                return String(ts || '');
            }
        },

        // =================================================================
        // LOG ENTRY CLASSIFICATION AND STYLING
        // =================================================================

        /**
         * Get CSS classes for a log entry based on its properties
         */
        getLogEntryClasses(log) {
            const classes = ['odcm-log-entry'];

            // Add consolidated entry class
            if (this.isConsolidatedEntry(log)) {
                classes.push('is-consolidated');
            }

            // Add process representative class
            if (log.is_process_representative) {
                classes.push('is-process-representative');
            }

            // Add selected state
            if (this.selectedLog && this.selectedLog.id === log.id) {
                classes.push('is-selected');
            }

            // Add checkbox selected state
            if (this.isLogSelected(log.id)) {
                classes.push('is-checkbox-selected');
            }

            return classes.join(' ');
        },

        /**
         * Check if a log entry is consolidated (has multiple events)
         */
        isConsolidatedEntry(log) {
            // In flat view, nothing should be treated as consolidated
            if (this.viewMode === 'flat') {
                return false;
            }

            return log.is_process_representative === true ||
                   (log.consolidation_data && log.consolidation_data.is_consolidated === true) ||
                   (log.process_event_count && log.process_event_count > 1);
        },

        // =================================================================
        // THREE-TIER EXPAND/COLLAPSE FUNCTIONALITY
        // =================================================================

        /**
         * Initialize three-tier expand/collapse system for timeline components
         */
        initThreeTierToggles() {
            // Remove any existing handlers to prevent duplicates
            document.removeEventListener('click', this.handleTierToggleClick);
            document.removeEventListener('keydown', this.handleTierToggleKeydown);

            // Add event listeners for three-tier toggles
            document.addEventListener('click', this.handleTierToggleClick.bind(this));
            document.addEventListener('keydown', this.handleTierToggleKeydown.bind(this));

            if (odcmIsDebug()) {
                console.log('ODCM: Three-tier toggle handlers initialized');
            }
        },

        /**
         * Handle tier toggle button clicks
         */
        handleTierToggleClick(event) {
            // Check if the clicked element is a tier toggle button
            if (!event.target.classList.contains('odcm-tier-toggle')) {
                return;
            }

            event.preventDefault();
            this.toggleTier(event.target);
        },

        /**
         * Handle keyboard navigation for tier toggles
         */
        handleTierToggleKeydown(event) {
            if (!event.target.classList.contains('odcm-tier-toggle')) {
                return;
            }

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.toggleTier(event.target);
            }
        },

        /**
         * Toggle a specific tier (contextual or technical)
         */
        toggleTier(toggleButton) {
            try {
                const target = toggleButton.dataset.target;
                if (!target) {
                    if (odcmIsDebug()) {
                        console.warn('ODCM: Tier toggle button missing data-target attribute');
                    }
                    return;
                }

                const expandableSection = toggleButton.closest('.odcm-expandable-section');
                if (!expandableSection) {
                    if (odcmIsDebug()) {
                        console.warn('ODCM: Could not find expandable section for tier toggle');
                    }
                    return;
                }

                const tierContent = expandableSection.querySelector('.odcm-tier-content');
                if (!tierContent) {
                    if (odcmIsDebug()) {
                        console.warn('ODCM: Could not find tier content for toggle');
                    }
                    return;
                }

                const component = toggleButton.closest('.odcm-component');
                // Fixed: Check the button's aria-expanded attribute to determine expansion state
                const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';

                if (odcmIsDebug()) {
                    console.log('ODCM: Toggling tier:', {
                        target: target,
                        isExpanded: isExpanded,
                        componentId: component?.id || 'unknown',
                        buttonAriaExpanded: toggleButton.getAttribute('aria-expanded'),
                        sectionAriaExpanded: expandableSection.getAttribute('aria-expanded')
                    });
                }

                if (isExpanded) {
                    // Collapse the tier
                    this.collapseTier(tierContent, toggleButton, target, component);
                } else {
                    // Expand the tier
                    this.expandTier(tierContent, toggleButton, target, component);
                }

            } catch (error) {
                console.error('ODCM: Error toggling tier:', error);
                if (odcmIsDebug()) {
                    console.error('ODCM: Tier toggle error details:', {
                        target: toggleButton.dataset.target,
                        buttonText: toggleButton.textContent,
                        error: error.message
                    });
                }
            }
        },

        /**
         * Expand a tier with smooth animation
         */
        expandTier(tierContent, toggleButton, target, component) {
            // Find the expandable section and update its aria-expanded attribute first
            const expandableSection = toggleButton.closest('.odcm-expandable-section');
            if (expandableSection) {
                expandableSection.setAttribute('aria-expanded', 'true');
            }

            // Update button text based on tier type
            const showText = toggleButton.textContent;
            if (target === 'contextual') {
                toggleButton.textContent = showText.replace('Show', 'Hide');
                toggleButton.setAttribute('aria-expanded', 'true');
            } else if (target === 'technical') {
                toggleButton.textContent = showText.replace('Show', 'Hide');
                toggleButton.setAttribute('aria-expanded', 'true');
            }

            // Add expanded class to component
            if (component) {
                component.classList.add(`${target}-expanded`);
            }

            // Re-highlight code blocks in expanded technical details
            if (target === 'technical') {
                this.highlightCodeBlocks(tierContent);
            }

            if (odcmIsDebug()) {
                console.log(`ODCM: Expanded ${target} tier`);
            }
        },

        /**
         * Collapse a tier with smooth animation
         */
        collapseTier(tierContent, toggleButton, target, component) {
            // Find the expandable section and update its aria-expanded attribute first
            const expandableSection = toggleButton.closest('.odcm-expandable-section');
            if (expandableSection) {
                expandableSection.setAttribute('aria-expanded', 'false');
            }

            // Update button text based on tier type
            const hideText = toggleButton.textContent;
            if (target === 'contextual') {
                toggleButton.textContent = hideText.replace('Hide', 'Show');
                toggleButton.setAttribute('aria-expanded', 'false');
            } else if (target === 'technical') {
                toggleButton.textContent = hideText.replace('Hide', 'Show');
                toggleButton.setAttribute('aria-expanded', 'false');
            }

            // Remove expanded class from component
            if (component) {
                component.classList.remove(`${target}-expanded`);
            }

            // Clear any inline styles that might conflict with CSS
            tierContent.style.display = '';
            tierContent.style.opacity = '';
            tierContent.style.maxHeight = '';
            tierContent.style.overflow = '';
            tierContent.style.transition = '';

            if (odcmIsDebug()) {
                console.log(`ODCM: Collapsed ${target} tier`);
            }
        },

        // =================================================================
        // DETAIL PANE
        // =================================================================
        async selectLog(log) {
            this.selectedLog = log;
            this.detailLoading = true;

            // Check if this is a consolidated/representative entry
            const isConsolidated = this.isConsolidatedEntry(log);

            if (odcmIsDebug()) {
                console.log('ODCM: selectLog called for:', {
                    id: log.id,
                    summary: log.summary,
                    isConsolidated: isConsolidated,
                    isProcessRepresentative: log.is_process_representative,
                    processEventCount: log.process_event_count,
                    viewMode: this.viewMode
                });
            }

            this.detailHtml = await this.fetchLogDetails(log.id, this.viewMode);
            this.detailLoading = false;
            this.$nextTick(() => {
                const detailPane = document.querySelector('.odcm-detail-content');
                if (detailPane) {
                    this.highlightCodeBlocks(detailPane);
                    // Initialize three-tier toggles for newly loaded content
                    this.initThreeTierToggles();
                }
            });
        },

    };
}

// ODCM: Global error boundaries to prevent total UI breakage and surface user-friendly notices
(function setupODCMGlobalErrorHandlers(){
    if (typeof window === 'undefined') return;
    if (window.__odcmGlobalErrorsInstalled) return;
    window.__odcmGlobalErrorsInstalled = true;

    function hasDashboardRoot(){
        try {
            return !!document.getElementById('odcm-insight-dashboard');
        } catch (e) { return false; }
    }
    function toast(msg, type){
        try {
            if (window.ODCMToasts && typeof window.ODCMToasts.addToast === 'function') {
                window.ODCMToasts.addToast(msg, type || 'error');
            } else if (type === 'error') {
                console.error(msg);
            } else if (odcmIsDebug()) {
                console.log(msg);
            }
        } catch (e) { /* noop */ }
    }

    // Enhanced Alpine.js detection and fallback
    function checkAlpineJS() {
        if (typeof window.Alpine === 'undefined') {
            console.error('ODCM: Alpine.js failed to load. Dashboard interactivity will be limited.');
            if (hasDashboardRoot()) {
                const dashboard = document.getElementById('odcm-insight-dashboard');
                dashboard.innerHTML = '<div class="odcm-error-state" style="padding: 20px; text-align: center; border: 1px solid #dc3545; background: #f8d7da; color: #721c24; border-radius: 4px; margin: 20px;"><h3>Dashboard Loading Error</h3><p>The dashboard JavaScript framework failed to load. This is typically caused by Content Security Policy restrictions.</p><p><strong>To fix this:</strong> Please contact your administrator to allow JavaScript from your domain, or check browser console for specific errors.</p></div>';
            }
            return false;
        }
        return true;
    }

    // Check Alpine.js availability after a short delay
    setTimeout(checkAlpineJS, 2000);

    window.addEventListener('error', function(ev){
        if (!ev) return;
        const msg = ev.message || 'Unexpected error';
        if (odcmIsDebug()) {
            console.error('ODCM Global Error:', msg, ev.error || {});
        }
        if (hasDashboardRoot()) {
            toast('An unexpected error occurred in the dashboard. Some features may be limited.', 'error');
        }
    });

    window.addEventListener('unhandledrejection', function(ev){
        const reason = ev && (ev.reason || ev);
        if (odcmIsDebug()) {
            console.error('ODCM Unhandled Promise Rejection:', reason);
        }
        if (hasDashboardRoot()) {
            toast('A background operation failed. The dashboard will continue in a safe state.', 'error');
        }
    });
})();
