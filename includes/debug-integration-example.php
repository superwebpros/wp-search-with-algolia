<?php
/**
 * Example integration of the logging system into your Algolia indexing workflow.
 *
 * This file shows how to integrate the new logging capabilities into your existing code.
 */

// 1. QUICK INTEGRATION - Add to your functions.php or plugin file
add_action( 'init', function() {
	// Include required files
	require_once plugin_dir_path( __FILE__ ) . 'class-algolia-index-logger.php';
	require_once plugin_dir_path( __FILE__ ) . 'class-algolia-log-analyzer.php';
	require_once plugin_dir_path( __FILE__ ) . 'traits/trait-algolia-index-logging.php';
	
	// Initialize admin page
	if ( is_admin() ) {
		require_once plugin_dir_path( __FILE__ ) . 'admin/class-algolia-admin-page-logs.php';
		new Algolia_Admin_Page_Logs();
	}
});

// 2. MODIFY YOUR INDEX CLASSES - Add logging trait
add_action( 'algolia_posts_index_init', function( $index ) {
	// Dynamically add logging methods to the index
	if ( ! method_exists( $index, 're_index_with_logging' ) ) {
		// Create a wrapper class that adds logging
		$logged_index = new class( $index ) {
			use Algolia_Index_Logging;
			
			private $wrapped_index;
			
			public function __construct( $index ) {
				$this->wrapped_index = $index;
				$this->init_logger();
			}
			
			// Proxy all method calls to the wrapped index
			public function __call( $method, $args ) {
				// Use logged versions if available
				if ( $method === 're_index' ) {
					return $this->re_index_with_logging( ...$args );
				}
				if ( $method === 'sync' ) {
					return $this->sync_with_logging( ...$args );
				}
				
				// Otherwise, call the original method
				return call_user_func_array( [ $this->wrapped_index, $method ], $args );
			}
			
			// Proxy property access
			public function __get( $property ) {
				return $this->wrapped_index->$property;
			}
			
			// Required abstract methods from trait
			protected function get_id() {
				return $this->wrapped_index->get_id();
			}
			
			protected function should_index( $item ) {
				return $this->wrapped_index->should_index( $item );
			}
			
			protected function get_records( $item ) {
				return $this->wrapped_index->get_records( $item );
			}
			
			// ... implement other required methods by proxying to wrapped_index
		};
		
		return $logged_index;
	}
	
	return $index;
});

// 3. ADD WP-CLI COMMANDS for debugging
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'algolia analyze-session', function( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a session ID.' );
		}
		
		$analyzer = new Algolia_Log_Analyzer();
		$analysis = $analyzer->analyze_session( $args[0] );
		
		if ( isset( $analysis['error'] ) ) {
			WP_CLI::error( $analysis['error'] );
		}
		
		// Display summary
		WP_CLI::success( 'Session Analysis Complete!' );
		WP_CLI::log( '' );
		
		// Stage summary
		WP_CLI::log( 'STAGE SUMMARY:' );
		foreach ( $analysis['stages'] as $stage => $data ) {
			WP_CLI::log( sprintf( '  %s: %d operations', ucfirst( $stage ), $data['count'] ) );
		}
		
		// Error summary
		if ( $analysis['errors']['total_count'] > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( sprintf( 'Found %d errors:', $analysis['errors']['total_count'] ) );
			foreach ( $analysis['errors']['by_stage'] as $stage => $count ) {
				WP_CLI::log( sprintf( '  %s: %d errors', ucfirst( $stage ), $count ) );
			}
		}
		
		// Item status
		$status_breakdown = [];
		foreach ( $analysis['items'] as $item ) {
			$status = $item['final_status'];
			if ( ! isset( $status_breakdown[$status] ) ) {
				$status_breakdown[$status] = 0;
			}
			$status_breakdown[$status]++;
		}
		
		WP_CLI::log( '' );
		WP_CLI::log( 'ITEM STATUS:' );
		foreach ( $status_breakdown as $status => $count ) {
			WP_CLI::log( sprintf( '  %s: %d items', ucfirst( $status ), $count ) );
		}
		
		// Export to file if requested
		if ( isset( $assoc_args['export'] ) ) {
			$filename = $assoc_args['export'];
			file_put_contents( $filename, json_encode( $analysis, JSON_PRETTY_PRINT ) );
			WP_CLI::success( "Full analysis exported to: $filename" );
		}
	});
	
	WP_CLI::add_command( 'algolia find-missing', function( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a session ID.' );
		}
		
		// Get expected IDs from second argument or from post type
		$expected_ids = [];
		if ( ! empty( $args[1] ) ) {
			$expected_ids = array_map( 'intval', explode( ',', $args[1] ) );
		} elseif ( isset( $assoc_args['post-type'] ) ) {
			// Get all published post IDs of the given type
			$posts = get_posts( [
				'post_type' => $assoc_args['post-type'],
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields' => 'ids',
			]);
			$expected_ids = $posts;
		} else {
			WP_CLI::error( 'Please provide expected IDs or use --post-type flag.' );
		}
		
		$analyzer = new Algolia_Log_Analyzer();
		$report = $analyzer->find_missing_items( $args[0], $expected_ids );
		
		if ( isset( $report['error'] ) ) {
			WP_CLI::error( $report['error'] );
		}
		
		WP_CLI::log( sprintf( 'Expected items: %d', $report['expected_count'] ) );
		WP_CLI::log( sprintf( 'Retrieved items: %d', $report['retrieved_count'] ) );
		WP_CLI::log( sprintf( 'Processed items: %d', $report['processed_count'] ) );
		
		if ( ! empty( $report['missing']['not_retrieved'] ) ) {
			WP_CLI::warning( sprintf( 
				'%d items not retrieved from database: %s',
				count( $report['missing']['not_retrieved'] ),
				implode( ', ', array_slice( $report['missing']['not_retrieved'], 0, 10 ) ) .
				( count( $report['missing']['not_retrieved'] ) > 10 ? '...' : '' )
			));
		}
		
		if ( ! empty( $report['missing']['retrieved_not_processed'] ) ) {
			WP_CLI::warning( sprintf(
				'%d items retrieved but not processed: %s',
				count( $report['missing']['retrieved_not_processed'] ),
				implode( ', ', array_slice( $report['missing']['retrieved_not_processed'], 0, 10 ) ) .
				( count( $report['missing']['retrieved_not_processed'] ) > 10 ? '...' : '' )
			));
		}
	});
}

// 4. HOOK INTO INDEXING PROCESS to automatically enable logging
add_filter( 'algolia_should_override_index_method', function( $should_override, $method, $index ) {
	// Enable logging for re_index operations when debug mode is on
	if ( defined( 'ALGOLIA_DEBUG' ) && ALGOLIA_DEBUG ) {
		if ( in_array( $method, [ 're_index', 'sync' ] ) ) {
			return true;
		}
	}
	
	return $should_override;
}, 10, 3 );

// 5. ADD DEBUG TOOLBAR for admins
add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	
	$logger = new Algolia_Index_Logger();
	$recent_sessions = $logger->get_recent_sessions( 1 );
	
	if ( ! empty( $recent_sessions ) ) {
		$session = $recent_sessions[0];
		$title = sprintf( 
			'Algolia: Last Index %s (%d errors)',
			human_time_diff( strtotime( $session['start_time'] ) ),
			$session['error_count']
		);
		
		$wp_admin_bar->add_node( [
			'id' => 'algolia-debug',
			'title' => $title,
			'href' => admin_url( 'admin.php?page=algolia-indexing-logs&tab=sessions&session_id=' . $session['session_id'] ),
			'meta' => [
				'class' => $session['error_count'] > 0 ? 'algolia-has-errors' : '',
			],
		]);
	}
}, 100 );

// 6. AUTOMATIC LOG CLEANUP
add_action( 'algolia_daily_cleanup', function() {
	$logger = new Algolia_Index_Logger();
	$logger->clean_old_logs( 7 ); // Keep 7 days of logs
});

// Schedule the cleanup if not already scheduled
if ( ! wp_next_scheduled( 'algolia_daily_cleanup' ) ) {
	wp_schedule_event( time(), 'daily', 'algolia_daily_cleanup' );
}

// 7. USAGE EXAMPLES

/**
 * Example 1: Debug a specific re-indexing operation
 */
function debug_posts_indexing() {
	// Enable debug mode
	if ( ! defined( 'ALGOLIA_DEBUG' ) ) {
		define( 'ALGOLIA_DEBUG', true );
	}
	
	// Get the posts index
	$index = new Algolia_Posts_Index( 'post' );
	
	// Run re-indexing with logging
	$index->re_index_with_logging( 1 );
	
	// Get the logger
	$logger = new Algolia_Index_Logger();
	$sessions = $logger->get_recent_sessions( 1 );
	$session_id = $sessions[0]['session_id'];
	
	// Analyze results
	$analyzer = new Algolia_Log_Analyzer();
	$analysis = $analyzer->analyze_session( $session_id );
	
	// Check for missing items
	$all_post_ids = get_posts( [
		'post_type' => 'post',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields' => 'ids',
	]);
	
	$missing_report = $analyzer->find_missing_items( $session_id, $all_post_ids );
	
	return [
		'session_id' => $session_id,
		'analysis' => $analysis,
		'missing_report' => $missing_report,
	];
}

/**
 * Example 2: Monitor sync operations
 */
add_action( 'algolia_after_sync_item', function( $item, $index ) {
	if ( defined( 'ALGOLIA_DEBUG' ) && ALGOLIA_DEBUG ) {
		$logger = new Algolia_Index_Logger();
		
		// Log additional context
		$logger->log(
			'DEBUG',
			'sync_complete',
			sprintf( 'Item %d sync completed', $item->ID ),
			[
				'index_id' => $index->get_id(),
				'item_id' => $item->ID,
				'memory_usage' => memory_get_usage( true ),
				'peak_memory' => memory_get_peak_usage( true ),
			]
		);
	}
}, 10, 2 );