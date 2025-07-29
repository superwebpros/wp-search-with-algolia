# MongoDB Logging Setup for WP Search with Algolia

## Quick Setup Guide

### 1. Add Configuration to wp-config.php

Add these lines to your `wp-config.php`:

```php
// MongoDB connection for Algolia logging
define( 'MONGODB_URI', 'mongodb+srv://wp_algolia_user:WpAlgolia2025!Secure@ch-db-mongodb-nyc1-54962-66672fda.mongo.ondigitalocean.com/wp_algolia_logs?tls=true&authSource=wp_algolia_logs&replicaSet=ch-db-mongodb-nyc1-54962' );
define( 'MONGODB_DATABASE', 'wp_algolia_logs' );
define( 'ALGOLIA_ENABLE_MONGO_LOGGING', true );
```

### 2. Install MongoDB PHP Driver

```bash
# Install MongoDB extension
sudo pecl install mongodb

# Add to php.ini
echo "extension=mongodb.so" | sudo tee -a /etc/php/7.4/fpm/php.ini

# Install composer package
composer require mongodb/mongodb
```

### 3. Add the Integration File

Copy `includes/algolia-mongo-integration.php` to your mu-plugins folder:

```bash
cp includes/algolia-mongo-integration.php wp-content/mu-plugins/
```

Or include it in your theme's functions.php:

```php
require_once get_template_directory() . '/includes/algolia-mongo-integration.php';
```

### 4. Test the Connection

Run this test in WP-CLI:

```bash
wp eval '
try {
    $client = new MongoDB\Client(MONGODB_URI);
    $db = $client->selectDatabase(MONGODB_DATABASE);
    $collections = $db->listCollections();
    echo "✓ MongoDB connection successful!\n";
    echo "Database: " . MONGODB_DATABASE . "\n";
    foreach ($collections as $collection) {
        echo "- Collection: " . $collection->getName() . "\n";
    }
} catch (Exception $e) {
    echo "✗ MongoDB connection failed: " . $e->getMessage() . "\n";
}
'
```

### 5. Start Indexing with Logging

The logging will automatically activate when you run any indexing operation:

```bash
# Re-index products
wp algolia reindex --type=product

# Or through the admin UI
# Go to: WP Admin → Algolia → Indexing
```

### 6. View Logs in MongoDB

#### Option A: WordPress Admin
Go to: **WP Admin → Algolia → MongoDB Logs**

#### Option B: WP-CLI Commands

```bash
# Find race conditions
wp algolia mongo-races

# Analyze specific session
wp algolia mongo-analyze idx_xyz123 --post-type=product
```

#### Option C: MongoDB Compass
1. Connect to: `mongodb+srv://wp_algolia_user:WpAlgolia2025!Secure@ch-db-mongodb-nyc1-54962-66672fda.mongo.ondigitalocean.com/`
2. Navigate to `wp_algolia_logs` database
3. Query the collections:
   - `items` - All indexed items with their journey
   - `race_conditions` - Detected concurrent access
   - `sessions` - Indexing session summaries

## Finding Issues

### Race Conditions Query (MongoDB Compass)
```javascript
// Find items accessed multiple times within 10 seconds
db.race_conditions.aggregate([
    { $match: { type: "concurrent_access" } },
    { $group: {
        _id: "$item_id",
        count: { $sum: 1 },
        sessions: { $addToSet: "$session_id" }
    }},
    { $sort: { count: -1 } },
    { $limit: 20 }
])
```

### Missing Items Query
```javascript
// Find items that started but didn't complete
db.items.find({
    session_id: "idx_YOUR_SESSION_ID",
    final_status: { $exists: false }
})
```

### Item Journey
```javascript
// Track specific item through all stages
db.items.findOne(
    { item_id: 12345 },
    { timeline: 1, errors: 1, final_status: 1, _id: 0 }
)
```

## What Gets Logged

1. **Item Tracking**
   - Every item ID through each stage
   - Timestamp at each stage
   - Success/failure status
   - Skip reasons

2. **Race Condition Detection**
   - Items accessed by multiple sessions
   - Time between accesses
   - Concurrent session IDs

3. **Performance Metrics**
   - Memory usage at each stage
   - Processing duration
   - Batch information

## Automatic Cleanup

Logs are automatically deleted after 7 days. To change this:

```php
// In wp-config.php - set to 14 days
define( 'ALGOLIA_MONGO_TTL_DAYS', 14 );
```

## Troubleshooting

### "MongoDB extension not found"
```bash
# Check if extension is loaded
php -m | grep mongodb

# If not, install it
sudo apt-get install php-mongodb
# or
sudo pecl install mongodb
```

### "Connection timeout"
- Check your server can reach DigitalOcean's MongoDB cluster
- Verify IP whitelist settings in DigitalOcean dashboard
- Test connection with mongosh or MongoDB Compass first

### "Collections not created"
The logger will create collections automatically on first use. If they're missing:
```bash
wp eval '$logger = new Algolia_Mongo_Logger(); $logger->track_item(1, "test", ["data" => "test"]);'
```