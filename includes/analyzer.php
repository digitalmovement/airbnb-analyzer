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
    
    // 1. Analyze title (using listing_title field)
    $title = isset($listing_data['listing_title']) ? $listing_data['listing_title'] : $listing_data['title'];
    $title_analysis = airbnb_analyzer_check_title($title);
    $analysis['recommendations'][] = $title_analysis;
    $score += $title_analysis['score'];
    
    // 2. Analyze photos (stricter 4+ images requirement)
    $photos_analysis = airbnb_analyzer_check_photos_enhanced($listing_data['photos']);
    $analysis['recommendations'][] = $photos_analysis;
    $score += $photos_analysis['score'];
    
    // 3. Analyze reviews and ratings
    $review_count = isset($listing_data['property_number_of_reviews']) ? $listing_data['property_number_of_reviews'] : $listing_data['review_count'];
    $reviews_analysis = analyze_reviews_enhanced($listing_data['rating'], $review_count, $listing_data);
    $analysis['recommendations'][] = $reviews_analysis;
    $score += $reviews_analysis['score'];
    
    // 4. Check host status (superhost, guest favorite)
    $host_analysis = analyze_host_status($listing_data);
    $analysis['recommendations'][] = $host_analysis;
    $score += $host_analysis['score'];
    
    // 5. Analyze amenities by groups
    $bathroom_analysis = analyze_bathroom_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $bathroom_analysis;
    $score += $bathroom_analysis['score'];
    
    $bedroom_analysis = analyze_bedroom_laundry_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $bedroom_analysis;
    $score += $bedroom_analysis['score'];
    
    $entertainment_analysis = analyze_entertainment_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $entertainment_analysis;
    $score += $entertainment_analysis['score'];
    
    $family_analysis = analyze_family_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $family_analysis;
    $score += $family_analysis['score'];
    
    $climate_analysis = analyze_climate_control($listing_data['amenities']);
    $analysis['recommendations'][] = $climate_analysis;
    $score += $climate_analysis['score'];
    
    $safety_analysis = analyze_safety_features($listing_data['amenities']);
    $analysis['recommendations'][] = $safety_analysis;
    $score += $safety_analysis['score'];
    
    $internet_analysis = analyze_internet_office_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $internet_analysis;
    $score += $internet_analysis['score'];
    
    $kitchen_analysis = analyze_kitchen_dining_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $kitchen_analysis;
    $score += $kitchen_analysis['score'];
    
    $parking_analysis = analyze_parking_facilities_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $parking_analysis;
    $score += $parking_analysis['score'];
    
    $services_analysis = analyze_services_amenities($listing_data['amenities']);
    $analysis['recommendations'][] = $services_analysis;
    $score += $services_analysis['score'];
    
    // 8. Analyze description completeness
    $description_analysis = airbnb_analyzer_check_description_enhanced($listing_data);
    $analysis['recommendations'][] = $description_analysis;
    $score += $description_analysis['score'];
    
    // Calculate final score (Total possible: ~200 points, capped at 100)
    $analysis['score'] = min(100, round($score * 0.6));
    
    // Generate summary based on score
    if ($analysis['score'] >= 85) {
        $analysis['summary'] = 'Outstanding listing! Your property meets all key optimization criteria.';
    } elseif ($analysis['score'] >= 70) {
        $analysis['summary'] = 'Great listing with minor areas for improvement.';
    } elseif ($analysis['score'] >= 55) {
        $analysis['summary'] = 'Good foundation but several opportunities to boost your booking potential.';
    } elseif ($analysis['score'] >= 40) {
        $analysis['summary'] = 'Your listing needs attention in multiple areas to compete effectively.';
    } else {
        $analysis['summary'] = 'Significant improvements needed to attract guests and increase bookings.';
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
        
        // Analyze amenities
        $amenities_analysis = airbnb_analyzer_claude_analyze_amenities($listing_data);
        if ($amenities_analysis['status'] === 'success') {
            $analysis['claude_analysis']['amenities'] = $amenities_analysis['data'];
        }
        
        // Analyze property reviews
        $reviews_analysis = airbnb_analyzer_claude_analyze_reviews($listing_data);
        if ($reviews_analysis['status'] === 'success') {
            $analysis['claude_analysis']['reviews'] = $reviews_analysis['data'];
        }
        
        // Analyze cancellation policy
        $cancellation_analysis = airbnb_analyzer_claude_analyze_cancellation($listing_data);
        if ($cancellation_analysis['status'] === 'success') {
            $analysis['claude_analysis']['cancellation'] = $cancellation_analysis['data'];
        }
        
        // Add Claude analysis summary
        if (isset($analysis['claude_analysis'])) {
            $analysis['has_claude_analysis'] = true;
        }
    }
    
    return $analysis;
}

/**
 * Analyze amenities
 * 
 * @param array $amenities The listing amenities
 * @return array Analysis results
 */
function analyze_amenities($amenities) {
    $result = array(
        'category' => 'Amenities',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array()
    );
    
    // Count amenities
    $amenity_count = count($amenities);
    
    // Define essential amenities by category
    $essential_amenities = array(
        'Bathroom' => array(
            'Hair dryer',
            'Shampoo',
            'Hot water',
            'Shower gel',
            'Toilet paper'
        ),
        'Bedroom and Laundry' => array(
            'Washing machine',
            'Dryer',
            'Bed linens',
            'Extra pillows and blankets',
            'Hangers',
            'Iron'
        ),
        'Essentials' => array(
            'Towels',
            'Bed sheets',
            'Soap',
            'Toilet paper',
            'Hangers'
        ),
        'Entertainment' => array(
            'TV',
            'Books',
            'Board games'
        ),
        'Heating and Cooling' => array(
            'Heating',
            'Air conditioning',
            'Fans'
        ),
        'Home Safety' => array(
            'Smoke alarm',
            'Carbon monoxide alarm',
            'Fire extinguisher',
            'First aid kit'
        ),
        'Internet and Office' => array(
            'Wifi',
            'Dedicated workspace',
            'Laptop-friendly workspace'
        ),
        'Kitchen and Dining' => array(
            'Kitchen',
            'Refrigerator',
            'Microwave',
            'Cooking basics',
            'Dishes and silverware',
            'Dishwasher',
            'Coffee maker'
        )
    );
    
    // Check for missing essential amenities by category
    $missing_by_category = array();
    $present_count = 0;
    $total_essentials = 0;
    
    foreach ($essential_amenities as $category => $essentials) {
        $missing = array();
        
        foreach ($essentials as $essential) {
            $total_essentials++;
            $found = false;
            
            foreach ($amenities as $amenity) {
                if (stripos($amenity, $essential) !== false) {
                    $found = true;
                    $present_count++;
                    break;
                }
            }
            
            if (!$found) {
                $missing[] = $essential;
            }
        }
        
        if (!empty($missing)) {
            $missing_by_category[$category] = $missing;
        }
    }
    
    // Calculate score based on essential amenities coverage and total count
    $essentials_score = ($total_essentials > 0) ? ($present_count / $total_essentials) * 7 : 0;
    $count_score = min(3, ($amenity_count / 20) * 3); // Max 3 points for quantity
    
    $result['score'] = round($essentials_score + $count_score);
    
    // Set status based on score
    if ($result['score'] >= 8) {
        $result['status'] = 'excellent';
    } elseif ($result['score'] >= 6) {
        $result['status'] = 'good';
    } elseif ($result['score'] >= 4) {
        $result['status'] = 'average';
    } else {
        $result['status'] = 'poor';
    }
    
    // Set message based on score
    if ($result['score'] >= 8) {
        $result['message'] = 'Excellent amenities! Your listing offers most of the essential amenities guests look for.';
    } elseif ($result['score'] >= 6) {
        $result['message'] = 'Good amenities coverage, but there\'s room for improvement in some categories.';
    } elseif ($result['score'] >= 4) {
        $result['message'] = 'Your listing is missing several important amenities that guests typically expect.';
    } else {
        $result['message'] = 'Your listing lacks many essential amenities. Adding these could significantly improve your bookings.';
    }
    
    // Add recommendations for missing essentials by category
    foreach ($missing_by_category as $category => $missing) {
        if (count($missing) > 0) {
            $result['recommendations'][] = $category . ': Consider adding ' . implode(', ', $missing);
        }
    }
    
    // Add general recommendation for amenity count if needed
    if ($amenity_count < 15) {
        $result['recommendations'][] = 'Try to offer at least 15-20 amenities to make your listing more attractive to potential guests.';
    }
    
    return $result;
}

/**
 * Analyze reviews
 * 
 * @param float $rating The listing rating
 * @param int $review_count The number of reviews
 * @param bool $is_guest_favorite Whether the listing is a guest favorite
 * @return array Analysis results
 */
function analyze_reviews($rating, $review_count, $is_guest_favorite = false) {
    $result = array(
        'category' => 'Reviews',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array()
    );
    
    // Calculate base score based on rating and review count
    $rating_score = min(5, $rating) * 1.2; // Max 6 points for rating
    $review_count_score = min(4, ($review_count / 25) * 4); // Max 4 points for review count
    
    // Add bonus for guest favorite status
    $favorite_bonus = $is_guest_favorite ? 1 : 0; // 1 bonus point for guest favorite
    
    $result['score'] = round($rating_score + $review_count_score + $favorite_bonus);
    
    // Set status based on score
    if ($result['score'] >= 8) {
        $result['status'] = 'excellent';
    } elseif ($result['score'] >= 6) {
        $result['status'] = 'good';
    } elseif ($result['score'] >= 4) {
        $result['status'] = 'average';
    } else {
        $result['status'] = 'poor';
    }
    
    // Set message based on score and guest favorite status
    if ($is_guest_favorite) {
        $result['message'] = 'Congratulations! Your listing is a Guest Favorite, which significantly boosts your visibility and appeal.';
    } else {
        if ($result['score'] >= 8) {
            $result['message'] = 'Excellent reviews! Your listing has a great rating and a solid number of reviews.';
        } elseif ($result['score'] >= 6) {
            $result['message'] = 'Good reviews overall, but there\'s room for improvement to reach "Guest Favorite" status.';
        } elseif ($result['score'] >= 4) {
            $result['message'] = 'Your reviews are average. Focus on improving your rating and getting more reviews.';
        } else {
            $result['message'] = 'Your reviews need significant improvement to attract more guests.';
        }
    }
    
    // Add recommendations
    if ($rating < 4.5) {
        $result['recommendations'][] = 'Work on improving your rating by addressing common concerns in reviews.';
    }
    
    if ($review_count < 10) {
        $result['recommendations'][] = 'Encourage more guests to leave reviews by providing exceptional experiences and sending follow-up messages.';
    }
    
    if (!$is_guest_favorite) {
        $result['recommendations'][] = 'Aim for "Guest Favorite" status by consistently delivering exceptional experiences and maintaining a high rating.';
    }
    
    return $result;
}

/**
 * Analyze cancellation policy
 * 
 * @param array $policy_details The cancellation policy details
 * @param string $property_type The property type
 * @return array Analysis results
 */
function analyze_cancellation_policy($policy_details, $property_type = '') {
    $result = array(
        'category' => 'Cancellation Policy',
        'score' => 0,
        'max_score' => 10,
        'status' => 'average',
        'message' => '',
        'recommendations' => array()
    );
    
    $policy_name = isset($policy_details['name']) ? $policy_details['name'] : '';
    $strictness = isset($policy_details['strictness']) ? $policy_details['strictness'] : 3;
    $can_instant_book = isset($policy_details['can_instant_book']) ? $policy_details['can_instant_book'] : false;
    
    // Calculate base score based on policy strictness and property type
    // For most properties, a moderate policy (3) is optimal
    // For luxury or high-end properties, stricter policies (4-5) may be appropriate
    $is_luxury = false;
    if (!empty($property_type)) {
        $luxury_keywords = array('luxury', 'villa', 'mansion', 'penthouse', 'estate');
        foreach ($luxury_keywords as $keyword) {
            if (stripos($property_type, $keyword) !== false) {
                $is_luxury = true;
                break;
            }
        }
    }
    
    if ($is_luxury) {
        // For luxury properties, stricter policies are more acceptable
        if ($strictness <= 2) {
            $policy_score = 6; // Too lenient for luxury
        } elseif ($strictness == 3) {
            $policy_score = 8; // Good balance
        } elseif ($strictness == 4) {
            $policy_score = 9; // Very good for luxury
        } else {
            $policy_score = 7; // Super strict might be too much
        }
    } else {
        // For standard properties, more flexible policies are better
        if ($strictness <= 2) {
            $policy_score = 9; // Very flexible, attractive to guests
        } elseif ($strictness == 3) {
            $policy_score = 8; // Good balance
        } elseif ($strictness == 4) {
            $policy_score = 6; // Might be too strict
        } else {
            $policy_score = 4; // Super strict, may deter bookings
        }
    }
    
    // Add bonus for instant book
    $instant_book_bonus = $can_instant_book ? 1 : 0;
    
    $result['score'] = min(10, $policy_score + $instant_book_bonus);
    
    // Set status based on score
    if ($result['score'] >= 8) {
        $result['status'] = 'excellent';
    } elseif ($result['score'] >= 6) {
        $result['status'] = 'good';
    } elseif ($result['score'] >= 4) {
        $result['status'] = 'average';
    } else {
        $result['status'] = 'poor';
    }
    
    // Set message based on score and policy
    if (!empty($policy_name)) {
        if ($result['score'] >= 8) {
            $result['message'] = "Your '{$policy_name}' cancellation policy is well-balanced and appropriate for your property type.";
        } elseif ($result['score'] >= 6) {
            $result['message'] = "Your '{$policy_name}' cancellation policy is good, but could be optimized for better booking conversion.";
        } elseif ($result['score'] >= 4) {
            $result['message'] = "Your '{$policy_name}' cancellation policy may be too strict for your property type, potentially deterring bookings.";
        } else {
            $result['message'] = "Your '{$policy_name}' cancellation policy is very strict and likely reducing your booking rate significantly.";
        }
    } else {
        $result['message'] = "No cancellation policy information found. Setting a clear policy is important for guest expectations.";
    }
    
    // Add recommendations
    if ($is_luxury) {
        if ($strictness <= 2) {
            $result['recommendations'][] = "Consider a stricter cancellation policy for your luxury property to protect against last-minute cancellations.";
        } elseif ($strictness >= 5) {
            $result['recommendations'][] = "Even for luxury properties, a 'Super Strict' policy may deter bookings. Consider a standard 'Strict' policy instead.";
        }
    } else {
        if ($strictness >= 4) {
            $result['recommendations'][] = "Your strict cancellation policy may be deterring potential guests. Consider a more moderate policy to increase bookings.";
        }
    }
    
    if (!$can_instant_book) {
        $result['recommendations'][] = "Enabling Instant Book can increase your booking rate by 12-15% according to Airbnb data.";
    }
    
    return $result;
}

/**
 * Enhanced photo analysis - stricter requirements
 */
function airbnb_analyzer_check_photos_enhanced($photos) {
    $result = array(
        'category' => 'Photos',
        'score' => 0,
        'max_score' => 15,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $photo_count = count($photos);
    
    if ($photo_count == 0) {
        $result['message'] = 'No photos found!';
        $result['score'] = 0;
        $result['status'] = 'error';
        $result['recommendations'][] = 'Add at least 8 high-quality photos of your property.';
        $result['recommendations'][] = 'Include photos of every room, outdoor spaces, and neighborhood.';
    } elseif ($photo_count < 4) {
        $result['message'] = 'Critical: Too few photos - severely impacts bookings.';
        $result['score'] = 2;
        $result['status'] = 'error';
        $result['recommendations'][] = 'You need at least 4 photos minimum. Aim for 8-15 photos total.';
        $result['recommendations'][] = 'Photos are crucial - guests want to see what they\'re booking.';
    } elseif ($photo_count < 6) {
        $result['message'] = 'Below average photo count.';
        $result['score'] = 6;
        $result['status'] = 'warning';
        $result['recommendations'][] = 'Add 2-4 more photos to reach the optimal range of 8-15 photos.';
        $result['recommendations'][] = 'Include photos of all bedrooms, bathrooms, and common areas.';
    } elseif ($photo_count < 10) {
        $result['message'] = 'Good number of photos.';
        $result['score'] = 12;
        $result['status'] = 'good';
        $result['recommendations'][] = 'Consider adding 2-3 more photos to showcase all property features.';
    } else {
        $result['message'] = 'Excellent photo coverage!';
        $result['score'] = 15;
        $result['status'] = 'excellent';
    }
    
    return $result;
}

/**
 * Enhanced reviews analysis
 */
function analyze_reviews_enhanced($rating, $review_count, $listing_data) {
    $result = array(
        'category' => 'Reviews & Ratings',
        'score' => 0,
        'max_score' => 25,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    // Analyze review count
    $review_score = 0;
    if ($review_count == 0) {
        $result['message'] = 'No reviews yet - this significantly impacts bookings.';
        $review_score = 0;
        $result['recommendations'][] = 'Focus on getting your first 5 reviews by offering competitive pricing.';
        $result['recommendations'][] = 'Ask guests to leave reviews after positive stays.';
    } elseif ($review_count < 5) {
        $result['message'] = 'Limited reviews - potential guests may hesitate to book.';
        $review_score = 3;
        $result['recommendations'][] = 'Work on getting more reviews to build trust with potential guests.';
    } elseif ($review_count < 15) {
        $result['message'] = 'Building a good review base.';
        $review_score = 6;
        $result['recommendations'][] = 'Continue providing excellent service to accumulate more reviews.';
    } else {
        $result['message'] = 'Strong review count builds guest confidence.';
        $review_score = 8;
    }
    
    // Analyze overall rating
    $rating_score = 0;
    if ($rating >= 4.8) {
        $rating_score = 7;
        $result['status'] = 'excellent';
    } elseif ($rating >= 4.5) {
        $rating_score = 5;
        $result['status'] = 'good';
    } elseif ($rating >= 4.0) {
        $rating_score = 3;
        $result['status'] = 'average';
        $result['recommendations'][] = 'Focus on improving guest experience to boost ratings above 4.5.';
    } elseif ($rating > 0) {
        $rating_score = 1;
        $result['status'] = 'poor';
        $result['recommendations'][] = 'Critical: Low ratings hurt bookings. Address guest concerns immediately.';
    }
    
    // Analyze category ratings (10 points total)
    $category_score = 0;
    $problem_categories = array();
    
    if (isset($listing_data['property_rating_details']) && is_array($listing_data['property_rating_details'])) {
        foreach ($listing_data['property_rating_details'] as $category_rating) {
            if (isset($category_rating['name']) && isset($category_rating['value'])) {
                $category_name = $category_rating['name'];
                $category_value = floatval($category_rating['value']);
                
                // Value category is subjective and can be lower than 5.0
                if (strtolower($category_name) === 'value') {
                    if ($category_value >= 4.5) {
                        $category_score += 1.5;
                    } elseif ($category_value >= 4.0) {
                        $category_score += 1;
                    } else {
                        $category_score += 0.5;
                        $problem_categories[] = $category_name . ' (' . $category_value . ')';
                    }
                } else {
                    // All other categories should be 5.0 or very close
                    if ($category_value >= 4.9) {
                        $category_score += 1.5;
                    } elseif ($category_value >= 4.7) {
                        $category_score += 1;
                        $problem_categories[] = $category_name . ' (' . $category_value . ')';
                    } elseif ($category_value >= 4.5) {
                        $category_score += 0.5;
                        $problem_categories[] = $category_name . ' (' . $category_value . ')';
                    } else {
                        $problem_categories[] = $category_name . ' (' . $category_value . ')';
                    }
                }
            }
        }
    }
    
    // Add category-specific recommendations
    if (!empty($problem_categories)) {
        $result['recommendations'][] = 'IMPROVE CATEGORY RATINGS: Focus on ' . implode(', ', $problem_categories) . ' to reach 4.9+.';
        
        foreach ($listing_data['property_rating_details'] as $category_rating) {
            $category_name = strtolower($category_rating['name']);
            $category_value = floatval($category_rating['value']);
            
            if ($category_value < 4.9 && $category_name !== 'value') {
                switch ($category_name) {
                    case 'cleanliness':
                        if ($category_value < 4.9) {
                            $result['recommendations'][] = 'CLEANLINESS: Deep clean before each guest, provide cleaning supplies, ensure spotless bathrooms and kitchen.';
                        }
                        break;
                    case 'accuracy':
                        if ($category_value < 4.9) {
                            $result['recommendations'][] = 'ACCURACY: Update photos, description, and amenities list to match exactly what guests will find.';
                        }
                        break;
                    case 'check-in':
                        if ($category_value < 4.9) {
                            $result['recommendations'][] = 'CHECK-IN: Provide clear instructions, consider self-check-in, be available for questions.';
                        }
                        break;
                    case 'communication':
                        if ($category_value < 4.9) {
                            $result['recommendations'][] = 'COMMUNICATION: Respond quickly, provide local tips, check in during stay, be helpful and friendly.';
                        }
                        break;
                    case 'location':
                        if ($category_value < 4.9) {
                            $result['recommendations'][] = 'LOCATION: Provide detailed directions, local guides, transport info, and highlight nearby attractions.';
                        }
                        break;
                }
            }
        }
    }
    
    $result['score'] = min(25, $review_score + $rating_score + $category_score);
    
    // Update status based on final score
    if ($result['score'] >= 22) {
        $result['status'] = 'excellent';
        if (empty($problem_categories)) {
            $result['message'] = 'Outstanding ratings across all categories!';
        } else {
            $result['message'] = 'Strong ratings with minor areas for improvement.';
        }
    } elseif ($result['score'] >= 18) {
        $result['status'] = 'good';
        $result['message'] = 'Good ratings with room for improvement.';
    } elseif ($result['score'] >= 12) {
        $result['status'] = 'average';
        $result['message'] = 'Average ratings - focus on guest experience improvements.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'Ratings need significant improvement to attract bookings.';
    }
    
    return $result;
}

/**
 * Analyze host status and performance
 */
function analyze_host_status($listing_data) {
    $result = array(
        'category' => 'Host Performance',
        'score' => 0,
        'max_score' => 15,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $score = 0;
    $status_items = array();
    
    // Check superhost status (5 points)
    $is_superhost = isset($listing_data['is_supperhost']) ? $listing_data['is_supperhost'] : false;
    if ($is_superhost) {
        $score += 5;
        $status_items[] = 'Superhost';
    } else {
        $result['recommendations'][] = 'Work towards Superhost status for increased visibility and trust.';
    }
    
    // Check guest favorite (3 points)
    $is_guest_favorite = isset($listing_data['is_guest_favorite']) ? $listing_data['is_guest_favorite'] : false;
    if ($is_guest_favorite) {
        $score += 3;
        $status_items[] = 'Guest Favorite';
    } else {
        $result['recommendations'][] = 'Aim for Guest Favorite status by maintaining high ratings and reviews.';
    }
    
    // Check host response rate (3 points)
    $response_rate = isset($listing_data['host_response_rate']) ? intval($listing_data['host_response_rate']) : 0;
    if ($response_rate >= 95) {
        $score += 3;
        $status_items[] = $response_rate . '% response rate';
    } elseif ($response_rate >= 85) {
        $score += 2;
        $status_items[] = $response_rate . '% response rate';
        $result['recommendations'][] = 'Improve response rate to 95%+ for better guest confidence.';
    } elseif ($response_rate > 0) {
        $score += 1;
        $result['recommendations'][] = 'Critical: Low response rate (' . $response_rate . '%) hurts bookings significantly.';
    } else {
        $result['recommendations'][] = 'Set up automatic responses to improve response rate.';
    }
    
    // Check host rating (2 points)
    $host_rating = isset($listing_data['host_rating']) ? floatval($listing_data['host_rating']) : 0;
    if ($host_rating >= 4.8) {
        $score += 2;
    } elseif ($host_rating >= 4.5) {
        $score += 1;
    } elseif ($host_rating > 0) {
        $result['recommendations'][] = 'Focus on improving host rating through better communication and service.';
    }
    
    // Check hosting experience (2 points)
    $hosting_years = isset($listing_data['hosts_year']) ? intval($listing_data['hosts_year']) : 0;
    if ($hosting_years >= 3) {
        $score += 2;
        $status_items[] = $hosting_years . ' years hosting';
    } elseif ($hosting_years >= 1) {
        $score += 1;
        $status_items[] = $hosting_years . ' year' . ($hosting_years > 1 ? 's' : '') . ' hosting';
    } else {
        $result['recommendations'][] = 'New host - focus on building reviews and establishing trust.';
    }
    
    $result['score'] = $score;
    
    // Generate status and message
    if ($score >= 13) {
        $result['status'] = 'excellent';
        $result['message'] = 'Outstanding host profile: ' . implode(', ', $status_items);
    } elseif ($score >= 10) {
        $result['status'] = 'good';
        $result['message'] = 'Strong host profile: ' . implode(', ', $status_items);
    } elseif ($score >= 6) {
        $result['status'] = 'average';
        $result['message'] = 'Developing host profile: ' . implode(', ', $status_items);
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'Host profile needs improvement' . (!empty($status_items) ? ': ' . implode(', ', $status_items) : '.');
        $result['recommendations'][] = 'Focus on quick responses, clear communication, and exceeding guest expectations.';
    }
    
    return $result;
}



/**
 * Analyze climate control
 */
function analyze_climate_control($amenities) {
    $result = array(
        'category' => 'Climate Control',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $has_heating = false;
    $has_cooling = false;
    
    // Look specifically in "Heating and cooling" group
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                $group_name = $amenity_group['group_name'];
                
                // Only check "Heating and cooling" group for climate features
                if (strtolower($group_name) === 'heating and cooling') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['value']) && isset($item['name'])) {
                            $value = $item['value'];
                            $name = strtolower($item['name']);
                            
                            // Check for heating
                            if (stripos($name, 'heating') !== false || 
                                stripos($name, 'radiant heating') !== false ||
                                stripos($name, 'central heating') !== false ||
                                stripos($name, 'fireplace') !== false) {
                                $has_heating = true;
                            }
                            
                            // Check for cooling
                            if (stripos($name, 'air conditioning') !== false || 
                                stripos($name, 'ac') !== false ||
                                stripos($name, 'central air') !== false ||
                                stripos($name, 'fan') !== false ||
                                stripos($name, 'cooling') !== false) {
                                $has_cooling = true;
                            }
                        }
                    }
                }
            }
        }
    }
    
    $score = 0;
    if ($has_heating) $score += 5;
    if ($has_cooling) $score += 5;
    
    $result['score'] = $score;
    
    if ($score >= 10) {
        $result['status'] = 'excellent';
        $result['message'] = 'Complete climate control - heating and cooling available.';
    } elseif ($score >= 5) {
        $result['status'] = 'average';
        if ($has_heating && !$has_cooling) {
            $result['message'] = 'Heating available but no cooling options.';
            $result['recommendations'][] = 'Consider adding air conditioning or fans for guest comfort.';
        } else {
            $result['message'] = 'Cooling available but no heating options.';
            $result['recommendations'][] = 'Consider adding heating for year-round comfort.';
        }
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'No climate control amenities found.';
        $result['recommendations'][] = 'Add heating and cooling options for guest comfort.';
        $result['recommendations'][] = 'Basic fans and portable heaters can improve guest satisfaction.';
    }
    
    return $result;
}

/**
 * Analyze safety features
 */
function analyze_safety_features($amenities) {
    $result = array(
        'category' => 'Safety Features',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $has_smoke_detector = false;
    $has_co_detector = false;
    $has_first_aid = false;
    $has_fire_extinguisher = false;
    
    // Look specifically in "Home safety" group
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                $group_name = $amenity_group['group_name'];
                
                // Only check "Home safety" group for safety features
                if (strtolower($group_name) === 'home safety') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['value']) && isset($item['name'])) {
                            $value = $item['value'];
                            $name = strtolower($item['name']);
                            
                            if ($value === 'SYSTEM_DETECTOR_SMOKE' || stripos($name, 'smoke alarm') !== false) {
                                $has_smoke_detector = true;
                            }
                            if ($value === 'SYSTEM_DETECTOR_CO' || stripos($name, 'carbon monoxide') !== false) {
                                $has_co_detector = true;
                            }
                            if ($value === 'SYSTEM_FIRST_AID_KIT' || stripos($name, 'first aid') !== false) {
                                $has_first_aid = true;
                            }
                            if ($value === 'SYSTEM_FIRE_EXTINGUISHER' || stripos($name, 'fire extinguisher') !== false) {
                                $has_fire_extinguisher = true;
                            }
                        }
                    }
                }
            }
        }
    }
    
    $score = 0;
    if ($has_smoke_detector) $score += 4;
    if ($has_co_detector) $score += 4;
    if ($has_first_aid) $score += 1;
    if ($has_fire_extinguisher) $score += 1;
    
    $result['score'] = $score;
    
    if ($score >= 8) {
        $result['status'] = 'excellent';
        $result['message'] = 'Excellent safety features - guests will feel secure.';
    } elseif ($score >= 6) {
        $result['status'] = 'good';
        $result['message'] = 'Good safety coverage with room for improvement.';
    } elseif ($score >= 4) {
        $result['status'] = 'average';
        $result['message'] = 'Basic safety features present.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'Critical safety features are missing.';
    }
    
    if (!$has_smoke_detector) {
        $result['recommendations'][] = 'CRITICAL: Install smoke detectors - required by most jurisdictions.';
    }
    if (!$has_co_detector) {
        $result['recommendations'][] = 'CRITICAL: Install carbon monoxide detectors for guest safety.';
    }
    if (!$has_first_aid) {
        $result['recommendations'][] = 'Provide a basic first aid kit for emergencies.';
    }
    if (!$has_fire_extinguisher) {
        $result['recommendations'][] = 'Consider adding a fire extinguisher for additional safety.';
    }
    
    return $result;
}

/**
 * Enhanced description analysis
 */
function airbnb_analyzer_check_description_enhanced($listing_data) {
    $result = array(
        'category' => 'Description Completeness',
        'score' => 0,
        'max_score' => 20,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    // Check if we have the sectioned description data
    $description_sections = isset($listing_data['description_by_sections']) ? $listing_data['description_by_sections'] : null;
    $fallback_description = isset($listing_data['description']) ? $listing_data['description'] : '';
    
    if (empty($description_sections) && empty($fallback_description)) {
        $result['message'] = 'No description provided!';
        $result['score'] = 0;
        $result['recommendations'][] = 'Write a detailed description covering the space, guest access, and important notes.';
        return $result;
    }
    
    // If we don't have sectioned data, fall back to basic analysis
    if (empty($description_sections)) {
        return airbnb_analyzer_check_description_basic($fallback_description);
    }
    
    // Analyze each of the 4 standard sections
    $sections_analysis = array(
        'main_description' => array('found' => false, 'quality' => 0, 'length' => 0),
        'the_space' => array('found' => false, 'quality' => 0, 'length' => 0),
        'guest_access' => array('found' => false, 'quality' => 0, 'length' => 0),
        'other_notes' => array('found' => false, 'quality' => 0, 'length' => 0)
    );
    
    foreach ($description_sections as $section) {
        $title = strtolower($section['title'] ?? '');
        $value = trim($section['value'] ?? '');
        $length = strlen($value);
        
        if (empty($value) || $length < 10) continue; // Skip empty or very short sections
        
        if (empty($title) || $title === 'null') {
            // Main description (no title)
            $sections_analysis['main_description']['found'] = true;
            $sections_analysis['main_description']['length'] = $length;
            $sections_analysis['main_description']['quality'] = analyze_section_quality($value, 'main');
        } elseif (strpos($title, 'space') !== false) {
            // The space section
            $sections_analysis['the_space']['found'] = true;
            $sections_analysis['the_space']['length'] = $length;
            $sections_analysis['the_space']['quality'] = analyze_section_quality($value, 'space');
        } elseif (strpos($title, 'access') !== false) {
            // Guest access section
            $sections_analysis['guest_access']['found'] = true;
            $sections_analysis['guest_access']['length'] = $length;
            $sections_analysis['guest_access']['quality'] = analyze_section_quality($value, 'access');
        } elseif (strpos($title, 'note') !== false || strpos($title, 'other') !== false) {
            // Other things to note section
            $sections_analysis['other_notes']['found'] = true;
            $sections_analysis['other_notes']['length'] = $length;
            $sections_analysis['other_notes']['quality'] = analyze_section_quality($value, 'notes');
        }
    }
    
    // Calculate score based on sections (5 points each)
    $score = 0;
    foreach ($sections_analysis as $section_key => $section_data) {
        if ($section_data['found']) {
            $score += min(5, $section_data['quality']);
        }
    }
    
    $result['score'] = $score;
    
    // Generate specific recommendations for missing or weak sections
    if (!$sections_analysis['main_description']['found']) {
        $result['recommendations'][] = 'ADD MAIN DESCRIPTION: Write an engaging overview highlighting your property\'s best features and location benefits.';
    } elseif ($sections_analysis['main_description']['quality'] < 3) {
        $result['recommendations'][] = 'IMPROVE MAIN DESCRIPTION: Make it more compelling with key selling points, location benefits, and target guest appeal.';
    }
    
    if (!$sections_analysis['the_space']['found']) {
        $result['recommendations'][] = 'ADD "THE SPACE" SECTION: Describe room layout, furniture, amenities, and what makes your space special.';
    } elseif ($sections_analysis['the_space']['quality'] < 3) {
        $result['recommendations'][] = 'IMPROVE "THE SPACE" SECTION: Add more detail about rooms, layout, furnishings, and special features.';
    }
    
    if (!$sections_analysis['guest_access']['found']) {
        $result['recommendations'][] = 'ADD "GUEST ACCESS" SECTION: Clarify what spaces guests can use, any shared areas, and access instructions.';
    } elseif ($sections_analysis['guest_access']['quality'] < 3) {
        $result['recommendations'][] = 'IMPROVE "GUEST ACCESS" SECTION: Be more specific about what guests can access and any restrictions.';
    }
    
    if (!$sections_analysis['other_notes']['found']) {
        $result['recommendations'][] = 'ADD "OTHER THINGS TO NOTE" SECTION: Include house rules, important policies, neighborhood info, or special considerations.';
    } elseif ($sections_analysis['other_notes']['quality'] < 3) {
        $result['recommendations'][] = 'IMPROVE "OTHER NOTES" SECTION: Add more helpful information about rules, policies, or local area insights.';
    }
    
    // Set status and message
    $sections_found = count(array_filter($sections_analysis, function($s) { return $s['found']; }));
    
    if ($result['score'] >= 18) {
        $result['status'] = 'excellent';
        $result['message'] = 'Outstanding description with all 4 sections well-developed!';
    } elseif ($result['score'] >= 14) {
        $result['status'] = 'good';
        $result['message'] = "Good description coverage ({$sections_found}/4 sections present).";
    } elseif ($result['score'] >= 8) {
        $result['status'] = 'average';
        $result['message'] = "Basic description but missing key sections ({$sections_found}/4 sections present).";
    } else {
        $result['status'] = 'poor';
        $result['message'] = "Description needs major improvement ({$sections_found}/4 sections present).";
    }
    
    return $result;
}

/**
 * Analyze the quality of a specific description section
 */
function analyze_section_quality($text, $section_type) {
    $length = strlen($text);
    $text_lower = strtolower($text);
    $quality = 0;
    
    // Base score for having content
    if ($length > 50) $quality += 1;
    if ($length > 150) $quality += 1;
    if ($length > 300) $quality += 1;
    
    // Content-specific quality checks
    switch ($section_type) {
        case 'main':
            // Main description should be engaging and highlight key benefits
            if (preg_match('/\b(luxury|modern|cozy|spacious|stunning|beautiful|perfect|ideal|convenient)\b/', $text_lower)) $quality += 1;
            if (strpos($text_lower, 'location') !== false || strpos($text_lower, 'walk') !== false) $quality += 1;
            break;
            
        case 'space':
            // Space description should mention rooms and features
            if (preg_match('/\b(bedroom|bathroom|kitchen|living|room|bed)\b/', $text_lower)) $quality += 1;
            if (preg_match('/\b(balcony|garden|view|terrace|patio|amenities)\b/', $text_lower)) $quality += 1;
            break;
            
        case 'access':
            // Access should clarify what guests can use
            if (preg_match('/\b(entire|private|shared|access|use)\b/', $text_lower)) $quality += 1;
            if (strpos($text_lower, 'guest') !== false) $quality += 1;
            break;
            
        case 'notes':
            // Notes should have useful information
            if (preg_match('/\b(rule|policy|note|important|check|key|parking)\b/', $text_lower)) $quality += 1;
            if (preg_match('/\b(neighborhood|area|nearby|transport|restaurant|shop)\b/', $text_lower)) $quality += 1;
            break;
    }
    
    return min(5, $quality);
}

/**
 * Fallback basic description analysis for non-sectioned descriptions
 */
function airbnb_analyzer_check_description_basic($description) {
    $result = array(
        'category' => 'Description Completeness',
        'score' => 0,
        'max_score' => 15,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    if (empty($description)) {
        $result['message'] = 'No description provided!';
        $result['recommendations'][] = 'Write a detailed description covering the space, guest access, and important notes.';
        return $result;
    }
    
    $description_lower = strtolower($description);
    $length = strlen($description);
    
    // Check for key content areas
    $has_space_description = preg_match('/\b(bedroom|bathroom|kitchen|living|room|space|apartment)\b/', $description_lower);
    $has_location_info = preg_match('/\b(location|walk|nearby|transport|area|neighborhood)\b/', $description_lower);
    $has_access_info = preg_match('/\b(access|entire|private|shared|guest)\b/', $description_lower);
    $has_additional_info = preg_match('/\b(rule|policy|note|important|parking)\b/', $description_lower);
    
    // Score based on content and length
    $score = 0;
    if ($length > 100) $score += 3;
    if ($length > 300) $score += 2;
    if ($has_space_description) $score += 3;
    if ($has_location_info) $score += 3;
    if ($has_access_info) $score += 2;
    if ($has_additional_info) $score += 2;
    
    $result['score'] = min(15, $score);
    
    // Generate recommendations
    $result['recommendations'][] = 'Consider organizing your description into 4 sections: Main Description, The Space, Guest Access, and Other Things to Note.';
    
    if (!$has_space_description) {
        $result['recommendations'][] = 'Add detailed space description: layout, rooms, amenities, and unique features.';
    }
    if (!$has_location_info) {
        $result['recommendations'][] = 'Include location benefits: nearby attractions, transport, restaurants.';
    }
    if (!$has_access_info) {
        $result['recommendations'][] = 'Clarify guest access: what spaces guests can use and any restrictions.';
    }
    if (!$has_additional_info) {
        $result['recommendations'][] = 'Add important notes: house rules, policies, or special considerations.';
    }
    
    // Set status
    if ($result['score'] >= 12) {
        $result['status'] = 'good';
        $result['message'] = 'Good description content but could benefit from better organization.';
    } elseif ($result['score'] >= 8) {
        $result['status'] = 'average';
        $result['message'] = 'Basic description present but missing key information.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'Description needs significant improvement.';
    }
    
    return $result;
}

/**
 * Analyze bathroom amenities
 */
function analyze_bathroom_amenities($amenities) {
    $result = array(
        'category' => 'Bathroom Amenities',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $bathroom_items = array();
    $essential_bathroom = array('hot water', 'hair dryer', 'shampoo', 'body soap');
    $found_essentials = 0;
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'bathroom') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $bathroom_items[] = $item['name'];
                            $item_name = strtolower($item['name']);
                            
                            foreach ($essential_bathroom as $essential) {
                                if (stripos($item_name, $essential) !== false) {
                                    $found_essentials++;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    $result['score'] = min(10, $found_essentials * 2 + count($bathroom_items));
    
    if ($result['score'] >= 8) {
        $result['status'] = 'excellent';
        $result['message'] = 'Excellent bathroom amenities - guests will be comfortable!';
    } elseif ($result['score'] >= 6) {
        $result['status'] = 'good';
        $result['message'] = 'Good bathroom amenities with room for improvement.';
    } elseif ($result['score'] >= 3) {
        $result['status'] = 'average';
        $result['message'] = 'Basic bathroom amenities present.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'Bathroom amenities need attention.';
    }
    
    if ($found_essentials < 3) {
        $result['recommendations'][] = 'Add essential bathroom items: hot water, hair dryer, shampoo, body soap.';
    }
    
    return $result;
}

/**
 * Analyze bedroom and laundry amenities
 */
function analyze_bedroom_laundry_amenities($amenities) {
    $result = array(
        'category' => 'Bedroom & Laundry',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $bedroom_items = array();
    $has_washer = false;
    $has_essentials = false;
    $has_iron = false;
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'bedroom and laundry') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $bedroom_items[] = $item['name'];
                            $item_name = strtolower($item['name']);
                            
                            if (stripos($item_name, 'washing machine') !== false) {
                                $has_washer = true;
                            }
                            if (stripos($item_name, 'essentials') !== false) {
                                $has_essentials = true;
                            }
                            if (stripos($item_name, 'iron') !== false) {
                                $has_iron = true;
                            }
                        }
                    }
                }
            }
        }
    }
    
    $score = 0;
    if ($has_washer) $score += 4;
    if ($has_essentials) $score += 4;
    if ($has_iron) $score += 2;
    
    $result['score'] = $score;
    
    if ($score >= 8) {
        $result['status'] = 'excellent';
        $result['message'] = 'Excellent bedroom and laundry amenities!';
    } elseif ($score >= 6) {
        $result['status'] = 'good';
        $result['message'] = 'Good bedroom amenities.';
    } elseif ($score >= 3) {
        $result['status'] = 'average';
        $result['message'] = 'Basic bedroom amenities.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'Bedroom amenities need improvement.';
    }
    
    if (!$has_washer) {
        $result['recommendations'][] = 'Add washing machine access for guest convenience.';
    }
    if (!$has_essentials) {
        $result['recommendations'][] = 'Provide essentials: towels, bed sheets, soap, toilet paper.';
    }
    
    return $result;
}

/**
 * Analyze entertainment amenities
 */
function analyze_entertainment_amenities($amenities) {
    $result = array(
        'category' => 'Entertainment',
        'score' => 0,
        'max_score' => 8,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $entertainment_items = array();
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'entertainment') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $entertainment_items[] = $item['name'];
                        }
                    }
                }
            }
        }
    }
    
    $result['score'] = min(8, count($entertainment_items) * 4);
    
    if ($result['score'] >= 6) {
        $result['status'] = 'excellent';
        $result['message'] = 'Great entertainment options for guests!';
    } elseif ($result['score'] >= 4) {
        $result['status'] = 'good';
        $result['message'] = 'Good entertainment amenities.';
    } elseif ($result['score'] >= 2) {
        $result['status'] = 'average';
        $result['message'] = 'Basic entertainment available.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'No entertainment amenities found.';
        $result['recommendations'][] = 'Add entertainment options like TV, streaming services, or games.';
    }
    
    return $result;
}

/**
 * Analyze family amenities
 */
function analyze_family_amenities($amenities) {
    $result = array(
        'category' => 'Family-Friendly',
        'score' => 0,
        'max_score' => 6,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $family_items = array();
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'family') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $family_items[] = $item['name'];
                        }
                    }
                }
            }
        }
    }
    
    $result['score'] = min(6, count($family_items) * 3);
    
    if ($result['score'] >= 5) {
        $result['status'] = 'excellent';
        $result['message'] = 'Excellent family-friendly amenities!';
    } elseif ($result['score'] >= 3) {
        $result['status'] = 'good';
        $result['message'] = 'Good family amenities.';
    } elseif ($result['score'] >= 1) {
        $result['status'] = 'average';
        $result['message'] = 'Some family amenities available.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'No family-specific amenities found.';
        $result['recommendations'][] = 'Consider adding family amenities like board games, books, or child-friendly items.';
    }
    
    return $result;
}

/**
 * Analyze internet and office amenities
 */
function analyze_internet_office_amenities($amenities) {
    $result = array(
        'category' => 'Internet & Office',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $office_items = array();
    $has_wifi = false;
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'internet and office') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $office_items[] = $item['name'];
                            if (stripos($item['name'], 'wifi') !== false) {
                                $has_wifi = true;
                            }
                        }
                    }
                }
            }
        }
    }
    
    $result['score'] = $has_wifi ? 10 : 0;
    
    if ($has_wifi) {
        $result['status'] = 'excellent';
        $result['message'] = 'WiFi available - essential for modern guests!';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'WiFi is missing - this is essential!';
        $result['recommendations'][] = 'CRITICAL: Add WiFi - this is expected by virtually all guests.';
    }
    
    return $result;
}

/**
 * Analyze kitchen and dining amenities
 */
function analyze_kitchen_dining_amenities($amenities) {
    $result = array(
        'category' => 'Kitchen & Dining',
        'score' => 0,
        'max_score' => 15,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $kitchen_items = array();
    $essential_kitchen = array('kitchen', 'fridge', 'microwave');
    $found_essentials = 0;
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'kitchen and dining') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $kitchen_items[] = $item['name'];
                            $item_name = strtolower($item['name']);
                            
                            foreach ($essential_kitchen as $essential) {
                                if (stripos($item_name, $essential) !== false) {
                                    $found_essentials++;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    $result['score'] = min(15, $found_essentials * 3 + count($kitchen_items));
    
    if ($result['score'] >= 12) {
        $result['status'] = 'excellent';
        $result['message'] = 'Excellent kitchen facilities - guests can cook comfortably!';
    } elseif ($result['score'] >= 8) {
        $result['status'] = 'good';
        $result['message'] = 'Good kitchen amenities.';
    } elseif ($result['score'] >= 4) {
        $result['status'] = 'average';
        $result['message'] = 'Basic kitchen facilities available.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'Kitchen facilities need improvement.';
    }
    
    if ($found_essentials < 2) {
        $result['recommendations'][] = 'Add essential kitchen items: full kitchen access, fridge, microwave.';
    }
    
    return $result;
}

/**
 * Analyze parking and facilities amenities
 */
function analyze_parking_facilities_amenities($amenities) {
    $result = array(
        'category' => 'Parking & Facilities',
        'score' => 0,
        'max_score' => 8,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $facility_items = array();
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'parking and facilities') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $facility_items[] = $item['name'];
                        }
                    }
                }
            }
        }
    }
    
    $result['score'] = min(8, count($facility_items) * 4);
    
    if ($result['score'] >= 6) {
        $result['status'] = 'excellent';
        $result['message'] = 'Great parking and facility access!';
    } elseif ($result['score'] >= 4) {
        $result['status'] = 'good';
        $result['message'] = 'Good facility amenities.';
    } elseif ($result['score'] >= 2) {
        $result['status'] = 'average';
        $result['message'] = 'Some facilities available.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'No parking or facility information found.';
        $result['recommendations'][] = 'Consider mentioning parking options or building facilities like elevator access.';
    }
    
    return $result;
}

/**
 * Analyze services amenities
 */
function analyze_services_amenities($amenities) {
    $result = array(
        'category' => 'Guest Services',
        'score' => 0,
        'max_score' => 10,
        'status' => 'poor',
        'message' => '',
        'recommendations' => array(),
    );
    
    $service_items = array();
    $has_self_checkin = false;
    
    if (is_array($amenities)) {
        foreach ($amenities as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['group_name']) && isset($amenity_group['items'])) {
                if (strtolower($amenity_group['group_name']) === 'services') {
                    foreach ($amenity_group['items'] as $item) {
                        if (isset($item['name'])) {
                            $service_items[] = $item['name'];
                            if (stripos($item['name'], 'self check-in') !== false) {
                                $has_self_checkin = true;
                            }
                        }
                    }
                }
            }
        }
    }
    
    $result['score'] = min(10, count($service_items) * 3 + ($has_self_checkin ? 4 : 0));
    
    if ($result['score'] >= 8) {
        $result['status'] = 'excellent';
        $result['message'] = 'Excellent guest services - convenient and flexible!';
    } elseif ($result['score'] >= 5) {
        $result['status'] = 'good';
        $result['message'] = 'Good guest services.';
    } elseif ($result['score'] >= 2) {
        $result['status'] = 'average';
        $result['message'] = 'Basic services available.';
    } else {
        $result['status'] = 'poor';
        $result['message'] = 'No guest services found.';
        $result['recommendations'][] = 'Consider adding self check-in or other convenient guest services.';
    }
    
    return $result;
}