# PyAirbnb Custom API Data Format

This document describes the data format returned by the custom Airbnb API used by the Airbnb Listing Analyzer plugin.

## API Endpoint

- **URL**: Configured via `airbnb_analyzer_api_url` option (default: `https://airbnb-api-cql0.onrender.com/api/listing/details`)
- **Method**: POST
- **Content-Type**: application/json

## Request Body

```json
{
  "api_key": "your-api-key",
  "room_url": "https://www.airbnb.com/rooms/123456",
  "currency": "USD",
  "language": "en",
  "adults": 2
}
```

## Response Structure

The API returns a JSON response with the following top-level structure:

```json
{
  "data": { ... },
  "error": false
}
```

## Data Object Fields

### Basic Listing Information

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `title` | string | Listing title | `"Lovely 1 Bedroom Duplex Apartment"` |
| `description` | string | HTML-formatted description with sections | See [Description Format](#description-format) |
| `room_type` | string | Type of accommodation | `"Entire home/apt"` |
| `person_capacity` | integer | Maximum number of guests | `2` |
| `home_tier` | integer | Listing tier/level | `1` |
| `language` | string | Listing language | `"en"` |

### Sub Description

Contains parsed property details:

```json
{
  "sub_description": {
    "title": "Entire rental unit in Dubai, United Arab Emirates",
    "items": [
      "2 guests",
      "1 bedroom", 
      "1 bed",
      "1.5 baths"
    ]
  }
}
```

### Coordinates

```json
{
  "coordinates": {
    "latitude": 25.18854,
    "longitude": 55.2627
  }
}
```

### Rating Object

Contains all rating categories and review count:

```json
{
  "rating": {
    "accuracy": 4.94,
    "checking": 4.71,
    "cleanliness": 4.94,
    "communication": 5.0,
    "guest_satisfaction": 4.94,
    "location": 4.94,
    "value": 4.97,
    "review_count": "31"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `accuracy` | float | Accuracy rating (0-5) |
| `checking` | float | Check-in rating (0-5) |
| `cleanliness` | float | Cleanliness rating (0-5) |
| `communication` | float | Communication rating (0-5) |
| `guest_satisfaction` | float | Overall satisfaction rating (0-5) |
| `location` | float | Location rating (0-5) |
| `value` | float | Value rating (0-5) |
| `review_count` | string | Number of reviews (as string) |

### Host Information

```json
{
  "host": {
    "id": "673759711",
    "name": "Hosted by Rayan"
  },
  "is_super_host": true,
  "is_guest_favorite": true
}
```

**Note**: `is_super_host` uses underscore (not `is_superhost`).

### Images

Array of image objects:

```json
{
  "images": [
    {
      "title": "Living room image 1",
      "url": "https://a0.muscache.com/im/pictures/..."
    },
    {
      "title": "Bedroom image 1", 
      "url": "https://a0.muscache.com/im/pictures/..."
    }
  ]
}
```

### Amenities

Amenities are grouped by category:

```json
{
  "amenities": [
    {
      "title": "Bathroom",
      "values": [
        {
          "available": true,
          "icon": "SYSTEM_HAIRDRYER",
          "subtitle": "",
          "title": "Hair dryer"
        },
        {
          "available": true,
          "icon": "SYSTEM_HOT_WATER",
          "subtitle": "",
          "title": "Hot water"
        }
      ]
    },
    {
      "title": "Home safety",
      "values": [
        {
          "available": true,
          "icon": "SYSTEM_DETECTOR_SMOKE",
          "subtitle": "",
          "title": "Smoke alarm"
        }
      ]
    }
  ]
}
```

#### Common Amenity Groups

- `Scenic views`
- `Bathroom`
- `Bedroom and laundry`
- `Entertainment`
- `Heating and cooling`
- `Home safety`
- `Internet and office`
- `Kitchen and dining`
- `Location features`
- `Outdoor`
- `Parking and facilities`
- `Services`
- `Not included`

#### Common Icon Values

| Icon | Description |
|------|-------------|
| `SYSTEM_DETECTOR_SMOKE` | Smoke alarm |
| `SYSTEM_DETECTOR_CO` | Carbon monoxide detector |
| `SYSTEM_FIRST_AID_KIT` | First aid kit |
| `SYSTEM_FIRE_EXTINGUISHER` | Fire extinguisher |
| `SYSTEM_HAIRDRYER` | Hair dryer |
| `SYSTEM_HOT_WATER` | Hot water |
| `SYSTEM_WASHER` | Washing machine |
| `SYSTEM_DRYER` | Dryer |
| `SYSTEM_IRON` | Iron |
| `SYSTEM_WIFI` | WiFi |
| `SYSTEM_TV` | Television |
| `SYSTEM_VIEW_CITY` | City view |

### House Rules

```json
{
  "house_rules": {
    "general": "Check-in after 3:00 PM",
    "aditional": "Alcohol is not allowed in Co-Living premises."
  }
}
```

**Note**: `aditional` is intentionally misspelled in the API response.

### Location Descriptions

```json
{
  "location_descriptions": [
    {
      "title": "Dubai, United Arab Emirates",
      "content": "Executive Towers in Business Bay is a lively neighborhood..."
    },
    {
      "title": "Getting around",
      "content": "Getting around is easy at Executive Towers..."
    }
  ]
}
```

### Highlights

```json
{
  "highlights": [
    {
      "icon": "SYSTEM_CHECK_IN",
      "title": "Self check-in",
      "subtitle": "You can check in with the building staff."
    },
    {
      "icon": "SYSTEM_LOCATION",
      "title": "Great restaurants nearby",
      "subtitle": "Guests say there are excellent options for dining out."
    }
  ]
}
```

### Reviews

Array of review objects:

```json
{
  "reviews": [
    {
      "__typename": "PdpReviewForP3",
      "id": "1539761966650747553",
      "comments": "Great place will be back!!",
      "rating": 5,
      "createdAt": "2025-10-19T11:47:54Z",
      "localizedDate": "October 2025",
      "localizedReviewerLocation": "Elgin, Illinois",
      "language": "en",
      "reviewHighlight": "Stayed a few nights"
    }
  ]
}
```

### Calendar (Optional)

Contains availability calendar data:

```json
{
  "calendar": [
    {
      "__typename": "MerlinCalendarMonth",
      "listingId": "1339206455672752972",
      "month": 12,
      "year": 2025,
      "days": [...],
      "conditionRanges": [...]
    }
  ]
}
```

## Description Format

The description field contains HTML with section markers:

```html
Welcome to our lovely apartment...<br /><br />
<b>The space</b><br />
The apartment features a spacious living room...<br /><br />
<b>Guest access</b><br />
You'll have access to the entire apartment...<br /><br />
<b>Other things to note</b><br />
Please note that check-in is after 3 PM...
```

### Section Headers

- Main description (no header, appears first)
- `<b>The space</b>` - Property description
- `<b>Guest access</b>` - What guests can access
- `<b>Other things to note</b>` - Additional information
- `<b>Registration Details</b>` - Legal registration info (optional)

## Data Transformation

The `pyairbnb_format_for_analyzer()` function in `includes/pyairbnb-api.php` transforms this API data to the analyzer format.

### Key Mappings

| API Field | Analyzer Field |
|-----------|----------------|
| `data.title` | `title`, `listing_title` |
| `data.images[].url` | `photos[]` |
| `data.rating.guest_satisfaction` | `rating` |
| `data.rating.review_count` | `review_count`, `property_number_of_reviews` |
| `data.is_super_host` | `is_superhost`, `is_supperhost` |
| `data.is_guest_favorite` | `is_guest_favorite` |
| `data.person_capacity` | `max_guests` |
| `data.sub_description.items` | Parsed to `bedrooms`, `bathrooms`, `beds` |
| `data.amenities[].title` | `amenities[].group_name` |
| `data.amenities[].values[].title` | `amenities[].items[].name` |
| `data.amenities[].values[].icon` | `amenities[].items[].value` |
| `data.rating.*` | `property_rating_details[]` |
| `data.host.name` | `host_name` (stripped "Hosted by " prefix) |
| `data.location_descriptions` | `location`, `neighborhood_details` |

### Amenities Transformation

**From API format:**
```json
{
  "title": "Home safety",
  "values": [
    {"available": true, "icon": "SYSTEM_DETECTOR_SMOKE", "title": "Smoke alarm"}
  ]
}
```

**To Analyzer format:**
```json
{
  "group_name": "Home safety",
  "items": [
    {"name": "Smoke alarm", "value": "SYSTEM_DETECTOR_SMOKE"}
  ]
}
```

### Rating Details Transformation

**From API format:**
```json
{
  "rating": {
    "accuracy": 4.94,
    "checking": 4.71,
    "cleanliness": 4.94
  }
}
```

**To Analyzer format:**
```json
{
  "property_rating_details": [
    {"name": "Accuracy", "value": 4.94},
    {"name": "Check-in", "value": 4.71},
    {"name": "Cleanliness", "value": 4.94}
  ]
}
```

## Example Complete Response

See the JSON files in the project root for complete examples:
- `airbnb_output2.json` - Dubai co-living space
- `airbnb_output5.json` - Manchester apartment
- `airbnb_output7.json` - London room

## Error Handling

When an error occurs, the API returns:

```json
{
  "data": null,
  "error": true,
  "message": "Error description here"
}
```

## Notes for AI Assistants

1. **Field naming inconsistencies**: The API uses `is_super_host` (with underscore) but some older code expects `is_superhost` or `is_supperhost`. The transformation function handles all variants.

2. **String vs Number**: `review_count` is returned as a string in the rating object but should be converted to integer.

3. **HTML in description**: The description contains HTML tags (`<br />`, `<b>`) that need to be parsed or stripped.

4. **Unavailable amenities**: Amenities with `available: false` should typically be filtered out.

5. **Group name case**: Amenity group names have title case in the API (e.g., "Home safety") but analyzers use `strtolower()` for comparison.

