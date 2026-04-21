<?php
/**
 * Elementor Page Builder Sync Compatibility
 * 
 * This file handles Elementor-specific sync functionality.
 * It only runs when "Elementor" is selected in the plugin settings.
 * 
 * @package Staging_To_Live_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Log Elementor sync activity
 * 
 * @param string $message Log message
 * @param string $level Log level (info, warning, error, success)
 * @param array $data Additional data to log
 */
function stls_elementor_log($message, $level = 'info', $data = array())
{
	// Always log - don't require WP_DEBUG for Elementor sync logs
	$timestamp = current_time('mysql');
	$log_entry = sprintf(
		'[%s] [%s] %s',
		$timestamp,
		strtoupper($level),
		$message
	);

	if (!empty($data)) {
		$log_entry .= ' | Data: ' . print_r($data, true);
	}

	// Always write to error log for Elementor sync debugging
	// This ensures we can see what's happening even if WP_DEBUG is off
	error_log('STLS Elementor: ' . $log_entry);

	// Store in transient for easy retrieval (last 500 entries for better debugging)
	$log_key = 'stls_elementor_logs';
	$logs = get_transient($log_key);
	if (!is_array($logs)) {
		$logs = array();
	}

	$logs[] = array(
		'timestamp' => $timestamp,
		'level' => $level,
		'message' => $message,
		'data' => $data,
	);

	// Keep only last 500 entries (increased from 100 for better debugging)
	if (count($logs) > 500) {
		$logs = array_slice($logs, -500);
	}

	set_transient($log_key, $logs, 7 * DAY_IN_SECONDS); // Keep for 7 days
}

// Only proceed if Elementor is active (safety check)
if (!class_exists('\Elementor\Plugin') && !defined('ELEMENTOR_VERSION')) {
	// If Elementor is not installed/active, we skip this entirely
	return;
}

// Include helper functions
require_once plugin_dir_path(__FILE__) . 'elementor-sync-helpers.php';

/**
 * Add Elementor data to post data during sync (staging side)
 * 
 * @param array $post_data The post data array being prepared for sync
 * @param int   $post_id   The post ID being synced
 * @return array Modified post data with Elementor data
 */
function stls_elementor_add_to_sync_data($post_data, $post_id)
{
	// Double-check that Elementor is selected (safety check)
	$selected_pagebuilder = get_option('stls_pagebuilder', 'acf_gutenberg');
	if ($selected_pagebuilder !== 'elementor') {
		return $post_data;
	}

	// Add staging URL to payload for dynamic replacement on live site
	$post_data['elementor_staging_url'] = get_home_url();

	// Check if Elementor is active (optional check - we'll proceed even if not active)
	// Elementor might not be loaded yet, but the meta data exists

	// Get Elementor meta keys
	$elementor_meta_keys = array(
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_css',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_edit_mode',
		'_elementor_pro_version',
		'_elementor_page_assets',
		'_elementor_assets_data',
		'_elementor_conditions',
		'_elementor_controls_usage',
	);

	// Initialize Elementor data array
	$elementor_data_found = false;
	$post_data['elementor_data'] = array();

	foreach ($elementor_meta_keys as $meta_key) {
		$meta_value = get_post_meta($post_id, $meta_key, true);
		if ($meta_value !== '' && $meta_value !== false && $meta_value !== null) {
			$post_data['elementor_data'][$meta_key] = $meta_value;
			$elementor_data_found = true;

			// Log _elementor_data structure to verify all sections are collected
			if ($meta_key === '_elementor_data' && is_string($meta_value)) {
				$decoded = json_decode($meta_value, true);
				if (is_array($decoded)) {
					$section_count = 0;
					$container_count = 0;
					$section_ids = array();
					$container_ids = array();
					foreach ($decoded as $item) {
						if (isset($item['elType'])) {
							if ($item['elType'] === 'section') {
								$section_count++;
								if (isset($item['id'])) {
									$section_ids[] = $item['id'];
								}
							} elseif ($item['elType'] === 'container') {
								$container_count++;
								if (isset($item['id'])) {
									$container_ids[] = $item['id'];
								}
							}
						}
					}

					// Always log (not just in WP_DEBUG) for Elementor sync debugging
					stls_elementor_log('Collected _elementor_data from staging', 'info', array(
						'post_id' => $post_id,
						'sections_count' => $section_count,
						'containers_count' => $container_count,
						'section_ids' => $section_ids,
						'container_ids' => $container_ids,
						'total_elements' => count($decoded),
						'data_length' => strlen($meta_value),
						'json_valid' => json_last_error() === JSON_ERROR_NONE,
						'note' => 'This is the data collected from staging - all sections/containers should be here'
					));

					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('STLS Elementor: Collected _elementor_data from staging - Sections: ' . $section_count . ', Total elements: ' . count($decoded));
					}
				} else {
					stls_elementor_log('WARNING: _elementor_data from staging is not a valid array!', 'error', array(
						'post_id' => $post_id,
						'json_error' => json_last_error_msg(),
						'data_length' => strlen($meta_value),
						'data_sample' => substr($meta_value, 0, 500)
					));
				}
			}
		}
	}

	// Only process if we found Elementor data
	if (!$elementor_data_found) {
		return $post_data;
	}

	// Process _elementor_data to extract and convert image URLs
	if (!empty($post_data['elementor_data']['_elementor_data'])) {
		$elementor_data = $post_data['elementor_data']['_elementor_data'];

		// If it's a JSON string, decode it
		if (is_string($elementor_data)) {
			$decoded = json_decode($elementor_data, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				$elementor_data = $decoded;
			}
		}

		// Extract image URLs from Elementor data and store them for conversion
		// NOTE: We check validity check above.
		// CRITICAL: Base64 encode the string to prevent ANY slash/quote corruption during transport
		if (is_array($elementor_data)) {
			// It's still an array here because we didn't overwrite it in the loop above?
			// Wait, in previous step we commented out the overwrite.
			// So $post_data[...] is holding the original string from get_post_meta (line 110)
		}

		// Ensure we are working with the string version in post_data
		if (isset($post_data['elementor_data']['_elementor_data']) && is_string($post_data['elementor_data']['_elementor_data'])) {
			$raw_data = $post_data['elementor_data']['_elementor_data'];
			// Base64 encode to survive HTTP transport and WP unslashing unscathed
			$post_data['elementor_data']['_elementor_data_base64'] = base64_encode($raw_data);
			unset($post_data['elementor_data']['_elementor_data']); // Remove raw to save bandwidth and avoid confusion

			stls_elementor_log('Base64 encoded _elementor_data for transport', 'info', array(
				'post_id' => $post_id,
				'original_length' => strlen($raw_data),
				'encoded_length' => strlen($post_data['elementor_data']['_elementor_data_base64'])
			));
		}
	}

	// Process _elementor_page_settings similarly
	if (!empty($post_data['elementor_data']['_elementor_page_settings'])) {
		$page_settings = $post_data['elementor_data']['_elementor_page_settings'];

		// If it's a JSON string, decode it
		if (is_string($page_settings)) {
			$decoded = json_decode($page_settings, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				// Base64 encode page settings too
				$post_data['elementor_data']['_elementor_page_settings_base64'] = base64_encode($page_settings);
				unset($post_data['elementor_data']['_elementor_page_settings']);
			}
		}
	}

	if (defined('WP_DEBUG') && WP_DEBUG) {
		error_log('STLS Elementor: Added Elementor data to sync for post ' . $post_id);
	}

	return $post_data;
}


/**
 * Save Elementor data on live site after sync
 * 
 * @param int   $post_id   The post ID on the live site
 * @param array $post_data The post data received from staging
 * @return void
 */
function stls_elementor_save_on_live($post_id, $post_data)
{
	// 1. CRITICAL: Reset state and clear caches
	if (isset($GLOBALS['stls_html_processed_value']))
		unset($GLOBALS['stls_html_processed_value']);
	clean_post_cache($post_id);
	wp_cache_delete($post_id, 'post_meta');

	// Also clear Elementor-specific caches at the start
	if (class_exists('\Elementor\Plugin')) {
		$elementor = \Elementor\Plugin::$instance;
		if (isset($elementor->files_manager)) {
			$elementor->files_manager->clear_cache();
		}
		if (isset($elementor->posts_css_manager)) {
			$elementor->posts_css_manager->clear_cache();
		}
	}

	// 2. Validate environment and data
	// If we have elementor_data in the payload, we should process it if Elementor is active,
	// regardless of the stls_pagebuilder setting (which might not be set on a fresh install).
	if (!isset($post_data['elementor_data']) || !is_array($post_data['elementor_data'])) {
		return;
	}

	if (!class_exists('\Elementor\Plugin')) {
		stls_elementor_log('Elementor data received but Elementor is not active locally', 'warning', array('post_id' => $post_id));
		return;
	}

	$elementor_data = $post_data['elementor_data'];

	// 3. Determine URLs for replacement
	// Use staging_url from payload if available, fallback to settings
	$staging_url = isset($post_data['elementor_staging_url']) ? $post_data['elementor_staging_url'] : get_option('stls_staging_url', '');
	$live_url = home_url();

	if (empty($staging_url)) {
		stls_elementor_log('WARNING: No staging URL found for replacement. Syncing without URL updates.', 'warning', array('post_id' => $post_id));
	}

	// CRITICAL: Handle Base64 encoded data from staging
	// This ensures we get the EXACT string that was on staging, without slash corruption
	if (isset($elementor_data['_elementor_data_base64'])) {
		$decoded_data = base64_decode($elementor_data['_elementor_data_base64']);
		if ($decoded_data !== false) {
			$elementor_data['_elementor_data'] = $decoded_data;
			unset($elementor_data['_elementor_data_base64']);
			stls_elementor_log('Successfully decoded Base64 _elementor_data', 'info', array('post_id' => $post_id, 'length' => strlen($decoded_data)));
		}
	}
	if (isset($elementor_data['_elementor_page_settings_base64'])) {
		$decoded_settings = base64_decode($elementor_data['_elementor_page_settings_base64']);
		if ($decoded_settings !== false) {
			$elementor_data['_elementor_page_settings'] = $decoded_settings;
			unset($elementor_data['_elementor_page_settings_base64']);
		}
	}

	stls_elementor_log('Starting clean Elementor sync on live', 'info', array(
		'post_id' => $post_id,
		'meta_keys' => array_keys($elementor_data),
	));

	// 4. Process each meta key
	foreach ($elementor_data as $meta_key => $meta_value) {
		$value_to_save = $meta_value;

		if ($meta_key === '_elementor_data' || $meta_key === '_elementor_page_settings') {
			// CRITICAL: Process primary Elementor content
			$is_string = is_string($value_to_save);
			$decoded = $is_string ? json_decode($value_to_save, true) : $value_to_save;

			if (is_array($decoded)) {
				// STEP 1: Download images from staging to live
				$downloaded_images = array(); // Map of staging_url => live_url
				$id_map = array(); // Map of staging_id => live_id
				stls_elementor_download_images_recursive($decoded, $post_id, $staging_url, $downloaded_images, $id_map);

				// STEP 2: Replace staging image URLs with live URLs in the array
				if (!empty($downloaded_images)) {
					stls_elementor_replace_image_urls_recursive($decoded, $downloaded_images);
					stls_elementor_log('Downloaded and replaced Elementor images', 'info', array(
						'post_id' => $post_id,
						'images_downloaded' => count($downloaded_images),
						'image_urls' => array_keys($downloaded_images),
						'ids_mapped' => count($id_map)
					));
				}

				// STEP 3: Replace staging URLs with live URLs using more thorough replacement
				$processed_array = stls_elementor_replace_staging_urls_in_array($decoded, $staging_url, $live_url);

				// Handle HTML content if detected
				if (stls_elementor_contains_html_in_structure($processed_array) && is_string($meta_value)) {
					// CRITICAL: For HTML content, use thorough string replacement
					// It handles variations of trailing slashes and escaped slashes
					$value_to_save = stls_elementor_replace_urls_thoroughly($meta_value, $staging_url, $live_url);

					// Also replace downloaded image URLs and IDs in the string
					if (!empty($downloaded_images) || !empty($id_map)) {
						// 1. Replace URLs
						foreach ($downloaded_images as $staging_img_url => $live_img_url) {
							// For downloaded images, we need to handle both normal and escaped slashes in the JSON string
							$staging_img_url_esc = str_replace('/', '\/', $staging_img_url);
							$live_img_url_esc = str_replace('/', '\/', $live_img_url);

							$value_to_save = str_replace(
								array($staging_img_url, $staging_img_url_esc),
								array($live_img_url, $live_img_url_esc),
								$value_to_save
							);
						}

						// 2. Replace IDs ("id":123 -> "id":456) and classes (wp-image-123 -> wp-image-456)
						foreach ($id_map as $staging_id => $live_id) {
							$value_to_save = str_replace(
								array('"id":' . $staging_id, '"id":' . '"' . $staging_id . '"', 'wp-image-' . $staging_id),
								array('"id":' . $live_id, '"id":' . '"' . $live_id . '"', 'wp-image-' . $live_id),
								$value_to_save
							);
						}
					}

					stls_elementor_log('Using thorough string replacement for HTML content', 'info', array(
						'post_id' => $post_id,
						'length' => strlen($value_to_save)
					));
				} else {
					// Safe to save
					if ($meta_key === '_elementor_data') {
						// _elementor_data MUST be a JSON string
						$value_to_save = wp_json_encode($processed_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
					} else {
						// For _elementor_page_settings and others, we MUST keep it as an array
						// It will be serialized correctly by update_post_meta or stls_elementor_save_meta_direct
						$value_to_save = $processed_array;
					}
				}
			}
		}

		// 4. Save metadata using direct SQL for large values or _elementor_data
		if ($meta_key === '_elementor_data' || (is_string($value_to_save) && strlen($value_to_save) > 5000)) {
			$save_result = stls_elementor_save_meta_direct($post_id, $meta_key, $value_to_save);
			stls_elementor_log('Saved large/critical meta key via direct SQL', $save_result ? 'success' : 'error', array(
				'post_id' => $post_id,
				'meta_key' => $meta_key,
				'length' => strlen($value_to_save)
			));
		} else {
			update_post_meta($post_id, $meta_key, $value_to_save);
		}
	}

	// 5. Final Recognition and Cache Clearing

	// Verify _elementor_data is present and not empty
	global $wpdb;
	$final_data_check = $wpdb->get_var($wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
		$post_id
	));

	if (empty($final_data_check)) {
		stls_elementor_log('CRITICAL WARNING: _elementor_data is missing or empty after sync!', 'error', array('post_id' => $post_id));
		// Try to save it again from memory if possible? 
		// For now just log it.
	} else {
		// Ensure _elementor_edit_mode is 'builder' using direct SQL to bypass any filters
		stls_elementor_save_meta_direct($post_id, '_elementor_edit_mode', 'builder');
	}

	// Force _elementor_template_type if missing
	if (empty(get_post_meta($post_id, '_elementor_template_type', true))) {
		$post_type = get_post_type($post_id);
		// Default for pages/posts is usually 'wp-page' or 'default' or just valid type
		// Elementor uses 'wp-page', 'wp-post' or 'kit' etc.
		// Safe default: 'wp-page'
		update_post_meta($post_id, '_elementor_template_type', 'wp-page');
	}

	// Ensure post type support for Elementor so the Edit button appears
	$post_type = get_post_type($post_id);
	$cpt_support = get_option('elementor_cpt_support', array('post', 'page'));
	if (!is_array($cpt_support)) {
		$cpt_support = array('post', 'page');
	}
	if (!in_array($post_type, $cpt_support)) {
		$cpt_support[] = $post_type;
		update_option('elementor_cpt_support', $cpt_support);
		stls_elementor_log('Enabled Elementor support for post type: ' . $post_type, 'success');
	}

	// Ensure version is set (Force update)
	$version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.25.0'; // Modern default
	update_post_meta($post_id, '_elementor_version', $version);

	// Force Elementor to re-recognize the post and regenerate CSS
	if (class_exists('\Elementor\Plugin')) {
		$elementor = \Elementor\Plugin::$instance;
		$elementor->files_manager->clear_cache();

		// Trigger regenerate if method exists
		if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'regenerate_css_files')) {
			$elementor->files_manager->regenerate_css_files();
		}

		if (isset($elementor->posts_css_manager)) {
			$elementor->posts_css_manager->clear_cache();
		}

		// MANDATORY: Force regenerate the specific post CSS to ensure background images work
		// This uses Elementor's internal CSS manager to update the file immediately
		try {
			if (class_exists('\Elementor\Core\Files\CSS\Post')) {
				$post_css = new \Elementor\Core\Files\CSS\Post($post_id);
				$post_css->update();
				stls_elementor_log('Forced regeneration of specific post CSS', 'success', array('post_id' => $post_id));
			}
		} catch (\Exception $e) {
			stls_elementor_log('Failed to force regenerate post CSS', 'warning', array('error' => $e->getMessage()));
		}

		// Trigger Elementor hooks
		do_action('elementor/editor/after_save', $post_id, array());
	}

	clean_post_cache($post_id);

	stls_elementor_log('Elementor sync completed successfully', 'success', array(
		'post_id' => $post_id,
		'note' => 'Robust SQL helper used, caches cleared, Elementor recognition ensured.'
	));
}
function stls_elementor_ensure_recognition_in_admin()
{
	// Only run on edit pages (edit.php, post.php)
	global $pagenow;
	if (!in_array($pagenow, array('edit.php', 'post.php', 'post-new.php'))) {
		return;
	}

	// If we have _elementor_data but Elementor doesn't recognize it, fix it
	// No longer checking stls_pagebuilder here to ensure compatibility on any site with Elementor active

	// Only run if Elementor is active
	if (!class_exists('\Elementor\Plugin')) {
		return;
	}

	// Get current post ID if available
	$post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
	if (!$post_id && isset($_GET['post_id'])) {
		$post_id = intval($_GET['post_id']);
	}

	// If we have a specific post, check and fix it
	if ($post_id > 0) {
		$elementor_data = get_post_meta($post_id, '_elementor_data', true);
		$edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);

		// If _elementor_data exists but _elementor_edit_mode is not 'builder', fix it
		if (!empty($elementor_data) && $edit_mode !== 'builder') {
			update_post_meta($post_id, '_elementor_edit_mode', 'builder');

			// Ensure _elementor_version is set
			$version = get_post_meta($post_id, '_elementor_version', true);
			if (empty($version)) {
				if (defined('ELEMENTOR_VERSION')) {
					update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
				}
			}

			// Ensure _elementor_template_type is set
			$template_type = get_post_meta($post_id, '_elementor_template_type', true);
			if (empty($template_type)) {
				$post_type = get_post_type($post_id);
				update_post_meta($post_id, '_elementor_template_type', $post_type);
			}

			// Clear caches
			clean_post_cache($post_id);

			// Clear Elementor cache
			if (class_exists('\Elementor\Plugin')) {
				$elementor = \Elementor\Plugin::$instance;
				if (method_exists($elementor, 'files_manager') && method_exists($elementor->files_manager, 'clear_cache')) {
					$elementor->files_manager->clear_cache();
				}
			}
		}
	}
}

/**
 * Recursively convert image URLs to attachment IDs in Elementor data
 * 
 * @param array  $data              The Elementor data array
 * @param int    $post_id           The post ID
 * @param string $staging_url       The staging URL for replacement
 * @param array  $elementor_image_map Optional image ID to URL mapping for fallback
 * @param string $elementor_json_string Optional original JSON string for deep URL search
 * @return array Modified data with attachment IDs or live URLs
 */
function stls_elementor_convert_urls_to_attachment_ids($data, $post_id, $staging_url = '', $elementor_image_map = array(), $elementor_json_string = '')
{
	// Log function entry
	stls_elementor_log('convert_urls_to_attachment_ids called', 'info', array(
		'is_array' => is_array($data),
		'post_id' => $post_id,
		'staging_url' => $staging_url,
		'has_image_map' => !empty($elementor_image_map),
		'image_map_size' => count($elementor_image_map),
		'data_type' => gettype($data),
		'data_size' => is_array($data) ? count($data) : 0
	));

	if (!is_array($data)) {
		stls_elementor_log('convert_urls_to_attachment_ids: data is not array, returning', 'warning', array(
			'data_type' => gettype($data),
			'post_id' => $post_id
		));
		return $data;
	}

	$live_url = home_url();
	$processed_count = 0;
	$image_url_count = 0;

	foreach ($data as $key => $value) {
		if (is_array($value)) {
			// Check if this is an Elementor image object with 'id' and/or 'url' keys
			if (isset($value['id']) || isset($value['url'])) {
				$image_id = isset($value['id']) ? intval($value['id']) : 0;
				$image_url = isset($value['url']) ? $value['url'] : '';

				// If we have an ID, try to convert it
				if ($image_id > 0) {
					$attachment = get_post($image_id);
					if (!$attachment || $attachment->post_type !== 'attachment') {
						// Attachment doesn't exist on live, download from staging
						$download_url = $image_url;

						// If URL is empty or is a staging URL, try to get it from REST API
						if (empty($download_url) || (!empty($staging_url) && strpos($download_url, $staging_url) !== false)) {
							if (!empty($staging_url)) {
								$staging_media_url = trailingslashit($staging_url) . 'wp-json/wp/v2/media/' . $image_id;

								// Prepare request with API key if available
								$request_args = array(
									'timeout' => 30,
								);
								if (!empty($staging_api_key)) {
									$request_args['headers'] = array(
										'X-STLS-API-Key' => $staging_api_key,
									);
								}

								$response = wp_remote_get($staging_media_url, $request_args);

								if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
									$media_data = json_decode(wp_remote_retrieve_body($response), true);
									if (isset($media_data['source_url'])) {
										$download_url = $media_data['source_url'];

										if (defined('WP_DEBUG') && WP_DEBUG) {
											error_log('STLS Elementor: Retrieved image URL from staging REST API - ID: ' . $image_id . ', URL: ' . $download_url);
										}
									}
								} elseif (defined('WP_DEBUG') && WP_DEBUG) {
									$error_msg = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
									error_log('STLS Elementor: Failed to fetch media from staging REST API - ID: ' . $image_id . ', Error: ' . $error_msg);
								}
							}
						}

						// Download the image if we have a URL
						if (!empty($download_url)) {
							$attachment_id = stls_download_and_attach_image($download_url, $post_id, true);

							if ($attachment_id && !is_wp_error($attachment_id)) {
								$new_url = wp_get_attachment_url($attachment_id);
								$data[$key] = array(
									'id' => intval($attachment_id),
									'url' => $new_url ? $new_url : '',
								);

								if (defined('WP_DEBUG') && WP_DEBUG) {
									error_log('STLS Elementor: Successfully converted image object - Staging ID: ' . $image_id . ', Live ID: ' . $attachment_id . ', URL: ' . $new_url);
								}
							} else {
								// Download failed - log error and try to keep URL updated
								if (defined('WP_DEBUG') && WP_DEBUG) {
									$error_msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Unknown error';
									error_log('STLS Elementor: Failed to download image - URL: ' . $download_url . ', Error: ' . $error_msg);
								}

								// Keep original structure but update URL if we have one
								if (!empty($download_url) && !empty($staging_url)) {
									$live_url = home_url();
									$new_url = str_replace($staging_url, $live_url, $download_url);
									if (isset($data[$key]) && is_array($data[$key])) {
										$data[$key]['url'] = $new_url;
									} else {
										$data[$key] = array(
											'id' => 0,
											'url' => $new_url,
										);
									}
								}
							}
						} elseif (defined('WP_DEBUG') && WP_DEBUG) {
							error_log('STLS Elementor: No download URL available for image ID: ' . $image_id);
						}
					} else {
						// Attachment exists, update URL if needed
						$new_url = wp_get_attachment_url($image_id);
						if ($new_url) {
							$data[$key]['url'] = $new_url;
						}
					}
				} elseif (!empty($image_url)) {
					// Only URL provided, process the URL
					$processed = stls_elementor_convert_urls_to_attachment_ids(array('url' => $image_url), $post_id, $staging_url, $elementor_image_map, $elementor_json_string);
					if (is_array($processed) && isset($processed['url'])) {
						$data[$key] = $processed;
					} else {
						// Fallback: just update the URL
						$data[$key]['url'] = $processed;
					}
				} else {
					// Recursively process the array
					$data[$key] = stls_elementor_convert_urls_to_attachment_ids($value, $post_id, $staging_url, $elementor_image_map, $elementor_json_string);
				}
			} else {
				// Regular array, recursively process
				$data[$key] = stls_elementor_convert_urls_to_attachment_ids($value, $post_id, $staging_url, $elementor_image_map, $elementor_json_string);
			}
		} elseif (is_numeric($value) && $value > 0) {
			// Check if it's actually an integer (not a float like 0.7)
			$int_value = intval($value);
			if ($int_value != $value) {
				// It's a float, skip it
				stls_elementor_log('Skipping non-integer numeric value', 'warning', array(
					'value' => $value,
					'int_value' => $int_value,
					'key' => $key
				));
				// Keep original value
				$data[$key] = $value;
				continue;
			}

			// Handle numeric attachment IDs from staging (must be integer, not float)
			// Check if this attachment exists on live site
			$attachment = get_post($int_value);
			if (!$attachment || $attachment->post_type !== 'attachment') {
				// Attachment doesn't exist on live, try to download from staging
				// First check if we have the URL in our image map
				$image_url_from_map = false;
				$staging_id_int = intval($value);
				if (!empty($elementor_image_map) && isset($elementor_image_map[$staging_id_int])) {
					$image_url_from_map = $elementor_image_map[$staging_id_int];
					stls_elementor_log('Found image URL in map, skipping REST API call', 'info', array(
						'staging_id' => $value,
						'staging_id_int' => $staging_id_int,
						'image_url' => $image_url_from_map,
						'map_keys_sample' => array_slice(array_keys($elementor_image_map), 0, 10)
					));
				} else {
					// Log why image map lookup failed
					stls_elementor_log('Image ID not found in map', 'warning', array(
						'staging_id' => $value,
						'staging_id_int' => $staging_id_int,
						'map_has_data' => !empty($elementor_image_map),
						'map_keys' => !empty($elementor_image_map) ? array_keys($elementor_image_map) : array(),
						'map_size' => count($elementor_image_map)
					));
				}

				// If not in map, try to find URL in JSON string by searching for the attachment ID
				if (!$image_url_from_map && !empty($elementor_json_string)) {
					$found_url = stls_elementor_find_url_in_json_by_id($elementor_json_string, intval($value), $staging_url);
					if ($found_url) {
						$image_url_from_map = $found_url;
						stls_elementor_log('Found image URL in JSON string by ID search', 'success', array(
							'staging_id' => $value,
							'image_url' => $image_url_from_map,
							'json_string_length' => strlen($elementor_json_string)
						));
					} else {
						// Try a more aggressive search - look for the ID anywhere and find nearby URLs
						// Search for the ID pattern and extract context around it
						$id_pattern = '/"id"\s*:\s*' . preg_quote((string) intval($value), '/') . '/';
						if (preg_match_all($id_pattern, $elementor_json_string, $id_matches, PREG_OFFSET_CAPTURE)) {
							foreach ($id_matches[0] as $id_match) {
								$id_pos = $id_match[1];
								// Get a larger context (2000 chars) around this ID
								$context_start = max(0, $id_pos - 1000);
								$context_end = min(strlen($elementor_json_string), $id_pos + 1000);
								$context = substr($elementor_json_string, $context_start, $context_end - $context_start);

								// Look for URL patterns in this context
								if (preg_match_all('/"url"\s*:\s*"([^"]*wp-content\/uploads[^"]+)"/', $context, $url_matches)) {
									// Use the first URL found near this ID
									$image_url_from_map = $url_matches[1][0];
									stls_elementor_log('Found image URL via aggressive JSON search', 'success', array(
										'staging_id' => $value,
										'image_url' => $image_url_from_map,
										'context_length' => strlen($context)
									));
									break;
								}
							}
						}

						// LAST RESORT: If image map has URLs but wrong keys, try using any URL from the map
						// This handles cases where the map uses array indices instead of attachment IDs
						if (!$image_url_from_map && !empty($elementor_image_map)) {
							// Get all unique URLs from the image map
							$map_urls = array_unique(array_values($elementor_image_map));
							if (!empty($map_urls)) {
								// Try the first URL from the map as a fallback
								$image_url_from_map = reset($map_urls);
								stls_elementor_log('Using URL from image map as fallback (map has wrong keys)', 'warning', array(
									'staging_id' => $value,
									'image_url' => $image_url_from_map,
									'map_size' => count($elementor_image_map),
									'unique_urls_in_map' => count($map_urls),
									'all_map_urls' => $map_urls
								));
							}
						}

						if (!$image_url_from_map) {
							stls_elementor_log('JSON string search failed after all attempts', 'warning', array(
								'staging_id' => $value,
								'json_string_length' => strlen($elementor_json_string),
								'id_pattern_matches' => isset($id_matches) ? count($id_matches[0]) : 0,
								'image_map_has_urls' => !empty($elementor_image_map),
								'image_map_urls' => !empty($elementor_image_map) ? array_values($elementor_image_map) : array()
							));
						}
					}
				}

				// Get staging API key for authenticated requests
				$staging_api_key = get_option('stls_staging_api_key', '');

				// LAST RESORT BEFORE REST API: If image map has URLs but wrong keys, use any URL from map
				// This handles cases where map uses array indices (0,1,2,3) instead of attachment IDs
				if (!$image_url_from_map && !empty($elementor_image_map)) {
					// Get all unique URLs from the image map
					$map_urls = array_unique(array_values($elementor_image_map));
					if (!empty($map_urls)) {
						// Use the first URL from the map as fallback
						$image_url_from_map = reset($map_urls);
						stls_elementor_log('Using URL from image map as fallback (map has array indices instead of IDs)', 'warning', array(
							'staging_id' => $value,
							'image_url' => $image_url_from_map,
							'map_size' => count($elementor_image_map),
							'unique_urls_in_map' => count($map_urls),
							'all_map_urls' => $map_urls,
							'map_keys' => array_keys($elementor_image_map)
						));
					}
				}

				if (!empty($staging_url)) {
					// Use URL from map if available, otherwise try REST API
					$image_url = $image_url_from_map;

					if (!$image_url) {
						// Construct staging media REST API URL
						$staging_media_url = trailingslashit($staging_url) . 'wp-json/wp/v2/media/' . intval($value);

						// Prepare request with API key if available
						$request_args = array(
							'timeout' => 30,
						);
						if (!empty($staging_api_key)) {
							$request_args['headers'] = array(
								'X-STLS-API-Key' => $staging_api_key,
							);
						}

						// Try to get the image URL from staging REST API
						$response = wp_remote_get($staging_media_url, $request_args);

						if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
							$media_data = json_decode(wp_remote_retrieve_body($response), true);
							if (isset($media_data['source_url'])) {
								$image_url = $media_data['source_url'];

								stls_elementor_log('Retrieved image URL from REST API', 'info', array(
									'staging_id' => $value,
									'image_url' => $image_url
								));
							} else {
								stls_elementor_log('REST API response missing source_url', 'warning', array(
									'staging_id' => $value,
									'media_data_keys' => array_keys($media_data ? $media_data : array())
								));
							}
						} else {
							$error_msg = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
							$error_code = is_wp_error($response) ? $response->get_error_code() : 'http_error';

							stls_elementor_log('Failed to fetch attachment from REST API, trying fallback methods', 'warning', array(
								'staging_id' => $value,
								'api_url' => $staging_media_url,
								'error' => $error_msg,
								'error_code' => $error_code,
								'http_code' => wp_remote_retrieve_response_code($response)
							));
						}
					}

					// If we have an image URL (from map or REST API), try to download it
					if ($image_url) {
						stls_elementor_log('About to download image for numeric ID', 'info', array(
							'staging_id' => $value,
							'image_url' => $image_url,
							'source' => $image_url_from_map ? 'image_map' : 'rest_api',
							'post_id' => $post_id
						));

						$attachment_id = stls_download_and_attach_image($image_url, $post_id, true);

						if ($attachment_id && !is_wp_error($attachment_id)) {
							$data[$key] = intval($attachment_id);

							stls_elementor_log('Numeric attachment ID converted', 'success', array(
								'staging_id' => $value,
								'live_id' => $attachment_id,
								'image_url' => $image_url,
								'source' => $image_url_from_map ? 'image_map' : 'rest_api'
							));
						} else {
							// Download failed, try fallback URL patterns
							$error_msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Unknown error';
							$error_code = is_wp_error($attachment_id) ? $attachment_id->get_error_code() : 'unknown';

							stls_elementor_log('Download failed, trying fallback URL patterns', 'warning', array(
								'staging_id' => $value,
								'image_url' => $image_url,
								'error' => $error_msg,
								'error_code' => $error_code
							));

							// FALLBACK: Try multiple methods to find the image URL
							$fallback_urls = array();

							// Method 1: Try to get URL from staging database via REST API with different endpoints
							// Some sites might have custom endpoints or the attachment might be in a different format
							$alternative_endpoints = array(
								trailingslashit($staging_url) . 'wp-json/wp/v2/media/' . intval($value),
								trailingslashit($staging_url) . '?rest_route=/wp/v2/media/' . intval($value),
							);

							foreach ($alternative_endpoints as $alt_endpoint) {
								$alt_response = wp_remote_get($alt_endpoint, array('timeout' => 10));
								if (!is_wp_error($alt_response) && wp_remote_retrieve_response_code($alt_response) === 200) {
									$alt_data = json_decode(wp_remote_retrieve_body($alt_response), true);
									if (isset($alt_data['source_url'])) {
										$fallback_urls[] = $alt_data['source_url'];
										stls_elementor_log('Found URL via alternative REST endpoint', 'info', array(
											'endpoint' => $alt_endpoint,
											'url' => $alt_data['source_url']
										));
									}
								}
							}

							// Method 2: Try to construct URL from common WordPress upload patterns
							$year = date('Y');
							$month = date('m');

							// Common file extensions
							$extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg');

							// Try current year/month
							foreach ($extensions as $ext) {
								$fallback_urls[] = trailingslashit($staging_url) . 'wp-content/uploads/' . $year . '/' . $month . '/' . intval($value) . '.' . $ext;
								$fallback_urls[] = trailingslashit($staging_url) . 'wp-content/uploads/' . $year . '/' . $month . '/image-' . intval($value) . '.' . $ext;
							}

							// Try previous 12 months
							for ($i = 0; $i < 12; $i++) {
								$test_date = strtotime("-{$i} months");
								$test_year = date('Y', $test_date);
								$test_month = date('m', $test_date);

								foreach ($extensions as $ext) {
									$fallback_urls[] = trailingslashit($staging_url) . 'wp-content/uploads/' . $test_year . '/' . $test_month . '/' . intval($value) . '.' . $ext;
									$fallback_urls[] = trailingslashit($staging_url) . 'wp-content/uploads/' . $test_year . '/' . $test_month . '/image-' . intval($value) . '.' . $ext;
								}
							}

							// Method 3: Try to extract from Elementor JSON if we have it
							if (!empty($elementor_json_string)) {
								$json_url = stls_elementor_find_url_in_json_by_id($elementor_json_string, intval($value), $staging_url);
								if ($json_url) {
									array_unshift($fallback_urls, $json_url); // Add to beginning for priority
									stls_elementor_log('Found URL in JSON string', 'info', array(
										'attachment_id' => $value,
										'url' => $json_url
									));
								}
							}

							$download_success = false;
							$tried_urls = array();

							stls_elementor_log('Trying fallback URL patterns', 'info', array(
								'staging_id' => $value,
								'total_fallback_urls' => count($fallback_urls),
								'first_few_urls' => array_slice($fallback_urls, 0, 5)
							));

							foreach ($fallback_urls as $fallback_url) {
								$tried_urls[] = $fallback_url;

								// Quick HEAD request to check if URL exists
								$head_response = wp_remote_head($fallback_url, array('timeout' => 5));

								if (!is_wp_error($head_response) && wp_remote_retrieve_response_code($head_response) === 200) {
									stls_elementor_log('Fallback URL exists, attempting download', 'info', array(
										'staging_id' => $value,
										'fallback_url' => $fallback_url
									));

									$attachment_id = stls_download_and_attach_image($fallback_url, $post_id, true);

									if ($attachment_id && !is_wp_error($attachment_id)) {
										$data[$key] = intval($attachment_id);
										$download_success = true;

										stls_elementor_log('Successfully downloaded using fallback URL pattern', 'success', array(
											'staging_id' => $value,
											'live_id' => $attachment_id,
											'fallback_url' => $fallback_url,
											'urls_tried' => count($tried_urls)
										));
										break;
									} else {
										$error_msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Unknown error';
										stls_elementor_log('Fallback URL exists but download failed', 'warning', array(
											'staging_id' => $value,
											'fallback_url' => $fallback_url,
											'error' => $error_msg
										));
									}
								}
							}

							if (!$download_success) {
								// LAST RESORT: Try to construct URL from common WordPress patterns
								// This is a fallback when all other methods fail
								$constructed_urls = array();
								$current_year = date('Y');
								$current_month = date('m');

								// Try common upload paths
								$common_paths = array(
									$current_year . '/' . $current_month,
									date('Y', strtotime('-1 year')) . '/' . date('m', strtotime('-1 year')),
									date('Y', strtotime('-2 years')) . '/' . date('m', strtotime('-2 years')),
								);

								$common_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');

								foreach ($common_paths as $path) {
									foreach ($common_extensions as $ext) {
										$constructed_url = trailingslashit($staging_url) . 'wp-content/uploads/' . $path . '/' . intval($value) . '.' . $ext;
										$constructed_urls[] = $constructed_url;

										// Try with scaled suffix
										$constructed_url_scaled = trailingslashit($staging_url) . 'wp-content/uploads/' . $path . '/' . intval($value) . '-scaled.' . $ext;
										$constructed_urls[] = $constructed_url_scaled;
									}
								}

								// Try each constructed URL
								foreach ($constructed_urls as $constructed_url) {
									$head_response = wp_remote_head($constructed_url, array('timeout' => 5));
									if (!is_wp_error($head_response) && wp_remote_retrieve_response_code($head_response) === 200) {
										stls_elementor_log('Found image via constructed URL pattern', 'success', array(
											'staging_id' => $value,
											'constructed_url' => $constructed_url
										));

										$attachment_id = stls_download_and_attach_image($constructed_url, $post_id, true);
										if ($attachment_id && !is_wp_error($attachment_id)) {
											$data[$key] = intval($attachment_id);
											$download_success = true;
											break;
										}
									}
								}

								if (!$download_success) {
									stls_elementor_log('All download methods failed for attachment ID', 'error', array(
										'staging_id' => $value,
										'original_tried_url' => $image_url,
										'tried_fallback_urls' => count($fallback_urls),
										'tried_constructed_urls' => count($constructed_urls),
										'has_image_map' => !empty($elementor_image_map),
										'image_map_has_this_id' => isset($elementor_image_map[intval($value)]),
										'has_json_string' => !empty($elementor_json_string),
										'rest_api_404' => true,
										'sample_fallback_urls' => array_slice($fallback_urls, 0, 10),
										'sample_constructed_urls' => array_slice($constructed_urls, 0, 5)
									));
								}
							}
						}
					} else {
						// No URL found at all
						stls_elementor_log('No image URL available for attachment ID', 'error', array(
							'staging_id' => $value,
							'rest_api_failed' => true,
							'image_map_checked' => !empty($elementor_image_map)
						));
					}
				} else {
					stls_elementor_log('No staging URL available to download attachment ID', 'warning', array(
						'attachment_id' => $value,
						'post_id' => $post_id
					));
				}
			} else {
				// Attachment exists on live site, keep the ID
				$data[$key] = intval($value);
			}
		} elseif (is_string($value) && !empty($value)) {
			$processed_count++;

			// Check if this is an image URL
			$is_image_url = false;

			// Check for common image URL patterns
			if (filter_var($value, FILTER_VALIDATE_URL)) {
				if (
					preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico)(\?.*)?$/i', $value) ||
					strpos($value, 'wp-content/uploads') !== false ||
					strpos($value, '/wp-json/wp/v2/media') !== false
				) {
					$is_image_url = true;
					$image_url_count++;
				}
			}

			// Log ALL string values (not just image URLs) to debug what we're processing
			if (strlen($value) < 200) { // Only log short strings to avoid huge logs
				stls_elementor_log('Processing string value', 'info', array(
					'key' => $key,
					'value_preview' => substr($value, 0, 100),
					'is_image_url' => $is_image_url,
					'is_valid_url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
					'contains_uploads' => strpos($value, 'wp-content/uploads') !== false,
					'post_id' => $post_id
				));
			}

			// Log image URLs specifically
			if ($is_image_url) {
				stls_elementor_log('Found potential image URL string', 'info', array(
					'value' => $value,
					'key' => $key,
					'staging_url' => $staging_url,
					'post_id' => $post_id
				));

				// IMPORTANT: Process image URLs immediately when found
				// Don't wait for numeric ID processing - download the image now
				// Store original staging URL for download
				$original_url = $value;

				// Check if this is a staging URL that needs to be downloaded
				// Normalize URLs for comparison (remove trailing slashes, http/https)
				$normalized_value = rtrim(str_replace(array('http://', 'https://'), '', $value), '/');
				$normalized_staging = rtrim(str_replace(array('http://', 'https://'), '', $staging_url), '/');

				$is_staging_url = false;
				if (!empty($staging_url)) {
					// Check multiple variations
					$is_staging_url = (
						strpos($value, $staging_url) !== false ||
						strpos($normalized_value, $normalized_staging) !== false ||
						strpos($value, str_replace(array('http://', 'https://'), '', $staging_url)) !== false
					);
				}

				// Also check if URL contains common staging patterns (like .local domains)
				if (!$is_staging_url && preg_match('/\.local|staging|dev|test/i', $value)) {
					// If it's not the live site URL, treat it as staging
					$live_url_normalized = rtrim(str_replace(array('http://', 'https://'), '', home_url()), '/');
					if (strpos($normalized_value, $live_url_normalized) === false) {
						$is_staging_url = true;
						stls_elementor_log('Detected staging URL by pattern match', 'info', array(
							'url' => $value,
							'pattern_match' => true
						));
					}
				}

				stls_elementor_log('Processing image URL', 'info', array(
					'original_url' => $original_url,
					'is_staging_url' => $is_staging_url,
					'staging_url_setting' => $staging_url,
					'key' => $key,
					'post_id' => $post_id
				));

				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('STLS Elementor: Processing image URL - Original: ' . $original_url . ', Is staging URL: ' . ($is_staging_url ? 'Yes' : 'No') . ', Staging URL setting: ' . $staging_url);
				}

				// If it's a staging URL OR if it's an upload URL that doesn't match live site, try to download it
				$live_url = home_url();
				$live_url_normalized = rtrim(str_replace(array('http://', 'https://'), '', $live_url), '/');
				$value_normalized = rtrim(str_replace(array('http://', 'https://'), '', $value), '/');

				// Check if URL is from uploads but doesn't match live site
				$is_upload_url = strpos($value, 'wp-content/uploads') !== false;
				$is_live_url = strpos($value_normalized, $live_url_normalized) !== false;

				// Download if: staging URL detected OR upload URL that doesn't match live site
				$should_download = $is_staging_url || ($is_upload_url && !$is_live_url);

				if ($should_download) {
					// Log why we're downloading
					$download_reason = $is_staging_url ? 'staging_url_match' : 'upload_url_not_live';
					stls_elementor_log('Will download image', 'info', array(
						'reason' => $download_reason,
						'is_staging_url' => $is_staging_url,
						'is_upload_url' => $is_upload_url,
						'is_live_url' => $is_live_url,
						'url' => $value,
						'live_url' => $live_url
					));
					stls_elementor_log('Processing staging image URL', 'info', array(
						'url' => $original_url,
						'post_id' => $post_id,
						'key' => $key
					));

					// Quick check if URL is accessible
					$head_response = wp_remote_head($original_url, array('timeout' => 10));
					if (is_wp_error($head_response)) {
						stls_elementor_log('Cannot access staging URL', 'warning', array(
							'url' => $original_url,
							'error' => $head_response->get_error_message(),
							'error_code' => $head_response->get_error_code()
						));
					} elseif (wp_remote_retrieve_response_code($head_response) !== 200) {
						stls_elementor_log('Staging URL returned non-200 status', 'warning', array(
							'url' => $original_url,
							'status_code' => wp_remote_retrieve_response_code($head_response)
						));
					} else {
						stls_elementor_log('Staging URL is accessible', 'success', array('url' => $original_url));
					}

					// Download image from staging using original URL
					stls_elementor_log('Calling download function', 'info', array(
						'url' => $original_url,
						'post_id' => $post_id
					));

					$attachment_id = stls_download_and_attach_image($original_url, $post_id, true);

					// Log download result
					if ($attachment_id && !is_wp_error($attachment_id)) {
						stls_elementor_log('Download function returned ID', 'info', array(
							'attachment_id' => $attachment_id,
							'original_url' => $original_url
						));

						// Verify attachment actually exists in database
						$attachment_post = get_post($attachment_id);
						if ($attachment_post && $attachment_post->post_type === 'attachment') {
							stls_elementor_log('Attachment verified in database', 'success', array(
								'attachment_id' => $attachment_id,
								'title' => $attachment_post->post_title,
								'post_status' => $attachment_post->post_status
							));
						} else {
							stls_elementor_log('Attachment ID returned but post not found in database', 'error', array(
								'attachment_id' => $attachment_id,
								'post_exists' => $attachment_post ? 'yes' : 'no',
								'post_type' => $attachment_post ? $attachment_post->post_type : 'N/A'
							));
							$attachment_id = false;
						}
					} else {
						$error_msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Returned false/0';
						$error_code = is_wp_error($attachment_id) ? $attachment_id->get_error_code() : 'unknown';
						stls_elementor_log('Download failed', 'error', array(
							'url' => $original_url,
							'error' => $error_msg,
							'error_code' => $error_code,
							'post_id' => $post_id
						));
					}

					if ($attachment_id && !is_wp_error($attachment_id)) {
						// Verify attachment exists before using it
						$attachment_post = get_post($attachment_id);
						if ($attachment_post && $attachment_post->post_type === 'attachment') {
							// Get the new live URL for the downloaded image
							$new_live_url = wp_get_attachment_url($attachment_id);
							if ($new_live_url) {
								// Use the new live URL
								$data[$key] = $new_live_url;

								stls_elementor_log('Image successfully downloaded and URL updated', 'success', array(
									'original_url' => $original_url,
									'new_url' => $new_live_url,
									'attachment_id' => $attachment_id,
									'key' => $key
								));
							} else {
								// Fallback to attachment ID if URL not available
								$data[$key] = $attachment_id;

								stls_elementor_log('Downloaded image but URL not available, using ID', 'warning', array(
									'attachment_id' => $attachment_id,
									'original_url' => $original_url
								));
							}
						} else {
							// Attachment doesn't actually exist, treat as failure
							stls_elementor_log('Download returned ID but attachment does not exist', 'error', array(
								'attachment_id' => $attachment_id,
								'original_url' => $original_url
							));
							// Fall through to error handling
							$attachment_id = false;
						}
					}

					if (!$attachment_id || is_wp_error($attachment_id)) {
						// Download failed - log error and replace URL
						$error_msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Unknown error';
						$error_code = is_wp_error($attachment_id) ? $attachment_id->get_error_code() : 'unknown';

						stls_elementor_log('Download failed, replacing URL as fallback', 'error', array(
							'url' => $original_url,
							'error' => $error_msg,
							'error_code' => $error_code,
							'post_id' => $post_id
						));

						// Replace staging URL with live URL as fallback
						$live_url = home_url();
						$replaced_url = str_replace(
							array(
								$staging_url,
								trailingslashit($staging_url),
								untrailingslashit($staging_url)
							),
							array(
								$live_url,
								trailingslashit($live_url),
								untrailingslashit($live_url)
							),
							$original_url
						);
						$data[$key] = $replaced_url;
					}
				} else {
					// Not a staging URL - check if attachment exists by URL
					$attachment_id = attachment_url_to_postid($value);

					if ($attachment_id) {
						// Found existing attachment, use attachment ID
						$data[$key] = $attachment_id;

						if (defined('WP_DEBUG') && WP_DEBUG) {
							error_log('STLS Elementor: Found existing attachment - URL: ' . $value . ', Attachment ID: ' . $attachment_id);
						}
					} else {
						// Attachment doesn't exist, keep the URL as-is
						$data[$key] = $value;

						if (defined('WP_DEBUG') && WP_DEBUG) {
							error_log('STLS Elementor: No existing attachment found for URL: ' . $value . ', keeping URL as-is');
						}
					}
				}
			} elseif (!empty($staging_url) && strpos($value, $staging_url) !== false) {
				// Replace staging URL with live URL for non-image URLs too
				$live_url = home_url();
				$data[$key] = str_replace($staging_url, $live_url, $value);
			}
		}
	}

	// Log summary before returning
	stls_elementor_log('convert_urls_to_attachment_ids completed', 'info', array(
		'post_id' => $post_id,
		'processed_strings' => $processed_count,
		'image_urls_found' => $image_url_count,
		'data_keys_count' => count($data)
	));

	return $data;
}

/**
 * Replace URLs in a JSON string (fallback method)
 * 
 * @param string $json_string The JSON string
 * @param int    $post_id     The post ID
 * @param string $staging_url The staging URL
 * @return string Modified JSON string
 */
function stls_elementor_replace_urls_in_string($json_string, $post_id, $staging_url = '')
{
	if (empty($staging_url)) {
		return $json_string;
	}

	$live_url = home_url();

	// IMPORTANT: Only replace URLs in JSON string values, not in HTML attributes or content
	// Use a more targeted approach to avoid breaking HTML structure
	// Replace staging URL with live URL, but be careful not to break JSON structure
	$json_string = str_replace($staging_url, $live_url, $json_string);
	$json_string = str_replace(
		array(
			trailingslashit($staging_url),
			untrailingslashit($staging_url)
		),
		array(
			trailingslashit($live_url),
			untrailingslashit($live_url)
		),
		$json_string
	);

	// Verify JSON is still valid after replacement
	$test_decode = json_decode($json_string, true);
	if (json_last_error() !== JSON_ERROR_NONE) {
		// If JSON is broken, log error but return original to prevent breaking the page
		if (function_exists('stls_elementor_log')) {
			stls_elementor_log('JSON string became invalid after URL replacement', 'error', array(
				'post_id' => $post_id,
				'json_error' => json_last_error_msg(),
				'error_code' => json_last_error()
			));
		}
		// Return original string to prevent breaking the page
		// This is a fallback - ideally we should fix the replacement logic
		return $json_string;
	}

	return $json_string;
}

/**
 * Recursively replace URLs in an array
 * 
 * @param array  $data        The array data
 * @param string $staging_url The staging URL
 * @param string $live_url    The live URL
 * @return mixed Modified array or string with URLs replaced
 */
function stls_elementor_replace_urls_in_array($data, $staging_url, $live_url = '')
{
	if (!is_array($data)) {
		if (is_string($data) && !empty($staging_url)) {
			return str_replace($staging_url, $live_url, $data);
		}
		return $data;
	}

	foreach ($data as $key => $value) {
		if (is_array($value)) {
			$data[$key] = stls_elementor_replace_urls_in_array($value, $staging_url, $live_url);
		} elseif (is_string($value) && !empty($staging_url)) {
			$data[$key] = str_replace($staging_url, $live_url, $value);
		}
	}

	return $data;
}

/**
 * Recursively replace staging URLs with live URLs in an array (more thorough)
 * Handles all variations of staging URLs
 * 
 * @param array  $data        The array data
 * @param string $staging_url The staging URL
 * @param string $live_url    The live URL
 * @return array Modified array with URLs replaced
 */
function stls_elementor_replace_staging_urls_in_array($data, $staging_url, $live_url)
{
	if (!is_array($data)) {
		if (is_string($data) && !empty($staging_url)) {
			// IMPORTANT: Only replace URLs, not HTML content
			// Check if this string contains HTML tags - if so, be more careful
			$contains_html = (strpos($data, '<') !== false && strpos($data, '>') !== false);

			if ($contains_html) {
				// For HTML content, only replace URLs in href/src attributes, not in text content
				// Use regex to target URL patterns in attributes
				$patterns = array(
					'/(href|src|data-src|data-lazy-src)=["\']' . preg_quote($staging_url, '/') . '([^"\']*)["\']/i',
					'/(href|src|data-src|data-lazy-src)=["\']' . preg_quote(trailingslashit($staging_url), '/') . '([^"\']*)["\']/i',
				);
				$replacements = array(
					'$1="' . $live_url . '$2"',
					'$1="' . trailingslashit($live_url) . '$2"',
				);
				$data = preg_replace($patterns, $replacements, $data);
			} else {
				// For non-HTML strings, do full replacement
				$data = str_replace(
					array(
						$staging_url,
						trailingslashit($staging_url),
						untrailingslashit($staging_url),
						str_replace(array('http://', 'https://'), '', $staging_url),
						str_replace(array('http://', 'https://'), '', trailingslashit($staging_url)),
					),
					array(
						$live_url,
						trailingslashit($live_url),
						untrailingslashit($live_url),
						str_replace(array('http://', 'https://'), '', $live_url),
						str_replace(array('http://', 'https://'), '', trailingslashit($live_url)),
					),
					$data
				);
			}
		}
		return $data;
	}

	foreach ($data as $key => $value) {
		if (is_array($value)) {
			$data[$key] = stls_elementor_replace_staging_urls_in_array($value, $staging_url, $live_url);
		} elseif (is_string($value) && !empty($staging_url)) {
			// Replace all variations of staging URL
			$data[$key] = str_replace(
				array(
					$staging_url,
					trailingslashit($staging_url),
					untrailingslashit($staging_url),
				),
				array(
					$live_url,
					trailingslashit($live_url),
					untrailingslashit($live_url),
				),
				$value
			);
		}
	}

	return $data;
}

/**
 * Replace URLs in Elementor data with downloaded attachment URLs
 * 
 * @param array $data Elementor data array
 * @param array $downloaded_attachments Array mapping original_url => ['attachment_id' => id, 'new_url' => url]
 * @param string $staging_url The staging URL
 * @return array Modified data with URLs replaced
 */
function stls_elementor_replace_urls_with_attachments($data, $downloaded_attachments, $staging_url = '')
{
	if (!is_array($data)) {
		if (is_string($data) && !empty($downloaded_attachments)) {
			// Replace URLs in strings - but be careful not to break JSON structure
			foreach ($downloaded_attachments as $original_url => $attachment_data) {
				if (strpos($data, $original_url) !== false) {
					$data = str_replace($original_url, $attachment_data['new_url'], $data);
				}
			}
		}
		return $data;
	}

	// IMPORTANT: Preserve Elementor widget structure keys
	// Never modify keys like 'elType', 'widgetType', 'id', 'settings', 'elements', etc.
	// Only modify URL values within the structure
	$preserved_keys = array('elType', 'widgetType', 'id', 'settings', 'elements', 'isInner', 'isLocked', 'cssClasses');

	foreach ($data as $key => $value) {
		// Skip if this is a widget structure key that should not be modified
		if (in_array($key, $preserved_keys) && !is_array($value)) {
			continue; // Don't modify structure keys
		}

		if (is_array($value)) {
			// Check if this is an image object (has both 'id' and 'url' or just 'url')
			if (isset($value['url']) && is_string($value['url'])) {
				$image_url = $value['url'];
				// Check if this URL was downloaded
				foreach ($downloaded_attachments as $original_url => $attachment_data) {
					if ($image_url === $original_url || strpos($image_url, $original_url) !== false) {
						// Replace with new URL and attachment ID
						$data[$key]['url'] = $attachment_data['new_url'];
						if (isset($data[$key]['id']) && is_numeric($data[$key]['id'])) {
							$data[$key]['id'] = $attachment_data['attachment_id'];
						} elseif (!isset($data[$key]['id'])) {
							$data[$key]['id'] = $attachment_data['attachment_id'];
						}
						break;
					}
				}
			} else {
				// Recursively process nested arrays - but preserve widget structure
				$data[$key] = stls_elementor_replace_urls_with_attachments($value, $downloaded_attachments, $staging_url);
			}
		} elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
			// Check if this string is a URL that was downloaded
			foreach ($downloaded_attachments as $original_url => $attachment_data) {
				if ($value === $original_url || strpos($value, $original_url) !== false) {
					$data[$key] = $attachment_data['new_url'];
					break;
				}
			}
		}
	}

	return $data;
}

/**
 * Extract image ID to URL mapping from Elementor data
 * Enhanced to find more patterns, especially for Happy Elementor layouts
 * 
 * @param array $data Elementor data array
 * @return array Array mapping attachment_id => url
 */
function stls_elementor_extract_image_map($data, $parent_key = null)
{
	$image_map = array();

	if (!is_array($data)) {
		return $image_map;
	}

	foreach ($data as $key => $value) {
		// IMPORTANT: Don't use array key as ID - only use actual 'id' field from image objects
		if (is_array($value)) {
			// Check if this is an image object with both ID and URL
			if (isset($value['id']) && is_numeric($value['id'])) {
				$attachment_id = intval($value['id']);

				// If URL is provided, use it
				if (isset($value['url']) && !empty($value['url'])) {
					$image_map[$attachment_id] = $value['url'];
				}
				// Check various nested structures for URLs
				elseif (isset($value['image']) && is_array($value['image'])) {
					if (isset($value['image']['url']) && !empty($value['image']['url'])) {
						$image_map[$attachment_id] = $value['image']['url'];
					}
					// Also check if image itself has id and url
					if (isset($value['image']['id']) && is_numeric($value['image']['id']) && isset($value['image']['url'])) {
						$image_map[intval($value['image']['id'])] = $value['image']['url'];
					}
				}
				// Check for background_image
				elseif (isset($value['background_image']) && is_array($value['background_image'])) {
					if (isset($value['background_image']['url']) && !empty($value['background_image']['url'])) {
						$image_map[$attachment_id] = $value['background_image']['url'];
					}
					if (isset($value['background_image']['id']) && is_numeric($value['background_image']['id']) && isset($value['background_image']['url'])) {
						$image_map[intval($value['background_image']['id'])] = $value['background_image']['url'];
					}
				}
				// Check for background_overlay_image
				elseif (isset($value['background_overlay_image']) && is_array($value['background_overlay_image'])) {
					if (isset($value['background_overlay_image']['url']) && !empty($value['background_overlay_image']['url'])) {
						$image_map[$attachment_id] = $value['background_overlay_image']['url'];
					}
				}
				// Check for _background_image (with underscore)
				elseif (isset($value['_background_image']) && is_array($value['_background_image'])) {
					if (isset($value['_background_image']['url']) && !empty($value['_background_image']['url'])) {
						$image_map[$attachment_id] = $value['_background_image']['url'];
					}
				}
			}

			// Check for settings array with image data (common in Elementor)
			if (isset($value['settings']) && is_array($value['settings'])) {
				foreach ($value['settings'] as $setting_key => $setting_value) {
					// Check for background_image in settings (common for sections/columns)
					if ($setting_key === 'background_image' && is_array($setting_value)) {
						if (isset($setting_value['id']) && is_numeric($setting_value['id']) && isset($setting_value['url'])) {
							$image_map[intval($setting_value['id'])] = $setting_value['url'];
						} elseif (isset($setting_value['url']) && !empty($setting_value['url'])) {
							// URL without ID - try to extract ID from URL or use URL directly
							$url = $setting_value['url'];
							if (preg_match('/\/wp-json\/wp\/v2\/media\/(\d+)/', $url, $matches)) {
								$image_map[intval($matches[1])] = $url;
							} else {
								// Store URL with a temporary key for later processing
								$image_map['url_' . md5($url)] = $url;
							}
						}
					}
					// Check for background_background_image (nested background settings)
					if ($setting_key === 'background_background_image' && is_array($setting_value)) {
						if (isset($setting_value['id']) && is_numeric($setting_value['id']) && isset($setting_value['url'])) {
							$image_map[intval($setting_value['id'])] = $setting_value['url'];
						} elseif (isset($setting_value['url']) && !empty($setting_value['url'])) {
							$url = $setting_value['url'];
							if (preg_match('/\/wp-json\/wp\/v2\/media\/(\d+)/', $url, $matches)) {
								$image_map[intval($matches[1])] = $url;
							} else {
								$image_map['url_' . md5($url)] = $url;
							}
						}
					}
					// Check for background_overlay_image
					if ($setting_key === 'background_overlay_image' && is_array($setting_value)) {
						if (isset($setting_value['id']) && is_numeric($setting_value['id']) && isset($setting_value['url'])) {
							$image_map[intval($setting_value['id'])] = $setting_value['url'];
						} elseif (isset($setting_value['url']) && !empty($setting_value['url'])) {
							$url = $setting_value['url'];
							if (preg_match('/\/wp-json\/wp\/v2\/media\/(\d+)/', $url, $matches)) {
								$image_map[intval($matches[1])] = $url;
							} else {
								$image_map['url_' . md5($url)] = $url;
							}
						}
					}
					// Check for hover_image (for image widgets with hover effects)
					if ($setting_key === 'hover_image' && is_array($setting_value)) {
						if (isset($setting_value['id']) && is_numeric($setting_value['id']) && isset($setting_value['url'])) {
							$image_map[intval($setting_value['id'])] = $setting_value['url'];
						} elseif (isset($setting_value['url']) && !empty($setting_value['url'])) {
							$url = $setting_value['url'];
							if (preg_match('/\/wp-json\/wp\/v2\/media\/(\d+)/', $url, $matches)) {
								$image_map[intval($matches[1])] = $url;
							} else {
								$image_map['url_' . md5($url)] = $url;
							}
						}
					}
					// Look for keys that might contain images - MUST have both 'id' and 'url'
					if (is_array($setting_value) && isset($setting_value['id']) && is_numeric($setting_value['id']) && isset($setting_value['url']) && !empty($setting_value['url'])) {
						$setting_id = intval($setting_value['id']);
						$image_map[$setting_id] = $setting_value['url'];
					}
					// Also check if setting value itself is an image object with URL but no ID - try to extract ID from URL
					elseif (is_array($setting_value) && isset($setting_value['url']) && filter_var($setting_value['url'], FILTER_VALIDATE_URL) && (!isset($setting_value['id']) || empty($setting_value['id']))) {
						// Try to extract ID from URL pattern like /wp-content/uploads/2025/12/123.jpg or /wp-json/wp/v2/media/123
						if (preg_match('/\/wp-json\/wp\/v2\/media\/(\d+)/', $setting_value['url'], $url_matches)) {
							$possible_id = intval($url_matches[1]);
							if ($possible_id > 0) {
								$image_map[$possible_id] = $setting_value['url'];
							}
						} elseif (preg_match('/[\/-](\d+)[\.\/_-]/', $setting_value['url'], $url_matches) && strpos($setting_value['url'], 'wp-content/uploads') !== false) {
							$possible_id = intval($url_matches[1]);
							if ($possible_id > 0) {
								$image_map[$possible_id] = $setting_value['url'];
							}
						}
					}
				}
			}

			// Check for widgetSettings (Happy Elementor and other addons)
			if (isset($value['widgetSettings']) && is_array($value['widgetSettings'])) {
				$widget_map = stls_elementor_extract_image_map($value['widgetSettings']);
				$image_map = array_merge($image_map, $widget_map);
			}

			// Check for any key ending with _image or containing 'image'
			foreach ($value as $sub_key => $sub_value) {
				if (is_array($sub_value) && (strpos($sub_key, 'image') !== false || strpos($sub_key, 'Image') !== false)) {
					if (isset($sub_value['id']) && is_numeric($sub_value['id']) && isset($sub_value['url'])) {
						$image_map[intval($sub_value['id'])] = $sub_value['url'];
					}
				}
			}

			// Recursively search nested arrays (but don't pass array key as parent_key to avoid using indices as IDs)
			$nested_map = stls_elementor_extract_image_map($value, null);
			$image_map = array_merge($image_map, $nested_map);
		} elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
			// Check if URL contains attachment ID pattern
			// Pattern: /wp-json/wp/v2/media/123 or /wp-content/uploads/.../123.jpg
			if (preg_match('/\/wp-json\/wp\/v2\/media\/(\d+)/', $value, $matches)) {
				$attachment_id = intval($matches[1]);
				if ($attachment_id > 0) {
					$image_map[$attachment_id] = $value;
				}
			} elseif (preg_match('/[\/-](\d+)[\._-]/', $value, $matches)) {
				$possible_id = intval($matches[1]);
				if ($possible_id > 0 && strpos($value, 'wp-content/uploads') !== false) {
					if (!isset($image_map[$possible_id])) {
						$image_map[$possible_id] = $value;
					}
				}
			}
		}
	}

	return $image_map;
}

/**
 * Extract image map from JSON string by searching for ID/URL patterns
 * This is a fallback when array-based extraction misses some images
 * 
 * @param string $json_string The JSON string to search
 * @return array Array mapping attachment_id => url
 */
function stls_elementor_extract_image_map_from_json($json_string)
{
	$image_map = array();

	if (empty($json_string) || !is_string($json_string)) {
		return $image_map;
	}

	// Pattern 1: {"id":123,"url":"http://..."} or {"id":"123","url":"http://..."}
	// Also handle cases where id and url might be in different order
	$pattern1 = '/"id"\s*:\s*"?(\d+)"?\s*,\s*"url"\s*:\s*"([^"]+)"/';
	if (preg_match_all($pattern1, $json_string, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			$attachment_id = intval($match[1]);
			$url = $match[2];
			if ($attachment_id > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
				$image_map[$attachment_id] = $url;
			}
		}
	}

	// Pattern 1b: {"url":"http://...","id":123} (reverse order)
	$pattern1b = '/"url"\s*:\s*"([^"]+)"\s*,\s*"id"\s*:\s*"?(\d+)"?/';
	if (preg_match_all($pattern1b, $json_string, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			$url = $match[1];
			$attachment_id = intval($match[2]);
			if ($attachment_id > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
				$image_map[$attachment_id] = $url;
			}
		}
	}

	// Pattern 2: Look for URLs in uploads directory and try to extract IDs from nearby id fields
	$pattern2 = '/"url"\s*:\s*"([^"]*wp-content\/uploads[^"]*)"[^}]*"id"\s*:\s*"?(\d+)"?/';
	if (preg_match_all($pattern2, $json_string, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			$url = $match[1];
			$attachment_id = intval($match[2]);
			if ($attachment_id > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
				$image_map[$attachment_id] = $url;
			}
		}
	}

	// Pattern 3: Find standalone numeric IDs that might be attachment IDs (look for patterns like "id":20 or "id":"20")
	// Then search nearby for URLs - use larger search window
	$pattern3 = '/"id"\s*:\s*"?(\d{2,})"?/'; // IDs with 2+ digits (to avoid matching small numbers)
	if (preg_match_all($pattern3, $json_string, $id_matches, PREG_OFFSET_CAPTURE)) {
		foreach ($id_matches[1] as $id_match) {
			$attachment_id = intval($id_match[0]);
			$id_position = $id_match[1];

			// Search for URL near this ID (within 2000 characters for better coverage)
			$search_start = max(0, $id_position - 1000);
			$search_end = min(strlen($json_string), $id_position + 1000);
			$search_section = substr($json_string, $search_start, $search_end - $search_start);

			// Look for URL pattern near this ID - try multiple patterns
			// First try uploads URLs
			if (preg_match('/"url"\s*:\s*"([^"]*wp-content\/uploads[^"]+)"/', $search_section, $url_match)) {
				$url = $url_match[1];
				if (filter_var($url, FILTER_VALIDATE_URL)) {
					$image_map[$attachment_id] = $url;
				}
			}
			// Also try any URL pattern (broader search)
			elseif (preg_match('/"url"\s*:\s*"([^"]+)"/', $search_section, $url_match)) {
				$url = $url_match[1];
				// Only accept if it looks like an image URL
				if (filter_var($url, FILTER_VALIDATE_URL) && (strpos($url, 'wp-content/uploads') !== false || preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $url))) {
					$image_map[$attachment_id] = $url;
				}
			}
		}
	}

	return $image_map;
}

/**
 * Verify that Elementor widget structure is preserved
 * Checks that critical keys like elType, widgetType, settings, elements are still present
 * 
 * @param array $current_data Current processed data
 * @param array $original_data Original data before processing
 * @return bool True if structure is valid, false if corrupted
 */
function stls_elementor_verify_widget_structure($current_data, $original_data)
{
	if (!is_array($current_data) || !is_array($original_data)) {
		return false;
	}

	// Check if top-level structure is preserved
	if (count($current_data) !== count($original_data)) {
		return false;
	}

	// Check each top-level item
	foreach ($original_data as $idx => $original_item) {
		if (!isset($current_data[$idx]) || !is_array($current_data[$idx])) {
			return false;
		}

		$current_item = $current_data[$idx];

		// Check critical Elementor keys
		if (isset($original_item['elType']) && !isset($current_item['elType'])) {
			return false;
		}
		if (isset($original_item['elType']) && $original_item['elType'] !== $current_item['elType']) {
			return false;
		}

		if (isset($original_item['widgetType']) && !isset($current_item['widgetType'])) {
			return false;
		}
		if (isset($original_item['widgetType']) && $original_item['widgetType'] !== $current_item['widgetType']) {
			return false;
		}

		// Check if settings structure is preserved
		if (isset($original_item['settings']) && !isset($current_item['settings'])) {
			return false;
		}

		// Check if elements structure is preserved (for sections/containers)
		if (isset($original_item['elements']) && !isset($current_item['elements'])) {
			return false;
		}
	}

	return true;
}

/**
 * Check if Elementor structure contains HTML content
 * This helps determine if we need to use minimal processing
 * 
 * @param array $data Elementor data array
 * @return bool True if HTML is detected
 */
/**
 * Check if Elementor data structure contains HTML content
 * 
 * @param mixed $data Elementor data array or string
 * @param int   $depth Current recursion depth
 * @return bool True if HTML is found
 */
function stls_elementor_contains_html_in_structure($data, $depth = 0)
{
	if ($depth > 10) {
		return false; // Prevent infinite recursion (increased depth for better detection)
	}

	if (!is_array($data)) {
		if (is_string($data)) {
			// Check if string contains HTML tags (including escaped ones)
			$has_html = (strpos($data, '<') !== false && strpos($data, '>') !== false && preg_match('/<[a-z][\s\S]*>/i', $data));
			$has_escaped_html = (strpos($data, '&lt;') !== false && strpos($data, '&gt;') !== false && preg_match('/&lt;[a-z][\s\S]*&gt;/i', $data));
			// Also check for common HTML tags that might be in widget content
			$has_common_tags = (preg_match('/<(span|div|p|a|strong|em|br|img|h[1-6])/i', $data) || preg_match('/&lt;(span|div|p|a|strong|em|br|img|h[1-6])/i', $data));
			return $has_html || $has_escaped_html || $has_common_tags;
		}
		return false;
	}

	foreach ($data as $key => $value) {
		// Check in settings (where widget content is stored)
		if ($key === 'settings' && is_array($value)) {
			foreach ($value as $setting_key => $setting_value) {
				if (is_string($setting_value)) {
					// Check for HTML tags (including escaped)
					$has_html = (strpos($setting_value, '<') !== false && strpos($setting_value, '>') !== false && preg_match('/<[a-z][\s\S]*>/i', $setting_value));
					$has_escaped_html = (strpos($setting_value, '&lt;') !== false && strpos($setting_value, '&gt;') !== false && preg_match('/&lt;[a-z][\s\S]*&gt;/i', $setting_value));
					$has_common_tags = (preg_match('/<(span|div|p|a|strong|em|br|img|h[1-6])/i', $setting_value) || preg_match('/&lt;(span|div|p|a|strong|em|br|img|h[1-6])/i', $setting_value));
					if ($has_html || $has_escaped_html || $has_common_tags) {
						return true;
					}
				}
			}
		}

		// Recursively check nested arrays
		if (is_array($value)) {
			if (stls_elementor_contains_html_in_structure($value, $depth + 1)) {
				return true;
			}
		} elseif (is_string($value)) {
			// Check for HTML tags (including escaped)
			$has_html = (strpos($value, '<') !== false && strpos($value, '>') !== false && preg_match('/<[a-z][\s\S]*>/i', $value));
			$has_escaped_html = (strpos($value, '&lt;') !== false && strpos($value, '&gt;') !== false && preg_match('/&lt;[a-z][\s\S]*&gt;/i', $value));
			$has_common_tags = (preg_match('/<(span|div|p|a|strong|em|br|img|h[1-6])/i', $value) || preg_match('/&lt;(span|div|p|a|strong|em|br|img|h[1-6])/i', $value));
			if ($has_html || $has_escaped_html || $has_common_tags) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Safely replace URLs in Elementor data when HTML is present
 * Only replaces URLs in string values that don't contain HTML
 * 
 * @param array  $data                 The Elementor data array
 * @param array  $downloaded_attachments Array of original_url => attachment_data
 * @param string $staging_url          The staging URL
 * @return mixed Modified data with URLs replaced safely
 */
function stls_elementor_replace_urls_safe_for_html($data, $downloaded_attachments = array(), $staging_url = '')
{
	if (!is_array($data)) {
		if (is_string($data)) {
			// Check if this string contains HTML - if so, don't replace URLs in it
			$contains_html = (strpos($data, '<') !== false && strpos($data, '>') !== false) ||
				(strpos($data, '&lt;') !== false && strpos($data, '&gt;') !== false);

			if (!$contains_html) {
				// Safe to replace URLs - this is not HTML content
				$modified = $data;

				// Replace downloaded attachment URLs
				if (!empty($downloaded_attachments)) {
					foreach ($downloaded_attachments as $original_url => $attachment_data) {
						$modified = str_replace($original_url, $attachment_data['new_url'], $modified);
					}
				}

				// Replace staging URLs with live URLs
				if (!empty($staging_url) && strpos($modified, $staging_url) !== false) {
					$live_url = home_url();
					$modified = str_replace($staging_url, $live_url, $modified);
				}

				return $modified;
			}
		}
		return $data;
	}

	foreach ($data as $key => $value) {
		if (is_array($value)) {
			$data[$key] = stls_elementor_replace_urls_safe_for_html($value, $downloaded_attachments, $staging_url);
		} elseif (is_string($value)) {
			// Check if this string contains HTML
			$contains_html = (strpos($value, '<') !== false && strpos($value, '>') !== false) ||
				(strpos($value, '&lt;') !== false && strpos($value, '&gt;') !== false);

			if (!$contains_html) {
				// Safe to replace URLs - this is not HTML content
				$modified = $value;

				// Replace downloaded attachment URLs
				if (!empty($downloaded_attachments)) {
					foreach ($downloaded_attachments as $original_url => $attachment_data) {
						$modified = str_replace($original_url, $attachment_data['new_url'], $modified);
					}
				}

				// Replace staging URLs with live URLs
				if (!empty($staging_url) && strpos($modified, $staging_url) !== false) {
					$live_url = home_url();
					$modified = str_replace($staging_url, $live_url, $modified);
				}

				$data[$key] = $modified;
			}
			// If it contains HTML, leave it unchanged
		}
	}

	return $data;
}

/**
 * Safe URL replacement that preserves Elementor widget structure
 * Only replaces URLs in string values, never modifies widget keys or structure
 * 
 * @param array  $data        The Elementor data array
 * @param string $staging_url The staging URL
 * @param string $live_url    The live URL
 * @return mixed Modified data with URLs replaced, structure preserved
 */
function stls_elementor_replace_staging_urls_in_array_safe($data, $staging_url, $live_url)
{
	if (!is_array($data)) {
		if (is_string($data) && !empty($staging_url)) {
			// Only replace URLs, preserve HTML and structure
			return str_replace($staging_url, $live_url, $data);
		}
		return $data;
	}

	// CRITICAL: Never modify these widget structure keys
	$preserved_keys = array('elType', 'widgetType', 'id', 'settings', 'elements', 'isInner', 'isLocked', 'cssClasses', '_id', '_widget_type');

	foreach ($data as $key => $value) {
		// Skip widget structure keys - never modify them
		if (in_array($key, $preserved_keys)) {
			// Only process nested arrays within structure keys, not the keys themselves
			if (is_array($value)) {
				$data[$key] = stls_elementor_replace_staging_urls_in_array_safe($value, $staging_url, $live_url);
			}
			// Skip string values in structure keys to preserve them
			continue;
		}

		if (is_array($value)) {
			// Recursively process nested arrays
			$data[$key] = stls_elementor_replace_staging_urls_in_array_safe($value, $staging_url, $live_url);
		} elseif (is_string($value) && !empty($staging_url) && strpos($value, $staging_url) !== false) {
			// Only replace URLs in string values
			// Be careful not to break HTML content
			$data[$key] = str_replace($staging_url, $live_url, $value);
		}
	}

	return $data;
}

/**
 * Get a sample of Elementor data structure for debugging
 * Helps understand how images are stored in the data
 * 
 * @param array $data Elementor data array
 * @param int   $max_depth Maximum depth to traverse
 * @return mixed Sample data structure
 */
function stls_elementor_get_data_sample($data, $max_depth = 3, $current_depth = 0)
{
	if ($current_depth >= $max_depth) {
		return '[max depth reached]';
	}

	if (!is_array($data)) {
		if (is_string($data) && strlen($data) > 200) {
			return substr($data, 0, 200) . '...';
		}
		return $data;
	}

	$sample = array();
	$count = 0;
	foreach ($data as $key => $value) {
		if ($count >= 5) { // Limit to 5 items per level
			$sample['...'] = '[' . (count($data) - 5) . ' more items]';
			break;
		}

		if (is_array($value)) {
			// Check if this looks like an image object
			if (isset($value['id']) || isset($value['url']) || isset($value['image'])) {
				$sample[$key] = array(
					'[image_object]' => true,
					'has_id' => isset($value['id']),
					'has_url' => isset($value['url']),
					'has_image' => isset($value['image']),
					'id_value' => isset($value['id']) ? $value['id'] : 'N/A',
					'url_value' => isset($value['url']) ? (strlen($value['url']) > 100 ? substr($value['url'], 0, 100) . '...' : $value['url']) : 'N/A'
				);
			} else {
				$sample[$key] = stls_elementor_get_data_sample($value, $max_depth, $current_depth + 1);
			}
		} elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && (strpos($value, 'wp-content/uploads') !== false || preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $value))) {
			$sample[$key] = '[IMAGE_URL] ' . (strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value);
		} elseif (is_numeric($value) && $value > 10) {
			$sample[$key] = '[NUMERIC_ID] ' . $value;
		} else {
			$sample[$key] = is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
		}
		$count++;
	}

	return $sample;
}

/**
 * Find image URL in JSON string by searching for attachment ID patterns
 * This is a fallback when image map doesn't have the URL
 * 
 * @param string $json_string The JSON string to search
 * @param int    $attachment_id The attachment ID to find
 * @param string $staging_url The staging URL
 * @return string|false Image URL if found, false otherwise
 */
function stls_elementor_find_url_in_json_by_id($json_string, $attachment_id, $staging_url = '')
{
	if (empty($json_string) || empty($attachment_id)) {
		return false;
	}

	$attachment_id_str = (string) $attachment_id;

	// Search for patterns like: "id":50 or "id":"50" followed by "url":"..."
	// Pattern 1: {"id":50,"url":"http://..."} (id and url on same line)
	$pattern1 = '/"id"\s*:\s*' . preg_quote($attachment_id_str, '/') . '\s*,\s*"url"\s*:\s*"([^"]+)"/';
	if (preg_match($pattern1, $json_string, $matches)) {
		return $matches[1];
	}

	// Pattern 2: {"id":"50","url":"http://..."} (id as string)
	$pattern2 = '/"id"\s*:\s*"' . preg_quote($attachment_id_str, '/') . '"\s*,\s*"url"\s*:\s*"([^"]+)"/';
	if (preg_match($pattern2, $json_string, $matches)) {
		return $matches[1];
	}

	// Pattern 3: {"url":"http://...","id":50} (reverse order)
	$pattern3 = '/"url"\s*:\s*"([^"]+)"\s*,\s*"id"\s*:\s*' . preg_quote($attachment_id_str, '/') . '/';
	if (preg_match($pattern3, $json_string, $matches)) {
		return $matches[1];
	}

	// Pattern 4: {"url":"http://...","id":"50"} (reverse order, id as string)
	$pattern4 = '/"url"\s*:\s*"([^"]+)"\s*,\s*"id"\s*:\s*"' . preg_quote($attachment_id_str, '/') . '"/';
	if (preg_match($pattern4, $json_string, $matches)) {
		return $matches[1];
	}

	// Pattern 5: Find the ID, then search nearby (within 500 chars) for a URL
	$pattern5 = '/"id"\s*:\s*' . preg_quote($attachment_id_str, '/') . '/';
	if (preg_match($pattern5, $json_string, $id_matches, PREG_OFFSET_CAPTURE)) {
		$id_position = $id_matches[0][1];
		// Search 1000 characters around the ID for a URL
		$search_start = max(0, $id_position - 500);
		$search_end = min(strlen($json_string), $id_position + 500);
		$search_section = substr($json_string, $search_start, $search_end - $search_start);

		// Look for URL pattern in this section
		if (preg_match('/"url"\s*:\s*"([^"]*wp-content\/uploads[^"]+)"/', $search_section, $url_matches)) {
			return $url_matches[1];
		}
		// Or any URL pattern
		if (preg_match('/"url"\s*:\s*"([^"]+)"/', $search_section, $url_matches)) {
			$found_url = $url_matches[1];
			// Only return if it looks like an image URL
			if (strpos($found_url, 'wp-content/uploads') !== false || preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $found_url)) {
				return $found_url;
			}
		}
	}

	// Pattern 3: Search for URLs containing the attachment ID in uploads path
	// Look for: wp-content/uploads/.../50.jpg or similar
	$pattern3 = '/(https?:\/\/[^"]*wp-content\/uploads\/[^"]*[\/-]' . preg_quote($attachment_id, '/') . '[\._-][^"]*)/i';
	if (preg_match($pattern3, $json_string, $matches)) {
		return $matches[1];
	}

	// Pattern 4: Search for URLs with attachment ID anywhere in the path
	if (!empty($staging_url)) {
		$pattern4 = '/(https?:\/\/[^"]*[\/-]' . preg_quote($attachment_id, '/') . '[\/\._-][^"]*)/i';
		if (preg_match($pattern4, $json_string, $matches)) {
			$found_url = $matches[1];
			// Verify it's from staging
			if (strpos($found_url, $staging_url) !== false || strpos($found_url, 'wp-content/uploads') !== false) {
				return $found_url;
			}
		}
	}

	return false;
}

/**
 * Find image URL in Elementor data by attachment ID
 * 
 * @param array  $data        Elementor data array
 * @param int    $attachment_id Attachment ID to search for
 * @param string $staging_url Staging URL
 * @return string|false Image URL if found, false otherwise
 */
function stls_elementor_find_image_url_in_data($data, $attachment_id, $staging_url = '')
{
	if (!is_array($data)) {
		return false;
	}

	foreach ($data as $key => $value) {
		// Check if this is an image object with matching ID
		if (is_array($value)) {
			if (isset($value['id']) && intval($value['id']) === intval($attachment_id)) {
				if (isset($value['url']) && !empty($value['url'])) {
					return $value['url'];
				}
			}

			// Recursively search nested arrays
			$found = stls_elementor_find_image_url_in_data($value, $attachment_id, $staging_url);
			if ($found) {
				return $found;
			}
		} elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
			// Check if URL contains the attachment ID
			if (
				strpos($value, (string) $attachment_id) !== false &&
				(strpos($value, 'wp-content/uploads') !== false ||
					strpos($value, '/wp-json/wp/v2/media/') !== false)
			) {
				return $value;
			}
		}
	}

	return false;
}

/**
 * Get Elementor sync logs
 * 
 * @param int $limit Number of log entries to return (default: 50)
 * @return array Array of log entries
 */
function stls_elementor_get_logs($limit = 50)
{
	$log_key = 'stls_elementor_logs';
	$logs = get_transient($log_key);

	if (!is_array($logs)) {
		return array();
	}

	// Return most recent entries
	return array_slice(array_reverse($logs), 0, $limit);
}

/**
 * Clear Elementor sync logs
 */
function stls_elementor_clear_logs()
{
	delete_transient('stls_elementor_logs');
}

/**
 * Export Elementor sync logs as JSON
 * 
 * @return string JSON encoded log data
 */
function stls_elementor_export_logs()
{
	$logs = stls_elementor_get_logs(1000);
	return wp_json_encode($logs, JSON_PRETTY_PRINT);
}

/**
 * Robustly save meta data using direct SQL to avoid truncation and handle large packets
 * 
 * @param int    $post_id    Post ID
 * @param string $meta_key   Meta key
 * @param string $meta_value Meta value
 * @return bool True on success, false on failure
 */
function stls_elementor_save_meta_direct($post_id, $meta_key, $meta_value)
{
	global $wpdb;

	// Attempt to increase max_allowed_packet for this session (16MB)
	// This helps with very large Elementor JSON blobs
	$wpdb->query("SET SESSION max_allowed_packet=16777216");

	// CRITICAL: ALWAYS delete ALL existing entries FIRST to ensure complete replacement on every sync
	// This ensures that each sync completely replaces old data, not just updates it
	// This is essential for subsequent syncs to work correctly
	$deleted_count = $wpdb->delete(
		$wpdb->postmeta,
		array('post_id' => $post_id, 'meta_key' => $meta_key),
		array('%d', '%s')
	);

	// Clear caches BEFORE insert to ensure fresh data
	clean_post_cache($post_id);
	wp_cache_delete($post_id, 'post_meta');
	wp_cache_delete($post_id . '_' . $meta_key, 'post_meta');
	wp_cache_flush_group('post_meta');

	// Now insert fresh data using direct SQL to avoid any truncation issues
	// Use manual escaping instead of $wpdb->prepare() to avoid potential truncation
	$dbh = $wpdb->dbh;
	$escaped_post_id = intval($post_id);
	$escaped_meta_key = addslashes($meta_key);

	// Escape the value based on database type
	// MUST use maybe_serialize() to ensure arrays/objects are stored in WP format
	$value_for_sql = maybe_serialize($meta_value);

	if (is_object($dbh) && $dbh instanceof mysqli) {
		$escaped_meta_value = mysqli_real_escape_string($dbh, $value_for_sql);
	} elseif (function_exists('mysql_real_escape_string') && is_resource($dbh)) {
		$escaped_meta_value = mysql_real_escape_string($value_for_sql, $dbh);
	} else {
		$escaped_meta_value = addslashes($value_for_sql);
	}

	// Insert using direct SQL query
	$query = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ({$escaped_post_id}, '{$escaped_meta_key}', '{$escaped_meta_value}')";
	$result = $wpdb->query($query);

	$success = ($result !== false && $result > 0);
	$action = 'insert';

	// CRITICAL: Clear ALL caches immediately after save to ensure fresh data on subsequent syncs
	clean_post_cache($post_id);
	wp_cache_delete($post_id, 'post_meta');
	wp_cache_delete($post_id . '_' . $meta_key, 'post_meta');
	wp_cache_flush_group('post_meta');

	// Also clear Elementor-specific caches if it's an Elementor key
	if (strpos($meta_key, '_elementor') === 0 && class_exists('\Elementor\Plugin')) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		\Elementor\Plugin::$instance->posts_css_manager->clear_cache();
	}

	// Verify the save immediately
	if ($success) {
		// Use direct SQL query to avoid truncation when reading back
		$escaped_post_id_verify = intval($post_id);
		$escaped_meta_key_verify = addslashes($meta_key);
		$saved_length = $wpdb->get_var(
			"SELECT LENGTH(meta_value) FROM {$wpdb->postmeta} WHERE post_id = {$escaped_post_id_verify} AND meta_key = '{$escaped_meta_key_verify}' ORDER BY meta_id DESC LIMIT 1"
		);

		$expected_length = strlen($value_for_sql);
		if ($saved_length != $expected_length) {
			stls_elementor_log("CRITICAL: SQL $action verification failed - Length mismatch!", 'error', array(
				'post_id' => $post_id,
				'meta_key' => $meta_key,
				'expected_length' => $expected_length,
				'actual_length' => $saved_length,
				'wpdb_last_error' => $wpdb->last_error
			));
			return false;
		}
	} else {
		stls_elementor_log("CRITICAL: SQL $action failed for $meta_key", 'error', array(
			'post_id' => $post_id,
			'wpdb_last_error' => $wpdb->last_error,
			'data_length' => strlen($meta_value)
		));
	}

	return $success;
}


/**
 * Hook into the sync process to add Elementor data before sending to live site
 */
add_filter('stls_post_data_before_sync', 'stls_elementor_add_to_sync_data', 10, 2);

/**
 * Hook into the sync request handler to save Elementor data on live site
 */
add_action('stls_after_sync_post_meta', 'stls_elementor_save_on_live', 10, 2);

/**
 * Hook to ensure Elementor recognizes posts in admin (runs when admin pages load)
 * This fixes posts that were synced but Elementor doesn't recognize them
 */
add_action('admin_init', 'stls_elementor_ensure_recognition_in_admin', 20);

/**
 * Add AJAX endpoint to get logs
 */
add_action('wp_ajax_stls_get_elementor_logs', function () {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => 'Unauthorized'));
	}

	$logs = stls_elementor_get_logs(200);
	wp_send_json_success(array('logs' => $logs, 'count' => count($logs)));
});

/**
 * Add AJAX endpoint to clear logs
 */
add_action('wp_ajax_stls_clear_elementor_logs', function () {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => 'Unauthorized'));
	}

	stls_elementor_clear_logs();
	wp_send_json_success(array('message' => 'Logs cleared'));
});

/**
 * Add AJAX endpoint to export logs
 */
add_action('wp_ajax_stls_export_elementor_logs', function () {
	if (!current_user_can('manage_options')) {
		wp_die('Unauthorized');
	}

	$logs_json = stls_elementor_export_logs();

	header('Content-Type: application/json');
	header('Content-Disposition: attachment; filename="elementor-sync-logs-' . date('Y-m-d-H-i-s') . '.json"');
	echo $logs_json;
	exit;
});
