<?php
/**
 * STLS MCP admin page and helpers.
 *
 * @package Staging_To_Live_Sync
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Whether STLS MCP integration is enabled in plugin settings.
 *
 * @return bool
 */
function stls_is_mcp_enabled()
{
	return get_option('stls_mcp_enabled', '1') === '1';
}

/**
 * Whether the WordPress MCP plugin is active and its tool API is available.
 *
 * @return bool
 */
function stls_is_wordpress_mcp_active()
{
	return class_exists('Automattic\WordpressMcp\Core\RegisterMcpTool');
}

/**
 * Whether STLS should register MCP tools (both STLS setting and WordPress MCP required).
 *
 * @return bool
 */
function stls_mcp_can_register_tools()
{
	return stls_is_mcp_enabled() && stls_is_wordpress_mcp_active();
}

/**
 * STLS MCP command catalog for admin display and documentation.
 *
 * @return array<int, array<string, mixed>>
 */
function stls_get_mcp_commands()
{
	$commands = array(
		array(
			'name'        => 'stls_sync_config',
			'title'       => __('Sync and Live Configuration', 'staging-to-live-sync'),
			'description' => __('Check staging/live URLs, API key status, and whether this site can push to live. Use before syncing.', 'staging-to-live-sync'),
			'type'        => __('Read', 'staging-to-live-sync'),
			'parameters'  => array(),
			'rest_route'  => 'GET /wp-json/stls/v1/sync-config',
		),
		array(
			'name'        => 'stls_sync_to_live',
			'title'       => __('Sync to Live', 'staging-to-live-sync'),
			'description' => __('Push a post from staging to the live site. Syncs title, content, excerpt, custom fields, ACF, featured image, media blocks, taxonomies, and SEO.', 'staging-to-live-sync'),
			'type'        => __('Action', 'staging-to-live-sync'),
			'parameters'  => array(
				array(
					'name'        => 'post_id',
					'type'        => 'integer',
					'required'    => false,
					'description' => __('Staging post ID to sync (preferred).', 'staging-to-live-sync'),
				),
				array(
					'name'        => 'slug',
					'type'        => 'string',
					'required'    => false,
					'description' => __('Post slug when post_id is unknown.', 'staging-to-live-sync'),
				),
				array(
					'name'        => 'post_type',
					'type'        => 'string',
					'required'    => false,
					'description' => __('Post type when resolving by slug.', 'staging-to-live-sync'),
				),
			),
			'rest_route'  => 'POST /wp-json/stls/v1/sync-to-live',
		),
	);

	if (stls_mcp_can_register_tools() && class_exists('STLS_Mcp_Cpt_Compat')) {
		$commands = array_merge($commands, STLS_Mcp_Cpt_Compat::get_command_catalog());
	}

	return $commands;
}

/**
 * Admin page: STLS Sync → MCP.
 */
class STLS_Mcp_Admin {

	/**
	 * Register settings for the MCP page.
	 */
	public static function register_settings()
	{
		register_setting(
			'stls_mcp_settings',
			'stls_mcp_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array(__CLASS__, 'sanitize_mcp_enabled'),
				'default'           => '1',
			)
		);
	}

	/**
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_mcp_enabled($value)
	{
		return ($value === '1' || $value === 1 || $value === true) ? '1' : '0';
	}

	/**
	 * Render MCP settings and command list.
	 */
	public static function render_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$instance          = Staging_To_Live_Sync::get_instance();
		$is_live_site      = $instance->is_live_site();
		$is_staging_site   = $instance->is_staging_site();
		$mcp_plugin_active = stls_is_wordpress_mcp_active();
		$mcp_enabled       = stls_is_mcp_enabled();
		$mcp_ready         = stls_mcp_can_register_tools();
		$commands          = stls_get_mcp_commands();

		if (isset($_GET['settings-updated'])) {
			add_settings_error('stls_mcp_messages', 'stls_mcp_message', __('MCP settings saved.', 'staging-to-live-sync'), 'updated');
		}

		settings_errors('stls_mcp_messages');
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<?php if ($is_live_site) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e('This is your live site. WordPress MCP is not required here — the live site only receives synced content via the STLS REST receiver.', 'staging-to-live-sync'); ?>
					</p>
					<p>
						<?php esc_html_e('Install and enable the WordPress MCP plugin on your staging site, then connect your AI assistant to staging (not live) to use MCP sync commands.', 'staging-to-live-sync'); ?>
					</p>
				</div>
			<?php elseif (!$mcp_plugin_active) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e('The WordPress MCP plugin is not active. STLS MCP tools require it to be installed and enabled on this staging site.', 'staging-to-live-sync'); ?>
					</p>
				</div>
			<?php elseif (!$mcp_enabled) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e('STLS MCP sync is disabled. Enable it below to expose sync tools to AI assistants. Admin sync buttons are not affected.', 'staging-to-live-sync'); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if (!$is_live_site) : ?>
			<form method="post" action="options.php">
				<?php
				settings_fields('stls_mcp_settings');
				?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e('MCP Integration', 'staging-to-live-sync'); ?></th>
							<td>
								<input type="hidden" name="stls_mcp_enabled" value="0" />
								<label for="stls_mcp_enabled">
									<input type="checkbox" id="stls_mcp_enabled" name="stls_mcp_enabled" value="1"
										<?php checked($mcp_enabled); ?> />
									<?php esc_html_e('Enable MCP sync', 'staging-to-live-sync'); ?>
								</label>
								<p class="description">
									<?php esc_html_e('When disabled, MCP tools and REST sync endpoints for AI assistants are blocked. Admin sync buttons in the post editor are not affected.', 'staging-to-live-sync'); ?>
								</p>
								<p>
									<strong><?php esc_html_e('Status:', 'staging-to-live-sync'); ?></strong>
									<?php if ($mcp_enabled) : ?>
										<span style="color:#00a32a;"><?php esc_html_e('Enabled', 'staging-to-live-sync'); ?></span>
									<?php else : ?>
										<span style="color:#d63638;"><?php esc_html_e('Disabled', 'staging-to-live-sync'); ?></span>
									<?php endif; ?>
								</p>
								<?php if ($is_staging_site && $mcp_ready) : ?>
									<p class="description" style="color:#00a32a;">
										<?php esc_html_e('MCP sync is ready. Connect WordPress MCP in Cursor to this staging site.', 'staging-to-live-sync'); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(__('Save MCP Settings', 'staging-to-live-sync')); ?>
			</form>

			<hr />
			<?php endif; ?>

			<h2><?php esc_html_e('MCP Commands', 'staging-to-live-sync'); ?></h2>
			<p class="description">
				<?php if ($is_live_site) : ?>
					<?php esc_html_e('Reference only — these commands run on the staging site where WordPress MCP is connected.', 'staging-to-live-sync'); ?>
				<?php else : ?>
					<?php esc_html_e('These commands are available when STLS MCP is enabled and the WordPress MCP plugin is active on this staging site.', 'staging-to-live-sync'); ?>
				<?php endif; ?>
			</p>

			<table class="widefat striped" style="max-width: 1200px;">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('Command', 'staging-to-live-sync'); ?></th>
						<th scope="col"><?php esc_html_e('Tool Name', 'staging-to-live-sync'); ?></th>
						<th scope="col"><?php esc_html_e('Type', 'staging-to-live-sync'); ?></th>
						<th scope="col"><?php esc_html_e('REST Endpoint', 'staging-to-live-sync'); ?></th>
						<th scope="col"><?php esc_html_e('Description', 'staging-to-live-sync'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($commands as $command) : ?>
						<tr>
							<td><strong><?php echo esc_html($command['title']); ?></strong></td>
							<td><code><?php echo esc_html($command['name']); ?></code></td>
							<td><?php echo esc_html($command['type']); ?></td>
							<td><code><?php echo esc_html($command['rest_route']); ?></code></td>
							<td><?php echo esc_html($command['description']); ?></td>
						</tr>
						<?php if (!empty($command['parameters'])) : ?>
							<tr>
								<td colspan="5" style="padding-left: 2em; background: #f6f7f7;">
									<strong><?php esc_html_e('Parameters:', 'staging-to-live-sync'); ?></strong>
									<ul style="margin: 0.5em 0 0.5em 1.5em; list-style: disc;">
										<?php foreach ($command['parameters'] as $param) : ?>
											<li>
												<code><?php echo esc_html($param['name']); ?></code>
												(<?php echo esc_html($param['type']); ?><?php echo !empty($param['required']) ? ', ' . esc_html__('required', 'staging-to-live-sync') : ', ' . esc_html__('optional', 'staging-to-live-sync'); ?>)
												— <?php echo esc_html($param['description']); ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top: 1em;">
				<?php esc_html_e('Do not use POST /wp-json/stls/v1/sync on staging — that endpoint is the live-site receiver only.', 'staging-to-live-sync'); ?>
			</p>
		</div>
		<?php
	}
}
