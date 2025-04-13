<?php
/**
 * Airbnb Listing Analyzer Core Functions
 */

// Include this file in the main plugin file
// require_once plugin_dir_path(__FILE__) . 'includes/analyzer.php';

function analyze_airbnb_listing($url) {
    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return array(
            'status' => 'error',
            'message' => 'Invalid URL format'
        );
    }
    
    // Check if it's an Airbnb URL
    if (strpos($url, 'airbnb.com/rooms/') === false && strpos($url, 'airbnb.com/h/') === false) {
        return array(
            'status' => 'error',
            'message' => 'URL does not appear to be an Airbnb listing'
        );
    }
    
    // Fetch the listing page
    $html = fetch_airbnb_page($url);
    
    if (!$html) {
        return array(
            'status' => 'error',
            'message' => 'Failed to fetch the Airbnb listing'
        );
    }
    
    // Parse the HTML to extract listing data
    $listing_data = parse_airbnb_listing($html);
    
    // Analyze the listing data
    $analysis = generate_analysis($listing_data);
    
    return array(
        'status' => 'success',
        'message' => 'Analysis completed',
        'data' => $analysis
    );
}

function fetch_airbnb_page($url) {
    $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $html = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('cURL Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($status_code != 200) {
        error_log('HTTP Error: ' . $status_code);
        return false;
    }
    
    return $html;
}

function parse_airbnb_listing($html) {
    // Initialize data structure
    $data = array(
        'title' => '',
        'description' => '',
        'photos' => array(),
        'reviews' => array(
            'count' => 0,
            'average' => 0
        ),
        'amenities' => array(),
        'host' => array(
            'name' => '',
            'photo' => '',
            'superhost' => false
        ),
        'price' => '',
        'location' => '',
        'property_type' => '',
        'bedrooms' => 0,
        'bathrooms' => 0,
        'max_guests' => 0
    );
    
    // Extract data using regex patterns
    // Note: This is a simplified approach. For production, consider using a proper HTML parser
    
    // Extract title
    preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $title_matches);
    if (!empty($title_matches[1])) {
        $data['title'] = strip_tags($title_matches[1]);
    }
    
    // Extract description
    preg_match('/<div[^>]*id="[^"]*description[^"]*"[^>]*>(.*?)<\/div>/s', $html, $desc_matches);
    if (!empty($desc_matches[1])) {
        $data['description'] = strip_tags($desc_matches[1]);
    }
    
    // Extract review count
    preg_match('/(\d+)\s+reviews?/i', $html, $review_count_matches);
    if (!empty($review_count_matches[1])) {
        $data['reviews']['count'] = intval($review_count_matches[1]);
    }
    
    // Extract review average
    preg_match('/(\d+\.\d+)\s+out of 5 stars/i', $html, $review_avg_matches);
    if (!empty($review_avg_matches[1])) {
        $data['reviews']['average'] = floatval($review_avg_matches[1]);
    }
    
    // Count photos (simplified)
    preg_match_all('/<img[^>]*src="([^"]*)/i', $html, $img_matches);
    if (!empty($img_matches[1])) {
        // Filter to only include listing photos (simplified approach)
        $photo_count = 0;
        foreach ($img_matches[1] as $img) {
            if (strpos($img, 'pictures') !== false) {
                $photo_count++;
            }
        }
        $data['photos']['count'] = $photo_count;
    }
    
    // Extract amenities
    preg_match_all('/<div[^>]*amenity[^>]*>(.*?)<\/div>/s', $html, $amenity_matches);
    if (!empty($amenity_matches[1])) {
        foreach ($amenity_matches[1] as $amenity) {
            $data['amenities'][] = strip_tags($amenity);
        }
    }
    
    return $data;
}

function generate_analysis($listing_data) {
    $analysis = array();
    
    // Title analysis
    $title_length = strlen($listing_data['title']);
    $analysis['title'] = array(
        'length' => $title_length,
        'recommendation' => get_title_recommendation($title_length)
    );
    
    // Description analysis
    $desc_length = strlen($listing_data['description']);
    $analysis['description'] = array(
        'length' => $desc_length,
        'recommendation' => get_description_recommendation($desc_length)
    );
    
    // Photos analysis
    $photo_count = isset($listing_data['photos']['count']) ? $listing_data['photos']['count'] : 0;
    $analysis['photos'] = array(
        'count' => $photo_count,
        'recommendation' => get_photos_recommendation($photo_count)
    );
    
    // Reviews analysis
    $review_count = $listing_data['reviews']['count'];
    $review_avg = $listing_data['reviews']['average'];
    $analysis['reviews'] = array(
        'count' => $review_count,
        'average' => $review_avg,
        'recommendation' => get_reviews_recommendation($review_count, $review_avg)
    );
    
    // Completeness analysis
    $missing_fields = array();
    $total_fields = 10; // Total number of important fields
    $filled_fields = 0;
    
    if (empty($listing_data['title'])) $missing_fields[] = 'Title';
    else $filled_fields++;
    
    if (empty($listing_data['description'])) $missing_fields[] = 'Description';
    else $filled_fields++;
    
    if ($photo_count < 1) $missing_fields[] = 'Photos';
    else $filled_fields++;
    
    if (empty($listing_data['amenities'])) $missing_fields[] = 'Amenities';
    else $filled_fields++;
    
    if (empty($listing_data['host']['name'])) $missing_fields[] = 'Host Information';
    else $filled_fields++;
    
    if (empty($listing_data['price'])) $missing_fields[] = 'Price';
    else $filled_fields++;
    
    if (empty($listing_data['location'])) $missing_fields[] = 'Location';
    else $filled_fields++;
    
    if (empty($listing_data['property_type'])) $missing_fields[] = 'Property Type';
    else $filled_fields++;
    
    if ($listing_data['bedrooms'] == 0) $missing_fields[] = 'Bedroom Information';
    else $filled_fields++;
    
    if ($listing_data['bathrooms'] == 0) $missing_fields[] = 'Bathroom Information';
    else $filled_fields++;
    
    $completeness_percentage = ($filled_fields / $total_fields) * 100;
    
    $analysis['completeness'] = array(
        'percentage' => round($completeness_percentage),
        'missing_fields' => $missing_fields,
        'recommendation' => get_completeness_recommendation($completeness_percentage, $missing_fields)
    );
    
    return $analysis;
}

function get_title_recommendation($length) {
    if ($length < 15) {
        return 'Your title is too short. Airbnb recommends titles between 35-65 characters. Add more descriptive words about your unique features.';
    } elseif ($length < 35) {
        return 'Your title could be more descriptive. Aim for 35-65 characters to highlight your property\'s best features.';
    } elseif ($length <= 65) {
        return 'Great job! Your title length is optimal for Airbnb listings.';
    } else {
        return 'Your title is a bit long. Consider shortening it to 65 characters or less for better readability.';
    }
}

function get_description_recommendation($length) {
    if ($length < 100) {
        return 'Your description is too brief. Add more details about your space, amenities, and the guest experience.';
    } elseif ($length < 300) {
        return 'Your description could be more detailed. Aim for at least 300 characters to fully describe your property.';
    } elseif ($length <= 1000) {
        return 'Great job! Your description length is good. Make sure it includes details about the space, neighborhood, and special features.';
    } else {
        return 'Your description is comprehensive, which is good. Consider organizing it into clear sections for better readability.';
    }
}

function get_photos_recommendation($count) {
    if ($count < 5) {
        return 'You need more photos. Airbnb recommends at least 10-15 high-quality photos showing different aspects of your property.';
    } elseif ($count < 10) {
        return 'Add more photos to showcase your property. Aim for at least 10-15 high-quality images of different rooms and features.';
    } elseif ($count <= 20) {
        return 'Good job with your photos! Make sure they\'re high quality and show all aspects of your property.';
    } else {
        return 'Excellent number of photos! Make sure they\'re high quality and well-organized to showcase your property\'s best features.';
    }
}

function get_reviews_recommendation($count, $average) {
    if ($count < 3) {
        return 'Your listing has few reviews. Consider asking friends or family to book and leave honest reviews to build credibility.';
    } elseif ($count < 10) {
        return 'You\'re building a review base. Encourage more guests to leave reviews by providing exceptional experiences.';
    } else {
        if ($average < 4.0) {
            return 'Your average rating could be improved. Address common concerns in reviews and make necessary improvements.';
        } elseif ($average < 4.5) {
            return 'Your rating is good, but there\'s room for improvement. Look for patterns in feedback to enhance the guest experience.';
        } else {
            return 'Excellent rating! Keep up the good work and maintain your high standards.';
        }
    }
}

function get_completeness_recommendation($percentage, $missing_fields) {
    if ($percentage < 70) {
        return 'Your listing is missing several important details. Complete the following fields to improve visibility: ' . implode(', ', $missing_fields);
    } elseif ($percentage < 90) {
        return 'Your listing is mostly complete, but adding the missing information will improve your chances of booking: ' . implode(', ', $missing_fields);
    } else {
        if (empty($missing_fields)) {
            return 'Excellent! Your listing is 100% complete.';
        } else {
            return 'Your listing is nearly complete. Consider adding: ' . implode(', ', $missing_fields);
        }
    }
}

/**
 * Analyze AirBnB listing
 * 
 * @param array $listing_data The listing data
 * @return array Analysis results
 */
function airbnb_analyzer_analyze_listing($listing_data) {
    $analysis = array(
        'score' => 0,
        'max_score' => 100,
        'summary' => '',
        'first_photo' => !empty($listing_data['photos'][0]) ? $listing_data['photos'][0] : '',
        'recommendations' => array(),
        'listing_data' => $listing_data,
    );
    
    // Initialize score
    $score = 0;
    
    // Analyze title
    $title_analysis = airbnb_analyzer_check_title($listing_data['title']);
    $analysis['recommendations'][] = $title_analysis;
    $score += $title_analysis['score'];
    
    // Analyze description
    $description_analysis = airbnb_analyzer_check_description($listing_data['description']);
    $analysis['recommendations'][] = $description_analysis;
    $score += $description_analysis['score'];
    
    // Analyze photos
    $photos_analysis = airbnb_analyzer_check_photos($listing_data['photos']);
    $analysis['recommendations'][] = $photos_analysis;
    $score += $photos_analysis['score'];
    
    // Analyze amenities
    $amenities_analysis = airbnb_analyzer_check_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $amenities_analysis;
    $score += $amenities_analysis['score'];
    
    // Calculate final score
    $analysis['score'] = min(100, $score);
    
    // Generate summary
    if ($analysis['score'] >= 90) {
        $analysis['summary'] = 'Excellent! Your listing is very well optimized.';
    } elseif ($analysis['score'] >= 70) {
        $analysis['summary'] = 'Good job! Your listing is well optimized but has room for improvement.';
    } elseif ($analysis['score'] >= 50) {
        $analysis['summary'] = 'Your listing needs some work to reach its full potential.';
    } else {
        $analysis['summary'] = 'Your listing needs significant improvements to attract more guests.';
    }
    
    return $analysis;
}

/**
 * Check listing title
 * 
 * @param string $title The listing title
 * @return array Analysis results
 */
function airbnb_analyzer_check_title($title) {
    $result = array(
        'category' => 'Title',
        'score' => 0,
        'max_score' => 20,
        'status' => 'warning',
        'message' => '',
        'recommendations' => array(),
    );
    
    // Check title length
    $title_length = strlen($title);
    
    if ($title_length < 15) {
        $result['message'] = 'Your title is too short.';
        $result['score'] = 5;
        $result['recommendations'][] = 'Aim for a title between 40-60 characters for optimal visibility.';
    } elseif ($title_length < 30) {
        $result['message'] = 'Your title could be more descriptive.';
        $result['score'] = 10;
        $result['recommendations'][] = 'Add more details to your title to reach 40-60 characters.';
    } elseif ($title_length <= 60) {
        $result['message'] = 'Great title length!';
        $result['score'] = 20;
        $result['status'] = 'success';
    } else {
        $result['message'] = 'Your title is a bit long.';
        $result['score'] = 15;
        $result['recommendations'][] = 'Consider shortening your title to 60 characters or less.';
    }
    
    // Check for keywords
    $keywords = array('cozy', 'luxury', 'modern', 'spacious', 'charming', 'private', 'central', 'stunning');
    $found_keywords = 0;
    
    foreach ($keywords as $keyword) {
        if (stripos($title, $keyword) !== false) {
            $found_keywords++;
        }
    }
    
    if ($found_keywords == 0 && $result['score'] < 20) {
        $result['recommendations'][] = 'Include attractive keywords like "cozy", "luxury", "modern", "spacious", etc.';
    }
    
    return $result;
}

/**
 * Check listing description
 * 
 * @param string $description The listing description
 * @return array Analysis results
 */
function airbnb_analyzer_check_description($description) {
    $result = array(
        'category' => 'Description',
        'score' => 0,
        'max_score' => 30,
        'status' => 'warning',
        'message' => '',
        'recommendations' => array(),
    );
    
    // Check description length
    $description_length = strlen($description);
    
    if ($description_length < 100) {
        $result['message'] = 'Your description is too short.';
        $result['score'] = 5;
        $result['recommendations'][] = 'Write a detailed description of at least 300 characters.';
    } elseif ($description_length < 300) {
        $result['message'] = 'Your description could be more detailed.';
        $result['score'] = 15;
        $result['recommendations'][] = 'Add more details to your description to reach at least 500 characters.';
    } elseif ($description_length < 1000) {
        $result['message'] = 'Good description length!';
        $result['score'] = 25;
        $result['status'] = 'success';
    } else {
        $result['message'] = 'Excellent detailed description!';
        $result['score'] = 30;
        $result['status'] = 'success';
    }
    
    // Check for formatting
    if (strpos($description, "\n") === false && $description_length > 300) {
        $result['recommendations'][] = 'Add paragraph breaks to make your description more readable.';
        if ($result['score'] > 5) {
            $result['score'] -= 5;
        }
    }
    
    return $result;
}

/**
 * Check listing photos
 * 
 * @param array $photos The listing photos
 * @return array Analysis results
 */
function airbnb_analyzer_check_photos($photos) {
    $result = array(
        'category' => 'Photos',
        'score' => 0,
        'max_score' => 30,
        'status' => 'warning',
        'message' => '',
        'recommendations' => array(),
    );
    
    // Check number of photos
    $photo_count = count($photos);
    
    if ($photo_count == 0) {
        $result['message'] = 'No photos found!';
        $result['score'] = 0;
        $result['status'] = 'error';
        $result['recommendations'][] = 'Add at least 5 high-quality photos of your property.';
    } elseif ($photo_count < 5) {
        $result['message'] = 'You need more photos.';
        $result['score'] = 10;
        $result['recommendations'][] = 'Add at least 5 more photos to showcase your property.';
    } elseif ($photo_count < 10) {
        $result['message'] = 'Good number of photos.';
        $result['score'] = 20;
        $result['status'] = 'success';
        $result['recommendations'][] = 'Consider adding a few more photos to showcase all aspects of your property.';
    } else {
        $result['message'] = 'Excellent number of photos!';
        $result['score'] = 30;
        $result['status'] = 'success';
    }
    
    return $result;
}

/**
 * Check listing amenities
 * 
 * @param array $amenities The listing amenities
 * @return array Analysis results
 */
function airbnb_analyzer_check_amenities($amenities) {
    $result = array(
        'category' => 'Amenities',
        'score' => 0,
        'max_score' => 20,
        'status' => 'warning',
        'message' => '',
        'recommendations' => array(),
    );
    
    // Check number of amenities
    $amenity_count = count($amenities);
    
    if ($amenity_count == 0) {
        $result['message'] = 'No amenities found!';
        $result['score'] = 0;
        $result['status'] = 'error';
        $result['recommendations'][] = 'Add all available amenities to your listing.';
    } elseif ($amenity_count < 5) {
        $result['message'] = 'You need to add more amenities.';
        $result['score'] = 5;
        $result['recommendations'][] = 'Add at least 10 amenities to make your listing more attractive.';
    } elseif ($amenity_count < 10) {
        $result['message'] = 'You have a decent number of amenities.';
        $result['score'] = 10;
        $result['recommendations'][] = 'Add more amenities to stand out from other listings.';
    } elseif ($amenity_count < 15) {
        $result['message'] = 'Good number of amenities!';
        $result['score'] = 15;
        $result['status'] = 'success';
    } else {
        $result['message'] = 'Excellent number of amenities!';
        $result['score'] = 20;
        $result['status'] = 'success';
    }
    
    // Check for essential amenities
    $essential_amenities = array('wifi', 'tv', 'kitchen', 'washer', 'dryer', 'air conditioning', 'heating');
    $missing_essentials = array();
    
    foreach ($essential_amenities as $essential) {
        $found = false;
        foreach ($amenities as $amenity) {
            if (stripos($amenity, $essential) !== false) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $missing_essentials[] = $essential;
        }
    }
    
    if (!empty($missing_essentials) && $amenity_count > 0) {
        $result['recommendations'][] = 'Consider adding these essential amenities if available: ' . implode(', ', $missing_essentials);
    }
    
    return $result;
}

/**
 * Analyze AirBnB listing with Claude AI
 * 
 * @param array $listing_data The listing data
 * @return array Analysis results with AI insights
 */
function airbnb_analyzer_analyze_listing_with_claude($listing_data) {
    // Get regular analysis first
    $analysis = airbnb_analyzer_analyze_listing($listing_data);
    
    // Add Claude AI analysis if API key is configured
    if (!empty(get_option('airbnb_analyzer_claude_api_key'))) {
        // Analyze title
        $title_analysis = airbnb_analyzer_claude_analyze_title($listing_data);
        if ($title_analysis['status'] === 'success') {
            $analysis['claude_analysis']['title'] = $title_analysis['data'];
        }
        
        // Analyze description
        $description_analysis = airbnb_analyzer_claude_analyze_description($listing_data);
        if ($description_analysis['status'] === 'success') {
            $analysis['claude_analysis']['description'] = $description_analysis['data'];
        }
        
        // Analyze host profile
        $host_analysis = airbnb_analyzer_claude_analyze_host($listing_data);
        if ($host_analysis['status'] === 'success') {
            $analysis['claude_analysis']['host'] = $host_analysis['data'];
        }
        
        // Add Claude analysis summary
        if (isset($analysis['claude_analysis'])) {
            $analysis['has_claude_analysis'] = true;
        }
    }
    
    return $analysis;
}
?> 