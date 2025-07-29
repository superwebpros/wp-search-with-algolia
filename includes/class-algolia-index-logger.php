<?php
/**
 * Algolia_Index_Logger class file.
 *
 * @package WebDevStudios\WPSWA
 */

/**
 * Class Algolia_Index_Logger
 *
 * Provides comprehensive logging for Algolia indexing operations to help debug item loss issues.
 */
class Algolia_Index_Logger {

	/**
	 * Log table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Current indexing session ID.
	 *
	 * @var string
	 */
	private $session_id;

	/**
	 * Statistics for current session.
	 *
	 * @var array
	 */
	private $stats = [
		'total_processed' => 0,
		'success_count' => 0,
		'skipped_count' => 0,
		'failed_count' => 0,
		'records_count' => 0,
		'api_calls' => 0,
		'items_by_stage' => [],
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'algolia_indexing_log';
		$this->session_id = uniqid( 'idx_', true );
	}

	/**
	 * Create log table if it doesn't exist.
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id varchar(50) NOT NULL,
			timestamp datetime DEFAULT CURRENT_TIMESTAMP,
			level varchar(20) NOT NULL,
			stage varchar(50) NOT NULL,
			index_id varchar(100),
			item_id bigint(20),
			item_type varchar(50),
			batch_page int,
			batch_size int,
			message text NOT NULL,
			metadata longtext,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY timestamp (timestamp),
			KEY stage (stage),
			KEY item_id (item_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log an entry.
	 *
	 * @param string $level    Log level (INFO, DEBUG, ERROR, STATS).
	 * @param string $stage    Processing stage.
	 * @param string $message  Log message.
	 * @param array  $metadata Additional metadata.
	 */
	public function log( $level, $stage, $message, $metadata = [] ) {
		global $wpdb;

		$data = [
			'session_id' => $this->session_id,
			'level' => $level,
			'stage' => $stage,
			'message' => $message,
		];

		// Extract common fields from metadata
		if ( isset( $metadata['index_id'] ) ) {
			$data['index_id'] = $metadata['index_id'];
		}
		if ( isset( $metadata['item_id'] ) ) {
			$data['item_id'] = $metadata['item_id'];
		}
		if ( isset( $metadata['item_type'] ) ) {
			$data['item_type'] = $metadata['item_type'];
		}
		if ( isset( $metadata['batch_page'] ) ) {
			$data['batch_page'] = $metadata['batch_page'];
		}
		if ( isset( $metadata['batch_size'] ) ) {
			$data['batch_size'] = $metadata['batch_size'];
		}

		// Store remaining metadata as JSON
		$data['metadata'] = wp_json_encode( $metadata );

		$wpdb->insert( $this->table_name, $data );

		// Also log to error_log if it's an error
		if ( 'ERROR' === $level ) {
			error_log( sprintf( '[Algolia Index] %s - %s: %s', $stage, $level, $message ) );
		}
	}

	/**
	 * Log retrieval stage.
	 *
	 * @param string $index_id Index ID.
	 * @param int    $page Current page.
	 * @param int    $batch_size Batch size.
	 * @param array  $items Retrieved items.
	 */
	public function log_retrieval( $index_id, $page, $batch_size, $items ) {
		$count = count( $items );
		$ids = array_map( function( $item ) {
			return $item->ID ?? 'unknown';
		}, $items );

		$this->log(
			'INFO',
			'retrieval',
			"Retrieved {$count} items for batch",
			[
				'index_id' => $index_id,
				'batch_page' => $page,
				'batch_size' => $batch_size,
				'retrieved_count' => $count,
				'item_ids' => $ids,
			]
		);

		$this->stats['total_processed'] += $count;
	}

	/**
	 * Log filtering decision.
	 *
	 * @param string  $index_id Index ID.
	 * @param WP_Post $item Post being filtered.
	 * @param bool    $should_index Whether item should be indexed.
	 * @param string  $reason Reason for decision.
	 */
	public function log_filtering( $index_id, $item, $should_index, $reason = '' ) {
		$this->log(
			'DEBUG',
			'filtering',
			$should_index ? "Item will be indexed" : "Item skipped: {$reason}",
			[
				'index_id' => $index_id,
				'item_id' => $item->ID,
				'item_type' => $item->post_type,
				'item_status' => $item->post_status,
				'should_index' => $should_index,
				'reason' => $reason,
			]
		);

		if ( ! $should_index ) {
			$this->stats['skipped_count']++;
		}
	}

	/**
	 * Log record generation.
	 *
	 * @param string  $index_id Index ID.
	 * @param WP_Post $item Post being processed.
	 * @param array   $records Generated records.
	 * @param string  $error Error message if failed.
	 */
	public function log_generation( $index_id, $item, $records = [], $error = null ) {
		if ( $error ) {
			$this->log(
				'ERROR',
				'generation',
				"Failed to generate records: {$error}",
				[
					'index_id' => $index_id,
					'item_id' => $item->ID,
					'item_type' => $item->post_type,
					'error' => $error,
				]
			);
			$this->stats['failed_count']++;
		} else {
			$count = count( $records );
			$this->log(
				'DEBUG',
				'generation',
				"Generated {$count} records",
				[
					'index_id' => $index_id,
					'item_id' => $item->ID,
					'item_type' => $item->post_type,
					'records_count' => $count,
					'object_ids' => array_column( $records, 'objectID' ),
				]
			);
			$this->stats['records_count'] += $count;
		}
	}

	/**
	 * Log sanitization results.
	 *
	 * @param string $index_id Index ID.
	 * @param int    $initial_count Initial record count.
	 * @param int    $sanitized_count Sanitized record count.
	 * @param array  $dropped_ids IDs of dropped records.
	 */
	public function log_sanitization( $index_id, $initial_count, $sanitized_count, $dropped_ids = [] ) {
		$dropped_count = $initial_count - $sanitized_count;
		
		$this->log(
			$dropped_count > 0 ? 'ERROR' : 'INFO',
			'sanitization',
			"Sanitization: {$initial_count} → {$sanitized_count} records" . 
			( $dropped_count > 0 ? " ({$dropped_count} dropped)" : "" ),
			[
				'index_id' => $index_id,
				'initial_count' => $initial_count,
				'sanitized_count' => $sanitized_count,
				'dropped_count' => $dropped_count,
				'dropped_ids' => $dropped_ids,
			]
		);
	}

	/**
	 * Log API submission.
	 *
	 * @param string $index_id Index ID.
	 * @param int    $count Record count.
	 * @param mixed  $response API response.
	 * @param string $error Error message if failed.
	 */
	public function log_submission( $index_id, $count, $response = null, $error = null ) {
		if ( $error ) {
			$this->log(
				'ERROR',
				'submission',
				"API submission failed: {$error}",
				[
					'index_id' => $index_id,
					'records_count' => $count,
					'error' => $error,
				]
			);
			$this->stats['failed_count'] += $count;
		} else {
			$this->log(
				'INFO',
				'submission',
				"Successfully submitted {$count} records to Algolia",
				[
					'index_id' => $index_id,
					'records_count' => $count,
					'task_id' => $response['taskID'] ?? 'unknown',
				]
			);
			$this->stats['success_count'] += $count;
			$this->stats['api_calls']++;
		}
	}

	/**
	 * Log session summary.
	 *
	 * @param string $index_id Index ID.
	 */
	public function log_summary( $index_id ) {
		$this->log(
			'STATS',
			'summary',
			"Indexing session complete",
			[
				'index_id' => $index_id,
				'session_id' => $this->session_id,
				'stats' => $this->stats,
			]
		);
	}

	/**
	 * Get session logs.
	 *
	 * @param string $session_id Session ID.
	 * @return array
	 */
	public function get_session_logs( $session_id = null ) {
		global $wpdb;

		if ( ! $session_id ) {
			$session_id = $this->session_id;
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE session_id = %s ORDER BY timestamp ASC",
				$session_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get recent sessions.
	 *
	 * @param int $limit Number of sessions to retrieve.
	 * @return array
	 */
	public function get_recent_sessions( $limit = 10 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					session_id,
					MIN(timestamp) as start_time,
					MAX(timestamp) as end_time,
					COUNT(*) as log_count,
					SUM(CASE WHEN level = 'ERROR' THEN 1 ELSE 0 END) as error_count
				FROM {$this->table_name}
				GROUP BY session_id
				ORDER BY MIN(timestamp) DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Clean old logs.
	 *
	 * @param int $days_to_keep Number of days to keep logs.
	 */
	public function clean_old_logs( $days_to_keep = 7 ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_to_keep
			)
		);
	}
}