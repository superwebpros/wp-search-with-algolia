<?php
/**
 * Cloud-based logger for production debugging without local infrastructure.
 * 
 * Supports multiple providers:
 * - Logtail (recommended)
 * - Papertrail
 * - Custom HTTP endpoint
 *
 * @package WebDevStudios\WPSWA
 */

class Algolia_Cloud_Logger {

	/**
	 * Provider configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Session ID for grouping logs.
	 *
	 * @var string
	 */
	private $session_id;

	/**
	 * Buffer for batch sending.
	 *
	 * @var array
	 */
	private $buffer = [];

	/**
	 * Buffer size before flush.
	 *
	 * @var int
	 */
	private $buffer_size = 25;

	/**
	 * Item tracking for analysis.
	 *
	 * @var array
	 */
	private $item_tracking = [];

	/**
	 * Constructor.
	 *
	 * @param array $config Provider configuration.
	 */
	public function __construct( $config = [] ) {
		$defaults = [
			'provider' => getenv( 'ALGOLIA_LOG_PROVIDER' ) ?: 'logtail',
			'token' => getenv( 'ALGOLIA_LOG_TOKEN' ) ?: '',
			'endpoint' => getenv( 'ALGOLIA_LOG_ENDPOINT' ) ?: '',
			'source' => get_site_url(),
		];
		
		$this->config = wp_parse_args( $config, $defaults );
		$this->session_id = uniqid( 'idx_', true );
		
		// Register shutdown function to flush remaining logs
		register_shutdown_function( [ $this, 'flush' ] );
	}

	/**
	 * Track an item through indexing.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $stage Processing stage.
	 * @param array  $data Stage data.
	 */
	public function track_item( $item_id, $stage, $data = [] ) {
		// Track locally for race detection
		$this->detect_race_condition( $item_id, $stage );
		
		// Prepare log entry
		$entry = [
			'timestamp' => gmdate( 'c' ),
			'level' => $data['error'] ?? false ? 'error' : 'info',
			'message' => "Item {$item_id} - Stage: {$stage}",
			'context' => array_merge( $data, [
				'session_id' => $this->session_id,
				'item_id' => $item_id,
				'stage' => $stage,
				'site' => $this->config['source'],
				'memory_usage' => memory_get_usage( true ),
			] ),
		];
		
		// Track item state
		if ( ! isset( $this->item_tracking[$item_id] ) ) {
			$this->item_tracking[$item_id] = [
				'first_seen' => microtime( true ),
				'stages' => [],
			];
		}
		
		$this->item_tracking[$item_id]['stages'][$stage] = microtime( true );
		$this->item_tracking[$item_id]['last_seen'] = microtime( true );
		
		// Add to buffer
		$this->buffer[] = $entry;
		
		if ( count( $this->buffer ) >= $this->buffer_size ) {
			$this->flush();
		}
	}

	/**
	 * Detect potential race conditions.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $stage Current stage.
	 */
	private function detect_race_condition( $item_id, $stage ) {
		$cache_key = 'algolia_item_' . $item_id;
		$recent = get_transient( $cache_key );
		
		if ( $recent && isset( $recent['session_id'] ) && $recent['session_id'] !== $this->session_id ) {
			// Different session accessed this item recently
			$time_diff = microtime( true ) - $recent['timestamp'];
			
			if ( $time_diff < 10 ) { // Within 10 seconds
				$this->buffer[] = [
					'timestamp' => gmdate( 'c' ),
					'level' => 'warning',
					'message' => "RACE CONDITION: Item {$item_id} accessed by multiple sessions",
					'context' => [
						'item_id' => $item_id,
						'current_session' => $this->session_id,
						'previous_session' => $recent['session_id'],
						'time_difference' => $time_diff,
						'current_stage' => $stage,
						'previous_stage' => $recent['stage'],
					],
				];
			}
		}
		
		// Update transient
		set_transient( $cache_key, [
			'session_id' => $this->session_id,
			'stage' => $stage,
			'timestamp' => microtime( true ),
		], 30 ); // 30 second TTL
	}

	/**
	 * Flush buffer to cloud provider.
	 */
	public function flush() {
		if ( empty( $this->buffer ) ) {
			return;
		}
		
		switch ( $this->config['provider'] ) {
			case 'logtail':
				$this->flush_to_logtail();
				break;
			case 'papertrail':
				$this->flush_to_papertrail();
				break;
			case 'custom':
				$this->flush_to_custom();
				break;
		}
		
		$this->buffer = [];
	}

	/**
	 * Send logs to Logtail.
	 */
	private function flush_to_logtail() {
		if ( empty( $this->config['token'] ) ) {
			return;
		}
		
		$endpoint = 'https://in.logtail.com/';
		
		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->config['token'],
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode( $this->buffer ),
			'timeout' => 5,
			'blocking' => false, // Don't wait for response
		] );
	}

	/**
	 * Send logs to Papertrail.
	 */
	private function flush_to_papertrail() {
		if ( empty( $this->config['endpoint'] ) ) {
			return;
		}
		
		// Parse endpoint (e.g., "logs.papertrailapp.com:12345")
		$parts = explode( ':', $this->config['endpoint'] );
		$host = $parts[0];
		$port = $parts[1] ?? 514;
		
		foreach ( $this->buffer as $entry ) {
			$message = sprintf(
				'<%d>%s %s algolia[%s]: %s %s',
				134, // facility.severity
				gmdate( 'M d H:i:s' ),
				gethostname(),
				$this->session_id,
				$entry['message'],
				wp_json_encode( $entry['context'] )
			);
			
			// Send via UDP
			$socket = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );
			socket_sendto( $socket, $message, strlen( $message ), 0, $host, $port );
			socket_close( $socket );
		}
	}

	/**
	 * Send logs to custom HTTP endpoint.
	 */
	private function flush_to_custom() {
		if ( empty( $this->config['endpoint'] ) ) {
			return;
		}
		
		wp_remote_post( $this->config['endpoint'], [
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Session-ID' => $this->session_id,
			],
			'body' => wp_json_encode( [
				'session_id' => $this->session_id,
				'logs' => $this->buffer,
				'source' => $this->config['source'],
			] ),
			'timeout' => 5,
			'blocking' => false,
		] );
	}

	/**
	 * Log session summary.
	 *
	 * @param array $summary Summary data.
	 */
	public function log_summary( $summary ) {
		// Calculate race conditions from tracking
		$race_conditions = [];
		foreach ( $this->item_tracking as $item_id => $data ) {
			$duration = $data['last_seen'] - $data['first_seen'];
			if ( $duration < 0.1 && count( $data['stages'] ) > 1 ) {
				// Multiple stages in < 100ms is suspicious
				$race_conditions[] = $item_id;
			}
		}
		
		$this->buffer[] = [
			'timestamp' => gmdate( 'c' ),
			'level' => 'info',
			'message' => 'Indexing session completed',
			'context' => array_merge( $summary, [
				'session_id' => $this->session_id,
				'total_items' => count( $this->item_tracking ),
				'potential_races' => count( $race_conditions ),
				'race_items' => array_slice( $race_conditions, 0, 10 ),
			] ),
		];
		
		$this->flush();
	}

	/**
	 * Get structured query for analysis.
	 *
	 * @return array Query strings for different providers.
	 */
	public function get_analysis_queries() {
		$queries = [];
		
		// Logtail queries
		$queries['logtail'] = [
			'find_races' => 'level:warning AND message:"RACE CONDITION"',
			'session_summary' => 'session_id:"' . $this->session_id . '" AND message:"completed"',
			'errors' => 'session_id:"' . $this->session_id . '" AND level:error',
			'item_journey' => 'item_id:12345 | sort by timestamp',
		];
		
		// Papertrail queries
		$queries['papertrail'] = [
			'find_races' => 'algolia "RACE CONDITION"',
			'session' => 'algolia ' . $this->session_id,
			'errors' => 'algolia ' . $this->session_id . ' error',
		];
		
		return $queries[$this->config['provider']] ?? [];
	}
}