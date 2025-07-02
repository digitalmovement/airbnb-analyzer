# Airbnb Analysis Results Page Setup

## Overview
The plugin now displays results using a WordPress shortcode instead of a custom PHP page. This makes the URLs cleaner and more WordPress-friendly.

## Setup Instructions

### 1. Create the Results Page
1. Go to **Pages → Add New** in your WordPress admin
2. Set the page title to: `Airbnb Analysis Results`
3. Set the page slug to: `airbnb-analysis-results` (this creates the URL `/airbnb-analysis-results/`)
4. In the page content, add only this shortcode:
   ```
   [airbnb_analysis_results]
   ```
5. Publish the page

### 2. URL Structure
- **Old URL**: `/wp-content/plugins/airbnb-analyzer/view-results.php?id=12345`
- **New URL**: `/airbnb-analysis-results/?id=12345`

### 3. What Changed
- **Admin Dashboard**: All "View Results" links now point to the new URL
- **Email Notifications**: Results links in emails now use the new URL  
- **Shortcode Support**: The `[airbnb_analysis_results]` shortcode displays the full analysis
- **Responsive Design**: Results now inherit your theme's responsive design
- **SEO Friendly**: Clean WordPress URLs instead of direct plugin file access

### 4. Features
- **Auto-detect ID**: The shortcode automatically reads the `id` parameter from the URL
- **Error Handling**: Shows user-friendly error messages if no ID is provided
- **Admin Debug**: Admin users still see the debug panel at the bottom
- **Theme Integration**: Results now blend seamlessly with your site's design

### 5. Backwards Compatibility
The old `view-results.php` file still works for any existing bookmarked links, but all new links will use the shortcode-based approach.

## Troubleshooting

**Problem**: "No analysis ID provided" error
**Solution**: Make sure the URL includes `?id=snapshot_id` parameter

**Problem**: Page not found
**Solution**: 
1. Ensure you created the page with slug `airbnb-analysis-results`
2. Go to **Settings → Permalinks** and click "Save Changes" to refresh URL rules

**Problem**: Results look unstyled
**Solution**: The shortcode includes its own CSS styling that should work with any theme 