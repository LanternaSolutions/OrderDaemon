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

        // Network status monitoring
        networkOnline: true,
        lastNetworkCheck: null,
        networkIssues: [],

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


        // Dynamic filter options and client-side caching (5 minutes)
        filterOptions: {
            sources: [],
            event_types: [],
            statuses: []
        },
        filterOptionsCache: null,
        filterOptionsCacheExpiry: null,

        // Default labels for filter dropdowns (used when repopulating DOM)
        allLabels: {
            statuses: 'All Statuses',
            eventTypes: 'All Event Types',
            sources: 'All Sources'
        },

        // Configuration from PHP
        config: window.odcmInsightConfig || {},
        i18n: window.odcmInsightConfig?.i18n || {},

        // Reprocess pending orders state
        isReprocessing: false,

        // Custom Webhook settings state
        cwEnabled: window.odcmInsightConfig?.customWebhook?.enabled || false,
        cwAuthMethod: window.odcmInsightConfig?.customWebhook?.authMethod || 'none',
        cwSlug: window.odcmInsightConfig?.customWebhook?.slug || 'custom-webhook',
        cwSaving: false,

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

                // Initialize network monitoring
                this.setupNetworkMonitoring();

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
                this.setupPrismWatchers();
                this.setupHeaderBottomMeasurement();

                // Keep selection state consistent with the current logs list
                this.$watch('logs', () => {
                    try {
                        const validIds = new Set((this.logs || []).map(l => l && l.id).filter(Boolean));

                        // Drop selections that no longer exist in the current list
                        this.selectedLogIds = (this.selectedLogIds || []).filter(id => validIds.has(id));

                        // Recompute derived select-all state
                        this.selectAll =
                            this.selectedLogIds.length > 0 &&
                            this.selectedLogIds.length === (this.logs || []).length;
                    } catch (e) {
                        if (odcmIsDebug()) { console.warn('ODCM: selection reconcile failed:', e); }
                    }
                });

                // Keep selectAll in sync when individual checkboxes update selectedLogIds
                this.$watch('selectedLogIds', () => {
                    try {
                        this.selectAll =
                            (this.selectedLogIds || []).length > 0 &&
                            (this.selectedLogIds || []).length === (this.logs || []).length;
                    } catch (e) {
                        if (odcmIsDebug()) { console.warn('ODCM: selectAll sync failed:', e); }
                    }
                });

                // Add click handler for outside clicks
                this.setupOutsideClickHandler();

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
            // Filter changes are handled via @change on dropdowns/date inputs
            // and @input on the search box — no deep watcher needed.
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
            this.$watch('detailHtml', () => {
                this.$nextTick(() => {
                    const detailPane = document.querySelector('.odcm-detail-content');
                    if (detailPane) {
                        this.highlightCodeBlocks(detailPane);
                        this.initTimelineExpanders(detailPane);
                    }
                });
            });
        },

        initTimelineExpanders(container) {
            container.querySelectorAll('.odcm-tl-node__expand').forEach(btn => {
                btn.addEventListener('click', () => {
                    const expanded = btn.getAttribute('aria-expanded') === 'true';
                    btn.setAttribute('aria-expanded', String(!expanded));
                    if (!expanded) {
                        const jsonDiv = btn.nextElementSibling;
                        if (jsonDiv) {
                            this.highlightCodeBlocks(jsonDiv);
                        }
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

                // Handle the new response structure where filter_options contains nested arrays
                // The API now returns: { filter_options: { statuses: [], event_types: [], sources: [] } }
                const filterOptionsData = data.filter_options || data;

                // Extract the arrays from the nested structure
                const statuses = Array.isArray(filterOptionsData.statuses) ? filterOptionsData.statuses : [];
                const eventTypes = Array.isArray(filterOptionsData.event_types) ? filterOptionsData.event_types : [];
                const sources = Array.isArray(filterOptionsData.sources) ? filterOptionsData.sources : [];

                // Store the filter options
                this.filterOptions = {
                    sources: sources,
                    event_types: eventTypes,
                    statuses: statuses
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
                if (odcmIsDebug()) { console.warn('ODCM: Could not fetch filter options.', error); }
                // Graceful degradation: keep hardcoded defaults
                return null;
            }
        },

        async fetchFilterOptionsWithCache() {
            try {
                const now = Date.now();
                if (this.filterOptionsCache && this.filterOptionsCacheExpiry && now < this.filterOptionsCacheExpiry) {
                    if (odcmIsDebug()) { console.log('ODCM: Filter options served from cache.'); }
                    // Apply cached options to DOM in case of re-entry
                    this.filterOptions = this.filterOptionsCache;
                    this.applyFilterOptionsToDOM();
                    return { cached: true, data: this.filterOptionsCache };
                }

                const data = await this.fetchFilterOptions();
                if (data && data.filter_options) {
                    this.filterOptionsCache = this.filterOptions;
                    const ttl = (data.meta && Number.isFinite(data.meta.cache_ttl)) ? data.meta.cache_ttl * 1000 : 300000; // default 5 min
                    this.filterOptionsCacheExpiry = now + ttl;
                    if (odcmIsDebug()) { console.log(`ODCM: Filter options cached for ${Math.round(ttl/1000)}s.`); }
                }
                return { cached: false, data };
            } catch (e) {
                if (odcmIsDebug()) { console.warn('ODCM: Filter options cache fetch failed.', e); }
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

                // Handle the new response structure where filter_options contains nested arrays
            const filterOptions = this.filterOptions.filter_options || this.filterOptions;

            // Extract the arrays from the nested structure
            const statuses = Array.isArray(filterOptions.statuses) ? filterOptions.statuses : [];
            const eventTypes = Array.isArray(filterOptions.event_types) ? filterOptions.event_types : [];
            const sources = Array.isArray(filterOptions.sources) ? filterOptions.sources : [];

            repopulate(statusSelect, statuses, this.allLabels.statuses);
            repopulate(eventTypeSelect, eventTypes, this.allLabels.eventTypes);
            repopulate(sourceSelect, sources, this.allLabels.sources);
        },

        async fetchLogDetails(logId, viewMode = 'consolidated') {
            this.detailLoading = true;
            const maxRetries = 3;
            const baseDelay = 1000; // Start with 1 second delay
            let lastError = null;
            let timeoutId = null; // Declare outside try block for proper scoping in finally

            // Track this request for cleanup
            const requestId = `fetchDetails_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
            if (window.odcmActiveRequests) {
                window.odcmActiveRequests.add(requestId);
            }

            for (let attempt = 1; attempt <= maxRetries; attempt++) {
                // Create abort controller for timeout - declared outside try for proper cleanup
                const controller = new AbortController();
                timeoutId = setTimeout(() => controller.abort(), 15000); // 15 second timeout

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
                                console.log(`ODCM: Server error ${response.status}, retrying in ${delay}ms (attempt ${attempt}/${maxRetries}).`);
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
                            console.warn(`ODCM: Retry ${attempt + 1}/${maxRetries} for log ${logId} after ${delay}ms. Error: ${error.message}.`);
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

                // Load filter pane visibility state (default: true on desktop, always false on mobile)
                const savedPaneVisible = localStorage.getItem('odcm_filter_pane_visible');
                const isMobileViewport = window.innerWidth <= 782;
                this.filterPaneVisible = isMobileViewport ? false : (savedPaneVisible !== null ? savedPaneVisible === 'true' : true);

                // Sync initial data-* grid attributes for new design CSS
                const grid = this.$el.querySelector('.odcm-content-grid');
                if (grid) {
                    if (!this.filterPaneVisible) grid.setAttribute('data-filter-collapsed', 'true');
                    grid.setAttribute('data-detail-collapsed', 'true'); // no log selected initially
                }

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

                // Sync data-* attribute for new design CSS column selectors
                const grid = this.$el.querySelector('.odcm-content-grid');
                if (grid) {
                    if (visible) {
                        grid.removeAttribute('data-filter-collapsed');
                    } else {
                        grid.setAttribute('data-filter-collapsed', 'true');
                    }
                }

                // Re-measure header bottom after pane state changes (header content may change)
                requestAnimationFrame(() => this.updateHeaderBottom());
            });

            // Re-measure header bottom when detail pane opens/closes (swaps header content)
            this.$watch('selectedLog', (log) => {
                // Sync data-* attribute for new design CSS column selectors
                const grid = this.$el.querySelector('.odcm-content-grid');
                if (grid) {
                    if (log) {
                        grid.removeAttribute('data-detail-collapsed');
                    } else {
                        grid.setAttribute('data-detail-collapsed', 'true');
                    }
                }
                requestAnimationFrame(() => this.updateHeaderBottom());
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

        // Watch filter checkbox changes for immediate persistence and force reload
        this.$watch('filters.include_tests', (value) => {
            this.saveSettings();
            if (odcmIsDebug()) { console.log('ODCM: Include tests setting changed to:', value); }
            // Force reload when debug/test filters change to ensure immediate data consistency
            this.forceReloadOnDebugFilterChange();
        });

        this.$watch('filters.include_debug', (value) => {
            this.saveSettings();
            if (odcmIsDebug()) { console.log('ODCM: Include debug setting changed to:', value); }
            // Force reload when debug/test filters change to ensure immediate data consistency
            this.forceReloadOnDebugFilterChange();
        });

            // Watch detail pane expansion changes for immediate persistence
            this.$watch('detailPaneExpanded', (expanded) => {
                this.saveSettings();
                if (odcmIsDebug()) { console.log('ODCM: Detail pane expansion changed to:', expanded); }
            });
        },

        // ==============================================================================
        // SETTINGS HELPERS (Timestamp, Per-Page, Debug, Retention, Reprocess, Uninstall)
        // ==============================================================================
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
        async saveCwEnabled(checked) {
            try {
                const payload = new URLSearchParams({
                    action: 'odcm_save_custom_webhook_settings',
                    _wpnonce: this.config.nonce,
                    odcm_custom_webhook_enabled: checked ? '1' : '0',
                    odcm_custom_webhook_auth_method: this.cwAuthMethod,
                    odcm_custom_webhook_secret: '__saved__',
                    odcm_custom_webhook_hmac_header: document.getElementById('odcm_cw_hmac_header')?.value || '',
                    odcm_custom_webhook_slug: this.cwSlug,
                });
                const resp = await fetch(this.config.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload,
                });
                const data = await resp.json().catch(() => null);
                if (data && data.success) {
                    this.showToast(checked ? 'Custom webhook enabled' : 'Custom webhook disabled', 'success');
                } else {
                    this.showToast((data && data.data?.message) || 'Failed to save webhook setting', 'error');
                }
            } catch (e) {
                this.showToast('Failed to save webhook setting', 'error');
            }
        },
        async saveCustomWebhookSettings() {
            if (this.cwSaving) return;
            this.cwSaving = true;
            try {
                const secret = document.getElementById('odcm_cw_secret');
                const header = document.getElementById('odcm_cw_hmac_header');
                const slugInput = document.getElementById('odcm_cw_slug');
                const payload = new URLSearchParams({
                    action: 'odcm_save_custom_webhook_settings',
                    _wpnonce: this.config.nonce,
                    odcm_custom_webhook_enabled: this.cwEnabled ? '1' : '0',
                    odcm_custom_webhook_auth_method: this.cwAuthMethod,
                    odcm_custom_webhook_secret: secret ? secret.value : '',
                    odcm_custom_webhook_hmac_header: header ? header.value : '',
                    odcm_custom_webhook_slug: slugInput ? slugInput.value : this.cwSlug,
                });
                const resp = await fetch(this.config.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload,
                });
                const data = await resp.json().catch(() => null);
                if (!resp.ok) {
                    this.showToast(`Server error (${resp.status}) — settings not saved`, 'error');
                    return;
                }
                if (data && data.success) {
                    if (data.data?.secret_saved && secret) secret.value = '__saved__';
                    if (data.data?.new_slug) this.cwSlug = data.data.new_slug;
                    this.showToast('Webhook settings saved', 'success');
                } else {
                    this.showToast((data && data.data?.message) || 'Failed to save webhook settings', 'error');
                }
            } catch (e) {
                this.showToast('Failed to save webhook settings — check your connection', 'error');
            } finally {
                this.cwSaving = false;
            }
        },
        copyWebhookUrl() {
            const urlBase = this.config?.customWebhook?.urlBase || '';
            const slug = (document.getElementById('odcm_cw_slug')?.value || this.cwSlug).trim();
            const url = urlBase + slug;
            const fallback = () => {
                const el = document.createElement('textarea');
                el.value = url;
                el.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none;';
                document.body.appendChild(el);
                el.focus();
                el.select();
                let ok = false;
                try { ok = document.execCommand('copy'); } catch (_) {}
                document.body.removeChild(el);
                this.showToast(ok ? 'Webhook URL copied' : 'Copy failed — URL: ' + url, ok ? 'success' : 'error');
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url)
                    .then(() => this.showToast('Webhook URL copied', 'success'))
                    .catch(fallback);
            } else {
                fallback();
            }
        },
        async saveUninstallDataSetting(checked) {
            // Show loading state
            this.isSavingUninstallSetting = true;

            // Make AJAX request
            fetch(this.config.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'odcm_save_uninstall_data_setting',
                    _wpnonce: this.config.nonce,
                    odcm_remove_all_data_on_uninstall: checked ? '1' : '0'
                })
            })
            .then(response => response.json())
            .then(data => {
                this.isSavingUninstallSetting = false;
                
                if (data.success) {
                    // Show success toast with proper message string
                    this.showToast(data.message, 'success', {
                        duration: 5000
                    });
                } else {
                    // Show error toast with proper message string
                    this.showToast(data.message, 'error', {
                        duration: 5000
                    });
                }
            })
            .catch(error => {
                this.isSavingUninstallSetting = false;
                console.error('Error saving uninstall setting:', error);
                
                // Show error toast
                    this.showToast('Failed to save uninstall setting. Please try again.', 'error', {
                    duration: 5000
                });
            });
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
                animationId: `new_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`
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

            // Search including order_id matching
            if (this.filters.search) {
                const searchTerm = this.filters.search.trim();

                // General search across multiple fields
                activeFilters.search = searchTerm;

                // Also search order_id field if search term is numeric
                if (/^\d+$/.test(searchTerm)) {
                    activeFilters.order_id = searchTerm;
                }

            }

            if (this.filters.event_type) {
                activeFilters.event_type = this.filters.event_type;
            }
            if (this.filters.source) {
                activeFilters.source = this.filters.source;
            }
            if (this.filters.order_id) {
                activeFilters.order_id = this.filters.order_id;
            }
            // Fix: Use date_from and date_to to match API expectations
            if (this.filters.date_start) {
                activeFilters.date_from = this.filters.date_start;
            }
            if (this.filters.date_end) {
                activeFilters.date_to = this.filters.date_end;
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
                if (f.event_type) return true;
                if (f.source) return true;
                if (f.order_id) return true;
                if (f.date_start) return true;
                if (f.date_end) return true;
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

        // Measures the actual bottom edge of the sticky header and writes it as
        // --odcm-header-bottom on the dashboard root so fixed drawers can anchor to it.
        updateHeaderBottom() {
            const header = this.$el.querySelector('.odcm-unified-header');
            if (!header) return;
            const bottom = header.getBoundingClientRect().bottom;
            this.$el.style.setProperty('--odcm-header-bottom', bottom + 'px');
        },

        setupHeaderBottomMeasurement() {
            this.updateHeaderBottom();
            let _resizeTimer = null;
            this._resizeHeaderBottom = () => {
                clearTimeout(_resizeTimer);
                _resizeTimer = setTimeout(() => this.updateHeaderBottom(), 100);
            };
            window.addEventListener('resize', this._resizeHeaderBottom);
        },
        openLastOpenedPane() {
            this.filterPaneVisible = true;
            this.activeFilterTab = this.lastOpenedTab || 'filters';
        },
        showFiltersPane() {
            // Toggle: clicking the active tab's icon closes the pane
            if (this.filterPaneVisible && this.activeFilterTab === 'filters') {
                this.filterPaneVisible = false;
                return;
            }
            this.activeFilterTab = 'filters';
            this.lastOpenedTab = 'filters';
            this.filterPaneVisible = true;
            // On mobile, slide the detail pane out when opening the filter/settings drawer
            if (window.innerWidth <= 782) {
                this.closeDetails();
            }
        },
        showSettingsPane() {
            // Toggle: clicking the active tab's icon closes the pane
            if (this.filterPaneVisible && this.activeFilterTab === 'settings') {
                this.filterPaneVisible = false;
                return;
            }
            this.activeFilterTab = 'settings';
            this.lastOpenedTab = 'settings';
            this.filterPaneVisible = true;
            // On mobile, slide the detail pane out when opening the filter/settings drawer
            if (window.innerWidth <= 782) {
                this.closeDetails();
            }
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

        applyDatePreset(preset) {
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const toDateStr = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
            const offsets = { '1h': 1/24, '24h': 1, '7d': 7, '30d': 30, '90d': 90 };
            if (preset === 'all') {
                this.filters.date_start = '';
                this.filters.date_end = '';
            } else if (offsets[preset] !== undefined) {
                const from = new Date(now.getTime() - offsets[preset] * 86400000);
                this.filters.date_start = toDateStr(from);
                this.filters.date_end = toDateStr(now);
            }
        },

        /**
         * Force reload of both log stream and currently open timeline when debug/test filters change
         * This ensures immediate data consistency when toggling filters that significantly change the visible dataset
         */
        async forceReloadOnDebugFilterChange() {
            if (odcmIsDebug()) {
                console.log('ODCM: forceReloadOnDebugFilterChange() called - reloading log stream and timeline');
            }

            // Reset to first page to ensure we see the most relevant data
            this.currentPage = 1;

            try {
                // Reload the log stream with the new filter settings
                await this.fetchLogs();

                // If there's a currently selected log, reload its timeline with the new filter settings
                if (this.selectedLog) {
                    if (odcmIsDebug()) {
                        console.log('ODCM: Reloading timeline for selected log:', this.selectedLog.id);
                    }
                    await this.selectLog(this.selectedLog);
                }

                if (odcmIsDebug()) {
                    console.log('ODCM: Debug filter reload completed successfully');
                }
            } catch (error) {
                console.error('ODCM: Error during debug filter reload:', error);
                this.showToast('Failed to reload data after filter change', 'error');
            }
        },

        // =================================================================
        // ERROR TEMPLATE HELPERS
        // =================================================================

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
        },

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
        },

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
        },

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
        },

        // =================================================================
        // TOAST HELPERS
        // =================================================================
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
                    if (odcmIsDebug()) {
                        console.error('ODCM Toast (Error):', message);
                        if (options && options.error) {
                            console.error('Error details:', options.error);
                        }
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

        createFallbackToast(message, type = 'info', options = {}) {
            try {
                const toastContainer = document.getElementById('odcm-toast-container') || this.createToastContainer();

                const toast = document.createElement('div');
                toast.className = `odcm-fallback-toast odcm-toast-${type}`;
                toast.style.cssText = `
                    background: ${this.getToastBackground(type)};
                    color: white;
                    padding: 12px 16px;
                    margin: 8px 0;
                    border-radius: 4px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                    animation: slideInRight 0.3s ease;
                    cursor: pointer;
                    position: relative;
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    max-width: 400px;
                    min-width: 250px
                `;

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

        // ADD helper method for toast background colors
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

        // ADD method for fallback toast removal
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
        },


        // =================================================================
        // SELECTION MANAGEMENT
        // =================================================================
        toggleSelectAll(checked) {
            const shouldSelect = (typeof checked === 'boolean') ? checked : !!this.selectAll;
            if (shouldSelect) {
                this.selectedLogIds = this.getValidIds((this.logs || []).map(l => l && l.id));
            } else {
                this.selectedLogIds = [];
            }
            this.selectAll = shouldSelect;
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

        // Helper method for ID validation
        getValidIds(ids) {
            return (ids || []).filter(id => id !== null && id !== undefined && id !== '');
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

                if (log && log.is_process_group === true) {
                    // This is a consolidated entry - fetch constituent logs from the process endpoint
                    try {
                        if (odcmIsDebug()) { console.log(`ODCM: Found consolidated entry ${selectedId}, fetching constituent logs...`); }

                        // Use the process endpoint to get all logs for this process
                        const processResponse = await fetch(`${this.config.apiUrl}process/${log.process_id}/`, {
                            headers: {
                                'X-WP-Nonce': this.config.nonce
                            }
                        });

                        if (processResponse.ok) {
                            const processData = await processResponse.json();

                            if (processData.logs && Array.isArray(processData.logs) && processData.logs.length > 0) {
                                // Extract all log IDs from the constituent logs
                                const constituentIds = processData.logs.map(l => l.id).filter(id => id);

                                if (constituentIds.length > 0) {
                                    expandedIds.push(...constituentIds);
                                    consolidatedEntriesExpanded++;
                                    if (odcmIsDebug()) { console.log(`ODCM: Expanded consolidated entry ${selectedId} to ${constituentIds.length} constituent IDs:`, constituentIds); }
                                } else {
                                    if (odcmIsDebug()) { console.warn(`ODCM: Consolidated entry ${selectedId} had no valid constituent IDs`); }
                                    // Fall back to deleting the placeholder ID
                                    expandedIds.push(selectedId);
                                }
                            } else {
                                if (odcmIsDebug()) { console.warn(`ODCM: Process endpoint returned no logs for process_id ${log.process_id}`); }
                                // Fall back to deleting the placeholder ID
                                expandedIds.push(selectedId);
                            }
                        } else {
                            if (odcmIsDebug()) { console.error(`ODCM: Failed to fetch constituent logs for process_id ${log.process_id}: ${processResponse.status}`); }
                            // Fall back to deleting the placeholder ID
                            expandedIds.push(selectedId);
                        }
                    } catch (error) {
                        if (odcmIsDebug()) { console.error(`ODCM: Error fetching constituent logs for consolidated entry ${selectedId}:`, error); }
                        // Fall back to deleting the placeholder ID
                        expandedIds.push(selectedId);
                    }
                } else {
                    // Regular individual entry - add its ID
                    expandedIds.push(selectedId);
                }
            }

            // Remove duplicates (in case of overlapping selections)
            const uniqueIds = [...new Set(expandedIds)];

            if (odcmIsDebug()) {
                console.log('ODCM: Starting batch delete:', {
                    selectedCount,
                    originalIds: selectedIds.length,
                    expandedIds: expandedIds.length,
                    uniqueIds: uniqueIds.length,
                    consolidatedEntriesExpanded,
                    apiUrl: this.config.apiUrl,
                    nonce: this.config.nonce ? 'present' : 'missing'
                });
            }

            // Split into chunks of 100 for server compatibility
            const CHUNK_SIZE = 100;
            const chunks = [];
            for (let i = 0; i < uniqueIds.length; i += CHUNK_SIZE) {
                chunks.push(uniqueIds.slice(i, i + CHUNK_SIZE));
            }

            if (odcmIsDebug()) { console.log(`ODCM: Processing ${chunks.length} chunks of max ${CHUNK_SIZE} items each`); }

            let totalDeleted = 0;
            let totalFailed = 0;
            let failedChunks = [];

            try {
                // Process chunks sequentially for stability
                for (let chunkIndex = 0; chunkIndex < chunks.length; chunkIndex++) {
                    const chunk = chunks[chunkIndex];
                    const chunkNumber = chunkIndex + 1;

                    if (odcmIsDebug()) { console.log(`ODCM: Processing chunk ${chunkNumber}/${chunks.length} (${chunk.length} items)`); }

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

                            if (odcmIsDebug()) {
                                console.log(`ODCM: Chunk ${chunkNumber} completed successfully:`, { deleted_count: deletedCount, chunk_size: chunk.length });
                            }
                        } else {
                            throw new Error(data.message || 'Unexpected response format');
                        }

                    } catch (chunkError) {
                        if (odcmIsDebug()) { console.error(`ODCM: Chunk ${chunkNumber} failed:`, chunkError); }
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

                if (odcmIsDebug()) {
                    console.log('ODCM: Batch delete process completed:', {
                        total_selected: selectedCount,
                        total_expanded: uniqueIds.length,
                        total_deleted: totalDeleted,
                        total_failed: totalFailed,
                        chunks_processed: chunks.length,
                        failed_chunks: failedChunks.length,
                        consolidated_entries_expanded: consolidatedEntriesExpanded
                    });
                }

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
        formatTimestamp(ts, element = null) {
            try {
                // Handle raw timestamp data attribute for timeline components
                if (element && element.hasAttribute('data-raw-timestamp')) {
                    ts = element.getAttribute('data-raw-timestamp');
                }

                const cfg = this.config.dateTimeConfig || {};
                let timestamp = ts;

                // Enhanced timestamp parsing with proper ISO 8601 handling
                if (typeof timestamp === 'string') {
                    // First, try to parse as ISO 8601 with timezone offset
                    if (typeof timestamp === 'string') {
                        // Try native Date parsing first
                        const parsedDate = new Date(timestamp);
                        if (!isNaN(parsedDate.getTime())) {
                            timestamp = parsedDate.getTime();
                        }
                    } else if (/^\d+(\.\d+)?$/.test(timestamp)) {
                        // Handle numeric strings (Unix timestamps)
                        timestamp = parseFloat(timestamp);

                        // If it's a Unix timestamp in seconds (10 digits), convert to milliseconds
                        if (timestamp > 1000000000 && timestamp < 9999999999) {
                            timestamp = timestamp * 1000;
                        }
                    } else {
                        // Try to parse as ISO date string without timezone offset
                        const parsedDate = new Date(timestamp);
                        if (!isNaN(parsedDate.getTime())) {
                            timestamp = parsedDate.getTime();
                        }
                    }
                } else if (typeof timestamp === 'number') {
                    // If it's a Unix timestamp in seconds (10 digits), convert to milliseconds
                    if (timestamp > 1000000000 && timestamp < 9999999999) {
                        timestamp = timestamp * 1000;
                    }
                }

                // Create Date object with properly formatted timestamp
                const d = new Date(timestamp);
                const mode = this.timestampDisplayMode || 'dateTime';

                // Use WordPress timezone if available
                const timezone = cfg.timezone || undefined;

                if (mode === 'timeOnly') {
                    // Get the base time string without milliseconds
                    let timeString = d.toLocaleTimeString(undefined, { 
                        hour: '2-digit', 
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false
                    });

                    // Add milliseconds if available (same logic as dateTime mode)
                    if (typeof ts === 'string' && /\.\d{3}/.test(ts)) {
                        const ms = ts.match(/\.(\d{3})/);
                        if (ms) {
                            timeString += '.' + ms[1];
                        }
                    } else if (typeof ts === 'number') {
                        // Get milliseconds from numeric timestamp
                        const ms = d.getMilliseconds();
                        if (ms) {
                            timeString += '.' + ms.toString().padStart(3, '0');
                        }
                    }

                    return timeString;
                }
                if (mode === 'relative') {
                    const diff = (Date.now() - d.getTime()) / 1000;
                    if (diff < 60) return `${Math.floor(diff)}s ago`;
                    if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
                    if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
                    return `${Math.floor(diff/86400)}d ago`;
                }

                // date & time with milliseconds
                let formatted;
                if (timezone && !/^(\+|-)\d{2}:\d{2}$/.test(timezone)) {
                    // Use timezone only if it's a valid IANA timezone name
                    try {
                        formatted = d.toLocaleString(timezone, {
                            year: 'numeric', 
                            month: 'short', 
                            day: '2-digit',
                            hour: '2-digit', 
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: false
                        });
                    } catch (e) {
                        // Fallback if timezone is invalid
                        formatted = d.toLocaleString(undefined, {
                            year: 'numeric', 
                            month: 'short', 
                            day: '2-digit',
                            hour: '2-digit', 
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: false
                        });
                    }
                } else {
                    // Fallback to default locale without timezone
                    formatted = d.toLocaleString(undefined, {
                        year: 'numeric', 
                        month: 'short', 
                        day: '2-digit',
                        hour: '2-digit', 
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false
                    });
                }

                // Add milliseconds if available
                if (typeof ts === 'string' && /\.\d{3}/.test(ts)) {
                    const ms = ts.match(/\.(\d{3})/);
                    if (ms) {
                        formatted += '.' + ms[1];
                    }
                }

                return formatted;

            } catch (e) {
                console.warn('ODCM: Timestamp formatting error:', e);
                // Return a formatted error message instead of raw timestamp
                return String(ts || 'Invalid timestamp');
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
            // Don't remove existing handlers - use persistent event delegation
            // This ensures dynamically loaded content works properly

            // Add event listeners for three-tier toggles (only if not already added)
            if (!document._odcmThreeTierTogglesInitialized) {
                document.addEventListener('click', this.handleTierToggleClick.bind(this));
                document.addEventListener('keydown', this.handleTierToggleKeydown.bind(this));
                document._odcmThreeTierTogglesInitialized = true;

                if (odcmIsDebug()) {
                    console.log('ODCM: Three-tier toggle handlers initialized (first time)');
                }
            } else {
                if (odcmIsDebug()) {
                    console.log('ODCM: Three-tier toggle handlers already initialized (using persistent delegation)');
                }
            }

            // Force re-initialize all existing toggle buttons to ensure proper state
            this.reinitializeAllToggleButtons();
        },

        /**
         * Reinitialize all toggle buttons in the DOM to ensure proper state synchronization
         * This is called after dynamic content loading to fix any inconsistencies
         */
        reinitializeAllToggleButtons() {
            try {
                if (odcmIsDebug()) {
                    console.log('ODCM: Reinitializing all toggle buttons...');
                }

                // Find all tier toggle buttons in the DOM
                const toggleButtons = document.querySelectorAll('.odcm-tier-toggle');
                if (odcmIsDebug()) {
                    console.log(`ODCM: Found ${toggleButtons.length} toggle buttons in DOM`);
                }

                let buttonsProcessed = 0;
                let buttonsWithIssues = 0;

                toggleButtons.forEach((button, index) => {
                    try {
                        // Validate required attributes
                        if (!button.dataset.target) {
                            if (odcmIsDebug()) {
                                console.warn(`ODCM: Toggle button at index ${index} missing data-target attribute`, button);
                            }
                            buttonsWithIssues++;
                            return;
                        }

                        // Find parent elements
                        const expandableSection = button.closest('.odcm-expandable-section');
                        const tierContent = expandableSection ? expandableSection.querySelector('.odcm-tier-content') : null;
                        const component = button.closest('.odcm-component');

                        if (!expandableSection || !tierContent) {
                            if (odcmIsDebug()) {
                                console.warn(`ODCM: Could not find required parent elements for toggle button ${index}`, {
                                    hasExpandableSection: !!expandableSection,
                                    hasTierContent: !!tierContent,
                                    button: button
                                });
                            }
                            buttonsWithIssues++;
                            return;
                        }

                        // Synchronize ARIA attributes with actual DOM state
                        const target = button.dataset.target;
                        const isCurrentlyExpanded = expandableSection.getAttribute('aria-expanded') === 'true';
                        const buttonAriaExpanded = button.getAttribute('aria-expanded');

                        // Fix inconsistencies
                        if (isCurrentlyExpanded !== (buttonAriaExpanded === 'true')) {
                            button.setAttribute('aria-expanded', isCurrentlyExpanded.toString());
                            if (odcmIsDebug()) {
                                console.log(`ODCM: Fixed ARIA inconsistency for button ${index} - section:${isCurrentlyExpanded}, button:${buttonAriaExpanded}`);
                            }
                        }

                        // Ensure proper button text based on expansion state
                        const currentText = button.textContent.trim();
                        const expectedText = isCurrentlyExpanded
                            ? currentText.replace('Show', 'Hide')
                            : currentText.replace('Hide', 'Show');

                        if (currentText !== expectedText) {
                            button.textContent = expectedText;
                            if (odcmIsDebug()) {
                                console.log(`ODCM: Fixed button text for button ${index}: "${currentText}" -> "${expectedText}"`);
                            }
                        }

                        // Ensure component has proper expanded class
                        if (component) {
                            const expectedClass = `${target}-expanded`;
                            const hasClass = component.classList.contains(expectedClass);

                            if (isCurrentlyExpanded && !hasClass) {
                                component.classList.add(`${target}-expanded`);
                                if (odcmIsDebug()) {
                                    console.log(`ODCM: Added ${expectedClass} class to component for button ${index}`);
                                }
                            } else if (!isCurrentlyExpanded && hasClass) {
                                component.classList.remove(`${target}-expanded`);
                                if (odcmIsDebug()) {
                                    console.log(`ODCM: Removed ${expectedClass} class from component for button ${index}`);
                                }
                            }
                        }

                        buttonsProcessed++;

                    } catch (error) {
                        if (odcmIsDebug()) {
                            console.error(`ODCM: Error processing toggle button ${index}:`, error);
                        }
                        buttonsWithIssues++;
                    }
                });

                if (odcmIsDebug()) {
                    console.log(`ODCM: Toggle button reinitialization complete - Processed: ${buttonsProcessed}, Issues: ${buttonsWithIssues}, Total: ${toggleButtons.length}`);
                }

                // If no buttons were found, that's expected when no log is selected yet
                // Toggle buttons appear dynamically when logs are selected
                // Event delegation handles clicks on dynamically loaded buttons automatically
                if (toggleButtons.length === 0) {
                    if (odcmIsDebug()) {
                        console.log('ODCM: No toggle buttons found in DOM - this is expected when no log entry is selected');
                    }
                }

            } catch (error) {
                console.error('ODCM: Error in reinitializeAllToggleButtons:', error);
                if (odcmIsDebug()) {
                    console.error('ODCM: Full error details:', {
                        name: error.name,
                        message: error.message,
                        stack: error.stack
                    });
                }
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
                // Fixed: Check the expandable section's aria-expanded attribute for more reliable state detection
                // The section's attribute is more authoritative than the button's attribute
                const sectionAriaExpanded = expandableSection.getAttribute('aria-expanded');
                const buttonAriaExpanded = toggleButton.getAttribute('aria-expanded');

                // Use section state as primary indicator, with button state as fallback
                const isExpanded = sectionAriaExpanded === 'true' || buttonAriaExpanded === 'true';

                if (odcmIsDebug()) {
                    console.log('ODCM: State detection - Section:', sectionAriaExpanded, 'Button:', buttonAriaExpanded, 'Final:', isExpanded);
                }

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
        // NETWORK STATUS MONITORING
        // =================================================================

        /**
         * Set up network monitoring for connectivity awareness
         */
        setupNetworkMonitoring() {
            try {
                // Set initial network status
                this.networkOnline = navigator.onLine;

                // Listen for online/offline events
                window.addEventListener('online', () => this.handleNetworkOnline());
                window.addEventListener('offline', () => this.handleNetworkOffline());

                // Periodic network health checks (every 30 seconds)
                this.networkHealthInterval = setInterval(() => {
                    this.checkNetworkHealth();
                }, 30000);

                if (odcmIsDebug()) {
                    console.log('ODCM: Network monitoring initialized, online:', this.networkOnline);
                }

            } catch (error) {
                console.error('ODCM: Error setting up network monitoring:', error);
            }
        },

        /**
         * Handle network coming online
         */
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

            // Auto-refresh data when connection is restored
            if (this.selectedLog) {
                this.selectLog(this.selectedLog);
            } else {
                this.fetchLogs();
            }
        },

        /**
         * Handle network going offline
         */
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
        },

        /**
         * Perform a network health check
         */
        checkNetworkHealth() {
            try {
                if (!this.networkOnline) return;

                // Simple health check using a small API request
                const healthCheckUrl = this.config.apiUrl + '?healthcheck=1';

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
                    if (!response.ok && response.status >= 500) {
                        this.recordNetworkIssue('healthcheck_failed', `Server returned ${response.status}`);
                    }
                })
                .catch(error => {
                    clearTimeout(timeoutId);
                    if (error.name !== 'AbortError') {
                        this.recordNetworkIssue('healthcheck_error', error.message);
                    }
                });

            } catch (error) {
                if (odcmIsDebug()) {
                    console.warn('ODCM: Network health check error:', error);
                }
            }
        },

        /**
         * Record a network issue for tracking
         */
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

            if (odcmIsDebug()) {
                console.warn('ODCM: Network issue recorded:', type, details);
            }
        },

        /**
         * Format relative time for display
         */
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
                if (odcmIsDebug()) {
                    console.warn('ODCM: Error formatting relative time:', error);
                }
                return isoString;
            }
        },

        // =================================================================
        // CLEANUP
        // =================================================================

        /**
         * Clean up resources when dashboard is destroyed
         */
        cleanup() {
            try {
                // Clean up active requests
                if (window.odcmActiveRequests) {
                    window.odcmActiveRequests.forEach(requestId => {
                        if (odcmIsDebug()) {
                            console.debug(`ODCM: Cleaning up request ${requestId}`);
                        }
                    });
                    window.odcmActiveRequests.clear();
                }

                // Stop auto-refresh
                this.stopAutoRefresh();

                // Clean up network health monitoring
                if (this.networkHealthInterval) {
                    clearInterval(this.networkHealthInterval);
                    this.networkHealthInterval = null;
                }

                // Remove network event listeners
                window.removeEventListener('online', this.handleNetworkOnline);
                window.removeEventListener('offline', this.handleNetworkOffline);

                // Remove header-bottom resize listener
                if (this._resizeHeaderBottom) {
                    window.removeEventListener('resize', this._resizeHeaderBottom);
                    this._resizeHeaderBottom = null;
                }

                // Clean up any debounced fetch timers
                if (this.debouncedFetchLogs && this.debouncedFetchLogs.timer) {
                    clearTimeout(this.debouncedFetchLogs.timer);
                }

                if (odcmIsDebug()) {
                    console.log('ODCM: Cleanup completed');
                }

            } catch (error) {
                console.error('ODCM: Error during cleanup:', error);
            }
        },

        // =================================================================
        // OUTSIDE CLICK HANDLER
        // =================================================================
        setupOutsideClickHandler() {
            // Add event listener for outside clicks
            document.addEventListener('click', this.handleOutsideClick.bind(this));
        },

        handleOutsideClick(event) {
            try {
                // Check if the click was outside the detail pane
                const detailPane = document.querySelector('.odcm-detail-pane');
                const logEntries = document.querySelectorAll('.odcm-log-entry');

                // The timeline header (.odcm-unified-header-details) is in the unified sticky header,
                // not inside .odcm-detail-pane, so clicks on it must also be treated as "inside" the detail UI.
                const detailPaneHeader = document.querySelector('.odcm-unified-header-details');

                // Check if the click was on the detail pane (or its header section) or any log entry
                const isClickOnDetailPane = (detailPane && detailPane.contains(event.target))
                    || (detailPaneHeader && detailPaneHeader.contains(event.target));
                const isClickOnLogEntry = Array.from(logEntries).some(entry => entry.contains(event.target));

                // Check if the click was on any focusable/interactive element
                const isClickOnInteractiveElement = this.isFocusable(event.target);

                // If the click was outside the detail pane, log entries, and interactive elements, close the detail pane
                if (!isClickOnDetailPane && !isClickOnLogEntry && !isClickOnInteractiveElement && this.selectedLog) {
                    this.closeDetails();
                }
            } catch (error) {
                if (odcmIsDebug()) {
                    console.warn('ODCM: Error handling outside click:', error);
                }
            }
        },

        /**
         * Check if an element is focusable/interactive
         * This is a more portable approach that doesn't rely on specific class names
         */
        isFocusable(element) {
            if (!element || element.nodeType !== Node.ELEMENT_NODE) {
                return false;
            }

            // Check for standard interactive elements
            const interactiveTags = ['BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'A'];
            if (interactiveTags.includes(element.nodeName)) {
                // For input, exclude hidden fields
                if (element.nodeName === 'INPUT' && element.type === 'hidden') {
                    return false;
                }
                return true;
            }

            // Check for tabindex (positive or zero)
            if (element.hasAttribute('tabindex') && parseInt(element.getAttribute('tabindex')) >= 0) {
                return true;
            }

            // Check for ARIA roles that indicate interactivity
            const interactiveRoles = ['button', 'tab', 'menuitem', 'link', 'checkbox', 'radio', 'combobox', 'slider', 'switch'];
            if (element.hasAttribute('role') && interactiveRoles.includes(element.getAttribute('role').toLowerCase())) {
                return true;
            }

            // Check if element has click event listeners (more advanced check)
            // This is a fallback for elements that might be interactive but don't fit other criteria
            if (typeof element.onclick === 'function' || (element.hasAttribute('onclick') && element.getAttribute('onclick'))) {
                return true;
            }

            // Recursively check parent elements (in case the click was on a child element)
            if (element.parentElement) {
                return this.isFocusable(element.parentElement);
            }

            return false;
        },

        // =================================================================
        // DETAIL PANE
        // =================================================================
        async selectLog(log) {
            this.selectedLog = log;
            this.detailLoading = true;

            // On mobile, close the filter drawer before the detail pane slides in
            if (window.innerWidth <= 782) {
                this.filterPaneVisible = false;
            }

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