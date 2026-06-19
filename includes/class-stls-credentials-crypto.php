<?php
/**
 * Encrypt/decrypt sensitive STLS option values at rest.
 *
 * @package Staging_To_Live_Sync
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * AES-256-CBC encryption for stored credentials.
 */
class STLS_Credentials_Crypto {

	const ENCRYPTED_PREFIX = 'stlsenc:v1:';

	/**
	 * @return string 32-byte encryption key.
	 */
	private static function get_key()
	{
		$seed = '';

		if (defined('AUTH_KEY') && AUTH_KEY !== '') {
			$seed .= AUTH_KEY;
		}

		if (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY !== '') {
			$seed .= SECURE_AUTH_KEY;
		}

		if ($seed === '') {
			$seed = wp_salt('auth');
		}

		return hash('sha256', $seed . '|stls-sftp-credentials', true);
	}

	/**
	 * @param string $plaintext Plain value.
	 * @return string
	 */
	public static function encrypt($plaintext)
	{
		$plaintext = (string) $plaintext;
		if ($plaintext === '') {
			return '';
		}

		if (!function_exists('openssl_encrypt')) {
			return self::ENCRYPTED_PREFIX . base64_encode($plaintext);
		}

		$iv_length = openssl_cipher_iv_length('AES-256-CBC');
		if (false === $iv_length) {
			return self::ENCRYPTED_PREFIX . base64_encode($plaintext);
		}

		$iv = function_exists('random_bytes') ? random_bytes($iv_length) : openssl_random_pseudo_bytes($iv_length);
		$ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', self::get_key(), OPENSSL_RAW_DATA, $iv);

		if ($ciphertext === false) {
			return self::ENCRYPTED_PREFIX . base64_encode($plaintext);
		}

		return self::ENCRYPTED_PREFIX . base64_encode($iv . $ciphertext);
	}

	/**
	 * @param string $stored Stored option value.
	 * @return string
	 */
	public static function decrypt($stored)
	{
		$stored = (string) $stored;
		if ($stored === '') {
			return '';
		}

		if (strpos($stored, self::ENCRYPTED_PREFIX) !== 0) {
			return $stored;
		}

		$payload = base64_decode(substr($stored, strlen(self::ENCRYPTED_PREFIX)), true);
		if ($payload === false || $payload === '') {
			return '';
		}

		if (!function_exists('openssl_decrypt')) {
			return $payload;
		}

		$iv_length = openssl_cipher_iv_length('AES-256-CBC');
		if (false === $iv_length || strlen($payload) <= $iv_length) {
			return $payload;
		}

		$iv = substr($payload, 0, $iv_length);
		$ciphertext = substr($payload, $iv_length);
		$plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', self::get_key(), OPENSSL_RAW_DATA, $iv);

		if ($plaintext === false) {
			return '';
		}

		return $plaintext;
	}

	/**
	 * @param mixed $value Submitted value.
	 * @return string Encrypted value for storage.
	 */
	public static function sanitize_field($value)
	{
		return self::encrypt(sanitize_text_field((string) $value));
	}

	/**
	 * @param string $option_name Option name.
	 * @param mixed  $default     Default value.
	 * @return string Decrypted value.
	 */
	public static function get_option($option_name, $default = '')
	{
		return self::decrypt((string) get_option($option_name, $default));
	}

	/**
	 * Re-encrypt legacy plain-text SFTP options once.
	 */
	public static function maybe_migrate_legacy_options()
	{
		$options = array(
			'stls_sftp_host',
			'stls_sftp_username',
			'stls_sftp_password',
		);

		foreach ($options as $option_name) {
			$stored = get_option($option_name, '');
			if ($stored === '' || strpos((string) $stored, self::ENCRYPTED_PREFIX) === 0) {
				continue;
			}

			update_option($option_name, self::encrypt((string) $stored), false);
		}
	}
}
