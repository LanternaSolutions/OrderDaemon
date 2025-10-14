/**
 * Order Daemon Diagnostics Dashboard JavaScript
 *
 * Provides AJAX functionality for running diagnostic tests and displaying results.
 * Handles user interactions with the diagnostic dashboard interface.
 */

(function($) {
    'use strict';

    // Diagnostic Dashboard Controller
    const DiagnosticDashboard = {

        /**
         * Initialize the diagnostic dashboard
         */
        init: function() {
            this.bindEvents();
            this.updateButtonStates();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Run all diagnostics
            $('#run-all-diagnostics').on('click', this.runAllDiagnostics.bind(this));

            // Run critical diagnostics only
            $('#run-critical-diagnostics').on('click', this.runCriticalDiagnostics.bind(this));

            // Run diagnostics by category
            $('.run-category').on('click', this.runCategoryDiagnostics.bind(this));

            // Run single diagnostic
            $('.run-single').on('click', this.runSingleDiagnostic.bind(this));
        },

        /**
         * Update button states based on current conditions
         */
        updateButtonStates: function() {
            // Enable all buttons initially
            $('.button').prop('disabled', false);
        },

        /**
         * Run all diagnostic tests
         */
        runAllDiagnostics: function(e) {
            e.preventDefault();

            const button = $(e.currentTarget);
            const originalText = button.text();

            this.setButtonLoading(button, odcmDiagnostics.strings.running);
            this.updateResultsPanel('<p>Running all diagnostic tests...</p>');

            this.makeAjaxRequest({
                action: 'odcm_run_diagnostics',
                category: 'all'
            }).done((response) => {
                this.handleDiagnosticResults(response.data);
                this.showToast(odcmDiagnostics.strings.success, 'success');
            }).fail((xhr) => {
                const error = xhr.responseJSON?.data?.message || odcmDiagnostics.strings.error;
                this.showToast(error, 'error');
                this.updateResultsPanel('<p class="odcm-error">Failed to run diagnostics: ' + error + '</p>');
            }).always(() => {
                this.setButtonLoading(button, originalText, false);
            });
        },

        /**
         * Run critical diagnostics only
         */
        runCriticalDiagnostics: function(e) {
            e.preventDefault();

            const button = $(e.currentTarget);
            const originalText = button.text();

            this.setButtonLoading(button, 'Running critical tests...');
            this.updateResultsPanel('<p>Running critical diagnostic tests...</p>');

            this.makeAjaxRequest({
                action: 'odcm_run_diagnostics',
                category: 'critical'
            }).done((response) => {
                this.handleDiagnosticResults(response.data);
                this.showToast('Critical diagnostics completed', 'success');
            }).fail((xhr) => {
                const error = xhr.responseJSON?.data?.message || odcmDiagnostics.strings.error;
                this.showToast(error, 'error');
                this.updateResultsPanel('<p class="odcm-error">Failed to run critical diagnostics: ' + error + '</p>');
            }).always(() => {
                this.setButtonLoading(button, originalText, false);
            });
        },

        /**
         * Run diagnostics for a specific category
         */
        runCategoryDiagnostics: function(e) {
            e.preventDefault();

            const button = $(e.currentTarget);
            const category = button.data('category');
            const originalText = button.text();

            this.setButtonLoading(button, 'Running...');

            this.makeAjaxRequest({
                action: 'odcm_run_diagnostics',
                category: category
            }).done((response) => {
                this.handleDiagnosticResults(response.data);
                this.showToast(`${category} diagnostics completed`, 'success');
            }).fail((xhr) => {
                const error = xhr.responseJSON?.data?.message || odcmDiagnostics.strings.error;
                this.showToast(error, 'error');
            }).always(() => {
                this.setButtonLoading(button, originalText, false);
            });
        },

        /**
         * Run a single diagnostic test
         */
        runSingleDiagnostic: function(e) {
            e.preventDefault();

            const button = $(e.currentTarget);
            const diagnostic = button.data('diagnostic');
            const originalText = button.text();
            const resultContainer = button.closest('.odcm-diagnostic-item').find('.odcm-diagnostic-result');

            this.setButtonLoading(button, 'Running...');
            resultContainer.hide();

            this.makeAjaxRequest({
                action: 'odcm_run_single_diagnostic',
                diagnostic: diagnostic
            }).done((response) => {
                const result = response.data;
                resultContainer.html(result.html).show();

                // Update button based on result
                const statusClass = result.result.successful ? 'odcm-success' : 'odcm-error';
                button.removeClass('odcm-success odcm-error').addClass(statusClass);

                this.showToast(`Test "${result.result.name}" completed`,
                    result.result.successful ? 'success' : 'warning');

            }).fail((xhr) => {
                const error = xhr.responseJSON?.data?.message || odcmDiagnostics.strings.error;
                resultContainer.html(`<p class="odcm-error">Failed to run test: ${error}</p>`).show();
                this.showToast(error, 'error');
            }).always(() => {
                this.setButtonLoading(button, originalText, false);
            });
        },

        /**
         * Handle comprehensive diagnostic results
         */
        handleDiagnosticResults: function(data) {
            if (data.html) {
                this.updateResultsPanel(data.html);
            }

            if (data.report) {
                this.updateHealthStatus(data.report);
                this.updateIndividualResults(data.results);
            }
        },

        /**
         * Update the health status display
         */
        updateHealthStatus: function(report) {
            const healthContainer = $('.odcm-health-status');
            const summary = report.summary;

            // Determine overall status
            let status = 'healthy';
            if (summary.failed > 0) {
                status = report.critical_issues && report.critical_issues.length > 0 ? 'critical' : 'warning';
            }

            // Update status classes
            healthContainer.removeClass('odcm-status-healthy odcm-status-warning odcm-status-critical')
                           .addClass(`odcm-status-${status}`);

            // Update status text
            healthContainer.find('h2').text(`System Health: ${status.charAt(0).toUpperCase() + status.slice(1)}`);
            healthContainer.find('p').first().text(`${summary.failed} issues found (${report.critical_issues ? report.critical_issues.length : 0} critical)`);
        },

        /**
         * Update individual diagnostic result displays
         */
        updateIndividualResults: function(results) {
            Object.keys(results).forEach(key => {
                const result = results[key];
                const item = $(`.odcm-diagnostic-item[data-diagnostic="${key}"]`);
                const button = item.find('.run-single');
                const resultContainer = item.find('.odcm-diagnostic-result');

                // Update button status
                const statusClass = result.successful ? 'odcm-success' : 'odcm-error';
                button.removeClass('odcm-success odcm-error').addClass(statusClass);

                // Create result HTML
                const resultHtml = this.createSingleResultHtml(result);
                resultContainer.html(resultHtml).show();
            });
        },

        /**
         * Create HTML for a single diagnostic result
         */
        createSingleResultHtml: function(result) {
            const statusClass = result.successful ? 'odcm-success' : 'odcm-error';
            let html = `<div class="odcm-single-result ${statusClass}">`;
            html += `<p><strong>${this.escapeHtml(result.message)}</strong></p>`;

            if (result.recommendations && result.recommendations.length > 0) {
                html += '<div class="odcm-recommendations"><strong>Recommendations:</strong><ul>';
                result.recommendations.forEach(rec => {
                    html += `<li>${this.escapeHtml(rec)}</li>`;
                });
                html += '</ul></div>';
            }

            if (result.details && Object.keys(result.details).length > 0) {
                html += '<details><summary>Technical Details</summary>';
                html += `<pre>${this.escapeHtml(JSON.stringify(result.details, null, 2))}</pre>`;
                html += '</details>';
            }

            html += '</div>';
            return html;
        },

        /**
         * Update the main results panel
         */
        updateResultsPanel: function(html) {
            $('#odcm-results-content').html(html);
        },

        /**
         * Set button loading state
         */
        setButtonLoading: function(button, text, loading = true) {
            if (loading) {
                button.prop('disabled', true).addClass('odcm-loading').text(text);
            } else {
                button.prop('disabled', false).removeClass('odcm-loading').text(text);
            }
        },

        /**
         * Make AJAX request with proper nonce and error handling
         */
        makeAjaxRequest: function(data) {
            return $.ajax({
                url: odcmDiagnostics.ajaxUrl,
                type: 'POST',
                data: {
                    ...data,
                    nonce: odcmDiagnostics.nonce
                },
                timeout: 30000 // 30 second timeout for diagnostic tests
            });
        },

        /**
         * Show toast notification (using Order Daemon toast system if available)
         */
        showToast: function(message, type = 'info') {
            // Try to use Order Daemon toast system first
            if (window.ODCMToasts && typeof window.ODCMToasts.show === 'function') {
                window.ODCMToasts.show(message, type);
                return;
            }

            // Fallback to simple alert or console log
            if (type === 'error') {
                alert('Error: ' + message);
            } else {
                console.log(`[${type.toUpperCase()}] ${message}`);
            }
        },

        /**
         * Escape HTML for safe display
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize on the diagnostic page
        if ($('#odcm-results-content').length > 0) {
            DiagnosticDashboard.init();
        }
    });

    // Export for global access if needed
    window.DiagnosticDashboard = DiagnosticDashboard;

})(jQuery);
