<?php
/**
 * Quick MongoDB logging integration for Algolia indexing.
 * 
 * Add this to your theme's functions.php or as a mu-plugin to enable
 * production logging without modifying core plugin files.
 *
 * @package WebDevStudios\WPSWA
 */

// Only load if MongoDB extension is available
if ( ! extension_loaded( 'mongodb' ) ) {
	return;
}

// Load MongoDB logger class
require_once __DIR__ . '/class-algolia-mongo-logger.php';
require_once __DIR__ . '/class-algolia-mongo-analyzer.php';

/**
 * Hook into Algolia indexing to add MongoDB logging.
 */
class Algolia_Mongo_Integration {

	/**
	 * MongoDB logger instance.
	 *
	 * @var Algolia_Mongo_Logger
	 */
	private static $logger;

	/**
	 * Initialize integration.
	 */
	public static function init() {
		// Initialize logger
		self::$logger = new Algolia_Mongo_Logger();

		// Hook into indexing operations
		add_action( 'algolia_before_get_records', [ __CLASS__, 'before_get_records' ], 10, 1 );
		add_action( 'algolia_after_get_records', [ __CLASS__, 'after_get_records' ], 10, 1 );
		
		// Hook into re-indexing process
		add_filter( 'algolia_re_index_records', [ __CLASS__, 'track_reindex_batch' ], 10, 3 );
		add_filter( 'algolia_update_records', [ __CLASS__, 'track_update_records' ], 10, 3 );
		
		// Hook into sync operations
		add_filter( 'algolia_should_index_post', [ __CLASS__, 'track_should_index' ], 10, 2 );
		
		// Track deletions
		add_action( 'algolia_delete_item', [ __CLASS__, 'track_deletion' ], 10, 2 );
		
		// Track API submissions
		add_action( 'algolia_before_save_objects', [ __CLASS__, 'before_save_objects' ], 10, 2 );
		add_action( 'algolia_after_save_objects', [ __CLASS__, 'after_save_objects' ], 10, 3 );
		
		// Session management
		add_action( 'algolia_re_indexed_items', [ __CLASS__, 'end_indexing_session' ], 10, 1 );
		
		// Add admin menu for viewing logs
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
		
		// WP-CLI commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'algolia mongo-analyze', [ __CLASS__, 'cli_analyze' ] );
			WP_CLI::add_command( 'algolia mongo-races', [ __CLASS__, 'cli_races' ] );
		}
	}

	/**
	 * Track before getting records.
	 *
	 * @param mixed $item Item being processed.
	 */
	public static function before_get_records( $item ) {
		if ( ! is_object( $item ) || ! isset( $item->ID ) ) {
			return;
		}

		self::$logger->track_item( $item->ID, 'generation_start', [
			'type' => $item->post_type ?? 'unknown',
			'memory_before' => memory_get_usage( true ),
		] );
	}

	/**
	 * Track after getting records.
	 *
	 * @param mixed $item Item being processed.
	 */
	public static function after_get_records( $item ) {
		if ( ! is_object( $item ) || ! isset( $item->ID ) ) {
			return;
		}

		self::$logger->track_item( $item->ID, 'generation_end', [
			'memory_after' => memory_get_usage( true ),
		] );
	}

	/**
	 * Track re-index batch.
	 *
	 * @param array  $records Records being indexed.
	 * @param int    $page Current page.
	 * @param string $index_id Index ID.
	 * @return array
	 */
	public static function track_reindex_batch( $records, $page, $index_id ) {
		// Group records by item ID
		$items = [];
		foreach ( $records as $record ) {
			if ( isset( $record['post_id'] ) ) {
				$item_id = $record['post_id'];
				if ( ! isset( $items[$item_id] ) ) {
					$items[$item_id] = 0;
				}
				$items[$item_id]++;
			}
		}

		// Track each item
		foreach ( $items as $item_id => $count ) {
			self::$logger->track_item( $item_id, 'generation', [
				'records_count' => $count,
				'batch_page' => $page,
				'index_id' => $index_id,
				'success' => true,
			] );
		}

		return $records;
	}

	/**
	 * Track update records.
	 *
	 * @param array  $records Records being updated.
	 * @param object $item Item being updated.
	 * @param string $index_id Index ID.
	 * @return array
	 */
	public static function track_update_records( $records, $item, $index_id ) {
		if ( isset( $item->ID ) ) {
			self::$logger->track_item( $item->ID, 'update', [
				'records_count' => count( $records ),
				'index_id' => $index_id,
			] );
		}

		return $records;
	}

	/**
	 * Track should_index decision.
	 *
	 * @param bool    $should_index Whether to index.
	 * @param WP_Post $post Post being checked.
	 * @return bool
	 */
	public static function track_should_index( $should_index, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $should_index;
		}

		$skip_reason = null;
		if ( ! $should_index ) {
			if ( 'publish' !== $post->post_status ) {
				$skip_reason = "Post status: {$post->post_status}";
			} elseif ( ! empty( $post->post_password ) ) {
				$skip_reason = 'Password protected';
			} else {
				$skip_reason = 'Filtered out';
			}
		}

		self::$logger->track_item( $post->ID, 'filtering', [
			'should_index' => $should_index,
			'skip_reason' => $skip_reason,
			'post_type' => $post->post_type,
			'post_status' => $post->post_status,
		] );

		return $should_index;
	}

	/**
	 * Track item deletion.
	 *
	 * @param mixed  $item Item being deleted.
	 * @param string $index_id Index ID.
	 */
	public static function track_deletion( $item, $index_id ) {
		$item_id = is_object( $item ) && isset( $item->ID ) ? $item->ID : 0;
		
		self::$logger->track_item( $item_id, 'deletion', [
			'index_id' => $index_id,
			'reason' => 'should_not_index',
		] );
	}

	/**
	 * Track before saving objects.
	 *
	 * @param array  $objects Objects being saved.
	 * @param string $index_name Index name.
	 */
	public static function before_save_objects( $objects, $index_name ) {
		// Store count for tracking after save
		set_transient( 'algolia_save_count_' . $index_name, count( $objects ), 60 );
	}

	/**
	 * Track after saving objects.
	 *
	 * @param array  $response API response.
	 * @param array  $objects Objects that were saved.
	 * @param string $index_name Index name.
	 */
	public static function after_save_objects( $response, $objects, $index_name ) {
		// Track by item ID
		$items = [];
		foreach ( $objects as $object ) {
			if ( isset( $object['post_id'] ) ) {
				$items[$object['post_id']] = true;
			}
		}

		$success = ! empty( $response['taskID'] );
		
		foreach ( array_keys( $items ) as $item_id ) {
			self::$logger->track_item( $item_id, 'submission', [
				'success' => $success,
				'task_id' => $response['taskID'] ?? null,
				'index_name' => $index_name,
				'error' => $success ? null : 'API submission failed',
			] );
		}
	}

	/**
	 * End indexing session.
	 *
	 * @param string $index_id Index ID.
	 */
	public static function end_indexing_session( $index_id ) {
		self::$logger->log_summary( [
			'index_id' => $index_id,
			'duration' => time() - ( $_SERVER['REQUEST_TIME'] ?? time() ),
			'memory_peak' => memory_get_peak_usage( true ),
			'memory_current' => memory_get_usage( true ),
		] );
	}

	/**
	 * Add admin menu.
	 */
	public static function add_admin_menu() {
		add_submenu_page(
			'algolia',
			'MongoDB Logs',
			'MongoDB Logs',
			'manage_options',
			'algolia-mongo-logs',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	/**
	 * Render admin page.
	 */
	public static function render_admin_page() {
		$analyzer = new Algolia_Mongo_Analyzer();
		$races = $analyzer->detect_race_conditions( [ 'limit' => 50 ] );
		
		?>
		<div class="wrap">
			<h1>Algolia MongoDB Race Condition Analysis</h1>
			
			<?php if ( empty( $races['top_concurrent_items'] ) ) : ?>
				<div class="notice notice-success">
					<p>No race conditions detected in the last 7 days!</p>
				</div>
			<?php else : ?>
				<div class="notice notice-warning">
					<p>Found <?php echo count( $races['top_concurrent_items'] ); ?> items with potential race conditions.</p>
				</div>
				
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Item ID</th>
							<th>Concurrent Operations</th>
							<th>Sessions Involved</th>
							<th>Stages Affected</th>
							<th>Time Span</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $races['top_concurrent_items'] as $item ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $item['_id'] ); ?>
									<?php
									$post = get_post( $item['_id'] );
									if ( $post ) {
										echo '<br><small>' . esc_html( $post->post_title ) . '</small>';
									}
									?>
								</td>
								<td><?php echo esc_html( $item['occurrences'] ); ?></td>
								<td><?php echo count( $item['sessions'] ); ?> sessions</td>
								<td><?php echo esc_html( implode( ', ', $item['stages'] ) ); ?></td>
								<td>
									<?php
									if ( isset( $item['first_seen'] ) && isset( $item['last_seen'] ) ) {
										$span = human_time_diff(
											$item['first_seen']->toDateTime()->getTimestamp(),
											$item['last_seen']->toDateTime()->getTimestamp()
										);
										echo esc_html( $span );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				
				<h2>Temporal Patterns</h2>
				<p>Most race conditions occur during:</p>
				<ul>
					<?php foreach ( array_slice( $races['patterns']['temporal_patterns'], 0, 5 ) as $pattern ) : ?>
						<li>
							Hour <?php echo esc_html( $pattern['_id']['hour'] ); ?> on 
							day <?php echo esc_html( $pattern['_id']['day'] ); ?>: 
							<?php echo esc_html( $pattern['count'] ); ?> occurrences
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * WP-CLI analyze command.
	 *
	 * @param array $args Command arguments.
	 * @param array $assoc_args Command options.
	 */
	public static function cli_analyze( $args, $assoc_args ) {
		$session_id = $args[0] ?? null;
		
		if ( ! $session_id ) {
			WP_CLI::error( 'Please provide a session ID' );
		}
		
		$analyzer = new Algolia_Mongo_Analyzer();
		
		// Get expected IDs
		$post_type = $assoc_args['post-type'] ?? 'post';
		$expected_ids = get_posts( [
			'post_type' => $post_type,
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields' => 'ids',
		] );
		
		$missing = $analyzer->find_missing_items( $session_id, $expected_ids );
		
		WP_CLI::log( "Session: {$session_id}" );
		WP_CLI::log( "Expected items: {$missing['expected_count']}" );
		WP_CLI::log( "Processed items: {$missing['processed_count']}" );
		
		if ( ! empty( $missing['missing']['never_seen'] ) ) {
			WP_CLI::warning( 
				count( $missing['missing']['never_seen'] ) . ' items never retrieved: ' .
				implode( ', ', array_slice( $missing['missing']['never_seen'], 0, 10 ) )
			);
		}
	}

	/**
	 * WP-CLI race detection command.
	 *
	 * @param array $args Command arguments.
	 * @param array $assoc_args Command options.
	 */
	public static function cli_races( $args, $assoc_args ) {
		$analyzer = new Algolia_Mongo_Analyzer();
		$races = $analyzer->detect_race_conditions();
		
		WP_CLI::log( "Race Condition Analysis" );
		WP_CLI::log( "======================" );
		WP_CLI::log( "Total affected items: " . $races['summary']['total_items_affected'] );
		WP_CLI::log( "" );
		
		if ( ! empty( $races['top_concurrent_items'] ) ) {
			WP_CLI::log( "Top 10 affected items:" );
			
			$table_data = [];
			foreach ( array_slice( $races['top_concurrent_items'], 0, 10 ) as $item ) {
				$table_data[] = [
					'Item ID' => $item['_id'],
					'Operations' => $item['occurrences'],
					'Sessions' => count( $item['sessions'] ),
				];
			}
			
			WP_CLI\Utils\format_items( 'table', $table_data, [ 'Item ID', 'Operations', 'Sessions' ] );
		}
	}
}

// Initialize the integration
add_action( 'init', [ 'Algolia_Mongo_Integration', 'init' ] );