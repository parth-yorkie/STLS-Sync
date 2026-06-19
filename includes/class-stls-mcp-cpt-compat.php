<?php
/**
 * MCP compatibility tools for custom post types (rest_base-based API).
 *
 * Registers tool names expected by Cursor/Claude clients without modifying wordpress-mcp.
 *
 * @package Staging_To_Live_Sync
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * STLS MCP CPT compatibility layer.
 */
class STLS_Mcp_Cpt_Compat {

	/**
	 * Register compatibility tools on wordpress_mcp_init.
	 */
	public static function register_tools()
	{
		if (!stls_mcp_can_register_tools()) {
			return;
		}

		if (self::mcp_tool_exists('wp_create_cpt_item')) {
			return;
		}

		self::register_tool(
			array(
				'name'                => 'wp_get_post_types',
				'description'         => 'List registered post types with REST access. Returns rest_base values required by CPT tools (e.g. blog). Call this before wp_create_cpt_item.',
				'type'                => 'read',
				'inputSchema'         => array('type' => 'object'),
				'callback'            => array(__CLASS__, 'get_post_types'),
				'permission_callback' => array(__CLASS__, 'can_edit_posts'),
				'annotations'         => array(
					'title'         => 'Get Post Types',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		self::register_tool(
			array(
				'name'                => 'wp_list_cpt_items',
				'description'         => 'List items for a custom post type using its REST base (e.g. rest_base: blog).',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'rest_base' => array('type' => 'string'),
						'status'    => array('type' => 'string'),
						'per_page'  => array('type' => 'integer'),
						'page'      => array('type' => 'integer'),
						'search'    => array('type' => 'string'),
						'orderby'   => array('type' => 'string'),
						'order'     => array('type' => 'string'),
					),
					'required'   => array('rest_base'),
				),
				'callback'            => array(__CLASS__, 'list_cpt_items'),
				'permission_callback' => array(__CLASS__, 'can_edit_posts'),
				'annotations'         => array(
					'title'         => 'List CPT Items',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		self::register_tool(
			array(
				'name'                => 'wp_get_cpt_item',
				'description'         => 'Get one custom post type item by REST base and item ID.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'rest_base' => array('type' => 'string'),
						'item_id'   => array('type' => 'integer'),
					),
					'required'   => array('rest_base', 'item_id'),
				),
				'callback'            => array(__CLASS__, 'get_cpt_item'),
				'permission_callback' => array(__CLASS__, 'can_edit_posts'),
				'annotations'         => array(
					'title'         => 'Get CPT Item',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		self::register_tool(
			array(
				'name'                => 'wp_create_cpt_item',
				'description'         => 'Create a new item in a custom post type using its REST base. Example: rest_base "blog" for Blog posts. Then use stls_sync_to_live to push to live.',
				'type'                => 'create',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'rest_base' => array('type' => 'string'),
						'title'     => array('type' => 'string'),
						'content'   => array('type' => 'string'),
						'status'    => array('type' => 'string'),
						'excerpt'   => array('type' => 'string'),
						'slug'      => array('type' => 'string'),
					),
					'required'   => array('rest_base', 'title'),
				),
				'callback'            => array(__CLASS__, 'create_cpt_item'),
				'permission_callback' => array(__CLASS__, 'can_edit_posts'),
				'annotations'         => array(
					'title'           => 'Create CPT Item',
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => false,
					'openWorldHint'   => false,
				),
			)
		);

		self::register_tool(
			array(
				'name'                => 'wp_update_cpt_item',
				'description'         => 'Update an existing custom post type item by REST base and item ID.',
				'type'                => 'update',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'rest_base' => array('type' => 'string'),
						'item_id'   => array('type' => 'integer'),
						'title'     => array('type' => 'string'),
						'content'   => array('type' => 'string'),
						'status'    => array('type' => 'string'),
						'excerpt'   => array('type' => 'string'),
						'slug'      => array('type' => 'string'),
					),
					'required'   => array('rest_base', 'item_id'),
				),
				'callback'            => array(__CLASS__, 'update_cpt_item'),
				'permission_callback' => array(__CLASS__, 'can_edit_posts'),
				'annotations'         => array(
					'title'           => 'Update CPT Item',
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			)
		);
	}

	/**
	 * Register a tool with WordPress MCP when available.
	 *
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
	public static function can_edit_posts()
	{
		if (!stls_mcp_can_register_tools()) {
			return false;
		}

		return current_user_can('edit_posts');
	}

	/**
	 * @param string $tool_name Tool name.
	 * @return bool
	 */
	private static function mcp_tool_exists($tool_name)
	{
		if (!stls_is_wordpress_mcp_active() || !class_exists('Automattic\WordpressMcp\Core\WpMcp')) {
			return false;
		}

		try {
			$wpmcp = \Automattic\WordpressMcp\Core\WpMcp::instance();
		} catch (\Throwable $e) {
			return false;
		}

		if (!method_exists($wpmcp, 'get_tools')) {
			return false;
		}

		foreach ($wpmcp->get_tools() as $tool) {
			if (isset($tool['name']) && $tool['name'] === $tool_name) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $rest_base REST base slug.
	 * @return string|null
	 */
	private static function resolve_post_type_from_rest_base($rest_base)
	{
		$post_types = get_post_types(array('show_in_rest' => true), 'objects');

		foreach ($post_types as $post_type) {
			$base = !empty($post_type->rest_base) ? (string) $post_type->rest_base : $post_type->name;
			if ($base === $rest_base || $post_type->name === $rest_base) {
				return $post_type->name;
			}
		}

		return null;
	}

	/**
	 * @param string $method    HTTP method.
	 * @param string $rest_base REST base slug.
	 * @param array  $params    Query/body params.
	 * @param int    $item_id   Optional item ID.
	 * @return array
	 */
	private static function rest_request_for_cpt($method, $rest_base, $params = array(), $item_id = 0)
	{
		if (!self::resolve_post_type_from_rest_base($rest_base)) {
			return array(
				'error' => array(
					'code'    => 'rest_no_route',
					'message' => sprintf(
						'No REST-enabled post type found for rest_base "%s". Call wp_get_post_types first.',
						$rest_base
					),
				),
			);
		}

		$route = '/wp/v2/' . $rest_base;
		if ($item_id > 0) {
			$route .= '/' . $item_id;
		}

		$request = new WP_REST_Request($method, $route);

		if (in_array($method, array('GET', 'DELETE'), true)) {
			$request->set_query_params($params);
		} else {
			$request->set_body_params($params);
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			$error = $response->as_error();
			return array(
				'error' => array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
					'data'    => $error->get_error_data(),
				),
			);
		}

		return $response->get_data();
	}

	/**
	 * @return array
	 */
	public static function get_post_types()
	{
		$post_types = get_post_types(array('show_in_rest' => true), 'objects');
		$results    = array();

		foreach ($post_types as $post_type) {
			$results[] = array(
				'slug'         => $post_type->name,
				'name'         => $post_type->labels->name,
				'description'  => $post_type->description,
				'rest_base'    => !empty($post_type->rest_base) ? (string) $post_type->rest_base : $post_type->name,
				'hierarchical' => (bool) $post_type->hierarchical,
			);
		}

		return array('post_types' => $results);
	}

	/**
	 * @param array $params Request params.
	 * @return array
	 */
	public static function list_cpt_items($params)
	{
		$params    = is_array($params) ? $params : array();
		$rest_base = sanitize_text_field((string) ($params['rest_base'] ?? ''));
		$query     = array(
			'per_page' => isset($params['per_page']) ? max(1, min(100, (int) $params['per_page'])) : 10,
			'page'     => isset($params['page']) ? max(1, (int) $params['page']) : 1,
			'status'   => !empty($params['status']) ? sanitize_text_field((string) $params['status']) : 'publish',
			'orderby'  => !empty($params['orderby']) ? sanitize_text_field((string) $params['orderby']) : 'date',
			'order'    => !empty($params['order']) ? sanitize_text_field((string) $params['order']) : 'desc',
		);

		if (!empty($params['search'])) {
			$query['search'] = sanitize_text_field((string) $params['search']);
		}

		return self::rest_request_for_cpt('GET', $rest_base, $query);
	}

	/**
	 * @param array $params Request params.
	 * @return array
	 */
	public static function get_cpt_item($params)
	{
		$params    = is_array($params) ? $params : array();
		$rest_base = sanitize_text_field((string) ($params['rest_base'] ?? ''));
		$item_id   = (int) ($params['item_id'] ?? 0);

		if ($item_id <= 0) {
			return array(
				'error' => array(
					'code'    => 'invalid_item_id',
					'message' => 'item_id is required.',
				),
			);
		}

		return self::rest_request_for_cpt('GET', $rest_base, array(), $item_id);
	}

	/**
	 * @param array $params Request params.
	 * @return array
	 */
	public static function create_cpt_item($params)
	{
		$params    = is_array($params) ? $params : array();
		$rest_base = sanitize_text_field((string) ($params['rest_base'] ?? ''));
		$body      = array(
			'title'  => sanitize_text_field((string) ($params['title'] ?? '')),
			'status' => !empty($params['status']) ? sanitize_text_field((string) $params['status']) : 'draft',
		);

		if (isset($params['content'])) {
			$body['content'] = wp_kses_post((string) $params['content']);
		}
		if (!empty($params['excerpt'])) {
			$body['excerpt'] = sanitize_textarea_field((string) $params['excerpt']);
		}
		if (!empty($params['slug'])) {
			$body['slug'] = sanitize_title((string) $params['slug']);
		}

		return self::rest_request_for_cpt('POST', $rest_base, $body);
	}

	/**
	 * @param array $params Request params.
	 * @return array
	 */
	public static function update_cpt_item($params)
	{
		$params    = is_array($params) ? $params : array();
		$rest_base = sanitize_text_field((string) ($params['rest_base'] ?? ''));
		$item_id   = (int) ($params['item_id'] ?? 0);

		if ($item_id <= 0) {
			return array(
				'error' => array(
					'code'    => 'invalid_item_id',
					'message' => 'item_id is required.',
				),
			);
		}

		$body = array();
		if (!empty($params['title'])) {
			$body['title'] = sanitize_text_field((string) $params['title']);
		}
		if (isset($params['content'])) {
			$body['content'] = wp_kses_post((string) $params['content']);
		}
		if (!empty($params['excerpt'])) {
			$body['excerpt'] = sanitize_textarea_field((string) $params['excerpt']);
		}
		if (!empty($params['status'])) {
			$body['status'] = sanitize_text_field((string) $params['status']);
		}
		if (!empty($params['slug'])) {
			$body['slug'] = sanitize_title((string) $params['slug']);
		}

		return self::rest_request_for_cpt('POST', $rest_base, $body, $item_id);
	}

	/**
	 * Command catalog for STLS MCP admin page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_command_catalog()
	{
		return array(
			array(
				'name'        => 'wp_get_post_types',
				'title'       => __('Get Post Types', 'staging-to-live-sync'),
				'description' => __('Discover REST-enabled post types and rest_base values (e.g. blog).', 'staging-to-live-sync'),
				'type'        => __('Read', 'staging-to-live-sync'),
				'parameters'  => array(),
				'rest_route'  => __('STLS MCP compatibility tool', 'staging-to-live-sync'),
			),
			array(
				'name'        => 'wp_create_cpt_item',
				'title'       => __('Create CPT Item', 'staging-to-live-sync'),
				'description' => __('Create a blog or other CPT item on staging using rest_base.', 'staging-to-live-sync'),
				'type'        => __('Create', 'staging-to-live-sync'),
				'parameters'  => array(
					array('name' => 'rest_base', 'type' => 'string', 'required' => true, 'description' => __('REST base, e.g. blog', 'staging-to-live-sync')),
					array('name' => 'title', 'type' => 'string', 'required' => true, 'description' => __('Post title', 'staging-to-live-sync')),
					array('name' => 'content', 'type' => 'string', 'required' => false, 'description' => __('Post content', 'staging-to-live-sync')),
					array('name' => 'status', 'type' => 'string', 'required' => false, 'description' => __('draft, publish, etc.', 'staging-to-live-sync')),
				),
				'rest_route'  => __('STLS MCP compatibility tool', 'staging-to-live-sync'),
			),
			array(
				'name'        => 'wp_list_cpt_items',
				'title'       => __('List CPT Items', 'staging-to-live-sync'),
				'description' => __('List items for a CPT by rest_base.', 'staging-to-live-sync'),
				'type'        => __('Read', 'staging-to-live-sync'),
				'parameters'  => array(
					array('name' => 'rest_base', 'type' => 'string', 'required' => true, 'description' => __('REST base slug', 'staging-to-live-sync')),
				),
				'rest_route'  => __('STLS MCP compatibility tool', 'staging-to-live-sync'),
			),
			array(
				'name'        => 'wp_get_cpt_item',
				'title'       => __('Get CPT Item', 'staging-to-live-sync'),
				'description' => __('Get one CPT item by rest_base and item_id.', 'staging-to-live-sync'),
				'type'        => __('Read', 'staging-to-live-sync'),
				'parameters'  => array(
					array('name' => 'rest_base', 'type' => 'string', 'required' => true, 'description' => __('REST base slug', 'staging-to-live-sync')),
					array('name' => 'item_id', 'type' => 'integer', 'required' => true, 'description' => __('Post ID', 'staging-to-live-sync')),
				),
				'rest_route'  => __('STLS MCP compatibility tool', 'staging-to-live-sync'),
			),
			array(
				'name'        => 'wp_update_cpt_item',
				'title'       => __('Update CPT Item', 'staging-to-live-sync'),
				'description' => __('Update a CPT item by rest_base and item_id.', 'staging-to-live-sync'),
				'type'        => __('Update', 'staging-to-live-sync'),
				'parameters'  => array(
					array('name' => 'rest_base', 'type' => 'string', 'required' => true, 'description' => __('REST base slug', 'staging-to-live-sync')),
					array('name' => 'item_id', 'type' => 'integer', 'required' => true, 'description' => __('Post ID', 'staging-to-live-sync')),
				),
				'rest_route'  => __('STLS MCP compatibility tool', 'staging-to-live-sync'),
			),
		);
	}
}
