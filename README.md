# Airbnb Listing Analyzer WordPress Plugin

A comprehensive WordPress plugin that analyzes Airbnb listings and provides detailed optimization recommendations using BrightData API and Claude AI.

## Features

- **Comprehensive Analysis**: Analyzes 15+ aspects of Airbnb listings including title, photos, description, amenities, reviews, and more
- **AI-Powered Insights**: Uses Claude AI for intelligent recommendations
- **Category-Specific Amenity Analysis**: Breaks down amenities by categories (Bathroom, Kitchen, Safety, etc.)
- **Email Notifications**: Sends results via email when analysis is complete
- **Admin Dashboard**: Full statistics and management interface
- **Shortcode Display**: Clean, theme-integrated results pages

## Installation

1. Upload the plugin files to `/wp-content/plugins/airbnb-analyzer/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure API keys in the admin settings
4. Create a results page (see Shortcode Setup below)

## Shortcodes

### `[airbnb_analyzer]`
Displays the main analyzer form where users can submit Airbnb URLs for analysis.

### `[airbnb_analysis_results]`
Displays analysis results. **Important**: You must create a WordPress page with this shortcode for results to display properly.

## Required Setup

### 1. Create Results Page
1. Go to **Pages → Add New**
2. Title: "Airbnb Analysis Results"
3. Slug: `airbnb-analysis-results`
4. Content: `[airbnb_analysis_results]`
5. Publish the page

### 2. API Configuration
Configure these API keys in the WordPress admin:
- **BrightData API**: For scraping Airbnb listing data
- **Claude API**: For AI-powered analysis and recommendations
- **reCAPTCHA**: For spam protection

## Analysis Categories

The plugin analyzes these aspects of Airbnb listings:

1. **Title Optimization** - Character count, keywords, appeal
2. **Photo Quality** - Count, variety, first impression
3. **Description Analysis** - Four-section breakdown with specific recommendations
4. **Host Performance** - Superhost status, response rate, experience
5. **Review Analysis** - Rating, count, guest favorite status, category ratings
6. **Essential Amenities** - WiFi, kitchen basics, parking
7. **Bathroom Amenities** - Towels, toiletries, hair dryer
8. **Bedroom & Laundry** - Linens, hangers, washer/dryer
9. **Entertainment** - TV, streaming, games
10. **Family Features** - Child safety, high chair, crib
11. **Climate Control** - Heating, air conditioning
12. **Safety Features** - Smoke detector, CO detector, first aid
13. **Internet & Office** - WiFi speed, workspace
14. **Kitchen & Dining** - Appliances, cookware, dining space
15. **Parking & Facilities** - Parking availability, accessibility
16. **Guest Services** - Check-in options, cleaning fees

## URL Structure

- **Analyzer Form**: `/your-analyzer-page/` (where you place `[airbnb_analyzer]`)
- **Results**: `/airbnb-analysis-results/?id=snapshot_id`

## Admin Features

- **Statistics Dashboard**: View analysis metrics and engagement rates
- **Email Management**: Export email lists, view submissions
- **Pending Requests**: Process incomplete analyses
- **Debug Tools**: View raw data, clear cache, regenerate reports

## Technical Details

- **Database Tables**: Stores analysis data, email submissions, and raw API responses
- **Caching**: Intelligent caching prevents duplicate API calls
- **Error Handling**: Graceful handling of API failures and missing data
- **Security**: Input sanitization, nonce verification, capability checks

## Support

For setup assistance or troubleshooting, refer to `SHORTCODE_SETUP.md` for detailed instructions on configuring the results page.
 
