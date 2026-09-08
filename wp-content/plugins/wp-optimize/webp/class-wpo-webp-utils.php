<?php

if (!defined('ABSPATH')) die('No direct access allowed');

use \WebPConvert\Convert\ConverterFactory;

require_once WPO_PLUGIN_MAIN_PATH . 'vendor/autoload.php';

if (!class_exists('WPO_WebP_Utils')) :

class WPO_WebP_Utils {

	/**
	 * Minimum Firefox version that supports WebP images.
	 *
	 * @see https://caniuse.com/webp
	 */
	const MIN_FIREFOX_VERSION_FOR_WEBP = '65.0.0';

	/**
	 * Warning message patterns to suppress during WebP conversion.
	 *
	 * @var array<string>
	 */
	private static $suppressed_warning_patterns = array(
		'/unlink\(.+\): No such file or directory/',
		'/rename\(.+\): No such file or directory/',
		'/filesize\(\): stat failed for/',
	);

	/**
	 * Determines whether we can do webp conversion or not.
	 *
	 * @return bool
	 */
	public static function can_do_webp_conversion(): bool {
		$options = WP_Optimize()->get_options();
		$webp_conversion = $options->get_option('webp_conversion');
		$webp_converters = $options->get_option('webp_converters');

		return $webp_conversion && !empty($webp_converters);
	}

	/**
	 * Convert given image file to webp format.
	 *
	 * @param string $source            Path of image file.
	 * @param bool   $convert_if_exists Whether to convert if the webp file already exists or not.
	 *
	 * @return void
	 */
	public static function do_webp_conversion($source, $convert_if_exists = false): void {
		if (file_exists(self::get_destination_path($source)) && !$convert_if_exists) {
			return;
		}

		$webp_converter = WPO_WebP_Convert::get_instance();
		$webp_converter->convert($source);
	}

	/**
	 * Returns the destination full path.
	 *
	 * @param string $source Path of the source file.
	 *
	 * @return string Path of destination file.
	 */
	public static function get_destination_path($source): string {
		return dirname($source) . '/' . basename($source) . '.webp';
	}

	/**
	 * Converts an image to WebP format using a specified converter.
	 *
	 * This method acts as a wrapper for the `WebPConvert\Convert\Converters\AbstractConverter::doConvert()` method
	 * from the `webp-convert` library.
	 *
	 * @param string $converter   The converter to be used for the conversion process.
	 * @param string $source      The path to the source image file.
	 * @param string $destination The path to the destination WebP image file.
	 *
	 * @return void
	 */
	public static function perform_webp_conversion($converter, $source, $destination): void {
		set_error_handler(array(__CLASS__, 'handle_webp_conversion_warnings'), E_WARNING); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- This is needed in order to suppress PHP warnings thrown by third party library

		try {
			$converter_instance = ConverterFactory::makeConverter(
				$converter,
				$source,
				$destination
			);

			$converter_instance->doConvert();

			restore_error_handler();
		} catch (Exception $e) {
			restore_error_handler(); // Make sure we restore even if an exception occurs

			$log_details = 'Converter: '.$converter. ', '.WP_Optimize_Utils::get_wp_relative_path($source).' => '.WP_Optimize_Utils::get_wp_relative_path($destination);
			WP_Optimize_WebP::get_instance()->log('Failed to convert to WebP: '. $log_details);
			throw new Exception(esc_html(__('Failed to convert to WebP.', 'wp-optimize')));
		}
	}

	/**
	 * Custom error handler for handling PHP warnings during the WebP conversion process.
	 *
	 * @param int    $errno  The level of the error raised.
	 * @param string $errstr The error message.
	 *
	 * @return bool
	 */
	public static function handle_webp_conversion_warnings($errno, $errstr): bool {
		foreach (self::$suppressed_warning_patterns as $pattern) {
			if (preg_match($pattern, $errstr)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decide whether the browser requesting the URL can accept webp images or not.
	 *
	 * @return bool
	 */
	public static function is_browser_accepting_webp(): bool {
		if (self::browser_accepts_webp_header()) {
			return true;
		}

		return self::user_agent_supports_webp();
	}

	/**
	 * Check if the HTTP Accept header indicates WebP support.
	 *
	 * @return bool
	 */
	private static function browser_accepts_webp_header(): bool {
		$http_accept = TeamUpdraft\WP_Optimize\Includes\Fragments\fetch_superglobal(
			'server',
			'HTTP_ACCEPT',
			'string',
			'sanitize_text_field',
			''
		);

		if (!is_string($http_accept)) {
			return false;
		}

		return false !== strpos($http_accept, 'image/webp');
	}

	/**
	 * Check if the user agent indicates WebP support based on known browser versions.
	 *
	 * @return bool
	 */
	private static function user_agent_supports_webp(): bool {
		$user_agent = TeamUpdraft\WP_Optimize\Includes\Fragments\fetch_superglobal(
			'server',
			'HTTP_USER_AGENT',
			'string',
			'sanitize_text_field',
			''
		);

		if (empty($user_agent) || !is_string($user_agent)) {
			return false;
		}

		if (preg_match('/Firefox\/([\d\.]+[a-z\d]*)/', $user_agent, $matches)) {
			if (version_compare(self::MIN_FIREFOX_VERSION_FOR_WEBP, $matches[1], '<=')) {
				return true;
			}
		}

		return false;
	}
}

endif;
