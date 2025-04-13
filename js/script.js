/**
 * AirBnB Listing Analyzer JavaScript
 */

jQuery(document).ready(function($) {
    // Handle form submission
    $('#airbnb-analyzer-form').on('submit', function(e) {
        e.preventDefault();
        
        // Get listing URL
        var listingUrl = $('#airbnb-listing-url').val();
        
        // Validate URL
        if (!listingUrl || !listingUrl.includes('airbnb.com')) {
            alert('Please enter a valid AirBnB listing URL');
            return;
        }
        
        // Show loading indicator
        $('#airbnb-analyzer-results').show();
        $('.airbnb-analyzer-loading').show();
        $('.airbnb-analyzer-content').hide();
        
        // Send AJAX request
        $.ajax({
            url: airbnb_analyzer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'analyze_airbnb_listing',
                nonce: airbnb_analyzer_ajax.nonce,
                listing_url: listingUrl
            },
            success: function(response) {
                // Hide loading indicator
                $('.airbnb-analyzer-loading').hide();
                
                if (response.success) {
                    // Display results
                    displayResults(response.data);
                } else {
                    // Display error
                    displayError(response.data.message);
                }
            },
            error: function() {
                // Hide loading indicator
                $('.airbnb-analyzer-loading').hide();
                
                // Display error
                displayError('An error occurred while analyzing the listing. Please try again.');
            }
        });
    });
    
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
        
        // Display results
        $('.airbnb-analyzer-content').html(html).show();
    }
    
    /**
     * Display error message
     */
    function displayError(message) {
        var html = '<div class="airbnb-analyzer-error">';
        html += '<p>' + message + '</p>';
        html += '</div>';
        
        $('.airbnb-analyzer-content').html(html).show();
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