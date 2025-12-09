# Migration from Brightdata to Python PyAirbnb

This document describes the migration from Brightdata API to Python-based scraping using the pyairbnb library.

## Changes Made

### 1. New Files Created

- **`pyairbnb-scraper.py`**: Python script that uses pyairbnb.get_details() to scrape Airbnb listings
- **`includes/pyairbnb-api.php`**: PHP wrapper that replaces brightdata-api.php functionality

### 2. Files Modified

- **`airbnb-analyzer.php`**: 
  - Replaced brightdata-api.php include with pyairbnb-api.php
  - Updated AJAX handler to use pyairbnb_trigger_scraping() instead of brightdata_trigger_scraping()
  - Added cron hook for processing PyAirbnb requests

- **`includes/api.php`**: Updated comments to reflect PyAirbnb instead of Brightdata

- **`includes/settings.php`**: 
  - Removed Brightdata API key, dataset ID, and test mode settings
  - Added Python path, currency, and language settings

- **`view-results.php`**: Updated to use pyairbnb_get_request() instead of brightdata_get_request()

- **`results.php`**: Updated to use pyairbnb_get_request() instead of brightdata_get_request()

- **`notify.php`**: 
  - Marked as deprecated (webhooks no longer needed with cron-based processing)
  - Updated to use new PyAirbnb functions for backward compatibility

- **`includes/admin.php`**: Updated to use pyairbnb_format_for_analyzer() instead of brightdata_format_for_analyzer()

- **`README.md`**: Updated documentation to reflect Python-based scraping

### 3. Database

- The database table name `wp_airbnb_analyzer_brightdata_requests` is kept for backward compatibility
- All new requests use the same table structure
- Request IDs now use format: `pyairbnb_[timestamp]_[random]` instead of Brightdata snapshot IDs

## Installation Requirements

1. **Python 3** must be installed on the server
2. **pyairbnb library** must be installed (see installation methods below)
3. **Python path** must be configured in plugin settings (default: python3)

### Installing pyairbnb Without Admin Permissions

If you don't have admin/sudo access, use one of these methods:

**Method 1: User Installation (Recommended)**
```bash
pip install --user pyairbnb
```
or
```bash
python3 -m pip install --user pyairbnb
```

**Method 2: Local Installation**
Download pyairbnb and place it in the plugin directory or WordPress root.

**Method 3: Virtual Environment**
Create a virtual environment and install pyairbnb there.

See `INSTALL_PYTHON_NO_ADMIN.md` for detailed instructions on all methods.

## Configuration

Go to WordPress Admin → Settings → AirBnB Analyzer and configure:

- **Python Path**: Path to Python executable (e.g., `python3`, `python`, or full path)
- **Currency**: Default currency code (e.g., USD, EUR, GBP)
- **Language**: Default language code (e.g., en, es, fr, de)

## How It Works

1. User submits Airbnb listing URL via the form
2. PHP stores the request in the database with status 'pending'
3. WordPress cron schedules the processing (runs within 5 seconds)
4. Cron calls `pyairbnb_process_request()` which:
   - Calls the Python script `pyairbnb-scraper.py`
   - Python script uses `pyairbnb.get_details()` to scrape the listing
   - Python returns JSON data
   - PHP formats the data using `pyairbnb_format_for_analyzer()`
   - Analysis is performed (with or without Claude AI)
   - Results are stored and email is sent

## Backward Compatibility

- Old Brightdata requests in the database will still work
- The `brightdata-api.php` file is kept but no longer used
- Table name remains the same for compatibility
- All existing analysis results remain accessible

## Testing

To test the new system:

1. Ensure Python 3 and pyairbnb are installed
2. Configure Python path in settings
3. Submit a test Airbnb listing URL
4. Check that the request is processed within 1-2 minutes
5. Verify email is received with results

## Troubleshooting

### Python script not found
- Check that `pyairbnb-scraper.py` is in the plugin root directory
- Verify file permissions allow execution

### pyairbnb import error
- Run `pip install pyairbnb` on the server
- Verify Python path in settings is correct
- Check Python version: `python3 --version` (should be 3.6+)

### No data returned
- Check Python script output in debug logs
- Verify the Airbnb URL is valid and accessible
- Check that pyairbnb library is working: `python3 -c "import pyairbnb; print(pyairbnb.__version__)"`

### Cron not running
- WordPress cron requires site traffic to trigger
- You can manually trigger: `wp cron event run airbnb_analyzer_process_pyairbnb_request`
- Or use a real cron job to trigger WordPress cron: `*/5 * * * * curl -s https://yoursite.com/wp-cron.php`

