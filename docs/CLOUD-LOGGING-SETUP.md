# Cloud Logging Setup for Production Debugging

This guide shows how to use cloud logging services (no MongoDB required) to debug Algolia indexing issues in production.

## Option 1: Logtail (Recommended - Free tier available)

### 1. Sign up for Logtail
- Go to [Logtail.com](https://logtail.com)
- Create free account (1GB/month free)
- Get your source token

### 2. Configure WordPress
Add to `wp-config.php`:
```php
define( 'ALGOLIA_LOG_PROVIDER', 'logtail' );
define( 'ALGOLIA_LOG_TOKEN', 'your-logtail-source-token' );
```

### 3. Add Integration Code
Add to your theme's `functions.php`:
```php
// Load cloud logger
require_once 'path/to/class-algolia-cloud-logger.php';

// Initialize logging
add_action( 'init', function() {
    if ( ! defined( 'ALGOLIA_LOG_TOKEN' ) ) {
        return;
    }
    
    $logger = new Algolia_Cloud_Logger();
    
    // Hook into Algolia operations
    add_filter( 'algolia_should_index_post', function( $should_index, $post ) use ( $logger ) {
        $logger->track_item( $post->ID, 'filtering', [
            'should_index' => $should_index,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
        ]);
        return $should_index;
    }, 10, 2 );
    
    add_action( 'algolia_before_get_records', function( $item ) use ( $logger ) {
        if ( isset( $item->ID ) ) {
            $logger->track_item( $item->ID, 'generation_start', [
                'type' => $item->post_type,
            ]);
        }
    });
    
    add_action( 'algolia_re_indexed_items', function( $index_id ) use ( $logger ) {
        $logger->log_summary([
            'index_id' => $index_id,
            'duration' => time() - $_SERVER['REQUEST_TIME'],
            'memory_peak' => memory_get_peak_usage(true),
        ]);
    });
});
```

### 4. Analyze in Logtail Dashboard

Find race conditions:
```
level:warning AND message:"RACE CONDITION"
```

Track specific item:
```
item_id:12345 | sort by timestamp
```

Find missing items:
```
session_id:"idx_xyz123" | stats count by item_id
```

## Option 2: Papertrail (Syslog-based)

### 1. Sign up for Papertrail
- Go to [Papertrailapp.com](https://papertrailapp.com)
- Get your log endpoint (e.g., `logs.papertrailapp.com:12345`)

### 2. Configure WordPress
```php
define( 'ALGOLIA_LOG_PROVIDER', 'papertrail' );
define( 'ALGOLIA_LOG_ENDPOINT', 'logs.papertrailapp.com:12345' );
```

### 3. Search in Papertrail
```
# Find race conditions
algolia "RACE CONDITION"

# Track session
algolia idx_xyz123

# Find errors
algolia error
```

## Option 3: Custom HTTP Endpoint (Your own API)

### 1. Create Simple Logging API
```php
// Simple logging endpoint (log-receiver.php)
<?php
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $_SERVER['HTTP_X_SESSION_ID'] ?? 'unknown';

// Store in database or file
$log_file = "/var/log/algolia/{$session_id}.jsonl";
foreach ($data['logs'] as $log) {
    file_put_contents($log_file, json_encode($log) . "\n", FILE_APPEND | LOCK_EX);
}

echo json_encode(['status' => 'ok']);
```

### 2. Configure WordPress
```php
define( 'ALGOLIA_LOG_PROVIDER', 'custom' );
define( 'ALGOLIA_LOG_ENDPOINT', 'https://your-domain.com/log-receiver.php' );
```

## Minimal Integration Code

For the simplest setup, add this single file to mu-plugins:

```php
<?php
// wp-content/mu-plugins/algolia-cloud-debug.php

if ( ! defined( 'ALGOLIA_LOG_TOKEN' ) ) {
    return; // Not configured
}

require_once __DIR__ . '/class-algolia-cloud-logger.php';

class Algolia_Cloud_Debug {
    private static $logger;
    private static $item_count = 0;
    
    public static function init() {
        self::$logger = new Algolia_Cloud_Logger();
        
        // Track key operations
        add_filter( 'algolia_should_index_post', [__CLASS__, 'track_filtering'], 10, 2 );
        add_action( 'algolia_before_get_records', [__CLASS__, 'track_generation'] );
        add_filter( 'algolia_re_index_records', [__CLASS__, 'track_batch'], 10, 3 );
        add_action( 'algolia_re_indexed_items', [__CLASS__, 'finish_session'] );
    }
    
    public static function track_filtering( $should_index, $post ) {
        self::$logger->track_item( $post->ID, 'filtering', [
            'should_index' => $should_index,
            'reason' => $should_index ? null : 'filtered_out',
        ]);
        
        if ( $should_index ) {
            self::$item_count++;
        }
        
        return $should_index;
    }
    
    public static function track_generation( $item ) {
        if ( isset( $item->ID ) ) {
            self::$logger->track_item( $item->ID, 'generation', [
                'memory' => memory_get_usage( true ),
            ]);
        }
    }
    
    public static function track_batch( $records, $page, $index_id ) {
        // Log batch summary
        self::$logger->track_item( 0, 'batch', [
            'page' => $page,
            'records_count' => count( $records ),
            'index_id' => $index_id,
        ]);
        
        return $records;
    }
    
    public static function finish_session( $index_id ) {
        self::$logger->log_summary([
            'index_id' => $index_id,
            'total_items' => self::$item_count,
            'duration' => time() - $_SERVER['REQUEST_TIME'],
        ]);
    }
}

Algolia_Cloud_Debug::init();
```

## Finding Issues in Cloud Logs

### 1. Race Conditions
Look for:
- Multiple sessions accessing same item within seconds
- "RACE CONDITION" warnings
- Same item_id appearing multiple times rapidly

### 2. Missing Items
Steps:
1. Export all item_ids from a session
2. Compare with expected WordPress post IDs
3. Look for items that stopped at "filtering" stage

### 3. Performance Issues
Search for:
- High memory usage entries
- Long gaps between stages for same item
- Timeout errors

## Cost Considerations

- **Logtail**: 1GB/month free, then $0.20/GB
- **Papertrail**: 100MB/month free, then starts at $7/month
- **Custom**: Your hosting costs

For 28k products indexed daily:
- ~5 log entries per item = 140k logs/day
- ~50 bytes per log = 7MB/day
- ~210MB/month

This fits comfortably in most free tiers for debugging purposes.