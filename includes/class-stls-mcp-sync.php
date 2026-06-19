<?php
/**
 * WordPress MCP tools: sync staging posts to the live site.
 *
 * @package Staging_To_Live_Sync
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Registers STLS MCP tools for AI assistants.
 */
class STLS_Mcp_Sync {

	/**
	 * Register MCP tools (call from wordpress_mcp_init only).
	 */
	public static function register_tools()
	{
		if (!stls_mcp_can_register_tools()) {
			return;
		}

		self::register_tool(
			array(
				'name'                => 'stls_sync_config',
				'description'         => 'Check Staging-to-Live Sync (STLS) configuration. Use before syncing. Returns staging/live URLs, API key status (length only), and whether this site can push to live. IMPORTANT: Do NOT use POST /stls/v1/sync on staging — that is the live-site receiver.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type' => 'object',
				),
				'callback'            => array(__CLASS__, 'get_sync_config'),
				'permission_callback' => array(__CLASS__, 'can_sync'),
				'annotations'         => array(
					'title'         => 'STLS Sync Config Check',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		self::register_tool(
			array(
				'name'                => 'stls_sync_to_live',
				'description'         => 'Push a post from this staging site to the live site. Use when the user says "sync to live". Syncs title, content, excerpt, custom fields, ACF, featured image, Gutenberg media blocks, taxonomies, and SEO. Images already in the staging Media Library are downloaded and attached on live automatically — do NOT upload images to live separately. Workflow: (1) upload images on staging via wp_upload_media or Media Library, (2) attach them in the post on staging, (3) call this tool. Pass post_id (preferred) or slug. NEVER call POST /stls/v1/sync on staging — that endpoint is on the live site for receiving data.',
				'type'                => 'action',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => 'Staging post ID to sync.',
						),
						'slug'      => array(
							'type'        => 'string',
							'description' => 'Post slug when post_id is unknown.',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type when resolving by slug.',
						),
					),
				),
				'callback'            => array(__CLASS__, 'sync_to_live'),
				'permission_callback' => array(__CLASS__, 'can_sync'),
				'annotations'         => array(
					'title'           => 'Sync Post to Live',
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			)
		);
	}

	/**
	 * @param array $args Tool arguments.
	 */
	private static function register_tool(array $args)
	{
		if (!stls_mcp_can_register_tools()) {
			return;
		}

		new \Automattic\WordpressMcp\Core\RegisterMcpTool($args);
	}

	/**
	 * @return bool
	 */
	public static function can_sync() {
		if (!stls_mcp_can_register_tools()) {
			return false;
		}

		return current_user_can('edit_posts');
	}

	/**
	 * @return array
	 */
	public static function get_sync_config() {
		if (!stls_mcp_can_register_tools()) {
			$error = !stls_is_mcp_enabled()
				? 'STLS MCP integration is disabled. Enable it under STLS Sync → MCP.'
				: 'WordPress MCP plugin is not active. Install and enable it on staging.';

			return array(
				'success' => false,
				'error'   => $error,
				'config'  => stls_get_sync_config_status(),
			);
		}

		return array(
			'success' => true,
			'config'  => stls_get_sync_config_status(),
		);
	}

	/**
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public static function sync_to_live($args) {
		if (!stls_mcp_can_register_tools()) {
			$error = !stls_is_mcp_enabled()
				? 'STLS MCP integration is disabled. Enable it under STLS Sync → MCP.'
				: 'WordPress MCP plugin is not active. Install and enable it on staging.';
			$code  = !stls_is_mcp_enabled() ? 'mcp_disabled' : 'wordpress_mcp_inactive';

			return array(
				'success' => false,
				'error'   => $error,
				'code'    => $code,
				'config'  => stls_get_sync_config_status(),
			);
		}

		$args = is_array($args) ? $args : array();

		$post_id   = isset($args['post_id']) ? (int) $args['post_id'] : 0;
		$slug      = isset($args['slug']) ? sanitize_title($args['slug']) : '';
		$post_type = isset($args['post_type']) ? sanitize_key($args['post_type']) : '';

		if ($post_id <= 0 && $slug === '') {
			return array(
				'success' => false,
				'error'   => 'Provide post_id or slug to sync.',
			);
		}

		$result = Staging_To_Live_Sync::get_instance()->sync_post($post_id, $slug, $post_type, 'mcp');

		if (is_wp_error($result)) {
			$response = array(
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			);

			if (in_array($result->get_error_code(), array('sync_failed', 'missing_api_key', 'invalid_api_key', 'api_key_not_configured', 'wrong_sync_endpoint'), true)) {
				$response['config'] = stls_get_sync_config_status();
			}

			return $response;
		}

		return array(
			'success' => true,
			'message' => $result['message'],
			'staging' => $result['staging'],
			'live'    => $result['live'],
			'synced'  => $result['synced'],
		);
	}
}
