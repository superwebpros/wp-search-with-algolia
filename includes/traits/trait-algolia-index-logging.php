<?php
/**
 * Trait Algolia_Index_Logging
 *
 * Adds logging capabilities to Algolia Index classes.
 *
 * @package WebDevStudios\WPSWA
 */

trait Algolia_Index_Logging {

	/**
	 * Logger instance.
	 *
	 * @var Algolia_Index_Logger
	 */
	protected $logger;

	/**
	 * Initialize logger.
	 */
	protected function init_logger() {
		if ( ! class_exists( 'Algolia_Index_Logger' ) ) {
			require_once dirname( __DIR__ ) . '/class-algolia-index-logger.php';
		}
		
		$this->logger = new Algolia_Index_Logger();
		$this->logger->create_table();
	}

	/**
	 * Get logger instance.
	 *
	 * @return Algolia_Index_Logger
	 */
	protected function get_logger() {
		if ( ! $this->logger ) {
			$this->init_logger();
		}
		return $this->logger;
	}

	/**
	 * Override re_index to add logging.
	 */
	public function re_index_with_logging( $page, $specific_ids = [] ) {
		$logger = $this->get_logger();
		$page = (int) $page;

		if ( $page < 1 ) {
			throw new InvalidArgumentException( 'Page should be superior to 0.' );
		}

		if ( 1 === $page ) {
			$this->create_index_if_not_existing();
		}

		$batch_size = (int) $this->get_re_index_batch_size();
		if ( $batch_size < 1 ) {
			throw new InvalidArgumentException( 'Re-index batch size can not be lower than 1.' );
		}

		$items_count = $this->get_re_index_items_count();
		$max_num_pages = (int) max( ceil( $items_count / $batch_size ), 1 );

		// Log batch info
		$logger->log(
			'INFO',
			'batch_start',
			"Starting batch {$page} of {$max_num_pages}",
			[
				'index_id' => $this->get_id(),
				'batch_page' => $page,
				'batch_size' => $batch_size,
				'total_items' => $items_count,
				'max_pages' => $max_num_pages,
			]
		);

		// Get items with logging
		$items = $this->get_items( $page, $batch_size, $specific_ids );
		$logger->log_retrieval( $this->get_id(), $page, $batch_size, $items );

		$records = array();
		$this->reindexing = true;

		foreach ( $items as $item ) {
			// Log filtering decision
			$should_index = $this->should_index( $item );
			
			if ( ! $should_index ) {
				$reason = $this->get_skip_reason( $item );
				$logger->log_filtering( $this->get_id(), $item, false, $reason );
				$this->delete_item( $item );
				continue;
			}

			$logger->log_filtering( $this->get_id(), $item, true );

			// Generate records with error handling
			try {
				do_action( 'algolia_before_get_records', $item );
				$item_records = $this->get_records( $item );
				$logger->log_generation( $this->get_id(), $item, $item_records );
				$records = array_merge( $records, $item_records );
				do_action( 'algolia_after_get_records', $item );
				
				$this->update_records( $item, $item_records );
			} catch ( \Throwable $throwable ) {
				$logger->log_generation( $this->get_id(), $item, [], $throwable->getMessage() );
			}
		}

		if ( ! empty( $records ) ) {
			$records = apply_filters(
				'algolia_re_index_records',
				$records,
				$page,
				$this->get_id()
			);

			$initial_count = count( $records );
			
			try {
				$sanitized_records = $this->sanitize_json_data( $records );
				$sanitized_count = count( $sanitized_records );
				
				// Log sanitization results
				if ( $initial_count !== $sanitized_count ) {
					$dropped_ids = $this->get_dropped_record_ids( $records, $sanitized_records );
					$logger->log_sanitization( $this->get_id(), $initial_count, $sanitized_count, $dropped_ids );
				}
			} catch ( \Throwable $throwable ) {
				$logger->log_sanitization( $this->get_id(), $initial_count, 0, [] );
				$logger->log(
					'ERROR',
					'sanitization',
					"Sanitization failed: " . $throwable->getMessage(),
					[
						'index_id' => $this->get_id(),
						'error' => $throwable->getMessage(),
					]
				);
			}
		}

		// Submit to Algolia with logging
		if ( ! empty( $sanitized_records ) ) {
			$index = $this->get_index();

			try {
				$response = $index->saveObjects( $sanitized_records );
				$logger->log_submission( $this->get_id(), count( $sanitized_records ), $response );
			} catch ( \Throwable $throwable ) {
				$logger->log_submission( $this->get_id(), count( $sanitized_records ), null, $throwable->getMessage() );
			}
		}

		$this->reindexing = false;

		if ( $page === $max_num_pages ) {
			$logger->log_summary( $this->get_id() );
			do_action( 'algolia_re_indexed_items', $this->get_id() );
		}
	}

	/**
	 * Override sync to add logging.
	 */
	public function sync_with_logging( $item ) {
		$logger = $this->get_logger();
		
		$this->assert_is_supported( $item );
		
		// Log sync start
		$logger->log(
			'INFO',
			'sync_start',
			"Starting sync for item",
			[
				'index_id' => $this->get_id(),
				'item_id' => $item->ID ?? 'unknown',
				'item_type' => $item->post_type ?? 'unknown',
			]
		);
		
		if ( $this->should_index( $item ) ) {
			$logger->log_filtering( $this->get_id(), $item, true );
			
			try {
				do_action( 'algolia_before_get_records', $item );
				$records = $this->get_records( $item );
				$logger->log_generation( $this->get_id(), $item, $records );
				do_action( 'algolia_after_get_records', $item );
				
				$this->update_records( $item, $records );
			} catch ( \Throwable $throwable ) {
				$logger->log_generation( $this->get_id(), $item, [], $throwable->getMessage() );
			}
			
			return;
		}

		$reason = $this->get_skip_reason( $item );
		$logger->log_filtering( $this->get_id(), $item, false, $reason );
		$this->delete_item( $item );
	}

	/**
	 * Override update_records to add logging.
	 */
	protected function update_records_with_logging( $item, array $records ) {
		$logger = $this->get_logger();
		
		if ( empty( $records ) ) {
			$logger->log(
				'INFO',
				'update',
				"No records to update, deleting item",
				[
					'index_id' => $this->get_id(),
					'item_id' => $item->ID ?? 'unknown',
				]
			);
			$this->delete_item( $item );
			return;
		}

		if ( true === $this->reindexing ) {
			return;
		}

		$records = apply_filters(
			'algolia_update_records',
			$records,
			$item,
			$this->get_id()
		);

		$initial_count = count( $records );
		
		try {
			$sanitized_records = $this->sanitize_json_data( $records );
			$sanitized_count = count( $sanitized_records );
			
			if ( $initial_count !== $sanitized_count ) {
				$dropped_ids = $this->get_dropped_record_ids( $records, $sanitized_records );
				$logger->log_sanitization( $this->get_id(), $initial_count, $sanitized_count, $dropped_ids );
			}
		} catch ( \Throwable $throwable ) {
			$logger->log(
				'ERROR',
				'sanitization',
				"Failed to sanitize records: " . $throwable->getMessage(),
				[
					'index_id' => $this->get_id(),
					'item_id' => $item->ID ?? 'unknown',
					'error' => $throwable->getMessage(),
				]
			);
		}

		if ( empty( $sanitized_records ) ) {
			return;
		}

		$index = $this->get_index();

		try {
			$response = $index->saveObjects( $sanitized_records );
			$logger->log_submission( $this->get_id(), count( $sanitized_records ), $response );
		} catch ( \Throwable $throwable ) {
			$logger->log_submission( $this->get_id(), count( $sanitized_records ), null, $throwable->getMessage() );
		}
	}

	/**
	 * Get reason why item was skipped.
	 *
	 * @param mixed $item The item.
	 * @return string
	 */
	protected function get_skip_reason( $item ) {
		if ( ! $item instanceof WP_Post ) {
			return 'Not a WP_Post object';
		}

		if ( 'publish' !== $item->post_status ) {
			return "Post status is '{$item->post_status}'";
		}

		if ( ! empty( $item->post_password ) ) {
			return 'Post is password protected';
		}

		return 'Filtered out by algolia_should_index_post';
	}

	/**
	 * Get IDs of records that were dropped during sanitization.
	 *
	 * @param array $original Original records.
	 * @param array $sanitized Sanitized records.
	 * @return array
	 */
	protected function get_dropped_record_ids( $original, $sanitized ) {
		$original_ids = array_column( $original, 'objectID' );
		$sanitized_ids = array_column( $sanitized, 'objectID' );
		
		return array_diff( $original_ids, $sanitized_ids );
	}
}