<?php
/**
 * Plugin Name: York Staging to Live Sync(ACF Gutenberg)
 * Plugin URI: https://york.ie
 * Description: Sync posts, pages, and custom post types from staging to live environment. Supports ACF Gutenberg blocks.
 * Version: 1.0.0
 * Author: York IE
 * Author URI: https://york.ie
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: staging-to-live-sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('STLS_VERSION', '1.0.0');
define('STLS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STLS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class Staging_To_Live_Sync
{

	/**
	 * Instance of this class
	 */
	private static $instance = null;

	/**
	 * Get instance of this class
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct()
	{
		$this->init_hooks();
		$this->create_logs_table();
		$this->schedule_log_cleanup();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks()
	{
		// Activation hook
		register_activation_hook(__FILE__, array($this, 'activate'));

		// Deactivation hook
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));

		// Admin menu
		add_action('admin_menu', array($this, 'add_admin_menu'));

		// Admin settings
		add_action('admin_init', array($this, 'register_settings'));

		// Load pagebuilder-specific files
		add_action('plugins_loaded', array($this, 'load_pagebuilder_files'), 20);

		// Add row actions to all post types dynamically
		add_action('admin_init', array($this, 'add_sync_row_actions_to_all_post_types'));

		// AJAX handlers
		add_action('wp_ajax_stls_sync_post', array($this, 'ajax_sync_post'));
		add_action('wp_ajax_stls_generate_api_key', array($this, 'ajax_generate_api_key'));
		add_action('wp_ajax_stls_sync_files', array($this, 'ajax_sync_files'));
		add_action('wp_ajax_stls_load_directory', array($this, 'ajax_load_directory'));

		// Enqueue scripts
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

		// Schedule log cleanup
		add_action('stls_cleanup_old_logs', array($this, 'cleanup_old_logs'));
	}

	/**
	 * Load pagebuilder-specific files based on settings
	 */
	public function load_pagebuilder_files()
	{
		$pagebuilder = get_option('stls_pagebuilder', 'acf_gutenberg');

		$plugin_dir = STLS_PLUGIN_DIR;

		if ($pagebuilder === 'elementor') {
			$elementor_file = $plugin_dir . 'elementor-sync.php';
			if (file_exists($elementor_file)) {
				require_once $elementor_file;
			}
		} elseif ($pagebuilder === 'wpbakery') {
			$wpbakery_file = $plugin_dir . 'wpbakery-sync.php';
			if (file_exists($wpbakery_file)) {
				require_once $wpbakery_file;
			}
		}
		// If 'acf_gutenberg' or default, no additional file is needed (current functionality)
	}

	/**
	 * Plugin activation
	 */
	public function activate()
	{
		$this->create_logs_table();
		$this->schedule_log_cleanup();
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate()
	{
		// Clear scheduled event
		$timestamp = wp_next_scheduled('stls_cleanup_old_logs');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'stls_cleanup_old_logs');
		}
	}

	/**
	 * Create logs table
	 */
	private function create_logs_table()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'stls_sync_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			user_name varchar(255) NOT NULL,
			post_id bigint(20) NOT NULL,
			post_title varchar(255) NOT NULL,
			post_type varchar(20) NOT NULL,
			sync_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY post_id (post_id),
			KEY sync_time (sync_time)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	/**
	 * Schedule log cleanup
	 */
	private function schedule_log_cleanup()
	{
		if (!wp_next_scheduled('stls_cleanup_old_logs')) {
			wp_schedule_event(time(), 'daily', 'stls_cleanup_old_logs');
		}
	}

	/**
	 * Cleanup old logs (older than 30 days)
	 */
	public function cleanup_old_logs()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'stls_sync_logs';
		$days = 30;
		$date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

		$wpdb->query($wpdb->prepare(
			"DELETE FROM $table_name WHERE sync_time < %s",
			$date_threshold
		));
	}

	/**
	 * Log sync activity
	 */
	private function log_sync_activity($post_id, $file_sync_data = null)
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'stls_sync_logs';

		$user_id = get_current_user_id();
		$user = wp_get_current_user();
		$user_name = $user->display_name ? $user->display_name : $user->user_login;

		// Handle file sync logging
		if ($file_sync_data !== null && is_array($file_sync_data)) {
			$file_count = isset($file_sync_data['count']) ? intval($file_sync_data['count']) : 0;
			$success_count = isset($file_sync_data['success_count']) ? intval($file_sync_data['success_count']) : 0;
			$error_count = isset($file_sync_data['error_count']) ? intval($file_sync_data['error_count']) : 0;
			$file_names = isset($file_sync_data['files']) && is_array($file_sync_data['files']) ? $file_sync_data['files'] : array();

			// Build title with file information
			$title = sprintf(__('File Sync: %d file(s)', 'staging-to-live-sync'), $file_count);
			if ($success_count > 0 || $error_count > 0) {
				$title .= sprintf(' (%d success, %d failed)', $success_count, $error_count);
			}

			// If there are specific file names, add them (limit to first 5 to avoid very long titles)
			if (!empty($file_names) && count($file_names) <= 5) {
				$file_list = implode(', ', array_map('basename', $file_names));
				$title .= ' - ' . $file_list;
			} elseif (!empty($file_names)) {
				$file_list = implode(', ', array_map('basename', array_slice($file_names, 0, 5)));
				$title .= ' - ' . $file_list . '...';
			}

			$wpdb->insert(
				$table_name,
				array(
					'user_id' => $user_id,
					'user_name' => $user_name,
					'post_id' => 0,
					'post_title' => $title,
					'post_type' => 'file_sync',
					'sync_time' => current_time('mysql'),
				),
				array('%d', '%s', '%d', '%s', '%s', '%s')
			);

			return $wpdb->insert_id;
		}

		// Handle post sync logging (original functionality)
		$post = get_post($post_id);
		if (!$post) {
			return false;
		}

		$wpdb->insert(
			$table_name,
			array(
				'user_id' => $user_id,
				'user_name' => $user_name,
				'post_id' => $post_id,
				'post_title' => $post->post_title,
				'post_type' => $post->post_type,
				'sync_time' => current_time('mysql'),
			),
			array('%d', '%s', '%d', '%s', '%s', '%s')
		);

		return $wpdb->insert_id;
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu()
	{
		// Main menu item
		add_menu_page(
			'Staging to Live Sync',
			'STLS Sync',
			'manage_options',
			'staging-to-live-sync',
			array($this, 'render_options_page'),
			'dashicons-update',
			30
		);

		// Settings submenu (same as main page)
		add_submenu_page(
			'staging-to-live-sync',
			'Settings',
			'Settings',
			'manage_options',
			'staging-to-live-sync',
			array($this, 'render_options_page')
		);

		// Logs submenu
		add_submenu_page(
			'staging-to-live-sync',
			'Activity Logs',
			'Logs',
			'manage_options',
			'staging-to-live-sync-logs',
			array($this, 'render_logs_page')
		);

		// File Sync submenu
		add_submenu_page(
			'staging-to-live-sync',
			'File Sync',
			'File Sync',
			'manage_options',
			'staging-to-live-sync-files',
			array($this, 'render_file_sync_page')
		);

		// Elementor Logs submenu (only if Elementor is selected)
		$pagebuilder = get_option('stls_pagebuilder', 'acf_gutenberg');
		if ($pagebuilder === 'elementor' && function_exists('stls_elementor_get_logs')) {
			add_submenu_page(
				'staging-to-live-sync',
				'Elementor Sync Logs',
				'Elementor Logs',
				'manage_options',
				'staging-to-live-sync-elementor-logs',
				array($this, 'render_elementor_logs_page')
			);
		}
	}

	/**
	 * Register settings
	 */
	public function register_settings()
	{
		register_setting('stls_settings', 'stls_staging_url', array(
			'type' => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default' => ''
		));

		register_setting('stls_settings', 'stls_live_url', array(
			'type' => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default' => ''
		));

		register_setting('stls_settings', 'stls_staging_api_key', array(
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => ''
		));

		register_setting('stls_settings', 'stls_live_api_key', array(
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => ''
		));

		register_setting('stls_settings', 'stls_disabled_post_types', array(
			'type' => 'array',
			'sanitize_callback' => array($this, 'sanitize_disabled_post_types'),
			'default' => array()
		));

		register_setting('stls_settings', 'stls_selected_themes_plugins', array(
			'type' => 'array',
			'sanitize_callback' => array($this, 'sanitize_selected_themes_plugins'),
			'default' => array()
		));

		register_setting('stls_settings', 'stls_pagebuilder', array(
			'type' => 'string',
			'sanitize_callback' => array($this, 'sanitize_pagebuilder'),
			'default' => 'acf_gutenberg'
		));
	}

	/**
	 * Sanitize selected themes and plugins setting
	 */
	public function sanitize_selected_themes_plugins($value)
	{
		if (!is_array($value)) {
			return array();
		}

		// Get all valid themes and plugins
		$valid_items = array();

		// Get themes
		$themes = wp_get_themes();
		foreach ($themes as $theme_slug => $theme_obj) {
			$valid_items[] = 'theme_' . $theme_slug;
		}

		// Get plugins
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ($plugins as $plugin_file => $plugin_data) {
			$valid_items[] = 'plugin_' . $plugin_file;
		}

		// Only keep valid items
		return array_intersect($value, $valid_items);
	}

	/**
	 * Sanitize disabled post types setting
	 */
	public function sanitize_disabled_post_types($value)
	{
		if (!is_array($value)) {
			return array();
		}

		// Get all valid post types
		$valid_post_types = get_post_types(array('show_ui' => true), 'names');

		// Only keep valid post types
		return array_intersect($value, $valid_post_types);
	}

	/**
	 * Sanitize pagebuilder setting
	 */
	public function sanitize_pagebuilder($value)
	{
		$allowed_values = array('acf_gutenberg', 'elementor', 'wpbakery');
		if (in_array($value, $allowed_values, true)) {
			return $value;
		}
		return 'acf_gutenberg'; // Default to ACF Gutenberg
	}

	/**
	 * Check if a post type is disabled for syncing
	 */
	private function is_post_type_disabled($post_type)
	{
		$disabled_post_types = get_option('stls_disabled_post_types', array());
		return in_array($post_type, $disabled_post_types, true);
	}

	/**
	 * Render options page
	 */
	public function render_options_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// Check if settings were saved
		if (isset($_GET['settings-updated'])) {
			add_settings_error('stls_messages', 'stls_message', 'Settings Saved', 'updated');
		}

		settings_errors('stls_messages');
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('stls_settings');
				do_settings_sections('stls_settings');
				?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label
									for="stls_staging_url"><?php esc_html_e('Staging URL', 'staging-to-live-sync'); ?></label>
							</th>
							<td>
								<input type="url" id="stls_staging_url" name="stls_staging_url"
									value="<?php echo esc_attr(get_option('stls_staging_url')); ?>" class="regular-text"
									placeholder="https://staging.example.com" />
								<p class="description">
									<?php esc_html_e('Enter the full URL of your staging site (e.g., https://staging.example.com)', 'staging-to-live-sync'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="stls_live_url"><?php esc_html_e('Live URL', 'staging-to-live-sync'); ?></label>
							</th>
							<td>
								<input type="url" id="stls_live_url" name="stls_live_url"
									value="<?php echo esc_attr(get_option('stls_live_url')); ?>" class="regular-text"
									placeholder="https://example.com" />
								<p class="description">
									<?php esc_html_e('Enter the full URL of your live site (e.g., https://example.com)', 'staging-to-live-sync'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label
									for="stls_staging_api_key"><?php esc_html_e('Staging API Key (Optional)', 'staging-to-live-sync'); ?></label>
							</th>
							<td>
								<div class="stls-api-key-wrapper">
									<input type="text" id="stls_staging_api_key" name="stls_staging_api_key"
										value="<?php echo esc_attr(get_option('stls_staging_api_key')); ?>"
										class="regular-text stls-api-key-input" />
									<button type="button" class="button stls-generate-key-btn"
										data-target="stls_staging_api_key"
										data-nonce="<?php echo esc_attr(wp_create_nonce('stls_generate_key')); ?>">
										<?php esc_html_e('Generate', 'staging-to-live-sync'); ?>
									</button>
									<button type="button" class="button stls-copy-key-btn" data-target="stls_staging_api_key"
										title="<?php esc_attr_e('Copy to clipboard', 'staging-to-live-sync'); ?>">
										<?php esc_html_e('Copy', 'staging-to-live-sync'); ?>
									</button>
								</div>
								<p class="description">
									<?php esc_html_e('Optional: API key for authentication with staging site. Click "Generate" to create a secure random key.', 'staging-to-live-sync'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label
									for="stls_live_api_key"><?php esc_html_e('Live API Key (Optional)', 'staging-to-live-sync'); ?></label>
							</th>
							<td>
								<div class="stls-api-key-wrapper">
									<input type="text" id="stls_live_api_key" name="stls_live_api_key"
										value="<?php echo esc_attr(get_option('stls_live_api_key')); ?>"
										class="regular-text stls-api-key-input" />
									<button type="button" class="button stls-generate-key-btn" data-target="stls_live_api_key"
										data-nonce="<?php echo esc_attr(wp_create_nonce('stls_generate_key')); ?>">
										<?php esc_html_e('Generate', 'staging-to-live-sync'); ?>
									</button>
									<button type="button" class="button stls-copy-key-btn" data-target="stls_live_api_key"
										title="<?php esc_attr_e('Copy to clipboard', 'staging-to-live-sync'); ?>">
										<?php esc_html_e('Copy', 'staging-to-live-sync'); ?>
									</button>
								</div>
								<p class="description">
									<?php esc_html_e('Optional: API key for authentication with live site. Click "Generate" to create a secure random key. Use the same key on both staging and live sites.', 'staging-to-live-sync'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label><?php esc_html_e('Select Pagebuilder before using the plugin:', 'staging-to-live-sync'); ?></label>
							</th>
							<td>
								<?php
								$selected_pagebuilder = get_option('stls_pagebuilder', 'acf_gutenberg');
								?>
								<fieldset>
									<legend class="screen-reader-text">
										<span><?php esc_html_e('Select Pagebuilder', 'staging-to-live-sync'); ?></span>
									</legend>
									<label style="display: block; margin-bottom: 10px;">
										<input type="radio" name="stls_pagebuilder" value="acf_gutenberg" <?php checked($selected_pagebuilder, 'acf_gutenberg'); ?> />
										<strong><?php esc_html_e('ACF Gutenberg', 'staging-to-live-sync'); ?></strong>
									</label>
									<label style="display: block; margin-bottom: 10px;">
										<input type="radio" name="stls_pagebuilder" value="elementor" <?php checked($selected_pagebuilder, 'elementor'); ?> />
										<strong><?php esc_html_e('Elementor', 'staging-to-live-sync'); ?></strong>
									</label>
									<div
										style="margin-left: 24px; margin-bottom: 10px; padding: 8px 12px; background-color: #fff3cd; border-left: 4px solid #ffc107; color: #856404;">
										<p style="margin: 0; font-size: 13px;">
											<strong><?php esc_html_e('Important:', 'staging-to-live-sync'); ?></strong>
											<?php esc_html_e('Make sure the Elementor version is the same on both the staging and live websites. Additionally, if Elementor uses any add-ons, those should also be the same on both websites.', 'staging-to-live-sync'); ?>
										</p>
									</div>
									<label style="display: block; margin-bottom: 10px;">
										<input type="radio" name="stls_pagebuilder" value="wpbakery" <?php checked($selected_pagebuilder, 'wpbakery'); ?> />
										<strong><?php esc_html_e('WPBakery', 'staging-to-live-sync'); ?></strong>
									</label>
								</fieldset>
								<p class="description">
									<?php esc_html_e('Please select the page builder you are using before using the plugin.', 'staging-to-live-sync'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label><?php esc_html_e('Disable Sync for Post Types', 'staging-to-live-sync'); ?></label>
							</th>
							<td>
								<?php
								$disabled_post_types = get_option('stls_disabled_post_types', array());
								$all_post_types = get_post_types(array('show_ui' => true), 'objects');

								if (!empty($all_post_types)):
									?>
									<fieldset>
										<legend class="screen-reader-text">
											<span><?php esc_html_e('Disable Sync for Post Types', 'staging-to-live-sync'); ?></span>
										</legend>
										<?php foreach ($all_post_types as $post_type_name => $post_type_obj): ?>
											<label style="display: block; margin-bottom: 8px;">
												<input type="checkbox" name="stls_disabled_post_types[]"
													value="<?php echo esc_attr($post_type_name); ?>" <?php checked(in_array($post_type_name, $disabled_post_types, true)); ?> />
												<strong><?php echo esc_html($post_type_obj->label); ?></strong>
												<span
													style="color: #666; margin-left: 5px;">(<?php echo esc_html($post_type_name); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
									<p class="description">
										<?php esc_html_e('Select post types for which you want to disable the sync functionality. The sync button will not appear for selected post types.', 'staging-to-live-sync'); ?>
									</p>
									<?php
								else:
									?>
									<p><?php esc_html_e('No post types found.', 'staging-to-live-sync'); ?></p>
									<?php
								endif;
								?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label><?php esc_html_e('File Sync: Selected Themes & Plugins', 'staging-to-live-sync'); ?></label>
							</th>
							<td>
								<?php
								$selected_items = get_option('stls_selected_themes_plugins', array());

								// Get all themes
								$themes = wp_get_themes();

								// Get all plugins
								if (!function_exists('get_plugins')) {
									require_once ABSPATH . 'wp-admin/includes/plugin.php';
								}
								$plugins = get_plugins();

								// Show message if nothing is selected
								if (empty($selected_items)):
									?>
									<div class="notice notice-warning inline" style="padding: 12px; margin-bottom: 15px;">
										<p style="margin: 0; font-size: 14px;">
											<strong><?php esc_html_e('⚠️ No Selection', 'staging-to-live-sync'); ?></strong>
										</p>
										<p style="margin: 8px 0 0 0;">
											<?php esc_html_e('Please choose themes/plugins which you want to sync from setting page.', 'staging-to-live-sync'); ?>
										</p>
									</div>
									<?php
								endif;

								if (!empty($themes) || !empty($plugins)):
									?>
									<p class="description" style="margin-bottom: 15px;">
										<?php esc_html_e('Select which themes and plugins should be visible on the File Sync page. Only selected items will be loaded to avoid memory issues.', 'staging-to-live-sync'); ?>
									</p>

									<?php if (!empty($themes)): ?>
										<h3 style="margin-top: 20px; margin-bottom: 10px;">
											<?php esc_html_e('Themes', 'staging-to-live-sync'); ?>
										</h3>
										<fieldset
											style="border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 20px; max-height: 200px; overflow-y: auto;">
											<legend class="screen-reader-text">
												<span><?php esc_html_e('Themes', 'staging-to-live-sync'); ?></span>
											</legend>
											<label style="display: block; margin-bottom: 5px;">
												<input type="checkbox" class="stls-select-all-themes"
													onchange="stlsToggleAllThemes(this.checked)" />
												<strong><?php esc_html_e('Select All Themes', 'staging-to-live-sync'); ?></strong>
											</label>
											<hr style="margin: 10px 0;" />
											<?php foreach ($themes as $theme_slug => $theme_obj):
												$item_key = 'theme_' . $theme_slug;
												$is_selected = in_array($item_key, $selected_items, true);
												?>
												<label style="display: block; margin-bottom: 8px;">
													<input type="checkbox" name="stls_selected_themes_plugins[]"
														value="<?php echo esc_attr($item_key); ?>" class="stls-theme-checkbox" <?php checked($is_selected); ?> />
													<strong><?php echo esc_html($theme_obj->get('Name')); ?></strong>
													<span
														style="color: #666; margin-left: 5px;">(<?php echo esc_html($theme_slug); ?>)</span>
													<?php if ($theme_obj->get('Version')): ?>
														<span
															style="color: #666; margin-left: 5px;">v<?php echo esc_html($theme_obj->get('Version')); ?></span>
													<?php endif; ?>
												</label>
											<?php endforeach; ?>
										</fieldset>
									<?php endif; ?>

									<?php if (!empty($plugins)): ?>
										<h3 style="margin-top: 20px; margin-bottom: 10px;">
											<?php esc_html_e('Plugins', 'staging-to-live-sync'); ?>
										</h3>
										<fieldset
											style="border: 1px solid #ccd0d4; padding: 15px; max-height: 300px; overflow-y: auto;">
											<legend class="screen-reader-text">
												<span><?php esc_html_e('Plugins', 'staging-to-live-sync'); ?></span>
											</legend>
											<label style="display: block; margin-bottom: 5px;">
												<input type="checkbox" class="stls-select-all-plugins"
													onchange="stlsToggleAllPlugins(this.checked)" />
												<strong><?php esc_html_e('Select All Plugins', 'staging-to-live-sync'); ?></strong>
											</label>
											<hr style="margin: 10px 0;" />
											<?php foreach ($plugins as $plugin_file => $plugin_data):
												$item_key = 'plugin_' . $plugin_file;
												$is_selected = in_array($item_key, $selected_items, true);
												?>
												<label style="display: block; margin-bottom: 8px;">
													<input type="checkbox" name="stls_selected_themes_plugins[]"
														value="<?php echo esc_attr($item_key); ?>" class="stls-plugin-checkbox" <?php checked($is_selected); ?> />
													<strong><?php echo esc_html($plugin_data['Name']); ?></strong>
													<span
														style="color: #666; margin-left: 5px;">(<?php echo esc_html(dirname($plugin_file)); ?>)</span>
													<?php if (!empty($plugin_data['Version'])): ?>
														<span
															style="color: #666; margin-left: 5px;">v<?php echo esc_html($plugin_data['Version']); ?></span>
													<?php endif; ?>
												</label>
											<?php endforeach; ?>
										</fieldset>
									<?php endif; ?>

									<script>
										function stlsToggleAllThemes(checked) {
											var checkboxes = document.querySelectorAll('.stls-theme-checkbox');
											checkboxes.forEach(function (cb) {
												cb.checked = checked;
											});
										}

										function stlsToggleAllPlugins(checked) {
											var checkboxes = document.querySelectorAll('.stls-plugin-checkbox');
											checkboxes.forEach(function (cb) {
												cb.checked = checked;
											});
										}
									</script>
									<?php
								else:
									?>
									<p><?php esc_html_e('No themes or plugins found.', 'staging-to-live-sync'); ?></p>
									<?php
								endif;
								?>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button('Save Settings'); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render logs page
	 */
	public function render_logs_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// Handle clear logs action
		if (isset($_POST['stls_clear_logs']) && check_admin_referer('stls_clear_logs_action', 'stls_clear_logs_nonce')) {
			$this->clear_logs();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All logs cleared successfully.', 'staging-to-live-sync') . '</p></div>';
		}

		// Get logs from database
		$logs = $this->get_stls_logs();
		$total_logs = $this->get_total_logs_count();
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<div class="stls-logs-header" style="margin: 20px 0;">
				<form method="post" action="" style="display: inline-block;">
					<?php wp_nonce_field('stls_clear_logs_action', 'stls_clear_logs_nonce'); ?>
					<input type="hidden" name="stls_clear_logs" value="1" />
					<?php submit_button(__('Clear All Logs', 'staging-to-live-sync'), 'secondary', 'clear', false); ?>
				</form>
				<span style="margin-left: 15px; color: #666;">
					<?php
					echo esc_html(sprintf(__('Total log entries: %d (Logs older than 30 days are automatically deleted)', 'staging-to-live-sync'), $total_logs));
					?>
				</span>
			</div>

			<?php if (empty($logs)): ?>
				<div class="notice notice-info">
					<p><?php esc_html_e('No sync activity logs found. Logs will appear here when you sync posts.', 'staging-to-live-sync'); ?>
					</p>
				</div>
			<?php else: ?>
				<div class="stls-logs-container"
					style="background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 0; margin-top: 20px;">
					<div style="padding: 15px; background: #f5f5f5; border-bottom: 1px solid #ccd0d4;">
						<strong><?php echo esc_html(sprintf(__('Showing %d log entries', 'staging-to-live-sync'), count($logs))); ?></strong>
					</div>
					<div style="overflow-x: auto;">
						<table class="widefat fixed striped" style="margin: 0;">
							<thead>
								<tr>
									<th style="width: 60px;"><?php esc_html_e('ID', 'staging-to-live-sync'); ?></th>
									<th style="width: 150px;"><?php esc_html_e('User', 'staging-to-live-sync'); ?></th>
									<th style="width: 200px;"><?php esc_html_e('Page/Post Name', 'staging-to-live-sync'); ?></th>
									<th style="width: 100px;"><?php esc_html_e('Post Type', 'staging-to-live-sync'); ?></th>
									<th style="width: 180px;"><?php esc_html_e('Time', 'staging-to-live-sync'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($logs as $log): ?>
									<tr>
										<td><?php echo esc_html($log->id); ?></td>
										<td>
											<?php
											$user = get_user_by('id', $log->user_id);
											if ($user) {
												echo esc_html($log->user_name);
												echo '<br><small style="color: #666;">' . esc_html($user->user_email) . '</small>';
											} else {
												echo esc_html($log->user_name);
											}
											?>
										</td>
										<td>
											<?php
											$edit_link = get_edit_post_link($log->post_id);
											if ($edit_link) {
												echo '<a href="' . esc_url($edit_link) . '">' . esc_html($log->post_title) . '</a>';
											} else {
												echo esc_html($log->post_title);
											}
											?>
										</td>
										<td>
											<?php
											$post_type_obj = get_post_type_object($log->post_type);
											if ($post_type_obj) {
												echo esc_html($post_type_obj->labels->singular_name);
											} else {
												echo esc_html($log->post_type);
											}
											?>
										</td>
										<td>
											<?php
											$sync_time = strtotime($log->sync_time);
											echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $sync_time));
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render file sync page
	 */
	public function render_file_sync_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// Get wp-content directory
		$wp_content_dir = WP_CONTENT_DIR;
		$wp_content_url = content_url();

		// Increase memory limit temporarily for file tree building
		$original_memory_limit = ini_get('memory_limit');
		@ini_set('memory_limit', '512M');

		// Build file tree with error handling - Only directories initially (lazy loading)
		$file_tree = array();
		$tree_error = false;

		// Get selected themes and plugins
		$selected_items = get_option('stls_selected_themes_plugins', array());
		$file_tree = array();

		// Check if any themes/plugins are selected
		if (empty($selected_items)) {
			$tree_error = 'no_selection';
		} else {
			try {
				// Only show selected directories
				$file_tree = $this->build_file_tree_selected($wp_content_dir, $selected_items);
			} catch (Exception $e) {
				$tree_error = $e->getMessage();
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS File Tree Error: ' . $tree_error);
				}
			} catch (Error $e) {
				$tree_error = $e->getMessage();
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS File Tree Fatal Error: ' . $tree_error);
				}
			}
		}

		// Restore original memory limit
		if ($original_memory_limit) {
			@ini_set('memory_limit', $original_memory_limit);
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<div class="stls-file-sync-header" style="margin: 20px 0;">
				<?php if ($tree_error === 'no_selection'): ?>
					<div class="notice notice-warning inline" style="padding: 15px; margin-bottom: 20px;">
						<p style="margin: 0; font-size: 14px;">
							<strong
								style="font-size: 16px;"><?php esc_html_e('⚠️ No Themes or Plugins Selected', 'staging-to-live-sync'); ?></strong>
						</p>
						<p style="margin: 10px 0 0 0;">
							<?php esc_html_e('Please select which themes and plugins you want to sync from the settings page. This helps prevent memory issues by only loading selected files.', 'staging-to-live-sync'); ?>
						</p>
						<p style="margin: 15px 0 0 0;">
							<a href="<?php echo esc_url(admin_url('admin.php?page=staging-to-live-sync')); ?>"
								class="button button-primary">
								<?php esc_html_e('Go to Settings', 'staging-to-live-sync'); ?>
							</a>
						</p>
					</div>
				<?php elseif ($tree_error && $tree_error !== 'no_selection'): ?>
					<div class="notice notice-warning inline">
						<p>
							<strong><?php esc_html_e('Warning:', 'staging-to-live-sync'); ?></strong>
							<?php esc_html_e('The file tree could not be fully loaded due to memory constraints. Large directories may not be displayed. Use the search function to find specific files.', 'staging-to-live-sync'); ?>
						</p>
					</div>
				<?php else: ?>
					<?php
					$selected_items = get_option('stls_selected_themes_plugins', array());
					if (!empty($selected_items)):
						?>
						<div class="notice notice-info inline">
							<p>
								<strong><?php esc_html_e('Filtered View:', 'staging-to-live-sync'); ?></strong>
								<?php esc_html_e('Only selected themes and plugins are shown. To change this, go to', 'staging-to-live-sync'); ?>
								<a
									href="<?php echo esc_url(admin_url('admin.php?page=staging-to-live-sync')); ?>"><?php esc_html_e('Settings', 'staging-to-live-sync'); ?></a>.
							</p>
						</div>
					<?php endif; ?>
					<?php if ($tree_error !== 'no_selection'): ?>
						<p class="description">
							<?php esc_html_e('Browse files in the wp-content directory. Only folders are shown initially - click on a folder to load its contents (files and subfolders). This prevents memory issues with large directories.', 'staging-to-live-sync'); ?>
						</p>
						<p>
							<strong><?php esc_html_e('Directory:', 'staging-to-live-sync'); ?></strong>
							<code><?php echo esc_html($wp_content_dir); ?></code>
						</p>

						<div style="margin-top: 15px; margin-bottom: 15px;">
							<label for="stls-file-search" style="display: block; margin-bottom: 5px; font-weight: 600;">
								<?php esc_html_e('Search Files:', 'staging-to-live-sync'); ?>
							</label>
							<input type="text" id="stls-file-search" class="regular-text"
								placeholder="<?php esc_attr_e('Enter file or folder name to search...', 'staging-to-live-sync'); ?>"
								style="width: 400px; max-width: 100%;" onkeyup="stlsSearchFiles(this.value)" />
							<button type="button" class="button"
								onclick="document.getElementById('stls-file-search').value = ''; stlsSearchFiles('');"
								style="margin-left: 5px;">
								<?php esc_html_e('Clear', 'staging-to-live-sync'); ?>
							</button>
							<span id="stls-search-results" style="margin-left: 15px; color: #666;"></span>
						</div>

						<div style="margin-top: 15px;">
							<button type="button" class="button"
								onclick="stlsExpandAll()"><?php esc_html_e('Expand All', 'staging-to-live-sync'); ?></button>
							<button type="button" class="button"
								onclick="stlsCollapseAll()"><?php esc_html_e('Collapse All', 'staging-to-live-sync'); ?></button>
							<span style="margin-left: 15px; color: #666;">
								<?php esc_html_e('Click folders to load files', 'staging-to-live-sync'); ?>
							</span>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php if ($tree_error !== 'no_selection'): ?>
				<div class="stls-file-tree-container"
					style="background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin-top: 20px; max-height: 800px; overflow-y: auto;">
					<?php
					if (empty($file_tree) && !$tree_error) {
						echo '<p class="description">' . esc_html__('No files found or directory is too large to display. Try using the search function to find specific files.', 'staging-to-live-sync') . '</p>';
					} elseif (!empty($file_tree)) {
						$this->render_file_tree($file_tree, $wp_content_dir);
					}
					?>
				</div>
			<?php endif; ?>

			<?php if ($tree_error !== 'no_selection'): ?>
				<div class="stls-file-sync-actions"
					style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<div style="display: flex; align-items: center; justify-content: space-between;">
						<div>
							<button type="button" class="button"
								onclick="stlsSelectAllFiles()"><?php esc_html_e('Select All', 'staging-to-live-sync'); ?></button>
							<button type="button" class="button"
								onclick="stlsDeselectAllFiles()"><?php esc_html_e('Deselect All', 'staging-to-live-sync'); ?></button>
							<span id="stls-selected-count" style="margin-left: 15px; color: #666; font-weight: 600;">
								<?php esc_html_e('0 files selected', 'staging-to-live-sync'); ?>
							</span>
						</div>
						<div>
							<button type="button" id="stls-sync-files-btn" class="button button-primary button-large"
								onclick="stlsSyncSelectedFiles()" disabled>
								<?php esc_html_e('Sync Files', 'staging-to-live-sync'); ?>
							</button>
						</div>
					</div>
					<div id="stls-sync-progress" style="margin-top: 15px; display: none;">
						<div class="notice notice-info inline">
							<p>
								<span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
								<span id="stls-sync-status"><?php esc_html_e('Syncing files...', 'staging-to-live-sync'); ?></span>
							</p>
						</div>
					</div>
					<div id="stls-sync-results" style="margin-top: 15px; display: none;"></div>
				</div>
			<?php endif; ?>
		</div>

		<style>
			<?php echo $this->get_file_sync_styles(); ?>
		</style>

		<script>
			var stlsSyncFilesNonce = '<?php echo wp_create_nonce('stls_sync_files'); ?>';
			var stlsLoadDirectoryNonce = '<?php echo wp_create_nonce('stls_load_directory'); ?>';
			<?php echo $this->get_file_sync_scripts(); ?>

			// Initialize on page load
			jQuery(document).ready(function () {
				stlsUpdateSelectedCount();
			});
		</script>
		<?php
	}

	/**
	 * Render Elementor logs page
	 */
	public function render_elementor_logs_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// Check if function exists
		if (!function_exists('stls_elementor_get_logs')) {
			echo '<div class="wrap"><h1>Elementor Sync Logs</h1><div class="notice notice-error"><p>Elementor sync logging is not available. Please ensure Elementor is selected as the pagebuilder.</p></div></div>';
			return;
		}

		// Handle clear logs action
		if (isset($_POST['stls_clear_elementor_logs']) && check_admin_referer('stls_clear_elementor_logs_action', 'stls_clear_elementor_logs_nonce')) {
			if (function_exists('stls_elementor_clear_logs')) {
				stls_elementor_clear_logs();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Elementor logs cleared successfully.', 'staging-to-live-sync') . '</p></div>';
			}
		}

		// Handle export logs
		if (isset($_GET['export']) && $_GET['export'] === '1' && check_admin_referer('stls_export_elementor_logs', 'nonce')) {
			if (function_exists('stls_elementor_export_logs')) {
				$logs_json = stls_elementor_export_logs();
				header('Content-Type: application/json');
				header('Content-Disposition: attachment; filename="elementor-sync-logs-' . date('Y-m-d-H-i-s') . '.json"');
				echo $logs_json;
				exit;
			}
		}

		// Get logs
		$logs = stls_elementor_get_logs(500);
		$total_logs = count($logs);

		// Filter logs by level if requested
		$filter_level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
		if (!empty($filter_level) && in_array($filter_level, array('error', 'warning', 'info', 'success'))) {
			$logs = array_filter($logs, function ($log) use ($filter_level) {
				return isset($log['level']) && $log['level'] === $filter_level;
			});
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<div class="stls-elementor-logs-header"
				style="margin: 20px 0; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
				<form method="post" action="" style="display: inline-block;">
					<?php wp_nonce_field('stls_clear_elementor_logs_action', 'stls_clear_elementor_logs_nonce'); ?>
					<input type="hidden" name="stls_clear_elementor_logs" value="1" />
					<?php submit_button(__('Clear All Logs', 'staging-to-live-sync'), 'secondary', 'clear', false); ?>
				</form>

				<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('export', '1'), 'stls_export_elementor_logs', 'nonce')); ?>"
					class="button">
					<?php esc_html_e('Export Logs (JSON)', 'staging-to-live-sync'); ?>
				</a>

				<div style="margin-left: auto;">
					<label>
						<?php esc_html_e('Filter by level:', 'staging-to-live-sync'); ?>
						<select name="level" onchange="window.location.href = addQueryParam('level', this.value)">
							<option value=""><?php esc_html_e('All', 'staging-to-live-sync'); ?></option>
							<option value="error" <?php selected($filter_level, 'error'); ?>>
								<?php esc_html_e('Errors', 'staging-to-live-sync'); ?>
							</option>
							<option value="warning" <?php selected($filter_level, 'warning'); ?>>
								<?php esc_html_e('Warnings', 'staging-to-live-sync'); ?>
							</option>
							<option value="info" <?php selected($filter_level, 'info'); ?>>
								<?php esc_html_e('Info', 'staging-to-live-sync'); ?>
							</option>
							<option value="success" <?php selected($filter_level, 'success'); ?>>
								<?php esc_html_e('Success', 'staging-to-live-sync'); ?>
							</option>
						</select>
					</label>
				</div>

				<span style="color: #666;">
					<?php
					echo esc_html(sprintf(__('Total log entries: %d', 'staging-to-live-sync'), $total_logs));
					if (!empty($filter_level)) {
						echo ' (' . esc_html(sprintf(__('Filtered: %d', 'staging-to-live-sync'), count($logs))) . ')';
					}
					?>
				</span>
			</div>

			<?php if (empty($logs)): ?>
				<div class="notice notice-info">
					<p><?php esc_html_e('No Elementor sync logs found. Logs will appear here when you sync posts with Elementor content.', 'staging-to-live-sync'); ?>
					</p>
				</div>
			<?php else: ?>
				<div class="stls-elementor-logs-container"
					style="background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 0; margin-top: 20px; max-height: 800px; overflow-y: auto;">
					<div
						style="padding: 15px; background: #f5f5f5; border-bottom: 1px solid #ccd0d4; position: sticky; top: 0; z-index: 10;">
						<strong><?php echo esc_html(sprintf(__('Showing %d log entries', 'staging-to-live-sync'), count($logs))); ?></strong>
					</div>
					<div style="padding: 15px;">
						<?php foreach (array_reverse($logs) as $index => $log):
							$level = isset($log['level']) ? $log['level'] : 'info';
							$level_colors = array(
								'error' => '#dc3232',
								'warning' => '#ffb900',
								'success' => '#46b450',
								'info' => '#2271b1',
							);
							$level_color = isset($level_colors[$level]) ? $level_colors[$level] : '#666';
							?>
							<div
								style="margin-bottom: 15px; padding: 12px; border-left: 4px solid <?php echo esc_attr($level_color); ?>; background: #f9f9f9;">
								<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
									<div>
										<strong
											style="color: <?php echo esc_attr($level_color); ?>; text-transform: uppercase; font-size: 11px;">
											<?php echo esc_html($level); ?>
										</strong>
										<span style="margin-left: 10px; color: #666; font-size: 12px;">
											<?php echo esc_html(isset($log['timestamp']) ? $log['timestamp'] : ''); ?>
										</span>
									</div>
								</div>
								<div style="margin-bottom: 8px; font-weight: 500;">
									<?php echo esc_html(isset($log['message']) ? $log['message'] : ''); ?>
								</div>
								<?php if (!empty($log['data']) && is_array($log['data'])): ?>
									<details style="margin-top: 8px;">
										<summary style="cursor: pointer; color: #2271b1; font-size: 12px;">
											<?php esc_html_e('View Details', 'staging-to-live-sync'); ?>
										</summary>
										<pre
											style="margin-top: 8px; padding: 10px; background: #fff; border: 1px solid #ddd; overflow-x: auto; font-size: 11px; max-height: 300px; overflow-y: auto;"><?php echo esc_html(print_r($log['data'], true)); ?></pre>
									</details>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<script>
			function addQueryParam(key, value) {
				var url = new URL(window.location.href);
				if (value) {
					url.searchParams.set(key, value);
				} else {
					url.searchParams.delete(key);
				}
				return url.toString();
			}
		</script>
		<?php
	}

	/**
	 * Build file tree structure for selected themes and plugins only
	 */
	private function build_file_tree_selected($wp_content_dir, $selected_items)
	{
		$tree = array();

		// Build paths for selected items
		$selected_paths = array();

		foreach ($selected_items as $item_key) {
			if (strpos($item_key, 'theme_') === 0) {
				$theme_slug = str_replace('theme_', '', $item_key);
				$selected_paths[] = 'themes' . DIRECTORY_SEPARATOR . $theme_slug;
			} elseif (strpos($item_key, 'plugin_') === 0) {
				$plugin_file = str_replace('plugin_', '', $item_key);
				$plugin_dir = dirname($plugin_file);
				if ($plugin_dir === '.') {
					// Plugin is in root plugins directory
					$selected_paths[] = 'plugins' . DIRECTORY_SEPARATOR . basename($plugin_file);
				} else {
					$selected_paths[] = 'plugins' . DIRECTORY_SEPARATOR . $plugin_dir;
				}
			}
		}

		// Build tree for each selected path
		foreach ($selected_paths as $relative_path) {
			$full_path = $wp_content_dir . DIRECTORY_SEPARATOR . $relative_path;

			if (is_dir($full_path) && is_readable($full_path)) {
				$path_parts = explode(DIRECTORY_SEPARATOR, $relative_path);
				$item_name = end($path_parts);

				$tree[] = array(
					'name' => $item_name,
					'path' => $full_path,
					'relative_path' => $relative_path,
					'type' => 'directory',
					'children' => array(), // Will be loaded via AJAX
					'lazy_load' => true,
				);
			}
		}

		return $tree;
	}

	/**
	 * Build file tree structure
	 */
	private function build_file_tree($dir, $base_dir, $load_files = false)
	{
		$tree = array();

		if (!is_dir($dir) || !is_readable($dir)) {
			return $tree;
		}

		$items = scandir($dir);
		if ($items === false) {
			return $tree;
		}

		// Sort items: directories first, then files
		$dirs = array();
		$files = array();

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$full_path = $dir . DIRECTORY_SEPARATOR . $item;
			$relative_path = str_replace($base_dir . DIRECTORY_SEPARATOR, '', $full_path);

			// Skip certain directories for security/performance
			$skip_dirs = array('cache', 'upgrade', 'backup', 'backups', '.git', '.svn', 'node_modules');
			if (is_dir($full_path) && in_array(strtolower($item), $skip_dirs, true)) {
				continue;
			}

			if (is_dir($full_path)) {
				$dirs[] = array(
					'name' => $item,
					'path' => $full_path,
					'relative_path' => $relative_path,
					'type' => 'directory',
					'children' => $this->build_file_tree($full_path, $base_dir, $load_files)
				);
			} else {
				$file_size = filesize($full_path);
				$file_size_formatted = size_format($file_size, 2);
				$file_ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));

				$files[] = array(
					'name' => $item,
					'path' => $full_path,
					'relative_path' => $relative_path,
					'type' => 'file',
					'size' => $file_size,
					'size_formatted' => $file_size_formatted,
					'extension' => $file_ext
				);
			}
		}

		// Sort directories and files alphabetically
		usort($dirs, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});
		usort($files, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		// Combine: directories first, then files
		return array_merge($dirs, $files);
	}

	/**
	 * Render file tree HTML
	 */
	private function render_file_tree($tree, $base_dir, $level = 0)
	{
		if (empty($tree)) {
			return;
		}

		$indent = $level * 20;

		foreach ($tree as $item) {
			$is_dir = $item['type'] === 'directory';
			$has_children = $is_dir && !empty($item['children']);
			$lazy_load = isset($item['lazy_load']) && $item['lazy_load'];
			$item_id = 'stls-file-' . md5($item['path']);

			?>
			<div class="stls-file-item" data-level="<?php echo esc_attr($level); ?>"
				data-name="<?php echo esc_attr(strtolower($item['name'])); ?>"
				data-path="<?php echo esc_attr($item['relative_path']); ?>"
				style="margin-left: <?php echo esc_attr($indent); ?>px; padding: 5px 0;">
				<div class="stls-file-row"
					style="display: flex; align-items: center; padding: 5px; border-radius: 3px; cursor: <?php echo $is_dir ? 'pointer' : 'default'; ?>;"
					<?php if ($is_dir): ?>onclick="stlsToggleFolder('<?php echo esc_js($item_id); ?>')" <?php endif; ?>>
					<?php if (!$is_dir): ?>
						<input type="checkbox" class="stls-file-checkbox" value="<?php echo esc_attr($item['relative_path']); ?>"
							data-file-path="<?php echo esc_attr($item['relative_path']); ?>"
							data-file-name="<?php echo esc_attr($item['name']); ?>" style="margin-right: 8px; cursor: pointer;"
							onclick="event.stopPropagation(); stlsUpdateSelectedCount();" />
					<?php else: ?>
						<span style="margin-right: 8px; width: 20px; display: inline-block;"></span>
					<?php endif; ?>

					<?php if ($is_dir): ?>
						<span class="stls-folder-icon" id="icon-<?php echo esc_attr($item_id); ?>"
							style="margin-right: 5px; font-size: 16px; width: 20px; display: inline-block;">
							<?php echo ($has_children || $lazy_load) ? '📁' : '📂'; ?>
						</span>
					<?php else: ?>
						<span class="stls-file-icon" style="margin-right: 5px; font-size: 16px; width: 20px; display: inline-block;">
							<?php echo $this->get_file_icon($item['extension']); ?>
						</span>
					<?php endif; ?>

					<span class="stls-file-name" style="flex: 1; font-family: monospace; font-size: 13px;">
						<?php echo esc_html($item['name']); ?>
					</span>

					<?php if (!$is_dir): ?>
						<span class="stls-file-size" style="margin-left: 10px; color: #666; font-size: 12px;">
							<?php echo esc_html($item['size_formatted']); ?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ($has_children): ?>
					<div class="stls-file-children" id="<?php echo esc_attr($item_id); ?>" style="display: none; margin-top: 5px;">
						<?php $this->render_file_tree($item['children'], $base_dir, $level + 1); ?>
					</div>
				<?php elseif (isset($item['lazy_load']) && $item['lazy_load']): ?>
					<div class="stls-file-children" id="<?php echo esc_attr($item_id); ?>" style="display: none; margin-top: 5px;">
						<div class="stls-loading" style="padding: 10px; color: #666; font-style: italic;">
							<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
							<?php esc_html_e('Loading...', 'staging-to-live-sync'); ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Get file icon based on extension
	 */
	private function get_file_icon($extension)
	{
		$icons = array(
			'php' => '📄',
			'js' => '📜',
			'css' => '🎨',
			'json' => '📋',
			'png' => '🖼️',
			'jpg' => '🖼️',
			'jpeg' => '🖼️',
			'gif' => '🖼️',
			'svg' => '🖼️',
			'pdf' => '📕',
			'zip' => '📦',
			'txt' => '📝',
			'md' => '📝',
			'xml' => '📄',
			'html' => '🌐',
			'woff' => '🔤',
			'woff2' => '🔤',
			'ttf' => '🔤',
			'eot' => '🔤',
		);

		return isset($icons[$extension]) ? $icons[$extension] : '📄';
	}

	/**
	 * Get file sync page styles
	 */
	private function get_file_sync_styles()
	{
		return '
		.stls-file-row:hover {
			background-color: #f0f0f1;
		}
		.stls-file-item {
			user-select: none;
		}
		.stls-file-children {
			border-left: 2px solid #e0e0e0;
			margin-left: 10px;
			padding-left: 10px;
		}
		.stls-file-row {
			transition: background-color 0.2s ease;
		}
		.stls-folder-icon, .stls-file-icon {
			text-align: center;
		}
		.stls-search-match .stls-file-row {
			background-color: #fff3cd !important;
			border-left: 3px solid #ffc107;
			padding-left: 7px;
		}
		.stls-search-match .stls-file-row:hover {
			background-color: #ffe69c !important;
		}
		';
	}

	/**
	 * Count files in tree
	 */
	private function count_files_in_tree($tree)
	{
		$count = 0;
		foreach ($tree as $item) {
			if ($item['type'] === 'file') {
				$count++;
			} elseif ($item['type'] === 'directory' && !empty($item['children'])) {
				$count += $this->count_files_in_tree($item['children']);
			}
		}
		return $count;
	}

	/**
	 * Get file sync page scripts
	 */
	private function get_file_sync_scripts()
	{
		return '
		function stlsToggleFolder(itemId) {
			var children = document.getElementById(itemId);
			var icon = document.getElementById("icon-" + itemId);
			if (children) {
				if (children.style.display === "none") {
					// Check if this is a lazy-loaded directory
					var loadingDiv = children.querySelector(".stls-loading");
					if (loadingDiv) {
						// Load directory contents via AJAX
						var fileItem = children.closest(".stls-file-item");
						if (fileItem) {
							var dirPath = fileItem.getAttribute("data-path");
							if (dirPath) {
								stlsLoadDirectory(itemId, dirPath);
								return;
							}
						}
					}
					children.style.display = "block";
					if (icon) icon.textContent = "📁";
				} else {
					children.style.display = "none";
					if (icon) icon.textContent = "📂";
				}
			}
		}
		
		function stlsLoadDirectory(itemId, dirPath) {
			var children = document.getElementById(itemId);
			var loadingDiv = children.querySelector(".stls-loading");
			if (!loadingDiv) return;
			
			loadingDiv.innerHTML = "<span class=\"spinner is-active\" style=\"float: none; margin: 0 5px 0 0;\"></span> Loading...";
			
			jQuery.ajax({
				url: ajaxurl,
				type: "POST",
				data: {
					action: "stls_load_directory",
					nonce: stlsLoadDirectoryNonce,
					dir_path: dirPath
				},
				success: function(response) {
					if (response.success && response.data.html) {
						children.innerHTML = response.data.html;
						children.style.display = "block";
						var icon = document.getElementById("icon-" + itemId);
						if (icon) icon.textContent = "📁";
					} else {
						loadingDiv.innerHTML = "<span style=\"color: #d63638;\">Error: " + (response.data.message || "Failed to load directory") + "</span>";
					}
				},
				error: function(xhr, status, error) {
					loadingDiv.innerHTML = "<span style=\"color: #d63638;\">Error loading directory: " + error + "</span>";
				}
			});
		}
		
		function stlsExpandAll() {
			var allChildren = document.querySelectorAll(".stls-file-children");
			var allIcons = document.querySelectorAll(".stls-folder-icon");
			allChildren.forEach(function(el) {
				el.style.display = "block";
			});
			allIcons.forEach(function(el) {
				el.textContent = "📁";
			});
		}
		
		function stlsCollapseAll() {
			var allChildren = document.querySelectorAll(".stls-file-children");
			var allIcons = document.querySelectorAll(".stls-folder-icon");
			allChildren.forEach(function(el) {
				el.style.display = "none";
			});
			allIcons.forEach(function(el) {
				el.textContent = "📂";
			});
		}
		
		function stlsSearchFiles(searchTerm) {
			var searchLower = searchTerm.toLowerCase().trim();
			var allItems = document.querySelectorAll(".stls-file-item");
			var matchCount = 0;
			var visibleCount = 0;
			
			if (searchLower === "") {
				// Show all items
				allItems.forEach(function(item) {
					item.style.display = "";
					item.classList.remove("stls-search-match");
					var row = item.querySelector(".stls-file-row");
					if (row) {
						row.style.backgroundColor = "";
					}
				});
				document.getElementById("stls-search-results").textContent = "";
				return;
			}
			
			// First, hide all items
			allItems.forEach(function(item) {
				item.style.display = "none";
				item.classList.remove("stls-search-match");
				var row = item.querySelector(".stls-file-row");
				if (row) {
					row.style.backgroundColor = "";
				}
			});
			
			// Find matching items
			allItems.forEach(function(item) {
				var fileName = item.getAttribute("data-name") || "";
				var filePath = item.getAttribute("data-path") || "";
				
				if (fileName.indexOf(searchLower) !== -1 || filePath.indexOf(searchLower) !== -1) {
					matchCount++;
					item.classList.add("stls-search-match");
					item.style.display = "";
					
					// Highlight the row
					var row = item.querySelector(".stls-file-row");
					if (row) {
						row.style.backgroundColor = "#fff3cd";
					}
					
					// Show parent folders
					var parent = item.parentElement;
					while (parent && parent.classList.contains("stls-file-children")) {
						parent.style.display = "block";
						var parentItem = parent.parentElement;
						if (parentItem && parentItem.classList.contains("stls-file-item")) {
							parentItem.style.display = "";
							var parentIcon = parentItem.querySelector(".stls-folder-icon");
							if (parentIcon) {
								parentIcon.textContent = "📁";
							}
							parent = parentItem.parentElement;
						} else {
							break;
						}
					}
					
					// Show all children if it\'s a folder
					var children = item.querySelector(".stls-file-children");
					if (children) {
						children.style.display = "block";
						var icon = item.querySelector(".stls-folder-icon");
						if (icon) {
							icon.textContent = "📁";
						}
					}
				}
			});
			
			// Count visible items
			allItems.forEach(function(item) {
				if (item.style.display !== "none") {
					visibleCount++;
				}
			});
			
			// Update search results text
			var resultsText = "";
			if (matchCount > 0) {
				resultsText = "Found " + matchCount + " matching " + (matchCount === 1 ? "item" : "items");
			} else {
				resultsText = "No matches found";
			}
			document.getElementById("stls-search-results").textContent = resultsText;
		}
		
		function stlsUpdateSelectedCount() {
			var checkboxes = document.querySelectorAll(".stls-file-checkbox:checked");
			var count = checkboxes.length;
			var countText = count + " file" + (count !== 1 ? "s" : "") + " selected";
			document.getElementById("stls-selected-count").textContent = countText;
			
			var syncBtn = document.getElementById("stls-sync-files-btn");
			if (syncBtn) {
				syncBtn.disabled = count === 0;
			}
		}
		
		function stlsSelectAllFiles() {
			var checkboxes = document.querySelectorAll(".stls-file-checkbox");
			checkboxes.forEach(function(checkbox) {
				checkbox.checked = true;
			});
			stlsUpdateSelectedCount();
		}
		
		function stlsDeselectAllFiles() {
			var checkboxes = document.querySelectorAll(".stls-file-checkbox");
			checkboxes.forEach(function(checkbox) {
				checkbox.checked = false;
			});
			stlsUpdateSelectedCount();
		}
		
		function stlsSyncSelectedFiles() {
			var checkboxes = document.querySelectorAll(".stls-file-checkbox:checked");
			if (checkboxes.length === 0) {
				alert("Please select at least one file to sync.");
				return;
			}
			
			var files = [];
			checkboxes.forEach(function(checkbox) {
				files.push({
					path: checkbox.getAttribute("data-file-path"),
					name: checkbox.getAttribute("data-file-name")
				});
			});
			
			// Show progress
			document.getElementById("stls-sync-progress").style.display = "block";
			document.getElementById("stls-sync-results").style.display = "none";
			document.getElementById("stls-sync-files-btn").disabled = true;
			document.getElementById("stls-sync-status").textContent = "Syncing " + files.length + " file(s)...";
			
			// Send AJAX request
			jQuery.ajax({
				url: ajaxurl,
				type: "POST",
				data: {
					action: "stls_sync_files",
					nonce: stlsSyncFilesNonce,
					files: JSON.stringify(files)
				},
				success: function(response) {
					document.getElementById("stls-sync-progress").style.display = "none";
					document.getElementById("stls-sync-files-btn").disabled = false;
					
					var resultsDiv = document.getElementById("stls-sync-results");
					resultsDiv.style.display = "block";
					
					if (response.success) {
						resultsDiv.innerHTML = "<div class=\"notice notice-success inline\"><p>" + response.data.message + "</p></div>";
						if (response.data.details) {
							resultsDiv.innerHTML += "<div style=\"margin-top: 10px;\"><strong>Details:</strong><ul style=\"margin: 10px 0;\">";
							response.data.details.forEach(function(detail) {
								var statusClass = detail.success ? "color: green;" : "color: red;";
								resultsDiv.innerHTML += "<li style=\"" + statusClass + "\">" + detail.file + ": " + detail.message + "</li>";
							});
							resultsDiv.innerHTML += "</ul></div>";
						}
					} else {
						resultsDiv.innerHTML = "<div class=\"notice notice-error inline\"><p>" + (response.data.message || "Error syncing files.") + "</p></div>";
					}
				},
				error: function(xhr, status, error) {
					document.getElementById("stls-sync-progress").style.display = "none";
					document.getElementById("stls-sync-files-btn").disabled = false;
					document.getElementById("stls-sync-results").style.display = "block";
					document.getElementById("stls-sync-results").innerHTML = "<div class=\"notice notice-error inline\"><p>Error: " + error + "</p></div>";
				}
			});
		}
		';
	}

	/**
	 * Get STLS logs from database
	 */
	private function get_stls_logs($limit = 100)
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'stls_sync_logs';

		$logs = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name ORDER BY sync_time DESC LIMIT %d",
			$limit
		));

		return $logs ? $logs : array();
	}

	/**
	 * Get total logs count
	 */
	private function get_total_logs_count()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'stls_sync_logs';

		$count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

		return $count ? intval($count) : 0;
	}

	/**
	 * Clear all STLS logs from database
	 */
	private function clear_logs()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'stls_sync_logs';

		$wpdb->query("TRUNCATE TABLE $table_name");
	}

	/**
	 * Add sync row actions to all registered post types
	 */
	public function add_sync_row_actions_to_all_post_types()
	{
		// Get all registered post types (excluding built-in types that don't have admin UI)
		$post_types = get_post_types(array('show_ui' => true), 'names');

		foreach ($post_types as $post_type) {
			// Add filter for each post type's row actions
			add_filter($post_type . '_row_actions', array($this, 'add_sync_row_action'), 10, 2);
		}
	}

	/**
	 * Add sync row action
	 */
	public function add_sync_row_action($actions, $post)
	{
		if (!current_user_can('edit_post', $post->ID)) {
			return $actions;
		}

		// Check if this post type is disabled for syncing
		if ($this->is_post_type_disabled($post->post_type)) {
			return $actions;
		}

		$staging_url = get_option('stls_staging_url');
		$live_url = get_option('stls_live_url');

		// Only show sync if URLs are configured
		if (empty($staging_url) || empty($live_url)) {
			return $actions;
		}

		// Determine if we're on staging or live
		$current_site_url = home_url();
		$current_host = parse_url($current_site_url, PHP_URL_HOST);
		$staging_host = parse_url($staging_url, PHP_URL_HOST);
		$live_host = parse_url($live_url, PHP_URL_HOST);

		// Only show sync button if we're on staging (not on live)
		// This ensures we're syncing FROM staging TO live
		$is_staging = ($current_host === $staging_host ||
			(!empty($current_host) && !empty($staging_host) &&
				(strpos($current_host, $staging_host) !== false ||
					strpos($staging_host, $current_host) !== false)));

		// Don't show sync button on live site
		$is_live = ($current_host === $live_host ||
			(!empty($current_host) && !empty($live_host) &&
				(strpos($current_host, $live_host) !== false ||
					strpos($live_host, $current_host) !== false)));

		if ($is_staging && !$is_live) {
			$actions['stls_sync'] = sprintf(
				'<a href="#" class="stls-sync-post" data-post-id="%d" data-nonce="%s">%s</a>',
				esc_attr($post->ID),
				wp_create_nonce('stls_sync_' . $post->ID),
				esc_html__('Sync', 'staging-to-live-sync')
			);
		}

		return $actions;
	}

	/**
	 * Enqueue admin scripts
	 */
	public function enqueue_admin_scripts($hook)
	{
		// Load on settings page, logs page, file sync page, and edit pages
		// Hook format for submenu pages: {parent-slug}_page_{submenu-slug}
		if (strpos($hook, 'staging-to-live-sync') !== false || 'edit.php' === $hook) {
			wp_enqueue_script(
				'stls-admin-script',
				STLS_PLUGIN_URL . 'assets/admin.js',
				array('jquery'),
				STLS_VERSION,
				true
			);

			wp_localize_script('stls-admin-script', 'stlsData', array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'strings' => array(
					'syncing' => __('Syncing...', 'staging-to-live-sync'),
					'success' => __('Synced successfully!', 'staging-to-live-sync'),
					'error' => __('Error syncing. Please try again.', 'staging-to-live-sync'),
					'generating' => __('Generating...', 'staging-to-live-sync'),
					'keyGenerated' => __('Key generated!', 'staging-to-live-sync'),
					'keyCopied' => __('Copied!', 'staging-to-live-sync'),
					'copyFailed' => __('Failed to copy. Please copy manually.', 'staging-to-live-sync'),
				),
			));

			wp_enqueue_style(
				'stls-admin-style',
				STLS_PLUGIN_URL . 'assets/admin.css',
				array(),
				STLS_VERSION
			);
		}
	}

	/**
	 * AJAX handler for generating API key
	 */
	public function ajax_generate_api_key()
	{
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'stls_generate_key')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'staging-to-live-sync')));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to generate API keys.', 'staging-to-live-sync')));
		}

		// Generate secure random API key
		$api_key = $this->generate_secure_api_key();

		wp_send_json_success(array('api_key' => $api_key));
	}


	/**
	 * Get ACF Gutenberg blocks data
	 */
	private function get_acf_gutenberg_blocks_data($post_id, $post_content)
	{
		$acf_blocks_data = array();
		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		if ($debug_mode) {
			error_log('STLS: Starting block data collection for post ' . $post_id);
		}

		// Check if ACF is active and has blocks
		if (!function_exists('acf_get_block_types')) {
			if ($debug_mode) {
				error_log('STLS: acf_get_block_types function not available');
			}
			return $acf_blocks_data;
		}

		if (empty($post_content)) {
			if ($debug_mode) {
				error_log('STLS: Post content is empty');
			}
			return $acf_blocks_data;
		}

		// Parse Gutenberg blocks
		if (!function_exists('parse_blocks')) {
			if ($debug_mode) {
				error_log('STLS: parse_blocks function not available');
			}
			return $acf_blocks_data;
		}

		$blocks = parse_blocks($post_content);

		if ($debug_mode) {
			error_log('STLS: Parsed ' . count($blocks) . ' blocks from post content');

			// Log all block names found
			$all_block_names = array();
			$this->collect_all_block_names($blocks, $all_block_names);
			if (!empty($all_block_names)) {
				error_log('STLS: All block names found in content: ' . implode(', ', array_unique($all_block_names)));
			}
		}

		// Get all registered ACF block types
		$acf_block_types = acf_get_block_types();
		$acf_block_names = array();

		if ($debug_mode) {
			error_log('STLS: Found ' . count($acf_block_types) . ' registered ACF block types');
		}

		if (!empty($acf_block_types)) {
			foreach ($acf_block_types as $block_type) {
				if (isset($block_type['name'])) {
					$acf_block_names[] = 'acf/' . $block_type['name'];
					if ($debug_mode) {
						error_log('STLS: Registered ACF block: acf/' . $block_type['name']);
					}
				}
			}
		}

		// Also scan all blocks to find any that start with 'acf/' (fallback for custom blocks)
		// This ensures we catch blocks even if they're not in the registered list
		$found_block_names = array();
		foreach ($blocks as $block) {
			if ($this->is_acf_block($block['blockName'])) {
				if (!in_array($block['blockName'], $acf_block_names, true)) {
					$acf_block_names[] = $block['blockName'];
					$found_block_names[] = $block['blockName'];
				}
			}
		}

		if ($debug_mode) {
			if (!empty($found_block_names)) {
				error_log('STLS: Found additional ACF blocks in content: ' . implode(', ', $found_block_names));
			}
			error_log('STLS: Looking for ACF blocks: ' . implode(', ', $acf_block_names));
		}

		// Recursively find all ACF blocks
		$this->extract_acf_blocks_recursive($blocks, $acf_block_names, $post_id, $acf_blocks_data);

		if ($debug_mode) {
			error_log('STLS: Found ' . count($acf_blocks_data) . ' ACF blocks after extraction');
		}

		return $acf_blocks_data;
	}

	/**
	 * Collect all block names recursively for debugging
	 */
	private function collect_all_block_names($blocks, &$block_names)
	{
		foreach ($blocks as $block) {
			if (!empty($block['blockName'])) {
				$block_names[] = $block['blockName'];
			}
			if (!empty($block['innerBlocks'])) {
				$this->collect_all_block_names($block['innerBlocks'], $block_names);
			}
		}
	}

	/**
	 * Recursively extract ACF blocks from block structure
	 */
	/**
	 * Check if a block is an ACF block
	 * 
	 * @param string $block_name The block name to check
	 * @return bool True if it's an ACF block, false otherwise
	 */
	private function is_acf_block($block_name)
	{
		return !empty($block_name) && strpos($block_name, 'acf/') === 0;
	}

	/**
	 * Check if a block is a default WordPress image block
	 * 
	 * @param string $block_name The block name to check
	 * @return bool True if it's a default image block, false otherwise
	 */
	private function is_default_image_block($block_name)
	{
		return in_array($block_name, array(
			'core/image',
			'wp:image',
			'core/gallery',
			'wp:gallery',
			'core/cover',
			'wp:cover',
			'core/media-text',
			'wp:media-text',
			'core/file',
			'wp:file',
		), true);
	}

	/**
	 * Check if a block is a default WordPress audio block
	 * 
	 * @param string $block_name The block name to check
	 * @return bool True if it's a default audio block, false otherwise
	 */
	private function is_default_audio_block($block_name)
	{
		return $block_name === 'core/audio' || $block_name === 'wp:audio';
	}

	/**
	 * Check if a block is a default WordPress video block
	 * 
	 * @param string $block_name The block name to check
	 * @return bool True if it's a default video block, false otherwise
	 */
	private function is_default_video_block($block_name)
	{
		return $block_name === 'core/video' || $block_name === 'wp:video';
	}

	/**
	 * Extract default WordPress image blocks from post content
	 */
	private function extract_image_blocks_recursive($blocks, &$image_blocks_data)
	{
		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		foreach ($blocks as $block) {
			if (empty($block['blockName'])) {
				continue;
			}

			// Check if this is a default WordPress image block
			if ($this->is_default_image_block($block['blockName'])) {
				if (isset($block['attrs'])) {
					$image_data = array(
						'blockName' => $block['blockName'],
						'attrs' => $block['attrs'],
					);

					// Extract image URL or ID from various possible attributes
					$attachment_id = null;
					$image_url = null;

					// 1. Check for 'mediaId' (Media & Text block) or 'id' (Image/Cover block)
					if (isset($block['attrs']['mediaId']) && is_numeric($block['attrs']['mediaId'])) {
						$attachment_id = intval($block['attrs']['mediaId']);
					} elseif (isset($block['attrs']['id']) && is_numeric($block['attrs']['id'])) {
						$attachment_id = intval($block['attrs']['id']);
					}

					// 2. Check for 'mediaUrl' (Media & Text), 'href' (File block), or 'url' (Image/Cover)
					if (isset($block['attrs']['mediaUrl']) && !empty($block['attrs']['mediaUrl'])) {
						$image_url = $block['attrs']['mediaUrl'];
					} elseif (isset($block['attrs']['href']) && !empty($block['attrs']['href'])) {
						$image_url = $block['attrs']['href'];
					} elseif (isset($block['attrs']['url']) && !empty($block['attrs']['url'])) {
						$image_url = $block['attrs']['url'];
					}

					// 3. Special handling for Gallery blocks (older format might have 'ids' attribute)
					if (($block['blockName'] === 'core/gallery' || $block['blockName'] === 'wp:gallery') && isset($block['attrs']['ids']) && is_array($block['attrs']['ids'])) {
						foreach ($block['attrs']['ids'] as $gal_id) {
							if (is_numeric($gal_id)) {
								$gal_attachment = get_post($gal_id);
								if ($gal_attachment && $gal_attachment->post_type === 'attachment') {
									$gal_url = wp_get_attachment_url($gal_id);
									$image_blocks_data[] = array(
										'blockName' => $block['blockName'],
										'attrs' => $block['attrs'],
										'image' => array(
											'attachment_id' => intval($gal_id),
											'url' => $gal_url,
											'alt' => get_post_meta($gal_id, '_wp_attachment_image_alt', true),
											'title' => $gal_attachment->post_title,
											'caption' => $gal_attachment->post_excerpt,
											'description' => $gal_attachment->post_content,
											'mime_type' => get_post_mime_type($gal_id),
										)
									);
								}
							}
						}
					}

					if ($attachment_id) {
						$attachment = get_post($attachment_id);
						if ($attachment && $attachment->post_type === 'attachment') {
							if (empty($image_url)) {
								$image_url = wp_get_attachment_url($attachment_id);
							}

							if (!empty($image_url) && strpos($image_url, 'http') !== 0) {
								$image_url = home_url($image_url);
							}

							$image_data['image'] = array(
								'attachment_id' => $attachment_id,
								'url' => $image_url,
								'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
								'title' => $attachment->post_title,
								'caption' => $attachment->post_excerpt,
								'description' => $attachment->post_content,
								'mime_type' => get_post_mime_type($attachment_id),
							);

							if ($debug_mode) {
								error_log('STLS: Found media block with attachment ID: ' . $attachment_id);
							}
						}
					} elseif (!empty($image_url)) {
						// Always extract image blocks, whether local or external
						// Check if it's a local URL (from this site)
						$attachment_id = null;
						if (strpos($image_url, home_url()) === 0 || strpos($image_url, '/wp-content/uploads/') !== false) {
							$attachment_id = attachment_url_to_postid($image_url);

							if ($attachment_id) {
								$attachment = get_post($attachment_id);
								if ($attachment && $attachment->post_type === 'attachment') {
									$image_data['image'] = array(
										'attachment_id' => $attachment_id,
										'url' => $image_url,
										'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
										'title' => $attachment->post_title,
										'caption' => $attachment->post_excerpt,
										'description' => $attachment->post_content,
										'mime_type' => get_post_mime_type($attachment_id),
									);
								}
							}
						}

						// If no attachment found or external URL, store URL for downloading
						if (!isset($image_data['image'])) {
							$image_data['image'] = array(
								'attachment_id' => $attachment_id ? $attachment_id : 0,
								'url' => $image_url,
								'alt' => isset($block['attrs']['alt']) ? $block['attrs']['alt'] : '',
								'title' => isset($block['attrs']['title']) ? $block['attrs']['title'] : '',
								'caption' => isset($block['attrs']['caption']) ? $block['attrs']['caption'] : '',
								'description' => '',
								'mime_type' => '',
							);
						}

						if ($debug_mode) {
							error_log('STLS: Found media block with URL: ' . $image_url . ($attachment_id ? ' (Attachment ID: ' . $attachment_id . ')' : ' (No attachment found)'));
						}
					}

					if (isset($image_data['image'])) {
						$image_blocks_data[] = $image_data;
					}
				}
			}

			// Recursively check inner blocks
			if (!empty($block['innerBlocks'])) {
				$this->extract_image_blocks_recursive($block['innerBlocks'], $image_blocks_data);
			}
		}
	}

	/**
	 * Extract default WordPress audio blocks from post content
	 */
	private function extract_audio_blocks_recursive($blocks, &$audio_blocks_data)
	{
		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		foreach ($blocks as $block) {
			if (empty($block['blockName'])) {
				continue;
			}

			// Check if this is a default WordPress audio block
			if ($this->is_default_audio_block($block['blockName'])) {
				if ($debug_mode) {
					error_log('STLS: Found audio block - Block name: ' . $block['blockName'] . ', Attrs: ' . print_r($block['attrs'] ?? array(), true));
				}

				if (isset($block['attrs'])) {
					$audio_data = array(
						'blockName' => $block['blockName'],
						'attrs' => $block['attrs'],
					);

					$audio_url = null;
					$attachment_id = null;

					// Extract audio URL or ID - check multiple possible locations
					// 1. Check for 'id' attribute (attachment ID)
					if (isset($block['attrs']['id']) && is_numeric($block['attrs']['id'])) {
						$attachment_id = intval($block['attrs']['id']);
						$attachment = get_post($attachment_id);

						if ($attachment && $attachment->post_type === 'attachment') {
							$audio_url = wp_get_attachment_url($attachment_id);
							if (!empty($audio_url) && strpos($audio_url, 'http') !== 0) {
								$audio_url = home_url($audio_url);
							}

							$audio_data['audio'] = array(
								'attachment_id' => $attachment_id,
								'url' => $audio_url,
								'title' => $attachment->post_title,
								'caption' => $attachment->post_excerpt,
								'description' => $attachment->post_content,
								'mime_type' => get_post_mime_type($attachment_id),
							);

							if ($debug_mode) {
								error_log('STLS: Found audio block with attachment ID: ' . $attachment_id . ', URL: ' . $audio_url);
							}
						}
					}
					// 2. Check for 'src' attribute
					elseif (isset($block['attrs']['src']) && !empty($block['attrs']['src'])) {
						$audio_url = $block['attrs']['src'];

						// Check if it's a local URL (from this site)
						if (strpos($audio_url, home_url()) === 0 || strpos($audio_url, '/wp-content/uploads/') !== false) {
							$attachment_id = attachment_url_to_postid($audio_url);

							if ($attachment_id) {
								$attachment = get_post($attachment_id);
								if ($attachment && $attachment->post_type === 'attachment') {
									$audio_data['audio'] = array(
										'attachment_id' => $attachment_id,
										'url' => $audio_url,
										'title' => $attachment->post_title,
										'caption' => $attachment->post_excerpt,
										'description' => $attachment->post_content,
										'mime_type' => get_post_mime_type($attachment_id),
									);
								}
							}
						}

						// If no attachment found or external URL, store URL for downloading
						if (!isset($audio_data['audio'])) {
							$audio_data['audio'] = array(
								'attachment_id' => $attachment_id ? $attachment_id : 0,
								'url' => $audio_url,
								'title' => isset($block['attrs']['title']) ? $block['attrs']['title'] : '',
								'caption' => isset($block['attrs']['caption']) ? $block['attrs']['caption'] : '',
								'description' => '',
								'mime_type' => '',
							);
						}

						if ($debug_mode) {
							error_log('STLS: Found audio block with src URL: ' . $audio_url . ($attachment_id ? ' (Attachment ID: ' . $attachment_id . ')' : ' (No attachment found)'));
						}
					}
					// 3. Check innerHTML for audio source tags
					elseif (isset($block['innerHTML']) && is_string($block['innerHTML']) && !empty($block['innerHTML'])) {
						// Extract audio URL from <audio> or <source> tags
						if (preg_match('/<audio[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
							$audio_url = $matches[1];
						} elseif (preg_match('/<source[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
							$audio_url = $matches[1];
						}

						if (!empty($audio_url)) {
							// Make URL absolute if relative
							if (strpos($audio_url, 'http') !== 0) {
								if (strpos($audio_url, '/') === 0) {
									$audio_url = home_url($audio_url);
								} else {
									$audio_url = home_url('/' . $audio_url);
								}
							}

							// Check if it's a local URL (from this site)
							if (strpos($audio_url, home_url()) === 0 || strpos($audio_url, '/wp-content/uploads/') !== false) {
								$attachment_id = attachment_url_to_postid($audio_url);

								if ($attachment_id) {
									$attachment = get_post($attachment_id);
									if ($attachment && $attachment->post_type === 'attachment') {
										$audio_data['audio'] = array(
											'attachment_id' => $attachment_id,
											'url' => $audio_url,
											'title' => $attachment->post_title,
											'caption' => $attachment->post_excerpt,
											'description' => $attachment->post_content,
											'mime_type' => get_post_mime_type($attachment_id),
										);
									}
								}
							}

							// If no attachment found or external URL, store URL for downloading
							if (!isset($audio_data['audio'])) {
								$audio_data['audio'] = array(
									'attachment_id' => $attachment_id ? $attachment_id : 0,
									'url' => $audio_url,
									'title' => isset($block['attrs']['title']) ? $block['attrs']['title'] : '',
									'caption' => isset($block['attrs']['caption']) ? $block['attrs']['caption'] : '',
									'description' => '',
									'mime_type' => '',
								);
							}

							if ($debug_mode) {
								error_log('STLS: Found audio block with URL from innerHTML: ' . $audio_url . ($attachment_id ? ' (Attachment ID: ' . $attachment_id . ')' : ' (No attachment found)'));
							}
						}
					}

					if (isset($audio_data['audio'])) {
						$audio_blocks_data[] = $audio_data;
						if ($debug_mode) {
							error_log('STLS: Added audio block data - URL: ' . $audio_data['audio']['url']);
						}
					} elseif ($debug_mode) {
						error_log('STLS: Audio block found but no audio data extracted - Block: ' . $block['blockName']);
					}
				}
			}

			// Recursively check inner blocks
			if (!empty($block['innerBlocks'])) {
				$this->extract_audio_blocks_recursive($block['innerBlocks'], $audio_blocks_data);
			}
		}
	}

	/**
	 * Extract default WordPress video blocks from post content
	 */
	private function extract_video_blocks_recursive($blocks, &$video_blocks_data)
	{
		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		foreach ($blocks as $block) {
			if (empty($block['blockName'])) {
				continue;
			}

			// Check if this is a default WordPress video block
			if ($this->is_default_video_block($block['blockName'])) {
				if ($debug_mode) {
					error_log('STLS: Found video block - Block name: ' . $block['blockName'] . ', Attrs: ' . print_r($block['attrs'] ?? array(), true));
				}

				if (isset($block['attrs'])) {
					$video_data = array(
						'blockName' => $block['blockName'],
						'attrs' => $block['attrs'],
					);

					$video_url = null;
					$attachment_id = null;

					// Extract video URL or ID - check multiple possible locations
					// 1. Check for 'id' attribute (attachment ID)
					if (isset($block['attrs']['id']) && is_numeric($block['attrs']['id'])) {
						$attachment_id = intval($block['attrs']['id']);
						$attachment = get_post($attachment_id);

						if ($attachment && $attachment->post_type === 'attachment') {
							$video_url = wp_get_attachment_url($attachment_id);
							if (!empty($video_url) && strpos($video_url, 'http') !== 0) {
								$video_url = home_url($video_url);
							}

							$video_data['video'] = array(
								'attachment_id' => $attachment_id,
								'url' => $video_url,
								'title' => $attachment->post_title,
								'caption' => $attachment->post_excerpt,
								'description' => $attachment->post_content,
								'mime_type' => get_post_mime_type($attachment_id),
							);

							if ($debug_mode) {
								error_log('STLS: Found video block with attachment ID: ' . $attachment_id . ', URL: ' . $video_url);
							}
						}
					}
					// 2. Check for 'src' attribute
					elseif (isset($block['attrs']['src']) && !empty($block['attrs']['src'])) {
						$video_url = $block['attrs']['src'];

						// Check if it's a local URL (from this site)
						if (strpos($video_url, home_url()) === 0 || strpos($video_url, '/wp-content/uploads/') !== false) {
							$attachment_id = attachment_url_to_postid($video_url);

							if ($attachment_id) {
								$attachment = get_post($attachment_id);
								if ($attachment && $attachment->post_type === 'attachment') {
									$video_data['video'] = array(
										'attachment_id' => $attachment_id,
										'url' => $video_url,
										'title' => $attachment->post_title,
										'caption' => $attachment->post_excerpt,
										'description' => $attachment->post_content,
										'mime_type' => get_post_mime_type($attachment_id),
									);
								}
							}
						}

						// If no attachment found or external URL, store URL for downloading
						if (!isset($video_data['video'])) {
							$video_data['video'] = array(
								'attachment_id' => $attachment_id ? $attachment_id : 0,
								'url' => $video_url,
								'title' => isset($block['attrs']['title']) ? $block['attrs']['title'] : '',
								'caption' => isset($block['attrs']['caption']) ? $block['attrs']['caption'] : '',
								'description' => '',
								'mime_type' => '',
							);
						}

						if ($debug_mode) {
							error_log('STLS: Found video block with src URL: ' . $video_url . ($attachment_id ? ' (Attachment ID: ' . $attachment_id . ')' : ' (No attachment found)'));
						}
					}
					// 3. Check innerHTML for video source tags
					elseif (isset($block['innerHTML']) && is_string($block['innerHTML']) && !empty($block['innerHTML'])) {
						// Extract video URL from <video> or <source> tags
						if (preg_match('/<video[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
							$video_url = $matches[1];
						} elseif (preg_match('/<source[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
							$video_url = $matches[1];
						}

						if (!empty($video_url)) {
							// Make URL absolute if relative
							if (strpos($video_url, 'http') !== 0) {
								if (strpos($video_url, '/') === 0) {
									$video_url = home_url($video_url);
								} else {
									$video_url = home_url('/' . $video_url);
								}
							}

							// Check if it's a local URL (from this site)
							if (strpos($video_url, home_url()) === 0 || strpos($video_url, '/wp-content/uploads/') !== false) {
								$attachment_id = attachment_url_to_postid($video_url);

								if ($attachment_id) {
									$attachment = get_post($attachment_id);
									if ($attachment && $attachment->post_type === 'attachment') {
										$video_data['video'] = array(
											'attachment_id' => $attachment_id,
											'url' => $video_url,
											'title' => $attachment->post_title,
											'caption' => $attachment->post_excerpt,
											'description' => $attachment->post_content,
											'mime_type' => get_post_mime_type($attachment_id),
										);
									}
								}
							}

							// If no attachment found or external URL, store URL for downloading
							if (!isset($video_data['video'])) {
								$video_data['video'] = array(
									'attachment_id' => $attachment_id ? $attachment_id : 0,
									'url' => $video_url,
									'title' => isset($block['attrs']['title']) ? $block['attrs']['title'] : '',
									'caption' => isset($block['attrs']['caption']) ? $block['attrs']['caption'] : '',
									'description' => '',
									'mime_type' => '',
								);
							}

							if ($debug_mode) {
								error_log('STLS: Found video block with URL from innerHTML: ' . $video_url . ($attachment_id ? ' (Attachment ID: ' . $attachment_id . ')' : ' (No attachment found)'));
							}
						}
					}

					if (isset($video_data['video'])) {
						$video_blocks_data[] = $video_data;
						if ($debug_mode) {
							error_log('STLS: Added video block data - URL: ' . $video_data['video']['url']);
						}
					} elseif ($debug_mode) {
						error_log('STLS: Video block found but no video data extracted - Block: ' . $block['blockName']);
					}
				}
			}

			// Recursively check inner blocks
			if (!empty($block['innerBlocks'])) {
				$this->extract_video_blocks_recursive($block['innerBlocks'], $video_blocks_data);
			}
		}
	}

	private function extract_acf_blocks_recursive($blocks, $acf_block_names, $post_id, &$acf_blocks_data)
	{
		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		foreach ($blocks as $block) {
			if (empty($block['blockName'])) {
				continue;
			}

			if ($debug_mode) {
				error_log('STLS: Checking block: ' . $block['blockName']);
			}

			// Check if this is an ACF block
			if ($this->is_acf_block($block['blockName']) && in_array($block['blockName'], $acf_block_names, true)) {
				if ($debug_mode) {
					error_log('STLS: Found ACF block: ' . $block['blockName']);
				}
				$block_id = isset($block['attrs']['id']) ? $block['attrs']['id'] : '';
				$block_name = str_replace('acf/', '', $block['blockName']);

				// Get ACF field values for this block
				// ACF blocks store field data in post meta, referenced by block attributes
				$block_fields = array();

				if (function_exists('get_field_objects')) {
					// Get all post meta to find ACF fields related to this block
					$all_meta = get_post_meta($post_id);

					// Get all ACF field groups for this block type
					$acf_field_groups = acf_get_field_groups(array('block' => $block_name));

					// Extract field keys from field groups
					$field_keys = array();
					foreach ($acf_field_groups as $field_group) {
						$fields = acf_get_fields($field_group);
						if ($fields) {
							foreach ($fields as $field) {
								if (isset($field['key'])) {
									$field_keys[] = $field['key'];
								}
							}
						}
					}

					// Get field values for this block
					// ACF stores block fields with their field keys, and values are stored separately
					// For ACF blocks, we need to use the block ID context
					foreach ($field_keys as $field_key) {
						// Get field value using ACF's get_field
						// For blocks, we need to check both by field key and by field name
						$field_object = get_field_object($field_key, $post_id);

						if ($field_object) {
							$field_name = isset($field_object['name']) ? $field_object['name'] : '';

							// Try to get field value by field key first (with block context if available)
							$field_value = get_field($field_key, $post_id);

							// If empty, try by field name (for newly uploaded images)
							if (empty($field_value) && !empty($field_name)) {
								$field_value = get_field($field_name, $post_id);
							}

							// Also check meta directly - ACF blocks may store data with block ID suffix
							if (empty($field_value) && isset($all_meta[$field_key])) {
								$meta_value = maybe_unserialize(is_array($all_meta[$field_key]) ? $all_meta[$field_key][0] : $all_meta[$field_key]);
								if (!empty($meta_value)) {
									$field_value = $meta_value;
								}
							}

							// Also check if field is stored with block ID suffix (for block-specific fields)
							if (empty($field_value) && !empty($block_id)) {
								$block_specific_key = $field_key . '_' . $block_id;
								if (isset($all_meta[$block_specific_key])) {
									$meta_value = maybe_unserialize(is_array($all_meta[$block_specific_key]) ? $all_meta[$block_specific_key][0] : $all_meta[$block_specific_key]);
									if (!empty($meta_value)) {
										$field_value = $meta_value;
									}
								}
							}

							if (!empty($field_value)) {
								// Convert image fields to URLs
								$processed_value = $field_value;
								if (isset($field_object['type']) && $field_object['type'] === 'image') {
									$processed_value = $this->convert_image_id_to_data($field_value);
								} elseif (is_array($field_value)) {
									// Recursively process nested fields (repeaters, groups, etc.)
									// This catches images in nested structures
									$processed_value = $this->convert_acf_image_fields_to_urls($field_value);
								}

								$block_fields[$field_key] = array(
									'key' => $field_key,
									'name' => $field_name,
									'value' => $processed_value,
									'original_value' => $field_value,
									'type' => isset($field_object['type']) ? $field_object['type'] : '',
									'object' => $field_object,
								);
							}
						}
					}

					// IMPORTANT: Also scan ALL post meta for ACF fields related to this block
					// This catches newly uploaded images that might not be in field groups yet
					foreach ($all_meta as $meta_key => $meta_values) {
						// Skip if already processed
						if (isset($block_fields[$meta_key])) {
							continue;
						}

						// Check if this meta key is an ACF field (starts with 'field_' or contains block ID)
						$is_acf_field = (strpos($meta_key, 'field_') === 0);
						$has_block_id = (!empty($block_id) && strpos($meta_key, '_' . $block_id) !== false);

						if ($is_acf_field || $has_block_id) {
							$field_value = maybe_unserialize(is_array($meta_values) ? $meta_values[0] : $meta_values);

							// Try to get field object
							$field_object = get_field_object($meta_key, $post_id);

							// If field object doesn't exist, check if it's an image attachment ID
							if (!$field_object && is_numeric($field_value) && $field_value > 0) {
								$attachment = get_post($field_value);
								if ($attachment && $attachment->post_type === 'attachment') {
									$mime_type = get_post_mime_type($attachment->ID);
									// Handle all file types (images, videos, PDFs, etc.)
									if (!empty($mime_type)) {
										// It's a file attachment, create field data
										$block_fields[$meta_key] = array(
											'key' => $meta_key,
											'name' => str_replace('field_', '', $meta_key),
											'value' => $this->convert_image_id_to_data($field_value),
											'original_value' => $field_value,
											'type' => 'file', // Changed from 'image' to 'file' to support all types
										);

										if (defined('WP_DEBUG') && WP_DEBUG) {
											error_log('STLS: Found image attachment ID in block meta (no field object) - Key: ' . $meta_key . ', ID: ' . $field_value);
										}
										continue;
									}
								}
							}

							if ($field_object) {
								// Convert image fields to URLs
								$processed_value = $field_value;
								if (isset($field_object['type']) && $field_object['type'] === 'image') {
									$processed_value = $this->convert_image_id_to_data($field_value);
								} elseif (is_array($field_value)) {
									// Recursively process nested fields (repeaters, groups, etc.)
									// This catches images in nested structures, including newly uploaded ones
									$processed_value = $this->convert_acf_image_fields_to_urls($field_value);
								}

								$block_fields[$meta_key] = array(
									'key' => $meta_key,
									'name' => isset($field_object['name']) ? $field_object['name'] : '',
									'value' => $processed_value,
									'original_value' => $field_value,
									'type' => isset($field_object['type']) ? $field_object['type'] : '',
									'object' => $field_object,
								);
							} elseif (is_array($field_value)) {
								// Field object doesn't exist (newly uploaded), but value is an array
								// Recursively process to find images
								$processed_value = $this->convert_acf_image_fields_to_urls($field_value);

								$block_fields[$meta_key] = array(
									'key' => $meta_key,
									'name' => str_replace(array('field_', '_' . $block_id), '', $meta_key),
									'value' => $processed_value,
									'original_value' => $field_value,
									'type' => 'unknown',
								);
							}
						}
					}

					// Also check for ACF return format fields (array format with url, alt, etc.)
					// These might be stored differently for newly uploaded images
					foreach ($all_meta as $meta_key => $meta_values) {
						if (isset($block_fields[$meta_key])) {
							continue;
						}

						$field_value = maybe_unserialize(is_array($meta_values) ? $meta_values[0] : $meta_values);

						// Check if value is an array with image data (ACF return format)
						if (is_array($field_value) && isset($field_value['ID'])) {
							$attachment_id = $field_value['ID'];
							$attachment = get_post($attachment_id);
							if ($attachment && $attachment->post_type === 'attachment') {
								$mime_type = get_post_mime_type($attachment->ID);
								// Handle all file types (images, videos, PDFs, etc.)
								if (!empty($mime_type)) {
									// Convert ACF array format to file data
									$block_fields[$meta_key] = array(
										'key' => $meta_key,
										'name' => str_replace(array('field_', '_' . $block_id), '', $meta_key),
										'value' => array(
											'attachment_id' => $attachment_id,
											'url' => isset($field_value['url']) ? $field_value['url'] : wp_get_attachment_url($attachment_id),
											'alt' => isset($field_value['alt']) ? $field_value['alt'] : get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
											'title' => isset($field_value['title']) ? $field_value['title'] : $attachment->post_title,
											'caption' => isset($field_value['caption']) ? $field_value['caption'] : $attachment->post_excerpt,
											'description' => isset($field_value['description']) ? $field_value['description'] : $attachment->post_content,
											'mime_type' => $mime_type,
										),
										'original_value' => $field_value,
										'type' => 'image',
									);
								}
							}
						}
					}

					// Final pass: Recursively process all block fields to ensure nested images are converted
					// This catches any images that might have been missed in the previous passes
					foreach ($block_fields as $field_key => $field_data) {
						if (isset($field_data['value']) && is_array($field_data['value'])) {
							// Recursively process nested structures
							$block_fields[$field_key]['value'] = $this->convert_acf_image_fields_to_urls($field_data['value']);
						}
					}
				}

				// Process block attributes for images (some blocks store image IDs in attributes)
				// ACF blocks often store field values in attrs['data'] array
				$processed_attrs = isset($block['attrs']) ? $block['attrs'] : array();
				if (!empty($processed_attrs)) {
					// Check if there's a 'data' array within attributes (ACF block format)
					if (isset($processed_attrs['data']) && is_array($processed_attrs['data'])) {
						foreach ($processed_attrs['data'] as $data_key => $data_value) {
							// Skip field keys (they start with underscore)
							if (strpos($data_key, '_') === 0) {
								continue;
							}

							// Check if value is an attachment ID (image)
							if (is_numeric($data_value) && $data_value > 0) {
								$attachment = get_post($data_value);
								if ($attachment && $attachment->post_type === 'attachment') {
									$mime_type = get_post_mime_type($attachment->ID);
									// Handle all file types (images, videos, PDFs, etc.)
									if (!empty($mime_type)) {
										// Convert attachment ID to file data
										$processed_attrs['data'][$data_key] = $this->convert_image_id_to_data($data_value);

										if (defined('WP_DEBUG') && WP_DEBUG) {
											error_log('STLS: Found file in block data attribute - Key: ' . $data_key . ', ID: ' . $data_value . ', MIME: ' . $mime_type);
										}
									}
								}
							} elseif (is_array($data_value)) {
								// Recursively process arrays in data
								$processed_attrs['data'][$data_key] = $this->convert_acf_image_fields_to_urls($data_value);
							}
						}
					}

					// Also check top-level attributes (for blocks that don't use 'data' array)
					foreach ($processed_attrs as $attr_key => $attr_value) {
						// Skip 'data' as we already processed it
						if ($attr_key === 'data') {
							continue;
						}

						// Check if attribute value is an attachment ID (image)
						if (is_numeric($attr_value) && $attr_value > 0) {
							$attachment = get_post($attr_value);
							if ($attachment && $attachment->post_type === 'attachment') {
								$mime_type = get_post_mime_type($attachment->ID);
								// Handle all file types (images, videos, PDFs, etc.)
								if (!empty($mime_type)) {
									// Convert attachment ID to file data
									$processed_attrs[$attr_key] = $this->convert_image_id_to_data($attr_value);

									if (defined('WP_DEBUG') && WP_DEBUG) {
										error_log('STLS: Found file in block attribute - Key: ' . $attr_key . ', ID: ' . $attr_value . ', MIME: ' . $mime_type);
									}
								}
							}
						} elseif (is_array($attr_value)) {
							// Recursively process arrays in attributes
							$processed_attrs[$attr_key] = $this->convert_acf_image_fields_to_urls($attr_value);
						}
					}
				}

				// Store block data
				$acf_blocks_data[] = array(
					'blockName' => $block['blockName'],
					'blockId' => $block_id,
					'blockName_slug' => $block_name,
					'attrs' => $processed_attrs,
					'innerHTML' => isset($block['innerHTML']) ? $block['innerHTML'] : '',
					'innerContent' => isset($block['innerContent']) ? $block['innerContent'] : array(),
					'fields' => $block_fields,
				);
			}

			// Recursively check inner blocks
			if (!empty($block['innerBlocks'])) {
				if ($debug_mode) {
					error_log('STLS: Checking ' . count($block['innerBlocks']) . ' inner blocks');
				}
				$this->extract_acf_blocks_recursive($block['innerBlocks'], $acf_block_names, $post_id, $acf_blocks_data);
			}
		}
	}

	/**
	 * Convert ACF image fields from attachment IDs to URLs and metadata
	 */
	private function convert_acf_image_fields_to_urls($acf_fields)
	{
		if (empty($acf_fields) || !is_array($acf_fields)) {
			return $acf_fields;
		}

		foreach ($acf_fields as $key => $value) {
			if (is_array($value)) {
				// If already processed image data, skip
				if (isset($value['attachment_id']) && isset($value['url'])) {
					$acf_fields[$key] = $value;
					continue;
				}

				// Check if this is an ACF image array format (has 'ID' key)
				if (isset($value['ID']) && is_numeric($value['ID'])) {
					$attachment_id = $value['ID'];
					$attachment = get_post($attachment_id);
					if ($attachment && $attachment->post_type === 'attachment') {
						$mime_type = get_post_mime_type($attachment_id);
						// Handle all file types (images, videos, PDFs, etc.)
						if (!empty($mime_type)) {
							// Convert ACF array format to our file data format
							$acf_fields[$key] = array(
								'attachment_id' => $attachment_id,
								'url' => isset($value['url']) ? $value['url'] : wp_get_attachment_url($attachment_id),
								'alt' => isset($value['alt']) ? $value['alt'] : get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
								'title' => isset($value['title']) ? $value['title'] : $attachment->post_title,
								'caption' => isset($value['caption']) ? $value['caption'] : $attachment->post_excerpt,
								'description' => isset($value['description']) ? $value['description'] : $attachment->post_content,
								'mime_type' => $mime_type,
							);

							if (defined('WP_DEBUG') && WP_DEBUG) {
								error_log('STLS: Converted ACF image array format - Key: ' . $key . ', ID: ' . $attachment_id);
							}
							continue;
						}
					}
				}

				// Recursively process nested arrays
				$acf_fields[$key] = $this->convert_acf_image_fields_to_urls($value);
			} elseif (is_numeric($value)) {
				// Check if this is an attachment ID (image)
				$attachment = get_post($value);
				if ($attachment && $attachment->post_type === 'attachment') {
					$mime_type = get_post_mime_type($attachment->ID);
					// Handle all file types (images, videos, PDFs, etc.)
					if (!empty($mime_type)) {
						// It's a file attachment, convert to URL and metadata
						$acf_fields[$key] = $this->convert_image_id_to_data($value);

						if (defined('WP_DEBUG') && WP_DEBUG) {
							error_log('STLS: Converted numeric file ID in ACF field - Key: ' . $key . ', ID: ' . $value . ', MIME: ' . $mime_type);
						}
					}
				}
			} elseif (is_string($value) && $this->is_image_url($value)) {
				$acf_fields[$key] = $this->convert_image_url_to_data($value);

				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS: Converted image URL in ACF field - Key: ' . $key . ', URL: ' . $value);
				}
			}
		}

		return $acf_fields;
	}

	/**
	 * Convert ACF field objects to include image URLs
	 */
	private function convert_acf_field_objects_images($field_objects)
	{
		if (empty($field_objects) || !is_array($field_objects)) {
			return $field_objects;
		}

		foreach ($field_objects as $field_key => $field_object) {
			if (isset($field_object['type']) && $field_object['type'] === 'image') {
				$value = isset($field_object['value']) ? $field_object['value'] : null;

				if (is_numeric($value)) {
					// Single image field
					$attachment = get_post($value);
					if ($attachment && $attachment->post_type === 'attachment') {
						$image_data = $this->convert_image_id_to_data($value);
						$field_objects[$field_key]['image_data'] = $image_data;
						$field_objects[$field_key]['value'] = $image_data;
					}
				} elseif (is_array($value) && isset($value['ID'])) {
					// Image field with array format
					$attachment_id = $value['ID'];
					$attachment = get_post($attachment_id);
					if ($attachment && $attachment->post_type === 'attachment') {
						$image_data = $this->convert_image_id_to_data($attachment_id);
						$image_data['original_array'] = $value;
						$field_objects[$field_key]['image_data'] = $image_data;
						$field_objects[$field_key]['value'] = $image_data;
					}
				} elseif (is_string($value) && $this->is_image_url($value)) {
					$image_data = $this->convert_image_url_to_data($value);
					$field_objects[$field_key]['image_data'] = $image_data;
					$field_objects[$field_key]['value'] = $image_data;
				}
			} elseif (isset($field_object['value']) && is_array($field_object['value'])) {
				// Recursively process nested fields
				$field_objects[$field_key]['value'] = $this->convert_acf_image_fields_to_urls($field_object['value']);
			}
		}

		return $field_objects;
	}

	/**
	 * Process all meta to find and convert images
	 */
	/**
	 * Process all meta fields for images and prepare for sync
	 * 
	 * @param array $all_meta All post meta
	 * @param array $exclude_keys Keys to exclude from processing (e.g., ACF fields handled separately)
	 * @return array Processed meta
	 */
	private function process_meta_for_images($all_meta, $exclude_keys = array())
	{
		$processed_meta = array();

		foreach ($all_meta as $meta_key => $meta_values) {
			// Skip internal WordPress meta (except page template)
			if (strpos($meta_key, '_wp_') === 0 && $meta_key !== '_wp_page_template') {
				continue;
			}

			// Skip Yoast SEO meta (handled separately)
			if (strpos($meta_key, '_yoast_wpseo_') === 0) {
				continue;
			}

			// Skip ACF fields (handled separately)
			if (in_array($meta_key, $exclude_keys) || strpos($meta_key, 'field_') === 0) {
				continue;
			}

			// Skip ACF field references (they start with field_ and may have block IDs appended)
			$is_acf_field = false;
			foreach ($exclude_keys as $acf_key) {
				if (strpos($meta_key, $acf_key) !== false) {
					$is_acf_field = true;
					break;
				}
			}
			if ($is_acf_field) {
				continue;
			}

			$processed_values = array();
			$values_array = is_array($meta_values) ? $meta_values : array($meta_values);

			foreach ($values_array as $meta_value) {
				$unserialized = maybe_unserialize($meta_value);

				// Check if this is an image attachment ID
				if (is_numeric($unserialized)) {
					$attachment = get_post($unserialized);
					if ($attachment && $attachment->post_type === 'attachment') {
						$mime_type = get_post_mime_type($attachment->ID);
						// Handle all file types (images, videos, PDFs, etc.)
						if (!empty($mime_type)) {
							// Convert to file data
							$processed_values[] = $this->convert_image_id_to_data($unserialized);
							continue;
						}
					}
				}

				// Check if this is an ACF image array format
				if (is_array($unserialized) && isset($unserialized['ID'])) {
					$attachment_id = $unserialized['ID'];
					$attachment = get_post($attachment_id);
					if ($attachment && $attachment->post_type === 'attachment') {
						$mime_type = get_post_mime_type($attachment->ID);
						// Handle all file types (images, videos, PDFs, etc.)
						if (!empty($mime_type)) {
							// Convert ACF array to file data
							$processed_values[] = array(
								'attachment_id' => $attachment_id,
								'url' => isset($unserialized['url']) ? $unserialized['url'] : wp_get_attachment_url($attachment_id),
								'alt' => isset($unserialized['alt']) ? $unserialized['alt'] : get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
								'title' => isset($unserialized['title']) ? $unserialized['title'] : $attachment->post_title,
								'caption' => isset($unserialized['caption']) ? $unserialized['caption'] : $attachment->post_excerpt,
								'description' => isset($unserialized['description']) ? $unserialized['description'] : $attachment->post_content,
								'mime_type' => $mime_type,
							);
							continue;
						}
					}
				}

				// Check if this is a direct image URL
				if (is_string($unserialized) && $this->is_image_url($unserialized)) {
					$processed_values[] = $this->convert_image_url_to_data($unserialized);
					continue;
				}

				// Check if array contains nested image IDs
				if (is_array($unserialized)) {
					$processed_unserialized = $this->process_array_for_images($unserialized);
					$processed_values[] = $processed_unserialized;
					continue;
				}

				// Keep original value if not an image
				$processed_values[] = $meta_value;
			}

			$processed_meta[$meta_key] = count($processed_values) === 1 ? $processed_values[0] : $processed_values;
		}

		return $processed_meta;
	}

	/**
	 * Recursively process arrays to find images
	 */
	private function process_array_for_images($array)
	{
		if (!is_array($array)) {
			return $array;
		}

		foreach ($array as $key => $value) {
			// Check if numeric (attachment ID)
			if (is_numeric($value)) {
				$attachment = get_post($value);
				if ($attachment && $attachment->post_type === 'attachment') {
					$mime_type = get_post_mime_type($attachment->ID);
					// Handle all file types (images, videos, PDFs, etc.)
					if (!empty($mime_type)) {
						$array[$key] = $this->convert_image_id_to_data($value);
					}
				}
			}
			// Check if string URL pointing to an image
			elseif (is_string($value) && $this->is_image_url($value)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS: Found URL-format image in repeater - Key: ' . $key . ', URL: ' . $value);
				}
				$array[$key] = $this->convert_image_url_to_data($value);
			}
			// Recursively process nested arrays
			elseif (is_array($value)) {
				if (isset($value['attachment_id']) && isset($value['url'])) {
					$array[$key] = $value;
					continue;
				}
				$array[$key] = $this->process_array_for_images($value);
			}
		}

		return $array;
	}

	/**
	 * Check if a string is an image URL from this site
	 */
	private function is_image_url($url)
	{
		if (!is_string($url) || empty($url)) {
			return false;
		}

		// Check if it's a URL from wp-content/uploads
		if (strpos($url, '/wp-content/uploads/') === false) {
			return false;
		}

		// Check if it has a file extension (supports all file types: images, videos, PDFs, etc.)
		$extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));

		// Return true if it has any extension (it's a file)
		return !empty($extension);
	}

	/**
	 * Get attachment ID from image URL
	 */
	private function get_attachment_id_from_url($url)
	{
		// Try WordPress built-in function first
		$attachment_id = attachment_url_to_postid($url);

		if ($attachment_id) {
			return $attachment_id;
		}

		// Fallback: Search by filename
		$filename = basename($url);

		// Remove size suffix (e.g., -300x200)
		$filename = preg_replace('/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp|svg|bmp|ico)$)/i', '', $filename);

		global $wpdb;
		$attachment_id = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s",
			'%' . $wpdb->esc_like($filename)
		));

		if ($attachment_id) {
			return intval($attachment_id);
		}

		// Fallback: Search in attachment file meta
		$uploads = wp_get_upload_dir();
		if (!empty($uploads['baseurl']) && strpos($url, $uploads['baseurl']) !== false) {
			$relative_path = str_replace(trailingslashit($uploads['baseurl']), '', $url);
			$relative_path_no_size = preg_replace('/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp|svg|bmp|ico)$)/i', '', $relative_path);
			$attachment_id = $wpdb->get_var($wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN (%s, %s) LIMIT 1",
				$relative_path,
				$relative_path_no_size
			));
		}

		if ($attachment_id) {
			return intval($attachment_id);
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('STLS: Unable to find attachment ID for URL: ' . $url);
		}

		return $attachment_id ? intval($attachment_id) : 0;
	}

	/**
	 * Get Yoast SEO data for a post
	 * 
	 * @param int $post_id Post ID
	 * @return array Yoast SEO meta data
	 */
	private function get_yoast_seo_data($post_id)
	{
		$yoast_data = array();

		// Get all post meta
		$all_meta = get_post_meta($post_id);

		// List of Yoast SEO meta keys to sync
		$yoast_keys = array(
			// Basic SEO
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_breadcrumbs_title',

			// Robots meta
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_meta-robots-adv',
			'_yoast_wpseo_meta-robots-primary-category',

			// Open Graph
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_opengraph-image-id',

			// Twitter
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
			'_yoast_wpseo_twitter-image',
			'_yoast_wpseo_twitter-image-id',

			// Schema
			'_yoast_wpseo_schema_page_type',
			'_yoast_wpseo_schema_article_type',

			// Other
			'_yoast_wpseo_content_score',
			'_yoast_wpseo_estimated-reading-time-minutes',
			'_yoast_wpseo_wordproof_timestamp',
			'_yoast_wpseo_primary_category',
			'_yoast_wpseo_primary_product_cat',
			'_yoast_wpseo_primary_product_tag',
		);

		// Also check for any other Yoast meta keys
		foreach ($all_meta as $meta_key => $meta_values) {
			if (strpos($meta_key, '_yoast_wpseo_') === 0) {
				$yoast_keys[] = $meta_key;
			}
		}

		// Remove duplicates
		$yoast_keys = array_unique($yoast_keys);

		// Collect Yoast SEO meta values
		foreach ($yoast_keys as $key) {
			if (isset($all_meta[$key])) {
				$value = maybe_unserialize(is_array($all_meta[$key]) ? $all_meta[$key][0] : $all_meta[$key]);

				// Handle image fields - convert attachment IDs to image data
				if (in_array($key, array('_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_twitter-image-id'))) {
					if (is_numeric($value) && $value > 0) {
						$yoast_data[$key] = $this->convert_image_id_to_data($value);
					} else {
						$yoast_data[$key] = $value;
					}
				} elseif (in_array($key, array('_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image'))) {
					// These might be URLs or IDs, handle both
					if (is_numeric($value) && $value > 0) {
						$yoast_data[$key] = $this->convert_image_id_to_data($value);
					} elseif (is_string($value) && $this->is_image_url($value)) {
						$attachment_id = $this->get_attachment_id_from_url($value);
						if ($attachment_id) {
							$image_data = $this->convert_image_id_to_data($attachment_id);
							if (is_array($image_data)) {
								$image_data['was_url'] = true;
							}
							$yoast_data[$key] = $image_data;
						} else {
							$yoast_data[$key] = $value; // Keep as URL if attachment not found
						}
					} else {
						$yoast_data[$key] = $value;
					}
				} else {
					$yoast_data[$key] = $value;
				}
			}
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('STLS: Collected ' . count($yoast_data) . ' Yoast SEO meta fields for post ' . $post_id);
		}

		return $yoast_data;
	}

	/**
	 * Convert image URL to data array with metadata
	 */
	private function convert_image_url_to_data($url)
	{
		if (empty($url)) {
			return $url;
		}

		$attachment_id = $this->get_attachment_id_from_url($url);
		$image_data = null;

		if ($attachment_id) {
			$image_data = $this->convert_image_id_to_data($attachment_id);
		} else {
			$image_data = array(
				'attachment_id' => 0,
				'url' => $url,
				'alt' => '',
				'title' => '',
				'caption' => '',
				'description' => '',
				'mime_type' => '',
			);
		}

		if (is_array($image_data)) {
			$image_data['was_url'] = true;
		}

		return $image_data;
	}

	/**
	 * Convert image attachment ID to image data array
	 */
	private function convert_image_id_to_data($image_id)
	{
		if (empty($image_id)) {
			return null;
		}

		// Handle array format (ACF sometimes returns arrays)
		if (is_array($image_id) && isset($image_id['ID'])) {
			$image_id = $image_id['ID'];
		}

		if (!is_numeric($image_id)) {
			return $image_id;
		}

		$attachment = get_post($image_id);
		if (!$attachment || $attachment->post_type !== 'attachment') {
			return $image_id;
		}

		$mime_type = get_post_mime_type($attachment->ID);
		// Handle all file types (images, videos, PDFs, etc.)
		// No need to restrict to images only

		// Get file URL - use wp_get_attachment_url for full URL
		$file_url = wp_get_attachment_url($image_id);

		// If URL is relative, make it absolute
		if (!empty($file_url) && strpos($file_url, 'http') !== 0) {
			$file_url = home_url($file_url);
		}

		// Return file data (works for images, videos, PDFs, etc.)
		$file_data = array(
			'attachment_id' => $image_id,
			'url' => $file_url,
			'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
			'title' => $attachment->post_title,
			'caption' => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'mime_type' => $mime_type,
		);

		// Debug logging
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('STLS File Conversion: ID=' . $image_id . ', URL=' . $file_url . ', MIME=' . $mime_type);
		}

		return $file_data;
	}

	/**
	 * Generate secure random API key
	 */
	private function generate_secure_api_key()
	{
		// Use WordPress's wp_generate_password for cryptographically secure random string
		// 64 characters with special characters for strong security
		$api_key = wp_generate_password(64, true, true);

		// Alternative method if wp_generate_password is not sufficient
		// Combine multiple random sources for extra security
		if (function_exists('random_bytes')) {
			$random_bytes = random_bytes(32);
			$api_key = bin2hex($random_bytes) . wp_generate_password(32, true, true);
		}

		return $api_key;
	}

	/**
	 * AJAX handler for syncing post
	 */
	public function ajax_sync_post()
	{
		// Verify nonce
		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

		if (!$post_id || !isset($_POST['nonce'])) {
			wp_send_json_error(array('message' => __('Invalid request.', 'staging-to-live-sync')));
		}

		if (!wp_verify_nonce($_POST['nonce'], 'stls_sync_' . $post_id)) {
			wp_send_json_error(array('message' => __('Security check failed.', 'staging-to-live-sync')));
		}

		// Check permissions
		if (!current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('You do not have permission to sync this post.', 'staging-to-live-sync')));
		}

		// Get post to check post type
		$post = get_post($post_id);
		if (!$post) {
			wp_send_json_error(array('message' => __('Post not found.', 'staging-to-live-sync')));
		}

		// Check if this post type is disabled for syncing
		if ($this->is_post_type_disabled($post->post_type)) {
			wp_send_json_error(array('message' => __('Sync is disabled for this post type.', 'staging-to-live-sync')));
		}

		// Get URLs
		$staging_url = get_option('stls_staging_url');
		$live_url = get_option('stls_live_url');

		if (empty($staging_url) || empty($live_url)) {
			wp_send_json_error(array('message' => __('Staging or Live URL not configured. Please configure in Settings > Staging to Live Sync.', 'staging-to-live-sync')));
		}

		// Enable debug mode if WP_DEBUG is on
		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		// Perform sync
		$result = $this->sync_post_to_live($post_id, $staging_url, $live_url);

		if (is_wp_error($result)) {
			if ($debug_mode) {
				error_log('STLS Sync Error for Post ' . $post_id . ': ' . $result->get_error_message());
			}
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		// Log sync activity
		$this->log_sync_activity($post_id);

		if ($debug_mode) {
			error_log('STLS Sync Success for Post ' . $post_id);
		}

		wp_send_json_success(array('message' => __('Post synced successfully!', 'staging-to-live-sync')));
	}

	/**
	 * AJAX handler for syncing files
	 */
	public function ajax_sync_files()
	{
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'stls_sync_files')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'staging-to-live-sync')));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to sync files.', 'staging-to-live-sync')));
		}

		// Get URLs
		$staging_url = get_option('stls_staging_url');
		$live_url = get_option('stls_live_url');

		if (empty($staging_url) || empty($live_url)) {
			wp_send_json_error(array('message' => __('Staging or Live URL not configured. Please configure in Settings > Staging to Live Sync.', 'staging-to-live-sync')));
		}

		// Ensure URLs are properly formatted (remove trailing slashes)
		$staging_url = untrailingslashit($staging_url);
		$live_url = untrailingslashit($live_url);

		// Validate URLs
		if (!filter_var($staging_url, FILTER_VALIDATE_URL) || !filter_var($live_url, FILTER_VALIDATE_URL)) {
			wp_send_json_error(array('message' => __('Invalid staging or live URL configured. Please check your settings.', 'staging-to-live-sync')));
		}

		// Get files from request
		$files_json = isset($_POST['files']) ? sanitize_text_field($_POST['files']) : '';
		$files = json_decode(stripslashes($files_json), true);

		if (empty($files) || !is_array($files)) {
			wp_send_json_error(array('message' => __('No files selected for sync.', 'staging-to-live-sync')));
		}

		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;
		$results = array();
		$success_count = 0;
		$error_count = 0;

		// Get live site API key
		$live_api_key = get_option('stls_live_api_key');
		if (empty($live_api_key)) {
			wp_send_json_error(array('message' => __('Live API key not configured. Please configure in Settings.', 'staging-to-live-sync')));
		}

		// Sync each file
		foreach ($files as $file_data) {
			$file_path = isset($file_data['path']) ? $file_data['path'] : '';
			$file_name = isset($file_data['name']) ? $file_data['name'] : basename($file_path);

			if (empty($file_path)) {
				$results[] = array(
					'file' => $file_name,
					'success' => false,
					'message' => __('Invalid file path', 'staging-to-live-sync')
				);
				$error_count++;
				continue;
			}

			// Build full file path
			$wp_content_dir = WP_CONTENT_DIR;
			$full_file_path = $wp_content_dir . DIRECTORY_SEPARATOR . $file_path;

			// Check if file exists
			if (!file_exists($full_file_path) || !is_readable($full_file_path)) {
				$results[] = array(
					'file' => $file_name,
					'success' => false,
					'message' => __('File not found or not readable', 'staging-to-live-sync')
				);
				$error_count++;
				continue;
			}

			// Build staging file URL
			// Normalize file path (replace backslashes with forward slashes)
			$normalized_path = str_replace('\\', '/', $file_path);
			// Remove any leading slashes
			$normalized_path = ltrim($normalized_path, '/');

			if ($debug_mode) {
				error_log('STLS File Sync [STAGING]: Building URL for file - Name: ' . $file_name . ', Original path: ' . $file_path . ', Normalized path: ' . $normalized_path);
				error_log('STLS File Sync [STAGING]: Staging URL: ' . $staging_url);
			}

			// Build URL - WordPress download_url handles encoding, but we need to ensure it's a valid URL
			// Only encode if there are special characters that need encoding
			$staging_file_url = trailingslashit($staging_url) . 'wp-content/' . $normalized_path;

			if ($debug_mode) {
				error_log('STLS File Sync [STAGING]: Built URL (before validation): ' . $staging_file_url);
				error_log('STLS File Sync [STAGING]: URL validation (filter_var): ' . var_export(filter_var($staging_file_url, FILTER_VALIDATE_URL), true));
			}

			// Validate URL format first
			if (!filter_var($staging_file_url, FILTER_VALIDATE_URL)) {
				if ($debug_mode) {
					error_log('STLS File Sync [STAGING]: URL validation failed, trying with encoding...');
				}
				// Try with URL encoding if validation fails
				$path_segments = explode('/', $normalized_path);
				$encoded_segments = array_map('rawurlencode', $path_segments);
				$encoded_path = implode('/', $encoded_segments);
				$staging_file_url = trailingslashit($staging_url) . 'wp-content/' . $encoded_path;

				if ($debug_mode) {
					error_log('STLS File Sync [STAGING]: Built URL (with encoding): ' . $staging_file_url);
					error_log('STLS File Sync [STAGING]: URL validation (filter_var) after encoding: ' . var_export(filter_var($staging_file_url, FILTER_VALIDATE_URL), true));
				}

				// Re-validate
				if (!filter_var($staging_file_url, FILTER_VALIDATE_URL)) {
					$results[] = array(
						'file' => $file_name,
						'success' => false,
						'message' => __('Invalid file URL generated: ', 'staging-to-live-sync') . $staging_file_url
					);
					$error_count++;

					if ($debug_mode) {
						error_log('STLS File Sync [STAGING]: Invalid URL for file - ' . $file_name . ', Original path: ' . $file_path . ', Final URL: ' . $staging_file_url);
					}
					continue;
				}
			}

			if ($debug_mode) {
				error_log('STLS File Sync [STAGING]: Final URL to send: ' . $staging_file_url);
			}

			// Sync file to live site
			$sync_result = $this->sync_file_to_live($staging_file_url, $file_path, $live_url, $live_api_key);

			if (is_wp_error($sync_result)) {
				$results[] = array(
					'file' => $file_name,
					'success' => false,
					'message' => $sync_result->get_error_message()
				);
				$error_count++;

				if ($debug_mode) {
					error_log('STLS File Sync Error: ' . $file_name . ' - ' . $sync_result->get_error_message());
				}
			} else {
				$results[] = array(
					'file' => $file_name,
					'success' => true,
					'message' => __('File synced successfully', 'staging-to-live-sync')
				);
				$success_count++;

				if ($debug_mode) {
					error_log('STLS File Sync Success: ' . $file_name);
				}
			}
		}

		$message = sprintf(
			__('Synced %d file(s) successfully. %d file(s) failed.', 'staging-to-live-sync'),
			$success_count,
			$error_count
		);

		// Log file sync activity
		$file_names = array();
		foreach ($files as $file_data) {
			$file_path = isset($file_data['path']) ? $file_data['path'] : '';
			if (!empty($file_path)) {
				$file_names[] = $file_path;
			}
		}

		$this->log_sync_activity(0, array(
			'count' => count($files),
			'success_count' => $success_count,
			'error_count' => $error_count,
			'files' => $file_names
		));

		wp_send_json_success(array(
			'message' => $message,
			'details' => $results,
			'success_count' => $success_count,
			'error_count' => $error_count
		));
	}

	/**
	 * AJAX handler to load directory contents
	 */
	public function ajax_load_directory()
	{
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'stls_load_directory')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'staging-to-live-sync')));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to load directories.', 'staging-to-live-sync')));
		}

		$dir_path = isset($_POST['dir_path']) ? sanitize_text_field($_POST['dir_path']) : '';

		if (empty($dir_path)) {
			wp_send_json_error(array('message' => __('Directory path is required.', 'staging-to-live-sync')));
		}

		// Validate that path is within wp-content
		$wp_content_dir = WP_CONTENT_DIR;
		$full_path = $wp_content_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir_path);

		// Security check: ensure path is within wp-content
		$real_wp_content = realpath($wp_content_dir);
		$real_path = realpath($full_path);

		if (!$real_path || strpos($real_path, $real_wp_content) !== 0) {
			wp_send_json_error(array('message' => __('Invalid directory path.', 'staging-to-live-sync')));
		}

		if (!is_dir($full_path) || !is_readable($full_path)) {
			wp_send_json_error(array('message' => __('Directory not found or not readable.', 'staging-to-live-sync')));
		}

		// Check if we should filter by selected themes/plugins
		$selected_items = get_option('stls_selected_themes_plugins', array());
		if (!empty($selected_items)) {
			// Check if this directory is within a selected theme/plugin
			$is_allowed = false;
			$relative_path = str_replace($wp_content_dir . DIRECTORY_SEPARATOR, '', $full_path);

			foreach ($selected_items as $item_key) {
				if (strpos($item_key, 'theme_') === 0) {
					$theme_slug = str_replace('theme_', '', $item_key);
					$theme_path = 'themes' . DIRECTORY_SEPARATOR . $theme_slug;
					if (strpos($relative_path, $theme_path) === 0) {
						$is_allowed = true;
						break;
					}
				} elseif (strpos($item_key, 'plugin_') === 0) {
					$plugin_file = str_replace('plugin_', '', $item_key);
					$plugin_dir = dirname($plugin_file);
					if ($plugin_dir === '.') {
						$plugin_path = 'plugins' . DIRECTORY_SEPARATOR . basename($plugin_file);
					} else {
						$plugin_path = 'plugins' . DIRECTORY_SEPARATOR . $plugin_dir;
					}
					if (strpos($relative_path, $plugin_path) === 0) {
						$is_allowed = true;
						break;
					}
				}
			}

			if (!$is_allowed) {
				wp_send_json_error(array('message' => __('This directory is not in the selected themes/plugins list.', 'staging-to-live-sync')));
			}
		}

		// Load directory contents (both directories and files)
		$tree = $this->build_file_tree($full_path, $wp_content_dir, true);

		// Convert to HTML
		ob_start();
		$this->render_file_tree($tree, $wp_content_dir, 0);
		$html = ob_get_clean();

		wp_send_json_success(array(
			'html' => $html,
			'count' => count($tree)
		));
	}

	/**
	 * Sync a single file to live site
	 */
	private function sync_file_to_live($staging_file_url, $file_path, $live_url, $api_key)
	{
		// Make request to live site to sync the file
		$endpoint = trailingslashit($live_url) . 'wp-json/stls/v1/sync-file';

		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		// Check if this is a PHP file - if so, read from filesystem and base64 encode
		// to avoid execution when downloading via HTTP
		$file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
		$php_extensions = array('php', 'phtml', 'php3', 'php4', 'php5', 'phps');
		$is_php_file = in_array($file_extension, $php_extensions);

		$file_content = null;
		$file_content_base64 = null;

		if ($is_php_file) {
			// Read file directly from filesystem to avoid execution
			$wp_content_dir = WP_CONTENT_DIR;
			$full_file_path = $wp_content_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file_path);

			if (file_exists($full_file_path) && is_readable($full_file_path)) {
				$file_content = file_get_contents($full_file_path);
				if ($file_content !== false) {
					$file_content_base64 = base64_encode($file_content);
					if ($debug_mode) {
						error_log('STLS File Sync [STAGING]: PHP file detected, reading from filesystem and encoding');
						error_log('STLS File Sync [STAGING]: File size: ' . strlen($file_content) . ' bytes');
						error_log('STLS File Sync [STAGING]: Encoded size: ' . strlen($file_content_base64) . ' bytes');
					}
				} else {
					if ($debug_mode) {
						error_log('STLS File Sync [STAGING]: Failed to read PHP file from filesystem');
					}
				}
			} else {
				if ($debug_mode) {
					error_log('STLS File Sync [STAGING]: PHP file not found or not readable: ' . $full_file_path);
				}
			}
		}

		if ($debug_mode) {
			error_log('STLS File Sync [STAGING]: Sending request to live site');
			error_log('STLS File Sync [STAGING]: Endpoint: ' . $endpoint);
			error_log('STLS File Sync [STAGING]: File URL: ' . $staging_file_url);
			error_log('STLS File Sync [STAGING]: File Path: ' . $file_path);
			error_log('STLS File Sync [STAGING]: Is PHP file: ' . var_export($is_php_file, true));
			error_log('STLS File Sync [STAGING]: Has file content: ' . var_export(!empty($file_content_base64), true));
		}

		// Build request body
		$request_body = array(
			'file_url' => $staging_file_url,
			'file_path' => $file_path,
		);

		// If we have file content (for PHP files), include it
		if (!empty($file_content_base64)) {
			$request_body['file_content_base64'] = $file_content_base64;
			$request_body['is_php_file'] = true;
		}

		$response = wp_remote_post($endpoint, array(
			'timeout' => 300, // 5 minutes timeout for large files
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-STLS-API-Key' => $api_key,
			),
			'body' => json_encode($request_body),
		));

		if (is_wp_error($response)) {
			if ($debug_mode) {
				error_log('STLS File Sync [STAGING]: Request error: ' . $response->get_error_message());
			}
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		if ($debug_mode) {
			error_log('STLS File Sync [STAGING]: Response code: ' . $response_code);
			error_log('STLS File Sync [STAGING]: Response body: ' . $response_body);
			error_log('STLS File Sync [STAGING]: Response data: ' . print_r($response_data, true));
		}

		if ($response_code !== 200) {
			// Try to extract error message from various possible locations
			// WordPress REST API formats WP_Error as: { "code": "...", "message": "...", "data": {...} }
			$error_message = __('Failed to sync file', 'staging-to-live-sync');
			$error_details = array();

			// Check for WordPress REST API error format
			if (isset($response_data['code']) && isset($response_data['message'])) {
				$error_message = $response_data['message'];
				if (isset($response_data['data']['status'])) {
					$error_message .= ' (HTTP ' . $response_data['data']['status'] . ')';
				}
				// Extract additional details if available
				if (isset($response_data['data']['details'])) {
					$error_details = $response_data['data']['details'];
					// Add details to error message if they contain useful info
					if (is_array($error_details)) {
						$detail_messages = array();
						if (isset($error_details['php_error']['message'])) {
							$detail_messages[] = $error_details['php_error']['message'];
						}
						if (isset($error_details['temp_dir_writable']) && !$error_details['temp_dir_writable']) {
							$detail_messages[] = 'Temp directory not writable';
						}
						if (isset($error_details['target_dir_writable']) && !$error_details['target_dir_writable']) {
							$detail_messages[] = 'Target directory not writable';
						}
						if (!empty($detail_messages)) {
							$error_message .= ' - ' . implode(', ', $detail_messages);
						}
					}
				}
			} elseif (isset($response_data['message'])) {
				$error_message = $response_data['message'];
			} elseif (isset($response_data['data']['message'])) {
				$error_message = $response_data['data']['message'];
			} elseif (!empty($response_body) && !is_array($response_data)) {
				// Response might not be JSON, use raw body
				$error_message = __('HTTP Error', 'staging-to-live-sync') . ' ' . $response_code . ': ' . substr($response_body, 0, 200);
			}

			// Always log errors for debugging
			error_log('STLS File Sync [STAGING]: Error response - Code: ' . $response_code . ', Message: ' . $error_message);
			error_log('STLS File Sync [STAGING]: Full response data: ' . print_r($response_data, true));
			error_log('STLS File Sync [STAGING]: Full response body: ' . $response_body);

			return new WP_Error('sync_failed', $error_message);
		}

		// Check if response is valid JSON
		if (json_last_error() !== JSON_ERROR_NONE && !empty($response_body)) {
			$error_message = __('Invalid response from server', 'staging-to-live-sync') . ': ' . json_last_error_msg();
			if ($debug_mode) {
				error_log('STLS File Sync [STAGING]: JSON decode error - ' . $error_message);
				error_log('STLS File Sync [STAGING]: Response body: ' . substr($response_body, 0, 500));
			}
			return new WP_Error('sync_failed', $error_message);
		}

		if (isset($response_data['success']) && $response_data['success']) {
			if ($debug_mode) {
				error_log('STLS File Sync [STAGING]: File synced successfully');
			}
			return true;
		}

		// Extract error message from various possible locations
		// WordPress REST API formats WP_Error as: { "code": "...", "message": "...", "data": {...} }
		$error_message = __('Unknown error', 'staging-to-live-sync');
		$error_details = array();

		if (isset($response_data['code']) && isset($response_data['message'])) {
			$error_message = $response_data['message'];
			if (isset($response_data['data']['details'])) {
				$error_details = $response_data['data']['details'];
			}
		} elseif (isset($response_data['message'])) {
			$error_message = $response_data['message'];
		} elseif (isset($response_data['data']['message'])) {
			$error_message = $response_data['data']['message'];
		} elseif (isset($response_data['error'])) {
			$error_message = is_string($response_data['error']) ? $response_data['error'] : __('Error occurred', 'staging-to-live-sync');
		}

		// Always log errors for debugging
		error_log('STLS File Sync [STAGING]: Unknown error - Message: ' . $error_message);
		error_log('STLS File Sync [STAGING]: Full response data: ' . print_r($response_data, true));
		error_log('STLS File Sync [STAGING]: Full response body: ' . $response_body);

		return new WP_Error('sync_failed', $error_message);
	}

	/**
	 * Sync post to live environment
	 */
	private function sync_post_to_live($post_id, $staging_url, $live_url)
	{
		// Get post data
		$post = get_post($post_id);

		if (!$post) {
			return new WP_Error('post_not_found', __('Post not found.', 'staging-to-live-sync'));
		}

		// Get all post meta first
		$all_post_meta = get_post_meta($post_id);

		// Get ACF field keys to exclude them from general meta sync (they're handled separately)
		$acf_field_keys = array();
		if (function_exists('get_field_objects')) {
			$acf_field_objects = get_field_objects($post_id);
			if ($acf_field_objects && is_array($acf_field_objects)) {
				$acf_field_keys = array_keys($acf_field_objects);
			}
		}

		// Process meta to extract and convert all images
		// Exclude ACF fields as they're handled separately
		$processed_meta = $this->process_meta_for_images($all_post_meta, $acf_field_keys);

		// Prepare post data
		$post_data = array(
			'title' => $post->post_title,
			'content' => $post->post_content,
			'excerpt' => $post->post_excerpt,
			'status' => $post->post_status,
			'post_type' => $post->post_type,
			'slug' => $post->post_name,
			'post_date' => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
			'post_modified' => $post->post_modified,
			'post_modified_gmt' => $post->post_modified_gmt,
			'meta' => $processed_meta,
		);

		// Get ACF fields if ACF is active
		if (function_exists('get_fields')) {
			$acf_fields = get_fields($post_id);
			if ($acf_fields) {
				$post_data['acf_fields'] = $acf_fields;
			}
		}

		// Get all ACF field values using field keys for better compatibility
		if (function_exists('get_field_objects')) {
			$field_objects = get_field_objects($post_id);
			if ($field_objects) {
				$post_data['acf_field_objects'] = $field_objects;
			}
		}

		// Get ACF Gutenberg blocks data
		$post_data['acf_blocks'] = $this->get_acf_gutenberg_blocks_data($post_id, $post->post_content);

		// Debug: Log block data structure
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('STLS: Block data collected for post ' . $post_id . ': ' . print_r($post_data['acf_blocks'], true));
		}

		// Extract default WordPress image blocks
		$image_blocks_data = array();
		if (function_exists('parse_blocks') && !empty($post->post_content)) {
			$blocks = parse_blocks($post->post_content);
			$this->extract_image_blocks_recursive($blocks, $image_blocks_data);
			$post_data['image_blocks'] = $image_blocks_data;

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('STLS: Found ' . count($image_blocks_data) . ' image blocks in post content');
			}
		}

		// Extract default WordPress audio blocks
		$audio_blocks_data = array();
		if (function_exists('parse_blocks') && !empty($post->post_content)) {
			if (!isset($blocks)) {
				$blocks = parse_blocks($post->post_content);
			}
			$this->extract_audio_blocks_recursive($blocks, $audio_blocks_data);
			$post_data['audio_blocks'] = $audio_blocks_data;

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('STLS: Found ' . count($audio_blocks_data) . ' audio blocks in post content');
			}
		}

		// Extract default WordPress video blocks
		$video_blocks_data = array();
		if (function_exists('parse_blocks') && !empty($post->post_content)) {
			if (!isset($blocks)) {
				$blocks = parse_blocks($post->post_content);
			}
			$this->extract_video_blocks_recursive($blocks, $video_blocks_data);
			$post_data['video_blocks'] = $video_blocks_data;

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('STLS: Found ' . count($video_blocks_data) . ' video blocks in post content');
			}
		}

		// Process ACF fields to convert image IDs to URLs
		$post_data['acf_fields'] = $this->convert_acf_image_fields_to_urls($post_data['acf_fields'] ?? array());
		$post_data['acf_field_objects'] = $this->convert_acf_field_objects_images($post_data['acf_field_objects'] ?? array());

		// Get taxonomy terms with full data
		$taxonomies = get_object_taxonomies($post->post_type);
		$post_data['taxonomies'] = array();

		foreach ($taxonomies as $taxonomy) {
			// Get terms with full data (not just slugs)
			$terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'all'));
			if (!is_wp_error($terms) && !empty($terms)) {
				$post_data['taxonomies'][$taxonomy] = array();

				foreach ($terms as $term) {
					// Get term meta if available
					$term_meta = get_term_meta($term->term_id);
					$clean_term_meta = array();
					foreach ($term_meta as $meta_key => $meta_values) {
						// Skip internal WordPress meta
						if (strpos($meta_key, '_') !== 0 || strpos($meta_key, '_wp_') === 0) {
							$clean_term_meta[$meta_key] = maybe_unserialize(is_array($meta_values) ? $meta_values[0] : $meta_values);
						}
					}

					// Store full term data
					$post_data['taxonomies'][$taxonomy][] = array(
						'name' => $term->name,
						'slug' => $term->slug,
						'description' => $term->description,
						'parent' => $term->parent,
						'term_id' => $term->term_id,
						'term_taxonomy_id' => $term->term_taxonomy_id,
						'count' => $term->count,
						'meta' => $clean_term_meta,
					);
				}
			}
		}

		// Get featured image
		$thumbnail_id = get_post_thumbnail_id($post_id);
		if ($thumbnail_id) {
			$thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'full');
			if ($thumbnail_url) {
				$post_data['featured_image'] = array(
					'url' => $thumbnail_url,
					'alt' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
				);
			}
		}

		// Get Yoast SEO data
		$post_data['yoast_seo'] = $this->get_yoast_seo_data($post_id);

		// Allow pagebuilder-specific modifications to post data
		$post_data = apply_filters('stls_post_data_before_sync', $post_data, $post_id);

		// Prepare REST API endpoint
		$api_key = get_option('stls_live_api_key');
		$endpoint = trailingslashit($live_url) . 'wp-json/stls/v1/sync';

		// Prepare request arguments
		$args = array(
			'method' => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'timeout' => 60,
		);

		// Prepare request body with API key
		$request_body = array(
			'post_data' => $post_data,
			'post_id' => $post_id,
			'slug' => $post->post_name,
			'staging_url' => home_url(), // Send staging URL so live site can replace it
		);

		// Add API key to request body (more reliable than headers in REST API)
		if (!empty($api_key)) {
			$request_body['api_key'] = $api_key;
			// Also add as header for compatibility
			$args['headers']['X-STLS-API-Key'] = $api_key;
		}

		// Update body with API key included
		$args['body'] = wp_json_encode($request_body);

		// Make request to live site
		$response = wp_remote_request($endpoint, $args);

		if (is_wp_error($response)) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		if ($response_code !== 200) {
			$error_message = __('Failed to sync post. Live site returned error code: ', 'staging-to-live-sync') . $response_code;
			$response_data = json_decode($response_body, true);

			if (isset($response_data['message'])) {
				$error_message .= ' - ' . $response_data['message'];
			}

			return new WP_Error('sync_failed', $error_message);
		}

		return true;
	}
}

// Initialize plugin
function stls_init()
{
	return Staging_To_Live_Sync::get_instance();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', 'stls_init');

// Include test file for debugging
if (defined('WP_DEBUG') && WP_DEBUG) {
	require_once plugin_dir_path(__FILE__) . 'test-image-detection.php';
}

/**
 * Register REST API endpoint on live site for receiving sync requests
 */
add_action('rest_api_init', function () {
	register_rest_route('stls/v1', '/sync', array(
		'methods' => 'POST',
		'callback' => 'stls_handle_sync_request',
		'permission_callback' => function ($request) {
			return stls_check_sync_permission($request);
		},
	));

	register_rest_route('stls/v1', '/sync-file', array(
		'methods' => 'POST',
		'callback' => 'stls_handle_file_sync_request',
		'permission_callback' => function ($request) {
			return stls_check_sync_permission($request);
		},
	));
});

/**
 * Check permission for sync request
 */
function stls_check_sync_permission($request)
{
	$api_key = get_option('stls_live_api_key');

	// If API key is configured, require it
	if (!empty($api_key)) {
		// Try multiple methods to get the API key header
		$provided_key = '';

		// Method 1: Try to get from request headers (various formats)
		if ($request instanceof WP_REST_Request) {
			$provided_key = $request->get_header('X-STLS-API-Key');

			// Try with lowercase
			if (empty($provided_key)) {
				$provided_key = $request->get_header('x-stls-api-key');
			}
		}

		// Method 2: Check $_SERVER (HTTP headers are prefixed with HTTP_ and uppercased)
		if (empty($provided_key) && isset($_SERVER['HTTP_X_STLS_API_KEY'])) {
			$provided_key = sanitize_text_field($_SERVER['HTTP_X_STLS_API_KEY']);
		}

		// Method 3: Use getallheaders if available
		if (empty($provided_key) && function_exists('getallheaders')) {
			$headers = getallheaders();
			if (isset($headers['X-STLS-API-Key'])) {
				$provided_key = $headers['X-STLS-API-Key'];
			} elseif (isset($headers['x-stls-api-key'])) {
				$provided_key = $headers['x-stls-api-key'];
			}
		}

		// Method 4: Check request body for API key (most reliable for REST API)
		if (empty($provided_key)) {
			$params = $request->get_json_params();
			if (isset($params['api_key'])) {
				$provided_key = sanitize_text_field($params['api_key']);
			}
		}

		// Verify the API key
		if (empty($provided_key)) {
			return new WP_Error('missing_api_key', __('API key is required but not provided.', 'staging-to-live-sync'), array('status' => 401));
		}

		if ($provided_key !== $api_key) {
			return new WP_Error('invalid_api_key', __('Invalid API key provided.', 'staging-to-live-sync'), array('status' => 401));
		}

		// API key matches, allow the request
		return true;
	}

	// If no API key is configured, allow for authenticated users with manage_options
	// OR allow unauthenticated requests (for testing/development)
	// For production, API key should be configured
	if (current_user_can('manage_options')) {
		return true;
	}

	// Allow unauthenticated requests if no API key is configured (for backward compatibility)
	// In production, you should configure an API key
	return true;
}

/**
 * Allow post date to be updated for published posts
 * WordPress by default doesn't allow changing post_date for published posts
 * 
 * @param array $data Post data
 * @param array $postarr Post array
 * @return array Modified post data
 */
function stls_allow_post_date_update($data, $postarr)
{
	// If post_date is being set in postarr, allow it
	if (isset($postarr['post_date']) && !empty($postarr['post_date'])) {
		$data['post_date'] = $postarr['post_date'];
	}

	if (isset($postarr['post_date_gmt']) && !empty($postarr['post_date_gmt'])) {
		$data['post_date_gmt'] = $postarr['post_date_gmt'];
	}

	return $data;
}

/**
 * Get term by staging term ID
 * 
 * @param int $staging_term_id The term ID from staging site
 * @param string $taxonomy The taxonomy name
 * @return WP_Term|false Term object if found, false otherwise
 */
function stls_get_term_by_staging_id($staging_term_id, $taxonomy)
{
	if (empty($staging_term_id) || empty($taxonomy)) {
		return false;
	}

	// Query for terms with the staging term ID in meta
	$terms = get_terms(array(
		'taxonomy' => $taxonomy,
		'hide_empty' => false,
		'meta_query' => array(
			array(
				'key' => '_stls_staging_term_id',
				'value' => $staging_term_id,
				'compare' => '=',
			),
		),
		'number' => 1,
	));

	if (!is_wp_error($terms) && !empty($terms)) {
		return $terms[0];
	}

	return false;
}

/**
 * Handle sync request on live site
 */
function stls_handle_sync_request(WP_REST_Request $request)
{
	$params = $request->get_json_params();

	if (!isset($params['post_data']) || !isset($params['slug'])) {
		return new WP_Error('invalid_request', __('Invalid request data.', 'staging-to-live-sync'), array('status' => 400));
	}

	$post_data = $params['post_data'];
	$slug = sanitize_title($params['slug']);
	$source_post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;

	// Add staging_url to post_data for pagebuilder handlers
	if (isset($params['staging_url'])) {
		$post_data['staging_url'] = esc_url_raw($params['staging_url']);
	}

	// Try to find existing post by slug
	$existing_post = get_page_by_path($slug, OBJECT, $post_data['post_type']);

	$post_id = $existing_post ? $existing_post->ID : 0;

	// Prepare post array
	$post_array = array(
		'post_title' => sanitize_text_field($post_data['title']),
		// Don't use wp_kses_post() on post_content - it strips HTML comments used by Gutenberg blocks
		// WordPress will handle sanitization during wp_insert_post/wp_update_post
		'post_content' => wp_slash($post_data['content']),
		'post_excerpt' => sanitize_textarea_field($post_data['excerpt']),
		'post_status' => sanitize_text_field($post_data['status']),
		'post_type' => sanitize_text_field($post_data['post_type']),
		'post_name' => $slug,
	);

	// Add post date if provided
	if (isset($post_data['post_date']) && !empty($post_data['post_date'])) {
		$post_array['post_date'] = sanitize_text_field($post_data['post_date']);
	}

	if (isset($post_data['post_date_gmt']) && !empty($post_data['post_date_gmt'])) {
		$post_array['post_date_gmt'] = sanitize_text_field($post_data['post_date_gmt']);
	}

	// Add post modified date if provided (optional, for reference)
	if (isset($post_data['post_modified']) && !empty($post_data['post_modified'])) {
		$post_array['post_modified'] = sanitize_text_field($post_data['post_modified']);
	}

	if (isset($post_data['post_modified_gmt']) && !empty($post_data['post_modified_gmt'])) {
		$post_array['post_modified_gmt'] = sanitize_text_field($post_data['post_modified_gmt']);
	}

	// Debug logging
	if (defined('WP_DEBUG') && WP_DEBUG) {
		if (isset($post_array['post_date'])) {
			error_log('STLS: Syncing post date - post_date: ' . $post_array['post_date'] . ', post_date_gmt: ' . (isset($post_array['post_date_gmt']) ? $post_array['post_date_gmt'] : 'not set'));
		}
	}

	if ($post_id) {
		$post_array['ID'] = $post_id;

		// WordPress by default doesn't allow changing post_date for published posts
		// We need to temporarily allow it using a filter
		add_filter('wp_insert_post_data', 'stls_allow_post_date_update', 10, 2);
		// Disable KSES to prevent stripping of data attributes in object tags
		kses_remove_filters();
		$result = wp_update_post($post_array, true);
		kses_init_filters();
		remove_filter('wp_insert_post_data', 'stls_allow_post_date_update', 10);
	} else {
		// Disable KSES to prevent stripping of data attributes in object tags
		kses_remove_filters();
		$result = wp_insert_post($post_array, true);
		kses_init_filters();
		$post_id = $result;
	}

	if (is_wp_error($result)) {
		return new WP_Error('sync_failed', $result->get_error_message(), array('status' => 500));
	}

	// Update meta fields - sync all custom fields from staging
	// Only add/update fields that exist on staging (don't delete fields that exist on live but not on staging)
	if (isset($post_data['meta']) && is_array($post_data['meta'])) {
		$synced_fields = array();

		foreach ($post_data['meta'] as $key => $values) {
			// Skip internal WordPress meta (already filtered on staging, but double-check)
			if (strpos($key, '_wp_') === 0 && $key !== '_wp_page_template') {
				continue;
			}

			// Skip Yoast SEO meta (handled separately)
			if (strpos($key, '_yoast_wpseo_') === 0) {
				continue;
			}

			// Skip ACF fields (handled separately)
			if (strpos($key, 'field_') === 0) {
				continue;
			}

			// Skip Elementor meta fields (handled separately by elementor-sync.php)
			if (strpos($key, '_elementor_') === 0) {
				continue;
			}

			$values_array = is_array($values) ? $values : array($values);
			$processed_values = array();

			foreach ($values_array as $single_value) {
				$unserialized = maybe_unserialize($single_value);
				// Pass field key (meta key) to determine return format
				$processed = stls_convert_image_data_to_attachment_id($unserialized, $post_id, $key);
				$processed_values[] = $processed;
			}

			// Delete existing meta for this key (only for fields that exist on staging)
			delete_post_meta($post_id, $key);

			// Add new meta values
			foreach ($processed_values as $processed_value) {
				add_post_meta($post_id, $key, $processed_value);
			}

			$synced_fields[] = $key;
		}

		// Debug logging
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('STLS: Synced ' . count($synced_fields) . ' custom meta fields: ' . implode(', ', $synced_fields));
		}
	}

	// Update ACF fields - use field objects if available for better compatibility
	if (isset($post_data['acf_field_objects']) && function_exists('update_field')) {
		foreach ($post_data['acf_field_objects'] as $field_key => $field_object) {
			if (isset($field_object['value'])) {
				// Pass field_key to determine return format
				$field_value = stls_convert_image_data_to_attachment_id($field_object['value'], $post_id, $field_key);
				update_field($field_key, $field_value, $post_id);
			}
		}
	} elseif (isset($post_data['acf_fields']) && function_exists('update_field')) {
		foreach ($post_data['acf_fields'] as $field_key => $field_value) {
			// Pass field_key to determine return format
			$processed_value = stls_convert_image_data_to_attachment_id($field_value, $post_id, $field_key);
			update_field($field_key, $processed_value, $post_id);
		}
	}

	// Update ACF Gutenberg blocks
	if (isset($post_data['acf_blocks']) && is_array($post_data['acf_blocks']) && function_exists('update_field')) {
		$staging_url = isset($params['staging_url']) ? esc_url_raw($params['staging_url']) : '';
		stls_sync_acf_blocks($post_id, $post_data['acf_blocks'], $staging_url);
	}

	// Sync default WordPress image blocks
	if (isset($post_data['image_blocks']) && is_array($post_data['image_blocks']) && !empty($post_data['image_blocks'])) {
		stls_sync_image_blocks($post_id, $post_data['image_blocks']);
	}

	// Sync default WordPress audio blocks
	if (isset($post_data['audio_blocks']) && is_array($post_data['audio_blocks']) && !empty($post_data['audio_blocks'])) {
		stls_sync_audio_blocks($post_id, $post_data['audio_blocks']);
	}

	// Sync default WordPress video blocks
	if (isset($post_data['video_blocks']) && is_array($post_data['video_blocks']) && !empty($post_data['video_blocks'])) {
		stls_sync_video_blocks($post_id, $post_data['video_blocks']);
	}

	// Allow pagebuilder-specific sync handlers to process their data
	do_action('stls_after_sync_post_meta', $post_id, $post_data);

	// Update taxonomies - create missing terms if needed
	if (isset($post_data['taxonomies']) && is_array($post_data['taxonomies'])) {
		foreach ($post_data['taxonomies'] as $taxonomy => $terms) {
			if (!taxonomy_exists($taxonomy)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS: Taxonomy does not exist on live site: ' . $taxonomy);
				}
				continue;
			}

			// First pass: Sort terms to process parents before children
			$sorted_terms = array();
			$term_map = array(); // Map staging term_id to term_data

			foreach ($terms as $term_data) {
				if (is_array($term_data) && isset($term_data['term_id'])) {
					$term_map[$term_data['term_id']] = $term_data;
					$parent_id = isset($term_data['parent']) ? intval($term_data['parent']) : 0;
					$sorted_terms[] = array(
						'data' => $term_data,
						'parent_id' => $parent_id,
						'processed' => false,
					);
				} else {
					// Old format or string, add as-is
					$sorted_terms[] = array(
						'data' => $term_data,
						'parent_id' => 0,
						'processed' => false,
					);
				}
			}

			// Sort: terms with no parent first, then by parent
			usort($sorted_terms, function ($a, $b) {
				if ($a['parent_id'] === 0 && $b['parent_id'] !== 0) {
					return -1;
				}
				if ($a['parent_id'] !== 0 && $b['parent_id'] === 0) {
					return 1;
				}
				return $a['parent_id'] - $b['parent_id'];
			});

			$term_ids = array();
			$staging_to_live_term_map = array(); // Map staging term_id to live term_id

			// Process terms in order (parents first)
			foreach ($sorted_terms as $term_item) {
				$term_data = $term_item['data'];
				// Handle both old format (just slugs) and new format (full term data)
				if (is_string($term_data)) {
					// Old format: just a slug
					$term_slug = $term_data;
					$term = get_term_by('slug', $term_slug, $taxonomy);

					if ($term) {
						$term_ids[] = $term->term_id;
					} else {
						// Term doesn't exist, create it
						$new_term = wp_insert_term($term_slug, $taxonomy, array(
							'slug' => $term_slug,
						));

						if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
							$term_ids[] = $new_term['term_id'];
							if (defined('WP_DEBUG') && WP_DEBUG) {
								error_log('STLS: Created new term: ' . $term_slug . ' in taxonomy: ' . $taxonomy);
							}
						}
					}
				} elseif (is_array($term_data) && isset($term_data['slug'])) {
					// New format: full term data
					$term_slug = $term_data['slug'];
					$term_name = isset($term_data['name']) ? $term_data['name'] : $term_slug;
					$term_description = isset($term_data['description']) ? $term_data['description'] : '';
					$staging_term_id = isset($term_data['term_id']) ? intval($term_data['term_id']) : 0;
					$staging_parent_id = isset($term_data['parent']) ? intval($term_data['parent']) : 0;

					// Check if term exists by slug
					$term = get_term_by('slug', $term_slug, $taxonomy);

					// Also check if term exists by staging term ID (if we have it)
					if (!$term && $staging_term_id > 0) {
						$term = stls_get_term_by_staging_id($staging_term_id, $taxonomy);
					}

					if ($term) {
						// Term exists, update it if needed
						$update_args = array();

						if ($term->name !== $term_name) {
							$update_args['name'] = $term_name;
						}
						if ($term->description !== $term_description) {
							$update_args['description'] = $term_description;
						}

						// Handle parent term - find live parent term ID
						$live_parent_id = 0;
						if ($staging_parent_id > 0) {
							// Check if we've already mapped this parent
							if (isset($staging_to_live_term_map[$staging_parent_id])) {
								$live_parent_id = $staging_to_live_term_map[$staging_parent_id];
							} else {
								// Try to find parent by staging term ID
								$parent_term = stls_get_term_by_staging_id($staging_parent_id, $taxonomy);
								if ($parent_term) {
									$live_parent_id = $parent_term->term_id;
									$staging_to_live_term_map[$staging_parent_id] = $live_parent_id;
								} else {
									// Try by slug (if parent data is in our term list)
									if (isset($term_map[$staging_parent_id])) {
										$parent_data = $term_map[$staging_parent_id];
										$parent_term = get_term_by('slug', $parent_data['slug'], $taxonomy);
										if ($parent_term) {
											$live_parent_id = $parent_term->term_id;
											$staging_to_live_term_map[$staging_parent_id] = $live_parent_id;
										}
									}
								}
							}
						}

						if ($term->parent != $live_parent_id) {
							$update_args['parent'] = $live_parent_id;
						}

						if (!empty($update_args)) {
							wp_update_term($term->term_id, $taxonomy, $update_args);
						}

						// Store staging term ID mapping if not already stored
						if ($staging_term_id > 0) {
							$existing_staging_id = get_term_meta($term->term_id, '_stls_staging_term_id', true);
							if (!$existing_staging_id) {
								update_term_meta($term->term_id, '_stls_staging_term_id', $staging_term_id);
							}
							$staging_to_live_term_map[$staging_term_id] = $term->term_id;
						}

						// Sync term meta if available
						if (isset($term_data['meta']) && is_array($term_data['meta'])) {
							foreach ($term_data['meta'] as $meta_key => $meta_value) {
								// Skip internal meta
								if (strpos($meta_key, '_') === 0 && strpos($meta_key, '_stls_') !== 0) {
									continue;
								}
								update_term_meta($term->term_id, $meta_key, $meta_value);
							}
						}

						$term_ids[] = $term->term_id;
					} else {
						// Term doesn't exist, create it
						$insert_args = array(
							'slug' => $term_slug,
							'description' => $term_description,
						);

						// Handle parent term - find live parent term ID
						$live_parent_id = 0;
						if ($staging_parent_id > 0) {
							// Check if we've already mapped this parent
							if (isset($staging_to_live_term_map[$staging_parent_id])) {
								$live_parent_id = $staging_to_live_term_map[$staging_parent_id];
							} else {
								// Try to find parent by staging term ID
								$parent_term = stls_get_term_by_staging_id($staging_parent_id, $taxonomy);
								if ($parent_term) {
									$live_parent_id = $parent_term->term_id;
									$staging_to_live_term_map[$staging_parent_id] = $live_parent_id;
								} else {
									// Try by slug (if parent data is in our term list)
									if (isset($term_map[$staging_parent_id])) {
										$parent_data = $term_map[$staging_parent_id];
										$parent_term = get_term_by('slug', $parent_data['slug'], $taxonomy);
										if ($parent_term) {
											$live_parent_id = $parent_term->term_id;
											$staging_to_live_term_map[$staging_parent_id] = $live_parent_id;
										}
									}
								}
							}

							if ($live_parent_id > 0) {
								$insert_args['parent'] = $live_parent_id;
							}
						}

						$new_term = wp_insert_term($term_name, $taxonomy, $insert_args);

						if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
							$live_term_id = $new_term['term_id'];
							$term_ids[] = $live_term_id;

							// Store staging term ID in meta for future reference
							if ($staging_term_id > 0) {
								update_term_meta($live_term_id, '_stls_staging_term_id', $staging_term_id);
								$staging_to_live_term_map[$staging_term_id] = $live_term_id;
							}

							// Sync term meta if available
							if (isset($term_data['meta']) && is_array($term_data['meta'])) {
								foreach ($term_data['meta'] as $meta_key => $meta_value) {
									// Skip internal meta
									if (strpos($meta_key, '_') === 0 && strpos($meta_key, '_stls_') !== 0) {
										continue;
									}
									update_term_meta($live_term_id, $meta_key, $meta_value);
								}
							}

							if (defined('WP_DEBUG') && WP_DEBUG) {
								error_log('STLS: Created new term: ' . $term_name . ' (slug: ' . $term_slug . ') in taxonomy: ' . $taxonomy);
							}
						} else {
							if (defined('WP_DEBUG') && WP_DEBUG) {
								error_log('STLS: Failed to create term: ' . $term_name . ' - ' . (is_wp_error($new_term) ? $new_term->get_error_message() : 'Unknown error'));
							}
						}
					}
				}
			}

			// Set terms for the post
			if (!empty($term_ids)) {
				$result = wp_set_object_terms($post_id, $term_ids, $taxonomy);
				if (is_wp_error($result)) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('STLS: Failed to set terms for post ' . $post_id . ' in taxonomy ' . $taxonomy . ': ' . $result->get_error_message());
					}
				} else {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('STLS: Successfully set ' . count($term_ids) . ' terms for post ' . $post_id . ' in taxonomy ' . $taxonomy);
					}
				}
			}
		}
	}

	// Handle featured image
	if (isset($post_data['featured_image']) && !empty($post_data['featured_image']['url'])) {
		$image_url = $post_data['featured_image']['url'];

		// Download image from staging
		$image_id = stls_download_and_attach_image($image_url, $post_id);

		if ($image_id && !is_wp_error($image_id)) {
			set_post_thumbnail($post_id, $image_id);

			// Set alt text if provided, or remove it if empty to avoid WordPress warnings
			if (isset($post_data['featured_image']['alt'])) {
				if (!empty($post_data['featured_image']['alt'])) {
					update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($post_data['featured_image']['alt']));
				} else {
					// Delete alt text meta if it's empty to avoid WordPress accessibility warnings
					delete_post_meta($image_id, '_wp_attachment_image_alt');
				}
			}
		}
	}

	// Sync Yoast SEO data
	if (isset($post_data['yoast_seo']) && is_array($post_data['yoast_seo']) && !empty($post_data['yoast_seo'])) {
		stls_sync_yoast_seo_data($post_id, $post_data['yoast_seo']);
	}

	// Replace staging URLs with live URLs in post content
	// Use simple string replacement to avoid corrupting block structure
	// Only parse/serialize blocks when we need to update block attributes (done separately)
	$post_content = get_post_field('post_content', $post_id);
	if (!empty($post_content)) {
		// Get staging URL from request params
		$params = $request->get_json_params();
		$staging_url = isset($params['staging_url']) ? $params['staging_url'] : '';

		// If we have a staging URL, replace it with live URL using simple string replacement
		// This preserves block structure better than parsing/serializing
		if (!empty($staging_url)) {
			$live_url = home_url();
			$updated_content = $post_content;

			// Replace staging URL with live URL (handle various formats)
			// Include escaped slashes for Gutenberg block attributes in post_content
			$staging_url_variations = array(
				$staging_url,
				trailingslashit($staging_url),
				untrailingslashit($staging_url),
				str_replace('http://', 'https://', $staging_url),
				str_replace('https://', 'http://', $staging_url),
				str_replace('/', '\/', $staging_url), // Escaped slashes for JSON
				str_replace('/', '\/', trailingslashit($staging_url)),
				str_replace('/', '\/', untrailingslashit($staging_url)),
			);

			$live_url_variations = array(
				$live_url,
				trailingslashit($live_url),
				untrailingslashit($live_url),
				str_replace('/', '\/', $live_url), // Escaped slashes for JSON
			);

			foreach ($staging_url_variations as $staging_var) {
				foreach ($live_url_variations as $live_var) {
					// Only replace if they match the "escaped-ness" to avoid partial corruption
					$is_staging_escaped = (strpos($staging_var, '\/') !== false);
					$is_live_escaped = (strpos($live_var, '\/') !== false);

					if ($is_staging_escaped === $is_live_escaped) {
						$updated_content = str_replace($staging_var, $live_var, $updated_content);
					}
				}
			}

			// Only update if content changed
			if ($updated_content !== $post_content) {
				// Disable KSES to prevent stripping of data attributes in object tags
				kses_remove_filters();
				wp_update_post(array(
					'ID' => $post_id,
					'post_content' => wp_slash($updated_content),
				));
				kses_init_filters();

				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS: Replaced staging URLs with live URLs in post content. Staging: ' . $staging_url . ' -> Live: ' . $live_url);
				}
			}
		}
	}

	// CRITICAL: Fire hook for pagebuilder-specific processing (e.g., Elementor)
	// This allows pagebuilder sync modules to process their data after standard meta is synced
	do_action('stls_after_sync_post_meta', $post_id, $post_data);

	return rest_ensure_response(array(
		'success' => true,
		'post_id' => $post_id,
		'message' => __('Post synced successfully.', 'staging-to-live-sync'),
	));
}

/**
 * Handle file sync request on live site
 */
function stls_handle_file_sync_request(WP_REST_Request $request)
{
	// Create a custom log file as fallback (in case error_log is disabled)
	$log_file = WP_CONTENT_DIR . '/stls-sync.log';
	$write_log = function ($message) use ($log_file) {
		$timestamp = date('Y-m-d H:i:s');
		$log_entry = "[{$timestamp}] {$message}\n";
		@file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
		// Also try error_log
		@error_log($message);
	};

	try {
		$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

		// Always log file sync requests for debugging
		$write_log('STLS File Sync [LIVE]: ========== File sync request received ==========');
		$write_log('STLS File Sync [LIVE]: Request method: ' . $request->get_method());
		$write_log('STLS File Sync [LIVE]: Request URI: ' . $request->get_route());

		// Define $force_log to always log (for debugging file sync issues)
		$force_log = function ($message) use ($write_log) {
			$write_log($message);
		};

		$raw_body = $request->get_body();
		$params = $request->get_json_params();

		$write_log('STLS File Sync [LIVE]: Raw body length: ' . strlen($raw_body));
		$write_log('STLS File Sync [LIVE]: Params: ' . print_r($params, true));

		// Fallback: If get_json_params() fails, try manual JSON decode
		if (empty($params) && !empty($raw_body)) {
			$params = json_decode($raw_body, true);
		}

		// Get file_url, file_path, and optional base64 content from params or raw body
		$file_url = '';
		$file_path = '';
		$file_content_base64 = '';
		$is_php_file = false;

		if (is_array($params) && isset($params['file_url'])) {
			$file_url = $params['file_url'];
		} elseif (!empty($raw_body)) {
			$decoded = json_decode($raw_body, true);
			$force_log('STLS File Sync [LIVE]: Manual decode result: ' . print_r($decoded, true));
			if ($decoded && isset($decoded['file_url'])) {
				$file_url = $decoded['file_url'];
				$force_log('STLS File Sync [LIVE]: Retrieved file_url from raw body decode: ' . $file_url);
				if ($debug_mode) {
					error_log('STLS File Sync [LIVE]: Retrieved file_url from raw body decode: ' . $file_url);
				}
			} else {
				$force_log('STLS File Sync [LIVE]: ERROR - Could not decode file_url from raw body');
			}
		} else {
			$force_log('STLS File Sync [LIVE]: ERROR - No params array and no raw body');
		}

		if (is_array($params) && isset($params['file_path'])) {
			$file_path = sanitize_text_field($params['file_path']);
			$force_log('STLS File Sync [LIVE]: Got file_path from params: ' . $file_path);
		} elseif (!empty($raw_body)) {
			$decoded = json_decode($raw_body, true);
			if ($decoded && isset($decoded['file_path'])) {
				$file_path = sanitize_text_field($decoded['file_path']);
				$force_log('STLS File Sync [LIVE]: Retrieved file_path from raw body decode: ' . $file_path);
				if ($debug_mode) {
					error_log('STLS File Sync [LIVE]: Retrieved file_path from raw body decode: ' . $file_path);
				}
			}
		}

		// Check for base64 encoded file content (for PHP files)
		if (is_array($params) && isset($params['file_content_base64'])) {
			$file_content_base64 = $params['file_content_base64'];
			$is_php_file = isset($params['is_php_file']) && $params['is_php_file'];
			$force_log('STLS File Sync [LIVE]: Got base64 file content (PHP file: ' . var_export($is_php_file, true) . ')');
			$force_log('STLS File Sync [LIVE]: Base64 content length: ' . strlen($file_content_base64));
		} elseif (!empty($raw_body)) {
			$decoded = json_decode($raw_body, true);
			if ($decoded && isset($decoded['file_content_base64'])) {
				$file_content_base64 = $decoded['file_content_base64'];
				$is_php_file = isset($decoded['is_php_file']) && $decoded['is_php_file'];
				$force_log('STLS File Sync [LIVE]: Retrieved base64 file content from raw body decode');
			}
		}

		$force_log('STLS File Sync [LIVE]: Final file_url: ' . var_export($file_url, true));
		$force_log('STLS File Sync [LIVE]: Final file_path: ' . var_export($file_path, true));
		$force_log('STLS File Sync [LIVE]: file_url type: ' . gettype($file_url));
		$force_log('STLS File Sync [LIVE]: file_url length: ' . strlen($file_url));
		$force_log('STLS File Sync [LIVE]: file_url empty: ' . var_export(empty($file_url), true));
		$force_log('STLS File Sync [LIVE]: file_path empty: ' . var_export(empty($file_path), true));

		if ($debug_mode) {
			error_log('STLS File Sync [LIVE]: Final file_url: ' . var_export($file_url, true));
			error_log('STLS File Sync [LIVE]: Final file_path: ' . var_export($file_path, true));
			error_log('STLS File Sync [LIVE]: file_url type: ' . gettype($file_url));
			error_log('STLS File Sync [LIVE]: file_url length: ' . strlen($file_url));
			error_log('STLS File Sync [LIVE]: file_url empty: ' . var_export(empty($file_url), true));
			error_log('STLS File Sync [LIVE]: file_path empty: ' . var_export(empty($file_path), true));
		}

		// Check if we have base64 content (for PHP files) - if so, we don't need file_url
		if (empty($file_content_base64) && (empty($file_url) || empty($file_path))) {
			$force_log('STLS File Sync [LIVE]: ERROR - Empty file_url or file_path (and no base64 content)');
			$force_log('STLS File Sync [LIVE]: file_url empty: ' . var_export(empty($file_url), true));
			$force_log('STLS File Sync [LIVE]: file_path empty: ' . var_export(empty($file_path), true));
			if ($debug_mode) {
				error_log('STLS File Sync [LIVE]: ERROR - Empty file_url or file_path');
				error_log('STLS File Sync [LIVE]: file_url empty: ' . var_export(empty($file_url), true));
				error_log('STLS File Sync [LIVE]: file_path empty: ' . var_export(empty($file_path), true));
			}
			return new WP_Error('invalid_request', __('File URL and path cannot be empty.', 'staging-to-live-sync'), array('status' => 400));
		}

		if (empty($file_path)) {
			$force_log('STLS File Sync [LIVE]: ERROR - file_path is required');
			return new WP_Error('invalid_request', __('File path cannot be empty.', 'staging-to-live-sync'), array('status' => 400));
		}

		// Get permissions from constants if defined, otherwise use defaults
		$dir_perms = defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : 0775;
		$file_perms = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0664;

		// Build full file path on live site
		$wp_content_dir = WP_CONTENT_DIR;
		$full_file_path = $wp_content_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file_path);

		$force_log('STLS File Sync [LIVE]: Building file path');
		$force_log('STLS File Sync [LIVE]: WP_CONTENT_DIR: ' . $wp_content_dir);
		$force_log('STLS File Sync [LIVE]: File path: ' . $file_path);
		$force_log('STLS File Sync [LIVE]: Full file path: ' . $full_file_path);
		$force_log('STLS File Sync [LIVE]: Using file permissions: ' . sprintf('%o', $file_perms));
		$force_log('STLS File Sync [LIVE]: Using directory permissions: ' . sprintf('%o', $dir_perms));

		// Ensure directory exists
		$file_dir = dirname($full_file_path);
		$force_log('STLS File Sync [LIVE]: File directory: ' . $file_dir);
		$force_log('STLS File Sync [LIVE]: Directory exists: ' . var_export(file_exists($file_dir), true));
		$force_log('STLS File Sync [LIVE]: Directory is writable: ' . var_export(is_writable($file_dir), true));

		if (!file_exists($file_dir)) {
			$force_log('STLS File Sync [LIVE]: Directory does not exist, attempting to create: ' . $file_dir);
			$dir_created = wp_mkdir_p($file_dir);
			$force_log('STLS File Sync [LIVE]: Directory creation result: ' . var_export($dir_created, true));

			if (!$dir_created) {
				$force_log('STLS File Sync [LIVE]: ERROR - Failed to create directory: ' . $file_dir);
				return new WP_Error('directory_creation_failed', sprintf(__('Failed to create directory: %s', 'staging-to-live-sync'), $file_dir), array('status' => 500));
			}

			// Set directory permissions
			@chmod($file_dir, $dir_perms);
			$force_log('STLS File Sync [LIVE]: Directory created successfully');
		}

		// Check if directory is writable
		if (!is_writable($file_dir)) {
			$force_log('STLS File Sync [LIVE]: ERROR - Directory is not writable: ' . $file_dir);
			$force_log('STLS File Sync [LIVE]: Directory permissions: ' . substr(sprintf('%o', fileperms($file_dir)), -4));
			return new WP_Error('directory_not_writable', sprintf(__('Directory is not writable: %s', 'staging-to-live-sync'), $file_dir), array('status' => 500));
		}

		// Check if file exists and is writable (if it exists)
		if (file_exists($full_file_path)) {
			$force_log('STLS File Sync [LIVE]: File already exists: ' . $full_file_path);
			$force_log('STLS File Sync [LIVE]: File is writable: ' . var_export(is_writable($full_file_path), true));
			$force_log('STLS File Sync [LIVE]: File permissions: ' . substr(sprintf('%o', fileperms($full_file_path)), -4));

			if (!is_writable($full_file_path)) {
				$force_log('STLS File Sync [LIVE]: WARNING - Existing file is not writable, attempting to change permissions');
				$file_perms = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0664;
				@chmod($full_file_path, $file_perms);
				if (!is_writable($full_file_path)) {
					$force_log('STLS File Sync [LIVE]: ERROR - Cannot make file writable: ' . $full_file_path);
					return new WP_Error('file_not_writable', sprintf(__('File exists but is not writable: %s', 'staging-to-live-sync'), $full_file_path), array('status' => 500));
				}
			}
		}

		$file_body = '';

		// If we have base64 encoded content (for PHP files), decode it
		if (!empty($file_content_base64) && $is_php_file) {
			$force_log('STLS File Sync [LIVE]: Processing PHP file with base64 content');
			$file_body = base64_decode($file_content_base64, true);
			if ($file_body === false) {
				$force_log('STLS File Sync [LIVE]: ERROR - Failed to decode base64 content');
				return new WP_Error('decode_failed', __('Failed to decode file content.', 'staging-to-live-sync'), array('status' => 500));
			}
			$force_log('STLS File Sync [LIVE]: Successfully decoded base64 content, size: ' . strlen($file_body) . ' bytes');
		} else {
			// Download file from staging via HTTP (for non-PHP files)
			// Trim and clean the URL
			$file_url = trim($file_url);
			$force_log('STLS File Sync [LIVE]: URL after trim: ' . $file_url);
			$force_log('STLS File Sync [LIVE]: URL starts with http:// or https://: ' . var_export(preg_match('#^https?://#i', $file_url), true));

			if ($debug_mode) {
				error_log('STLS File Sync [LIVE]: URL after trim: ' . $file_url);
				error_log('STLS File Sync [LIVE]: URL starts with http:// or https://: ' . var_export(preg_match('#^https?://#i', $file_url), true));
			}

			// Basic URL validation - just check for protocol
			if (!preg_match('#^https?://#i', $file_url)) {
				$force_log('STLS File Sync [LIVE]: ERROR - URL missing protocol');
				$force_log('STLS File Sync [LIVE]: URL value: ' . $file_url);
				if ($debug_mode) {
					error_log('STLS File Sync [LIVE]: ERROR - URL missing protocol');
					error_log('STLS File Sync [LIVE]: URL value: ' . $file_url);
				}
				return new WP_Error('invalid_url', __('A valid URL was not provided. URL must start with http:// or https://', 'staging-to-live-sync'), array('status' => 400));
			}

			$timeout = 300; // 5 minutes for large files

			// Ensure URL is properly formatted - remove any null bytes or invalid characters
			$file_url = str_replace(array("\0", "\r", "\n"), '', $file_url);
			$file_url = trim($file_url);

			// Validate URL one more time
			if (empty($file_url)) {
				$force_log('STLS File Sync [LIVE]: ERROR - URL is empty after cleaning');
				if ($debug_mode) {
					error_log('STLS File Sync [LIVE]: ERROR - URL is empty after cleaning');
				}
				return new WP_Error('invalid_url', __('A valid URL was not provided.', 'staging-to-live-sync'), array('status' => 400));
			}

			$force_log('STLS File Sync [LIVE]: Attempting to download file via HTTP');
			$force_log('STLS File Sync [LIVE]: URL: ' . $file_url);
			$force_log('STLS File Sync [LIVE]: URL length: ' . strlen($file_url));
			$force_log('STLS File Sync [LIVE]: Timeout: ' . $timeout);
			$force_log('STLS File Sync [LIVE]: Target file path: ' . $full_file_path);

			if ($debug_mode) {
				error_log('STLS File Sync [LIVE]: Calling wp_remote_get() with URL: ' . $file_url);
				error_log('STLS File Sync [LIVE]: URL length: ' . strlen($file_url));
				error_log('STLS File Sync [LIVE]: Timeout: ' . $timeout);
			}

			// Use wp_remote_get() directly (not wp_safe_remote_get) to allow local URLs
			$response = wp_remote_get($file_url, array(
				'timeout' => $timeout,
				'sslverify' => false, // Allow self-signed certificates for local development
				'redirection' => 5,
			));

			if (is_wp_error($response)) {
				$force_log('STLS File Sync [LIVE]: wp_remote_get() failed');
				$force_log('STLS File Sync [LIVE]: Error code: ' . $response->get_error_code());
				$force_log('STLS File Sync [LIVE]: Error message: ' . $response->get_error_message());
				$force_log('STLS File Sync [LIVE]: Error data: ' . print_r($response->get_error_data(), true));
				if ($debug_mode) {
					error_log('STLS File Sync [LIVE]: wp_remote_get() failed');
					error_log('STLS File Sync [LIVE]: Error code: ' . $response->get_error_code());
					error_log('STLS File Sync [LIVE]: Error message: ' . $response->get_error_message());
				}
				return new WP_Error('download_failed', $response->get_error_message(), array('status' => 500));
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$force_log('STLS File Sync [LIVE]: Response code: ' . $response_code);

			if ($response_code !== 200) {
				$force_log('STLS File Sync [LIVE]: ERROR - HTTP response code is not 200: ' . $response_code);
				$response_message = wp_remote_retrieve_response_message($response);
				$force_log('STLS File Sync [LIVE]: Response message: ' . $response_message);
				return new WP_Error('download_failed', sprintf(__('Failed to download file. HTTP %d: %s', 'staging-to-live-sync'), $response_code, $response_message), array('status' => $response_code));
			}

			// Get the file body
			$file_body = wp_remote_retrieve_body($response);
			if (empty($file_body)) {
				$force_log('STLS File Sync [LIVE]: ERROR - Response body is empty');
				return new WP_Error('download_failed', __('File content is empty.', 'staging-to-live-sync'), array('status' => 500));
			}
		}

		// Write file to destination
		$force_log('STLS File Sync [LIVE]: Attempting to write file');
		$force_log('STLS File Sync [LIVE]: File path: ' . $full_file_path);
		$force_log('STLS File Sync [LIVE]: File body size: ' . strlen($file_body) . ' bytes');
		$force_log('STLS File Sync [LIVE]: Directory is writable: ' . var_export(is_writable($file_dir), true));

		// Check if file already exists and its permissions
		if (file_exists($full_file_path)) {
			$force_log('STLS File Sync [LIVE]: File already exists, checking permissions');
			$current_perms = fileperms($full_file_path);
			$force_log('STLS File Sync [LIVE]: Current file permissions: ' . substr(sprintf('%o', $current_perms), -4));
			$force_log('STLS File Sync [LIVE]: File is writable: ' . var_export(is_writable($full_file_path), true));

			// Try to make it writable if it's not (WP Engine default: 0664)
			if (!is_writable($full_file_path)) {
				$force_log('STLS File Sync [LIVE]: Attempting to change file permissions to 0664 (WP Engine default)');
				@chmod($full_file_path, 0664);
				$force_log('STLS File Sync [LIVE]: File is writable after chmod: ' . var_export(is_writable($full_file_path), true));
			}

			// Try to delete the existing file first (backup approach)
			if (!is_writable($full_file_path)) {
				$force_log('STLS File Sync [LIVE]: File still not writable, attempting to delete and recreate');
				$deleted = @unlink($full_file_path);
				$force_log('STLS File Sync [LIVE]: File deletion result: ' . var_export($deleted, true));
				if (!$deleted) {
					$force_log('STLS File Sync [LIVE]: WARNING - Could not delete existing file, will attempt overwrite');
				}
			}
		}

		// Use a temporary file approach: write to wp-content/uploads first (usually writable on WP Engine)
		// Then move to final location
		$upload_dir = wp_upload_dir();
		$temp_base_dir = $upload_dir['basedir'] . '/stls-temp';

		// Ensure temp directory exists
		if (!file_exists($temp_base_dir)) {
			wp_mkdir_p($temp_base_dir);
			@chmod($temp_base_dir, $dir_perms);
			$force_log('STLS File Sync [LIVE]: Created temp directory: ' . $temp_base_dir);
		} else {
			// Make sure temp directory has correct permissions
			@chmod($temp_base_dir, $dir_perms);
		}

		// Create temp file in uploads directory (which is usually writable)
		$temp_file = $temp_base_dir . '/stls_tmp_' . time() . '_' . basename($full_file_path);
		$force_log('STLS File Sync [LIVE]: Attempting to write to temporary file in uploads: ' . $temp_file);
		$force_log('STLS File Sync [LIVE]: Temp directory is writable: ' . var_export(is_writable($temp_base_dir), true));
		$force_log('STLS File Sync [LIVE]: Using file permissions: ' . sprintf('%o', $file_perms));
		$force_log('STLS File Sync [LIVE]: Using directory permissions: ' . sprintf('%o', $dir_perms));

		// Clear any previous PHP errors
		$previous_error = error_get_last();

		// Attempt to write to temporary file first
		$file_written = @file_put_contents($temp_file, $file_body, LOCK_EX);

		// Set permissions on temp file if it was created
		if ($file_written !== false && file_exists($temp_file)) {
			@chmod($temp_file, $file_perms);
		}

		// Check for errors
		$last_error = error_get_last();

		if ($file_written === false) {
			$error_message = __('Failed to write file to disk.', 'staging-to-live-sync');
			$error_details = array();

			$force_log('STLS File Sync [LIVE]: ERROR - Failed to write to temporary file: ' . $temp_file);
			$error_details['temp_file'] = $temp_file;
			$error_details['temp_dir_writable'] = is_writable($temp_base_dir);
			$error_details['temp_dir_perms'] = file_exists($temp_base_dir) ? substr(sprintf('%o', fileperms($temp_base_dir)), -4) : 'N/A';

			// Get detailed error information
			if ($last_error && (!$previous_error || $last_error['message'] !== $previous_error['message'])) {
				$error_message .= ' ' . $last_error['message'];
				$error_details['php_error'] = $last_error;
				$force_log('STLS File Sync [LIVE]: PHP Error: ' . $last_error['message'] . ' in ' . $last_error['file'] . ' on line ' . $last_error['line']);
			}

			// Check disk space
			$free_space = @disk_free_space($file_dir);
			$error_details['free_disk_space'] = $free_space !== false ? number_format($free_space / 1024 / 1024, 2) . ' MB' : 'Unknown';
			$force_log('STLS File Sync [LIVE]: Free disk space: ' . $error_details['free_disk_space']);
			if ($free_space !== false && $free_space < strlen($file_body)) {
				$error_message .= ' ' . __('Insufficient disk space.', 'staging-to-live-sync');
				$error_details['disk_space'] = $free_space;
			}

			// Try direct write as fallback
			$force_log('STLS File Sync [LIVE]: Attempting direct write as fallback');
			$direct_write = @file_put_contents($full_file_path, $file_body, LOCK_EX);
			if ($direct_write !== false) {
				$force_log('STLS File Sync [LIVE]: Direct write succeeded!');
				@chmod($full_file_path, $file_perms);
				$file_written = $direct_write;
			} else {
				$direct_error = error_get_last();
				if ($direct_error) {
					$error_message .= ' Direct write failed: ' . $direct_error['message'];
					$error_details['direct_write_error'] = $direct_error;
				}
				$error_details['target_dir_writable'] = is_writable($file_dir);
				$error_details['target_dir_perms'] = file_exists($file_dir) ? substr(sprintf('%o', fileperms($file_dir)), -4) : 'N/A';
				$error_details['target_file_exists'] = file_exists($full_file_path);
				if (file_exists($full_file_path)) {
					$error_details['target_file_perms'] = substr(sprintf('%o', fileperms($full_file_path)), -4);
					$error_details['target_file_writable'] = is_writable($full_file_path);
				}

				$force_log('STLS File Sync [LIVE]: Direct write also failed');
				$force_log('STLS File Sync [LIVE]: Error details: ' . print_r($error_details, true));

				// Include detailed error information in the message
				$detailed_message = $error_message;
				if (!empty($error_details['php_error']['message'])) {
					$detailed_message .= ' (' . $error_details['php_error']['message'] . ')';
				}
				if (isset($error_details['temp_dir_writable']) && !$error_details['temp_dir_writable']) {
					$detailed_message .= ' Temp dir not writable.';
				}
				if (isset($error_details['target_dir_writable']) && !$error_details['target_dir_writable']) {
					$detailed_message .= ' Target dir not writable.';
				}

				// Log the full error details
				$write_log('STLS File Sync [LIVE]: Write failed - ' . $detailed_message);
				$write_log('STLS File Sync [LIVE]: Full error details: ' . print_r($error_details, true));

				return new WP_Error('write_failed', $detailed_message, array('status' => 500, 'details' => $error_details));
			}
		} else {
			// Successfully wrote to temp file, now rename it
			$force_log('STLS File Sync [LIVE]: Successfully wrote to temporary file, renaming to final location');
			$force_log('STLS File Sync [LIVE]: Temp file: ' . $temp_file);
			$force_log('STLS File Sync [LIVE]: Target file: ' . $full_file_path);
			$force_log('STLS File Sync [LIVE]: Temp file exists: ' . var_export(file_exists($temp_file), true));
			$force_log('STLS File Sync [LIVE]: Target file exists: ' . var_export(file_exists($full_file_path), true));

			// If target file exists, try to delete it first
			if (file_exists($full_file_path)) {
				$force_log('STLS File Sync [LIVE]: Target file exists, attempting to delete it first');
				$force_log('STLS File Sync [LIVE]: Target file is writable: ' . var_export(is_writable($full_file_path), true));
				$force_log('STLS File Sync [LIVE]: Target file permissions: ' . substr(sprintf('%o', fileperms($full_file_path)), -4));

				// Try to make it writable first - use FS_CHMOD_FILE if defined
				$file_perms = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0664;
				@chmod($full_file_path, $file_perms);

				// Try to delete
				$deleted = @unlink($full_file_path);
				$force_log('STLS File Sync [LIVE]: Target file deletion result: ' . var_export($deleted, true));

				if (!$deleted) {
					$force_log('STLS File Sync [LIVE]: WARNING - Could not delete existing file, will attempt to overwrite');
				}
			}

			// Try WordPress Filesystem API first (better for managed hosting)
			global $wp_filesystem;
			$wp_filesystem_success = false;

			if (empty($wp_filesystem)) {
				$force_log('STLS File Sync [LIVE]: Initializing WordPress Filesystem API');
				require_once(ABSPATH . '/wp-admin/includes/file.php');

				// Try to initialize with direct method (no credentials needed)
				$credentials = request_filesystem_credentials('', '', false, false, null);
				$force_log('STLS File Sync [LIVE]: Filesystem credentials result: ' . var_export($credentials, true));

				// Initialize filesystem - try direct method first
				$init_result = WP_Filesystem(false, $file_dir, true);
				$force_log('STLS File Sync [LIVE]: WP_Filesystem() initialization result: ' . var_export($init_result, true));

				if (!$init_result) {
					$force_log('STLS File Sync [LIVE]: WP_Filesystem() initialization failed');
					if (!empty($wp_filesystem->errors) && is_wp_error($wp_filesystem->errors)) {
						$force_log('STLS File Sync [LIVE]: Filesystem errors: ' . $wp_filesystem->errors->get_error_message());
					}
				} else {
					$force_log('STLS File Sync [LIVE]: WP_Filesystem() initialized successfully');
					$force_log('STLS File Sync [LIVE]: Filesystem method: ' . (isset($wp_filesystem->method) ? $wp_filesystem->method : 'Unknown'));
				}
			} else {
				$force_log('STLS File Sync [LIVE]: WordPress Filesystem API already initialized');
				$force_log('STLS File Sync [LIVE]: Filesystem method: ' . (isset($wp_filesystem->method) ? $wp_filesystem->method : 'Unknown'));
			}

			if (!empty($wp_filesystem) && method_exists($wp_filesystem, 'put_contents')) {
				$force_log('STLS File Sync [LIVE]: Attempting WordPress Filesystem API write');
				$force_log('STLS File Sync [LIVE]: Target file path: ' . $full_file_path);
				$force_log('STLS File Sync [LIVE]: File body size: ' . strlen($file_body) . ' bytes');

				// Use FS_CHMOD_FILE constant if defined, otherwise use default
				$wp_write = $wp_filesystem->put_contents($full_file_path, $file_body, $file_perms);

				if ($wp_write !== false) {
					$force_log('STLS File Sync [LIVE]: WordPress Filesystem API write succeeded!');
					@unlink($temp_file);
					$file_written = strlen($file_body);
					$wp_filesystem_success = true;
				} else {
					$force_log('STLS File Sync [LIVE]: WordPress Filesystem API write failed');

					// Check for errors
					if (!empty($wp_filesystem->errors) && is_wp_error($wp_filesystem->errors)) {
						$force_log('STLS File Sync [LIVE]: Filesystem API errors: ' . $wp_filesystem->errors->get_error_message());
						$force_log('STLS File Sync [LIVE]: Filesystem API error codes: ' . print_r($wp_filesystem->errors->get_error_codes(), true));
					}

					// Check if file was partially written
					if (file_exists($full_file_path)) {
						$existing_size = filesize($full_file_path);
						$force_log('STLS File Sync [LIVE]: File exists after failed write, size: ' . $existing_size . ' bytes (expected: ' . strlen($file_body) . ' bytes)');
					}
				}
			} else {
				$force_log('STLS File Sync [LIVE]: WordPress Filesystem API not available or put_contents method missing');
				if (empty($wp_filesystem)) {
					$force_log('STLS File Sync [LIVE]: $wp_filesystem is empty');
				} else {
					$force_log('STLS File Sync [LIVE]: put_contents method exists: ' . var_export(method_exists($wp_filesystem, 'put_contents'), true));
				}
			}

			if (!$wp_filesystem_success) {
				// Try rename first (atomic operation)
				$renamed = @rename($temp_file, $full_file_path);
				$force_log('STLS File Sync [LIVE]: Rename result: ' . var_export($renamed, true));

				if (!$renamed) {
					// Get the error if available
					$rename_error = error_get_last();
					if ($rename_error) {
						$force_log('STLS File Sync [LIVE]: Rename error: ' . $rename_error['message']);
					}

					$force_log('STLS File Sync [LIVE]: WARNING - Failed to rename temp file, attempting copy instead');

					// Try copy as fallback (copy works across filesystems)
					$copied = @copy($temp_file, $full_file_path);
					$force_log('STLS File Sync [LIVE]: Copy from temp to final location result: ' . var_export($copied, true));

					if (!$copied) {
						$copy_error = error_get_last();
						if ($copy_error) {
							$force_log('STLS File Sync [LIVE]: Copy error: ' . $copy_error['message']);
						}

						// Try using WordPress Filesystem API copy
						if (!empty($wp_filesystem) && method_exists($wp_filesystem, 'copy')) {
							$force_log('STLS File Sync [LIVE]: Attempting WordPress Filesystem API copy');
							$wp_copy = $wp_filesystem->copy($temp_file, $full_file_path, true);
							if ($wp_copy) {
								$force_log('STLS File Sync [LIVE]: WordPress Filesystem API copy succeeded!');
								@unlink($temp_file);
								$file_written = strlen($file_body);
								$copied = true;
							} else {
								$force_log('STLS File Sync [LIVE]: WordPress Filesystem API copy failed');
							}
						}

						if (!$copied) {
							// Last resort: try direct write
							$force_log('STLS File Sync [LIVE]: Copy failed, attempting direct write as last resort');
							$direct_write = @file_put_contents($full_file_path, $file_body, LOCK_EX);

							if ($direct_write !== false) {
								$force_log('STLS File Sync [LIVE]: Direct write succeeded!');
								@unlink($temp_file);
								$file_written = $direct_write;
							} else {
								$direct_error = error_get_last();
								if ($direct_error) {
									$force_log('STLS File Sync [LIVE]: Direct write error: ' . $direct_error['message']);
								}

								// ALTERNATIVE APPROACH: File is already saved in uploads/stls-temp/
								// Keep it there and return success with instructions
								$force_log('STLS File Sync [LIVE]: All write methods failed, using alternative approach');
								$force_log('STLS File Sync [LIVE]: File successfully saved to: ' . $temp_file);
								$force_log('STLS File Sync [LIVE]: Target location: ' . $full_file_path);

								// Store file mapping for potential manual sync or retry
								$sync_queue = get_option('stls_file_sync_queue', array());
								$sync_queue[] = array(
									'temp_file' => $temp_file,
									'target_file' => $full_file_path,
									'file_path' => $file_path,
									'timestamp' => current_time('mysql'),
									'file_size' => strlen($file_body),
								);
								update_option('stls_file_sync_queue', $sync_queue);

								$force_log('STLS File Sync [LIVE]: File added to sync queue. Total queued files: ' . count($sync_queue));

								// Return success with warning - file is saved, just needs manual move
								return rest_ensure_response(array(
									'success' => true,
									'file_path' => $file_path,
									'warning' => true,
									'message' => sprintf(
										__('File synced to temporary location due to permission restrictions. File saved to: %s. Please move it manually to: %s via SFTP/SSH or WP Engine User Portal.', 'staging-to-live-sync'),
										str_replace(WP_CONTENT_DIR, 'wp-content', $temp_file),
										str_replace(WP_CONTENT_DIR, 'wp-content', $full_file_path)
									),
									'temp_location' => str_replace(WP_CONTENT_DIR, 'wp-content', $temp_file),
									'target_location' => str_replace(WP_CONTENT_DIR, 'wp-content', $full_file_path),
									'instructions' => __('You can move the file manually via SFTP/SSH or use WP Engine User Portal file manager.', 'staging-to-live-sync'),
								));
							}
						} else {
							@unlink($temp_file);
							$force_log('STLS File Sync [LIVE]: Successfully copied temp file to final location');
						}
					} else {
						@unlink($temp_file);
						$force_log('STLS File Sync [LIVE]: Successfully copied temp file to final location');
					}
				} else {
					$force_log('STLS File Sync [LIVE]: Successfully renamed temp file to final location');
				}
			}
		}

		$force_log('STLS File Sync [LIVE]: File downloaded and written successfully');
		$force_log('STLS File Sync [LIVE]: File size: ' . $file_written . ' bytes');
		$force_log('STLS File Sync [LIVE]: File path: ' . $full_file_path);

		// Set proper file permissions - use FS_CHMOD_FILE if defined
		$file_perms = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0664;
		@chmod($full_file_path, $file_perms);

		// File has been written directly to destination, no need to move
		$force_log('STLS File Sync [LIVE]: File sync completed successfully');
		if ($debug_mode) {
			error_log('STLS File Sync: Successfully synced file - ' . $file_path);
		}

		$write_log('STLS File Sync [LIVE]: File sync completed successfully');

		return rest_ensure_response(array(
			'success' => true,
			'file_path' => $file_path,
			'message' => __('File synced successfully.', 'staging-to-live-sync'),
		));

	} catch (Exception $e) {
		// Catch any exceptions and return proper error
		$error_message = 'Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
		$write_log('STLS File Sync [LIVE]: FATAL ERROR - ' . $error_message);
		$write_log('STLS File Sync [LIVE]: Stack trace: ' . $e->getTraceAsString());

		return new WP_Error(
			'sync_exception',
			__('File sync failed with exception: ', 'staging-to-live-sync') . $e->getMessage(),
			array(
				'status' => 500,
				'exception' => $error_message,
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			)
		);
	} catch (Error $e) {
		// Catch fatal errors (PHP 7+)
		$error_message = 'Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
		$write_log('STLS File Sync [LIVE]: FATAL ERROR - ' . $error_message);
		$write_log('STLS File Sync [LIVE]: Stack trace: ' . $e->getTraceAsString());

		return new WP_Error(
			'sync_fatal_error',
			__('File sync failed with fatal error: ', 'staging-to-live-sync') . $e->getMessage(),
			array(
				'status' => 500,
				'error' => $error_message,
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			)
		);
	}
}

/**
 * Download and attach file from URL
 * Supports all file types: images, audio (mp3, ogg, wav, m4a, flac, etc.), videos, PDFs, etc.
 */
function stls_download_and_attach_image($image_url, $post_id, $is_elementor_context = false)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	if ($debug_mode) {
		error_log('STLS Download: Starting download for URL: ' . $image_url);
	}

	if (empty($image_url)) {
		if ($debug_mode) {
			error_log('STLS Download: Empty URL provided');
		}
		return new WP_Error('empty_url', 'Empty URL provided');
	}

	// Get file name from URL
	$filename = basename($image_url);
	if (empty($filename)) {
		return new WP_Error('invalid_url', 'Could not extract filename from URL');
	}

	// Make sure required admin files are loaded
	require_once(ABSPATH . 'wp-admin/includes/file.php');
	require_once(ABSPATH . 'wp-admin/includes/media.php');
	require_once(ABSPATH . 'wp-admin/includes/image.php');

	// Download file
	$response = wp_remote_get($image_url, array('timeout' => 30, 'sslverify' => false));

	if (is_wp_error($response)) {
		if ($debug_mode) {
			error_log('STLS Download: Request failed - ' . $response->get_error_message());
		}
		return $response;
	}

	$response_code = wp_remote_retrieve_response_code($response);
	if ($response_code !== 200) {
		if ($debug_mode) {
			error_log('STLS Download: Unexpected response code - ' . $response_code);
		}
		return new WP_Error('download_failed', 'Download failed with status code ' . $response_code);
	}

	$file_content = wp_remote_retrieve_body($response);
	if (empty($file_content)) {
		if ($debug_mode) {
			error_log('STLS Download: Empty response body');
		}
		return new WP_Error('download_failed', 'Empty file content');
	}

	if ($debug_mode) {
		error_log('STLS Download: Successfully downloaded ' . strlen($file_content) . ' bytes');
	}

	// Create temp file
	$upload_dir = wp_upload_dir();
	$tmp_file = $upload_dir['basedir'] . '/tmp_' . $filename;

	if (!file_put_contents($tmp_file, $file_content)) {
		if ($debug_mode) {
			error_log('STLS Download: Failed to write temp file: ' . $tmp_file);
		}
		return new WP_Error('write_failed', 'Could not write temporary file');
	}

	if ($debug_mode) {
		error_log('STLS Download: Wrote temp file: ' . $tmp_file);
	}

	// Extract upload path from staging URL to preserve original folder structure
	$upload_path = '';
	$url_path = parse_url($image_url, PHP_URL_PATH);
	if ($url_path && strpos($url_path, '/wp-content/uploads/') !== false) {
		$path_parts = explode('/wp-content/uploads/', $url_path);
		if (isset($path_parts[1])) {
			$relative_path = $path_parts[1];
			$upload_path = dirname($relative_path);
			$upload_path = str_replace('wp-content/uploads/', '', $upload_path);
			$upload_path = trim($upload_path, '/');
		}
	}

	$upload_dir_filter_callback = null;
	if (!empty($upload_path)) {
		$upload_dir_filter_callback = function ($dirs) use ($upload_path) {
			$custom_path = $dirs['basedir'] . '/' . $upload_path;
			$custom_url = $dirs['baseurl'] . '/' . $upload_path;
			if (!file_exists($custom_path)) {
				wp_mkdir_p($custom_path);
			}
			$dirs['path'] = $custom_path;
			$dirs['url'] = $custom_url;
			$dirs['subdir'] = '/' . $upload_path;
			return $dirs;
		};
		add_filter('upload_dir', $upload_dir_filter_callback, 999);
	}

	$file_array = array(
		'name' => $filename,
		'tmp_name' => $tmp_file,
	);

	// Add filters to allow SVG
	$svg_mime_filter = function ($mimes) {
		$mimes['svg'] = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
		return $mimes;
	};
	add_filter('upload_mimes', $svg_mime_filter, 999);

	$svg_filetype_filter = function ($data, $file, $filename, $mimes) {
		$filetype = wp_check_filetype($filename, $mimes);
		if ($filetype['ext'] === 'svg' || $filetype['ext'] === 'svgz') {
			$data['ext'] = $filetype['ext'];
			$data['type'] = $filetype['type'];
			$data['proper_filename'] = $filename;
		}
		return $data;
	};
	add_filter('wp_check_filetype_and_ext', $svg_filetype_filter, 999, 4);

	// Bypass Safe SVG plugin restrictions if active
	add_filter('safe_svg_current_user_can_upload', '__return_true', 999);

	if ($debug_mode) {
		error_log('STLS Download: Calling media_handle_sideload for ' . $filename);
	}

	// Perform sideload
	$attachment_id = media_handle_sideload($file_array, $post_id);

	if ($debug_mode) {
		if (is_wp_error($attachment_id)) {
			error_log('STLS Download: media_handle_sideload failed - ' . $attachment_id->get_error_message());
		} else {
			error_log('STLS Download: media_handle_sideload success - Attachment ID: ' . $attachment_id);
		}
	}

	// Clean up filters
	remove_filter('upload_mimes', $svg_mime_filter, 999);
	remove_filter('wp_check_filetype_and_ext', $svg_filetype_filter, 999);
	remove_filter('safe_svg_current_user_can_upload', '__return_true', 999);
	if ($upload_dir_filter_callback) {
		remove_filter('upload_dir', $upload_dir_filter_callback, 999);
	}

	// Clean up temp file
	if (file_exists($tmp_file)) {
		@unlink($tmp_file);
	}

	return $attachment_id;
}

/**
 * Get attachment by filename
 */
function stls_get_attachment_by_filename($filename)
{
	global $wpdb;

	$attachment = $wpdb->get_col($wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} 
		WHERE post_type = 'attachment' 
		AND guid LIKE %s 
		LIMIT 1",
		'%' . $wpdb->esc_like($filename)
	));

	if (!empty($attachment)) {
		return $attachment[0];
	}

	return false;
}

/**
 * Sync ACF Gutenberg blocks on live site
 */
function stls_sync_acf_blocks($post_id, $acf_blocks, $staging_url = '')
{
	if (!function_exists('update_field') || !function_exists('acf_get_block_types')) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('STLS: ACF functions not available for block syncing');
		}
		return;
	}

	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	if ($debug_mode) {
		error_log('STLS: Starting ACF block sync for post ' . $post_id . ' with ' . count($acf_blocks) . ' blocks');
	}

	foreach ($acf_blocks as $block_index => $block_data) {
		$block_id = isset($block_data['blockId']) ? $block_data['blockId'] : '';
		$block_name = isset($block_data['blockName']) ? $block_data['blockName'] : 'unknown';
		$block_fields = isset($block_data['fields']) ? $block_data['fields'] : array();

		if ($debug_mode) {
			error_log('STLS: Processing block ' . ($block_index + 1) . ' - Name: ' . $block_name . ', ID: ' . $block_id . ', Fields: ' . count($block_fields));
		}

		// Update each field in the block
		if (!empty($block_fields)) {
			foreach ($block_fields as $field_name => $field_data) {
				if (isset($field_data['key']) && isset($field_data['value'])) {
					$field_key = $field_data['key'];
					$field_type = isset($field_data['type']) ? $field_data['type'] : 'unknown';
					$field_value_original = $field_data['value'];

					if ($debug_mode) {
						error_log('STLS: Processing field - Key: ' . $field_key . ', Name: ' . $field_name . ', Type: ' . $field_type);
						if (is_array($field_value_original)) {
							error_log('STLS: Field value is array: ' . print_r($field_value_original, true));
						} else {
							error_log('STLS: Field value: ' . var_export($field_value_original, true));
						}
					}

					// Check if this is an image field with URL data
					$field_value = $field_value_original;

					// Pass field key to determine return format
					if (isset($field_data['type']) && $field_data['type'] === 'image') {
						// Convert image URL data to attachment ID
						if ($debug_mode) {
							error_log('STLS: Converting image field value');
						}
						$field_value = stls_convert_image_data_to_attachment_id($field_data['value'], $post_id, $field_data['key']);
					} else {
						// Recursively process nested arrays for images
						if ($debug_mode) {
							error_log('STLS: Recursively processing field value for images');
						}
						$field_value = stls_convert_image_data_to_attachment_id($field_data['value'], $post_id, $field_data['key']);
					}

					if ($debug_mode) {
						error_log('STLS: Converted field value: ' . var_export($field_value, true));
					}

					// Update the field using ACF's update_field function
					$update_result = update_field($field_data['key'], $field_value, $post_id);

					if ($debug_mode) {
						error_log('STLS: Updated field ' . $field_key . ' - Result: ' . var_export($update_result, true));
					}

					// Also update using field name if needed
					if (!empty($field_name) && $field_name !== $field_data['key']) {
						$update_result2 = update_field($field_name, $field_value, $post_id);
						if ($debug_mode) {
							error_log('STLS: Updated field by name ' . $field_name . ' - Result: ' . var_export($update_result2, true));
						}
					}
				}
			}
		} else {
			if ($debug_mode) {
				error_log('STLS: No fields found in block ' . $block_name);
			}
		}

		// Update block attributes if they contain ACF data or images
		// ACF blocks store field values in attrs['data'] array
		if (isset($block_data['attrs']) && is_array($block_data['attrs'])) {
			if ($debug_mode) {
				error_log('STLS: Processing block attributes: ' . print_r($block_data['attrs'], true));
			}

			// Process 'data' array within attributes (ACF block format)
			if (isset($block_data['attrs']['data']) && is_array($block_data['attrs']['data'])) {
				if ($debug_mode) {
					error_log('STLS: Processing block data attributes');
				}

				foreach ($block_data['attrs']['data'] as $data_key => $data_value) {
					// Skip field keys (they start with underscore)
					if (strpos($data_key, '_') === 0) {
						continue;
					}

					if ($debug_mode) {
						error_log('STLS: Processing data attribute - Key: ' . $data_key . ', Value type: ' . gettype($data_value) . ', Value: ' . var_export($data_value, true));
					}

					// Check if value is still a numeric ID (staging attachment ID)
					if (is_numeric($data_value) && $data_value > 0) {
						// This is a staging attachment ID - we need to download the image from staging
						// Check if this attachment exists on live site (it shouldn't, but check anyway)
						$attachment = get_post($data_value);
						if (!$attachment || $attachment->post_type !== 'attachment') {
							// Attachment doesn't exist on live site, download from staging
							if (!empty($staging_url)) {
								// Construct the staging image URL using REST API
								$staging_image_url = trailingslashit($staging_url) . 'wp-json/wp/v2/media/' . $data_value;

								// Try to get the image URL from staging REST API
								$response = wp_remote_get($staging_image_url);
								if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
									$media_data = json_decode(wp_remote_retrieve_body($response), true);
									if (isset($media_data['source_url'])) {
										$image_url = $media_data['source_url'];
										$converted_value = stls_download_and_attach_image($image_url, $post_id);

										if ($converted_value && !is_wp_error($converted_value)) {
											// Ensure it's an integer ID
											$attachment_id = intval($converted_value);

											$field_key = isset($block_data['attrs']['data']['_' . $data_key]) ? $block_data['attrs']['data']['_' . $data_key] : '';
											if (!empty($field_key) && function_exists('update_field')) {
												// For block attributes, always use just the attachment ID (not an array)
												update_field($field_key, $attachment_id, $post_id);
											}
											// Store just the ID in post meta
											update_post_meta($post_id, $data_key, $attachment_id);

											if ($debug_mode) {
												error_log('STLS: Handled numeric staging attachment ID in data attribute - Downloaded from: ' . $image_url . ', Attached as ID: ' . $attachment_id);
											}
										}
									}
								} elseif ($debug_mode) {
									error_log('STLS: Failed to fetch staging attachment data for ID: ' . $data_value . ' from URL: ' . $staging_image_url);
								}
							} elseif ($debug_mode) {
								error_log('STLS: No staging URL provided, cannot download attachment for numeric ID: ' . $data_value);
							}
						} else {
							// Attachment exists on live site with same ID (unlikely but possible)
							if ($debug_mode) {
								error_log('STLS: Attachment ID ' . $data_value . ' already exists on live site, using existing attachment');
							}
						}
						continue;
					}

					// Check if this is image data (from staging)
					if (is_array($data_value) && isset($data_value['attachment_id']) && isset($data_value['url'])) {
						if ($debug_mode) {
							error_log('STLS: Found image data in data attribute - URL: ' . $data_value['url'] . ', ID: ' . $data_value['attachment_id']);
						}

						// Convert image data to attachment ID
						$converted_value = stls_convert_image_data_to_attachment_id($data_value, $post_id, $data_key);

						if ($debug_mode) {
							error_log('STLS: Converted data attribute value: ' . var_export($converted_value, true));
						}

						// For ACF blocks, we need to update the field using update_field
						// But also update the block attribute in post content
						// First, try to get the field key from the corresponding _field_key
						$field_key = isset($block_data['attrs']['data']['_' . $data_key]) ? $block_data['attrs']['data']['_' . $data_key] : '';

						if ($debug_mode) {
							error_log('STLS: Field key for ' . $data_key . ': ' . $field_key);
							error_log('STLS: Converted value type: ' . gettype($converted_value) . ', Value: ' . var_export($converted_value, true));
						}

						// For block attributes, we need to extract just the attachment ID
						// even if the ACF field return format is 'array'
						// Block attributes expect just the ID (e.g., "sef_img_txt":10110), not an array
						$attachment_id_for_field = $converted_value;
						if (is_array($converted_value)) {
							// Extract ID from array
							if (isset($converted_value['ID'])) {
								$attachment_id_for_field = intval($converted_value['ID']);
							} elseif (isset($converted_value['id'])) {
								$attachment_id_for_field = intval($converted_value['id']);
							} elseif (isset($converted_value['attachment_id'])) {
								$attachment_id_for_field = intval($converted_value['attachment_id']);
							}
						} elseif (is_string($converted_value) && is_numeric($converted_value)) {
							$attachment_id_for_field = intval($converted_value);
						}

						// Ensure we have a numeric ID
						if (!is_numeric($attachment_id_for_field)) {
							$attachment_id_for_field = $converted_value;
						}

						if (!empty($field_key) && function_exists('update_field')) {
							// For block attributes, always use just the attachment ID (not the array)
							// This ensures the block attribute shows the ID, matching the staging format
							$field_update_result = update_field($field_key, $attachment_id_for_field, $post_id);
							if ($debug_mode) {
								error_log('STLS: Updated ACF field - Key: ' . $field_key . ', Value (ID only): ' . $attachment_id_for_field . ', Result: ' . var_export($field_update_result, true));
							}

							// Also try updating by field name
							if (!$field_update_result) {
								$field_update_result2 = update_field($data_key, $attachment_id_for_field, $post_id);
								if ($debug_mode) {
									error_log('STLS: Tried updating by field name - Key: ' . $data_key . ', Value (ID only): ' . $attachment_id_for_field . ', Result: ' . var_export($field_update_result2, true));
								}
							}
						}

						// For block attributes in post meta, store just the attachment ID (not the array)
						// This ensures the block attribute shows the ID, not the full array
						update_post_meta($post_id, $data_key, $attachment_id_for_field);
						if (!empty($field_key)) {
							// Store the ID for the field key meta as well
							update_post_meta($post_id, $field_key, $attachment_id_for_field);
						}

						if ($debug_mode) {
							error_log('STLS: Updated post meta - Key: ' . $data_key . ', Value (ID only): ' . $attachment_id_for_field);
						}

						if ($debug_mode) {
							error_log('STLS: Updated block data attribute image - Key: ' . $data_key . ', Value: ' . (is_numeric($converted_value) ? 'ID: ' . $converted_value : 'URL: ' . $converted_value));
						}
					} elseif (is_array($data_value)) {
						// Recursively process arrays in data attributes
						if ($debug_mode) {
							error_log('STLS: Recursively processing array data attribute');
						}
						$converted_value = stls_convert_image_data_to_attachment_id($data_value, $post_id, $data_key);
						update_post_meta($post_id, $data_key, $converted_value);
					}
				}
			}

			// Also process top-level attributes (for blocks that don't use 'data' array)
			foreach ($block_data['attrs'] as $attr_key => $attr_value) {
				// Skip 'data' as we already processed it
				if ($attr_key === 'data') {
					continue;
				}

				if ($debug_mode) {
					error_log('STLS: Processing attribute - Key: ' . $attr_key . ', Value type: ' . gettype($attr_value));
				}

				// Check if this is image data (from staging)
				if (is_array($attr_value) && isset($attr_value['attachment_id']) && isset($attr_value['url'])) {
					if ($debug_mode) {
						error_log('STLS: Found image data in attribute - URL: ' . $attr_value['url'] . ', ID: ' . $attr_value['attachment_id']);
					}

					// Convert image data to attachment ID or URL
					$converted_value = stls_convert_image_data_to_attachment_id($attr_value, $post_id, $attr_key);

					if ($debug_mode) {
						error_log('STLS: Converted attribute value: ' . var_export($converted_value, true));
					}

					// Update the attribute - check if it should be ID or URL based on original format
					if (isset($attr_value['was_url']) && $attr_value['was_url']) {
						// Original was URL, keep as URL
						if (is_numeric($converted_value)) {
							$converted_value = wp_get_attachment_url($converted_value);
						}
					}

					// Update post meta (ACF stores block attributes in meta)
					$meta_result = update_post_meta($post_id, $attr_key, $converted_value);

					if ($debug_mode) {
						error_log('STLS: Updated block attribute image - Key: ' . $attr_key . ', Value: ' . (is_numeric($converted_value) ? 'ID: ' . $converted_value : 'URL: ' . $converted_value) . ', Meta update result: ' . var_export($meta_result, true));
					}
				} elseif (is_array($attr_value)) {
					// Recursively process arrays in attributes
					if ($debug_mode) {
						error_log('STLS: Recursively processing array attribute');
					}
					$converted_value = stls_convert_image_data_to_attachment_id($attr_value, $post_id, $attr_key);
					update_post_meta($post_id, $attr_key, $converted_value);
				} else {
					// Check if this attribute is an ACF field reference
					if (strpos($attr_key, 'data') === 0 || strpos($attr_key, 'acf') === 0) {
						update_post_meta($post_id, $attr_key, $attr_value);
					}
				}
			}
		} else {
			if ($debug_mode) {
				error_log('STLS: No attributes found in block ' . $block_name);
			}
		}
	}

	// After syncing all blocks, update the post content to reflect the new block attributes
	// This ensures the block attributes in the post content match the updated field values
	// Only update ACF blocks - default Gutenberg blocks are handled separately
	$post_content = get_post_field('post_content', $post_id);
	if (!empty($post_content) && function_exists('parse_blocks') && function_exists('serialize_blocks') && !empty($acf_blocks)) {
		$blocks = parse_blocks($post_content);

		// Only update if we have ACF blocks to sync
		if (!empty($blocks)) {
			$updated_content = stls_update_acf_block_attributes_in_content($blocks, $acf_blocks, $post_id);

			if ($updated_content !== $post_content) {
				// Validate the updated content by parsing it back
				$validation_blocks = parse_blocks($updated_content);
				if (!empty($validation_blocks)) {
					kses_remove_filters();
					wp_update_post(array(
						'ID' => $post_id,
						'post_content' => wp_slash($updated_content),
					));
					kses_init_filters();

					if ($debug_mode) {
						error_log('STLS: Updated post content with new ACF block attributes');
					}
				} elseif ($debug_mode) {
					error_log('STLS: Warning - Updated content failed validation, not updating post content');
				}
			}
		}
	}

	if ($debug_mode) {
		error_log('STLS: Finished ACF block sync for post ' . $post_id);
	}
}

/**
 * Check if a block is an ACF block
 * 
 * @param string $block_name The block name to check
 * @return bool True if it's an ACF block, false otherwise
 */
function stls_is_acf_block($block_name)
{
	return !empty($block_name) && strpos($block_name, 'acf/') === 0;
}

/**
 * Check if a block is a default WordPress image block
 * 
 * @param string $block_name The block name to check
 * @return bool True if it's a default image block, false otherwise
 */
function stls_is_default_image_block($block_name)
{
	return !empty($block_name) && in_array($block_name, array(
		'core/image',
		'wp:image',
		'core/gallery',
		'wp:gallery',
		'core/cover',
		'wp:cover',
		'core/media-text',
		'wp:media-text',
		'core/file',
		'wp:file',
	), true);
}

/**
 * Check if a block is a default WordPress audio block
 * 
 * @param string $block_name The block name to check
 * @return bool True if it's a default audio block, false otherwise
 */
function stls_is_default_audio_block($block_name)
{
	return $block_name === 'core/audio' || $block_name === 'wp:audio';
}

/**
 * Check if a block is a default WordPress video block
 * 
 * @param string $block_name The block name to check
 * @return bool True if it's a default video block, false otherwise
 */
function stls_is_default_video_block($block_name)
{
	return $block_name === 'core/video' || $block_name === 'wp:video';
}

/**
 * Recursively replace URLs in block attributes
 */
function stls_replace_urls_in_attrs($attrs, $old_url, $new_url)
{
	if (empty($old_url) || empty($new_url)) {
		return $attrs;
	}

	// Prepare multiple search variations
	$old_url_escaped = str_replace('/', '\/', $old_url);
	$new_url_escaped = str_replace('/', '\/', $new_url);

	$search = array($old_url, $old_url_escaped);
	$replace = array($new_url, $new_url_escaped);

	foreach ($attrs as $key => $value) {
		if (is_string($value)) {
			$attrs[$key] = str_replace($search, $replace, $value);
		} elseif (is_array($value)) {
			$attrs[$key] = stls_replace_urls_in_attrs($value, $old_url, $new_url);
		}
	}
	return $attrs;
}

/**
 * Sync default WordPress image blocks
 */
function stls_sync_image_blocks($post_id, $image_blocks)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	if ($debug_mode) {
		error_log('STLS: Starting image block sync for post ' . $post_id . ' with ' . count($image_blocks) . ' blocks');
	}

	if (empty($image_blocks) || !is_array($image_blocks)) {
		return;
	}

	// Get post content
	$post_content = get_post_field('post_content', $post_id);
	if (empty($post_content) || !function_exists('parse_blocks')) {
		return;
	}

	$blocks = parse_blocks($post_content);
	$updated = false;

	// Process each image block
	foreach ($image_blocks as $image_block_data) {
		if (!isset($image_block_data['image']) || !isset($image_block_data['image']['url'])) {
			continue;
		}

		$staging_image_data = $image_block_data['image'];
		$staging_url = $staging_image_data['url'];

		// Download and attach the image
		$attachment_id = stls_convert_image_data_to_attachment_id($staging_image_data, $post_id);

		if ($attachment_id && !is_wp_error($attachment_id) && is_numeric($attachment_id)) {
			// Find and update the corresponding block in the content
			$blocks = stls_update_image_block_in_blocks($blocks, $staging_image_data, $attachment_id, $updated);

			if ($debug_mode) {
				error_log('STLS: Synced image block - URL: ' . $staging_url . ', New Attachment ID: ' . $attachment_id);
			}
		} elseif ($debug_mode) {
			error_log('STLS: Failed to sync image block - URL: ' . $staging_url);
		}
	}

	// Update post content if blocks were modified
	// Only update default Gutenberg image blocks - preserve all other blocks as-is
	if ($updated) {
		// Don't use stls_ensure_valid_block_structure - it can corrupt blocks
		// WordPress parse_blocks() already provides valid structure
		$updated_content = serialize_blocks($blocks);

		// Validate the serialized content by parsing it back
		$validation_blocks = parse_blocks($updated_content);
		if (!empty($validation_blocks)) {
			kses_remove_filters();
			wp_update_post(array(
				'ID' => $post_id,
				'post_content' => wp_slash($updated_content),
			));
			kses_init_filters();

			if ($debug_mode) {
				error_log('STLS: Updated post content with synced default Gutenberg image blocks');
			}
		} elseif ($debug_mode) {
			error_log('STLS: Warning - Serialized content failed validation, not updating post content');
		}
	}
}

/**
 * Recursively update image blocks in block array
 */
function stls_update_image_block_in_blocks($blocks, $staging_image_data, $new_attachment_id, &$updated)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	foreach ($blocks as &$block) {
		if (empty($block['blockName'])) {
			continue;
		}

		// Check if this is an image/legacy media block
		if (stls_is_default_image_block($block['blockName']) && isset($block['attrs'])) {
			// Check multiple possible URL attributes
			$block_url = '';
			if (isset($block['attrs']['mediaUrl'])) {
				$block_url = $block['attrs']['mediaUrl'];
			} elseif (isset($block['attrs']['href'])) {
				$block_url = $block['attrs']['href'];
			} elseif (isset($block['attrs']['url'])) {
				$block_url = $block['attrs']['url'];
			}

			// Check multiple possible ID attributes
			$block_id = 0;
			if (isset($block['attrs']['mediaId'])) {
				$block_id = intval($block['attrs']['mediaId']);
			} elseif (isset($block['attrs']['id'])) {
				$block_id = intval($block['attrs']['id']);
			}

			$staging_url = $staging_image_data['url'];
			$staging_id = isset($staging_image_data['attachment_id']) ? intval($staging_image_data['attachment_id']) : 0;

			// Check if this is the block we need to update (match by URL or ID)
			$is_match = false;
			if ($staging_id > 0 && $block_id === $staging_id) {
				$is_match = true;
			} elseif (!empty($block_url) && !empty($staging_url)) {
				// Compare URLs (handle different URL formats)
				$block_url_normalized = str_replace(array('http://', 'https://'), '', $block_url);
				$staging_url_normalized = str_replace(array('http://', 'https://'), '', $staging_url);
				if ($block_url_normalized === $staging_url_normalized || basename($block_url) === basename($staging_url)) {
					$is_match = true;
				}
			}

			// Special case: Gallery block 'ids' array
			if (!$is_match && ($block['blockName'] === 'core/gallery' || $block['blockName'] === 'wp:gallery') && isset($block['attrs']['ids']) && is_array($block['attrs']['ids'])) {
				if ($staging_id > 0 && in_array($staging_id, $block['attrs']['ids'])) {
					// We need to update this ID in the array
					$ids = $block['attrs']['ids'];
					$key = array_search($staging_id, $ids);
					if ($key !== false) {
						$ids[$key] = $new_attachment_id;
						$block['attrs']['ids'] = $ids;

						// Set match to true to trigger HTML/innerHTML update below
						$is_match = true;
						$block_url = $staging_image_data['url']; // Use staging URL as the 'old' URL to replace in HTML
					}
				}
			}

			if ($is_match) {
				// Get new attachment URL
				$new_url = wp_get_attachment_url($new_attachment_id);
				$old_url = $block_url;

				// If we have no new URL but have a staging URL, construct a live URL as fallback
				if (empty($new_url) && !empty($staging_url)) {
					$live_domain_url = home_url();
					$staging_domain_url = get_option('stls_staging_url', $staging_url);
					if (!empty($staging_domain_url)) {
						$new_url = str_replace(untrailingslashit($staging_domain_url), untrailingslashit($live_domain_url), $staging_url);
					}
				}

				// If we still have no old_url reference from block, use staging_url
				if (empty($old_url)) {
					$old_url = $staging_url;
				}

				// Update block attributes - update both if they exist
				if (isset($block['attrs']['mediaId'])) {
					$block['attrs']['mediaId'] = $new_attachment_id;
				}
				if (isset($block['attrs']['id'])) {
					$block['attrs']['id'] = $new_attachment_id;
				}

				if ($new_url) {
					$attributes_to_update = array('mediaUrl', 'href', 'url', 'textLinkHref', 'textLinkURL');
					foreach ($attributes_to_update as $attr) {
						if (isset($block['attrs'][$attr])) {
							$block['attrs'][$attr] = $new_url;
						}
					}

					// Also replace old URL with new URL in ALL string attributes recursively
					$block['attrs'] = stls_replace_urls_in_attrs($block['attrs'], $old_url, $new_url);
				}

				// Handle alt text
				$alt_text = get_post_meta($new_attachment_id, '_wp_attachment_image_alt', true);
				if (empty($alt_text)) {
					// Remove alt attribute if empty to avoid WordPress warning
					if (isset($block['attrs']['alt'])) {
						unset($block['attrs']['alt']);
					}
				} else {
					$block['attrs']['alt'] = $alt_text;
				}

				// Update innerHTML to reflect new URL, alt text, and ID class
				// This is critical - WordPress validates that innerHTML matches the attributes
				if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
					// Prepare search variations for HTML replacement
					if (!empty($old_url) && !empty($new_url)) {
						$old_url_escaped = str_replace('/', '\/', $old_url);
						$new_url_escaped = str_replace('/', '\/', $new_url);

						$search = array($old_url, $old_url_escaped);
						$replace = array($new_url, $new_url_escaped);

						// Replace all occurrences in HTML
						$block['innerHTML'] = str_replace($search, $replace, $block['innerHTML']);

						// Also search for src, data, href, data-wp-context, data-wp-bind specifically
						// \b ensures we match exactly the attribute name
						if (preg_match_all('/\b(src|data|href|data-wp-context|data-wp-bind)=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches, PREG_SET_ORDER)) {
							foreach ($matches as $match) {
								$attr_name = $match[1];
								$attr_value = $match[2];

								// If it matches by full URL, escaped URL, or basename
								if (
									strpos($attr_value, $old_url) !== false ||
									strpos(str_replace('\/', '/', $attr_value), $old_url) !== false ||
									basename($attr_value) === basename($old_url)
								) {

									// Replace all variations within this attribute value
									$new_attr_value = str_replace($search, $replace, $attr_value);

									// If still not changed but basename matches, try a more aggressive approach for the value
									if ($new_attr_value === $attr_value && basename($attr_value) === basename($old_url)) {
										$new_attr_value = str_replace(basename($attr_value), basename($new_url), $attr_value);
									}

									if ($new_attr_value !== $attr_value) {
										$block['innerHTML'] = str_replace($attr_name . '="' . $attr_value . '"', $attr_name . '="' . $new_attr_value . '"', $block['innerHTML']);
										$block['innerHTML'] = str_replace($attr_name . "='" . $attr_value . "'", $attr_name . "='" . $new_attr_value . "'", $block['innerHTML']);
									}
								}
							}
						}
					}

					// 2. Update alt text if available
					if (!empty($alt_text)) {
						$block['innerHTML'] = preg_replace(
							'/(<img[^>]*\s+alt=["\'])[^"\']*(["\'])/i',
							'$1' . esc_attr($alt_text) . '$2',
							$block['innerHTML']
						);
					} else {
						// Remove alt attribute if empty
						$block['innerHTML'] = preg_replace(
							'/(<img[^>]*\s+)alt=["\'][^"\']*["\']/i',
							'$1',
							$block['innerHTML']
						);
					}

					// 3. Update wp-image-{ID} class - THIS IS CRITICAL FOR VALIDATION
					if ($staging_id > 0 && $new_attachment_id > 0) {
						$block['innerHTML'] = str_replace('wp-image-' . $staging_id, 'wp-image-' . $new_attachment_id, $block['innerHTML']);
					}
				}

				// Update innerContent array to match innerHTML
				if (isset($block['innerContent']) && is_array($block['innerContent'])) {
					foreach ($block['innerContent'] as $key => $content) {
						if (is_string($content)) {
							// Prepare search variations for content replacement
							if (!empty($old_url) && !empty($new_url)) {
								$old_url_escaped = str_replace('/', '\/', $old_url);
								$new_url_escaped = str_replace('/', '\/', $new_url);

								$search = array($old_url, $old_url_escaped);
								$replace = array($new_url, $new_url_escaped);

								// Replace all occurrences in content string
								$block['innerContent'][$key] = str_replace($search, $replace, $content);

								// Also search specifically for src/data/href/data-wp-context attribute patterns
								if (preg_match_all('/\b(src|data|href|data-wp-context|data-wp-bind)=["\']([^"\']+)["\']/i', $block['innerContent'][$key], $matches, PREG_SET_ORDER)) {
									foreach ($matches as $match) {
										$attr_name = $match[1];
										$attr_value = $match[2];

										if (
											strpos($attr_value, $old_url) !== false ||
											strpos(str_replace('\/', '/', $attr_value), $old_url) !== false ||
											basename($attr_value) === basename($old_url)
										) {

											$new_attr_value = str_replace($search, $replace, $attr_value);

											if ($new_attr_value === $attr_value && basename($attr_value) === basename($old_url)) {
												$new_attr_value = str_replace(basename($attr_value), basename($new_url), $attr_value);
											}

											if ($new_attr_value !== $attr_value) {
												$block['innerContent'][$key] = str_replace($attr_name . '="' . $attr_value . '"', $attr_name . '="' . $new_attr_value . '"', $block['innerContent'][$key]);
												$block['innerContent'][$key] = str_replace($attr_name . "='" . $attr_value . "'", $attr_name . "='" . $new_attr_value . "'", $block['innerContent'][$key]);
											}
										}
									}
								}
							}

							// 2. Update alt text if available
							if (!empty($alt_text)) {
								$block['innerContent'][$key] = preg_replace(
									'/(<img[^>]*\s+alt=["\'])[^"\']*(["\'])/i',
									'$1' . esc_attr($alt_text) . '$2',
									$block['innerContent'][$key]
								);
							} else {
								$block['innerContent'][$key] = preg_replace(
									'/(<img[^>]*\s+)alt=["\'][^"\']*["\']/i',
									'$1',
									$block['innerContent'][$key]
								);
							}

							// 3. Update wp-image-{ID} class
							if ($staging_id > 0 && $new_attachment_id > 0) {
								$block['innerContent'][$key] = str_replace('wp-image-' . $staging_id, 'wp-image-' . $new_attachment_id, $block['innerContent'][$key]);
							}
						}
					}
				}

				$updated = true;

				if ($debug_mode) {
					error_log('STLS: Updated media block - Old ID: ' . $block_id . ', New ID: ' . $new_attachment_id . ', Old URL: ' . $old_url . ', New URL: ' . ($new_url ? $new_url : 'None'));
				}
			}
		}

		// Recursively process inner blocks
		if (!empty($block['innerBlocks'])) {
			$block['innerBlocks'] = stls_update_image_block_in_blocks($block['innerBlocks'], $staging_image_data, $new_attachment_id, $updated);
		}
	}

	return $blocks;
}

/**
 * Sync default WordPress audio blocks
 * Supports all WordPress audio formats: .mp3, .ogg, .wav, .m4a, .flac, etc.
 */
function stls_sync_audio_blocks($post_id, $audio_blocks)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	if ($debug_mode) {
		error_log('STLS: Starting audio block sync for post ' . $post_id . ' with ' . count($audio_blocks) . ' blocks');
	}

	if (empty($audio_blocks) || !is_array($audio_blocks)) {
		return;
	}

	// Get post content
	$post_content = get_post_field('post_content', $post_id);
	if (empty($post_content) || !function_exists('parse_blocks')) {
		return;
	}

	$blocks = parse_blocks($post_content);
	$updated = false;

	// Process each audio block
	foreach ($audio_blocks as $audio_block_data) {
		if (!isset($audio_block_data['audio']) || !isset($audio_block_data['audio']['url'])) {
			continue;
		}

		$staging_audio_data = $audio_block_data['audio'];
		$staging_url = $staging_audio_data['url'];

		if ($debug_mode) {
			error_log('STLS: Processing audio block - URL: ' . $staging_url . ', Attachment ID: ' . (isset($staging_audio_data['attachment_id']) ? $staging_audio_data['attachment_id'] : 'none'));
		}

		// Download and attach the audio file
		$attachment_id = stls_convert_image_data_to_attachment_id($staging_audio_data, $post_id);

		if ($attachment_id && !is_wp_error($attachment_id) && is_numeric($attachment_id)) {
			// Find and update the corresponding block in the content
			$blocks = stls_update_audio_block_in_blocks($blocks, $staging_audio_data, $attachment_id, $updated);

			if ($debug_mode) {
				error_log('STLS: Synced audio block - URL: ' . $staging_url . ', New Attachment ID: ' . $attachment_id);
			}
		} elseif ($debug_mode) {
			if (is_wp_error($attachment_id)) {
				error_log('STLS: Failed to sync audio block - URL: ' . $staging_url . ', Error: ' . $attachment_id->get_error_message());
			} else {
				error_log('STLS: Failed to sync audio block - URL: ' . $staging_url . ', Attachment ID returned: ' . print_r($attachment_id, true));
			}
		}
	}

	// Update post content if blocks were modified
	// Only update default Gutenberg audio blocks - preserve all other blocks as-is
	if ($updated) {
		// Don't use stls_ensure_valid_block_structure - it can corrupt blocks
		// WordPress parse_blocks() already provides valid structure
		$updated_content = serialize_blocks($blocks);

		// Validate the serialized content by parsing it back
		$validation_blocks = parse_blocks($updated_content);
		if (!empty($validation_blocks)) {
			kses_remove_filters();
			wp_update_post(array(
				'ID' => $post_id,
				'post_content' => wp_slash($updated_content),
			));
			kses_init_filters();

			if ($debug_mode) {
				error_log('STLS: Updated post content with synced default Gutenberg audio blocks');
			}
		} elseif ($debug_mode) {
			error_log('STLS: Warning - Serialized content failed validation, not updating post content');
		}
	}
}

/**
 * Recursively update audio blocks in block array
 */
function stls_update_audio_block_in_blocks($blocks, $staging_audio_data, $new_attachment_id, &$updated)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	foreach ($blocks as &$block) {
		if (empty($block['blockName'])) {
			continue;
		}

		// Check if this is an audio block
		if (stls_is_default_audio_block($block['blockName']) && isset($block['attrs'])) {
			$block_src = isset($block['attrs']['src']) ? $block['attrs']['src'] : '';
			$block_id = isset($block['attrs']['id']) ? intval($block['attrs']['id']) : 0;
			$staging_url = $staging_audio_data['url'];
			$staging_id = isset($staging_audio_data['attachment_id']) ? intval($staging_audio_data['attachment_id']) : 0;

			// Also check innerHTML for audio URL
			$block_html_url = '';
			if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
				if (preg_match('/<audio[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
					$block_html_url = $matches[1];
				} elseif (preg_match('/<source[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
					$block_html_url = $matches[1];
				}
			}

			// Check if this is the block we need to update (match by URL or ID)
			$is_match = false;
			if ($staging_id > 0 && $block_id === $staging_id) {
				$is_match = true;
			} elseif (!empty($block_src) && !empty($staging_url)) {
				// Compare URLs (handle different URL formats)
				$block_url_normalized = str_replace(array('http://', 'https://'), '', $block_src);
				$staging_url_normalized = str_replace(array('http://', 'https://'), '', $staging_url);
				if ($block_url_normalized === $staging_url_normalized || basename($block_src) === basename($staging_url)) {
					$is_match = true;
				}
			} elseif (!empty($block_html_url) && !empty($staging_url)) {
				// Compare URLs from innerHTML
				$block_url_normalized = str_replace(array('http://', 'https://'), '', $block_html_url);
				$staging_url_normalized = str_replace(array('http://', 'https://'), '', $staging_url);
				if ($block_url_normalized === $staging_url_normalized || basename($block_html_url) === basename($staging_url)) {
					$is_match = true;
				}
			}

			if ($is_match) {
				// Get new attachment URL
				$new_url = wp_get_attachment_url($new_attachment_id);
				$old_url = isset($block['attrs']['src']) ? $block['attrs']['src'] : '';
				if (empty($old_url) && !empty($block_html_url)) {
					$old_url = $block_html_url;
				}

				// Update block attributes
				$block['attrs']['id'] = $new_attachment_id;
				if ($new_url) {
					$block['attrs']['src'] = $new_url;
				}

				// Update innerHTML to reflect new URL
				// This is critical - WordPress validates that innerHTML matches the attributes
				if (isset($block['innerHTML']) && is_string($block['innerHTML']) && !empty($old_url) && !empty($new_url)) {
					// Replace old URL with new URL in innerHTML
					$block['innerHTML'] = str_replace($old_url, $new_url, $block['innerHTML']);

					// Also update src attribute in <audio> or <source> tags
					$block['innerHTML'] = preg_replace(
						'/(<audio[^>]*\s+src=["\'])([^"\']+)(["\'])/i',
						'$1' . esc_attr($new_url) . '$3',
						$block['innerHTML']
					);
					$block['innerHTML'] = preg_replace(
						'/(<source[^>]*\s+src=["\'])([^"\']+)(["\'])/i',
						'$1' . esc_attr($new_url) . '$3',
						$block['innerHTML']
					);
				}

				// Update innerContent array to match innerHTML
				// innerContent should mirror innerHTML for consistency
				if (isset($block['innerContent']) && is_array($block['innerContent'])) {
					foreach ($block['innerContent'] as $key => $content) {
						if (is_string($content) && !empty($old_url) && !empty($new_url)) {
							// Replace old URL with new URL in innerContent strings
							$block['innerContent'][$key] = str_replace($old_url, $new_url, $content);
						}
					}
				}

				$updated = true;

				if ($debug_mode) {
					error_log('STLS: Updated audio block - Old ID: ' . $block_id . ', New ID: ' . $new_attachment_id . ', Old URL: ' . $old_url . ', New URL: ' . $new_url);
				}
			}
		}

		// Recursively process inner blocks
		if (!empty($block['innerBlocks'])) {
			$block['innerBlocks'] = stls_update_audio_block_in_blocks($block['innerBlocks'], $staging_audio_data, $new_attachment_id, $updated);
		}
	}

	return $blocks;
}

/**
 * Sync default WordPress video blocks
 * Supports all WordPress video formats: .mp4, .webm, .ogv, .mov, etc.
 */
function stls_sync_video_blocks($post_id, $video_blocks)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	if ($debug_mode) {
		error_log('STLS: Starting video block sync for post ' . $post_id . ' with ' . count($video_blocks) . ' blocks');
	}

	if (empty($video_blocks) || !is_array($video_blocks)) {
		return;
	}

	// Get post content
	$post_content = get_post_field('post_content', $post_id);
	if (empty($post_content) || !function_exists('parse_blocks')) {
		return;
	}

	$blocks = parse_blocks($post_content);
	$updated = false;

	// Process each video block
	foreach ($video_blocks as $video_block_data) {
		if (!isset($video_block_data['video']) || !isset($video_block_data['video']['url'])) {
			continue;
		}

		$staging_video_data = $video_block_data['video'];
		$staging_url = $staging_video_data['url'];

		if ($debug_mode) {
			error_log('STLS: Processing video block - URL: ' . $staging_url . ', Attachment ID: ' . (isset($staging_video_data['attachment_id']) ? $staging_video_data['attachment_id'] : 'none'));
		}

		// Download and attach the video file
		$attachment_id = stls_convert_image_data_to_attachment_id($staging_video_data, $post_id);

		if ($attachment_id && !is_wp_error($attachment_id) && is_numeric($attachment_id)) {
			// Find and update the corresponding block in the content
			$blocks = stls_update_video_block_in_blocks($blocks, $staging_video_data, $attachment_id, $updated);

			if ($debug_mode) {
				error_log('STLS: Synced video block - URL: ' . $staging_url . ', New Attachment ID: ' . $attachment_id);
			}
		} elseif ($debug_mode) {
			if (is_wp_error($attachment_id)) {
				error_log('STLS: Failed to sync video block - URL: ' . $staging_url . ', Error: ' . $attachment_id->get_error_message());
			} else {
				error_log('STLS: Failed to sync video block - URL: ' . $staging_url . ', Attachment ID returned: ' . print_r($attachment_id, true));
			}
		}
	}

	// Update post content if blocks were modified
	// Only update default Gutenberg video blocks - preserve all other blocks as-is
	if ($updated) {
		// Don't use stls_ensure_valid_block_structure - it can corrupt blocks
		// WordPress parse_blocks() already provides valid structure
		$updated_content = serialize_blocks($blocks);

		// Validate the serialized content by parsing it back
		$validation_blocks = parse_blocks($updated_content);
		if (!empty($validation_blocks)) {
			kses_remove_filters();
			wp_update_post(array(
				'ID' => $post_id,
				'post_content' => wp_slash($updated_content),
			));
			kses_init_filters();

			if ($debug_mode) {
				error_log('STLS: Updated post content with synced default Gutenberg video blocks');
			}
		} elseif ($debug_mode) {
			error_log('STLS: Warning - Serialized content failed validation, not updating post content');
		}
	}
}

/**
 * Recursively update video blocks in block array
 */
function stls_update_video_block_in_blocks($blocks, $staging_video_data, $new_attachment_id, &$updated)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	foreach ($blocks as &$block) {
		if (empty($block['blockName'])) {
			continue;
		}

		// Check if this is a video block
		if (stls_is_default_video_block($block['blockName']) && isset($block['attrs'])) {
			$block_src = isset($block['attrs']['src']) ? $block['attrs']['src'] : '';
			$block_id = isset($block['attrs']['id']) ? intval($block['attrs']['id']) : 0;
			$staging_url = $staging_video_data['url'];
			$staging_id = isset($staging_video_data['attachment_id']) ? intval($staging_video_data['attachment_id']) : 0;

			// Also check innerHTML for video URL
			$block_html_url = '';
			if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
				if (preg_match('/<video[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
					$block_html_url = $matches[1];
				} elseif (preg_match('/<source[^>]*src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches)) {
					$block_html_url = $matches[1];
				}
			}

			// Check if this is the block we need to update (match by URL or ID)
			$is_match = false;
			if ($staging_id > 0 && $block_id === $staging_id) {
				$is_match = true;
			} elseif (!empty($block_src) && !empty($staging_url)) {
				// Compare URLs (handle different URL formats)
				$block_url_normalized = str_replace(array('http://', 'https://'), '', $block_src);
				$staging_url_normalized = str_replace(array('http://', 'https://'), '', $staging_url);
				if ($block_url_normalized === $staging_url_normalized || basename($block_src) === basename($staging_url)) {
					$is_match = true;
				}
			} elseif (!empty($block_html_url) && !empty($staging_url)) {
				// Compare URLs from innerHTML
				$block_url_normalized = str_replace(array('http://', 'https://'), '', $block_html_url);
				$staging_url_normalized = str_replace(array('http://', 'https://'), '', $staging_url);
				if ($block_url_normalized === $staging_url_normalized || basename($block_html_url) === basename($staging_url)) {
					$is_match = true;
				}
			}

			if ($is_match) {
				// Get new attachment URL
				$new_url = wp_get_attachment_url($new_attachment_id);
				$old_url = isset($block['attrs']['src']) ? $block['attrs']['src'] : '';
				if (empty($old_url) && !empty($block_html_url)) {
					$old_url = $block_html_url;
				}

				// Update block attributes
				$block['attrs']['id'] = $new_attachment_id;
				if ($new_url) {
					$block['attrs']['src'] = $new_url;
				}

				// Update innerHTML to reflect new URL
				// This is critical - WordPress validates that innerHTML matches the attributes
				if (isset($block['innerHTML']) && is_string($block['innerHTML']) && !empty($old_url) && !empty($new_url)) {
					// Replace old URL with new URL in innerHTML
					$block['innerHTML'] = str_replace($old_url, $new_url, $block['innerHTML']);

					// Also update src attribute in <video> or <source> tags
					$block['innerHTML'] = preg_replace(
						'/(<video[^>]*\s+src=["\'])([^"\']+)(["\'])/i',
						'$1' . esc_attr($new_url) . '$3',
						$block['innerHTML']
					);
					$block['innerHTML'] = preg_replace(
						'/(<source[^>]*\s+src=["\'])([^"\']+)(["\'])/i',
						'$1' . esc_attr($new_url) . '$3',
						$block['innerHTML']
					);
				}

				// Update innerContent array to match innerHTML
				// innerContent should mirror innerHTML for consistency
				if (isset($block['innerContent']) && is_array($block['innerContent'])) {
					foreach ($block['innerContent'] as $key => $content) {
						if (is_string($content) && !empty($old_url) && !empty($new_url)) {
							// Replace old URL with new URL in innerContent strings
							$block['innerContent'][$key] = str_replace($old_url, $new_url, $content);
						}
					}
				}

				$updated = true;

				if ($debug_mode) {
					error_log('STLS: Updated video block - Old ID: ' . $block_id . ', New ID: ' . $new_attachment_id . ', Old URL: ' . $old_url . ', New URL: ' . $new_url);
				}
			}
		}

		// Recursively process inner blocks
		if (!empty($block['innerBlocks'])) {
			$block['innerBlocks'] = stls_update_video_block_in_blocks($block['innerBlocks'], $staging_video_data, $new_attachment_id, $updated);
		}
	}

	return $blocks;
}

/**
 * Update ACF block attributes in post content after syncing
 * Only processes ACF blocks, preserves all other blocks as-is
 * 
 * @param array $blocks Parsed blocks from post content
 * @param array $synced_blocks Block data that was synced (ACF blocks only)
 * @param int $post_id Post ID
 * @return string Updated post content
 */
function stls_update_acf_block_attributes_in_content($blocks, $synced_blocks, $post_id)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	// Create a map of block IDs to synced data for quick lookup
	$synced_blocks_map = array();
	foreach ($synced_blocks as $synced_block) {
		$block_id = isset($synced_block['blockId']) ? $synced_block['blockId'] : '';
		if (!empty($block_id)) {
			$synced_blocks_map[$block_id] = $synced_block;
		}
	}

	// Recursively update only ACF blocks, preserve all others
	$updated_blocks = stls_update_acf_blocks_recursive($blocks, $synced_blocks_map, $post_id);

	// Serialize blocks back to content
	// Don't use stls_ensure_valid_block_structure here - it can corrupt blocks
	// WordPress parse_blocks() already provides valid structure
	return serialize_blocks($updated_blocks);
}

/**
 * Recursively update ACF block attributes only
 * Preserves all other blocks (including default Gutenberg blocks) as-is
 */
function stls_update_acf_blocks_recursive($blocks, $synced_blocks_map, $post_id)
{
	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;
	$updated_blocks = array();

	foreach ($blocks as $block) {
		// Preserve blocks without blockName (spacers, etc.)
		if (empty($block['blockName'])) {
			$updated_blocks[] = $block;
			continue;
		}

		// Check if this is an ACF block
		$is_acf_block = stls_is_acf_block($block['blockName']);

		// Only process ACF blocks - preserve all other blocks (default Gutenberg blocks, etc.) as-is
		if ($is_acf_block && isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
			// Update block attributes by checking post meta directly
			// This works even if the block doesn't have an ID attribute
			foreach ($block['attrs']['data'] as $data_key => $data_value) {
				// Skip field keys (they start with underscore)
				if (strpos($data_key, '_') === 0) {
					continue;
				}

				// Get the field key from the corresponding _field_key
				$field_key = isset($block['attrs']['data']['_' . $data_key]) ? $block['attrs']['data']['_' . $data_key] : '';

				// Check if this is a numeric attachment ID (staging ID)
				if (is_numeric($data_value) && $data_value > 0) {
					// Check if this is an image field by verifying the ACF field type
					$is_image_field = false;
					if (!empty($field_key) && function_exists('acf_get_field')) {
						$acf_field = acf_get_field($field_key);
						if ($acf_field && isset($acf_field['type']) && $acf_field['type'] === 'image') {
							$is_image_field = true;
						}
					} else {
						// If no field key, check if the attachment exists and is an image
						$attachment = get_post($data_value);
						if ($attachment && $attachment->post_type === 'attachment') {
							$mime_type = get_post_mime_type($attachment->ID);
							// Handle all file types (images, videos, PDFs, etc.)
							if (!empty($mime_type)) {
								$is_image_field = true; // Keep variable name for compatibility, but now handles all file types
							}
						}
					}

					// If this is an image field, get the updated attachment ID from post meta/ACF
					if ($is_image_field) {
						// Try to get the updated value from post meta first (most reliable)
						$new_value = get_post_meta($post_id, $data_key, true);

						// If not found, try ACF field by field key
						if (empty($new_value) && !empty($field_key) && function_exists('get_field')) {
							$new_value = get_field($field_key, $post_id);
						}

						// If still not found, try by field name
						if (empty($new_value) && function_exists('get_field')) {
							$new_value = get_field($data_key, $post_id);
						}

						if (!empty($new_value)) {
							// If it's an array with ID, extract the ID
							if (is_array($new_value) && isset($new_value['ID'])) {
								$new_value = $new_value['ID'];
							} elseif (is_array($new_value) && isset($new_value['id'])) {
								$new_value = $new_value['id'];
							}

							// Ensure it's numeric (attachment ID)
							if (is_numeric($new_value)) {
								$new_attachment_id = intval($new_value);
								$current_attachment_id = intval($data_value);

								// Only update if the value has changed (staging ID vs live ID)
								if ($current_attachment_id != $new_attachment_id) {
									$block['attrs']['data'][$data_key] = $new_attachment_id;

									if ($debug_mode) {
										error_log('STLS: Updated block attribute in content - Key: ' . $data_key . ', Old ID: ' . $current_attachment_id . ', New attachment ID: ' . $new_attachment_id);
									}
								} elseif ($debug_mode) {
									error_log('STLS: Block attribute already has correct ID - Key: ' . $data_key . ', ID: ' . $new_attachment_id);
								}
							}
						} elseif ($debug_mode) {
							error_log('STLS: Could not find updated attachment ID for block attribute - Key: ' . $data_key . ', Field key: ' . $field_key . ', Staging ID: ' . $data_value);
						}
					}
				} elseif (is_string($data_value) && !empty($data_value) && (strpos($data_value, 'http://') === 0 || strpos($data_value, 'https://') === 0)) {
					// This is a string URL attribute - replace staging URL with live URL
					// Get staging URL from settings
					$staging_url = get_option('stls_staging_url');
					if (!empty($staging_url)) {
						$staging_url_no_protocol = str_replace(array('http://', 'https://'), '', untrailingslashit($staging_url));
						$data_value_no_protocol = str_replace(array('http://', 'https://'), '', untrailingslashit($data_value));

						if (strpos($data_value_no_protocol, $staging_url_no_protocol) === 0) {
							$live_url = home_url();
							$new_url = str_replace(untrailingslashit($staging_url), untrailingslashit($live_url), $data_value);

							if ($new_url !== $data_value) {
								$block['attrs']['data'][$data_key] = $new_url;
								if ($debug_mode) {
									error_log('STLS: Replaced staging URL in block attribute - Key: ' . $data_key . ', Old: ' . $data_value . ', New: ' . $new_url);
								}
							}
						}
					}
				}
			}
		}

		// Also check if this block was in the synced blocks map (for blocks with IDs)
		$block_id = isset($block['attrs']['id']) ? $block['attrs']['id'] : '';
		if (!empty($block_id) && isset($synced_blocks_map[$block_id])) {
			$synced_block = $synced_blocks_map[$block_id];

			// Update block attributes with synced data (for additional fields that might not be in post meta)
			if (isset($block['attrs']['data']) && is_array($block['attrs']['data']) && isset($synced_block['attrs']['data'])) {
				// This section handles any additional updates from synced block data
				// The main image field updates are handled above
			}
		}

		// Recursively process inner blocks
		if (!empty($block['innerBlocks'])) {
			$block['innerBlocks'] = stls_update_acf_blocks_recursive($block['innerBlocks'], $synced_blocks_map, $post_id);
		}

		// Preserve the original block structure as much as possible
		// WordPress parse_blocks() already provides a valid structure
		$updated_blocks[] = $block;
	}

	return $updated_blocks;
}

/**
 * Ensure block structure is valid for WordPress block validation
 * This prevents "Block contains unexpected or invalid content" errors
 * 
 * @param array $block Block array
 * @return array Validated block array
 */
function stls_ensure_valid_block_structure($block)
{
	// Only ensure basic properties exist - don't reconstruct innerContent/innerHTML
	// WordPress parse_blocks() already provides the correct structure, so we should preserve it

	// Ensure required properties exist
	if (!isset($block['blockName'])) {
		$block['blockName'] = '';
	}

	// Ensure attrs is an array
	if (!isset($block['attrs']) || !is_array($block['attrs'])) {
		$block['attrs'] = array();
	}

	// Only set innerContent if it's completely missing AND we have innerHTML
	// Don't reconstruct it if it already exists - preserve the original structure
	if (!isset($block['innerContent']) && isset($block['innerHTML']) && is_string($block['innerHTML'])) {
		// Only set it if innerHTML exists and innerContent is completely missing
		$block['innerContent'] = array($block['innerHTML']);
	} elseif (!isset($block['innerContent'])) {
		// If innerContent doesn't exist and innerHTML also doesn't exist, set empty array
		// But don't try to reconstruct from innerBlocks - that's too risky
		$block['innerContent'] = array();
	}

	// Only set innerHTML if it's completely missing
	// Don't reconstruct it - preserve what WordPress parsed
	if (!isset($block['innerHTML'])) {
		$block['innerHTML'] = '';
	}

	// Ensure innerBlocks is an array if it exists
	if (isset($block['innerBlocks']) && !is_array($block['innerBlocks'])) {
		$block['innerBlocks'] = array();
	}

	// Don't try to sync innerBlocks and innerContent - WordPress handles this
	// We should preserve the structure as parsed by parse_blocks()

	return $block;
}

/**
 * Recursively replace staging URLs with live URLs in block attributes
 * 
 * @param array $blocks Parsed blocks
 * @param string $staging_url Staging site URL
 * @param string $live_url Live site URL
 * @return array Updated blocks
 */
function stls_replace_staging_urls_in_blocks($blocks, $staging_url, $live_url)
{
	$updated_blocks = array();

	foreach ($blocks as $block) {
		if (!empty($block['attrs']) && is_array($block['attrs'])) {
			// Replace URLs in block attributes
			$block['attrs'] = stls_replace_urls_in_array($block['attrs'], $staging_url, $live_url);
		}

		// Also replace URLs in innerHTML if it exists
		if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
			$block['innerHTML'] = str_replace($staging_url, $live_url, $block['innerHTML']);
		}

		// Replace URLs in innerContent array
		if (isset($block['innerContent']) && is_array($block['innerContent'])) {
			foreach ($block['innerContent'] as $key => $content) {
				if (is_string($content)) {
					$block['innerContent'][$key] = str_replace($staging_url, $live_url, $content);
				}
			}
		}

		// Recursively process inner blocks
		if (!empty($block['innerBlocks'])) {
			$block['innerBlocks'] = stls_replace_staging_urls_in_blocks($block['innerBlocks'], $staging_url, $live_url);
		}

		// Don't modify block structure - preserve as-is
		// This function is no longer used for URL replacement (we use string replacement instead)
		// But keeping it for backwards compatibility
		$updated_blocks[] = $block;
	}

	return $updated_blocks;
}

/**
 * Recursively replace staging URLs with live URLs in an array
 * 
 * @param mixed $data Data to process (array, string, etc.)
 * @param string $staging_url Staging site URL
 * @param string $live_url Live site URL
 * @return mixed Updated data
 */
function stls_replace_urls_in_array($data, $staging_url, $live_url)
{
	if (is_string($data)) {
		// Replace staging URL with live URL
		return str_replace($staging_url, $live_url, $data);
	} elseif (is_array($data)) {
		foreach ($data as $key => $value) {
			$data[$key] = stls_replace_urls_in_array($value, $staging_url, $live_url);
		}
	}

	return $data;
}

/**
 * Sync Yoast SEO data to live site
 * 
 * @param int $post_id Post ID on live site
 * @param array $yoast_data Yoast SEO data from staging site
 */
function stls_sync_yoast_seo_data($post_id, $yoast_data)
{
	if (empty($yoast_data) || !is_array($yoast_data)) {
		return;
	}

	$debug_mode = defined('WP_DEBUG') && WP_DEBUG;

	if ($debug_mode) {
		error_log('STLS: Syncing Yoast SEO data for post ' . $post_id);
	}

	foreach ($yoast_data as $meta_key => $meta_value) {
		// Handle image fields - convert image data back to attachment IDs
		if (in_array($meta_key, array('_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_twitter-image-id'))) {
			// These should be attachment IDs
			if (is_array($meta_value) && isset($meta_value['attachment_id'])) {
				// Convert image data to attachment ID
				$attachment_id = stls_convert_image_data_to_attachment_id($meta_value, $post_id, $meta_key);
				if ($attachment_id && !is_wp_error($attachment_id)) {
					update_post_meta($post_id, $meta_key, $attachment_id);

					// Also update the corresponding image URL field
					if ($meta_key === '_yoast_wpseo_opengraph-image-id') {
						$image_url = wp_get_attachment_url($attachment_id);
						if ($image_url) {
							update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $image_url);
						}
					} elseif ($meta_key === '_yoast_wpseo_twitter-image-id') {
						$image_url = wp_get_attachment_url($attachment_id);
						if ($image_url) {
							update_post_meta($post_id, '_yoast_wpseo_twitter-image', $image_url);
						}
					}

					if ($debug_mode) {
						error_log('STLS: Updated Yoast SEO image field ' . $meta_key . ' with attachment ID: ' . $attachment_id);
					}
				}
			} elseif (is_numeric($meta_value)) {
				// Already an ID, just update it
				update_post_meta($post_id, $meta_key, $meta_value);
			}
		} elseif (in_array($meta_key, array('_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image'))) {
			// These might be URLs or image data arrays
			$image_url = null;
			$attachment_id = null;

			if (is_array($meta_value) && isset($meta_value['url'])) {
				// Convert image data to attachment ID or URL
				$converted_value = stls_convert_image_data_to_attachment_id($meta_value, $post_id, $meta_key);

				if (is_numeric($converted_value)) {
					// Got an attachment ID
					$attachment_id = $converted_value;
					$image_url = wp_get_attachment_url($attachment_id);
				} elseif (is_string($converted_value)) {
					// Got a URL (was originally a URL)
					$image_url = $converted_value;
					// Try to get attachment ID from URL
					$attachment_id = attachment_url_to_postid($image_url);
				}
			} elseif (is_string($meta_value) && !empty($meta_value)) {
				// It's a URL string
				if (stls_is_image_url($meta_value)) {
					// Try to find existing attachment or download
					$attachment_id = attachment_url_to_postid($meta_value);
					if (!$attachment_id) {
						$attachment_id = stls_download_and_attach_image($meta_value, $post_id);
					}

					if ($attachment_id && !is_wp_error($attachment_id)) {
						$image_url = wp_get_attachment_url($attachment_id);
					} else {
						// Keep original URL if download failed
						$image_url = $meta_value;
						$attachment_id = null;
					}
				} else {
					// Not an image URL, just use as-is
					$image_url = $meta_value;
				}
			}

			// Update the URL field
			if ($image_url) {
				update_post_meta($post_id, $meta_key, $image_url);
			}

			// Update the corresponding ID field if we have an attachment ID
			if ($attachment_id) {
				if ($meta_key === '_yoast_wpseo_opengraph-image') {
					update_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id);
				} elseif ($meta_key === '_yoast_wpseo_twitter-image') {
					update_post_meta($post_id, '_yoast_wpseo_twitter-image-id', $attachment_id);
				}
			}
		} else {
			// Regular meta field, just update it
			update_post_meta($post_id, $meta_key, $meta_value);
		}
	}

	if ($debug_mode) {
		error_log('STLS: Successfully synced ' . count($yoast_data) . ' Yoast SEO fields for post ' . $post_id);
	}
}

/**
 * Check if a URL is an image URL
 * 
 * @param string $url URL to check
 * @return bool True if URL appears to be an image
 */
function stls_is_image_url($url)
{
	if (empty($url) || !is_string($url)) {
		return false;
	}

	// Check if it's a file URL (supports all file types: images, videos, PDFs, etc.)
	$path = parse_url($url, PHP_URL_PATH);

	if ($path) {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		// Return true if it has any extension (it's a file)
		return !empty($extension);
	}

	return false;
}

/**
 * Convert file data (URL) back to attachment ID on live site
 * This function handles ACF field types and checks if files already exist
 * Supports all file types: images, videos, PDFs, etc.
 */
function stls_convert_image_data_to_attachment_id($value, $post_id, $field_key = null)
{
	if (empty($value)) {
		return $value;
	}

	// If value is an array with file data
	if (is_array($value) && isset($value['url']) && isset($value['attachment_id'])) {
		// This is file data from staging site
		$image_url = $value['url'];
		$staging_attachment_id = isset($value['attachment_id']) ? intval($value['attachment_id']) : 0;
		$was_url = isset($value['was_url']) && $value['was_url'];

		// Get ACF field object to determine return format
		$return_format = 'id'; // Default to ID
		if ($field_key && function_exists('get_field_object')) {
			$field_object = get_field_object($field_key, $post_id);
			if ($field_object && isset($field_object['return_format'])) {
				$return_format = $field_object['return_format'];
			}
		}

		// Step 1: Check if image already exists by staging attachment ID
		// (Sometimes IDs match between staging and live)
		$existing_attachment_id = null;
		if ($staging_attachment_id > 0) {
			$check_attachment = get_post($staging_attachment_id);
			if ($check_attachment && $check_attachment->post_type === 'attachment') {
				$mime_type = get_post_mime_type($staging_attachment_id);
				// Handle all file types (images, videos, PDFs, etc.)
				if (!empty($mime_type)) {
					$existing_attachment_id = $staging_attachment_id;
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('STLS: Found existing file by staging ID: ' . $staging_attachment_id . ', MIME: ' . $mime_type);
					}
				}
			}
		}

		// Step 2: Check by filename if not found by ID
		if (!$existing_attachment_id) {
			$filename = basename(parse_url($image_url, PHP_URL_PATH));
			$existing_attachment_id = stls_get_attachment_by_filename($filename);
			if ($existing_attachment_id) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS: Found existing file by filename: ' . $filename . ' (ID: ' . $existing_attachment_id . ')');
				}
			}
		}

		// Step 3: Download if not found
		if (!$existing_attachment_id) {
			$existing_attachment_id = stls_download_and_attach_image($image_url, $post_id);
			if (is_wp_error($existing_attachment_id)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS Download Error: ' . $existing_attachment_id->get_error_message());
				}
				// If download failed, try using staging ID (might work if IDs match)
				$existing_attachment_id = $staging_attachment_id > 0 ? $staging_attachment_id : null;
			}
		}

		if ($existing_attachment_id && !is_wp_error($existing_attachment_id)) {
			// Update alt text if provided
			// If alt text is empty or not provided, delete the meta to avoid WordPress warnings
			if (isset($value['alt'])) {
				if (!empty($value['alt'])) {
					update_post_meta($existing_attachment_id, '_wp_attachment_image_alt', sanitize_text_field($value['alt']));
				} else {
					// Delete alt text meta if it's empty to avoid WordPress accessibility warnings
					delete_post_meta($existing_attachment_id, '_wp_attachment_image_alt');
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('STLS: Removed empty alt text from attachment ID: ' . $existing_attachment_id);
					}
				}
			}

			// Update title if provided
			if (!empty($value['title'])) {
				wp_update_post(array(
					'ID' => $existing_attachment_id,
					'post_title' => sanitize_text_field($value['title']),
					'post_excerpt' => isset($value['caption']) ? sanitize_textarea_field($value['caption']) : '',
					'post_content' => isset($value['description']) ? wp_slash(wp_kses_post($value['description'])) : '',
				));
			}

			// Return based on field return format or original format
			if ($was_url || $return_format === 'url') {
				$new_url = wp_get_attachment_url($existing_attachment_id);
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS: Returning URL format - Old: ' . $image_url . ' -> New: ' . $new_url);
				}
				return $new_url;
			} elseif ($return_format === 'array') {
				// Return ACF array format
				return array(
					'ID' => $existing_attachment_id,
					'id' => $existing_attachment_id,
					'url' => wp_get_attachment_url($existing_attachment_id),
					'alt' => get_post_meta($existing_attachment_id, '_wp_attachment_image_alt', true),
					'title' => get_the_title($existing_attachment_id),
					'caption' => wp_get_attachment_caption($existing_attachment_id),
					'description' => get_post_field('post_content', $existing_attachment_id),
					'mime_type' => get_post_mime_type($existing_attachment_id),
					'width' => 0,
					'height' => 0,
					'sizes' => array(),
				);
			} else {
				// Return attachment ID (default)
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS: Returning ID format - Attachment ID: ' . $existing_attachment_id);
				}
				return $existing_attachment_id;
			}
		}

		// If everything failed, return original value
		return $value;
	}

	// If value is an array, recursively process
	if (is_array($value)) {
		foreach ($value as $key => $item) {
			$value[$key] = stls_convert_image_data_to_attachment_id($item, $post_id, $field_key);
		}
		return $value;
	}

	// Return original value if not image data
	return $value;
}


