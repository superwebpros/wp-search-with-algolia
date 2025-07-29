# Production MongoDB Logging Guide

This guide explains how to use MongoDB logging to debug indexing issues and race conditions in production with minimal performance impact.

## Setup

### 1. Install MongoDB Driver

```bash
composer require mongodb/mongodb
```

### 2. Configure Connection

Add to your `wp-config.php`:

```php
define( 'MONGODB_URI', 'mongodb://localhost:27017' );
define( 'MONGODB_DATABASE', 'wp_algolia_logs' );
```

Or use environment variables:
```bash
export MONGODB_URI="mongodb+srv://user:pass@cluster.mongodb.net/"
export MONGODB_DATABASE="wp_algolia_logs"
```

### 3. Enable Logging in Your Index

```php
// In your theme's functions.php or a custom plugin
add_filter( 'algolia_posts_index_class', function( $index ) {
    require_once 'path/to/class-algolia-mongo-logger.php';
    
    // Add MongoDB logging to the index
    class Logged_Posts_Index extends $index {
        use Algolia_Mongo_Logging;
        
        public function re_index( $page, $specific_ids = [] ) {
            return $this->re_index_with_mongo_logging( $page, $specific_ids );
        }
    }
    
    return new Logged_Posts_Index( $index->post_type );
});
```

## Finding Race Conditions

### Query for Concurrent Access

```php
$analyzer = new Algolia_Mongo_Analyzer();

// Find all race conditions
$races = $analyzer->detect_race_conditions([
    'min_concurrent' => 2,     // At least 2 concurrent operations
    'time_window' => 10,       // Within 10 seconds
    'limit' => 100            // Top 100 affected items
]);

// Display results
foreach ($races['top_concurrent_items'] as $item) {
    echo "Item {$item['_id']} had {$item['occurrences']} concurrent accesses\n";
    echo "Involved sessions: " . implode(', ', $item['sessions']) . "\n";
    echo "Stages affected: " . implode(', ', $item['stages']) . "\n\n";
}
```

### MongoDB Shell Queries

Find items processed multiple times within 10 seconds:

```javascript
db.race_conditions.aggregate([
    {
        $match: {
            type: "concurrent_access",
            timestamp: { $gte: new Date(Date.now() - 7*24*60*60*1000) } // Last 7 days
        }
    },
    {
        $group: {
            _id: "$item_id",
            count: { $sum: 1 },
            sessions: { $addToSet: "$session_id" },
            timestamps: { $push: "$timestamp" }
        }
    },
    {
        $match: { count: { $gte: 3 } } // Items with 3+ concurrent operations
    },
    {
        $sort: { count: -1 }
    }
])
```

Find items that failed during specific stage:

```javascript
db.items.find({
    "timeline.stage": "generation",
    "errors": { $exists: true, $ne: [] }
}).limit(10)
```

## Analyzing Missing Items

### PHP Analysis

```php
// Get all published post IDs
$expected_ids = get_posts([
    'post_type' => 'product',
    'post_status' => 'publish',
    'numberposts' => -1,
    'fields' => 'ids'
]);

// Analyze what happened
$analyzer = new Algolia_Mongo_Analyzer();
$missing = $analyzer->find_missing_items($session_id, $expected_ids);

echo "Expected: {$missing['expected_count']} items\n";
echo "Processed: {$missing['processed_count']} items\n";
echo "Never seen: " . count($missing['missing']['never_seen']) . " items\n";

// Show where items stopped
foreach ($missing['missing']['by_stage'] as $stage) {
    echo "{$stage['_id']} stage: {$stage['count']} items stopped here\n";
}
```

### MongoDB Queries for Missing Items

Find items that started but didn't complete:

```javascript
db.items.find({
    session_id: "idx_xyz123",
    final_status: { $exists: false }
})
```

Track item journey:

```javascript
db.items.findOne(
    { item_id: 12345 },
    { timeline: 1, errors: 1, final_status: 1 }
)
```

## WP-CLI Commands

```bash
# Analyze race conditions
wp eval '
$analyzer = new Algolia_Mongo_Analyzer();
$races = $analyzer->detect_race_conditions();
print_r($races["summary"]);
'

# Check specific item history
wp eval '
$db = (new MongoDB\Client())->selectDatabase("wp_algolia_logs");
$item = $db->items->findOne(["item_id" => 12345]);
echo json_encode($item, JSON_PRETTY_PRINT);
'

# Export problematic items
wp eval '
$analyzer = new Algolia_Mongo_Analyzer();
$csv = $analyzer->export_problems_csv("idx_xyz123");
file_put_contents("problems.csv", $csv);
echo "Exported to problems.csv\n";
'
```

## Performance Considerations

1. **Buffered Writes**: Logger buffers 50 operations before writing
2. **TTL Indexes**: Logs auto-expire after 7 days
3. **Selective Logging**: Only logs key events, not every operation
4. **Async Option**: Can use MongoDB async driver for even better performance

## Monitoring Dashboard

Create a simple monitoring page:

```php
add_action('admin_menu', function() {
    add_menu_page(
        'Algolia Race Conditions',
        'Algolia Races',
        'manage_options',
        'algolia-races',
        function() {
            $analyzer = new Algolia_Mongo_Analyzer();
            $races = $analyzer->detect_race_conditions(['limit' => 20]);
            
            echo '<div class="wrap">';
            echo '<h1>Algolia Race Conditions</h1>';
            
            if (empty($races['top_concurrent_items'])) {
                echo '<p>No race conditions detected!</p>';
            } else {
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr>
                    <th>Item ID</th>
                    <th>Occurrences</th>
                    <th>Sessions</th>
                    <th>Time Range</th>
                </tr></thead><tbody>';
                
                foreach ($races['top_concurrent_items'] as $item) {
                    $time_range = human_time_diff(
                        $item['first_seen']->toDateTime()->getTimestamp(),
                        $item['last_seen']->toDateTime()->getTimestamp()
                    );
                    
                    echo "<tr>
                        <td>{$item['_id']}</td>
                        <td>{$item['occurrences']}</td>
                        <td>" . count($item['sessions']) . " sessions</td>
                        <td>{$time_range}</td>
                    </tr>";
                }
                
                echo '</tbody></table>';
            }
            
            echo '</div>';
        }
    );
});
```

## Cleanup

Remove old logs manually if needed:

```javascript
// MongoDB shell - remove logs older than 7 days
db.items.deleteMany({
    timestamp: { $lt: new Date(Date.now() - 7*24*60*60*1000) }
})

db.race_conditions.deleteMany({
    timestamp: { $lt: new Date(Date.now() - 7*24*60*60*1000) }
})
```

## Key Patterns to Look For

1. **Same item indexed multiple times within seconds** - Classic race condition
2. **Items missing from "retrieval" stage** - Database query issue
3. **High skip rate in "filtering"** - Check your should_index logic
4. **Failures in "generation"** - Memory or content issues
5. **Items without "submission"** - API or network problems

The structured MongoDB data makes it easy to query and analyze with any MongoDB client or visualization tool.