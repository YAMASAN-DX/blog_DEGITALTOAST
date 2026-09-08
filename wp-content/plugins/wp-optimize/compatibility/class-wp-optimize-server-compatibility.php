<?php
if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WP_Optimize_Server_Compatibility')) :
/**
 * Adds compatibility for Servers.
 */
class WP_Optimize_Server_Compatibility {

	/**
	 * Cached result of the Kinsta server detection.
	 *
	 * Null until first evaluation, then bool for subsequent calls.
	 *
	 * @var bool|null
	 */
	private $is_kinsta_server = null;

	/**
	 * Returns singleton instance
	 *
	 * @return WP_Optimize_Server_Compatibility
	 */
	public static function get_instance() {
		static $instance = null;
		if (null === $instance) {
			$instance = new WP_Optimize_Server_Compatibility();
		}
		return $instance;
	}

	/**
	 * Detects whether the server handles cache. e.g. Nginx cache
	 *
	 * @return bool
	 */
	public function does_server_handle_cache(): bool {
		return $this->is_kinsta();
	}

	/**
	 * Detects whether the server supports table optimization.
	 *
	 * Some servers prevent table optimization because the InnoDB engine
	 * does not optimize tables — instead it drops and recreates them,
	 * resulting in elevated disk write operations.
	 *
	 * @return bool
	 */
	public function does_server_allow_table_optimization(): bool {
		return !$this->is_kinsta();
	}

	/**
	 * Detects whether the server supports local webp conversion tools
	 *
	 * @return bool
	 */
	public function does_server_allow_local_webp_conversion(): bool {
		return !$this->is_kinsta();
	}

	/**
	 * Detects if the platform is Kinsta or not.
	 *
	 * Results are cached after the first call to ensure consistency
	 * and avoid redundant filter applications within a single request.
	 *
	 * @return bool Returns true if it is a Kinsta platform, otherwise returns false
	 */
	private function is_kinsta(): bool {
		if (null !== $this->is_kinsta_server) {
			return $this->is_kinsta_server;
		}

		$is_kinsta = isset($_SERVER['KINSTA_CACHE_ZONE']);

		/**
		 * Filter whether the server is detected as Kinsta.
		 *
		 * Allows overriding the default detection logic in case
		 * Kinsta changes environment variables or for edge cases.
		 *
		 * @param bool $is_kinsta Detected Kinsta status.
		 */
		$this->is_kinsta_server = (bool) apply_filters('wpo_is_kinsta_server', $is_kinsta);

		return $this->is_kinsta_server;
	}

	/**
	 * Disables automatic table optimization when the server does not allow it.
	 *
	 * Checks the current server capabilities and, if table optimization is
	 * unsupported, updates the "auto" option to disable it. This prevents
	 * scheduled or automatic optimizations from running on incompatible hosts.
	 *
	 * @return void
	 */
	public function maybe_disable_unsupported_table_optimization(): void {

		if ($this->does_server_allow_table_optimization()) {
			return;
		}

		$options = WP_Optimize()->get_options();
		$auto_options = $options->get_option('auto');

		if (empty($auto_options) || !is_array($auto_options)) {
			return;
		}

		if (isset($auto_options['optimize']) && 'true' === $auto_options['optimize']) {
			$auto_options['optimize'] = 'false';
			$options->update_option('auto', $auto_options);
		}
	}
}
endif;
