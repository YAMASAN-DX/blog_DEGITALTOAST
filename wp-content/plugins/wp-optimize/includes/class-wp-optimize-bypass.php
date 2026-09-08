<?php

if (!defined('ABSPATH')) die('Access denied.');

if (!class_exists('WP_Optimize_Bypass')) :

/**
 * WP_Optimize_Bypass class
 *
 * Handles bypass functionality to disable WP-Optimize optimizations
 * when the config constant WPO_DISABLE_MODE_URL_PARAM is present
 *
 * @since 4.4.0
 */
class WP_Optimize_Bypass {

	/**
	 * Singleton instance
	 *
	 * @var WP_Optimize_Bypass|null
	 */
	private static $instance = null;

	/**
	 * Get a singleton instance
	 *
	 * @return WP_Optimize_Bypass
	 */
	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if we should bypass WP-Optimize optimizations
	 *
	 * @return bool True to indicate should bypass; false otherwise.
	 */
	public function should_bypass() {
		$bypass = false;

		if (is_admin()) {
			return false;
		}

		// Check $_GET directly
		if ($this->is_bypass_mode_enabled()) {
			$bypass = true;
		}

		return $bypass;
	}

	/**
	 * Check if WP-Optimize bypass mode is enabled via secret URL parameter.
	 *
	 * @return bool
	 */
	private function is_bypass_mode_enabled() {
		// Feature is OFF unless explicitly enabled
		if (!defined('WPO_DISABLE_MODE_URL_PARAM') || empty(WPO_DISABLE_MODE_URL_PARAM)) {
			return false;
		}

		$param = WPO_DISABLE_MODE_URL_PARAM;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag, executes early
		return isset($_GET[$param]);
	}

	/**
	 * Show bypass admin notice
	 * Called early in the request
	 */
	public function show_admin_notice() {
		// Hook into early actions to set a bypass flag
		add_action('plugins_loaded', array($this, 'maybe_add_admin_notice'), 5);
	}

	/**
	 * Add admin bar notice when bypass is active
	 */
	public function maybe_add_admin_notice() {
		if (WP_Optimize()->current_user_can('manage_options')) {
			add_action('admin_bar_menu', array($this, 'add_bypass_notice_to_admin_bar'), 999);
		}
	}

	/**
	 * Add bypass notice to admin bar
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function add_bypass_notice_to_admin_bar($wp_admin_bar) {
		$wp_admin_bar->add_node(array(
			'id'    => 'wpo-bypass-notice',
			'title' => '<span style="color: #ff6600;">⚠ ' . esc_html__('WP-Optimize bypassed', 'wp-optimize') . '</span>',
			'href'  => false,
			'meta'  => array(
				'title' => __('WP-Optimize optimizations are currently bypassed via a custom URL parameter defined by WPO_DISABLE_MODE_URL_PARAM', 'wp-optimize'),
			),
		));
	}
}

endif;
