/**
 * Admin JavaScript for Order Daemon For Woocommerce
 */
(function($) {
    'use strict';

    /**
     * Initialize the meta box functionality.
     */
    function initMetaBox() {
        // Function to switch to a specific tab
        function switchToTab(tabId) {
            // Update active tab
            $('.odcm-tab-nav li').removeClass('odcm-tab-active');
            $('.odcm-tab-nav li[data-tab="' + tabId + '"]').addClass('odcm-tab-active');

            // Show the selected tab content
            $('.odcm-tab-pane').removeClass('odcm-tab-active');
            $('#odcm-tab-' + tabId).addClass('odcm-tab-active');

            // Update URL hash without triggering a page jump
            if (window.location.hash !== '#' + tabId) {
                history.pushState(null, null, '#' + tabId);
            }
        }

        // Tab switching - Prevent default action and add debugging
        $('.odcm-tab-nav li').on('click', function(e) {
            e.preventDefault();
            var tabId = $(this).data('tab');
            console.log('Tab clicked: ' + tabId);
            switchToTab(tabId);
        });

        // Function to check for hash in URL and switch to the corresponding tab
        function checkHashAndSwitchTab() {
            var hash = window.location.hash;
            if (hash) {
                // Remove the # character
                var tabId = hash.substring(1);

                // Check if this is a valid tab
                if ($('.odcm-tab-nav li[data-tab="' + tabId + '"]').length) {
                    switchToTab(tabId);
                }
            } else {
                // If no hash is present, default to the first tab
                var firstTab = $('.odcm-tab-nav li:first');
                if (firstTab.length) {
                    switchToTab(firstTab.data('tab'));
                }
            }
        }

        // Check for hash in URL on document ready (immediate check)
        checkHashAndSwitchTab();

        // Listen for hash changes to handle back/forward navigation
        $(window).on('hashchange', checkHashAndSwitchTab);

        // Make sure tabs are properly initialized on page load
        $(window).on('load', function() {
            // If no tab is active, activate the first one
            if ($('.odcm-tab-nav li.odcm-tab-active').length === 0) {
                checkHashAndSwitchTab();
            }
        });

        // Function to update selected radio button styling
        function updateSelectedRadioStyles() {
            // Remove selected class from all radio labels
            $('.odcm-field-group label, .odcm-radio-group label').removeClass('odcm-radio-selected');

            // Add selected class to labels with checked radio buttons
            $('.odcm-field-group input[type="radio"]:checked, .odcm-radio-group input[type="radio"]:checked').each(function() {
                $(this).closest('label').addClass('odcm-radio-selected');
            });
        }

        // Function to update selected checkbox styling
        function updateSelectedCheckboxStyles() {
            // Remove selected class from all checkbox labels
            $('.odcm-checkbox-group label, .odcm-order-total-inputs > label').removeClass('odcm-checkbox-selected');

            // Add selected class to labels with checked checkboxes
            $('.odcm-checkbox-group input[type="checkbox"]:checked, .odcm-order-total-inputs > label > input[type="checkbox"]:checked').each(function() {
                $(this).closest('label').addClass('odcm-checkbox-selected');
            });
        }

        // Update styles on page load
        updateSelectedRadioStyles();
        updateSelectedCheckboxStyles();

        // Update styles when any radio button changes
        $(document).on('change', 'input[type="radio"]', function() {
            updateSelectedRadioStyles();
        });

        // Update styles when any checkbox changes
        $(document).on('change', 'input[type="checkbox"]', function() {
            updateSelectedCheckboxStyles();
        });

        // Primary Condition Type Selector - Updated for row-based structure
        $('input[name="odcm_primary_condition_type"]').on('change', function() {
            // Find the condition row that contains this radio button
            var conditionRow = $(this).closest('.odcm-condition-row');

            // Hide all settings panels within this condition row
            conditionRow.find('.odcm-settings-panel').hide();

            // Get the selected condition type
            var selectedType = $(this).val();

            // Show the correct settings panel within this condition row
            if (selectedType === 'product_type') {
                conditionRow.find('.odcm-product-type-settings').show();
            } else if (selectedType === 'product_category') {
                conditionRow.find('.odcm-product-category-settings').show();
            }

            // Update the hidden field for form submission
            $('#odcm-primary-condition-type-hidden').val(selectedType);
        });

        // Order Total Condition Toggle - Updated for row-based structure
        $('input[name*="[check_order_total]"]').on('change', function() {
            // Find the condition row that contains this checkbox
            var conditionRow = $(this).closest('.odcm-condition-row');

            // Find the order total settings panel within this condition row
            var settingsPanel = conditionRow.find('.odcm-order-total-settings');

            if ($(this).is(':checked')) {
                settingsPanel.show();
            } else {
                settingsPanel.hide();
            }
        });

        // Order Total Comparison Radio Buttons
        $('input[name*="[order_total_compare]"]').on('change', function() {
            // Disable all order total value inputs
            $('.odcm-order-total-value').prop('disabled', true);

            // Enable the input field for the selected comparison type
            var selectedCompare = $(this).val();
            $('input[name*="[order_total_value_' + selectedCompare + ']"]').prop('disabled', false);

            // Update the hidden field with the value from the selected input
            updateOrderTotalHiddenField();
        });

        // Update hidden field when any order total value changes
        $('.odcm-order-total-value').on('input', function() {
            if (!$(this).prop('disabled')) {
                updateOrderTotalHiddenField();
            }
        });

        // Function to update the hidden order total value field
        function updateOrderTotalHiddenField() {
            var selectedCompare = $('input[name*="[order_total_compare]"]:checked').val();
            var value = $('input[name*="[order_total_value_' + selectedCompare + ']"]').val();
            $('#odcm-order-total-value-hidden').val(value);
        }

        // Initialize condition visibility on page load - trigger events to ensure state is set
        // Trigger the change event for primary condition type to show the correct panel
        $('input[name="odcm_primary_condition_type"]:checked').trigger('change');

        // Also trigger other change events for consistent initialization
        $('input[name*="[check_order_total]"]:checked').trigger('change');
        $('input[name*="[order_total_compare]"]:checked').trigger('change');

        // Initialize order total value inputs
        updateOrderTotalHiddenField();
    }

    /**
     * Renders audit log details into a structured HTML format
     * 
     * @param {Object} data - The JSON data object received from the AJAX response
     * @return {string} HTML string representation of the data
     */
    function renderAuditLogDetails(data) {
        // If data is null or undefined, return a message
        if (data === null || data === undefined) {
            return '<div class="odcm-empty-data">No data available</div>';
        }
        
        // Create a definition list for the data
        let html = '<dl class="odcm-log-details-dl">';
        
        // Function to recursively render nested objects and arrays
        function renderValue(value) {
            if (value === null || value === undefined || value === '') {
                return '<span class="odcm-empty-value">N/A</span>';
            } else if (typeof value === 'boolean') {
                return value ? 
                    '<span class="odcm-boolean-value odcm-true">True</span>' : 
                    '<span class="odcm-boolean-value odcm-false">False</span>';
            } else if (typeof value === 'number') {
                return '<span class="odcm-number-value">' + value + '</span>';
            } else if (typeof value === 'string') {
                return '<span class="odcm-string-value">' + value.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
            } else if (Array.isArray(value)) {
                if (value.length === 0) {
                    return '<span class="odcm-empty-value">Empty Array</span>';
                }
                
                let arrayHtml = '<ul class="odcm-array-list">';
                for (let i = 0; i < value.length; i++) {
                    arrayHtml += '<li class="odcm-array-item">' + renderValue(value[i]) + '</li>';
                }
                arrayHtml += '</ul>';
                return arrayHtml;
            } else if (typeof value === 'object') {
                let nestedHtml = '<dl class="odcm-nested-dl">';
                for (let key in value) {
                    if (value.hasOwnProperty(key)) {
                        nestedHtml += '<dt class="odcm-nested-dt">' + key + ':</dt>';
                        nestedHtml += '<dd class="odcm-nested-dd">' + renderValue(value[key]) + '</dd>';
                    }
                }
                nestedHtml += '</dl>';
                return nestedHtml;
            } else {
                return '<span class="odcm-unknown-value">' + String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
            }
        }
        
        // Iterate through the data object and add each key-value pair to the definition list
        for (let key in data) {
            if (data.hasOwnProperty(key)) {
                html += '<dt class="odcm-dt"><strong>' + key + ':</strong></dt>';
                html += '<dd class="odcm-dd">' + renderValue(data[key]) + '</dd>';
            }
        }
        
        html += '</dl>';
        return html;
    }
    
    // Try multiple approaches for toggle links to ensure they work
    // 1. Direct event delegation (original approach)
    $(document).on('click', '.audit-log-toggle-details', function(e) {
        e.preventDefault();
        console.log('Toggle link clicked (delegation)');
        handleToggleClick($(this));
    });

    // Helper function to handle toggle click
    function handleToggleClick($link) {
        var logId = $link.data('log-id');
        var $detailsRow = $('#audit-log-details-row-' + logId);

        console.log('Toggle clicked for log ID: ' + logId);
        console.log('Details row found: ' + ($detailsRow.length > 0));

        if ($detailsRow.length === 0) {
            console.error('Details row not found for log ID: ' + logId);
            // Try to find the row by traversing the DOM
            var $parentRow = $link.closest('tr');
            console.log('Parent row found:', $parentRow.length > 0);
            if ($parentRow.length > 0) {
                var $nextRow = $parentRow.next('tr');
                console.log('Next row found:', $nextRow.length > 0);
                if ($nextRow.length > 0 && $nextRow.hasClass('audit-log-details-row')) {
                    console.log('Found details row by DOM traversal');
                    $detailsRow = $nextRow;
                }
            }

            // If we still can't find the details row, try to find it by class and data attribute
            if ($detailsRow.length === 0) {
                $detailsRow = $('.audit-log-details-row[data-log-id="' + logId + '"]');
                console.log('Found details row by class and data attribute:', $detailsRow.length > 0);
            }

            // If we still can't find the details row, give up
            if ($detailsRow.length === 0) {
                console.error('Could not find details row by any method');
                return;
            }
        }

        // Check if the button is currently expanded
        var isExpanded = $link.hasClass('details-expanded');
        console.log('Button is currently expanded:', isExpanded);

        if (isExpanded) {
            console.log('Collapsing details row');

            // Update button state
            $link.attr('aria-expanded', 'false');
            $link.text('View Details');
            $link.removeClass('details-expanded');

            // Directly control the visibility of the details row
            $detailsRow.hide();
            $detailsRow.removeClass('details-visible');
        } else {
            console.log('Expanding details row');

            // Update button state
            $link.attr('aria-expanded', 'true');
            $link.text('Hide Details');
            $link.addClass('details-expanded');

            // Directly control the visibility of the details row
            $detailsRow.show();
            $detailsRow.addClass('details-visible');
        }
    }

    // Function to expand all log details
    function expandAllLogDetails() {
        console.log('Expanding all log details');

        // Update all toggle buttons and show all details rows
        var $toggleButtons = $('.audit-log-toggle-details');
        var $detailsRows = $('.audit-log-details-row');

        // 1. jQuery approach for buttons
        $toggleButtons.attr('aria-expanded', 'true').text('Hide Details');
        $toggleButtons.addClass('details-expanded');

        // 2. Direct DOM manipulation for better compatibility
        $toggleButtons.each(function() {
            this.setAttribute('aria-expanded', 'true');
            this.textContent = 'Hide Details';
            this.classList.add('details-expanded');

            // 3. Update onclick attribute to ensure it works with the new state
            var $button = $(this);
            var logId = $button.data('log-id');
            if (logId) {
                // Make sure the button still triggers the toggle event when clicked
                $button.attr('onclick', 'jQuery(this).trigger(\'odcm_toggle_details\'); return false;');
            }
        });

        // 4. Show all details rows
        $detailsRows.show();
        $detailsRows.addClass('details-visible');

        // 5. Trigger a custom event to notify any other code that details have been expanded
        $(document).trigger('odcm_details_expanded');
    }

    // Function to collapse all log details
    function collapseAllLogDetails() {
        console.log('Collapsing all log details');

        // Update all toggle buttons and hide all details rows
        var $toggleButtons = $('.audit-log-toggle-details');
        var $detailsRows = $('.audit-log-details-row');

        // 1. jQuery approach for buttons
        $toggleButtons.attr('aria-expanded', 'false').text('View Details');
        $toggleButtons.removeClass('details-expanded');

        // 2. Direct DOM manipulation for better compatibility
        $toggleButtons.each(function() {
            this.setAttribute('aria-expanded', 'false');
            this.textContent = 'View Details';
            this.classList.remove('details-expanded');

            // 3. Update onclick attribute to ensure it works with the new state
            var $button = $(this);
            var logId = $button.data('log-id');
            if (logId) {
                // Make sure the button still triggers the toggle event when clicked
                $button.attr('onclick', 'jQuery(this).trigger(\'odcm_toggle_details\'); return false;');
            }
        });

        // 4. Hide all details rows
        $detailsRows.hide();
        $detailsRows.removeClass('details-visible');

        // 5. Trigger a custom event to notify any other code that details have been collapsed
        $(document).trigger('odcm_details_collapsed');
    }

    /**
     * Initializes auto-expanded rows by rendering payload data from data attributes
     * This ensures consistent rendering between auto-expanded rows and manually expanded rows
     */
    function initializeAutoExpandedRows() {
        console.log('Initializing auto-expanded rows');
        
        // Find all elements with the data-payload attribute
        const payloadSections = document.querySelectorAll('.odcm-payload-section[data-payload]');
        console.log('Found ' + payloadSections.length + ' payload sections with data attributes');
        
        // Process each payload section
        payloadSections.forEach(function(section) {
            try {
                // Get the payload data from the data attribute
                const payloadData = section.getAttribute('data-payload');
                
                // Parse the JSON data
                const parsedData = JSON.parse(payloadData);
                
                // Render the payload data using our custom renderer
                const renderedHtml = renderAuditLogDetails(parsedData);
                
                // Replace the loading spinner with the rendered HTML
                // Keep the h4 heading and replace only the loading spinner
                const heading = section.querySelector('h4');
                section.innerHTML = '';
                section.appendChild(heading);
                
                // Create a container for the rendered content
                const contentContainer = document.createElement('div');
                contentContainer.className = 'odcm-payload-content';
                contentContainer.innerHTML = renderedHtml;
                section.appendChild(contentContainer);
                
                // Apply syntax highlighting if Prism is available
                const codeBlock = section.querySelector('code.language-json');
                if (codeBlock && typeof Prism !== 'undefined') {
                    Prism.highlightElement(codeBlock);
                }
                
                console.log('Successfully rendered payload data for a section');
            } catch (error) {
                // Handle any errors that occur during parsing or rendering
                console.error('Error rendering payload data:', error);
                section.innerHTML = '<h4>Payload Data</h4><div class="odcm-error">Error rendering payload data: ' + error.message + '</div>';
            }
        });
    }

    // Initialize on document ready
    $(document).ready(function() {
        console.log('Document ready - initializing');
        initMetaBox();
        
        // Initialize auto-expanded rows
        initializeAutoExpandedRows();
        
        // Handle click events on the toggle details button
        $('.odcm-audit-log').on('click', '.odcm-toggle-details', function(e) {
            e.preventDefault();

            const $link = $(this);
            const logId = $link.data('log-id');
            const detailRow = $('#log-details-' + logId);
            
            // Toggle the visibility of the detail row
            detailRow.toggle();
            
            // If we just showed the row and it doesn't have data yet, fetch it via AJAX
            if (detailRow.is(':visible')) {
                // Check if we've already loaded the data
                if (!detailRow.data('loaded')) {
                    // Change link text to indicate loading
                    const originalText = $link.text();
                    $link.text('Loading...');
                    
                    // Add a loading indicator to the details row
                    detailRow.html('<div class="odcm-loading">Loading log details...</div>');
                    
                    // Make the AJAX request to fetch the log details
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'odcm_get_log_details',
                            log_id: logId,
                            nonce: odcm_admin.audit_log_nonce
                        },
                        success: function(response) {
                            // Check if the request was successful
                            if (response.success) {
                                // Render the payload data using our custom renderer
                                const detailsContent = '<div class="odcm-log-details-container">' +
                                    '<div class="odcm-log-details-section">' +
                                    '<h4>Payload Data</h4>' +
                                    renderAuditLogDetails(response.data) +
                                    '</div>' +
                                    '</div>';
                                
                                // Update the details row with the content
                                detailRow.html(detailsContent);
                                
                                // Mark the row as loaded
                                detailRow.data('loaded', true);
                                
                                // Apply syntax highlighting
                                const codeBlock = detailRow.find('code.language-json');
                                if (codeBlock.length && typeof Prism !== 'undefined') {
                                    Prism.highlightElement(codeBlock[0]);
                                }
                                
                                // Change link text to "Hide Details"
                                $link.text('Hide Details');
                            } else {
                                // Show error message
                                detailRow.html('<div class="odcm-error">' + 
                                    (response.data.message || 'Error loading log details.') + 
                                    '</div>');
                                
                                // Restore original link text
                                $link.text(originalText);
                            }
                        },
                        error: function(xhr, status, error) {
                            // Show error message
                            detailRow.html('<div class="odcm-error">Error: ' + error + '</div>');
                            console.error('AJAX error:', error);
                            
                            // Restore original link text
                            $link.text(originalText);
                        }
                    });
                } else {
                    // Data already loaded, just change link text to "Hide Details"
                    $link.text('Hide Details');
                    
                    // Re-apply syntax highlighting
                    const codeBlock = detailRow.find('code.language-json');
                    if (codeBlock.length && typeof Prism !== 'undefined') {
                        Prism.highlightElement(codeBlock[0]);
                    }
                }
            } else {
                // Row is now hidden, change link text back to "View Details"
                $link.text('View Details');
            }
        });

        // Check if auto expand setting is enabled
        if (typeof odcmSettings !== 'undefined' && odcmSettings.autoExpandLogDetails === '1') {
            console.log('Auto expand setting is enabled');
            // Add a class to the body to indicate auto-expand is enabled
            $('body').addClass('odcm-auto-expand-enabled');
            expandAllLogDetails();
        } else {
            console.log('Auto expand setting is disabled');
            // Remove the class from the body
            $('body').removeClass('odcm-auto-expand-enabled');
            collapseAllLogDetails();
        }

        // Handle the auto expand checkbox
        $('#odcm-auto-expand-log-details').on('change', function() {
            var isChecked = $(this).is(':checked');
            console.log('Auto expand checkbox changed to: ' + (isChecked ? 'checked' : 'unchecked'));

            // Update the body class immediately for instant visual feedback
            if (isChecked) {
                $('body').addClass('odcm-auto-expand-enabled');
            } else {
                $('body').removeClass('odcm-auto-expand-enabled');
            }

            // Update the user meta via AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'odcm_update_auto_expand_setting',
                    auto_expand: isChecked ? 1 : 0,
                    nonce: odcmSettings.nonce
                },
                success: function(response) {
                    console.log('Auto expand setting updated successfully');

                    // Update the odcmSettings object
                    odcmSettings.autoExpandLogDetails = isChecked ? '1' : '0';

                    // Expand or collapse all log details based on the checkbox state
                    if (isChecked) {
                        expandAllLogDetails();
                    } else {
                        collapseAllLogDetails();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error updating auto expand setting:', error);

                    // Revert the body class if there was an error
                    if (isChecked) {
                        $('body').removeClass('odcm-auto-expand-enabled');
                    } else {
                        $('body').addClass('odcm-auto-expand-enabled');
                    }
                }
            });
        });

        // Log toggle links for debugging
        var toggleCount = $('.audit-log-toggle-details').length;
        console.log('Found ' + toggleCount + ' toggle links');

        // 2. Direct binding to elements that exist on page load
        $('.audit-log-toggle-details').on('click', function(e) {
            e.preventDefault();
            console.log('Toggle link clicked (direct binding)');
            handleToggleClick($(this));
        });

        // 3. Listen for the custom odcm_toggle_details event
        $(document).on('odcm_toggle_details', '.audit-log-toggle-details', function(e) {
            e.preventDefault();
            console.log('Toggle link triggered custom event');
            handleToggleClick($(this));
        });

        // 4. Add direct onclick handlers to all buttons
        $('.audit-log-toggle-details').each(function() {
            var $button = $(this);
            var logId = $button.data('log-id');

            // Add direct onclick handler as a fallback
            if (!$button.attr('onclick')) {
                $button.attr('onclick', 'jQuery(this).trigger(\'odcm_toggle_details\'); return false;');
                console.log('Added onclick handler to button for log ID: ' + logId);
            }
        });
    });

    // Fallback initialization with delay
    setTimeout(function() {
        console.log('Delayed initialization check');

        // Log toggle links again
        var toggleCount = $('.audit-log-toggle-details').length;
        console.log('Found ' + toggleCount + ' toggle links after delay');

        // 3. Direct binding after delay (for elements that might be added dynamically)
        $('.audit-log-toggle-details').off('click').on('click', function(e) {
            e.preventDefault();
            console.log('Toggle link clicked (delayed binding)');
            handleToggleClick($(this));
        });

        // 4. Listen for the custom odcm_toggle_details event (after delay)
        $(document).off('odcm_toggle_details', '.audit-log-toggle-details').on('odcm_toggle_details', '.audit-log-toggle-details', function(e) {
            e.preventDefault();
            console.log('Toggle link triggered custom event (delayed binding)');
            handleToggleClick($(this));
        });

        // 5. Add direct onclick handlers to all buttons (after delay)
        $('.audit-log-toggle-details').each(function() {
            var $button = $(this);
            var logId = $button.data('log-id');

            // Add direct onclick handler as a fallback
            if (!$button.attr('onclick')) {
                $button.attr('onclick', 'jQuery(this).trigger(\'odcm_toggle_details\'); return false;');
                console.log('Added onclick handler to button for log ID: ' + logId + ' (delayed)');
            }
        });

        // 6. Try direct DOM click handlers as a last resort
        $('.audit-log-toggle-details').each(function() {
            var button = this;
            var $button = $(button);
            var logId = $button.data('log-id');

            // Remove any existing click handlers and add a new one directly to the DOM element
            button.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Toggle link clicked via direct DOM handler for log ID: ' + logId);
                handleToggleClick($button);
                return false;
            };

            console.log('Added direct DOM click handler to button for log ID: ' + logId);
        });

        // Check if auto expand setting is enabled (fallback)
        if (typeof odcmSettings !== 'undefined' && odcmSettings.autoExpandLogDetails === '1') {
            // Add a class to the body to indicate auto-expand is enabled
            $('body').addClass('odcm-auto-expand-enabled');
            expandAllLogDetails();
        } else {
            // Remove the class from the body
            $('body').removeClass('odcm-auto-expand-enabled');
            // Make sure all detail rows are hidden if auto expand is disabled
            collapseAllLogDetails();
        }

        // 7. Set up a MutationObserver to handle dynamically added buttons
        if (window.MutationObserver) {
            console.log('Setting up MutationObserver for dynamically added buttons');

            // Function to process newly added buttons
            function processNewButtons(addedNodes) {
                $(addedNodes).find('.audit-log-toggle-details').each(function() {
                    var $button = $(this);
                    var logId = $button.data('log-id');

                    console.log('Found dynamically added button for log ID: ' + logId);

                    // Add click handler
                    $button.off('click').on('click', function(e) {
                        e.preventDefault();
                        console.log('Dynamically added button clicked for log ID: ' + logId);
                        handleToggleClick($(this));
                    });

                    // Add onclick attribute
                    if (!$button.attr('onclick')) {
                        $button.attr('onclick', 'jQuery(this).trigger(\'odcm_toggle_details\'); return false;');
                    }

                    // Add direct DOM click handler
                    var button = this;
                    button.onclick = function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('Dynamically added button clicked via direct DOM handler for log ID: ' + logId);
                        handleToggleClick($button);
                        return false;
                    };

                    // Set initial state based on auto expand setting
                    var logId = $button.data('log-id');
                    var $detailsRow = $('#audit-log-details-row-' + logId);

                    if (typeof odcmSettings !== 'undefined' && odcmSettings.autoExpandLogDetails === '1') {
                        $button.attr('aria-expanded', 'true').text('Hide Details');
                        $button.addClass('details-expanded');

                        // Show the details row
                        if ($detailsRow.length > 0) {
                            $detailsRow.show();
                            $detailsRow.addClass('details-visible');
                        }
                    } else {
                        $button.attr('aria-expanded', 'false').text('View Details');
                        $button.removeClass('details-expanded');

                        // Hide the details row
                        if ($detailsRow.length > 0) {
                            $detailsRow.hide();
                            $detailsRow.removeClass('details-visible');
                        }
                    }
                });
            }

            // Create and start the observer
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes && mutation.addedNodes.length > 0) {
                        processNewButtons(mutation.addedNodes);
                    }
                });
            });

            // Start observing the document body for changes
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }, 1000);

})(jQuery);

jQuery(document).ready(function($) {
    /**
     * Finds all audit log detail containers and renders the JSON data
     * using the renderAuditLogDetails function.
     */
    function initializeAuditLogDetails() {
        const detailContainers = document.querySelectorAll('.odcm-json-details');
        
        detailContainers.forEach(container => {
            const jsonString = container.getAttribute('data-json');
            if (jsonString) {
                try {
                    const dataObject = JSON.parse(jsonString);
                    // Use the existing renderer to generate the HTML
                    container.innerHTML = renderAuditLogDetails(dataObject);
                } catch (e) {
                    console.error('Failed to parse audit log JSON:', e);
                    // Display the raw string as a fallback on error
                    container.innerText = jsonString; 
                }
            }
        });
    }

    // Run the initializer on page load
    initializeAuditLogDetails();

    /**
     * Renders audit log details into a structured HTML format
     * 
     * @param {Object} data - The JSON data object received from the AJAX response
     * @return {string} HTML string representation of the data
     */
    function renderAuditLogDetails(data) {
        // If data is null or undefined, return a message
        if (data === null || data === undefined) {
            return '<div class="odcm-empty-data">No data available</div>';
        }
        
        // Create a definition list for the data
        let html = '<dl class="odcm-log-details-dl">';
        
        // Function to recursively render nested objects and arrays
        function renderValue(value) {
            if (value === null || value === undefined || value === '') {
                return '<span class="odcm-empty-value">N/A</span>';
            } else if (typeof value === 'boolean') {
                return value ? 
                    '<span class="odcm-boolean-value odcm-true">True</span>' : 
                    '<span class="odcm-boolean-value odcm-false">False</span>';
            } else if (typeof value === 'number') {
                return '<span class="odcm-number-value">' + value + '</span>';
            } else if (typeof value === 'string') {
                return '<span class="odcm-string-value">' + value.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
            } else if (Array.isArray(value)) {
                if (value.length === 0) {
                    return '<span class="odcm-empty-value">Empty Array</span>';
                }
                
                let arrayHtml = '<ul class="odcm-array-list">';
                for (let i = 0; i < value.length; i++) {
                    arrayHtml += '<li class="odcm-array-item">' + renderValue(value[i]) + '</li>';
                }
                arrayHtml += '</ul>';
                return arrayHtml;
            } else if (typeof value === 'object') {
                let nestedHtml = '<dl class="odcm-nested-dl">';
                for (let key in value) {
                    if (value.hasOwnProperty(key)) {
                        nestedHtml += '<dt class="odcm-nested-key">' + key.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</dt>';
                        nestedHtml += '<dd class="odcm-nested-value">' + renderValue(value[key]) + '</dd>';
                    }
                }
                nestedHtml += '</dl>';
                return nestedHtml;
            } else {
                return '<span class="odcm-unknown-value">' + String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
            }
        }

        // Iterate through the data object
        for (let key in data) {
            if (data.hasOwnProperty(key)) {
                html += '<dt class="odcm-log-details-key">' + key.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</dt>';
                html += '<dd class="odcm-log-details-value">' + renderValue(data[key]) + '</dd>';
            }
        }

        html += '</dl>';
        return html;
    }

})(jQuery);
