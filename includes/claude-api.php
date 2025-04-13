<?php
/**
 * Claude AI API integration for AirBnB Listing Analyzer
 */

/**
 * Send a request to Claude API
 * 
 * @param string $prompt The prompt to send to Claude
 * @return array|WP_Error The Claude API response or error
 */
function airbnb_analyzer_claude_request($prompt) {
    $api_key = get_option('airbnb_analyzer_claude_api_key');
    
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', 'Claude API key is not configured. Please set it in the settings page.');
    }
    
    $url = 'https://api.anthropic.com/v1/messages';
    
    $args = array(
        'method' => 'POST',
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01'
        ),
        'body' => json_encode(array(
            'model' => 'claude-3-haiku-20240307', // Using the cheapest Claude model
            'max_tokens' => 1000,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        ))
    );
    
    $response = wp_remote_post($url, $args);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        return new WP_Error('api_error', 'Error from Claude API: ' . $status_code);
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_error', 'Error parsing Claude API response: ' . json_last_error_msg());
    }
    
    return $data;
}

/**
 * Analyze listing title with Claude
 * 
 * @param array $listing_data The listing data
 * @return array Analysis results
 */
function airbnb_analyzer_claude_analyze_title($listing_data) {
    $title = $listing_data['title'];
    
    $prompt = "You are an Airbnb listing optimization expert. Please analyze this Airbnb listing title and provide feedback:
    
Title: \"$title\"

1. Rate the title quality on a scale of 1-10
2. Explain what makes it effective or ineffective
3. Suggest 2-3 alternative titles that might perform better
4. Keep your response concise and focused on actionable improvements

Format your response as JSON with these fields:
- rating: (number)
- feedback: (string)
- alternative_titles: (array of strings)";
    
    $response = airbnb_analyzer_claude_request($prompt);
    
    if (is_wp_error($response)) {
        return array(
            'status' => 'error',
            'message' => $response->get_error_message()
        );
    }
    
    // Extract the content from Claude's response
    if (isset($response['content'][0]['text'])) {
        $content = $response['content'][0]['text'];
        // Try to parse the JSON response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');
        if ($json_start !== false && $json_end !== false) {
            $json = substr($content, $json_start, $json_end - $json_start + 1);
            $data = json_decode($json, true);
            if ($data) {
                return array(
                    'status' => 'success',
                    'data' => $data
                );
            }
        }
    }
    
    // Fallback if JSON parsing fails
    return array(
        'status' => 'error',
        'message' => 'Could not parse Claude response'
    );
}

/**
 * Analyze listing description with Claude
 * 
 * @param array $listing_data The listing data
 * @return array Analysis results
 */
function airbnb_analyzer_claude_analyze_description($listing_data) {
    $description = $listing_data['description'];
    
    $prompt = "You are an Airbnb listing optimization expert. Please analyze this Airbnb listing description and provide feedback:
    
Description: \"$description\"

Important notes:
- The first 400 characters are the most important as they appear before the 'read more' button
- Analyze for clarity, appeal, and completeness
- Check for proper formatting, spelling, and grammar

Please provide:
1. Rating of the description quality (1-10)
2. Analysis of the first 400 characters
3. Overall feedback on the full description
4. 2-3 specific improvement suggestions

Format your response as JSON with these fields:
- rating: (number)
- first_impression: (string)
- overall_feedback: (string)
- suggestions: (array of strings)";
    
    $response = airbnb_analyzer_claude_request($prompt);
    
    if (is_wp_error($response)) {
        return array(
            'status' => 'error',
            'message' => $response->get_error_message()
        );
    }
    
    // Extract the content from Claude's response
    if (isset($response['content'][0]['text'])) {
        $content = $response['content'][0]['text'];
        // Try to parse the JSON response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');
        if ($json_start !== false && $json_end !== false) {
            $json = substr($content, $json_start, $json_end - $json_start + 1);
            $data = json_decode($json, true);
            if ($data) {
                return array(
                    'status' => 'success',
                    'data' => $data
                );
            }
        }
    }
    
    // Fallback if JSON parsing fails
    return array(
        'status' => 'error',
        'message' => 'Could not parse Claude response'
    );
}

/**
 * Analyze host profile with Claude
 * 
 * @param array $listing_data The listing data
 * @return array Analysis results
 */
function airbnb_analyzer_claude_analyze_host($listing_data) {
    $host_name = $listing_data['host_name'];
    $host_is_superhost = $listing_data['host_is_superhost'] ? 'Yes' : 'No';
    $host_about = isset($listing_data['host_about']) ? $listing_data['host_about'] : '';
    $host_response_rate = isset($listing_data['host_response_rate']) ? $listing_data['host_response_rate'] : '';
    $host_response_time = isset($listing_data['host_response_time']) ? $listing_data['host_response_time'] : '';
    $host_highlights = isset($listing_data['host_highlights']) ? implode("\n", $listing_data['host_highlights']) : '';
    $neighborhood_details = isset($listing_data['neighborhood_details']) ? $listing_data['neighborhood_details'] : '';
    $host_rating = isset($listing_data['host_rating']) ? $listing_data['host_rating'] : '';
    $host_review_count = isset($listing_data['host_review_count']) ? $listing_data['host_review_count'] : '';
    
    $prompt = "You are an Airbnb host optimization expert. Please analyze this host profile and provide feedback:
    
Host Name: \"$host_name\"
Superhost: $host_is_superhost
About: \"$host_about\"
Response Rate: $host_response_rate
Response Time: $host_response_time
Host Rating: $host_rating
Host Review Count: $host_review_count
Host Highlights: 
$host_highlights
Neighborhood Details:
$neighborhood_details

Please analyze:
1. Overall profile completeness (1-10)
2. Bio quality and optimal length (150-250 words is ideal)
3. Response rate and time effectiveness
4. Neighborhood highlights completeness
5. Rating and review quality
6. Specific improvement suggestions

Format your response as JSON with these fields:
- completeness_score: (number)
- bio_feedback: (string)
- response_feedback: (string)
- highlights_feedback: (string)
- neighborhood_feedback: (string)
- rating_feedback: (string)
- suggestions: (array of strings)";
    
    $response = airbnb_analyzer_claude_request($prompt);
    
    if (is_wp_error($response)) {
        return array(
            'status' => 'error',
            'message' => $response->get_error_message()
        );
    }
    
    // Extract the content from Claude's response
    if (isset($response['content'][0]['text'])) {
        $content = $response['content'][0]['text'];
        // Try to parse the JSON response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');
        if ($json_start !== false && $json_end !== false) {
            $json = substr($content, $json_start, $json_end - $json_start + 1);
            $data = json_decode($json, true);
            if ($data) {
                return array(
                    'status' => 'success',
                    'data' => $data
                );
            }
        }
    }
    
    // Fallback if JSON parsing fails
    return array(
        'status' => 'error',
        'message' => 'Could not parse Claude response'
    );
}

/**
 * Analyze amenities with Claude
 * 
 * @param array $listing_data The listing data
 * @return array Analysis results
 */
function airbnb_analyzer_claude_analyze_amenities($listing_data) {
    $amenities = isset($listing_data['amenities']) ? $listing_data['amenities'] : array();
    $amenities_list = implode("\n", $amenities);
    $property_type = isset($listing_data['property_type']) ? $listing_data['property_type'] : 'Property';
    
    // Define essential amenities by category for reference
    $essential_categories = "
Bathroom: Hair dryer, Shampoo, Hot water, Shower gel, Toilet paper
Bedroom and Laundry: Washing machine, Dryer, Bed linens, Extra pillows and blankets, Hangers, Iron
Essentials: Towels, Bed sheets, Soap, Toilet paper, Hangers
Entertainment: TV, Books, Board games
Heating and Cooling: Heating, Air conditioning, Fans
Home Safety: Smoke alarm, Carbon monoxide alarm, Fire extinguisher, First aid kit
Internet and Office: Wifi, Dedicated workspace, Laptop-friendly workspace
Kitchen and Dining: Kitchen, Refrigerator, Microwave, Cooking basics, Dishes and silverware, Dishwasher, Coffee maker";
    
    $prompt = "You are an Airbnb optimization expert. Please analyze these amenities for a $property_type listing:

$amenities_list

Here are the essential amenities by category that guests typically look for:
$essential_categories

Please analyze:
1. Overall amenities score (1-10)
2. Coverage of essential amenities by category
3. Missing important amenities by category
4. Standout/unique amenities that could be highlighted in the listing
5. Specific improvement suggestions

Format your response as JSON with these fields:
- score: (number)
- overall_feedback: (string)
- category_analysis: (object with category names as keys and feedback as values)
- missing_essentials: (array of strings)
- standout_amenities: (array of strings)
- suggestions: (array of strings)";
    
    $response = airbnb_analyzer_claude_request($prompt);
    
    if (is_wp_error($response)) {
        return array(
            'status' => 'error',
            'message' => $response->get_error_message()
        );
    }
    
    // Extract the content from Claude's response
    if (isset($response['content'][0]['text'])) {
        $content = $response['content'][0]['text'];
        // Try to parse the JSON response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');
        if ($json_start !== false && $json_end !== false) {
            $json = substr($content, $json_start, $json_end - $json_start + 1);
            $data = json_decode($json, true);
            if ($data) {
                return array(
                    'status' => 'success',
                    'data' => $data
                );
            }
        }
    }
    
    // Fallback if JSON parsing fails
    return array(
        'status' => 'error',
        'message' => 'Could not parse Claude response'
    );
} 