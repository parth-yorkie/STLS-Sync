<?php
/**
 * SFTP fallback for theme/plugin file deploy on managed hosts (e.g. WP Engine).
 *
 * @package Staging_To_Live_Sync
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Deploy files via SFTP when PHP cannot write to theme/plugin directories.
 */
class STLS_Sftp {

	/**
	 * @return bool
	 */
	public static function is_enabled()
	{
		return (bool) get_option('stls_sftp_enabled', false) && self::is_configured();
	}

	/**
	 * @return bool
	 */
	public static function is_configured()
	{
		$settings = self::get_settings(false);

		return !empty($settings['host']) && !empty($settings['username']) && $settings['password'] !== '';
	}

	/**
	 * @param bool $require_password Require a stored password.
	 * @return array{host:string,port:int,username:string,password:string}
	 */
	public static function get_settings($require_password = true)
	{
		return array(
			'host'     => trim(STLS_Credentials_Crypto::get_option('stls_sftp_host', '')),
			'port'     => max(1, (int) get_option('stls_sftp_port', 2222)),
			'username' => trim(STLS_Credentials_Crypto::get_option('stls_sftp_username', '')),
			'password' => STLS_Credentials_Crypto::get_option('stls_sftp_password', ''),
		);
	}

	/**
	 * @param mixed $value Submitted password.
	 * @return string Encrypted password for storage.
	 */
	public static function sanitize_password($value)
	{
		$value = (string) $value;
		if ($value === '') {
			return (string) get_option('stls_sftp_password', '');
		}

		return STLS_Credentials_Crypto::encrypt($value);
	}

	/**
	 * Build remote path relative to the WordPress install root.
	 *
	 * @param string $file_path Path relative to wp-content or absolute.
	 * @return string
	 */
	public static function remote_path_for_wp_content_file($file_path)
	{
		$file_path = ltrim(str_replace('\\', '/', (string) $file_path), '/');

		if (strpos($file_path, 'wp-content/') === 0) {
			return $file_path;
		}

		return 'wp-content/' . $file_path;
	}

	/**
	 * Deploy a wp-content-relative file via SFTP.
	 *
	 * @param string $file_path Path relative to wp-content.
	 * @param string $contents File contents.
	 * @return array
	 */
	public static function deploy_wp_content_file($file_path, $contents)
	{
		if (!self::is_enabled()) {
			return array(
				'success' => false,
				'message' => __('SFTP fallback is not configured.', 'staging-to-live-sync'),
			);
		}

		$remote_path = self::remote_path_for_wp_content_file($file_path);

		return self::upload_contents($remote_path, $contents);
	}

	/**
	 * Upload a local temp file to an absolute target path on the server.
	 *
	 * @param string $local_path         Local absolute path.
	 * @param string $target_absolute_path Target absolute path.
	 * @return array
	 */
	public static function upload_local_file($local_path, $target_absolute_path)
	{
		if (!self::is_enabled()) {
			return array(
				'success' => false,
				'message' => __('SFTP fallback is not configured.', 'staging-to-live-sync'),
			);
		}

		if (!file_exists($local_path) || !is_readable($local_path)) {
			return array(
				'success' => false,
				'message' => __('Temporary file is missing or unreadable.', 'staging-to-live-sync'),
			);
		}

		$contents = file_get_contents($local_path);
		if ($contents === false) {
			return array(
				'success' => false,
				'message' => __('Could not read temporary file for SFTP upload.', 'staging-to-live-sync'),
			);
		}

		$remote_path = self::remote_path_for_absolute_path($target_absolute_path);

		return self::upload_contents($remote_path, $contents);
	}

	/**
	 * @param string $absolute_path Absolute filesystem path.
	 * @return string
	 */
	public static function remote_path_for_absolute_path($absolute_path)
	{
		$absolute_path = wp_normalize_path($absolute_path);
		$root = wp_normalize_path(ABSPATH);

		if (strpos($absolute_path, $root) === 0) {
			return ltrim(substr($absolute_path, strlen($root)), '/');
		}

		$content_dir = wp_normalize_path(WP_CONTENT_DIR);
		if (strpos($absolute_path, $content_dir) === 0) {
			return 'wp-content/' . ltrim(substr($absolute_path, strlen($content_dir)), '/');
		}

		return ltrim(str_replace($root, '', $absolute_path), '/');
	}

	/**
	 * @param string $remote_path Remote path from WP root.
	 * @param string $contents    File contents.
	 * @return array
	 */
	public static function upload_contents($remote_path, $contents)
	{
		$settings = self::get_settings();
		$remote_path = ltrim(str_replace('\\', '/', $remote_path), '/');
		$errors = array();

		if (function_exists('ssh2_connect')) {
			$result = self::upload_via_ssh2($settings, $remote_path, $contents);
			if (!empty($result['success'])) {
				return $result;
			}
			$errors[] = isset($result['message']) ? $result['message'] : 'ssh2 failed';
		}

		if (function_exists('curl_init')) {
			$result = self::upload_via_curl($settings, $remote_path, $contents);
			if (!empty($result['success'])) {
				return $result;
			}
			$errors[] = isset($result['message']) ? $result['message'] : 'curl failed';
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: %s: comma-separated error messages */
				__('SFTP upload failed. %s Install/enable the PHP ssh2 extension or ensure cURL supports SFTP.', 'staging-to-live-sync'),
				implode(' ', $errors)
			),
		);
	}

	/**
	 * @param array  $settings    Connection settings.
	 * @param string $remote_path Remote path.
	 * @param string $contents    File contents.
	 * @return array
	 */
	private static function upload_via_ssh2(array $settings, $remote_path, $contents)
	{
		$connection = @ssh2_connect($settings['host'], $settings['port']);
		if (!$connection) {
			return array(
				'success' => false,
				'message' => __('SSH2 connect failed.', 'staging-to-live-sync'),
			);
		}

		if (!@ssh2_auth_password($connection, $settings['username'], $settings['password'])) {
			return array(
				'success' => false,
				'message' => __('SSH2 authentication failed.', 'staging-to-live-sync'),
			);
		}

		$sftp = @ssh2_sftp($connection);
		if (!$sftp) {
			return array(
				'success' => false,
				'message' => __('SSH2 SFTP subsystem failed.', 'staging-to-live-sync'),
			);
		}

		if (!self::ensure_remote_directory_ssh2($sftp, dirname($remote_path))) {
			return array(
				'success' => false,
				'message' => __('Could not create remote directory over SFTP.', 'staging-to-live-sync'),
			);
		}

		$remote_file = 'ssh2.sftp://' . intval($sftp) . '/' . $remote_path;
		$written = @file_put_contents($remote_file, $contents);
		if ($written === false) {
			return array(
				'success' => false,
				'message' => __('SSH2 SFTP write failed.', 'staging-to-live-sync'),
			);
		}

		return array(
			'success' => true,
			'method'  => 'ssh2_sftp',
			'message' => __('File deployed via SFTP.', 'staging-to-live-sync'),
		);
	}

	/**
	 * @param resource $sftp      SFTP resource.
	 * @param string   $directory Remote directory path.
	 * @return bool
	 */
	private static function ensure_remote_directory_ssh2($sftp, $directory)
	{
		$directory = trim(str_replace('\\', '/', $directory), '/');
		if ($directory === '' || $directory === '.') {
			return true;
		}

		$parts = explode('/', $directory);
		$current = '';
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			$current = ($current === '') ? $part : $current . '/' . $part;
			$path = 'ssh2.sftp://' . intval($sftp) . '/' . $current;
			if (!@file_exists($path)) {
				if (!@ssh2_sftp_mkdir($sftp, $current, 0755, true)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @param array  $settings    Connection settings.
	 * @param string $remote_path Remote path.
	 * @param string $contents    File contents.
	 * @return array
	 */
	private static function upload_via_curl(array $settings, $remote_path, $contents)
	{
		$temp_file = wp_tempnam('stls-sftp');
		if (!$temp_file) {
			return array(
				'success' => false,
				'message' => __('Could not create local temp file for SFTP upload.', 'staging-to-live-sync'),
			);
		}

		if (@file_put_contents($temp_file, $contents) === false) {
			@unlink($temp_file);
			return array(
				'success' => false,
				'message' => __('Could not write local temp file for SFTP upload.', 'staging-to-live-sync'),
			);
		}

		$fp = @fopen($temp_file, 'rb');
		if (false === $fp) {
			@unlink($temp_file);
			return array(
				'success' => false,
				'message' => __('Could not open local temp file for SFTP upload.', 'staging-to-live-sync'),
			);
		}

		$url = 'sftp://' . $settings['host'] . '/' . $remote_path;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_PORT, $settings['port']);
		curl_setopt($ch, CURLOPT_USERPWD, $settings['username'] . ':' . $settings['password']);
		curl_setopt($ch, CURLOPT_UPLOAD, true);
		curl_setopt($ch, CURLOPT_INFILE, $fp);
		curl_setopt($ch, CURLOPT_INFILESIZE, strlen($contents));
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		if (defined('CURLPROTO_SFTP')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);
		}
		if (defined('CURLSSH_AUTH_PASSWORD')) {
			curl_setopt($ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
		}

		$result = curl_exec($ch);
		$error = curl_error($ch);
		$code = curl_errno($ch);
		curl_close($ch);
		fclose($fp);
		@unlink($temp_file);

		if ($result === false) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: curl error code, 2: curl error message */
					__('cURL SFTP upload failed (%1$d: %2$s).', 'staging-to-live-sync'),
					$code,
					$error
				),
			);
		}

		return array(
			'success' => true,
			'method'  => 'curl_sftp',
			'message' => __('File deployed via SFTP.', 'staging-to-live-sync'),
		);
	}

	/**
	 * Test SFTP credentials.
	 *
	 * @return array
	 */
	public static function test_connection()
	{
		if (!self::is_configured()) {
			return array(
				'success' => false,
				'message' => __('Enter SFTP host, username, and password first.', 'staging-to-live-sync'),
			);
		}

		$probe_path = 'wp-content/uploads/stls-sftp-test-' . wp_generate_password(8, false, false) . '.txt';
		$result = self::upload_contents($probe_path, 'stls-sftp-test');

		if (empty($result['success'])) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: remote probe file path */
				__('SFTP connection succeeded. Test file uploaded to %s — you may delete it.', 'staging-to-live-sync'),
				$probe_path
			),
			'method' => isset($result['method']) ? $result['method'] : '',
		);
	}

	/**
	 * Attempt to apply all queued temp files via SFTP.
	 *
	 * @return array
	 */
	public static function apply_sync_queue()
	{
		if (!self::is_enabled()) {
			return array(
				'success' => false,
				'message' => __('SFTP fallback is not enabled.', 'staging-to-live-sync'),
			);
		}

		$queue = get_option('stls_file_sync_queue', array());
		if (!is_array($queue) || empty($queue)) {
			return array(
				'success' => true,
				'message' => __('No queued files to apply.', 'staging-to-live-sync'),
				'applied' => 0,
				'failed'  => 0,
			);
		}

		$applied = 0;
		$failed = 0;
		$messages = array();
		$remaining = array();

		foreach ($queue as $item) {
			$temp_file = isset($item['temp_file']) ? $item['temp_file'] : '';
			$target_file = isset($item['target_file']) ? $item['target_file'] : '';

			if ($temp_file === '' || $target_file === '') {
				$failed++;
				continue;
			}

			$result = self::upload_local_file($temp_file, $target_file);
			if (!empty($result['success'])) {
				@unlink($temp_file);
				$applied++;
				continue;
			}

			$failed++;
			$messages[] = basename($target_file) . ': ' . (isset($result['message']) ? $result['message'] : __('SFTP failed.', 'staging-to-live-sync'));
			$remaining[] = $item;
		}

		update_option('stls_file_sync_queue', $remaining);

		return array(
			'success' => ($failed === 0),
			'message' => sprintf(
				/* translators: 1: applied count, 2: failed count */
				__('Applied %1$d queued file(s) via SFTP. %2$d failed.', 'staging-to-live-sync'),
				$applied,
				$failed
			),
			'applied'  => $applied,
			'failed'   => $failed,
			'details'  => $messages,
		);
	}
}
