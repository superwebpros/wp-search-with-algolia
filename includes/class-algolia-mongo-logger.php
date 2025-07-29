<?php
/**
 * MongoDB-based logger for production debugging of Algolia indexing.
 *
 * @package WebDevStudios\WPSWA
 */

class Algolia_Mongo_Logger {

	/**
	 * MongoDB client.
	 *
	 * @var MongoDB\Client
	 */
	private $client;

	/**
	 * Database instance.
	 *
	 * @var MongoDB\Database
	 */
	private $database;

	/**
	 * Collection for item tracking.
	 *
	 * @var MongoDB\Collection
	 */
	private $items_collection;

	/**
	 * Collection for race condition detection.
	 *
	 * @var MongoDB\Collection
	 */
	private $race_collection;

	/**
	 * Current session ID.
	 *
	 * @var string
	 */
	private $session_id;

	/**
	 * Batch buffer for bulk writes.
	 *
	 * @var array
	 */
	private $buffer = [];

	/**
	 * Buffer size before flush.
	 *
	 * @var int
	 */
	private $buffer_size = 50;

	/**
	 * Constructor.
	 *
	 * @param array $config MongoDB connection config.
	 */
	public function __construct( $config = [] ) {
		// Check for WordPress constants first
		$default_config = [
			'uri' => defined( 'MONGODB_URI' ) ? MONGODB_URI : ( getenv( 'MONGODB_URI' ) ?: 'mongodb://localhost:27017' ),
			'database' => defined( 'MONGODB_DATABASE' ) ? MONGODB_DATABASE : ( getenv( 'MONGODB_DATABASE' ) ?: 'wp_algolia_logs' ),
		];
		
		$config = wp_parse_args( $config, $default_config );
		
		// Set buffer size from config
		if ( defined( 'ALGOLIA_MONGO_BUFFER_SIZE' ) ) {
			$this->buffer_size = (int) ALGOLIA_MONGO_BUFFER_SIZE;
		}
		
		try {
			$this->client = new MongoDB\Client( $config['uri'] );
			$this->database = $this->client->selectDatabase( $config['database'] );
			
			$this->items_collection = $this->database->selectCollection( 'items' );
			$this->race_collection = $this->database->selectCollection( 'race_conditions' );
			
			$this->session_id = uniqid( 'idx_', true );
			$this->ensure_indexes();
		} catch ( Exception $e ) {
			error_log( 'Algolia MongoDB Logger: ' . $e->getMessage() );
		}
	}

	/**
	 * Ensure MongoDB indexes for performance.
	 */
	private function ensure_indexes() {
		try {
			// Get TTL days from config or default to 7
			$ttl_seconds = ( defined( 'ALGOLIA_MONGO_TTL_DAYS' ) ? ALGOLIA_MONGO_TTL_DAYS : 7 ) * 86400;
			
			// Items collection indexes
			$this->items_collection->createIndex( [ 'session_id' => 1 ] );
			$this->items_collection->createIndex( [ 'item_id' => 1, 'session_id' => 1 ] );
			$this->items_collection->createIndex( [ 'timestamp' => 1 ] );
			$this->items_collection->createIndex( [ 'final_status' => 1 ] );
			$this->items_collection->createIndex( 
				[ 'timestamp' => 1 ], 
				[ 'expireAfterSeconds' => $ttl_seconds ]
			);
			
			// Race condition detection indexes
			$this->race_collection->createIndex( [ 'item_id' => 1, 'timestamp' => -1 ] );
			$this->race_collection->createIndex( 
				[ 'timestamp' => 1 ], 
				[ 'expireAfterSeconds' => $ttl_seconds ]
			);
			
			// Sessions collection indexes
			$sessions_collection = $this->database->selectCollection( 'sessions' );
			$sessions_collection->createIndex( [ 'session_id' => 1 ] );
			$sessions_collection->createIndex( [ 'timestamp' => 1 ] );
			
		} catch ( Exception $e ) {
			error_log( 'Algolia MongoDB Logger - Index creation: ' . $e->getMessage() );
		}
	}

	/**
	 * Track an item through the indexing pipeline.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $stage Current stage.
	 * @param array  $data Stage-specific data.
	 */
	public function track_item( $item_id, $stage, $data = [] ) {
		if ( ! $this->client ) {
			return;
		}

		$timestamp = new MongoDB\BSON\UTCDateTime();
		
		// Check for race conditions
		$this->detect_race_condition( $item_id, $stage, $timestamp );
		
		// Update or create item document
		$update = [
			'$set' => [
				'session_id' => $this->session_id,
				'last_updated' => $timestamp,
				'stages.' . $stage => array_merge( $data, [
					'timestamp' => $timestamp,
					'microtime' => microtime( true ),
				] ),
			],
			'$setOnInsert' => [
				'item_id' => $item_id,
				'created' => $timestamp,
			],
			'$push' => [
				'timeline' => [
					'stage' => $stage,
					'timestamp' => $timestamp,
					'data' => $data,
				],
			],
		];
		
		// Handle stage-specific updates
		switch ( $stage ) {
			case 'retrieval':
				$update['$set']['item_type'] = $data['type'] ?? 'unknown';
				$update['$set']['batch_info'] = [
					'page' => $data['page'] ?? 0,
					'position' => $data['position'] ?? 0,
				];
				break;
				
			case 'filtering':
				$update['$set']['should_index'] = $data['should_index'] ?? false;
				if ( ! empty( $data['skip_reason'] ) ) {
					$update['$set']['skip_reason'] = $data['skip_reason'];
				}
				break;
				
			case 'generation':
				$update['$set']['records_count'] = $data['records_count'] ?? 0;
				if ( isset( $data['error'] ) ) {
					$update['$push']['errors'] = [
						'stage' => 'generation',
						'error' => $data['error'],
						'timestamp' => $timestamp,
					];
				}
				break;
				
			case 'submission':
				$update['$set']['final_status'] = $data['success'] ? 'indexed' : 'failed';
				if ( isset( $data['task_id'] ) ) {
					$update['$set']['algolia_task_id'] = $data['task_id'];
				}
				break;
		}
		
		// Buffer the update
		$this->buffer[] = [
			'updateOne' => [
				[ 'item_id' => $item_id, 'session_id' => $this->session_id ],
				$update,
				[ 'upsert' => true ],
			],
		];
		
		if ( count( $this->buffer ) >= $this->buffer_size ) {
			$this->flush();
		}
	}

	/**
	 * Detect potential race conditions.
	 *
	 * @param int                     $item_id Item ID.
	 * @param string                  $stage Current stage.
	 * @param MongoDB\BSON\UTCDateTime $timestamp Current timestamp.
	 */
	private function detect_race_condition( $item_id, $stage, $timestamp ) {
		// Look for recent operations on the same item
		$recent_ops = $this->race_collection->find(
			[
				'item_id' => $item_id,
				'timestamp' => [
					'$gte' => new MongoDB\BSON\UTCDateTime( ( time() - 10 ) * 1000 ), // Last 10 seconds
				],
			],
			[
				'sort' => [ 'timestamp' => -1 ],
				'limit' => 5,
			]
		)->toArray();
		
		if ( count( $recent_ops ) > 0 ) {
			// Log potential race condition
			$this->race_collection->insertOne( [
				'item_id' => $item_id,
				'stage' => $stage,
				'session_id' => $this->session_id,
				'timestamp' => $timestamp,
				'concurrent_sessions' => array_unique( array_column( $recent_ops, 'session_id' ) ),
				'operation_count' => count( $recent_ops ),
				'type' => 'concurrent_access',
			] );
		}
		
		// Record this operation for future race detection
		$this->race_collection->insertOne( [
			'item_id' => $item_id,
			'stage' => $stage,
			'session_id' => $this->session_id,
			'timestamp' => $timestamp,
		] );
	}

	/**
	 * Flush buffer to MongoDB.
	 */
	public function flush() {
		if ( empty( $this->buffer ) || ! $this->client ) {
			return;
		}
		
		try {
			$this->items_collection->bulkWrite( $this->buffer );
			$this->buffer = [];
		} catch ( Exception $e ) {
			error_log( 'Algolia MongoDB flush error: ' . $e->getMessage() );
		}
	}

	/**
	 * Log session summary.
	 *
	 * @param array $summary Summary data.
	 */
	public function log_summary( $summary ) {
		if ( ! $this->client ) {
			return;
		}
		
		$this->flush(); // Ensure all items are written
		
		// Calculate statistics
		$stats = $this->items_collection->aggregate( [
			[ '$match' => [ 'session_id' => $this->session_id ] ],
			[ '$group' => [
				'_id' => '$final_status',
				'count' => [ '$sum' => 1 ],
			] ],
		] )->toArray();
		
		$status_counts = [];
		foreach ( $stats as $stat ) {
			$status_counts[ $stat['_id'] ?? 'unknown' ] = $stat['count'];
		}
		
		// Store session summary
		$this->database->selectCollection( 'sessions' )->insertOne( [
			'session_id' => $this->session_id,
			'timestamp' => new MongoDB\BSON\UTCDateTime(),
			'duration_seconds' => $summary['duration'] ?? 0,
			'total_items' => array_sum( $status_counts ),
			'status_breakdown' => $status_counts,
			'memory_peak' => $summary['memory_peak'] ?? 0,
			'summary' => $summary,
		] );
	}

	/**
	 * Get analysis-friendly data structure.
	 *
	 * @param array $filters MongoDB query filters.
	 * @return array
	 */
	public function get_analysis_data( $filters = [] ) {
		if ( ! $this->client ) {
			return [];
		}
		
		$default_filters = [ 'session_id' => $this->session_id ];
		$filters = array_merge( $default_filters, $filters );
		
		// Get items with their complete journey
		$items = $this->items_collection->find( $filters )->toArray();
		
		// Get race conditions
		$races = $this->race_collection->find( [
			'type' => 'concurrent_access',
			'session_id' => $this->session_id,
		] )->toArray();
		
		return [
			'session_id' => $this->session_id,
			'items' => $items,
			'race_conditions' => $races,
			'summary' => $this->get_session_summary(),
		];
	}

	/**
	 * Get session summary.
	 *
	 * @return array
	 */
	private function get_session_summary() {
		$pipeline = [
			[ '$match' => [ 'session_id' => $this->session_id ] ],
			[ '$facet' => [
				'by_status' => [
					[ '$group' => [
						'_id' => '$final_status',
						'count' => [ '$sum' => 1 ],
					] ],
				],
				'by_skip_reason' => [
					[ '$match' => [ 'skip_reason' => [ '$exists' => true ] ] ],
					[ '$group' => [
						'_id' => '$skip_reason',
						'count' => [ '$sum' => 1 ],
					] ],
				],
				'failed_items' => [
					[ '$match' => [ 'errors' => [ '$exists' => true, '$ne' => [] ] ] ],
					[ '$project' => [
						'item_id' => 1,
						'errors' => 1,
					] ],
				],
			] ],
		];
		
		$result = $this->items_collection->aggregate( $pipeline )->toArray();
		return $result[0] ?? [];
	}

	/**
	 * Destructor - ensure buffer is flushed.
	 */
	public function __destruct() {
		$this->flush();
	}
}

/**
 * Integration wrapper for existing code.
 */
trait Algolia_Mongo_Logging {
	
	/**
	 * MongoDB logger instance.
	 *
	 * @var Algolia_Mongo_Logger
	 */
	protected $mongo_logger;
	
	/**
	 * Initialize MongoDB logger.
	 */
	protected function init_mongo_logger() {
		$this->mongo_logger = new Algolia_Mongo_Logger();
	}
	
	/**
	 * Override re_index with MongoDB logging.
	 */
	public function re_index_with_mongo_logging( $page, $specific_ids = [] ) {
		if ( ! $this->mongo_logger ) {
			$this->init_mongo_logger();
		}
		
		$page = (int) $page;
		$batch_size = (int) $this->get_re_index_batch_size();
		$items_count = $this->get_re_index_items_count();
		
		// Get items
		$items = $this->get_items( $page, $batch_size, $specific_ids );
		
		// Track each item
		$position = 0;
		foreach ( $items as $item ) {
			$item_id = $item->ID ?? 0;
			
			// Track retrieval
			$this->mongo_logger->track_item( $item_id, 'retrieval', [
				'type' => $item->post_type ?? 'unknown',
				'status' => $item->post_status ?? 'unknown',
				'page' => $page,
				'position' => $position++,
			] );
			
			// Track filtering
			$should_index = $this->should_index( $item );
			$this->mongo_logger->track_item( $item_id, 'filtering', [
				'should_index' => $should_index,
				'skip_reason' => ! $should_index ? $this->get_skip_reason( $item ) : null,
			] );
			
			if ( ! $should_index ) {
				$this->delete_item( $item );
				continue;
			}
			
			// Track generation
			try {
				$records = $this->get_records( $item );
				$this->mongo_logger->track_item( $item_id, 'generation', [
					'records_count' => count( $records ),
					'success' => true,
				] );
			} catch ( \Throwable $e ) {
				$this->mongo_logger->track_item( $item_id, 'generation', [
					'success' => false,
					'error' => $e->getMessage(),
				] );
				continue;
			}
			
			// Track submission (would happen in update_records)
			// ... rest of indexing logic
		}
		
		// Log summary at end
		if ( $page === ceil( $items_count / $batch_size ) ) {
			$this->mongo_logger->log_summary( [
				'duration' => time() - $_SERVER['REQUEST_TIME'],
				'memory_peak' => memory_get_peak_usage( true ),
			] );
		}
	}
}