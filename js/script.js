/**
 * AirBnB Listing Analyzer JavaScript
 */

jQuery(document).ready(function($) {
    // Store the listing URL
    var listingUrl = '';
    
    // Handle "Continue" button click (Step 1 to Step 2)
    $('#airbnb-analyzer-next-step').on('click', function() {
        listingUrl = $('#airbnb-listing-url').val();
        
        if (!listingUrl) {
            alert('Please enter a valid AirBnB listing URL');
            return;
        }
        
        // Hide Step 1, show Step 2
        $('#airbnb-analyzer-step-1').hide();
        $('#airbnb-analyzer-step-2').show();
    });
    
    // Handle "Back" button click (Step 2 to Step 1)
    $('#airbnb-analyzer-back').on('click', function() {
        $('#airbnb-analyzer-step-2').hide();
        $('#airbnb-analyzer-step-1').show();
    });
    
    // Handle form submission
    $('#airbnb-analyzer-submit').on('click', function() {
        var email = $('#airbnb-analyzer-email').val();
        var captchaResponse = grecaptcha.getResponse();
        
        if (!email) {
            alert('Please enter your email address');
            return;
        }
        
        if (!captchaResponse) {
            alert('Please complete the CAPTCHA');
            return;
        }
        
        // Show loading indicator
        $('.airbnb-analyzer-step').hide();
        $('.airbnb-analyzer-loading').show();
        
        // Send AJAX request
        $.ajax({
            url: airbnb_analyzer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'analyze_airbnb_listing',
                nonce: airbnb_analyzer_ajax.nonce,
                listing_url: listingUrl,
                email: email,
                captcha: captchaResponse
            },
            success: function(response) {
                // Hide loading indicator
                $('.airbnb-analyzer-loading').hide();
                
                if (response.success) {
                    if (response.data.status === 'pending') {
                        // Show pending status message
                        displayPendingMessage(response.data);
                    } else {
                        // Display results (for backward compatibility)
                        displayResults(response.data);
                    }
                } else {
                    // Show error message
                    alert(response.data.message || 'An error occurred. Please try again.');
                    // Go back to step 1
                    $('#airbnb-analyzer-step-1').show();
                }
            },
            error: function() {
                // Hide loading indicator
                $('.airbnb-analyzer-loading').hide();
                
                // Show error message
                alert('An error occurred. Please try again.');
                
                // Go back to step 1
                $('#airbnb-analyzer-step-1').show();
            }
        });
    });
    
    /**
     * Display pending message for async processing
     */
    function displayPendingMessage(data) {
        var html = '';
        
        html += '<div class="airbnb-analyzer-pending">';
        html += '<div class="pending-icon">⏳</div>';
        html += '<h3>Analysis in Progress</h3>';
        html += '<p>' + data.message + '</p>';
        html += '<div class="pending-details">';
        html += '<p><strong>What happens next:</strong></p>';
        html += '<ul>';
        html += '<li>Our system is now scraping your Airbnb listing data</li>';
        html += '<li>This process takes 1-2 minutes to complete</li>';
        
        if (data.test_mode) {
            html += '<li><strong>Test Mode Active:</strong> Email notifications are disabled. Check the WordPress admin dashboard for results.</li>';
            html += '<li>To receive email notifications, disable test mode in the plugin settings</li>';
        } else {
            html += '<li>Once finished, we\'ll analyze your listing and send the results to your email</li>';
            html += '<li>You can close this page - the analysis will continue in the background</li>';
        }
        
        html += '</ul>';
        html += '</div>';
        if (data.snapshot_id) {
            html += '<div class="pending-reference">';
            html += '<p><small>Reference ID: ' + data.snapshot_id + '</small></p>';
            html += '</div>';
        }
        html += '<div class="pending-actions">';
        html += '<button type="button" id="analyze-another" class="button">Analyze Another Listing</button>';
        html += '</div>';
        html += '</div>';
        
        // Set the HTML and show results container
        var resultsContainer = $('#airbnb-analyzer-results');
        resultsContainer.html(html).show();
        
        // Handle "Analyze Another" button
        $('#analyze-another').on('click', function() {
            // Reset form
            $('#airbnb-listing-url').val('');
            $('#airbnb-analyzer-email').val('');
            if (typeof grecaptcha !== 'undefined') {
                grecaptcha.reset();
            }
            
            // Hide results and show step 1
            $('#airbnb-analyzer-results').hide();
            $('#airbnb-analyzer-step-1').show();
        });
    }

    /**
     * Display analysis results
     */
    function displayResults(data) {
        var html = '';
        
        // Add listing title if available
        if (data.listing_data && data.listing_data.title) {
            html += '<div class="airbnb-analyzer-listing-title">';
            html += '<h2>' + data.listing_data.title + '</h2>';
            html += '</div>';
        }
        
        // Add header with score
        html += '<div class="airbnb-analyzer-header">';
        html += '<h3>Analysis Results</h3>';
        html += '<div class="airbnb-analyzer-score">';
        html += '<div class="score-circle ' + getScoreClass(data.score) + '">' + data.score + '</div>';
        html += '<p>' + data.summary + '</p>';
        html += '</div>';
        html += '</div>';
        
        // Add listing photo if available
        if (data.listing_data && data.listing_data.photos && data.listing_data.photos.length > 0) {
            html += '<div class="airbnb-analyzer-photo">';
            html += '<img src="' + data.listing_data.photos[0] + '" alt="Listing Photo">';
            html += '</div>';
        } else if (data.first_photo) {
            html += '<div class="airbnb-analyzer-photo">';
            html += '<img src="' + data.first_photo + '" alt="Listing Photo">';
            html += '</div>';
        }
        
        // Add Claude AI insights if available
        if (data.has_claude_analysis) {
            html += '<div class="airbnb-analyzer-claude-insights">';
            html += '<h3>AI-Powered Insights</h3>';
            
            // Title analysis
            if (data.claude_analysis.title) {
                html += '<div class="claude-section">';
                html += '<h4>Title Analysis</h4>';
                html += '<div class="claude-rating">Rating: <span class="' + getScoreClass(data.claude_analysis.title.rating * 10) + '">' + data.claude_analysis.title.rating + '/10</span></div>';
                html += '<p>' + data.claude_analysis.title.feedback + '</p>';
                
                if (data.claude_analysis.title.alternative_titles && data.claude_analysis.title.alternative_titles.length > 0) {
                    html += '<div class="claude-alternatives">';
                    html += '<h5>Suggested Alternatives:</h5>';
                    html += '<ul>';
                    data.claude_analysis.title.alternative_titles.forEach(function(title) {
                        html += '<li>' + title + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                html += '</div>';
            }
            
            // Description analysis
            if (data.claude_analysis.description) {
                html += '<div class="claude-section">';
                html += '<h4>Description Analysis</h4>';
                html += '<div class="claude-rating">Rating: <span class="' + getScoreClass(data.claude_analysis.description.rating * 10) + '">' + data.claude_analysis.description.rating + '/10</span></div>';
                html += '<h5>First Impression (First 400 characters):</h5>';
                html += '<p>' + data.claude_analysis.description.first_impression + '</p>';
                html += '<h5>Overall Feedback:</h5>';
                html += '<p>' + data.claude_analysis.description.overall_feedback + '</p>';
                
                if (data.claude_analysis.description.suggestions && data.claude_analysis.description.suggestions.length > 0) {
                    html += '<div class="claude-suggestions">';
                    html += '<h5>Improvement Suggestions:</h5>';
                    html += '<ul>';
                    data.claude_analysis.description.suggestions.forEach(function(suggestion) {
                        html += '<li>' + suggestion + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                html += '</div>';
            }
            
            // Host analysis
            if (data.claude_analysis.host) {
                html += '<div class="claude-section">';
                html += '<h4>Host Profile Analysis</h4>';
                html += '<div class="claude-rating">Completeness: <span class="' + getScoreClass(data.claude_analysis.host.completeness_score * 10) + '">' + data.claude_analysis.host.completeness_score + '/10</span></div>';
                
                html += '<h5>Bio Feedback:</h5>';
                html += '<p>' + data.claude_analysis.host.bio_feedback + '</p>';
                
                html += '<h5>Response Feedback:</h5>';
                html += '<p>' + data.claude_analysis.host.response_feedback + '</p>';
                
                html += '<h5>Highlights Feedback:</h5>';
                html += '<p>' + data.claude_analysis.host.highlights_feedback + '</p>';
                
                if (data.claude_analysis.host.neighborhood_feedback) {
                    html += '<h5>Neighborhood Feedback:</h5>';
                    html += '<p>' + data.claude_analysis.host.neighborhood_feedback + '</p>';
                }
                
                if (data.claude_analysis.host.rating_feedback) {
                    html += '<h5>Rating Feedback:</h5>';
                    html += '<p>' + data.claude_analysis.host.rating_feedback + '</p>';
                }
                
                if (data.claude_analysis.host.suggestions && data.claude_analysis.host.suggestions.length > 0) {
                    html += '<div class="claude-suggestions">';
                    html += '<h5>Improvement Suggestions:</h5>';
                    html += '<ul>';
                    data.claude_analysis.host.suggestions.forEach(function(suggestion) {
                        html += '<li>' + suggestion + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                
                html += '</div>';
            }
            
            // Amenities analysis
            if (data.claude_analysis.amenities) {
                html += '<div class="claude-section">';
                html += '<h4>Amenities Analysis</h4>';
                html += '<div class="claude-rating">Score: <span class="' + getScoreClass(data.claude_analysis.amenities.score * 10) + '">' + data.claude_analysis.amenities.score + '/10</span></div>';
                
                html += '<h5>Overall Feedback:</h5>';
                html += '<p>' + data.claude_analysis.amenities.overall_feedback + '</p>';
                
                if (data.claude_analysis.amenities.category_analysis) {
                    html += '<h5>Category Analysis:</h5>';
                    html += '<div class="amenities-categories">';
                    
                    for (const [category, feedback] of Object.entries(data.claude_analysis.amenities.category_analysis)) {
                        html += '<div class="amenity-category">';
                        html += '<h6>' + category + '</h6>';
                        html += '<p>' + feedback + '</p>';
                        html += '</div>';
                    }
                    
                    html += '</div>';
                }
                
                if (data.claude_analysis.amenities.standout_amenities && data.claude_analysis.amenities.standout_amenities.length > 0) {
                    html += '<h5>Standout Amenities:</h5>';
                    html += '<ul class="standout-amenities">';
                    data.claude_analysis.amenities.standout_amenities.forEach(function(amenity) {
                        html += '<li>' + amenity + '</li>';
                    });
                    html += '</ul>';
                }
                
                if (data.claude_analysis.amenities.missing_essentials && data.claude_analysis.amenities.missing_essentials.length > 0) {
                    html += '<div class="claude-suggestions">';
                    html += '<h5>Missing Essential Amenities:</h5>';
                    html += '<ul>';
                    data.claude_analysis.amenities.missing_essentials.forEach(function(amenity) {
                        html += '<li>' + amenity + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                
                if (data.claude_analysis.amenities.suggestions && data.claude_analysis.amenities.suggestions.length > 0) {
                    html += '<div class="claude-suggestions">';
                    html += '<h5>Improvement Suggestions:</h5>';
                    html += '<ul>';
                    data.claude_analysis.amenities.suggestions.forEach(function(suggestion) {
                        html += '<li>' + suggestion + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                
                html += '</div>';
            }
            
            // Reviews analysis
            if (data.claude_analysis.reviews) {
                html += '<div class="claude-section">';
                html += '<h4>Reviews Analysis</h4>';
                html += '<div class="claude-rating">Score: <span class="' + getScoreClass(data.claude_analysis.reviews.score * 10) + '">' + data.claude_analysis.reviews.score + '/10</span></div>';
                
                html += '<h5>Overall Feedback:</h5>';
                html += '<p>' + data.claude_analysis.reviews.overall_feedback + '</p>';
                
                html += '<h5>Review Quantity:</h5>';
                html += '<p>' + data.claude_analysis.reviews.quantity_feedback + '</p>';
                
                if (data.claude_analysis.reviews.favorite_status_feedback) {
                    html += '<h5>Guest Favorite Status:</h5>';
                    html += '<p>' + data.claude_analysis.reviews.favorite_status_feedback + '</p>';
                }
                
                if (data.claude_analysis.reviews.strengths && data.claude_analysis.reviews.strengths.length > 0) {
                    html += '<h5>Key Strengths:</h5>';
                    html += '<ul class="review-strengths">';
                    data.claude_analysis.reviews.strengths.forEach(function(strength) {
                        html += '<li>' + strength + '</li>';
                    });
                    html += '</ul>';
                }
                
                if (data.claude_analysis.reviews.improvement_areas && data.claude_analysis.reviews.improvement_areas.length > 0) {
                    html += '<div class="claude-suggestions">';
                    html += '<h5>Areas for Improvement:</h5>';
                    html += '<ul>';
                    data.claude_analysis.reviews.improvement_areas.forEach(function(area) {
                        html += '<li>' + area + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                
                if (data.claude_analysis.reviews.strategies && data.claude_analysis.reviews.strategies.length > 0) {
                    html += '<div class="claude-suggestions">';
                    html += '<h5>Strategies to Improve:</h5>';
                    html += '<ul>';
                    data.claude_analysis.reviews.strategies.forEach(function(strategy) {
                        html += '<li>' + strategy + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                
                html += '</div>';
            }
            
            // Cancellation Policy analysis
            if (data.claude_analysis.cancellation) {
                html += '<div class="claude-section">';
                html += '<h4>Cancellation Policy Analysis</h4>';
                html += '<div class="claude-rating">Score: <span class="' + getScoreClass(data.claude_analysis.cancellation.score * 10) + '">' + data.claude_analysis.cancellation.score + '/10</span></div>';
                
                html += '<h5>Overall Feedback:</h5>';
                html += '<p>' + data.claude_analysis.cancellation.overall_feedback + '</p>';
                
                html += '<h5>Impact on Booking Conversion:</h5>';
                html += '<p>' + data.claude_analysis.cancellation.conversion_impact + '</p>';
                
                html += '<h5>Protection vs. Flexibility Balance:</h5>';
                html += '<p>' + data.claude_analysis.cancellation.protection_balance + '</p>';
                
                if (data.claude_analysis.cancellation.instant_book_feedback) {
                    html += '<h5>Instant Book Impact:</h5>';
                    html += '<p>' + data.claude_analysis.cancellation.instant_book_feedback + '</p>';
                }
                
                if (data.claude_analysis.cancellation.recommendations && data.claude_analysis.cancellation.recommendations.length > 0) {
                    html += '<div class="claude-suggestions">';
                    html += '<h5>Recommendations:</h5>';
                    html += '<ul>';
                    data.claude_analysis.cancellation.recommendations.forEach(function(recommendation) {
                        html += '<li>' + recommendation + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                
                html += '</div>';
            }
            
            html += '</div>';
        }
        
        // Add recommendations
        html += '<div class="airbnb-analyzer-recommendations">';
        
        data.recommendations.forEach(function(rec) {
            html += '<div class="recommendation-item ' + rec.status + '">';
            html += '<h4>' + rec.category + ' <span class="score">(' + rec.score + '/' + rec.max_score + ')</span></h4>';
            html += '<p class="message">' + rec.message + '</p>';
            
            if (rec.recommendations && rec.recommendations.length > 0) {
                html += '<ul class="tips">';
                rec.recommendations.forEach(function(tip) {
                    html += '<li>' + tip + '</li>';
                });
                html += '</ul>';
            }
            
            html += '</div>';
        });
        
        html += '</div>';
        
        // Add debugging section if enabled
        if (data._debug) {
            html += '<div class="airbnb-analyzer-debug">';
            html += '<h3>Debugging Information</h3>';
            
            html += '<div class="debug-toggle">';
            html += '<button class="toggle-raw-data">Toggle Raw API Data</button>';
            html += '<button class="toggle-extracted-data">Toggle Extracted Data</button>';
            html += '</div>';
            
            html += '<div class="debug-section raw-data" style="display:none;">';
            html += '<h4>Raw API Data</h4>';
            html += '<pre>' + JSON.stringify(data._debug.raw_data, null, 2) + '</pre>';
            html += '</div>';
            
            html += '<div class="debug-section extracted-data" style="display:none;">';
            html += '<h4>Extracted Data</h4>';
            html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            html += '</div>';
            
            html += '</div>';
        }
        
        // Display results
        $('.airbnb-analyzer-content').html(html).show();
        
        // Add event listeners for debug toggles
        if (data._debug) {
            $('.toggle-raw-data').on('click', function() {
                $('.debug-section.raw-data').toggle();
            });
            
            $('.toggle-extracted-data').on('click', function() {
                $('.debug-section.extracted-data').toggle();
            });
        }
    }
    
    /**
     * Get score CSS class
     */
    function getScoreClass(score) {
        if (score >= 80) return 'high';
        if (score >= 60) return 'medium';
        return 'low';
    }
});

// Expert Analysis functionality for results page
jQuery(document).ready(function($) {
    // Handle expert analysis button click
    $('#expert-analysis-btn').on('click', function() {
        requestExpertAnalysis();
    });
    
    // Handle retry button click
    $('#expert-analysis-retry').on('click', function() {
        requestExpertAnalysis();
    });
    
    function requestExpertAnalysis() {
        // Get snapshot ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        const snapshotId = urlParams.get('id');
        
        if (!snapshotId) {
            showError('Unable to identify the analysis. Please refresh the page and try again.');
            return;
        }
        
        // Prevent double-clicking by disabling the button immediately
        $('#expert-analysis-btn').prop('disabled', true);
        $('#expert-analysis-retry').prop('disabled', true);
        
        // Show loading state
        $('#expert-analysis-btn').hide();
        $('#expert-analysis-error').hide();
        $('#expert-analysis-results').hide();
        $('#expert-analysis-processing').hide();
        $('#expert-analysis-loading').show();
        
        // Make AJAX request
        $.ajax({
            url: airbnb_analyzer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'expert_analysis_airbnb',
                nonce: airbnb_analyzer_ajax.nonce,
                snapshot_id: snapshotId
            },
            timeout: 120000, // 2 minutes timeout for AI processing
            success: function(response) {
                $('#expert-analysis-loading').hide();
                
                if (response.success) {
                    if (response.data.status === 'processing') {
                        displayProcessingStatus(response.data);
                    } else {
                        displayExpertAnalysis(response.data);
                    }
                } else {
                    showError(response.data.message || 'Expert analysis failed. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                $('#expert-analysis-loading').hide();
                
                if (status === 'timeout') {
                    showError('The analysis is taking longer than expected. Please try again in a few moments.');
                } else {
                    showError('Network error occurred. Please check your connection and try again.');
                }
            }
        });
    }
    
    function displayProcessingStatus(data) {
        // Create processing status HTML
        let html = '<div class="expert-analysis-processing-status">';
        html += '<div class="processing-icon">⏳</div>';
        html += '<h3>Expert Analysis In Progress</h3>';
        html += '<p class="processing-message">' + data.message + '</p>';
        
        if (data.batch_id) {
            html += '<div class="processing-details">';
            html += '<p><strong>Batch ID:</strong> ' + data.batch_id + '</p>';
            
            if (data.batch_status) {
                html += '<p><strong>Status:</strong> ' + data.batch_status.charAt(0).toUpperCase() + data.batch_status.slice(1) + '</p>';
            }
            
            if (data.submitted_at) {
                html += '<p><strong>Submitted:</strong> ' + formatTimestamp(data.submitted_at) + '</p>';
            }
            
            if (data.time_remaining) {
                const minutes = Math.ceil(data.time_remaining / 60);
                html += '<p><strong>Please wait:</strong> ' + minutes + ' more minute(s) before requesting again</p>';
            }
            
            html += '</div>';
        }
        
        html += '<div class="processing-actions">';
        html += '<button type="button" id="check-analysis-status" class="button">Check Status</button>';
        html += '<button type="button" id="refresh-page" class="button">Refresh Page</button>';
        html += '</div>';
        
        html += '<div class="processing-info">';
        html += '<p><strong>What happens next:</strong></p>';
        html += '<ul>';
        html += '<li>Your expert analysis is being processed by Claude AI</li>';
        html += '<li>This can take up to 24 hours but often completes much sooner</li>';
        html += '<li>You will receive an email notification when it\'s ready</li>';
        html += '<li>You can safely close this page - the analysis will continue</li>';
        html += '</ul>';
        html += '</div>';
        
        html += '</div>';
        
        // Display the processing status
        $('#expert-analysis-processing').html(html).show();
        
        // Add event listeners for the buttons
        $('#check-analysis-status').on('click', function() {
            checkAnalysisStatus(data.batch_id);
        });
        
        $('#refresh-page').on('click', function() {
            window.location.reload();
        });
        
        // Don't re-enable the original button - force user to check status or refresh
        // This prevents accidental duplicate requests
        
        // Scroll to processing status
        $('html, body').animate({
            scrollTop: $('#expert-analysis-processing').offset().top - 20
        }, 500);
    }
    
    function checkAnalysisStatus(batchId) {
        $('#check-analysis-status').prop('disabled', true).text('Checking...');
        
        $.ajax({
            url: airbnb_analyzer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'check_expert_analysis_status',
                nonce: airbnb_analyzer_ajax.nonce,
                batch_id: batchId
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.status === 'completed') {
                        // Analysis is complete, refresh to show results
                        window.location.reload();
                    } else {
                        // Still processing, update the status
                        updateProcessingStatus(response.data);
                    }
                } else {
                    $('#check-analysis-status').prop('disabled', false).text('Check Status');
                    alert('Unable to check status: ' + (response.data.message || 'Unknown error'));
                }
            },
            error: function() {
                $('#check-analysis-status').prop('disabled', false).text('Check Status');
                alert('Network error occurred while checking status.');
            }
        });
    }
    
    function updateProcessingStatus(data) {
        $('#check-analysis-status').prop('disabled', false).text('Check Status');
        
        if (data.batch_status) {
            $('.processing-details p:contains("Status:")').html('<strong>Status:</strong> ' + data.batch_status.charAt(0).toUpperCase() + data.batch_status.slice(1));
        }
        
        if (data.message) {
            $('.processing-message').text(data.message);
        }
        
        // Show updated timestamp
        const now = new Date();
        $('.processing-details').append('<p><strong>Last checked:</strong> ' + now.toLocaleString() + '</p>');
    }
    
    function displayExpertAnalysis(data) {
        const analysis = data.analysis;
        const isCached = data.cached;
        
        // Format the content for better display
        let formattedContent = analysis.content;
        
        // Convert markdown-style headers to HTML
        formattedContent = formattedContent.replace(/^### (.*$)/gm, '<h3>$1</h3>');
        formattedContent = formattedContent.replace(/^## (.*$)/gm, '<h2>$1</h2>');
        formattedContent = formattedContent.replace(/^# (.*$)/gm, '<h1>$1</h1>');
        
        // Convert **bold** to <strong>
        formattedContent = formattedContent.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        
        // Convert bullet points
        formattedContent = formattedContent.replace(/^- (.*$)/gm, '<li>$1</li>');
        formattedContent = formattedContent.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
        
        // Convert line breaks to paragraphs
        formattedContent = formattedContent.replace(/\n\n/g, '</p><p>');
        formattedContent = '<p>' + formattedContent + '</p>';
        
        // Clean up empty paragraphs
        formattedContent = formattedContent.replace(/<p><\/p>/g, '');
        formattedContent = formattedContent.replace(/<p>\s*<h/g, '<h');
        formattedContent = formattedContent.replace(/<\/h([1-6])>\s*<\/p>/g, '</h$1>');
        formattedContent = formattedContent.replace(/<p>\s*<ul>/g, '<ul>');
        formattedContent = formattedContent.replace(/<\/ul>\s*<\/p>/g, '</ul>');
        
        // Display the results
        $('#expert-analysis-content').html(formattedContent);
        $('#expert-analysis-timestamp').text(formatTimestamp(analysis.generated_at));
        $('#expert-analysis-model').text(analysis.model_used || 'Claude AI');
        
        if (isCached) {
            $('#expert-analysis-badge').html('📋 Cached Result');
            $('#expert-analysis-cached-indicator').html('<strong>Source:</strong> Cached (previously generated)');
        } else {
            $('#expert-analysis-badge').html('✨ Freshly Generated');
            $('#expert-analysis-cached-indicator').html('<strong>Source:</strong> Just generated');
        }
        
        $('#expert-analysis-results').show();
        
        // Scroll to results
        $('html, body').animate({
            scrollTop: $('#expert-analysis-results').offset().top - 20
        }, 500);
    }
    
    function showError(message) {
        $('#expert-analysis-error-message').text(message);
        $('#expert-analysis-error').show();
        $('#expert-analysis-btn').show().prop('disabled', false);
        $('#expert-analysis-retry').prop('disabled', false);
        
        // Hide processing status if shown
        $('#expert-analysis-processing').hide();
        
        // Scroll to error message
        $('html, body').animate({
            scrollTop: $('#expert-analysis-error').offset().top - 20
        }, 500);
    }
    
    function formatTimestamp(timestamp) {
        if (!timestamp) return 'Unknown';
        
        const date = new Date(timestamp);
        return date.toLocaleString();
    }
    
    // Check if we're on the results page and if expert analysis data exists
    if (window.location.pathname.includes('results.php') || 
        window.location.search.includes('airbnb-analysis-results')) {
        
        // Auto-scroll to expert analysis if there's a hash
        if (window.location.hash === '#expert-analysis') {
            setTimeout(function() {
                $('html, body').animate({
                    scrollTop: $('.expert-analysis-section').offset().top - 20
                }, 500);
            }, 500);
        }
    }
}); 