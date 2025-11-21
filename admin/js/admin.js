/**
 * Admin JavaScript for FreshRank AI
 * Complete version with priority details functionality
 * Wrapped in IIFE to prevent conflicts
 */

(function($) {
    'use strict';

    // Wait for full DOM ready
    if (typeof $ === 'undefined') {
        return;
    }

    // Module-scoped variables (avoid global pollution)
    var progressDialog = null;
    var currentOperation = null;
    var operationLock = false;
    var bulkAnalysisErrors = null;
    var bulkUpdateErrors = null;
    var processDraftErrors = null;
    var openRouterModelsCache = [];
    var openRouterModelSelections = {
        analysis: '',
        writing: ''
    };

    var openRouterModelsLastRefresh = 0;

    /**
     * Escape HTML special characters in dynamic strings.
     *
     * @param {string} value
     * @returns {string}
     */
    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Wait for document ready
    $(document).ready(function() {
        // Initialize components
        initSortable();
        initDialogs();
        bindEvents();

        // Check for in-progress prioritization and resume polling if needed
        checkAndResumePrioritization();
    });
    
    /**
     * Initialize sortable articles list
     */
    function initSortable() {
        if ($('#freshrank-sortable-articles').length) {
            $('#freshrank-sortable-articles').sortable({
                cursor: 'move',
                placeholder: 'freshrank-sortable-placeholder',
                update: function(event, ui) {
                    saveArticleOrder();
                }
            });
        }
    }
    
    /**
     * Initialize dialogs
     */
    function initDialogs() {
        progressDialog = $('#freshrank-progress-dialog').dialog({
            autoOpen: false,
            modal: true,
            width: 500,
            height: 230,
            resizable: false,
            closeOnEscape: false,
            draggable: false,
            open: function() {
                $('.ui-dialog-titlebar-close', $(this).parent()).hide();
            }
        });
    }
    
    /**
     * Bind event listeners
     */
    function bindEvents() {
        // Main action buttons
        $('#freshrank-start-prioritization').on('click', startPrioritization);
        $('#freshrank-cancel-prioritization').on('click', cancelPrioritization);
        $('#freshrank-analyze-all-articles').on('click', analyzeAllArticles);
        $('#freshrank-analyze-selected').on('click', analyzeSelectedArticles);
        $('#freshrank-update-all-articles').on('click', updateAllArticles);
        $('#freshrank-update-selected').on('click', updateSelectedArticles);
        $('#freshrank-delete-selected').on('click', deleteSelectedArticles);

        // Individual article actions
        $(document).on('click', '.freshrank-analyze-single', analyzeSingleArticle);
        $(document).on('click', '.freshrank-update-single', updateSingleArticle);
        $(document).on('click', '.freshrank-re-analyze', reAnalyzeSingleArticle);
        $(document).on('click', '.freshrank-retry-analysis', retrySingleAnalysis);

        // Draft management - bind to both regular and inline buttons
        $(document).on('click', '.freshrank-approve-draft, .freshrank-approve-draft-inline', approveDraft);
        $(document).on('click', '.freshrank-reject-draft, .freshrank-reject-draft-inline', rejectDraft);
        $(document).on('click', '.freshrank-approve-revision', approveRevision); // Revision system approve
        $(document).on('click', '.freshrank-reject-revision', rejectRevision); // Revision system reject

        // Bulk draft actions (if elements exist)
        if ($('#freshrank-approve-all-drafts').length) {
            $('#freshrank-approve-all-drafts').on('click', approveAllDrafts);
        }
        if ($('#freshrank-reject-all-drafts').length) {
            $('#freshrank-reject-all-drafts').on('click', rejectAllDrafts);
        }

        // Selection management
        $('#freshrank-select-all').on('change', toggleSelectAll);
        $('#freshrank-select-all-drafts').on('change', toggleSelectAllDrafts);
        $(document).on('change', '.freshrank-article-checkbox', updateSelectAllState);
        $(document).on('change', '.freshrank-draft-checkbox', updateSelectAllDraftsState);

        // Settings page events (use delegated binding for dynamically shown elements)
        $(document).on('click', '#freshrank-test-gsc-connection', testGscConnection);
        $(document).on('click', '#freshrank-test-openai-connection', testOpenAiConnection);
        $(document).on('click', '#freshrank-test-openai-connection-alt', testOpenAiConnection);
        $(document).on('click', '#freshrank-diagnose-gsc', diagnoseGscConnection);

        // OpenRouter events (use delegated binding)
        $(document).on('change', 'input[name="ai_provider"]', toggleProviderSettings);
        $(document).on('click', '#freshrank-test-openrouter-connection', testOpenRouterConnection);
        $(document).on('click', '#freshrank-refresh-openrouter-models', refreshOpenRouterModels);
        $(document).on('input', 'input[name="openrouter_custom_model_analysis"], input[name="openrouter_custom_model_writing"]', handleCustomModelInput);
        $(document).on('input', '#openrouter_model_analysis_search, #openrouter_model_writing_search', handleOpenRouterSearch);
        $(document).on('change', '#openrouter_model_analysis, #openrouter_model_writing', rememberOpenRouterSelection);

        initializeOpenRouterModelState();

        // Initialize provider toggle on page load
        toggleProviderSettings();

        // Load OpenRouter models if API key exists
        if ($('#openrouter_api_key').val()) {
            loadOpenRouterModels();
        }

        // View details (expandable rows)
        $(document).on('click', '.freshrank-view-issues', viewAnalysisDetails);
        $(document).on('click', '.freshrank-toggle-issues', toggleAnalysisDetails);
        $(document).on('click', '.freshrank-toggle-draft-details', toggleDraftDetails);
        $(document).on('click', '.freshrank-toggle-priority-details, .freshrank-toggle-priority-details-inline', togglePriorityDetails);

        // View draft diff
        $(document).on('click', '.freshrank-view-diff', function(e) {
            e.preventDefault();
            e.stopPropagation();
            viewDraftDiff.call(this, e);
        });

        // Refresh GSC data for single article
        $(document).on('click', '.freshrank-refresh-gsc-data', refreshGscData);

        // Pagination per-page selector
        $('#freshrank-per-page').on('change', function() {
            var perPage = $(this).val();
            var currentUrl = window.location.href;
            var url = new URL(currentUrl);
            url.searchParams.set('per_page', perPage);
            url.searchParams.delete('paged'); // Reset to first page
            window.location.href = url.toString();
        });

        // Retry handlers for recovery options (delegated event handlers)
        $(document).on('click', '.freshrank-retry-analysis', function() {
            var postId = $(this).data('post-id');
            $('.notice-dismiss').click(); // Close the error notice
            $('.freshrank-analyze-single[data-post-id="' + postId + '"]').click();
        });

        $(document).on('click', '.freshrank-retry-update', function() {
            var postId = $(this).data('post-id');
            $('.notice-dismiss').click(); // Close the error notice
            $('.freshrank-update-single[data-post-id="' + postId + '"]').click();
        });

        $(document).on('click', '.freshrank-retry-approve', function() {
            var draftId = $(this).data('draft-id');
            var originalId = $(this).data('original-id');
            $('.notice-dismiss').click(); // Close the error notice
            $('.freshrank-approve-draft[data-draft-id="' + draftId + '"][data-original-id="' + originalId + '"]').click();
        });

        $(document).on('click', '.freshrank-retry-reject', function() {
            var draftId = $(this).data('draft-id');
            $('.notice-dismiss').click(); // Close the error notice
            $('.freshrank-reject-draft[data-draft-id="' + draftId + '"]').click();
        });

        // Retry prioritization (delegated event handler replaces inline onclick)
        $(document).on('click', '.freshrank-retry-prioritization', startPrioritization);

        // Refresh page buttons (delegated event handler replaces inline onclick)
        $(document).on('click', '.freshrank-refresh-page', function() {
            location.reload();
        });

        // Go to settings page from stat clickable (delegated event handler)
        $(document).on('click', '.freshrank-goto-settings', function() {
            window.location.href = $(this).data('url');
        });

        // Toggle error message (delegated event handler)
        $(document).on('click', '.freshrank-toggle-error', function(e) {
            e.preventDefault();
            var errorId = $(this).data('error-id');
            $('#' + errorId + '-short').toggle();
            $('#' + errorId + '-full').toggle();
        });

        // Custom Instructions Toggle (use delegated event for better compatibility)
        $(document).on('change', '#custom_instructions_enabled', function() {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#freshrank-custom-instructions-fields').slideDown(300);
            } else {
                $('#freshrank-custom-instructions-fields').slideUp(300);
            }
        });

        // Initialize visibility on page load
        if ($('#custom_instructions_enabled').length) {
            var initialChecked = $('#custom_instructions_enabled').is(':checked');
            if (initialChecked) {
                $('#freshrank-custom-instructions-fields').show();
            } else {
                $('#freshrank-custom-instructions-fields').hide();
            }
        }

        // Character counter for custom prompts
        function updateCharCounter(textarea, counter) {
            var length = textarea.val().length;
            var maxLength = textarea.attr('maxlength') || 1000;
            counter.text(length + '/' + maxLength + ' characters');

            // Color feedback
            if (length > maxLength * 0.9) {
                counter.css('color', '#d63638'); // Red when approaching limit
            } else if (length > maxLength * 0.75) {
                counter.css('color', '#dba617'); // Orange at 75%
            } else {
                counter.css('color', '#666'); // Gray default
            }
        }

        // Bind character counters
        var $analysisPrompt = $('#custom_analysis_prompt');
        var $analysisCounter = $('#custom_analysis_prompt_counter');
        var $rewritePrompt = $('#custom_rewrite_prompt');
        var $rewriteCounter = $('#custom_rewrite_prompt_counter');

        if ($analysisPrompt.length && $analysisCounter.length) {
            // Update on page load
            updateCharCounter($analysisPrompt, $analysisCounter);

            // Update on input
            $analysisPrompt.on('input', function() {
                updateCharCounter($analysisPrompt, $analysisCounter);
            });
        }

        if ($rewritePrompt.length && $rewriteCounter.length) {
            // Update on page load
            updateCharCounter($rewritePrompt, $rewriteCounter);

            // Update on input
            $rewritePrompt.on('input', function() {
                updateCharCounter($rewritePrompt, $rewriteCounter);
            });
        }
    }
    
    /**
     * Start article prioritization (batched with ActionScheduler)
     */
    var progressPollInterval = null;

    function startPrioritization() {
        // Check operation lock to prevent race conditions
        if (operationLock) {
            showNotification('Another operation is already in progress. Please wait for it to complete.', 'warning');
            return;
        }

        // Use the correct confirmation string
        if (!confirm(freshrank_ajax.strings.confirm_prioritize || 'This will fetch Google Search Console data and prioritize all articles. This will run in the background and may take 10-15 minutes for large sites. Continue?')) {
            return;
        }

        // Set operation lock
        operationLock = true;

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_prioritize_articles',
                nonce: freshrank_ajax.nonce
            },
            timeout: 30000, // 30 second timeout for starting job
            success: function(response) {
                if (response.success) {
                    // Start progress polling
                    startProgressPolling();
                    showNotification(response.data.message || 'Prioritization started successfully!', 'success');
                } else {
                    operationLock = false;
                    showNotification(response.data.message || 'Failed to start prioritization', 'error');
                }
            },
            error: function(xhr, status, error) {
                // Release operation lock
                operationLock = false;

                // Parse error message
                var errorMessage = error;
                if (status === 'timeout') {
                    errorMessage = 'Request timeout - failed to start prioritization';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.status === 0) {
                    errorMessage = 'Network connection failed';
                } else if (xhr.status === 403) {
                    errorMessage = 'Google Search Console authentication failed';
                }

                // Provide actionable error message
                var safeErrorMessage = escapeHtml(errorMessage);
                var safeSettingsUrl = escapeHtml(freshrank_ajax.settings_url || '');

                var errorHtml = '<div style="margin-top: 10px;"><strong>Prioritization failed:</strong> ' + safeErrorMessage + '</div>' +
                    '<div style="margin-top: 8px; font-size: 13px;">Troubleshooting steps:</div>' +
                    '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                    '<li>Verify your Google Search Console connection</li>' +
                    '<li>Check that your site is verified in GSC</li>' +
                    '<li>Ensure you have sufficient permissions in GSC</li>' +
                    '<li>Confirm your site URL matches exactly</li>' +
                    '</ul>' +
                    '<div style="margin-top: 10px;">' +
                    '<a href="' + safeSettingsUrl + '" class="button button-small">Check GSC Settings</a> ' +
                    '<button class="button button-small button-primary freshrank-retry-prioritization">Retry Prioritization</button>' +
                    '</div>';

                showNotificationHtml(errorHtml, 'error');
            }
        });
    }

    /**
     * Start polling for prioritization progress
     */
    function startProgressPolling() {
        // Show progress dialog
        currentOperation = 'prioritize';
        showProgressDialog('Prioritization in progress. If you have a large number of articles, this may take a bit longer. Check back later to see the results.');

        // Poll every 2 seconds
        progressPollInterval = setInterval(function() {
            $.ajax({
                url: freshrank_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'freshrank_get_prioritization_progress',
                    nonce: freshrank_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        updateProgressDisplay(response.data);

                        // Stop polling if complete or failed
                        if (response.data.status === 'complete' || response.data.status === 'failed' || response.data.status === 'cancelled') {
                            stopProgressPolling();
                            handlePrioritizationComplete(response.data);
                        }
                    }
                },
                error: function() {
                    // Continue polling even on error (might be temporary network issue)
                }
            });
        }, 2000);
    }

    /**
     * Stop progress polling
     */
    function stopProgressPolling() {
        if (progressPollInterval) {
            clearInterval(progressPollInterval);
            progressPollInterval = null;
        }
    }

    /**
     * Update progress display
     */
    function updateProgressDisplay(progress) {
        if (!progress) return;

        var percentage = progress.total_posts > 0 ? Math.round((progress.processed / progress.total_posts) * 100) : 0;
        
        $('#freshrank-progress-bar').css('width', percentage + '%');

        // ALSO update admin notice if present
        var $notice = $('.freshrank-prioritization-notice[data-status="running"]');
        if ($notice.length) {
            var noticeText = '<strong>Prioritization in progress:</strong> ' +
                (progress.processed || 0) + ' / ' + (progress.total_posts || 0) + ' articles processed ' +
                '(' + percentage + '%)';
            if (progress.current_batch && progress.total_batches) {
                noticeText += ' - Batch ' + progress.current_batch + ' / ' + progress.total_batches;
            }
            $notice.find('p').html(noticeText);
        }
    }

    /**
     * Handle prioritization completion
     */
    function handlePrioritizationComplete(progress) {
        // Release operation lock
        operationLock = false;
        hideProgressDialog();

        // Remove admin notice if present
        $('.freshrank-prioritization-notice').fadeOut(300, function() {
            $(this).remove();
        });

        if (progress.status === 'complete') {
            var message = 'Prioritization complete! ' + (progress.total_posts || 0) + ' articles processed.';
            if (progress.success_count) {
                message += ' Successfully fetched GSC data for ' + progress.success_count + ' articles.';
            }
            showNotification(message, 'success');

            // Reload page after 2 seconds to show new prioritization
            setTimeout(function() {
                location.reload();
            }, 2000);
        } else if (progress.status === 'failed') {
            var errorMessage = 'Prioritization failed.';
            if (progress.errors && progress.errors.length > 0) {
                var criticalError = progress.errors.find(function(e) { return e.critical; });
                if (criticalError) {
                    errorMessage += ' Error: ' + criticalError.error;
                }
            }
            showNotification(errorMessage, 'error');
        } else if (progress.status === 'cancelled') {
            showNotification('Prioritization was cancelled.', 'warning');
        }
    }

    /**
     * Cancel in-progress prioritization
     */
    function cancelPrioritization() {
        if (!confirm('Are you sure you want to cancel the prioritization? This will stop all pending batches.')) {
            return;
        }

        // Disable cancel button to prevent duplicate requests
        $('#freshrank-cancel-prioritization').prop('disabled', true).text('Cancelling...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_cancel_prioritization',
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    stopProgressPolling();
                    operationLock = false;
                    hideProgressDialog();
                    showNotification('Prioritization cancelled successfully.', 'warning');

                    // Remove admin notice if present
                    $('.freshrank-prioritization-notice').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    showNotification('Failed to cancel prioritization: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'), 'error');
                    // Re-enable button on failure
                    $('#freshrank-cancel-prioritization').prop('disabled', false).text('Cancel Prioritization');
                }
            },
            error: function() {
                showNotification('Error communicating with server. The prioritization may still be running.', 'error');
                // Re-enable button on error
                $('#freshrank-cancel-prioritization').prop('disabled', false).text('Cancel Prioritization');
            }
        });
    }

    /**
     * Check if prioritization is running and resume polling
     * Called on page load
     */
    function checkAndResumePrioritization() {
        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_get_prioritization_progress',
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data && response.data.status === 'running') {
                    // Resume progress polling
                    startProgressPolling();

                    // Show initial progress
                    updateProgressDisplay(response.data);
                }
            }
        });
    }

    /**
     * Analyze all articles
     */
    function analyzeAllArticles() {
        if (!confirm(freshrank_ajax.strings.confirm_analyze_all)) {
            return;
        }
        
        var articleIds = getAllArticleIds();
        if (articleIds.length === 0) {
            showNotification('No articles found to analyze.', 'warning');
            return;
        }
        
        bulkAnalyze(articleIds);
    }
    
    /**
     * Analyze selected articles
     */
    function analyzeSelectedArticles() {
        var selectedIds = getSelectedArticleIds();
        if (selectedIds.length === 0) {
            showNotification('Please select articles to analyze.', 'warning');
            return;
        }
        
        if (!confirm(freshrank_ajax.strings.confirm_analyze_all)) {
            return;
        }
        
        bulkAnalyze(selectedIds);
    }
    
    /**
     * Update all analyzed articles
     */
    function updateAllArticles() {
        if (!confirm(freshrank_ajax.strings.confirm_update_all)) {
            return;
        }
        
        var articleIds = getAllAnalyzedArticleIds();
        if (articleIds.length === 0) {
            showNotification('No analyzed articles found to update.', 'warning');
            return;
        }
        
        bulkUpdate(articleIds);
    }
    
    /**
     * Update selected analyzed articles
     */
    function updateSelectedArticles() {
        var selectedIds = getSelectedAnalyzedArticleIds();
        if (selectedIds.length === 0) {
            showNotification('Please select analyzed articles to update.', 'warning');
            return;
        }
        
        if (!confirm(freshrank_ajax.strings.confirm_update_all)) {
            return;
        }
        
        bulkUpdate(selectedIds);
    }
    
    /**
     * Perform bulk analysis
     */
    function bulkAnalyze(articleIds) {
        // Check operation lock to prevent race conditions
        if (operationLock) {
            showNotification('Another operation is already in progress. Please wait for it to complete.', 'warning');
            return;
        }

        // Set operation lock
        operationLock = true;

        // Show non-blocking notification instead of modal dialog
        showNotification(`Starting analysis of ${articleIds.length} articles in background...`, 'info');
        currentOperation = 'analyze';

        var completed = 0;
        var total = articleIds.length;
        var startTime = Date.now();

        function analyzeNext(index) {
            if (index >= articleIds.length) {
                // Release operation lock
                operationLock = false;

                // Show summary with any errors
                if (bulkAnalysisErrors && bulkAnalysisErrors.length > 0) {
                    var errorCount = bulkAnalysisErrors.length;
                    var successCount = total - errorCount;

                    var summaryHtml = '<div style="margin-top: 10px;"><strong>Bulk Analysis Complete</strong></div>' +
                        '<div style="margin-top: 8px;">Successfully analyzed: ' + successCount + ' articles</div>' +
                        '<div style="color: #d63638;">Failed: ' + errorCount + ' articles</div>' +
                        '<div style="margin-top: 10px; font-size: 13px;">Common issues to check:</div>' +
                        '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                        '<li>API rate limits - wait a few minutes between retries</li>' +
                        '<li>Invalid API credentials</li>' +
                        '<li>Network connectivity issues</li>' +
                        '</ul>' +
                        '<div style="margin-top: 10px;">' +
                        '<a href="' + freshrank_ajax.settings_url + '" class="button button-small">Check API Settings</a> ' +
                        '<button class="button button-small freshrank-refresh-page">Refresh Page</button>' +
                        '</div>';

                    showNotificationHtml(summaryHtml, 'warning');
                    bulkAnalysisErrors = null;
                } else {
                    showNotification(`Analysis completed successfully for ${total} articles.`, 'success');
                }

                return;
            }

            var postId = articleIds[index];

            // Update status to show analysis is in progress
            updateArticleStatus(postId, 'analyzing');

            $.ajax({
                url: freshrank_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'freshrank_analyze_article',
                    post_id: postId,
                    nonce: freshrank_ajax.nonce
                },
                timeout: 120000, // 2 minutes timeout for individual analysis
                success: function(response) {
                    completed++;
                    updateArticleStatus(postId, response.success ? 'completed' : 'error');
                    
                    // Add delay between requests
                    setTimeout(function() {
                        analyzeNext(index + 1);
                    }, 1000);
                },
                error: function(xhr, status, error) {
                    completed++;
                    updateArticleStatus(postId, 'error');

                    // Track failed articles for summary
                    if (!bulkAnalysisErrors) {
                        bulkAnalysisErrors = [];
                    }
                    bulkAnalysisErrors.push({
                        postId: postId,
                        error: status === 'timeout' ? 'Request timeout' : error,
                        status: xhr.status
                    });

                    setTimeout(function() {
                        analyzeNext(index + 1);
                    }, 1000);
                }
            });
        }
        
        analyzeNext(0);
    }
    
    /**
     * Perform bulk update
     */
    function bulkUpdate(articleIds) {
        // Check operation lock to prevent race conditions
        if (operationLock) {
            showNotification('Another operation is already in progress. Please wait for it to complete.', 'warning');
            return;
        }

        // Set operation lock
        operationLock = true;

        // Show non-blocking notification instead of modal dialog
        showNotification(`Starting draft creation for ${articleIds.length} articles in background...`, 'info');
        currentOperation = 'update';

        var completed = 0;
        var total = articleIds.length;
        var startTime = Date.now();

        function updateNext(index) {
            if (index >= articleIds.length) {
                // Release operation lock
                operationLock = false;

                // Show summary with any errors
                if (bulkUpdateErrors && bulkUpdateErrors.length > 0) {
                    var errorCount = bulkUpdateErrors.length;
                    var successCount = total - errorCount;

                    var summaryHtml = '<div style="margin-top: 10px;"><strong>Bulk Draft Creation Complete</strong></div>' +
                        '<div style="margin-top: 8px;">Successfully created: ' + successCount + ' drafts</div>' +
                        '<div style="color: #d63638;">Failed: ' + errorCount + ' drafts</div>' +
                        '<div style="margin-top: 10px; font-size: 13px;">Common issues to check:</div>' +
                        '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                        '<li>API rate limits or insufficient credits</li>' +
                        '<li>Articles must be analyzed before creating drafts</li>' +
                        '<li>Network connectivity issues</li>' +
                        '<li>Model token limits (try a model with higher output capacity)</li>' +
                        '</ul>' +
                        '<div style="margin-top: 10px;">' +
                        '<a href="' + freshrank_ajax.settings_url + '" class="button button-small">Check API Settings</a> ' +
                        '<button class="button button-small freshrank-refresh-page">Refresh Page</button>' +
                        '</div>';

                    showNotificationHtml(summaryHtml, 'warning');
                    bulkUpdateErrors = null;
                } else {
                    showNotification(`Draft creation completed successfully for ${total} articles.`, 'success');
                }

                return;
            }

            var postId = articleIds[index];

            // Update row to show draft creation is in progress
            var $row = $(`.freshrank-article-row[data-post-id="${postId}"]`);
            $row.addClass('wsau-creating-draft-row');
            var $draftCell = $row.find('.freshrank-draft-info');
            if ($draftCell.length) {
                $draftCell.html('<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>Creating Draft...');
            }

            $.ajax({
                url: freshrank_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'freshrank_update_article',
                    post_id: postId,
                    nonce: freshrank_ajax.nonce
                },
                timeout: 720000, // 12 minutes timeout for draft creation (allows for very long content generation)
                success: function(response) {
                    completed++;

                    // Remove creating status
                    $row.removeClass('wsau-creating-draft-row');

                    var postTitle = $.trim($row.find('.wsau-article-title').text());
                    var errorMessage = '';

                    if (!response || !response.success) {
                        errorMessage = (response && response.data && response.data.message) ? response.data.message : 'Failed to create draft';
                    } else if (response.data && response.data.background) {
                        showNotification(response.data.message || ('Draft creation started in background for "' + postTitle + '". Refresh to check progress.'), 'info');
                    } else if (response.data && response.data.message) {
                        // Optional success message from server
                        showNotification(response.data.message, 'success');
                    }

                    if (errorMessage) {
                        if (!bulkUpdateErrors) {
                            bulkUpdateErrors = [];
                        }
                        bulkUpdateErrors.push({
                            postId: postId,
                            error: errorMessage,
                            status: 'application'
                        });

                        var safeMsg = escapeHtml(errorMessage);
                        if ($draftCell.length) {
                            $draftCell.html('<span style="color: #d63638;">' + safeMsg + '</span>');
                        }

                        if (postTitle) {
                            showNotification('Draft creation failed for "' + postTitle + '": ' + errorMessage, 'warning');
                        } else {
                            showNotification('Draft creation failed: ' + errorMessage, 'warning');
                        }
                    } else {
                        if ($draftCell.length) {
                            $draftCell.html('<span style="color: #46b450;">' + escapeHtml(freshrank_ajax.strings.draft_created || 'Draft created. Refresh the page to view details.') + '</span>');
                        }
                    }

                    // Add delay between requests
                    setTimeout(function() {
                        updateNext(index + 1);
                    }, 2000); // Longer delay for content generation
                },
                error: function(xhr, status, error) {
                    completed++;

                    // Remove creating status and show error
                    $row.removeClass('wsau-creating-draft-row');
                    if ($draftCell.length) {
                        $draftCell.html('<span style="color: #d63638;">Draft creation failed</span>');
                    }

                    // Track failed articles for summary
                    if (!bulkUpdateErrors) {
                        bulkUpdateErrors = [];
                    }
                    bulkUpdateErrors.push({
                        postId: postId,
                        error: status === 'timeout' ? 'Request timeout' : error,
                        status: xhr.status
                    });

                    setTimeout(function() {
                        updateNext(index + 1);
                    }, 2000);
                }
            });
        }
        
        updateNext(0);
    }
    
    /**
     * Analyze single article
     */
    function analyzeSingleArticle() {
        var postId = $(this).data('post-id');
        var $button = $(this);
        var $row = $button.closest('tr');

        // Add loading state
        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + freshrank_ajax.strings.analyzing);
        $row.addClass('freshrank-analyzing-row');

        // Single AJAX call - simpler and more reliable
        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_analyze_article',
                post_id: postId,
                nonce: freshrank_ajax.nonce
            },
            timeout: 120000, // 2 minutes timeout
            success: function(response) {
                $button.removeClass('freshrank-button-loading');
                $row.removeClass('freshrank-analyzing-row');

                if (response.success) {
                    showNotification('Analysis completed successfully. Refreshing page...', 'success');
                    // Reload the page to show the full analysis results
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification(response.data.message || 'Analysis failed', 'error');
                    updateArticleStatus(postId, 'error');
                    $button.prop('disabled', false).text('Analyze');
                }
            },
            error: function(xhr, status, error) {
                $button.removeClass('freshrank-button-loading');
                $row.removeClass('freshrank-analyzing-row');

                // Parse error message
                var errorMessage = error;
                if (status === 'timeout') {
                    // Special handling for timeout - analysis might still be running
                    errorMessage = 'Browser timeout - analysis may still be running in background';
                    showNotification(errorMessage + '. Checking status...', 'warning');

                    // Start polling to check if analysis completes
                    var pollCount = 0;
                    var pollInterval = setInterval(function() {
                        pollCount++;

                        // Poll for up to 3 minutes (18 checks at 10 second intervals)
                        if (pollCount > 18) {
                            clearInterval(pollInterval);
                            showNotification('Analysis appears to have stalled. Please refresh the page.', 'warning');
                            $button.prop('disabled', false).html('Retry Analysis');
                            return;
                        }

                        // Check if analysis completed
                        $.ajax({
                            url: freshrank_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'freshrank_check_analysis_status',
                                post_id: postId,
                                nonce: freshrank_ajax.nonce
                            },
                            success: function(response) {
                                if (response.success && response.data.status === 'completed') {
                                    clearInterval(pollInterval);
                                    showNotification('Analysis completed! Refreshing page...', 'success');
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                } else if (response.data.status === 'error') {
                                    clearInterval(pollInterval);
                                    showNotification('Analysis failed: ' + (response.data.error || 'Unknown error'), 'error');
                                    $button.prop('disabled', false).html('Retry Analysis');
                                }
                                // If status is still 'analyzing', continue polling
                            }
                        });
                    }, 10000); // Check every 10 seconds

                    return; // Don't show error dialog for timeout
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.status === 0) {
                    errorMessage = 'Network connection failed';
                } else if (xhr.status === 403) {
                    errorMessage = 'Permission denied - please check your API key';
                } else if (xhr.status === 429) {
                    errorMessage = 'Rate limit exceeded - please wait and retry';
                }

                // Provide actionable error message (for non-timeout errors)
                    var safeErrorMessage = escapeHtml(errorMessage);
                    var safeSettingsUrl = escapeHtml(freshrank_ajax.settings_url || '');
                    var safePostId = escapeHtml(String(postId));

                    var errorHtml = '<div style="margin-top: 10px;"><strong>Analysis failed:</strong> ' + safeErrorMessage + '</div>' +
                        '<div style="margin-top: 8px; font-size: 13px;">Common causes:</div>' +
                        '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                        '<li>Invalid or expired API key</li>' +
                        '<li>Rate limits exceeded</li>' +
                        '<li>Network connectivity issues</li>' +
                        '<li>Insufficient permissions</li>' +
                        '</ul>' +
                        '<div style="margin-top: 10px;">' +
                        '<a href="' + safeSettingsUrl + '" class="button button-small">Check API Settings</a> ' +
                        '<button class="button button-small button-primary freshrank-retry-analysis" data-post-id="' + safePostId + '">Retry Analysis</button>' +
                        '</div>';

                    showNotificationHtml(errorHtml, 'error');
                updateArticleStatus(postId, 'error');
                $button.prop('disabled', false).text('Analyze');
            }
        });
    }
    
    /**
     * Update single article
     */
    function updateSingleArticle() {
        var postId = $(this).data('post-id');
        var $button = $(this);

        // Add loading state
        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + freshrank_ajax.strings.updating);

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_update_article',
                post_id: postId,
                nonce: freshrank_ajax.nonce
            },
            timeout: 720000, // 12 minutes timeout for draft creation (allows for very long content generation)
            success: function(response) {
                $button.removeClass('freshrank-button-loading');

                if (response.success) {
                    // Check if it's running in background
                    if (response.data && response.data.background) {
                        showNotification(response.data.message || 'Draft creation started in background. Refresh page to check progress.', 'info');
                        $button.prop('disabled', false).text('Creating in Background...');

                        // Change button state to show it's processing
                        $button.closest('tr').find('.freshrank-status').html(
                            '<span class="freshrank-status freshrank-status-creating">' +
                            '<span class="dashicons dashicons-edit spin" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>' +
                            'Creating Draft...</span>'
                        );
                    } else {
                        showNotification('Draft created successfully. Refreshing page...', 'success');
                        // Reload the page to show the draft information
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    }
                } else {
                    showNotification(response.data.message || 'Failed to create draft', 'error');
                    $button.prop('disabled', false).text('Create Draft');
                }
            },
            error: function(xhr, status, error) {
                $button.removeClass('freshrank-button-loading');

                // Parse error message
                var errorMessage = error;
                if (status === 'timeout') {
                    // Special handling for timeout - draft creation might still be running
                    errorMessage = 'Browser timeout - draft creation may still be running in background';
                    showNotification(errorMessage + '. Checking status every 10 seconds...', 'warning');

                    // Update button to show polling status
                    $button.prop('disabled', true).html(
                        '<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>' +
                        'Checking status...'
                    );

                    // Start polling to check if draft creation completes
                    var pollCount = 0;
                    var pollInterval = setInterval(function() {
                        pollCount++;

                        // Update button with poll count
                        $button.html(
                            '<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>' +
                            'Checking... (attempt ' + pollCount + '/18)'
                        );

                        // Poll for up to 3 minutes (18 checks at 10 second intervals)
                        if (pollCount > 18) {
                            clearInterval(pollInterval);
                            showNotification('Draft creation appears to have stalled. Please refresh the page.', 'warning');
                            $button.prop('disabled', false).html('Retry Draft');
                            return;
                        }

                        // Check if draft was created by reloading page data
                        $.ajax({
                            url: freshrank_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'freshrank_check_draft_status',
                                post_id: postId,
                                nonce: freshrank_ajax.nonce
                            },
                            success: function(response) {
                                if (response.success && response.data.has_draft) {
                                    clearInterval(pollInterval);
                                    showNotification('Draft created successfully! Refreshing page...', 'success');
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                } else if (response.data.status === 'error') {
                                    clearInterval(pollInterval);
                                    showNotification('Draft creation failed: ' + (response.data.error || 'Unknown error'), 'error');
                                    $button.prop('disabled', false).html('Retry Draft');
                                }
                                // If still creating, continue polling
                            }
                        });
                    }, 10000); // Check every 10 seconds

                    return; // Don't show error dialog for timeout
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.status === 0) {
                    errorMessage = 'Network connection failed';
                } else if (xhr.status === 403) {
                    errorMessage = 'Permission denied - please check your API key';
                } else if (xhr.status === 429) {
                    errorMessage = 'Rate limit exceeded - please wait and retry';
                }

                // Provide actionable error message (for non-timeout errors)
                var safeErrorMessage = escapeHtml(errorMessage);
                var safeSettingsUrl = escapeHtml(freshrank_ajax.settings_url || '');
                var safePostId = escapeHtml(String(postId));

                var errorHtml = '<div style="margin-top: 10px;"><strong>Draft creation failed:</strong> ' + safeErrorMessage + '</div>' +
                    '<div style="margin-top: 8px; font-size: 13px;">Possible solutions:</div>' +
                    '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                    '<li>Verify your API key is valid and has credits</li>' +
                    '<li>Check if the article has been analyzed first</li>' +
                    '<li>Ensure stable network connection</li>' +
                    '<li>Try a model with higher token limits if content is very long</li>' +
                    '<li>Try again in a few minutes if rate limited</li>' +
                    '</ul>' +
                    '<div style="margin-top: 10px;">' +
                    '<a href="' + safeSettingsUrl + '" class="button button-small">Check API Settings</a> ' +
                    '<button class="button button-small button-primary freshrank-retry-update" data-post-id="' + safePostId + '">Retry Draft Creation</button>' +
                    '</div>';

                showNotificationHtml(errorHtml, 'error');
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .text('Create Draft');
            }
        });
    }
    
    /**
     * Re-analyze single article
     */
    function reAnalyzeSingleArticle() {
        analyzeSingleArticle.call(this);
    }
    
    /**
     * Retry single analysis
     */
    function retrySingleAnalysis() {
        analyzeSingleArticle.call(this);
    }
    
    /**
     * Approve draft
     */
    function approveDraft(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!confirm(freshrank_ajax.strings.confirm_approve)) {
            return false;
        }

        var draftId = $(this).data('draft-id');
        var originalId = $(this).data('original-id');

        if (!draftId || !originalId) {
            showNotification('Error: Missing required data. Please refresh the page and try again.', 'error');
            return false;
        }

        var $button = $(this);
        var originalText = $button.text();

        // Add loading state
        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Approving...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_approve_draft',
                draft_id: draftId,
                original_id: originalId,
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                $button.removeClass('freshrank-button-loading');

                if (response.success) {
                    showNotification(response.data.message, 'success');
                    $button.closest('tr').fadeOut();
                } else {
                    showNotification(response.data.message, 'error');
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                // Parse error message
                var errorMessage = 'An unknown error occurred';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.status === 0) {
                    errorMessage = 'Network connection failed';
                } else if (xhr.status === 404) {
                    errorMessage = 'Draft or original post not found';
                } else if (xhr.status === 403) {
                    errorMessage = 'Permission denied';
                }

                // Provide actionable error message
                var safeErrorMessage = escapeHtml(errorMessage);
                var safeDraftId = escapeHtml(String(draftId));
                var safeOriginalId = escapeHtml(String(originalId));
                var safeAdminUrl = escapeHtml(freshrank_ajax.admin_url || '');

                var errorHtml = '<div style="margin-top: 10px;"><strong>Draft approval failed:</strong> ' + safeErrorMessage + '</div>' +
                    '<div style="margin-top: 8px; font-size: 13px;">What to check:</div>' +
                    '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                    '<li>Verify the draft still exists</li>' +
                    '<li>Check you have permission to edit the post</li>' +
                    '<li>Ensure the original post still exists</li>' +
                    '<li>Check for any post status conflicts</li>' +
                    '</ul>' +
                    '<div style="margin-top: 10px;">' +
                    '<button class="button button-small button-primary freshrank-retry-approve" data-draft-id="' + safeDraftId + '" data-original-id="' + safeOriginalId + '">Retry Approval</button> ' +
                    '<a href="' + safeAdminUrl + 'post.php?post=' + safeDraftId + '&action=edit" class="button button-small">View Draft</a>' +
                    '</div>';

                showNotificationHtml(errorHtml, 'error');
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .text(originalText);
            }
        });
    }

    /**
     * Reject draft
     */
    function rejectDraft(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!confirm(freshrank_ajax.strings.confirm_reject)) {
            return false;
        }

        var draftId = $(this).data('draft-id');

        if (!draftId) {
            showNotification('Error: Missing required data. Please refresh the page and try again.', 'error');
            return false;
        }

        var $button = $(this);
        var originalText = $button.text();

        // Add loading state
        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Rejecting...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_reject_draft',
                draft_id: draftId,
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                $button.removeClass('freshrank-button-loading');

                if (response.success) {
                    showNotification(response.data.message, 'success');
                    // Reload the page to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification(response.data.message, 'error');
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                // Parse error message
                var errorMessage = 'An unknown error occurred';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.status === 0) {
                    errorMessage = 'Network connection failed';
                } else if (xhr.status === 404) {
                    errorMessage = 'Draft not found';
                } else if (xhr.status === 403) {
                    errorMessage = 'Permission denied';
                }

                // Provide actionable error message
                var safeErrorMessage = escapeHtml(errorMessage);
                var safeDraftId = escapeHtml(String(draftId));
                var safeAdminUrl = escapeHtml(freshrank_ajax.admin_url || '');

                var errorHtml = '<div style="margin-top: 10px;"><strong>Draft rejection failed:</strong> ' + safeErrorMessage + '</div>' +
                    '<div style="margin-top: 8px; font-size: 13px;">What to check:</div>' +
                    '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                    '<li>Verify the draft still exists</li>' +
                    '<li>Check you have permission to delete the post</li>' +
                    '<li>Ensure no other process is accessing the draft</li>' +
                    '</ul>' +
                    '<div style="margin-top: 10px;">' +
                    '<button class="button button-small button-primary freshrank-retry-reject" data-draft-id="' + safeDraftId + '">Retry Rejection</button> ' +
                    '<a href="' + safeAdminUrl + 'post.php?post=' + safeDraftId + '&action=edit" class="button button-small">View Draft</a>' +
                    '</div>';

                showNotificationHtml(errorHtml, 'error');
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .text(originalText);
            }
        });
    }

    /**
     * Approve revision (keep current version, clear AI flag)
     */
    function approveRevision() {
        if (!confirm('Approve this AI update? The updated content will remain published.')) {
            return;
        }

        var postId = $(this).data('post-id');
        var $button = $(this);
        var originalHtml = $button.html();

        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Approving...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_approve_revision',
                post_id: postId,
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification(response.data.message, 'error');
                    $button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function(xhr, status, error) {
                showNotification('Failed to approve update: ' + error, 'error');
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .html(originalHtml);
            }
        });
    }

    /**
     * Reject revision (restore previous version)
     */
    function rejectRevision() {
        if (!confirm('Reject this AI update? The previous version will be restored.')) {
            return;
        }

        var postId = $(this).data('post-id');
        var $button = $(this);
        var originalHtml = $button.html();

        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Restoring...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_reject_revision',
                post_id: postId,
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification(response.data.message, 'error');
                    $button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function(xhr, status, error) {
                showNotification('Failed to restore previous version: ' + error, 'error');
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .html(originalHtml);
            }
        });
    }

    /**
     * Approve all drafts
     */
    function approveAllDrafts() {
        var draftIds = $('.freshrank-draft-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (draftIds.length === 0) {
            showNotification('Please select drafts to approve.', 'warning');
            return;
        }
        
        if (!confirm(`Are you sure you want to approve ${draftIds.length} drafts? This will replace the original posts.`)) {
            return;
        }
        
        // Process each draft individually
        processDrafts(draftIds, 'approve');
    }
    
    /**
     * Reject all drafts
     */
    function rejectAllDrafts() {
        var draftIds = $('.freshrank-draft-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (draftIds.length === 0) {
            showNotification('Please select drafts to reject.', 'warning');
            return;
        }
        
        if (!confirm(`Are you sure you want to reject ${draftIds.length} drafts? They will be permanently deleted.`)) {
            return;
        }
        
        processDrafts(draftIds, 'reject');
    }
    
    /**
     * Process multiple drafts
     */
    function processDrafts(draftIds, action) {
        showProgressDialog(`${action === 'approve' ? 'Approving' : 'Rejecting'} drafts...`);
        
        var completed = 0;
        var total = draftIds.length;
        
        function processNext(index) {
            if (index >= draftIds.length) {
                hideProgressDialog();

                // Show summary with any errors
                if (processDraftErrors && processDraftErrors.length > 0) {
                    var errorCount = processDraftErrors.length;
                    var successCount = total - errorCount;
                    var actionText = action === 'approve' ? 'Approval' : 'Rejection';
                    var actionPastTense = action === 'approve' ? 'approved' : 'rejected';

                    var summaryHtml = '<div style="margin-top: 10px;"><strong>Bulk Draft ' + actionText + ' Complete</strong></div>' +
                        '<div style="margin-top: 8px;">Successfully ' + actionPastTense + ': ' + successCount + ' drafts</div>' +
                        '<div style="color: #d63638;">Failed: ' + errorCount + ' drafts</div>' +
                        '<div style="margin-top: 10px; font-size: 13px;">Common issues to check:</div>' +
                        '<ul style="margin: 5px 0; padding-left: 20px; font-size: 13px;">' +
                        '<li>Verify drafts still exist</li>' +
                        '<li>Check for permission issues</li>' +
                        '<li>Ensure no conflicts with other processes</li>' +
                        '</ul>' +
                        '<div style="margin-top: 10px;">' +
                        '<button class="button button-small freshrank-refresh-page">Refresh Page</button>' +
                        '</div>';

                    showNotificationHtml(summaryHtml, 'warning');
                    processDraftErrors = null;
                } else {
                    showNotification(`${action === 'approve' ? 'Approved' : 'Rejected'} ${total} drafts successfully.`, 'success');
                }

                setTimeout(function() {
                    location.reload();
                }, 2000);
                return;
            }

            var draftId = draftIds[index];
            var originalId = $(`[data-draft-id="${draftId}"]`).data('original-id');

            updateProgress(completed, total, `Processing draft ${index + 1} of ${total}...`);

            $.ajax({
                url: freshrank_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: action === 'approve' ? 'freshrank_approve_draft' : 'freshrank_reject_draft',
                    draft_id: draftId,
                    original_id: originalId,
                    nonce: freshrank_ajax.nonce
                },
                timeout: 120000, // 2 minutes timeout for draft approval/rejection
                success: function(response) {
                    completed++;
                    setTimeout(function() {
                        processNext(index + 1);
                    }, 500);
                },
                error: function(xhr, status, error) {
                    completed++;

                    // Track failed drafts for summary
                    if (!processDraftErrors) {
                        processDraftErrors = [];
                    }
                    processDraftErrors.push({
                        draftId: draftId,
                        error: status === 'timeout' ? 'Request timeout' : error,
                        status: xhr.status,
                        action: action
                    });

                    setTimeout(function() {
                        processNext(index + 1);
                    }, 500);
                }
            });
        }
        
        processNext(0);
    }
    
    /**
     * Save article order
     */
    function saveArticleOrder() {
        var orderedIds = $('#freshrank-sortable-articles tr').map(function() {
            return $(this).data('post-id');
        }).get();
        
        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_reorder_articles',
                ordered_ids: orderedIds,
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Article order saved.', 'success');
                }
            }
        });
    }
    
    /**
     * Toggle select all articles
     */
    function toggleSelectAll() {
        var checked = $(this).is(':checked');
        $('.freshrank-article-checkbox').prop('checked', checked);
    }
    
    /**
     * Toggle select all drafts
     */
    function toggleSelectAllDrafts() {
        var checked = $(this).is(':checked');
        $('.freshrank-draft-checkbox').prop('checked', checked);
    }
    
    /**
     * Update select all state
     */
    function updateSelectAllState() {
        var total = $('.freshrank-article-checkbox').length;
        var checked = $('.freshrank-article-checkbox:checked').length;
        
        $('#freshrank-select-all').prop({
            'checked': checked === total,
            'indeterminate': checked > 0 && checked < total
        });
    }
    
    /**
     * Update select all drafts state
     */
    function updateSelectAllDraftsState() {
        var total = $('.freshrank-draft-checkbox').length;
        var checked = $('.freshrank-draft-checkbox:checked').length;
        
        $('#freshrank-select-all-drafts').prop({
            'checked': checked === total,
            'indeterminate': checked > 0 && checked < total
        });
    }
    
    /**
     * Test GSC connection
     */
    function testGscConnection() {
        var $button = $(this);

        // Add loading state
        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Testing...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_test_gsc_connection',
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                } else {
                    showNotification(response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Connection test failed.', 'error');
            },
            complete: function() {
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .text('Test Connection');
            }
        });
    }

    /**
     * Run GSC diagnostics
     */
    function diagnoseGscConnection() {
        var $button = $(this);
        var $container = $('#freshrank-gsc-diagnostics');
        var $content = $('#freshrank-gsc-diagnostics-content');

        // Add loading state
        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Running Diagnostics...');

        $container.show();
        $content.html('<p><span class="spinner is-active"></span> Running diagnostics...</p>');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_diagnose_gsc',
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var diag = response.data.diagnostics;
                    var html = '';

                    // Status badge
                    var statusClass = '';
                    var statusIcon = '';
                    if (diag.status === 'healthy') {
                        statusClass = 'notice-success';
                        statusIcon = '✅';
                    } else if (diag.status === 'needs_auth') {
                        statusClass = 'notice-warning';
                        statusIcon = '⚠️';
                    } else if (diag.status === 'not_configured') {
                        statusClass = 'notice-error';
                        statusIcon = '❌';
                    } else {
                        statusClass = 'notice-error';
                        statusIcon = '⚠️';
                    }

                    html += '<div class="notice ' + statusClass + ' inline" style="margin: 0 0 15px 0;">';
                    html += '<p><strong>' + statusIcon + ' ' + diag.message + '</strong></p>';
                    html += '</div>';

                    // Configuration Info
                    if (diag.info) {
                        html += '<h4>Configuration</h4>';
                        html += '<table class="widefat" style="margin-bottom: 15px;">';
                        html += '<tbody>';
                        for (var key in diag.info) {
                            var label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l){ return l.toUpperCase() });
                            var value = diag.info[key];

                            if (Array.isArray(value)) {
                                value = value.length > 0 ? value.join('<br>') : '<em>None</em>';
                            } else if (typeof value === 'boolean') {
                                value = value ? 'Yes' : 'No';
                            } else if (!value) {
                                value = '<em>Not set</em>';
                            }

                            html += '<tr><td style="width: 30%; font-weight: 600;">' + label + '</td><td>' + value + '</td></tr>';
                        }
                        html += '</tbody></table>';
                    }

                    // Issues
                    if (diag.issues && diag.issues.length > 0) {
                        html += '<h4 style="color: #d63638;">🔴 Issues (' + diag.issues.length + ')</h4>';
                        html += '<ul style="margin-left: 20px; color: #d63638;">';
                        diag.issues.forEach(function(issue) {
                            html += '<li>' + issue + '</li>';
                        });
                        html += '</ul>';
                    }

                    // Warnings
                    if (diag.warnings && diag.warnings.length > 0) {
                        html += '<h4 style="color: #f56e28;">⚠️ Warnings (' + diag.warnings.length + ')</h4>';
                        html += '<ul style="margin-left: 20px; color: #f56e28;">';
                        diag.warnings.forEach(function(warning) {
                            html += '<li>' + warning + '</li>';
                        });
                        html += '</ul>';
                    }

                    // Next Steps
                    if (diag.steps && diag.steps.length > 0) {
                        html += '<h4 style="color: #2271b1;">📋 Next Steps</h4>';
                        html += '<ol style="margin-left: 20px;">';
                        diag.steps.forEach(function(step) {
                            html += '<li>' + step + '</li>';
                        });
                        html += '</ol>';
                    }

                    // All clear message
                    if (diag.status === 'healthy' && (!diag.issues || diag.issues.length === 0) && (!diag.warnings || diag.warnings.length === 0)) {
                        html += '<p style="color: #00a32a; font-weight: 600;">✅ All systems are working correctly!</p>';
                    }

                    $content.html(html);
                } else {
                    $content.html('<p style="color: #d63638;">Diagnostics failed: ' + (response.data.message || 'Unknown error') + '</p>');
                }
            },
            error: function() {
                $content.html('<p style="color: #d63638;">Diagnostics request failed.</p>');
            },
            complete: function() {
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-admin-tools" style="margin-top: 4px;"></span> Run Diagnostics');
            }
        });
    }
    
    /**
     * Test OpenAI connection
     */
    function testOpenAiConnection() {
        var $button = $(this);

        // Add loading state
        $button.prop('disabled', true)
            .addClass('freshrank-button-loading')
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Testing...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_test_openai_connection',
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                } else {
                    showNotification(response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Connection test failed.', 'error');
            },
            complete: function() {
                $button.removeClass('freshrank-button-loading')
                    .prop('disabled', false)
                    .text('Test API Connection');
            }
        });
    }
    
    /**
     * View analysis details
     */
    function viewAnalysisDetails() {
        var postId = $(this).data('post-id');
        toggleAnalysisDetails.call(this);
    }
    
    /**
     * Toggle priority details row
     */
    function togglePriorityDetails(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }

        var postId = $(this).data('post-id');
        var $detailsRow = $(`.freshrank-priority-details-row[data-post-id="${postId}"]`);
        var $button = $(this);

        if ($detailsRow.length === 0) {
            return;
        }

        if ($detailsRow.is(':visible')) {
            $detailsRow.hide();
            $button.text('View Details').attr('aria-expanded', 'false');
        } else {
            // Hide all other details rows first (analysis, draft, and priority)
            $('.freshrank-analysis-details-row, .freshrank-draft-details-row, .freshrank-priority-details-row').hide();
            $('.freshrank-toggle-issues, .freshrank-toggle-draft-details, .freshrank-toggle-priority-details, .freshrank-toggle-priority-details-inline').each(function() {
                var originalText = 'View Details';
                if ($(this).hasClass('freshrank-toggle-draft-details')) {
                    originalText = 'View Draft Details';
                }
                $(this).text(originalText).attr('aria-expanded', 'false');
            });

            // Show this one
            $detailsRow.show();
            $button.text('Hide Details').attr('aria-expanded', 'true');

            // Focus management: move focus to the details region
            $detailsRow.find('.freshrank-priority-details-content').attr('tabindex', '-1').focus();
        }
    }
    
    /**
     * Toggle draft details in a modal dialog
     */
    function toggleDraftDetails(e) {
        e.preventDefault();
        var postId = $(this).data('post-id');
        var $detailsRow = $(`.freshrank-draft-details-row[data-post-id="${postId}"]`);
        var postTitle = $(this).closest('tr').find('.column-title-enhanced .row-title').text();

        // Get the draft content
        var content = $detailsRow.find('.freshrank-draft-details-content').html();

        if (!content) {
            showNotification('No draft details available', 'warning');
            return;
        }

        // Create dialog if it doesn't exist
        if ($('#freshrank-draft-dialog').length === 0) {
            $('body').append('<div id="freshrank-draft-dialog" style="display:none;"></div>');
        }

        // Set content and open dialog
        $('#freshrank-draft-dialog').html(content).dialog({
            title: 'Draft Details: ' + postTitle,
            width: Math.min(800, $(window).width() * 0.9),
            maxHeight: $(window).height() * 0.8,
            modal: true,
            closeText: 'Close',
            closeOnEscape: true,
            draggable: true,
            resizable: true,
            dialogClass: 'freshrank-draft-modal',
            buttons: {
                'Close': function() {
                    $(this).dialog('close');
                }
            },
            open: function() {
                $('.ui-widget-overlay').on('click', function() {
                    $('#freshrank-draft-dialog').dialog('close');
                });
            }
        });
    }
    
    /**
     * Toggle analysis details in a modal dialog
     */
    function toggleAnalysisDetails() {
        var postId = $(this).data('post-id');
        var $detailsRow = $(`.freshrank-analysis-details-row[data-post-id="${postId}"]`);
        var postTitle = $(this).closest('tr').find('.column-title-enhanced .row-title').text();

        // Get the analysis content
        var content = $detailsRow.find('.freshrank-analysis-details-content').html();

        if (!content) {
            showNotification('No analysis details available', 'warning');
            return;
        }

        // Create dialog if it doesn't exist
        if ($('#freshrank-analysis-dialog').length === 0) {
            $('body').append('<div id="freshrank-analysis-dialog" style="display:none;"></div>');
        }

        // Set content and open dialog
        $('#freshrank-analysis-dialog').html(content).dialog({
            title: 'Analysis Details: ' + postTitle,
            width: Math.min(800, $(window).width() * 0.9),
            maxHeight: $(window).height() * 0.8,
            modal: true,
            closeText: 'Close',
            closeOnEscape: true,
            draggable: true,
            resizable: true,
            dialogClass: 'freshrank-analysis-modal',
            buttons: {
                'Close': function() {
                    $(this).dialog('close');
                }
            },
            open: function() {
                $('.ui-widget-overlay').on('click', function() {
                    $('#freshrank-analysis-dialog').dialog('close');
                });
            }
        });
    }
    
    /**
     * Get all article IDs
     */
    function getAllArticleIds() {
        return $('.freshrank-article-row').map(function() {
            return $(this).data('post-id');
        }).get();
    }
    
    /**
     * Get selected article IDs
     */
    function getSelectedArticleIds() {
        return $('.freshrank-article-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
    }
    
    /**
     * Get all analyzed article IDs that can be updated
     */
    function getAllAnalyzedArticleIds() {
        return $('.freshrank-article-row').filter(function() {
            var hasCompletedAnalysis = $(this).find('.freshrank-status-completed').length > 0;
            var hasDraft = $(this).find('.freshrank-status-draft').length > 0;
            return hasCompletedAnalysis && !hasDraft;
        }).map(function() {
            return $(this).data('post-id');
        }).get();
    }
    
    /**
     * Get selected analyzed article IDs that can be updated
     */
    function getSelectedAnalyzedArticleIds() {
        return $('.freshrank-article-checkbox:checked').filter(function() {
            var $row = $(this).closest('tr');
            var hasCompletedAnalysis = $row.find('.freshrank-status-completed').length > 0;
            var hasDraft = $row.find('.freshrank-status-draft').length > 0;
            return hasCompletedAnalysis && !hasDraft;
        }).map(function() {
            return $(this).val();
        }).get();
    }
    
    /**
     * Update article status in the table.
     */
    function updateArticleStatus(postId, status) {
        var $row = $(`.freshrank-article-row[data-post-id="${postId}"]`);
        var $statusCell = $row.find('.column-status');
        var statusHtml = '';

        switch (status) {
            case 'analyzing':
                statusHtml = '<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">' +
                    '<span class="dashicons dashicons-update spin" style="font-size: 14px; width: 14px; height: 14px; color: #0073aa;"></span>' +
                    '<span style="color: #0073aa;">Analyzing...</span>' +
                    '<span class="freshrank-status-flag freshrank-status-analyzing" style="display:none;"></span>' +
                    '</div>';
                break;
            case 'creating':
                statusHtml = '<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">' +
                    '<span class="dashicons dashicons-edit spin" style="font-size: 14px; width: 14px; height: 14px; color: #0073aa;"></span>' +
                    '<span style="color: #0073aa;">Creating Draft...</span>' +
                    '<span class="freshrank-status-flag freshrank-status-creating" style="display:none;"></span>' +
                    '</div>';
                break;
            case 'creating_draft':
                statusHtml = '<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">' +
                    '<span class="dashicons dashicons-edit spin" style="font-size: 14px; width: 14px; height: 14px; color: #0073aa;"></span>' +
                    '<span style="color: #0073aa;">Creating Draft...</span>' +
                    '<span class="freshrank-status-flag freshrank-status-creating" style="display:none;"></span>' +
                    '</div>';
                break;
            case 'completed':
                statusHtml = '<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">' +
                    '<span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px; color: #46b450;"></span>' +
                    '<span style="color: #46b450;">Ready</span>' +
                    '<span class="freshrank-status-flag freshrank-status-completed" style="display:none;"></span>' +
                    '</div>';
                break;
            case 'error':
                statusHtml = '<div style="display: flex; align-items: center; min-height: 60px; gap: 8px;">' +
                    '<span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px; color: #d63638;"></span>' +
                    '<span style="color: #d63638;">Error</span>' +
                    '<span class="freshrank-status-flag freshrank-status-error" style="display:none;"></span>' +
                    '</div>';
                break;
        }

        if (statusHtml) {
            $statusCell.html(statusHtml);
        }
    }
    
    /**
     * Show progress dialog
     */
    function showProgressDialog(message) {
        $('#freshrank-progress-text').text(message);
        $('#freshrank-progress-bar').css('width', '0%');

        // Show cancel button if this is a prioritization operation
        if (currentOperation === 'prioritize') {
            $('#freshrank-cancel-prioritization').show().prop('disabled', false).text('Cancel Prioritization');
        }

        progressDialog.dialog('open');
    }
    
    /**
     * Hide progress dialog
     */
    function hideProgressDialog() {
        if (progressDialog) {
            progressDialog.dialog('close');
        }
        // Hide and reset cancel button
        $('#freshrank-cancel-prioritization').hide().prop('disabled', false).text('Cancel Prioritization');
    }
    
    /**
     * Update progress
     */
    function updateProgress(completed, total, message, startTime) {
        var percentage = Math.round((completed / total) * 100);
        $('#freshrank-progress-bar').css('width', percentage + '%');

        // Calculate ETA
        var eta = '';
        if (startTime && completed > 0) {
            var elapsed = Date.now() - startTime;
            var avgTimePerItem = elapsed / completed;
            var remaining = total - completed;
            var etaMs = avgTimePerItem * remaining;

            // Format ETA
            if (etaMs > 60000) {
                var minutes = Math.round(etaMs / 60000);
                eta = ' • ETA: ' + minutes + ' min';
            } else {
                var seconds = Math.round(etaMs / 1000);
                eta = ' • ETA: ' + seconds + ' sec';
            }
        }

        $('#freshrank-progress-text').text(message + ' (' + percentage + '%' + eta + ')');
    }
    
    /**
     * Show notification with plain text (XSS safe)
     */
    function showNotification(message, type) {
        type = type || 'info';

        // Sanitize type to only allow valid WordPress notice types
        var validTypes = ['success', 'error', 'warning', 'info'];
        if (validTypes.indexOf(type) === -1) {
            type = 'info';
        }

        // Use .text() instead of HTML concatenation to prevent XSS
        var $notice = $('<div>')
            .addClass('notice notice-' + type + ' is-dismissible freshrank-notice')
            .append($('<p>').text(message)); // .text() escapes HTML

        // Add dismiss button
        $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>');

        $('.wrap h1').after($notice);

        // Handle dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });

        // Auto-dismiss after 5 seconds for non-error messages
        if (type !== 'error' && type !== 'warning') {
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    }

    /**
     * Remove existing FreshRank notices within the current settings scope.
     */
    function clearNotification(contextElement) {
        var $scope = $('.wrap');

        if (contextElement && contextElement.length) {
            var $closestWrap = contextElement.closest('.wrap');
            if ($closestWrap.length) {
                $scope = $closestWrap;
            }
        }

        $scope.find('.freshrank-notice').remove();
    }

    /**
     * Show notification with HTML content (for actionable error messages)
     * Only use when you need to display buttons/links and content is sanitized
     */
    function showNotificationHtml(htmlContent, type) {
        type = type || 'info';

        // Sanitize type to only allow valid WordPress notice types
        var validTypes = ['success', 'error', 'warning', 'info'];
        if (validTypes.indexOf(type) === -1) {
            type = 'info';
        }

        var $notice = $('<div>')
            .addClass('notice notice-' + type + ' is-dismissible freshrank-notice')
            .append($('<div>').html(htmlContent));

        // Add dismiss button
        $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>');

        $('.wrap h1').after($notice);

        // Handle dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });

        // Don't auto-dismiss error/warning messages with action buttons
        // User should manually dismiss after taking action
    }

    /**
     * View draft diff in a modal
     */
    function viewDraftDiff(e) {
        e.preventDefault();

        var draftId = $(this).data('draft-id');
        var originalId = $(this).data('original-id');
        var $button = $(this);

        $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>Loading...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_get_draft_diff',
                draft_id: draftId,
                original_id: originalId,
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).html('<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span> View Changes');

                if (response.success) {
                    // Create diff dialog if it doesn't exist
                    if ($('#freshrank-diff-dialog').length === 0) {
                        $('body').append('<div id="freshrank-diff-dialog" style="display:none;"></div>');
                    }

                    // Build diff HTML
                    var diffHtml = '<div class="freshrank-diff-container">' +
                        '<div class="freshrank-diff-pane freshrank-diff-original">' +
                        '<h3>Original</h3>' +
                        '<div class="freshrank-diff-content">' + response.data.original_html + '</div>' +
                        '</div>' +
                        '<div class="freshrank-diff-pane freshrank-diff-draft">' +
                        '<h3>Updated Draft</h3>' +
                        '<div class="freshrank-diff-content">' + response.data.draft_html + '</div>' +
                        '</div>' +
                        '</div>';

                    // Open dialog
                    $('#freshrank-diff-dialog').html(diffHtml).dialog({
                        title: 'Content Changes: ' + response.data.title,
                        width: Math.min(1200, $(window).width() * 0.95),
                        height: $(window).height() * 0.9,
                        modal: true,
                        closeText: 'Close',
                        closeOnEscape: true,
                        draggable: true,
                        resizable: true,
                        dialogClass: 'freshrank-diff-modal',
                        buttons: {
                            'Close': function() {
                                $(this).dialog('close');
                            }
                        },
                        open: function() {
                            $('.ui-widget-overlay').on('click', function() {
                                $('#freshrank-diff-dialog').dialog('close');
                            });
                        }
                    });

                    // Synchronize scrolling between panes
                    $('.freshrank-diff-content').on('scroll', function() {
                        var scrollTop = $(this).scrollTop();
                        $('.freshrank-diff-content').not(this).scrollTop(scrollTop);
                    });
                } else {
                    showNotification(response.data.message || 'Failed to load diff', 'error');
                }
            },
            error: function() {
                $button.prop('disabled', false).html('<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span> View Changes');
                showNotification('Failed to load diff', 'error');
            }
        });
    }

    /**
     * Refresh GSC data for single article
     */
    function refreshGscData() {
        var postId = $(this).data('post-id');
        var $button = $(this);
        var originalHtml = $button.html();

        if (!confirm('Refresh GSC data for this article? This will fetch fresh data from Google Search Console.')) {
            return;
        }

        $button.prop('disabled', true)
            .html('<span class="dashicons dashicons-update freshrank-spin" style="font-size: 14px; width: 14px; height: 14px; margin-top: 2px;"></span> Refreshing...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_refresh_gsc_single',
                post_id: postId,
                nonce: freshrank_ajax.nonce
            },
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification(response.data.message || 'Failed to refresh GSC data', 'error');
                    $button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function(xhr, status, error) {
                showNotification('Failed to refresh GSC data: ' + (xhr.responseJSON?.data?.message || error), 'error');
                $button.prop('disabled', false).html(originalHtml);
            }
        });
    }

    /**
     * Delete selected articles (bulk)
     */
    function deleteSelectedArticles(e) {
        e.preventDefault();

        var selectedCheckboxes = $('.freshrank-article-checkbox:checked');
        var selectedCount = selectedCheckboxes.length;

        if (selectedCount === 0) {
            showNotification('Please select at least one article to delete', 'error');
            return false;
        }

        // Confirmation dialog
        var confirmMsg = 'WARNING: This will permanently delete ' + selectedCount + ' article(s) and all their FreshRank data.\n\n';
        confirmMsg += 'This action cannot be undone!\n\n';
        confirmMsg += 'Are you sure you want to delete ' + selectedCount + ' article(s)?';

        if (!confirm(confirmMsg)) {
            return false;
        }

        var postIds = [];
        selectedCheckboxes.each(function() {
            postIds.push($(this).val());
        });

        var $button = $(this);
        var originalHtml = $button.html();

        // Add loading state
        $button.prop('disabled', true)
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Deleting...');

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_delete_bulk',
                post_ids: postIds,
                nonce: freshrank_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Fade out and remove all deleted rows
                    selectedCheckboxes.each(function() {
                        var $row = $(this).closest('tr.wsau-article-row');
                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });

                        // Also remove any expandable rows
                        $row.next('.wsau-priority-details-row').remove();
                        $row.next('.wsau-analysis-details-row').remove();
                        $row.next('.wsau-draft-details-row').remove();
                    });

                    var message = response.data.message || (response.data.deleted_count + ' articles deleted successfully');
                    showNotification(message, 'success');

                    // Reload page after 1 second to update statistics
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification('Error: ' + (response.data.message || 'Failed to delete articles'), 'error');
                    $button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function(xhr, status, error) {
                showNotification('Error: Failed to delete articles. Please try again.', 'error');
                $button.prop('disabled', false).html(originalHtml);
            }
        });
    }

    /**
     * Toggle provider settings visibility
     */
    function toggleProviderSettings(eventOrProvider) {
        var $openaiSettings = $('#freshrank-openai-settings');
        var $openrouterSettings = $('#freshrank-openrouter-settings');
        var provider = eventOrProvider;

        // Support being called as an event handler or with explicit provider value.
        if (eventOrProvider && eventOrProvider.target) {
            provider = $(eventOrProvider.target).val();
        }

        if (!provider) {
            provider = $('input[name="ai_provider"]:checked').val();
        }

        if (provider === 'openrouter') {
            $openaiSettings.hide();
            $openrouterSettings.show();
            loadOpenRouterModels(false);
        } else {
            $openrouterSettings.hide();
            $openaiSettings.show();
        }

    }

    /**
     * Load models from OpenRouter API and populate dropdowns.
     */
    function loadOpenRouterModels(forceRefresh, options) {
        forceRefresh = !!forceRefresh;
        options = options || {};

        var $analysisSelect = $('#openrouter_model_analysis');
        var $writingSelect = $('#openrouter_model_writing');

        if (!$analysisSelect.length || !$writingSelect.length) {
            return;
        }

        // Only update cache if current select has a value (don't overwrite with empty)
        var currentAnalysisVal = $analysisSelect.val();
        var currentWritingVal = $writingSelect.val();

        if (currentAnalysisVal) {
            openRouterModelSelections.analysis = currentAnalysisVal;
        }
        if (currentWritingVal) {
            openRouterModelSelections.writing = currentWritingVal;
        }

        var loadingLabel = (freshrank_ajax.strings && freshrank_ajax.strings.loading_models) ? freshrank_ajax.strings.loading_models : 'Loading models...';

        if (!options.silent) {
            $analysisSelect.empty().append($('<option>', { value: '', text: loadingLabel }));
            $writingSelect.empty().append($('<option>', { value: '', text: loadingLabel }));
        }

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_get_openrouter_models',
                nonce: freshrank_ajax.nonce,
                force_refresh: forceRefresh ? 1 : 0
            },
            success: function(response) {
                var success = false;

                if (response && response.success && response.data && response.data.models) {
                    openRouterModelsCache = $.isArray(response.data.models) ? response.data.models : [];
                    openRouterModelsLastRefresh = Date.now();
                    populateOpenRouterDropdowns('analysis');
                    populateOpenRouterDropdowns('writing');
                    showNotification('Model list refreshed successfully', 'success');
                    success = openRouterModelsCache.length > 0;
                } else {
                    openRouterModelsCache = [];
                    populateOpenRouterDropdowns('analysis');
                    populateOpenRouterDropdowns('writing');
                }

                if (!success && !options.silent) {
                    showNotification((freshrank_ajax.strings && freshrank_ajax.strings.models_load_failed) ? freshrank_ajax.strings.models_load_failed : 'Failed to load OpenRouter models.', 'error');
                }

                if (options.onComplete) {
                    options.onComplete(success, response);
                }
            },
            error: function(xhr, status, error) {
                if (!options.silent) {
                    var errorMessage = (freshrank_ajax.strings && freshrank_ajax.strings.models_load_failed) ? freshrank_ajax.strings.models_load_failed : 'Failed to load OpenRouter models.';
                    showNotification(errorMessage + ' ' + error, 'error');
                }

                if (options.onComplete) {
                    options.onComplete(false);
                }
            }
        });
    }

    /**
     * Initialize OpenRouter selection cache from saved values in HTML
     */
    function initializeOpenRouterModelState() {
        if ($('#openrouter_model_analysis').length) {
            var analysisVal = $('#openrouter_model_analysis').val();
            // Initialize cache with saved value from PHP (don't overwrite if already set)
            if (analysisVal && !openRouterModelSelections.analysis) {
                openRouterModelSelections.analysis = analysisVal;
            }
        }

        if ($('#openrouter_model_writing').length) {
            var writingVal = $('#openrouter_model_writing').val();
            // Initialize cache with saved value from PHP (don't overwrite if already set)
            if (writingVal && !openRouterModelSelections.writing) {
                openRouterModelSelections.writing = writingVal;
            }
        }
    }

    /**
     * Render OpenRouter select options using cached model list
     */
    function populateOpenRouterDropdowns(target) {
        var $select = target === 'writing' ? $('#openrouter_model_writing') : $('#openrouter_model_analysis');
        if (!$select.length) {
            return;
        }

        var $searchInput = target === 'writing' ? $('#openrouter_model_writing_search') : $('#openrouter_model_analysis_search');
        var searchTerm = '';
        if ($searchInput.length) {
            searchTerm = ($searchInput.val() || '').toLowerCase();
        }

        var selectedValue = openRouterModelSelections[target] || '';
        var selectPlaceholder = $select.data('placeholder') || 'Select a model...';
        var noModelsMessage = (freshrank_ajax.strings && freshrank_ajax.strings.models_unavailable) ? freshrank_ajax.strings.models_unavailable : 'No models available. Try refreshing the list.';
        var noMatchesMessage = (freshrank_ajax.strings && freshrank_ajax.strings.no_models_found) ? freshrank_ajax.strings.no_models_found : 'No models match your search.';
        var savedSelectionLabel = (freshrank_ajax.strings && freshrank_ajax.strings.model_saved_placeholder) ? freshrank_ajax.strings.model_saved_placeholder : 'Previously saved selection (not in latest list)';

        $select.empty();
        $select.append($('<option>', { value: '', text: selectPlaceholder }));

        var renderedOptionCount = 1; // Placeholder already added

        if (!openRouterModelsCache || !openRouterModelsCache.length) {
            $select.append($('<option>', { value: '', text: noModelsMessage, disabled: true }));
            renderedOptionCount++;

            if (selectedValue) {
                $select.append($('<option>', {
                    value: selectedValue,
                    text: selectedValue + ' — ' + savedSelectionLabel,
                    'data-fallback': '1'
                }));
                renderedOptionCount++;
                $select.val(selectedValue);
                openRouterModelSelections[target] = selectedValue;
            } else {
                $select.val('');
                openRouterModelSelections[target] = '';
            }

            setOpenRouterSelectExpanded($select, searchTerm !== '', renderedOptionCount);
            return;
        }

        var filtered = [];
        for (var i = 0; i < openRouterModelsCache.length; i++) {
            var model = openRouterModelsCache[i];
            var haystack = ((model.name || '') + ' ' + (model.id || '')).toLowerCase();

            if (!searchTerm || haystack.indexOf(searchTerm) !== -1) {
                filtered.push(model);
            }
        }

        var selectedModel = null;
        if (selectedValue) {
            for (var j = 0; j < openRouterModelsCache.length; j++) {
                if (openRouterModelsCache[j].id === selectedValue) {
                    selectedModel = openRouterModelsCache[j];
                    break;
                }
            }
        }

        var hasSelectedInFiltered = false;
        if (selectedModel) {
            for (var k = 0; k < filtered.length; k++) {
                if (filtered[k].id === selectedModel.id) {
                    hasSelectedInFiltered = true;
                    break;
                }
            }
        }

        if (selectedModel && searchTerm && !hasSelectedInFiltered) {
            filtered.unshift(selectedModel);
        }

        if (!filtered.length) {
            $select.append($('<option>', { value: '', text: noMatchesMessage, disabled: true }));
            renderedOptionCount++;

            if (selectedValue) {
                $select.append($('<option>', {
                    value: selectedValue,
                    text: selectedValue + ' — ' + savedSelectionLabel,
                    'data-fallback': '1'
                }));
                renderedOptionCount++;
                $select.val(selectedValue);
                openRouterModelSelections[target] = selectedValue;
            } else {
                $select.val('');
                openRouterModelSelections[target] = '';
            }

            setOpenRouterSelectExpanded($select, searchTerm !== '', renderedOptionCount);
            return;
        }

        for (var m = 0; m < filtered.length; m++) {
            var modelEntry = filtered[m];
            if (!modelEntry.id) {
                continue;
            }
            var labelPieces = [];
            var modelName = modelEntry.name || modelEntry.id || '';

            if (modelEntry.name && modelEntry.id) {
                labelPieces.push(modelEntry.name + ' (' + modelEntry.id + ')');
            } else {
                labelPieces.push(modelName);
            }

            var costLabel = buildOpenRouterCostLabel(modelEntry);
            if (costLabel) {
                labelPieces.push(costLabel);
            }

            if (modelEntry.usage_label) {
                labelPieces.push(modelEntry.usage_label);
            } else if (modelEntry.rank_label) {
                labelPieces.push(modelEntry.rank_label);
            } else if (typeof modelEntry.rank === 'number') {
                var rankFallback = (freshrank_ajax.strings && freshrank_ajax.strings.rank_fallback) ? freshrank_ajax.strings.rank_fallback : 'Rank #%s';
                labelPieces.push(rankFallback.replace('%s', modelEntry.rank));
            }

            $select.append($('<option>', {
                value: modelEntry.id,
                text: labelPieces.join(' — ')
            }));
            renderedOptionCount++;
        }

        var hasMatchingOption = false;
        if (selectedValue) {
            hasMatchingOption = $select.find('option').filter(function() {
                return $(this).val() === selectedValue;
            }).length > 0;
        }

        if (selectedValue && !hasMatchingOption) {
            $select.append($('<option>', {
                value: selectedValue,
                text: selectedValue + ' — ' + savedSelectionLabel,
                'data-fallback': '1'
            }));
            renderedOptionCount++;
            hasMatchingOption = true;
        }

        if (hasMatchingOption) {
            $select.val(selectedValue);
        }

        var currentValue = $select.val() || '';
        if (!currentValue && selectedValue) {
            currentValue = selectedValue;
        }

        openRouterModelSelections[target] = currentValue;
        setOpenRouterSelectExpanded($select, searchTerm !== '', renderedOptionCount);
    }

    /**
     * Filter model list when search input changes
     */
    function handleOpenRouterSearch() {
        var target = $(this).data('target');
        if (!target) {
            target = this.id === 'openrouter_model_writing_search' ? 'writing' : 'analysis';
        }

        populateOpenRouterDropdowns(target);
    }

    /**
     * Cache selection state so it survives re-renders
     */
    function rememberOpenRouterSelection() {
        var $select = $(this);
        var target = this.id === 'openrouter_model_writing' ? 'writing' : 'analysis';
        openRouterModelSelections[target] = $select.val() || '';

        // Collapse the selector after choosing a model
        setOpenRouterSelectExpanded($select, false, 0);

        // Blur to mimic native dropdown closing behaviour
        setTimeout(function() {
            if ($select.length) {
                $select.removeAttr('size').removeClass('freshrank-model-open').blur();
            }
        }, 0);
    }

    /**
     * Expand or collapse the select element to mimic an open dropdown.
     */
    function setOpenRouterSelectExpanded($select, expanded, optionCount) {
        if (!$select.length) {
            return;
        }

        if (!expanded || optionCount <= 1) {
            $select.removeClass('freshrank-model-open');
            $select.removeAttr('size');
            return;
        }

        var visibleRows = Math.min(optionCount, 10); // Cap to avoid giant lists
        $select.addClass('freshrank-model-open');
        $select.attr('size', visibleRows);
    }

    /**
     * Build cost label for dropdown option
     */
    function buildOpenRouterCostLabel(modelEntry) {
        if (!modelEntry || !modelEntry.pricing_has_data) {
            return '';
        }

        var promptCost = parseFloat(modelEntry.pricing_prompt_per_1k);
        var completionCost = parseFloat(modelEntry.pricing_completion_per_1k);
        var currencySymbol = modelEntry.pricing_currency_symbol || '';
        var currencyCode = (modelEntry.pricing_currency || '').toUpperCase();
        var parts = [];

        if (isFinite(promptCost) && promptCost > 0) {
            parts.push(formatOpenRouterCostFragment(promptCost, currencySymbol, currencyCode, 'prompt'));
        }

        if (isFinite(completionCost) && completionCost > 0) {
            parts.push(formatOpenRouterCostFragment(completionCost, currencySymbol, currencyCode, 'completion'));
        }

        if (!parts.length) {
            return '';
        }

        var separator = (freshrank_ajax.strings && freshrank_ajax.strings.cost_estimate_separator) ? freshrank_ajax.strings.cost_estimate_separator : ' • ';

        return parts.join(separator);
    }

    function formatOpenRouterCostFragment(costValue, currencySymbol, currencyCode, type) {
        var amount = formatOpenRouterCostAmount(costValue, currencySymbol, currencyCode);
        if (!amount) {
            return '';
        }

        var template = (freshrank_ajax.strings && freshrank_ajax.strings.cost_estimate_fragment) ? freshrank_ajax.strings.cost_estimate_fragment : 'Approx. %1$s / 1K %2$s';
        var suffix = getOpenRouterCostSuffix(type);

        return template.replace('%1$s', amount).replace('%2$s', suffix);
    }

    function formatOpenRouterCostAmount(costValue, currencySymbol, currencyCode) {
        if (!isFinite(costValue) || costValue <= 0) {
            return '';
        }

        var formattedNumber = formatOpenRouterCostNumber(costValue);

        if (currencySymbol) {
            return currencySymbol + formattedNumber;
        }

        if (currencyCode) {
            return formattedNumber + ' ' + currencyCode;
        }

        return formattedNumber;
    }

    function formatOpenRouterCostNumber(costValue) {
        var decimals;

        if (costValue >= 1) {
            decimals = 2;
        } else if (costValue >= 0.1) {
            decimals = 3;
        } else if (costValue >= 0.01) {
            decimals = 4;
        } else if (costValue >= 0.001) {
            decimals = 5;
        } else {
            decimals = 6;
        }

        var multiplier = Math.pow(10, decimals);
        var rounded = Math.round(costValue * multiplier) / multiplier;

        return rounded.toString();
    }

    function getOpenRouterCostSuffix(type) {
        if (type === 'completion') {
            return (freshrank_ajax.strings && freshrank_ajax.strings.cost_completion_suffix) ? freshrank_ajax.strings.cost_completion_suffix : 'completion tokens';
        }

        return (freshrank_ajax.strings && freshrank_ajax.strings.cost_prompt_suffix) ? freshrank_ajax.strings.cost_prompt_suffix : 'prompt tokens';
    }

    /**
     * Test OpenRouter connection
     */
    function testOpenRouterConnection() {
        var $button = $('#freshrank-test-openrouter-connection');
        var $spinner = $button.find('.dashicons');
        var apiKey = $('#openrouter_api_key').val(); // Get current value from input

        $button.prop('disabled', true);
        $spinner.addClass('spin');
        clearNotification($button);

        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                'action': 'freshrank_test_openrouter_connection',
                'nonce': freshrank_ajax.nonce,
                'api_key': apiKey // Send current key for testing before save
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message || 'Connection successful!', 'success', $button);
                } else {
                    showNotification(response.data.message || 'Connection failed', 'error', $button);
                }
            },
            error: function(xhr, status, error) {
                showNotification('AJAX error: ' + error, 'error', $button);
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('spin');
            }
        });
    }

    /**
     * Refresh OpenRouter models list.
     *
     * @since 2.0.2
     */
    function refreshOpenRouterModels() {
        loadOpenRouterModels(true);
    }

    /**
     * Handle custom model ID input
     */
    function handleCustomModelInput() {
        var $input = $(this);
        var value = $input.val().trim();

        if (value) {
            $input.css('border-color', '#46b450'); // Green border
        } else {
            $input.css('border-color', ''); // Reset to default
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        // Silent initialization - no console output
    });

})(jQuery);
// Dashboard Filters
jQuery(document).ready(function($) {
    // Handle filter reset button
    $('#freshrank-reset-filters').on('click', function() {
        // Reset form
        $('#freshrank-filter-form')[0].reset();
        
        // Redirect to page without filters
        var adminUrl = typeof freshrank_ajax !== 'undefined' ? freshrank_ajax.ajax_url.replace('/admin-ajax.php', '') : '/wp-admin';
        window.location = adminUrl + '/admin.php?page=freshrank-ai';
    });

    // Remember filter state (optional)
    $('#freshrank-filter-form').on('submit', function() {
        if (typeof localStorage !== 'undefined') {
            var filters = {
                author: $('#author-filter').val(),
                category: $('#category-filter').val(),
                period: $('#period-filter').val(),
                search: $('#post-search-input').val()
            };
            localStorage.setItem('freshrank_last_filters', JSON.stringify(filters));
        }
    });
});

// ==================================================================
// Analysis Item Management - Dismiss/Restore & View Toggle
// ==================================================================

jQuery(document).ready(function($) {
    
    /**
     * Handle Dismiss Item Button Click
     */
    $(document).on('click', '.freshrank-dismiss-item', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $issueItem = $button.closest('.wsau-issue');
        var postId = $issueItem.data('post-id');
        var category = $issueItem.data('category');
        var index = $issueItem.data('index');
        
        
        if( 
            $issueItem.hasClass('freshrank-issue-status-category_disabled') || 
            $issueItem.hasClass('freshrank-issue-status-severity_filtered') 
        ) {
            return false;
        }

        // Add loading state
        $button.addClass('loading').prop('disabled', true);
        
        // Make AJAX request
        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_dismiss_item',
                nonce: freshrank_ajax.nonce,
                post_id: postId,
                category: category,
                index: index
            },
            success: function(response) {
                if (response.success) {
                    // Update item status (update both attr and data to ensure jQuery data cache is updated)
                    $issueItem.attr('data-status', 'dismissed')
                        .data('status', 'dismissed')
                        .removeClass('freshrank-issue-status-actionable freshrank-issue-status-severity_filtered freshrank-issue-status-category_disabled')
                        .addClass('freshrank-issue-status-dismissed');

                    // Replace dismiss button with restore button
                    $button.removeClass('freshrank-dismiss-item loading')
                        .addClass('freshrank-restore-item')
                        .attr('title', 'Restore this item')
                        .html('<span class="dashicons dashicons-undo"></span>')
                        .prop('disabled', false);
                    
                    $issueItem.addClass('freshrank-issue-status-dismissed');                    
                    
                    // Hide item after animation if in actionable view
                    var currentView = getCurrentView(postId);

                    if (currentView === 'actionable') {
                        setTimeout(function() {
                            $issueItem.hide();
                            updateBadgeCounts(postId);
                        }, 300);
                    } else {
                        updateBadgeCounts(postId);
                    }
                    
                } else {
                    alert('Failed to dismiss item: ' + (response.data.message || 'Unknown error'));
                    $button.removeClass('loading').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                alert('Error dismissing item: ' + error);
                $button.removeClass('loading').prop('disabled', false);
            }
        });
    });
    
    /**
     * Handle Restore Item Button Click
     */
    $(document).on('click', '.freshrank-restore-item', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $issueItem = $button.closest('.wsau-issue');
        var postId = $issueItem.data('post-id');
        var category = $issueItem.data('category');
        var index = $issueItem.data('index');
        
        if( 
            $issueItem.hasClass('freshrank-issue-status-category_disabled') || 
            $issueItem.hasClass('freshrank-issue-status-severity_filtered') 
        ) {
            return false;
        }

        // Add loading state
        $button.addClass('loading').prop('disabled', true);
        
        // Make AJAX request
        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_restore_item',
                nonce: freshrank_ajax.nonce,
                post_id: postId,
                category: category,
                index: index
            },
            success: function(response) {
                if (response.success) {
                    // Determine new status (would need to check filters, but for now mark as actionable)
                    $issueItem.attr('data-status', 'actionable')
                        .data('status', 'actionable')
                        .removeClass('freshrank-issue-status-dismissed freshrank-issue-status-severity_filtered freshrank-issue-status-category_disabled')
                        .addClass('freshrank-issue-status-actionable');

                    // Replace restore button with dismiss button
                    $button.removeClass('freshrank-restore-item loading')
                        .addClass('freshrank-dismiss-item')
                        .attr('title', 'Dismiss this item')
                        .html('×')
                        .prop('disabled', false);
                    
                    // Add fade in animation
                    setTimeout(function() {
                        $issueItem.removeClass('freshrank-issue-fading-in');
                    }, 300);
                    
                    // Hide item if in dismissed view
                    var currentView = getCurrentView(postId);
                    if (currentView === 'dismissed') {
                        setTimeout(function() {
                            $issueItem.hide();
                            updateBadgeCounts(postId);
                        }, 300);
                    } else {
                        updateBadgeCounts(postId);
                    }
                    
                } else {
                    alert('Failed to restore item: ' + (response.data.message || 'Unknown error'));
                    $button.removeClass('loading').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                alert('Error restoring item: ' + error);
                $button.removeClass('loading').prop('disabled', false);
            }
        });
    });
    
    /**
     * Handle View Toggle Change
     */
    $(document).on('change', '.freshrank-view-selector', function() {
        var $selector = $(this);
        var postId = $selector.data('post-id');
        var view = $selector.val();
        
        // Update the data attribute on the analysis container
        var $analysis = $selector.closest('.wsau-detailed-analysis');
        $analysis.attr('data-view', view);
        
        // Show/hide items based on view
        filterItemsByView($analysis, view);
        
        // Save preference via AJAX
        $.ajax({
            url: freshrank_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'freshrank_set_view_preference',
                nonce: freshrank_ajax.nonce,
                view: view
            },
            success: function(response) {
                if (!response.success) {
                    if (typeof FreshRankDebug !== 'undefined' && FreshRankDebug) {
                        console.error('Failed to save view preference:', response.data);
                    }
                }
            },
            error: function(xhr, status, error) {
                if (typeof FreshRankDebug !== 'undefined' && FreshRankDebug) {
                    console.error('Error saving view preference:', error);
                }
            }
        });
    });
    
    /**
     * Filter items based on view selection
     */
    function filterItemsByView($analysis, view) {
        var $items = $analysis.find('.wsau-issue');
        
        $items.each(function() {
            var $item = $(this);
            var status = $item.data('status');
            var shouldShow = false;
            
            if (view === 'actionable') {
                shouldShow = (status === 'actionable');
            } else if (view === 'dismissed') {
                shouldShow = (status === 'dismissed');
            } else { // 'all'
                shouldShow = true;
            }
            
            if (shouldShow) {
                $item.fadeIn(200);
            } else {
                $item.fadeOut(200);
            }
        });
    }
    
    /**
     * Get current view preference for a post
     */
    function getCurrentView(postId) {
        var $analysis = $('.wsau-detailed-analysis[data-post-id="' + postId + '"]');
        return $analysis.attr('data-view') || 'actionable';
    }
    
    /**
     * Update badge counts after dismiss/restore
     * Note: This is a simple client-side update. For accuracy, a page reload or AJAX refresh would be better.
     */
    function updateBadgeCounts(postId) {
        var $analysis = $('.wsau-detailed-analysis[data-post-id="' + postId + '"]');
        
        // Count visible actionable items
        var actionableCount = $analysis.find('.freshrank-issue-status-actionable:visible').length;
        var dismissedCount = $analysis.find('.freshrank-issue-status-dismissed').length;
        var filteredCount = $analysis.find('.freshrank-issue-status-severity_filtered, .freshrank-issue-status-category_disabled').length;
        
        // Update badge text
        var $actionableBadge = $analysis.find('.badge-actionable');
        if (actionableCount > 0) {
            $actionableBadge.text('✓ Showing ' + actionableCount + ' actionable issues');
        } else {
            $actionableBadge.removeClass('badge-actionable').addClass('badge-info')
                .text('No actionable issues');
        }
        
        var $filteredBadge = $analysis.find('.badge-secondary');
        var hiddenCount = dismissedCount + filteredCount;
        if (hiddenCount > 0) {
            $filteredBadge.text(hiddenCount + ' filtered out').show();
        } else {
            $filteredBadge.hide();
        }
        
        // Update category counts
        $analysis.find('.wsau-analysis-section').each(function() {
            var $section = $(this);
            var $items = $section.find('.wsau-issue');
            var actionable = $items.filter('.freshrank-issue-status-actionable').length;
            var total = $items.length;
            var filtered = total - actionable;
            
            $section.find('.category-count').text('(' + actionable + ' actionable, ' + filtered + ' filtered out)');
        });
    }
    
    /**
     * Initialize view on page load
     */
    $('.wsau-detailed-analysis').each(function() {
        var $analysis = $(this);
        var $selector = $analysis.find('.freshrank-view-selector');
        var view = $selector.val() || 'actionable';
        
        $analysis.attr('data-view', view);
        filterItemsByView($analysis, view);
    });
});
