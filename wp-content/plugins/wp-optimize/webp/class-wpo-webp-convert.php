<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WPO_WebP_Convert')) :

/**
 * Handles WebP conversion by iterating through available converters.
 */
class WPO_WebP_Convert {

	/**
	 * @var array<string>
	 */
	private $converters = array();

	private function __construct() {
		$webp_converters = WP_Optimize()->get_options()->get_option('webp_converters');
		$this->converters = false === $webp_converters ? array() : $webp_converters;
	}

	/**
	 * Singleton instance
	 *
	 * @return WPO_WebP_Convert
	 */
	public static function get_instance(): self {
		static $instance = null;
		if (null === $instance) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Convert an image file to WebP format using the first available converter that succeeds.
	 *
	 * @param string $source Path of the source file.
	 * @return bool True if conversion succeeded, false otherwise.
	 */
	public function convert($source): bool {
		if (empty($this->converters)) {
			return false;
		}

		$destination = WPO_WebP_Utils::get_destination_path($source);

		return $this->attempt_conversion($source, $destination);
	}

	/**
	 * Try each configured converter in order until one produces a WebP file.
	 *
	 * @param string $source      Path of the source file.
	 * @param string $destination Path of the destination file.
	 * @return bool True if a converter produced the destination file.
	 */
	private function attempt_conversion($source, $destination): bool {
		foreach ($this->converters as $converter) {
			WPO_WebP_Utils::perform_webp_conversion($converter, $source, $destination);
			if (is_file($destination)) {
				return true;
			}
		}

		return false;
	}
}
endif;
