<?php
/**
 * Algolia_Admin_Page_Logs class file.
 *
 * @package WebDevStudios\WPSWA
 */

/**
 * Class Algolia_Admin_Page_Logs
 *
 * Admin page for viewing and analyzing indexing logs.
 */
class Algolia_Admin_Page_Logs {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private $slug = 'algolia-indexing-logs';

	/**
	 * Logger instance.
	 *
	 * @var Algolia_Index_Logger
	 */
	private $logger;

	/**
	 * Analyzer instance.
	 *
	 * @var Algolia_Log_Analyzer
	 */
	private $analyzer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'init_tools' ] );
	}

	/**
	 * Initialize logging tools.
	 */
	public function init_tools() {
		if ( ! class_exists( 'Algolia_Index_Logger' ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../class-algolia-index-logger.php';
		}
		if ( ! class_exists( 'Algolia_Log_Analyzer' ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../class-algolia-log-analyzer.php';
		}

		$this->logger = new Algolia_Index_Logger();
		$this->analyzer = new Algolia_Log_Analyzer();
		
		// Ensure table exists
		$this->logger->create_table();
	}

	/**
	 * Add admin page.
	 */
	public function add_page() {
		add_submenu_page(
			'algolia',
			__( 'Indexing Logs', 'wp-search-with-algolia' ),
			__( 'Indexing Logs', 'wp-search-with-algolia' ),
			'manage_options',
			$this->slug,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'sessions';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Algolia Indexing Logs', 'wp-search-with-algolia' ); ?></h1>
			
			<nav class="nav-tab-wrapper">
				<a href="?page=<?php echo esc_attr( $this->slug ); ?>&tab=sessions" 
				   class="nav-tab <?php echo $tab === 'sessions' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Sessions', 'wp-search-with-algolia' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->slug ); ?>&tab=analysis" 
				   class="nav-tab <?php echo $tab === 'analysis' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Analysis', 'wp-search-with-algolia' ); ?>
				</a>
			</nav>

			<div class="tab-content">
				<?php
				switch ( $tab ) {
					case 'sessions':
						$this->render_sessions_tab();
						break;
					case 'analysis':
						$this->render_analysis_tab();
						break;
				}
				?>
			</div>
		</div>

		<style>
			.algolia-logs-table { border-collapse: collapse; width: 100%; margin-top: 20px; }
			.algolia-logs-table th, .algolia-logs-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
			.algolia-logs-table th { background-color: #f2f2f2; }
			.algolia-logs-table tr:nth-child(even) { background-color: #f9f9f9; }
			.log-level-ERROR { color: #dc3232; font-weight: bold; }
			.log-level-INFO { color: #0073aa; }
			.log-level-DEBUG { color: #666; }
			.log-level-STATS { color: #46b450; font-weight: bold; }
			.algolia-stats-box { background: #fff; border: 1px solid #ddd; padding: 15px; margin: 10px 0; }
			.algolia-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
			.algolia-stat-item { text-align: center; }
			.algolia-stat-number { font-size: 2em; font-weight: bold; color: #0073aa; }
			.algolia-stat-label { color: #666; }
		</style>
		<?php
	}

	/**
	 * Render sessions tab.
	 */
	private function render_sessions_tab() {
		$sessions = $this->logger->get_recent_sessions( 20 );
		
		if ( isset( $_GET['session_id'] ) ) {
			$this->render_session_details( sanitize_text_field( $_GET['session_id'] ) );
			return;
		}
		?>
		<h2><?php esc_html_e( 'Recent Indexing Sessions', 'wp-search-with-algolia' ); ?></h2>
		
		<?php if ( empty( $sessions ) ) : ?>
			<p><?php esc_html_e( 'No indexing sessions found.', 'wp-search-with-algolia' ); ?></p>
		<?php else : ?>
			<table class="algolia-logs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Session ID', 'wp-search-with-algolia' ); ?></th>
						<th><?php esc_html_e( 'Start Time', 'wp-search-with-algolia' ); ?></th>
						<th><?php esc_html_e( 'End Time', 'wp-search-with-algolia' ); ?></th>
						<th><?php esc_html_e( 'Log Count', 'wp-search-with-algolia' ); ?></th>
						<th><?php esc_html_e( 'Errors', 'wp-search-with-algolia' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-search-with-algolia' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sessions as $session ) : ?>
						<tr>
							<td><code><?php echo esc_html( $session['session_id'] ); ?></code></td>
							<td><?php echo esc_html( $session['start_time'] ); ?></td>
							<td><?php echo esc_html( $session['end_time'] ); ?></td>
							<td><?php echo esc_html( $session['log_count'] ); ?></td>
							<td class="<?php echo $session['error_count'] > 0 ? 'log-level-ERROR' : ''; ?>">
								<?php echo esc_html( $session['error_count'] ); ?>
							</td>
							<td>
								<a href="?page=<?php echo esc_attr( $this->slug ); ?>&tab=sessions&session_id=<?php echo esc_attr( $session['session_id'] ); ?>" 
								   class="button button-small">
									<?php esc_html_e( 'View Details', 'wp-search-with-algolia' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render session details.
	 *
	 * @param string $session_id Session ID.
	 */
	private function render_session_details( $session_id ) {
		$analysis = $this->analyzer->analyze_session( $session_id );
		
		if ( isset( $analysis['error'] ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $analysis['error'] ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<h2>
			<?php esc_html_e( 'Session Analysis:', 'wp-search-with-algolia' ); ?>
			<code><?php echo esc_html( $session_id ); ?></code>
		</h2>
		
		<a href="?page=<?php echo esc_attr( $this->slug ); ?>&tab=sessions" class="button">
			← <?php esc_html_e( 'Back to Sessions', 'wp-search-with-algolia' ); ?>
		</a>

		<!-- Summary Stats -->
		<div class="algolia-stats-box">
			<h3><?php esc_html_e( 'Summary', 'wp-search-with-algolia' ); ?></h3>
			<div class="algolia-stats-grid">
				<?php
				$summary = $analysis['summary'];
				$stages = $analysis['stages'];
				?>
				<div class="algolia-stat-item">
					<div class="algolia-stat-number"><?php echo esc_html( count( $stages['retrieval']['items'] ?? [] ) ); ?></div>
					<div class="algolia-stat-label"><?php esc_html_e( 'Items Retrieved', 'wp-search-with-algolia' ); ?></div>
				</div>
				<div class="algolia-stat-item">
					<div class="algolia-stat-number"><?php echo esc_html( $stages['filtering']['passed'] ?? 0 ); ?></div>
					<div class="algolia-stat-label"><?php esc_html_e( 'Items Passed Filter', 'wp-search-with-algolia' ); ?></div>
				</div>
				<div class="algolia-stat-item">
					<div class="algolia-stat-number"><?php echo esc_html( $stages['filtering']['skipped'] ?? 0 ); ?></div>
					<div class="algolia-stat-label"><?php esc_html_e( 'Items Skipped', 'wp-search-with-algolia' ); ?></div>
				</div>
				<div class="algolia-stat-item">
					<div class="algolia-stat-number"><?php echo esc_html( $analysis['errors']['total_count'] ); ?></div>
					<div class="algolia-stat-label"><?php esc_html_e( 'Errors', 'wp-search-with-algolia' ); ?></div>
				</div>
			</div>
		</div>

		<!-- Stage Analysis -->
		<div class="algolia-stats-box">
			<h3><?php esc_html_e( 'Stage Analysis', 'wp-search-with-algolia' ); ?></h3>
			<table class="algolia-logs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Stage', 'wp-search-with-algolia' ); ?></th>
						<th><?php esc_html_e( 'Count', 'wp-search-with-algolia' ); ?></th>
						<th><?php esc_html_e( 'Details', 'wp-search-with-algolia' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stages as $stage_name => $stage_data ) : ?>
						<tr>
							<td><strong><?php echo esc_html( ucfirst( $stage_name ) ); ?></strong></td>
							<td><?php echo esc_html( $stage_data['count'] ); ?></td>
							<td>
								<?php
								switch ( $stage_name ) {
									case 'retrieval':
										echo sprintf(
											esc_html__( '%d unique items retrieved', 'wp-search-with-algolia' ),
											count( array_unique( $stage_data['items'] ?? [] ) )
										);
										break;
									case 'filtering':
										if ( ! empty( $stage_data['reasons'] ) ) {
											echo '<strong>' . esc_html__( 'Skip reasons:', 'wp-search-with-algolia' ) . '</strong><br>';
											foreach ( $stage_data['reasons'] as $reason => $count ) {
												echo esc_html( $reason ) . ': ' . esc_html( $count ) . '<br>';
											}
										}
										break;
									case 'sanitization':
										if ( $stage_data['records_dropped'] > 0 ) {
											echo '<span class="log-level-ERROR">';
											echo sprintf(
												esc_html__( '%d records dropped during sanitization', 'wp-search-with-algolia' ),
												$stage_data['records_dropped']
											);
											echo '</span>';
										}
										break;
									case 'submission':
										echo sprintf(
											esc_html__( 'Success: %d, Failed: %d', 'wp-search-with-algolia' ),
											$stage_data['success'],
											$stage_data['failed']
										);
										break;
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Errors -->
		<?php if ( ! empty( $analysis['errors']['details'] ) ) : ?>
			<div class="algolia-stats-box">
				<h3><?php esc_html_e( 'Errors', 'wp-search-with-algolia' ); ?></h3>
				<table class="algolia-logs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'wp-search-with-algolia' ); ?></th>
							<th><?php esc_html_e( 'Stage', 'wp-search-with-algolia' ); ?></th>
							<th><?php esc_html_e( 'Item ID', 'wp-search-with-algolia' ); ?></th>
							<th><?php esc_html_e( 'Error', 'wp-search-with-algolia' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $analysis['errors']['details'] as $error ) : ?>
							<tr>
								<td><?php echo esc_html( $error['timestamp'] ); ?></td>
								<td><?php echo esc_html( $error['stage'] ); ?></td>
								<td><?php echo esc_html( $error['item_id'] ?? '-' ); ?></td>
								<td class="log-level-ERROR"><?php echo esc_html( $error['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<!-- Raw Logs -->
		<details>
			<summary style="cursor: pointer; padding: 10px; background: #f0f0f0; margin-top: 20px;">
				<strong><?php esc_html_e( 'View Raw Logs', 'wp-search-with-algolia' ); ?></strong>
			</summary>
			<div style="max-height: 400px; overflow-y: auto; background: #f9f9f9; padding: 10px; margin-top: 10px;">
				<?php
				$logs = $this->logger->get_session_logs( $session_id );
				foreach ( $logs as $log ) {
					$metadata = json_decode( $log['metadata'], true );
					?>
					<div style="margin-bottom: 5px; font-family: monospace; font-size: 12px;">
						<span style="color: #666;">[<?php echo esc_html( $log['timestamp'] ); ?>]</span>
						<span class="log-level-<?php echo esc_attr( $log['level'] ); ?>">
							<?php echo esc_html( $log['level'] ); ?>
						</span>
						<span style="color: #0073aa;"><?php echo esc_html( $log['stage'] ); ?></span>
						- <?php echo esc_html( $log['message'] ); ?>
						<?php if ( $log['item_id'] ) : ?>
							<span style="color: #666;">(Item: <?php echo esc_html( $log['item_id'] ); ?>)</span>
						<?php endif; ?>
					</div>
					<?php
				}
				?>
			</div>
		</details>
		<?php
	}

	/**
	 * Render analysis tab.
	 */
	private function render_analysis_tab() {
		?>
		<h2><?php esc_html_e( 'Session Analysis Tools', 'wp-search-with-algolia' ); ?></h2>
		
		<div class="algolia-stats-box">
			<h3><?php esc_html_e( 'Find Missing Items', 'wp-search-with-algolia' ); ?></h3>
			<p><?php esc_html_e( 'Enter a session ID and comma-separated list of expected item IDs to find missing items.', 'wp-search-with-algolia' ); ?></p>
			
			<form method="post">
				<table class="form-table">
					<tr>
						<th><label for="session_id"><?php esc_html_e( 'Session ID', 'wp-search-with-algolia' ); ?></label></th>
						<td><input type="text" id="session_id" name="session_id" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="expected_ids"><?php esc_html_e( 'Expected IDs', 'wp-search-with-algolia' ); ?></label></th>
						<td>
							<textarea id="expected_ids" name="expected_ids" rows="3" cols="50" required 
									  placeholder="1,2,3,4,5"></textarea>
							<p class="description"><?php esc_html_e( 'Comma-separated list of item IDs', 'wp-search-with-algolia' ); ?></p>
						</td>
					</tr>
				</table>
				<?php wp_nonce_field( 'algolia_find_missing', 'algolia_nonce' ); ?>
				<p class="submit">
					<input type="submit" name="find_missing" class="button button-primary" 
						   value="<?php esc_attr_e( 'Find Missing Items', 'wp-search-with-algolia' ); ?>">
				</p>
			</form>
		</div>

		<?php
		if ( isset( $_POST['find_missing'] ) && wp_verify_nonce( $_POST['algolia_nonce'], 'algolia_find_missing' ) ) {
			$session_id = sanitize_text_field( $_POST['session_id'] );
			$expected_ids = array_map( 'intval', explode( ',', $_POST['expected_ids'] ) );
			
			$report = $this->analyzer->find_missing_items( $session_id, $expected_ids );
			
			if ( ! isset( $report['error'] ) ) {
				?>
				<div class="algolia-stats-box">
					<h3><?php esc_html_e( 'Missing Items Report', 'wp-search-with-algolia' ); ?></h3>
					
					<div class="algolia-stats-grid">
						<div class="algolia-stat-item">
							<div class="algolia-stat-number"><?php echo esc_html( $report['expected_count'] ); ?></div>
							<div class="algolia-stat-label"><?php esc_html_e( 'Expected', 'wp-search-with-algolia' ); ?></div>
						</div>
						<div class="algolia-stat-item">
							<div class="algolia-stat-number"><?php echo esc_html( $report['retrieved_count'] ); ?></div>
							<div class="algolia-stat-label"><?php esc_html_e( 'Retrieved', 'wp-search-with-algolia' ); ?></div>
						</div>
						<div class="algolia-stat-item">
							<div class="algolia-stat-number"><?php echo esc_html( $report['processed_count'] ); ?></div>
							<div class="algolia-stat-label"><?php esc_html_e( 'Processed', 'wp-search-with-algolia' ); ?></div>
						</div>
					</div>

					<?php if ( ! empty( $report['missing']['not_retrieved'] ) ) : ?>
						<h4><?php esc_html_e( 'Not Retrieved from Database:', 'wp-search-with-algolia' ); ?></h4>
						<p><code><?php echo esc_html( implode( ', ', $report['missing']['not_retrieved'] ) ); ?></code></p>
					<?php endif; ?>

					<?php if ( ! empty( $report['missing']['retrieved_not_processed'] ) ) : ?>
						<h4><?php esc_html_e( 'Retrieved but Not Processed:', 'wp-search-with-algolia' ); ?></h4>
						<p><code><?php echo esc_html( implode( ', ', $report['missing']['retrieved_not_processed'] ) ); ?></code></p>
					<?php endif; ?>

					<h4><?php esc_html_e( 'Item Status Breakdown:', 'wp-search-with-algolia' ); ?></h4>
					<ul>
						<?php foreach ( $report['item_status'] as $status => $count ) : ?>
							<li>
								<strong><?php echo esc_html( ucfirst( $status ) ); ?>:</strong> 
								<?php echo esc_html( $count ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php
			}
		}
		?>
		<?php
	}
}