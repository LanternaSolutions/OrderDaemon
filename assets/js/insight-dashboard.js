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
        if (typeof window !== 'undefined' && typeof window.ODCM_DEBUG === 'undefined' && window.odcmInsightConfig && typeof window.odcmInsightConfig.debug !== 'undefined') {
            window.ODCM_DEBUG = !!window.odcmInsightConfig.debug;
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

                // Initialize height detection and adjustment
                this.initializeHeightManagement();

                // Debug initial state
                if (odcmIsDebug()) { console.log('ODCM: Initial state - filterPaneVisible:', this.filterPaneVisible, 'detailsOpen:', this.detailsOpen); }
                if (odcmIsDebug()) { console.log('ODCM: Dashboard classes:', this.dashboardClasses); }

                // Initialize Prism.js highlighting
                this.initializePrismHighlighting();

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

        // =================================================================
        // WORDPRESS HEIGHT INTEGRATION
        // =================================================================

        initializeHeightManagement() {
            try {
                // Hook into WordPress's existing height management system
                this.setupWordPressHeightIntegration();

                if (odcmIsDebug()) {
                    console.log('ODCM: WordPress height integration initialized');
                }
            } catch (error) {
                if (odcmIsDebug()) {
                    console.warn('ODCM: WordPress height integration failed:', error);
                }
            }
        },

        setupWordPressHeightIntegration() {
            try {
                // Enhanced function to sync our dashboard with WordPress's layout system
                const syncDashboardWithWordPress = () => {
                    try {
                        const wpBodyContent = document.getElementById('wpbody-content');
                        const wpBody = document.getElementById('wpbody');
                        const wpcontent = document.getElementById('wpcontent');
                        const dashboard = document.getElementById('odcm-insight-dashboard');
                        const adminBar = document.getElementById('wpadminbar');

                        if (!dashboard) return;

                        // Calculate available height based on WordPress structure
                        let availableHeight;

                        // Method 1: Use WordPress's calculated height if available
                        if (wpBodyContent && wpBodyContent.style.height && wpBodyContent.style.height !== 'auto') {
                            availableHeight = wpBodyContent.style.height;
                            if (odcmIsDebug()) {
                                console.log(`ODCM: Using WordPress calculated height: ${availableHeight}`);
                            }
                        }
                        // Method 2: Calculate from viewport minus admin elements
                        else {
                            const adminBarHeight = adminBar ? adminBar.offsetHeight : 32;
                            const viewportHeight = window.innerHeight;

                            // Account for any additional WordPress admin elements
                            let additionalOffset = 0;

                            // Check for WordPress notices that might affect height
                            const notices = document.querySelectorAll('.notice, .updated, .error');
                            notices.forEach(notice => {
                                if (notice.offsetParent !== null) { // Only visible notices
                                    additionalOffset += notice.offsetHeight;
                                }
                            });

                            availableHeight = `${viewportHeight - adminBarHeight - additionalOffset}px`;

                            if (odcmIsDebug()) {
                                console.log(`ODCM: Calculated height - Viewport: ${viewportHeight}px, Admin bar: ${adminBarHeight}px, Notices: ${additionalOffset}px, Result: ${availableHeight}`);
                            }
                        }

                        // Apply height to dashboard with proper CSS custom property support
                        dashboard.style.height = availableHeight;
                        dashboard.style.minHeight = availableHeight;
                        dashboard.style.maxHeight = availableHeight;

                        // Update CSS custom properties for responsive components
                        const adminBarHeight = adminBar ? adminBar.offsetHeight : 32;
                        document.documentElement.style.setProperty('--odcm-calculated-admin-bar-height', `${adminBarHeight}px`);
                        document.documentElement.style.setProperty('--odcm-calculated-dashboard-height', availableHeight);

                        // Ensure parent containers support the full height
                        if (wpBodyContent) {
                            wpBodyContent.style.height = availableHeight;
                            wpBodyContent.style.minHeight = availableHeight;
                            wpBodyContent.style.overflow = 'hidden';
                        }

                        if (wpBody) {
                            wpBody.style.height = '100vh';
                            wpBody.style.display = 'flex';
                            wpBody.style.flexDirection = 'column';
                        }

                        if (wpcontent) {
                            wpcontent.style.height = '100vh';
                            wpcontent.style.display = 'flex';
                            wpcontent.style.flexDirection = 'column';
                        }

                        // Trigger a custom event for other components that might need to respond
                        const heightChangeEvent = new CustomEvent('odcm-height-updated', {
                            detail: {
                                height: availableHeight,
                                adminBarHeight: adminBarHeight,
                                timestamp: Date.now()
                            }
                        });
                        document.dispatchEvent(heightChangeEvent);

                        if (odcmIsDebug()) {
                            console.log(`ODCM: Height sync completed - Dashboard: ${availableHeight}`);
                        }

                    } catch (error) {
                        if (odcmIsDebug()) {
                            console.warn('ODCM: Height sync failed:', error);
                        }
                    }
                };

                // Enhanced WordPress event integration
                const wordPressHeightEvents = [
                    'wp-pin-menu',
                    'wp-window-resized.pin-menu',
                    'postboxes-columnchange.pin-menu',
                    'postbox-toggled.pin-menu',
                    'wp-collapse-menu.pin-menu',
                    'wp-scroll-start.pin-menu',
                    'wp-collapse-menu',
                    'adminmenu-resize'
                ];

                // Listen for WordPress height updates with jQuery if available
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).on(wordPressHeightEvents.join(' '), syncDashboardWithWordPress);

                    // Also listen for WordPress admin menu state changes
                    jQuery(document).on('wp-collapse-menu', function(event, data) {
                        // Delay sync to allow WordPress to complete its layout changes
                        setTimeout(syncDashboardWithWordPress, 150);
                    });

                    if (odcmIsDebug()) {
                        console.log('ODCM: Hooked into WordPress height events:', wordPressHeightEvents);
                    }
                }

                // Enhanced resize handling with debouncing
                let resizeTimeout;
                const handleResize = () => {
                    clearTimeout(resizeTimeout);
                    resizeTimeout = setTimeout(() => {
                        syncDashboardWithWordPress();
                        // Also trigger a re-layout of internal components
                        this.triggerLayoutUpdate();
                    }, 100);
                };

                // Listen for various resize events
                window.addEventListener('resize', handleResize);
                window.addEventListener('orientationchange', handleResize);

                // Listen for WordPress-specific layout changes
                document.addEventListener('wp-responsive-activate', handleResize);
                document.addEventListener('wp-responsive-deactivate', handleResize);

                // Enhanced initial sync with multiple attempts
                const performInitialSync = () => {
                    // Immediate sync
                    syncDashboardWithWordPress();

                    // Additional syncs to catch WordPress's delayed layout calculations
                    setTimeout(syncDashboardWithWordPress, 100);
                    setTimeout(syncDashboardWithWordPress, 300);
                    setTimeout(syncDashboardWithWordPress, 500);
                };

                // Use Alpine.js $nextTick if available, otherwise setTimeout
                if (this.$nextTick) {
                    this.$nextTick(performInitialSync);
                } else {
                    setTimeout(performInitialSync, 50);
                }

                // Store enhanced cleanup function
                this.heightCleanup = () => {
                    if (typeof jQuery !== 'undefined') {
                        jQuery(document).off(wordPressHeightEvents.join(' '), syncDashboardWithWordPress);
                        jQuery(document).off('wp-collapse-menu');
                    }
                    window.removeEventListener('resize', handleResize);
                    window.removeEventListener('orientationchange', handleResize);
                    document.removeEventListener('wp-responsive-activate', handleResize);
                    document.removeEventListener('wp-responsive-deactivate', handleResize);

                    // Clear any pending timeouts
                    if (resizeTimeout) {
                        clearTimeout(resizeTimeout);
                    }
                };

                // Enhanced cleanup watcher
                if (this.$watch) {
                    this.$watch('$el', (newEl, oldEl) => {
                        if (!newEl && oldEl && this.heightCleanup) {
                            this.heightCleanup();
                        }
                    });
                }

            } catch (error) {
                if (odcmIsDebug()) {
                    console.warn('ODCM: WordPress height integration setup failed:', error);
                }
            }
        },

        // New method to trigger layout updates for internal components
        triggerLayoutUpdate() {
            try {
                // Trigger re-calculation of internal component heights
                const dashboard = document.getElementById('odcm-insight-dashboard');
                if (dashboard) {
                    // Force a reflow to ensure all components adapt to new height
                    dashboard.style.display = 'none';
                    dashboard.offsetHeight; // Trigger reflow
                    dashboard.style.display = '';

                    // Dispatch event for components that need to respond to layout changes
                    const layoutEvent = new CustomEvent('odcm-layout-update', {
                        detail: { timestamp: Date.now() }
                    });
                    dashboard.dispatchEvent(layoutEvent);
                }
            } catch (error) {
                if (odcmIsDebug()) {
                    console.warn('ODCM: Layout update failed:', error);
                }
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

                        // Handle 404 as welcome scenario instead of error (fixes the main issue)
                        if (response.status === 404) {
                            if (odcmIsDebug()) {
                                console.log('ODCM: Treating 404 as welcome scenario (no logs available)');
                            }

                            // Set welcome scenario state instead of showing error
                            this.logs = [];
                            this.total = 0;
                            this.totalPages = 1;
                            this.currentPage = 1;
                            this.isWelcomeScenario = true;
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

                    // Don't override the welcome scenario detection here
                    // The proper welcome scenario check happens in checkWelcomeScenario()
                    // which runs in parallel during initialization

                    // Debug: consolidation diagnostics and page composition
                    if (odcmIsDebug()) {
                        if (data.meta?.consolidation_diag) {
                            console.debug('ODCM Consolidation Diag:', data.meta.consolidation_diag);
                            if (data.meta?.consolidation_diag?.enabled === false) {
                                console.warn('ODCM: Consolidation disabled reason:', data.meta.consolidation_diag?.reason);
                            }
                        } else {
                            console.debug('ODCM: No consolidation diagnostics present in response meta');
                        }
                        const counts = { consolidated: 0, individual: 0 };
                        (this.logs || []).forEach(l => {
                            if (l && l.consolidation_data && l.consolidation_data.is_consolidated) counts.consolidated++; else counts.individual++;
                        });
                        console.log(`ODCM: Page composition -> consolidated: ${counts.consolidated}, individual: ${counts.individual}, total: ${this.logs.length}`);
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

                // Capability: In core (free) plugin, premium filters are always disabled for educational display
                this.canUsePremiumFilters = false;

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

        async fetchLogDetails(logId) {
            this.detailLoading = true;

            try {
                const response = await fetch(`${this.config.renderUrl}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.nonce
                    },
                    body: JSON.stringify({
                        log_id: logId,
                        include_debug: this.filters.include_debug // Pass current debug filter state
                    })
                });

                if (!response.ok) {
                    // Handle debug-filtered entries gracefully
                    if (response.status === 403) {
                        return '<div class="odcm-debug-filtered">This log entry is only visible when "Include Debug Logs" is enabled.</div>';
                    }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                return data.html || '';

            } catch (error) {
                console.error('ODCM: Error fetching log details:', error);
                this.showToast('Failed to load log details', 'error');
                return '<div class="odcm-error">Failed to load details</div>';
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

                        // Merge new logs with proper deduplication
                        this.mergeLogs(data.logs);

                        // Update pagination if total changed
                        if (data.pagination?.total !== this.total) {
                            this.total = data.pagination.total;
                            this.totalPages = data.pagination.total_pages;
                            if (odcmIsDebug()) { console.log(`ODCM: Updated pagination - Total: ${this.total}, Pages: ${this.totalPages}`); }
                        }
                    }

                    this.lastFetchTime = new Date().toISOString();
                } else {
                    console.warn(`ODCM: Auto-refresh API error: ${response.status} ${response.statusText}`);
                }
            } catch (error) {
                console.warn('ODCM: Auto-refresh failed:', error);
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

            // Schedule individual animation cleanup (more efficient than bulk update)
            logsWithAnimation.forEach(log => {
                setTimeout(() => {
                    this.removeNewFlag(log.animationId);
                }, 600);
            });
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
        // UI AND LAYOUT HELPERS
        // =================================================================
        get dashboardClasses() {
            const classes = [];
            if (this.filterPaneVisible) classes.push('filter-pane-visible');
            if (this.selectedLog) classes.push('details-pane-visible');
            if (this.detailPaneExpanded) classes.push('detail-pane-expanded');
            return classes.join(' ');
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
                if (typeof window !== 'undefined' && window.ODCMToasts && typeof window.ODCMToasts.addToast === 'function') {
                    window.ODCMToasts.addToast(message, type);
                } else {
                    if (type === 'error') {
                        console.error(message);
                    } else if (odcmIsDebug()) {
                        console.log(message);
                    }
                }
            } catch (e) {
                if (type === 'error') {
                    console.error(message);
                } else if (odcmIsDebug()) {
                    console.log(message);
                }
            }
        },
        removeToast(id) {
            if (typeof window !== 'undefined' && window.ODCMToasts && typeof window.ODCMToasts.removeToast === 'function') {
                window.ODCMToasts.removeToast(id);
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

            // Store the count before we clear the array
            const selectedCount = this.selectedLogIds.length;
            const selectedIds = [...this.selectedLogIds]; // Create a copy for the request

            this.isDeleting = true;

            // Debug logging
            console.log('ODCM: Starting batch delete:', {
                selectedCount,
                selectedIds,
                apiUrl: this.config.apiUrl,
                nonce: this.config.nonce ? 'present' : 'missing'
            });

            try {
                const requestUrl = `${this.config.apiUrl}batch-delete/`;
                const requestBody = JSON.stringify({ log_ids: selectedIds });

                console.log('ODCM: Making DELETE request to:', requestUrl);
                console.log('ODCM: Request body:', requestBody);

                const response = await fetch(requestUrl, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.nonce
                    },
                    body: requestBody
                });

                console.log('ODCM: Response status:', response.status);
                console.log('ODCM: Response headers:', Object.fromEntries(response.headers.entries()));

                // Try to get response text first to see what we're actually getting
                const responseText = await response.text();
                console.log('ODCM: Raw response text:', responseText);

                if (!response.ok) {
                    // Handle specific error cases
                    let errorMessage = 'Failed to delete selected logs';
                    if (response.status === 403) {
                        errorMessage = 'Permission denied - you do not have sufficient privileges to delete logs';
                    } else if (response.status === 404) {
                        errorMessage = 'Selected logs not found or already deleted';
                    } else if (response.status === 500) {
                        errorMessage = 'Server error occurred while deleting logs';
                    } else {
                        errorMessage = `Failed to delete logs (HTTP ${response.status}): ${responseText}`;
                    }
                    throw new Error(errorMessage);
                }

                // Parse the response
                let data;
                try {
                    data = JSON.parse(responseText);
                    console.log('ODCM: Parsed response data:', data);
                } catch (parseError) {
                    console.error('ODCM: Failed to parse response as JSON:', parseError);
                    throw new Error(`Invalid JSON response: ${responseText}`);
                }

                if (data && data.success) {
                    const toDelete = new Set(selectedIds);
                    this.logs = this.logs.filter(l => !toDelete.has(l.id));
                    this.selectedLogIds = [];
                    this.selectAll = false;

                    // Use the message from the server response if available
                    const successMessage = data.message || `Successfully deleted ${data.deleted_count || selectedCount} log entries`;
                    this.showToast(successMessage, 'success');

                    console.log('ODCM: Batch delete completed successfully:', {
                        deleted_count: data.deleted_count,
                        requested_count: data.requested_count,
                        selected_count: selectedCount
                    });
                } else {
                    console.error('ODCM: Server returned unsuccessful response:', data);
                    throw new Error(data.message || 'Unexpected response format');
                }
            } catch (e) {
                console.error('ODCM: Batch delete error:', e);
                console.error('ODCM: Error stack:', e.stack);
                this.showToast(e.message || 'Failed to delete selected logs', 'error');
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
        // DETAIL PANE
        // =================================================================
        async selectLog(log) {
            this.selectedLog = log;
            this.detailLoading = true;
            this.detailHtml = await this.fetchLogDetails(log.id);
            this.detailLoading = false;
            this.$nextTick(() => {
                const detailPane = document.querySelector('.odcm-detail-content');
                if (detailPane) this.highlightCodeBlocks(detailPane);
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
