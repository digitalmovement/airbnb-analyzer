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
                
                // Debug logging
                console.log('AJAX Response:', response);
                
                if (response.success) {
                    console.log('Response status:', response.data.status);
                    
                    if (response.data.status === 'pending') {
                        // Show pending status message
                        console.log('Showing pending message');
                        displayPendingMessage(response.data);
                    } else {
                        // Display results (for backward compatibility)
                        console.log('Showing results');
                        displayResults(response.data);
                    }
                } else {
                    // Show error message
                    console.log('Error response:', response.data);
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
        console.log('displayPendingMessage called with data:', data);
        
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
        
        console.log('Generated HTML:', html);
        
        // Check if results container exists
        var resultsContainer = $('#airbnb-analyzer-results');
        console.log('Results container found:', resultsContainer.length > 0);
        console.log('Results container element:', resultsContainer);
        
        // Set the HTML
        resultsContainer.html(html);
        console.log('HTML set to results container');
        
        // Show results container
        resultsContainer.show();
        console.log('Results container shown');
        console.log('Container is visible:', resultsContainer.is(':visible'));
        
        // Handle "Analyze Another" button
        $('#analyze-another').on('click', function() {
            console.log('Analyze another button clicked');
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
        if (score >= 80) {
            return 'excellent';
        } else if (score >= 60) {
            return 'good';
        } else if (score >= 40) {
            return 'average';
        } else {
            return 'poor';
        }
    }
}); 