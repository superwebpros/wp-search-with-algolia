<?php
/**
 * Algolia_Log_Analyzer class file.
 *
 * @package WebDevStudios\WPSWA
 */

/**
 * Class Algolia_Log_Analyzer
 *
 * Analyzes indexing logs to help identify where items are being lost.
 */
class Algolia_Log_Analyzer {

	/**
	 * Logger instance.
	 *
	 * @var Algolia_Index_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! class_exists( 'Algolia_Index_Logger' ) ) {
			require_once __DIR__ . '/class-algolia-index-logger.php';
		}
		
		$this->logger = new Algolia_Index_Logger();
	}

	/**
	 * Analyze a specific indexing session.
	 *
	 * @param string $session_id Session ID to analyze.
	 * @return array Analysis results.
	 */
	public function analyze_session( $session_id ) {
		$logs = $this->logger->get_session_logs( $session_id );
		
		if ( empty( $logs ) ) {
			return [
				'error' => 'No logs found for session ' . $session_id
			];
		}

		$analysis = [
			'session_id' => $session_id,
			'total_logs' => count( $logs ),
			'stages' => $this->analyze_stages( $logs ),
			'items' => $this->analyze_items( $logs ),
			'errors' => $this->analyze_errors( $logs ),
			'summary' => $this->get_session_summary( $logs ),
			'timeline' => $this->get_timeline( $logs ),
		];

		return $analysis;
	}

	/**
	 * Analyze processing stages.
	 *
	 * @param array $logs Session logs.
	 * @return array Stage analysis.
	 */
	private function analyze_stages( $logs ) {
		$stages = [
			'retrieval' => [ 'count' => 0, 'items' => [] ],
			'filtering' => [ 'count' => 0, 'passed' => 0, 'skipped' => 0, 'reasons' => [] ],
			'generation' => [ 'count' => 0, 'success' => 0, 'failed' => 0 ],
			'sanitization' => [ 'count' => 0, 'records_dropped' => 0 ],
			'submission' => [ 'count' => 0, 'success' => 0, 'failed' => 0 ],
		];

		foreach ( $logs as $log ) {
			$stage = $log['stage'];
			$metadata = json_decode( $log['metadata'], true );

			switch ( $stage ) {
				case 'retrieval':
					$stages['retrieval']['count']++;
					if ( isset( $metadata['item_ids'] ) ) {
						$stages['retrieval']['items'] = array_merge(
							$stages['retrieval']['items'],
							$metadata['item_ids']
						);
					}
					break;

				case 'filtering':
					$stages['filtering']['count']++;
					if ( isset( $metadata['should_index'] ) ) {
						if ( $metadata['should_index'] ) {
							$stages['filtering']['passed']++;
						} else {
							$stages['filtering']['skipped']++;
							$reason = $metadata['reason'] ?? 'Unknown';
							if ( ! isset( $stages['filtering']['reasons'][$reason] ) ) {
								$stages['filtering']['reasons'][$reason] = 0;
							}
							$stages['filtering']['reasons'][$reason]++;
						}
					}
					break;

				case 'generation':
					$stages['generation']['count']++;
					if ( $log['level'] === 'ERROR' ) {
						$stages['generation']['failed']++;
					} else {
						$stages['generation']['success']++;
					}
					break;

				case 'sanitization':
					$stages['sanitization']['count']++;
					if ( isset( $metadata['dropped_count'] ) ) {
						$stages['sanitization']['records_dropped'] += $metadata['dropped_count'];
					}
					break;

				case 'submission':
					$stages['submission']['count']++;
					if ( $log['level'] === 'ERROR' ) {
						$stages['submission']['failed']++;
					} else {
						$stages['submission']['success']++;
					}
					break;
			}
		}

		return $stages;
	}

	/**
	 * Analyze item processing.
	 *
	 * @param array $logs Session logs.
	 * @return array Item analysis.
	 */
	private function analyze_items( $logs ) {
		$items = [];

		foreach ( $logs as $log ) {
			if ( ! isset( $log['item_id'] ) || empty( $log['item_id'] ) ) {
				continue;
			}

			$item_id = $log['item_id'];
			if ( ! isset( $items[$item_id] ) ) {
				$items[$item_id] = [
					'id' => $item_id,
					'type' => $log['item_type'] ?? 'unknown',
					'stages' => [],
					'final_status' => 'unknown',
					'errors' => [],
				];
			}

			$items[$item_id]['stages'][] = [
				'stage' => $log['stage'],
				'level' => $log['level'],
				'message' => $log['message'],
				'timestamp' => $log['timestamp'],
			];

			if ( $log['level'] === 'ERROR' ) {
				$items[$item_id]['errors'][] = $log['message'];
			}

			// Update final status based on stage
			if ( $log['stage'] === 'submission' && $log['level'] === 'INFO' ) {
				$items[$item_id]['final_status'] = 'indexed';
			} elseif ( $log['stage'] === 'filtering' && strpos( $log['message'], 'skipped' ) !== false ) {
				$items[$item_id]['final_status'] = 'skipped';
			} elseif ( $log['level'] === 'ERROR' ) {
				$items[$item_id]['final_status'] = 'failed';
			}
		}

		return $items;
	}

	/**
	 * Analyze errors.
	 *
	 * @param array $logs Session logs.
	 * @return array Error analysis.
	 */
	private function analyze_errors( $logs ) {
		$errors = [
			'total_count' => 0,
			'by_stage' => [],
			'details' => [],
		];

		foreach ( $logs as $log ) {
			if ( $log['level'] !== 'ERROR' ) {
				continue;
			}

			$errors['total_count']++;

			$stage = $log['stage'];
			if ( ! isset( $errors['by_stage'][$stage] ) ) {
				$errors['by_stage'][$stage] = 0;
			}
			$errors['by_stage'][$stage]++;

			$errors['details'][] = [
				'stage' => $stage,
				'message' => $log['message'],
				'item_id' => $log['item_id'] ?? null,
				'timestamp' => $log['timestamp'],
			];
		}

		return $errors;
	}

	/**
	 * Get session summary.
	 *
	 * @param array $logs Session logs.
	 * @return array Summary data.
	 */
	private function get_session_summary( $logs ) {
		// Find summary log
		foreach ( $logs as $log ) {
			if ( $log['stage'] === 'summary' && $log['level'] === 'STATS' ) {
				$metadata = json_decode( $log['metadata'], true );
				return $metadata['stats'] ?? [];
			}
		}

		// Calculate summary if not found
		$summary = [
			'start_time' => $logs[0]['timestamp'] ?? null,
			'end_time' => end( $logs )['timestamp'] ?? null,
			'duration' => null,
		];

		if ( $summary['start_time'] && $summary['end_time'] ) {
			$start = new DateTime( $summary['start_time'] );
			$end = new DateTime( $summary['end_time'] );
			$interval = $start->diff( $end );
			$summary['duration'] = $interval->format( '%H:%I:%S' );
		}

		return $summary;
	}

	/**
	 * Get processing timeline.
	 *
	 * @param array $logs Session logs.
	 * @return array Timeline data.
	 */
	private function get_timeline( $logs ) {
		$timeline = [];

		foreach ( $logs as $log ) {
			if ( $log['stage'] === 'batch_start' ) {
				$metadata = json_decode( $log['metadata'], true );
				$timeline[] = [
					'type' => 'batch',
					'timestamp' => $log['timestamp'],
					'page' => $metadata['batch_page'] ?? 'unknown',
					'total_pages' => $metadata['max_pages'] ?? 'unknown',
				];
			} elseif ( $log['level'] === 'ERROR' ) {
				$timeline[] = [
					'type' => 'error',
					'timestamp' => $log['timestamp'],
					'stage' => $log['stage'],
					'message' => $log['message'],
				];
			}
		}

		return $timeline;
	}

	/**
	 * Generate a report for missing items.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $expected_ids Expected item IDs.
	 * @return array Missing items report.
	 */
	public function find_missing_items( $session_id, $expected_ids ) {
		$analysis = $this->analyze_session( $session_id );
		
		if ( isset( $analysis['error'] ) ) {
			return $analysis;
		}

		$processed_ids = array_keys( $analysis['items'] );
		$retrieved_ids = $analysis['stages']['retrieval']['items'] ?? [];
		
		$report = [
			'expected_count' => count( $expected_ids ),
			'retrieved_count' => count( array_unique( $retrieved_ids ) ),
			'processed_count' => count( $processed_ids ),
			'missing' => [
				'not_retrieved' => array_values( array_diff( $expected_ids, $retrieved_ids ) ),
				'retrieved_not_processed' => array_values( array_diff( $retrieved_ids, $processed_ids ) ),
			],
			'item_status' => $this->get_item_status_breakdown( $analysis['items'] ),
		];

		return $report;
	}

	/**
	 * Get item status breakdown.
	 *
	 * @param array $items Item analysis data.
	 * @return array Status breakdown.
	 */
	private function get_item_status_breakdown( $items ) {
		$breakdown = [
			'indexed' => 0,
			'skipped' => 0,
			'failed' => 0,
			'unknown' => 0,
		];

		foreach ( $items as $item ) {
			$status = $item['final_status'];
			if ( isset( $breakdown[$status] ) ) {
				$breakdown[$status]++;
			}
		}

		return $breakdown;
	}

	/**
	 * Compare two indexing sessions.
	 *
	 * @param string $session1_id First session ID.
	 * @param string $session2_id Second session ID.
	 * @return array Comparison results.
	 */
	public function compare_sessions( $session1_id, $session2_id ) {
		$analysis1 = $this->analyze_session( $session1_id );
		$analysis2 = $this->analyze_session( $session2_id );

		if ( isset( $analysis1['error'] ) || isset( $analysis2['error'] ) ) {
			return [
				'error' => 'One or both sessions could not be analyzed'
			];
		}

		$comparison = [
			'sessions' => [
				'session1' => $session1_id,
				'session2' => $session2_id,
			],
			'items' => [
				'session1_only' => array_diff( 
					array_keys( $analysis1['items'] ), 
					array_keys( $analysis2['items'] )
				),
				'session2_only' => array_diff(
					array_keys( $analysis2['items'] ),
					array_keys( $analysis1['items'] )
				),
				'both' => array_intersect(
					array_keys( $analysis1['items'] ),
					array_keys( $analysis2['items'] )
				),
			],
			'errors' => [
				'session1' => $analysis1['errors']['total_count'],
				'session2' => $analysis2['errors']['total_count'],
			],
			'stages' => $this->compare_stages( $analysis1['stages'], $analysis2['stages'] ),
		];

		return $comparison;
	}

	/**
	 * Compare stage data between sessions.
	 *
	 * @param array $stages1 First session stages.
	 * @param array $stages2 Second session stages.
	 * @return array Stage comparison.
	 */
	private function compare_stages( $stages1, $stages2 ) {
		$comparison = [];

		foreach ( $stages1 as $stage => $data1 ) {
			$data2 = $stages2[$stage] ?? [];
			
			$comparison[$stage] = [
				'session1' => $data1['count'] ?? 0,
				'session2' => $data2['count'] ?? 0,
				'difference' => ( $data1['count'] ?? 0 ) - ( $data2['count'] ?? 0 ),
			];
		}

		return $comparison;
	}
}