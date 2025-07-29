<?php
/**
 * MongoDB analyzer for finding indexing issues and race conditions.
 *
 * @package WebDevStudios\WPSWA
 */

class Algolia_Mongo_Analyzer {

	/**
	 * MongoDB database.
	 *
	 * @var MongoDB\Database
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @param MongoDB\Database $database MongoDB database instance.
	 */
	public function __construct( $database = null ) {
		if ( ! $database ) {
			$client = new MongoDB\Client( 
				getenv( 'MONGODB_URI' ) ?: 'mongodb://localhost:27017' 
			);
			$this->database = $client->selectDatabase( 
				getenv( 'MONGODB_DATABASE' ) ?: 'wp_algolia_logs' 
			);
		} else {
			$this->database = $database;
		}
	}

	/**
	 * Find items that went missing during indexing.
	 *
	 * @param string $session_id Session to analyze.
	 * @param array  $expected_ids Expected item IDs.
	 * @return array Analysis results.
	 */
	public function find_missing_items( $session_id, $expected_ids ) {
		$items = $this->database->selectCollection( 'items' );
		
		// Get all items from this session
		$processed = $items->find( [ 'session_id' => $session_id ] )->toArray();
		$processed_ids = array_column( $processed, 'item_id' );
		
		// Categorize by where they stopped
		$pipeline = [
			[ '$match' => [ 'session_id' => $session_id ] ],
			[ '$project' => [
				'item_id' => 1,
				'last_stage' => [ '$arrayElemAt' => [ '$timeline.stage', -1 ] ],
				'final_status' => 1,
				'skip_reason' => 1,
				'errors' => 1,
			] ],
			[ '$group' => [
				'_id' => '$last_stage',
				'items' => [ '$push' => '$item_id' ],
				'count' => [ '$sum' => 1 ],
			] ],
		];
		
		$stages = $items->aggregate( $pipeline )->toArray();
		
		return [
			'session_id' => $session_id,
			'expected_count' => count( $expected_ids ),
			'processed_count' => count( $processed_ids ),
			'missing' => [
				'never_seen' => array_values( array_diff( $expected_ids, $processed_ids ) ),
				'by_stage' => $stages,
			],
			'problematic_items' => $this->get_problematic_items( $session_id ),
		];
	}

	/**
	 * Detect race conditions in the logs.
	 *
	 * @param array $options Query options.
	 * @return array Race condition analysis.
	 */
	public function detect_race_conditions( $options = [] ) {
		$races = $this->database->selectCollection( 'race_conditions' );
		
		$defaults = [
			'min_concurrent' => 2,
			'time_window' => 10, // seconds
			'limit' => 100,
		];
		$options = array_merge( $defaults, $options );
		
		// Find items with concurrent access
		$pipeline = [
			[ '$match' => [ 'type' => 'concurrent_access' ] ],
			[ '$group' => [
				'_id' => '$item_id',
				'occurrences' => [ '$sum' => 1 ],
				'sessions' => [ '$addToSet' => '$session_id' ],
				'first_seen' => [ '$min' => '$timestamp' ],
				'last_seen' => [ '$max' => '$timestamp' ],
				'stages' => [ '$addToSet' => '$stage' ],
			] ],
			[ '$match' => [ 
				'occurrences' => [ '$gte' => $options['min_concurrent'] ] 
			] ],
			[ '$sort' => [ 'occurrences' => -1 ] ],
			[ '$limit' => $options['limit'] ],
		];
		
		$concurrent_items = $races->aggregate( $pipeline )->toArray();
		
		// Get detailed timeline for top offenders
		$detailed = [];
		foreach ( array_slice( $concurrent_items, 0, 10 ) as $item ) {
			$detailed[] = $this->get_race_condition_timeline( $item['_id'] );
		}
		
		return [
			'summary' => [
				'total_items_affected' => count( $concurrent_items ),
				'time_range' => $this->get_time_range(),
			],
			'top_concurrent_items' => $concurrent_items,
			'detailed_timelines' => $detailed,
			'patterns' => $this->analyze_race_patterns(),
		];
	}

	/**
	 * Get timeline of operations for an item showing race conditions.
	 *
	 * @param int $item_id Item ID.
	 * @return array Timeline data.
	 */
	private function get_race_condition_timeline( $item_id ) {
		$items = $this->database->selectCollection( 'items' );
		$races = $this->database->selectCollection( 'race_conditions' );
		
		// Get all operations on this item
		$operations = $items->find( 
			[ 'item_id' => $item_id ],
			[ 'sort' => [ 'created' => -1 ], 'limit' => 20 ]
		)->toArray();
		
		// Get race condition detections
		$race_detections = $races->find(
			[ 'item_id' => $item_id, 'type' => 'concurrent_access' ],
			[ 'sort' => [ 'timestamp' => -1 ] ]
		)->toArray();
		
		// Build combined timeline
		$timeline = [];
		
		foreach ( $operations as $op ) {
			foreach ( $op['timeline'] ?? [] as $event ) {
				$timeline[] = [
					'timestamp' => $event['timestamp']->toDateTime()->format( 'Y-m-d H:i:s.u' ),
					'session_id' => $op['session_id'],
					'stage' => $event['stage'],
					'type' => 'operation',
				];
			}
		}
		
		foreach ( $race_detections as $race ) {
			$timeline[] = [
				'timestamp' => $race['timestamp']->toDateTime()->format( 'Y-m-d H:i:s.u' ),
				'session_id' => $race['session_id'],
				'concurrent_sessions' => $race['concurrent_sessions'],
				'type' => 'race_detected',
			];
		}
		
		// Sort by timestamp
		usort( $timeline, function( $a, $b ) {
			return strcmp( $a['timestamp'], $b['timestamp'] );
		} );
		
		return [
			'item_id' => $item_id,
			'timeline' => $timeline,
			'session_overlap' => $this->calculate_session_overlap( $operations ),
		];
	}

	/**
	 * Analyze patterns in race conditions.
	 *
	 * @return array Pattern analysis.
	 */
	private function analyze_race_patterns() {
		$races = $this->database->selectCollection( 'race_conditions' );
		
		// Time-based patterns
		$hourly = $races->aggregate( [
			[ '$match' => [ 'type' => 'concurrent_access' ] ],
			[ '$project' => [
				'hour' => [ '$hour' => '$timestamp' ],
				'dayOfWeek' => [ '$dayOfWeek' => '$timestamp' ],
			] ],
			[ '$group' => [
				'_id' => [ 'hour' => '$hour', 'day' => '$dayOfWeek' ],
				'count' => [ '$sum' => 1 ],
			] ],
			[ '$sort' => [ 'count' => -1 ] ],
		] )->toArray();
		
		// Stage patterns
		$stages = $races->aggregate( [
			[ '$match' => [ 'type' => 'concurrent_access' ] ],
			[ '$group' => [
				'_id' => '$stage',
				'count' => [ '$sum' => 1 ],
			] ],
			[ '$sort' => [ 'count' => -1 ] ],
		] )->toArray();
		
		return [
			'temporal_patterns' => $hourly,
			'stage_patterns' => $stages,
		];
	}

	/**
	 * Get problematic items from a session.
	 *
	 * @param string $session_id Session ID.
	 * @return array Problematic items.
	 */
	private function get_problematic_items( $session_id ) {
		$items = $this->database->selectCollection( 'items' );
		
		return $items->find( [
			'session_id' => $session_id,
			'$or' => [
				[ 'errors' => [ '$exists' => true, '$ne' => [] ] ],
				[ 'final_status' => 'failed' ],
				[ 'skip_reason' => [ '$exists' => true ] ],
			],
		], [
			'limit' => 100,
			'projection' => [
				'item_id' => 1,
				'item_type' => 1,
				'final_status' => 1,
				'skip_reason' => 1,
				'errors' => 1,
				'timeline' => 1,
			],
		] )->toArray();
	}

	/**
	 * Get time range of logs.
	 *
	 * @return array Time range.
	 */
	private function get_time_range() {
		$items = $this->database->selectCollection( 'items' );
		
		$range = $items->aggregate( [
			[ '$group' => [
				'_id' => null,
				'first' => [ '$min' => '$created' ],
				'last' => [ '$max' => '$last_updated' ],
			] ],
		] )->toArray();
		
		if ( empty( $range ) ) {
			return [ 'first' => null, 'last' => null ];
		}
		
		return [
			'first' => $range[0]['first']->toDateTime()->format( 'Y-m-d H:i:s' ),
			'last' => $range[0]['last']->toDateTime()->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Calculate session overlap for race detection.
	 *
	 * @param array $operations Operations array.
	 * @return array Overlap data.
	 */
	private function calculate_session_overlap( $operations ) {
		$sessions = [];
		
		foreach ( $operations as $op ) {
			$session_id = $op['session_id'];
			if ( ! isset( $sessions[$session_id] ) ) {
				$sessions[$session_id] = [
					'start' => $op['created']->toDateTime(),
					'end' => $op['last_updated']->toDateTime(),
				];
			} else {
				$start = $op['created']->toDateTime();
				$end = $op['last_updated']->toDateTime();
				
				if ( $start < $sessions[$session_id]['start'] ) {
					$sessions[$session_id]['start'] = $start;
				}
				if ( $end > $sessions[$session_id]['end'] ) {
					$sessions[$session_id]['end'] = $end;
				}
			}
		}
		
		// Find overlaps
		$overlaps = [];
		$session_ids = array_keys( $sessions );
		
		for ( $i = 0; $i < count( $session_ids ) - 1; $i++ ) {
			for ( $j = $i + 1; $j < count( $session_ids ); $j++ ) {
				$s1 = $sessions[$session_ids[$i]];
				$s2 = $sessions[$session_ids[$j]];
				
				// Check if sessions overlap
				if ( $s1['start'] <= $s2['end'] && $s2['start'] <= $s1['end'] ) {
					$overlap_start = max( $s1['start'], $s2['start'] );
					$overlap_end = min( $s1['end'], $s2['end'] );
					$overlap_seconds = $overlap_end->getTimestamp() - $overlap_start->getTimestamp();
					
					$overlaps[] = [
						'sessions' => [ $session_ids[$i], $session_ids[$j] ],
						'overlap_seconds' => $overlap_seconds,
						'overlap_start' => $overlap_start->format( 'Y-m-d H:i:s' ),
						'overlap_end' => $overlap_end->format( 'Y-m-d H:i:s' ),
					];
				}
			}
		}
		
		return $overlaps;
	}

	/**
	 * Generate CSV export of problematic items.
	 *
	 * @param string $session_id Session ID.
	 * @return string CSV data.
	 */
	public function export_problems_csv( $session_id ) {
		$items = $this->get_problematic_items( $session_id );
		
		$csv = "item_id,type,final_status,skip_reason,error_count,last_stage\n";
		
		foreach ( $items as $item ) {
			$last_stage = end( $item['timeline'] )['stage'] ?? 'unknown';
			$error_count = count( $item['errors'] ?? [] );
			
			$csv .= sprintf(
				'%d,"%s","%s","%s",%d,"%s"' . "\n",
				$item['item_id'],
				$item['item_type'] ?? '',
				$item['final_status'] ?? '',
				$item['skip_reason'] ?? '',
				$error_count,
				$last_stage
			);
		}
		
		return $csv;
	}
}