<?php
if (!defined('ABSPATH')) {
	die('No direct access allowed');
}

/**
 * Adds compatibility for Page Builder plugins.
 */
class WPO_Page_Builder_Compatibility {

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->disable_webp_alter_html_in_edit_mode();
		
		add_filter('wpo_minify_file_modification_time', array($this, 'use_file_hash_for_divi_assets'), 10, 2);
	}

	/**
	 * Returns singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		static $instance = null;
		if (null === $instance) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Replaces the modification time of Divi assets with the file hash for WPO Minify
	 *
	 * @param string $modification_time The original modification time.
	 * @param string $file_path         The absolute path to the file.
	 * @return string
	 */
	public function use_file_hash_for_divi_assets($modification_time, $file_path) {
		if (false !== strpos($file_path, 'et-cache')) {
			$hash = hash_file('adler32', $file_path);
			if ($hash) {
				return $hash . '-h';
			}
		}

		return $modification_time;
	}

	/**
	 * Determines whether the page is in edit mode using page builders
	 * Beaver Builder, Divi Theme, Elementor, and Oxygen Builder
	 *
	 * @return bool
	 */
	public static function is_edit_mode(): bool {
		return isset($_GET['fl_builder']) || isset($_GET['et_fb']) || isset($_GET['ct_builder']) || isset($_GET['elementor-preview']) || isset($_GET['oxygen']); // phpcs:ignore WordPress.Security.NonceVerification -- We are not using any global values, only checking for existence
	}

	/**
	 * Disables altering HTML for WebP when current page is in edit mode.
	 */
	private function disable_webp_alter_html_in_edit_mode() {
		if (self::is_edit_mode()) {
			add_filter('wpo_disable_webp_alter_html', '__return_true');
		}
	}
}
