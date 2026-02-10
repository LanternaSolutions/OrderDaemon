/**
 * JavaScript for handling rule toggle and drag-and-drop functionality.
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize sortable for the rules table
        initSortableRules();

        // Handle toggle switch change
        $('.odcm-toggle-switch input[type="checkbox"]').on('change', function() {
            const $checkbox = $(this);
            const $toggleSwitch = $checkbox.closest('.odcm-toggle-switch');
            const ruleId = $checkbox.data('rule-id');
            const nonce = $checkbox.data('nonce');

            // Prevent multiple clicks
            if ($toggleSwitch.hasClass('loading')) {
                return;
            }

            // Add loading state
            $toggleSwitch.addClass('loading');

            // Send AJAX request to toggle rule status
            $.ajax({
                url: odcmToggleRules.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'odcm_toggle_rule_status',
                    rule_id: ruleId,
                    nonce: nonce
                },
                success: function(response) {
                    // Enhanced response validation and error handling
                    try {
                        // Validate response structure
                        if (typeof response !== 'object' || response === null) {
                            throw new Error('Invalid response format: not an object');
                        }

                        if (response.success === true) {
                            // Validate required response data
                            if (!response.data || typeof response.data !== 'object') {
                                throw new Error('Invalid response data structure');
                            }

                            // Validate required fields
                            const requiredFields = ['new_status', 'date_text', 'post_title'];
                            for (const field of requiredFields) {
                                if (!(field in response.data)) {
                                    throw new Error(`Missing required field: ${field}`);
                                }
                            }

                            // Update checkbox state
                            $checkbox.prop('checked', response.data.new_status === '1');

                            // Update title attribute
                            $toggleSwitch.attr('title', response.data.new_status === '1' ? 
                                odcmToggleRules.activeText : odcmToggleRules.inactiveText);

                            // Get the row element for this rule
                            const $row = $checkbox.closest('tr');

                            // Update the date column
                            const $dateColumn = $row.find('.column-date');
                            if ($dateColumn.length && response.data.date_text) {
                                // Find the abbr element that contains the date text
                                const $dateAbbr = $dateColumn.find('abbr');
                                if ($dateAbbr.length) {
                                    // Get the current date/time text
                                    const dateTimeText = $dateAbbr.text();

                                    // Check if the text already starts with "Published" or "Last Modified"
                                    if (!dateTimeText.startsWith(odcmToggleRules.publishedText) && 
                                        !dateTimeText.startsWith(odcmToggleRules.lastModifiedText)) {
                                        // Prepend the status text to the date/time
                                        $dateAbbr.text(response.data.date_text + '\n' + dateTimeText);
                                    } else {
                                        // Replace just the status part
                                        const statusRegex = new RegExp('^(' + odcmToggleRules.publishedText + '|' + odcmToggleRules.lastModifiedText + ')');
                                        $dateAbbr.text(dateTimeText.replace(statusRegex, response.data.date_text + '\n'));
                                    }
                                } else {
                                    // If abbr not found, try to preserve existing content
                                    const currentText = $dateColumn.text();
                                    if (currentText && 
                                        !currentText.startsWith(odcmToggleRules.publishedText) && 
                                        !currentText.startsWith(odcmToggleRules.lastModifiedText)) {
                                        $dateColumn.html(response.data.date_text + '<br>' + currentText);
                                    } else {
                                        // Replace just the status part
                                        const statusRegex = new RegExp('^(' + odcmToggleRules.publishedText + '|' + odcmToggleRules.lastModifiedText + ')');
                                        $dateColumn.html(currentText.replace(statusRegex, response.data.date_text + '<br>'));
                                    }
                                }
                            }

                            // Update the title column
                            const $titleColumn = $row.find('.column-title');
                            if ($titleColumn.length && response.data.post_title) {
                                // Find the strong element that contains the title
                                const $titleStrong = $titleColumn.find('strong');
                                if ($titleStrong.length) {
                                    // Find the row-title element
                                    const $rowTitle = $titleStrong.find('.row-title');
                                    if ($rowTitle.length) {
                                        // Clear all content after the row-title to remove any existing draft text and dashes
                                        $rowTitle.nextAll().remove();

                                        // Remove any text nodes that might contain dashes
                                        $titleStrong.contents().filter(function() {
                                            return this.nodeType === 3 && this.nodeValue.trim().match(/^[\s\-—–]+$/);
                                        }).remove();

                                        // Set the clean title text
                                        $rowTitle.text(response.data.post_title);

                                        // Add draft text if needed
                                        if (response.data.new_status === '0') {
                                            $rowTitle.after(' - <span class="post-state">' + odcmToggleRules.draftText + '</span>');
                                        }
                                    }
                                }
                            }


                            // Success feedback for debug
                            if (typeof console !== 'undefined' && console.log) {
                                if (odcmIsDebug()) {console.log('Rule toggle successful:', response.data);}
                            }

                        } else if (response.success === false) {
                            // Handle server-side errors
                            let errorMessage = odcmToggleRules.errorMessage; // Default fallback
                            
                            if (response.data && typeof response.data === 'object' && response.data.message) {
                                errorMessage = response.data.message;
                            } else if (typeof response.data === 'string') {
                                errorMessage = response.data;
                            }
                            
                            alert(errorMessage);
                            // Revert checkbox state
                            $checkbox.prop('checked', !$checkbox.prop('checked'));
                        } else {
                            // Ambiguous response
                            throw new Error('Ambiguous server response: success property not boolean');
                        }

                    } catch (error) {
                        // Handle any errors that occur during success processing
                        console.error('Error processing toggle response:', error);
                        alert(odcmToggleRules.errorMessage);
                        // Revert checkbox state
                        $checkbox.prop('checked', !$checkbox.prop('checked'));
                    }
                },
                error: function() {
                    // Show error message
                    alert(odcmToggleRules.errorMessage);
                    // Revert checkbox state
                    $checkbox.prop('checked', !$checkbox.prop('checked'));
                },
                complete: function() {
                    // Remove loading state
                    $toggleSwitch.removeClass('loading');
                }
            });
        });
        
        /**
         * Initialize sortable functionality for the rules table
         */
        function initSortableRules() {
            // Use WordPress standard table selector - we're now using the WP default table
            const $rulesTable = $('#the-list').closest('table');
            const $tableBody = $('#the-list'); // WordPress uses #the-list for the tbody in admin tables

            if ($tableBody.length === 0) {
                // Table not found - fail silently
                return;
            }

            // Initialize data-id attributes for all rows
            initRowDataAttributes();

            // Initialize priority column values
            initPriorityColumnValues();

            $tableBody.sortable({
                handle: '.odcm-drag-handle',
                placeholder: 'odcm-sortable-placeholder',
                helper: fixWidthHelper,
                cursor: 'move',
                axis: 'y',
                opacity: 0.8,
                start: function(event, ui) {
                    ui.item.addClass('odcm-dragging');
                    // Save the start position
                    ui.item.data('start-pos', ui.item.index());
                },
                stop: function(event, ui) {
                    ui.item.removeClass('odcm-dragging');

                    // Get the end position
                    const startPos = ui.item.data('start-pos');
                    const endPos = ui.item.index();

                    // Only update if position changed
                    if (startPos !== endPos) {
                        updateRuleOrder($tableBody);
                    }
                }
            }).disableSelection();

            // Helper function to fix the width of the table row during dragging
            function fixWidthHelper(e, ui) {
                ui.children().each(function() {
                    $(this).width($(this).width());
                });
                return ui;
            }
        }

        /**
         * Initialize data-id attributes for all table rows
         */
        function initRowDataAttributes() {
            $('#the-list tr').each(function() {
                const postId = $(this).attr('id').replace('post-', '');
                if (postId) {
                    $(this).attr('data-id', postId);
                }
            });
        }

        /**
         * Initialize priority column values
         */
        function initPriorityColumnValues() {
            $('#the-list tr').each(function(index) {
                // Find the priority column and update its text
                const $priorityCell = $(this).find('.column-priority');
                if ($priorityCell.length && $priorityCell.text().trim() === '') {
                    $priorityCell.text(index);
                }
            });
        }
        
        /**
         * Save the new rule order via AJAX
         */
        function updateRuleOrder($tableBody) {
            // Show loading overlay
            const $overlay = $('<div class="odcm-loading-overlay"><span class="spinner is-active"></span></div>');
            $tableBody.closest('.wp-list-table').after($overlay);
            
            // Get all rule IDs in the current order
            const ruleIds = [];
            $tableBody.find('tr').each(function() {
                const ruleId = $(this).data('id');
                if (ruleId) {
                    ruleIds.push(ruleId);
                }
            });
            
            // If we have rule IDs, send the AJAX request
            if (ruleIds.length) {
                $.ajax({
                    url: odcmToggleRules.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'odcm_update_rule_order',
                        nonce: odcmToggleRules.orderNonce,
                        rule_ids: ruleIds
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the priority numbers displayed in the table
                            updateDisplayedPriorities();
                            
                            // Show success message
                            showNotice(response.data.message, 'success');
                        } else {
                            // Show error message
                            showNotice(response.data.message || odcmToggleRules.orderErrorMessage, 'error');
                            
                            // Revert to original order (refresh the page)
                            window.location.reload();
                        }
                    },
                    error: function() {
                        // Show error message
                        showNotice(odcmToggleRules.orderErrorMessage, 'error');
                        
                        // Revert to original order (refresh the page)
                        window.location.reload();
                    },
                    complete: function() {
                        // Remove loading overlay
                        $overlay.remove();
                    }
                });
            } else {
                // Remove loading overlay if no rules found
                $overlay.remove();
            }
        }
        
        /**
         * Update the displayed priority numbers after reordering
         */
        function updateDisplayedPriorities() {
            const $rows = $('#the-list tr');
            
            $rows.each(function(index) {
                // Find the priority column and update its text
                const $priorityCell = $(this).find('.column-priority');
                if ($priorityCell.length) {
                    // Use index directly for 0-based priorities (0, 1, 2, etc.)
                    $priorityCell.text(index);
                }
            });
        }
        
        /**
         * Display a WordPress admin notice
         */
        function showNotice(message, type) {
            // Remove existing notices
            $('.odcm-ajax-notice').remove();
            
            // Create notice element
            const $notice = $('<div class="notice odcm-ajax-notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Add the notice to the page (use WordPress standard selectors)
            $('.wp-header-end').after($notice);
            
            // Add dismissible functionality
            if (typeof wp !== 'undefined' && wp.a11y && wp.a11y.speak) {
                wp.a11y.speak(message);
            }
            
            // Auto-remove the notice after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(400, function() {
                    $(this).remove();
                });
            }, 5000);
        }

        // No quick edit functionality - using drag & drop only for priority management
    });
})(jQuery);
