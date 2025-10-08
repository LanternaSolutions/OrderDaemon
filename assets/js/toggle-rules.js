/**
 * JavaScript for handling rule toggle functionality.
 */
(function($) {
    'use strict';

    $(document).ready(function() {
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
                    if (response.success) {
                        // Update checkbox state
                        $checkbox.prop('checked', response.data.new_status === '1');

                        // Update title attribute
                        $toggleSwitch.attr('title', response.data.new_status === '1' ? 
                            odcmToggleRules.activeText : odcmToggleRules.inactiveText);

                        // Get the row element for this rule
                        const $row = $checkbox.closest('tr');

                        // Update the date column
                        const $dateColumn = $row.find('.column-date');
                        if ($dateColumn.length) {
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
                        if ($titleColumn.length) {
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

                        // If we're in freemium mode and other rules were affected, update other toggles
                        if (!response.data.is_premium && response.data.affected_rules > 0) {
                            // Find all other active toggles and set them to inactive
                            $('.odcm-toggle-switch input[type="checkbox"]').each(function() {
                                const $otherCheckbox = $(this);
                                const otherRuleId = $otherCheckbox.data('rule-id');

                                // Skip the current toggle
                                if (otherRuleId == ruleId) {
                                    return;
                                }

                                // Set other toggles to inactive
                                $otherCheckbox.prop('checked', false);
                                $otherCheckbox.closest('.odcm-toggle-switch').attr('title', odcmToggleRules.inactiveText);

                                // Also update the date and title columns for these rows
                                const $otherRow = $otherCheckbox.closest('tr');

                                // Update date column
                                const $otherDateColumn = $otherRow.find('.column-date');
                                if ($otherDateColumn.length) {
                                    const $otherDateAbbr = $otherDateColumn.find('abbr');
                                    if ($otherDateAbbr.length) {
                                        // Get the current date/time text
                                        const dateTimeText = $otherDateAbbr.text();

                                        // Check if the text already starts with "Published" or "Last Modified"
                                        if (!dateTimeText.startsWith(odcmToggleRules.publishedText) && 
                                            !dateTimeText.startsWith(odcmToggleRules.lastModifiedText)) {
                                            // Prepend the status text to the date/time
                                            $otherDateAbbr.text(odcmToggleRules.lastModifiedText + '\n' + dateTimeText);
                                        } else {
                                            // Replace just the status part
                                            const statusRegex = new RegExp('^(' + odcmToggleRules.publishedText + '|' + odcmToggleRules.lastModifiedText + ')');
                                            $otherDateAbbr.text(dateTimeText.replace(statusRegex, odcmToggleRules.lastModifiedText + '\n'));
                                        }
                                    } else {
                                        // If abbr not found, try to preserve existing content
                                        const currentText = $otherDateColumn.text();
                                        if (currentText && 
                                            !currentText.startsWith(odcmToggleRules.publishedText) && 
                                            !currentText.startsWith(odcmToggleRules.lastModifiedText)) {
                                            $otherDateColumn.html(odcmToggleRules.lastModifiedText + '<br>' + currentText);
                                        } else {
                                            // Replace just the status part
                                            const statusRegex = new RegExp('^(' + odcmToggleRules.publishedText + '|' + odcmToggleRules.lastModifiedText + ')');
                                            $otherDateColumn.html(currentText.replace(statusRegex, odcmToggleRules.lastModifiedText + '<br>'));
                                        }
                                    }
                                }

                                // Update title column to add "- Draft"
                                const $otherTitleColumn = $otherRow.find('.column-title');
                                if ($otherTitleColumn.length) {
                                    const $otherTitleStrong = $otherTitleColumn.find('strong');
                                    if ($otherTitleStrong.length) {
                                        const $otherRowTitle = $otherTitleStrong.find('.row-title');
                                        if ($otherRowTitle.length) {
                                            // Clear all content after the row-title to remove any existing draft text and dashes
                                            $otherRowTitle.nextAll().remove();

                                            // Remove any text nodes that might contain dashes
                                            $otherTitleStrong.contents().filter(function() {
                                                return this.nodeType === 3 && this.nodeValue.trim().match(/^[\s\-—–]+$/);
                                            }).remove();

                                            // Add the draft text
                                            $otherRowTitle.after(' - <span class="post-state">' + odcmToggleRules.draftText + '</span>');
                                        }
                                    }
                                }
                            });
                        }
                    } else {
                        // Show error message
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
    });
})(jQuery);
