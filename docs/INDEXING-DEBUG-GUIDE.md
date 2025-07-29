
# Algolia Indexing Debug Guide

This guide explains how to use the new logging system to debug missing items during indexing.

## Quick Start

### 1. Enable Logging in Your Index Class

Add the logging trait to your index class:

```php
// In class-algolia-posts-index.php or similar
class Algolia_Posts_Index extends Algolia_Index {
    use Algolia_Index_Logging;
    
    // ... rest of your class
}
```

### 2. Use Logged Methods

Replace standard methods with logged versions:

```php
// Instead of: $index->re_index($page);
$index->re_index_with_logging($page);

// Instead of: $index->sync($item);
$index->sync_with_logging($item);
```

### 3. Analyze Results

```php
// Create analyzer
$analyzer = new Algolia_Log_Analyzer();

// Get logger to find session ID
$logger = new Algolia_Index_Logger();
$recent_sessions = $logger->get_recent_sessions(5);
$latest_session_id = $recent_sessions[0]['session_id'];

// Analyze the session
$analysis = $analyzer->analyze_session($latest_session_id);

// Check for missing items
$expected_ids = [1, 2, 3, 4, 5]; // Your expected post IDs
$missing_report = $analyzer->find_missing_items($latest_session_id, $expected_ids);
```

## Understanding the Logs

### Log Stages

1. **retrieval** - Items fetched from database
2. **filtering** - Items checked for indexability
3. **generation** - Records created from items
4. **sanitization** - Records cleaned for JSON encoding
5. **submission** - Records sent to Algolia

### Finding Where Items Are Lost

Check the analysis results:

```php
// Stage analysis shows counts at each stage
print_r($analysis['stages']);

// Example output:
[
    'retrieval' => [
        'count' => 5,
        'items' => [1, 2, 3, 4, 5]
    ],
    'filtering' => [
        'count' => 5,
        'passed' => 3,
        'skipped' => 2,
        'reasons' => [
            'Post status is draft' => 1,
            'Post is password protected' => 1
        ]
    ],
    'generation' => [
        'count' => 3,
        'success' => 3,
        'failed' => 0
    ],
    'sanitization' => [
        'count' => 1,
        'records_dropped' => 2
    ],
    'submission' => [
        'count' => 1,
        'success' => 1,
        'failed' => 0
    ]
]
```

### Common Issues and Solutions

#### Items Not Retrieved
- Check your database query filters
- Verify pagination calculations
- Look for custom filters on `posts_where` or similar hooks

#### Items Filtered Out
- Check post status (only 'publish' by default)
- Check for password protection
- Review `algolia_should_index_post` filter implementations

#### Records Lost During Sanitization
- Look for non-UTF8 content
- Check for circular references in post meta
- Review memory limits for large posts

#### API Submission Failures
- Check Algolia API key permissions
- Review rate limits
- Look for network issues

## Example Debug Workflow

```php
// 1. Run indexing with logging
$index = new Algolia_Posts_Index('post');
$index->re_index_with_logging(1);

// 2. Get the session ID
$logger = new Algolia_Index_Logger();
$sessions = $logger->get_recent_sessions(1);
$session_id = $sessions[0]['session_id'];

// 3. Analyze the session
$analyzer = new Algolia_Log_Analyzer();
$analysis = $analyzer->analyze_session($session_id);

// 4. Check specific items
$problem_item_id = 123;
if (isset($analysis['items'][$problem_item_id])) {
    $item_journey = $analysis['items'][$problem_item_id];
    echo "Item $problem_item_id status: " . $item_journey['final_status'] . "\n";
    echo "Stages:\n";
    foreach ($item_journey['stages'] as $stage) {
        echo "  - {$stage['stage']}: {$stage['message']}\n";
    }
    if (!empty($item_journey['errors'])) {
        echo "Errors:\n";
        foreach ($item_journey['errors'] as $error) {
            echo "  - $error\n";
        }
    }
}

// 5. Compare with a previous successful run
$old_session_id = 'idx_abc123'; // From a known good run
$comparison = $analyzer->compare_sessions($old_session_id, $session_id);
print_r($comparison['items']['session1_only']); // Items that were indexed before but not now
```

## Viewing Raw Logs

To see all logs for a session:

```php
$logs = $logger->get_session_logs($session_id);
foreach ($logs as $log) {
    echo sprintf(
        "[%s] %s - %s: %s\n",
        $log['timestamp'],
        $log['stage'],
        $log['level'],
        $log['message']
    );
}
```

## Cleaning Up

The logs table can grow large. Clean old logs regularly:

```php
// Keep only last 7 days of logs
$logger->clean_old_logs(7);
```

## Integration with WP-CLI

You can create a WP-CLI command for easier debugging:

```php
WP_CLI::add_command('algolia debug-session', function($args) {
    $session_id = $args[0];
    $analyzer = new Algolia_Log_Analyzer();
    $analysis = $analyzer->analyze_session($session_id);
    WP_CLI::success("Analysis complete!");
    WP_CLI::log(print_r($analysis, true));
});
```

Then use: `wp algolia debug-session idx_xyz789`