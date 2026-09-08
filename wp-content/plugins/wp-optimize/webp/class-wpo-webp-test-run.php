<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WPO_WebP_Test_Run')) :
/**
 * Handles WebP converter test runs to determine which converters are available on the server.
 */
class WPO_WebP_Test_Run {

	/**
	 * Filename of the test image used for conversion attempts.
	 *
	 * @var string
	 */
	const TEST_IMAGE_FILENAME = 'wpo_logo_small.png';

	/**
	 * List of working converters.
	 *
	 * @var array<string>
	 */
	private static $working_converters = array();

	/**
	 * Errors collected during converter tests.
	 *
	 * @var array<string>
	 */
	private static $errors = array();

	/**
	 * Get the full path to the source test image.
	 *
	 * @return string
	 */
	private static function get_source_path(): string {
		return WPO_PLUGIN_MAIN_PATH . 'images/logo/' . self::TEST_IMAGE_FILENAME;
	}

	/**
	 * Get the full path for the WebP test output in the uploads directory.
	 *
	 * @param string $base_dir The uploads base directory.
	 * @return string
	 */
	private static function get_destination_path($base_dir): string {
		return $base_dir . '/wpo/images/' . self::TEST_IMAGE_FILENAME . '.webp';
	}

	/**
	 * Get a list of converters that don't use shell functions.
	 *
	 * @return array<string>
	 */
	public static function get_converters_without_shell(): array {
		$converters_without_shell = array(
			'vips',
			'wpc',
			'ewww',
			'imagick',
			'gmagick',
			'gd',
		);
		$filtered_converters_without_shell = apply_filters('wpo_converters_without_shell', $converters_without_shell);
		$filtered_converters_without_shell = is_array($filtered_converters_without_shell) ? $filtered_converters_without_shell : $converters_without_shell;
		/** @var array<string> $filtered_converters_without_shell */
		return $filtered_converters_without_shell;
	}

	/**
	 * Get a list of converters that use shell functions.
	 *
	 * @return array<string>
	 */
	public static function get_converters_with_shell(): array {
		$converters_with_shell = array(
			'imagemagick',
			'graphicsmagick',
			'ffmpeg',
		);
		$filtered_converters_with_shell = apply_filters('wpo_converters_with_shell', $converters_with_shell);
		$filtered_converters_with_shell = is_array($filtered_converters_with_shell) ? $filtered_converters_with_shell : $converters_with_shell;

		/** @var array<string> $filtered_converters_with_shell */
		return $filtered_converters_with_shell;
	}

	/**
	 * Get an array of working and non-working converters.
	 *
	 * @return array<string, array<string>>
	 */
	public static function get_converter_status(): array {
		self::$working_converters = array();
		self::$errors = array();

		self::try_converters(self::get_converters_without_shell());

		if (empty(self::$working_converters)) {
			self::try_shell_converters(self::get_converters_with_shell());
		}

		return array(
			'working_converters' => self::$working_converters,
			'errors' => self::$errors,
		);
	}

	/**
	 * Try shell-based converters, logging an error for each if shell functions are unavailable.
	 *
	 * @param array<string> $converters List of shell-based converter IDs.
	 * @return void
	 */
	private static function try_shell_converters($converters): void {
		if (WP_Optimize_WebP::get_instance()->shell_functions_available()) {
			self::try_converters($converters);
			return;
		}

		foreach ($converters as $converter_id) {
			self::$errors[$converter_id] = __('Required WebP shell functions are not available on the server.', 'wp-optimize');
		}
	}

	/**
	 * Try each converter from the given list to convert the test image to WebP.
	 *
	 * @param array<string> $converters List of converter IDs to try.
	 * @return void
	 */
	private static function try_converters($converters): void {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];
		$source = self::get_source_path();
		$destination = self::get_destination_path($base_dir);

		foreach ($converters as $converter_id) {
			$result = self::try_converter($converter_id, $source, $destination);

			if (true === $result) {
				self::$working_converters[] = $converter_id;
				self::copy_test_image($source, $base_dir);
			} else {
				self::$errors[$converter_id] = $result;
			}
		}
	}

	/**
	 * Attempt a single WebP conversion and return the result.
	 *
	 * @param string $converter_id The converter identifier.
	 * @param string $source       Full path to the source image.
	 * @param string $destination  Full path for the WebP output.
	 * @return true|string True on success, or an error message string on failure.
	 */
	private static function try_converter($converter_id, $source, $destination) {
		try {
			WPO_WebP_Utils::perform_webp_conversion($converter_id, $source, $destination);
			return true;
		} catch (\Exception $e) {
			return $e->getMessage();
		}
	}

	/**
	 * Copy the test source image to the uploads folder for redirection testing.
	 *
	 * The /wpo/images/ directory is guaranteed to exist at this point because
	 * perform_webp_conversion() already created it during the conversion step.
	 *
	 * @param string $source   Full path to the source image.
	 * @param string $base_dir The uploads base directory.
	 * @return void
	 */
	private static function copy_test_image($source, $base_dir): void {
		copy($source, $base_dir . '/wpo/images/' . self::TEST_IMAGE_FILENAME);
	}
}

endif;
