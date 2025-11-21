/**
 * Order Daemon Diagnostics Dashboard JavaScript (Plan B - Unified Report)
 *
 * Provides AJAX functionality for the unified diagnostic dashboard interface
 * with hero section, loading state, status banner, and unified results.
 */

(function($) {
    'use strict';

    // Unified Diagnostic Dashboard Controller
    const UnifiedDiagnosticDashboard = {

        /**
         * Initialize the unified diagnostic dashboard
         */
        init: function() {
            this.bindEvents();
            this.loadLastRunInfo();
        },

        /**
         * Bind event handlers for the unified interface
         */
        bindEvents: function() {
            // Main hero button - Run full diagnostics
            $(document).on('click', '#run-diagnostics', this.runFullDiagnostics.bind(this));

            // Status banner copy report button
            $(document).on('click', '#copy-report', this.copyReport.bind(this));

            // Advanced options - category buttons
            $(document).on('click', '.odcm-button-group .button[data-category]', this.runCategoryDiagnostics.bind(this));

            // Advanced options - individual test
            $(document).on('click', '#run-individual', this.runIndividualTest.bind(this));
        },

        /**
         * Load last run information from localStorage
         */
        loadLastRunInfo: function() {
            const lastRun = localStorage.getItem('odcm_last_diagnostic_run');
            if (lastRun) {
                const runData = JSON.parse(lastRun);
                $('#last-run-time').text(this.formatRelativeTime(runData.timestamp));
                $('#status-summary').text(`${runData.passed}/${runData.total} ${odcmDiagnostics.strings.passed}`);
            }
        },

        /**
         * Run full diagnostic tests (main hero button)
         */
        runFullDiagnostics: function(e) {
            e.preventDefault();
            
            this.showLoadingState();
            this.hideStatusBanner();
            this.hideUnifiedResults();

            // Start the diagnostic process
            this.startDiagnosticProcess('all');
        },

        /**
         * Run diagnostics for a specific category
         */
        runCategoryDiagnostics: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const category = button.data('category');
            const originalText = button.text();

            this.setButtonLoading(button, odcmDiagnostics.strings.buttonRunning);
            this.showLoadingState();

            this.startDiagnosticProcess(category).always(() => {
                this.setButtonLoading(button, originalText, false);
            });
        },

        /**
         * Run an individual test
         */
        runIndividualTest: function(e) {
            e.preventDefault();
            
            const diagnostic = $('#individual-test-select').val();
            if (!diagnostic) {
                this.showToast(odcmDiagnostics.strings.selectTest, 'warning');
                return;
            }

            const button = $(e.currentTarget);
            const originalText = button.text();
            this.setButtonLoading(button, odcmDiagnostics.strings.buttonRunning);

            this.makeAjaxRequest({
                action: 'odcm_run_single_diagnostic',
                diagnostic: diagnostic
            }).done((response) => {
                this.handleSingleTestResult(response.data);
                this.showToast(odcmDiagnostics.strings.testCompleted.replace('%s', response.data.result.name), 'success');
            }).fail((xhr) => {
                const error = xhr.responseJSON?.data?.message || odcmDiagnostics.strings.failedRunTest;
                this.showToast(error, 'error');
            }).always(() => {
                this.setButtonLoading(button, originalText, false);
            });
        },

        /**
         * Start diagnostic process with progress tracking
         */
        startDiagnosticProcess: function(category = 'all') {
            // Initialize progress
            this.updateProgress(0, 8, odcmDiagnostics.strings.preparingTests);

            return this.makeAjaxRequest({
                action: 'odcm_run_diagnostics',
                category: category
            }).done((response) => {
                this.handleDiagnosticResults(response.data);
            }).fail((xhr) => {
                const error = xhr.responseJSON?.data?.message || odcmDiagnostics.strings.failedRunDiagnostics;
                this.showError(error);
            }).always(() => {
                this.hideLoadingState();
            });
        },

        /**
         * Handle comprehensive diagnostic results
         */
        handleDiagnosticResults: function(data) {
            // DEBUG: Log the received data structure
            console.log('DEBUG handleDiagnosticResults: Received data structure:', {
                hasHtml: !!data.html,
                htmlLength: data.html ? data.html.length : 0,
                htmlPreview: data.html ? data.html.substring(0, 200) + '...' : 'No HTML',
                hasReport: !!data.report,
                reportCategories: data.report?.categories ? Object.keys(data.report.categories) : 'None'
            });
            
            // Update progress to completion
            this.updateProgress(100, 8, odcmDiagnostics.strings.testsCompleted);

            // Hide loading and show results
            setTimeout(() => {
                this.hideLoadingState();
                this.showStatusBanner(data.report);
                this.showUnifiedResults(data.html, data.report);
                this.updateLastRunInfo(data.report);
                this.showToast('Diagnostic tests completed successfully', 'success');
            }, 500);
        },

        /**
         * Handle single test result
         */
        handleSingleTestResult: function(data) {
            // Use the pre-rendered HTML from backend
            $('#odcm-results-content').html(data.html);
            this.showUnifiedResults(data.html, null, false);
        },

        /**
         * Show loading state with progress
         */
        showLoadingState: function() {
            $('#loading-state').show();
            $('.odcm-diagnostic-hero, #status-banner, #unified-results').hide();
            
            // Simulate progress updates
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += Math.random() * 20;
                if (progress >= 90) {
                    progress = 90;
                    clearInterval(progressInterval);
                }
                this.updateProgress(progress, 8, odcmDiagnostics.strings.runningTests);
            }, 300);

            // Store interval for cleanup
            this.progressInterval = progressInterval;
        },

        /**
         * Hide loading state
         */
        hideLoadingState: function() {
            $('#loading-state').hide();
            $('.odcm-diagnostic-hero').show();
            
            if (this.progressInterval) {
                clearInterval(this.progressInterval);
                this.progressInterval = null;
            }
        },

        /**
         * Update progress bar and text
         */
        updateProgress: function(percentage, totalTests, currentTest) {
            const completed = Math.floor((percentage / 100) * totalTests);
            $('#progress-bar').css('width', `${Math.min(percentage, 100)}%`);
            $('#progress-text').text(`${completed}/${totalTests} tests`);
            $('#current-test').text(currentTest);
        },

        /**
         * Show status banner with results
         */
        showStatusBanner: function(report) {
            const banner = $('#status-banner');
            const summary = report.summary;
            
            // Determine status
            let status = 'success';
            let statusText = odcmDiagnostics.strings.systemHealthy;
            let statusIcon = '✅';

            if (summary.failed > 0) {
                const hasCritical = report.critical_issues && report.critical_issues.length > 0;
                status = hasCritical ? 'error' : 'warning';
                statusText = hasCritical ? odcmDiagnostics.strings.issuesDetected : odcmDiagnostics.strings.warningsFound;
                statusIcon = hasCritical ? '❌' : '⚠️';
            }

            // Update banner content
            banner.removeClass('odcm-status-banner--success odcm-status-banner--warning odcm-status-banner--error')
                  .addClass(`odcm-status-banner--${status}`);
            
            $('#banner-status-icon').text(statusIcon);
            $('#banner-status-text').text(statusText);
            $('#banner-status-summary').text(`${summary.passed} passed, ${summary.failed} failed`);

            // Store report data for copy functionality
            banner.data('report', report);
            banner.show();
        },

        /**
         * Hide status banner
         */
        hideStatusBanner: function() {
            $('#status-banner').hide();
        },

        /**
         * Show unified results container
         */
        showUnifiedResults: function(html, report, updateTimestamp = true) {
            const container = $('#unified-results');
            
            // DEBUG: Log HTML content being inserted
            console.log('DEBUG showUnifiedResults: HTML content details:', {
                htmlLength: html ? html.length : 0,
                containsTreeConnectors: html ? html.includes('odcm-detail-tree-connector') : false,
                containsTechnicalInfo: html ? html.includes('odcm-technical-info') : false,
                containsDetailItems: html ? html.includes('odcm-detail-item') : false,
                htmlSnippet: html ? html.substring(0, 500) + '...' : 'No HTML'
            });
            
            if (updateTimestamp) {
                const now = new Date();
                const timestamp = now.toLocaleString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                });
                $('#results-timestamp').text(`Executed: ${timestamp}`);
            }

            // Use the pre-rendered HTML directly from backend instead of reconstructing it
            const resultsContent = $('#odcm-results-content');
            resultsContent.html(html);
            
            // Trigger Prism.js syntax highlighting
            if (typeof Prism !== 'undefined') {
                Prism.highlightAll();
            }
            
            // DEBUG: Verify DOM insertion worked
            const insertedContent = resultsContent.html();
            console.log('DEBUG showUnifiedResults: DOM insertion verification:', {
                contentInserted: insertedContent.length > 0,
                treeConnectorsInDOM: $('.odcm-detail-tree-connector').length,
                technicalInfoInDOM: $('.odcm-technical-info').length,
                detailItemsInDOM: $('.odcm-detail-item').length
            });
            
            container.show();
        },

        /**
         * Hide unified results container
         */
        hideUnifiedResults: function() {
            $('#unified-results').hide();
        },

        /**
         * Format HTML results for unified display
         */
        formatUnifiedResults: function(html, report) {
            if (!report) return html;

            let formattedHtml = '';

            // Add categories with proper styling
            if (report.categories) {
                Object.entries(report.categories).forEach(([categoryName, categoryData]) => {
                    formattedHtml += `
                        <div class="odcm-results-category">
                            <h3 class="odcm-category-title">${this.formatCategoryName(categoryName)} Diagnostics</h3>
                            ${this.formatCategoryTests(categoryData.tests)}
                        </div>
                    `;

                    // Add category divider except for last category
                    const categories = Object.keys(report.categories);
                    if (categoryName !== categories[categories.length - 1]) {
                        formattedHtml += '<div class="odcm-category-divider"></div>';
                    }
                });
            }

            return formattedHtml || html;
        },

        /**
         * Format category tests for unified display
         */
        formatCategoryTests: function(tests) {
            let testsHtml = '';
            
            Object.entries(tests).forEach(([testKey, testResult]) => {
                // Determine status based on the status field from DiagnosticRunner report
                let statusClass = 'error';
                let statusIcon = '❌';
                
                // Check for different possible status values from PHP
                const status = testResult.status?.toLowerCase();
                
                if (status === 'success' || status === 'passed' || testResult.successful === true) {
                    // Successful test - check if it has recommendations (warning)
                    if (testResult.recommendations && testResult.recommendations.length > 0) {
                        statusClass = 'warning';
                        statusIcon = '⚠️';
                    } else {
                        statusClass = 'success';
                        statusIcon = '✅';
                    }
                } else if (status === 'warning') {
                    statusClass = 'warning';
                    statusIcon = '⚠️';
                } else if (status === 'error' || status === 'failed' || testResult.successful === false) {
                    // Failed test - determine severity based on message content
                    const message = testResult.message.toLowerCase();
                    if (message.includes('critical') || message.includes('fatal')) {
                        statusClass = 'error';
                        statusIcon = '🔴';
                    } else if (message.includes('warning') || message.includes('recommend')) {
                        statusClass = 'warning';
                        statusIcon = '⚠️';
                    } else {
                        statusClass = 'error';
                        statusIcon = '❌';
                    }
                } else {
                    // Fallback: try to determine from other properties
                    if (testResult.message) {
                        const message = testResult.message.toLowerCase();
                        if (message.includes('success') || message.includes('ok') || message.includes('passed')) {
                            statusClass = 'success';
                            statusIcon = '✅';
                        } else if (message.includes('warning') || message.includes('recommend')) {
                            statusClass = 'warning';
                            statusIcon = '⚠️';
                        }
                    }
                }
                
                testsHtml += `
                    <div class="odcm-test-result odcm-test-result--${statusClass}">
                        <div class="odcm-test-result-header">
                            <span class="odcm-test-icon">${statusIcon}</span>
                            <h4 class="odcm-test-name">${testResult.name}</h4>
                        </div>
                        <p class="odcm-test-message">${testResult.message}</p>
                        ${this.formatRecommendations(testResult.recommendations)}
                        ${this.formatTechnicalDetails(testResult.details)}
                    </div>
                `;
            });

            return testsHtml;
        },

        /**
         * Format recommendations
         */
        formatRecommendations: function(recommendations) {
            if (!recommendations || recommendations.length === 0) return '';
            
            const recList = recommendations.map(rec => `<li>${rec}</li>`).join('');
            return `
                <div class="odcm-test-recommendations">
                    <strong>💡 Recommendations:</strong>
                    <ul>${recList}</ul>
                </div>
            `;
        },

        /**
         * Format technical details
         */
        formatTechnicalDetails: function(details) {
            if (!details || Object.keys(details).length === 0) return '';

            const detailItems = Object.entries(details)
                .filter(([key, value]) => typeof value === 'string' || typeof value === 'number')
                .map(([key, value]) => `${key}: ${value}`)
                .join(' | ');

            return `<div class="odcm-test-technical">📊 Details: ${detailItems}</div>`;
        },

        /**
         * Create single test result HTML
         */
        createSingleTestResultHtml: function(result) {
            const statusIcon = result.successful ? '✅' : '❌';
            const statusClass = result.successful ? 'success' : 'error';
            
            return `
                <div class="odcm-results-category">
                    <h3 class="odcm-category-title">Individual Test Result</h3>
                    <div class="odcm-test-result odcm-test-result--${statusClass}">
                        <div class="odcm-test-result-header">
                            <span class="odcm-test-icon">${statusIcon}</span>
                            <h4 class="odcm-test-name">${result.name}</h4>
                        </div>
                        <p class="odcm-test-message">${result.message}</p>
                        ${this.formatRecommendations(result.recommendations)}
                        ${this.formatTechnicalDetails(result.details)}
                    </div>
                </div>
            `;
        },

        /**
         * Copy diagnostic report to clipboard
         */
        copyReport: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const originalHtml = button.html();
            
            // Set copying state
            button.html('<span class="dashicons dashicons-update-alt"></span> Copying...');

            // Generate and copy report
            this.makeAjaxRequest({
                action: 'odcm_generate_dual_report'
            }).done((response) => {
                this.copyToClipboard(response.data.report)
                    .then(() => {
                        button.html('<span class="dashicons dashicons-yes-alt"></span> Copied!');
                        this.showToast('Diagnostic report copied to clipboard!', 'success');
                        
                        setTimeout(() => {
                            button.html(originalHtml);
                        }, 3000);
                    })
                    .catch(() => {
                        button.html(originalHtml);
                        this.showCopyFallback(response.data.report);
                    });
            }).fail((xhr) => {
                button.html(originalHtml);
                const error = xhr.responseJSON?.data?.message || 'Failed to generate report';
                this.showToast(error, 'error');
            });
        },

        /**
         * Copy text to clipboard
         */
        copyToClipboard: function(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            
            return new Promise((resolve, reject) => {
                try {
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    textArea.style.position = 'fixed';
                    textArea.style.top = '-9999px';
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    
                    if (document.execCommand('copy')) {
                        resolve();
                    } else {
                        reject(new Error('execCommand failed'));
                    }
                    
                    document.body.removeChild(textArea);
                } catch (error) {
                    reject(error);
                }
            });
        },

        /**
         * Show manual copy fallback
         */
        showCopyFallback: function(text) {
            // Create temporary modal or just show toast for now
            this.showToast('Could not copy automatically. Report generated in console.', 'warning');
            console.log('=== DIAGNOSTIC REPORT ===\n', text);
        },

        /**
         * Update last run information
         */
        updateLastRunInfo: function(report) {
            const runData = {
                timestamp: Date.now(),
                passed: report.summary.passed,
                failed: report.summary.failed,
                total: report.summary.total_tests
            };
            
            localStorage.setItem('odcm_last_diagnostic_run', JSON.stringify(runData));
            
            // Update hero section
            $('#last-run-time').text(odcmDiagnostics.strings.justNow);
            $('#status-summary').text(`${runData.passed}/${runData.total} ${odcmDiagnostics.strings.passed}`);
        },

        /**
         * Show error state
         */
        showError: function(message) {
            this.hideLoadingState();
            this.showToast(message, 'error');
            
            // Show error in results
            const errorHtml = `
                <div class="odcm-results-category">
                    <h3 class="odcm-category-title">Error</h3>
                    <div class="odcm-test-result odcm-test-result--error">
                        <div class="odcm-test-result-header">
                            <span class="odcm-test-icon">❌</span>
                            <h4 class="odcm-test-name">Diagnostic Error</h4>
                        </div>
                        <p class="odcm-test-message">${message}</p>
                        <div class="odcm-test-recommendations">
                            <strong>💡 Recommendations:</strong>
                            <ul>
                                <li>Try refreshing the page and running again</li>
                                <li>Check browser console for additional errors</li>
                                <li>Contact support if the issue persists</li>
                            </ul>
                        </div>
                    </div>
                </div>
            `;
            
            this.showUnifiedResults(errorHtml, null, true);
        },

        /**
         * Utility: Set button loading state
         */
        setButtonLoading: function(button, text, loading = true) {
            if (loading) {
                button.prop('disabled', true).addClass('odcm-loading').html(`<span class="dashicons dashicons-update-alt"></span> ${text}`);
            } else {
                button.prop('disabled', false).removeClass('odcm-loading').html(text);
            }
        },

        /**
         * Utility: Make AJAX request
         */
        makeAjaxRequest: function(data) {
            return $.ajax({
                url: odcmDiagnostics.ajaxUrl,
                type: 'POST',
                data: {
                    ...data,
                    nonce: odcmDiagnostics.nonce
                },
                timeout: 30000
            });
        },

        /**
         * Utility: Show toast notification
         */
        showToast: function(message, type = 'info') {
            if (window.ODCMToasts && typeof window.ODCMToasts.show === 'function') {
                window.ODCMToasts.show(message, type);
                return;
            }

            // Fallback notification
            const notification = $(`
                <div class="odcm-toast odcm-toast-${type}" style="
                    position: fixed;
                    top: 32px;
                    right: 20px;
                    background: ${type === 'success' ? '#46b450' : type === 'error' ? '#dc3232' : '#0073aa'};
                    color: white;
                    padding: 12px 16px;
                    border-radius: 4px;
                    z-index: 9999;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                ">
                    ${message}
                </div>
            `);
            
            $('body').append(notification);
            setTimeout(() => notification.fadeOut(() => notification.remove()), 4000);
        },

        /**
         * Utility: Format category name
         */
        formatCategoryName: function(category) {
            return category.charAt(0).toUpperCase() + category.slice(1);
        },

        /**
         * Utility: Format relative time
         */
        formatRelativeTime: function(timestamp) {
            const now = Date.now();
            const diff = now - timestamp;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);

            if (days > 0) {
                return days > 1 ? 
                    odcmDiagnostics.strings.daysAgo.replace('%d', days) : 
                    odcmDiagnostics.strings.dayAgo.replace('%d', days);
            }
            if (hours > 0) {
                return hours > 1 ? 
                    odcmDiagnostics.strings.hoursAgo.replace('%d', hours) : 
                    odcmDiagnostics.strings.hourAgo.replace('%d', hours);
            }
            if (minutes > 0) {
                return minutes > 1 ? 
                    odcmDiagnostics.strings.minutesAgo.replace('%d', minutes) : 
                    odcmDiagnostics.strings.minuteAgo.replace('%d', minutes);
            }
            return odcmDiagnostics.strings.justNow;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('.odcm-diagnostics-unified').length > 0) {
            UnifiedDiagnosticDashboard.init();
        }
    });

    // Export for global access
    window.UnifiedDiagnosticDashboard = UnifiedDiagnosticDashboard;

})(jQuery);
