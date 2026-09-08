<?php
if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WP_Optimize_Performance')) :

class WP_Optimize_Performance {

	/**
	 * WP_Optimize_404_Detector instance
	 *
	 * @var WP_Optimize_404_Detector
	 */
	private $obj_404_detector;
	
	/**
	 * Constructor
	 *
	 * @param WP_Optimize_404_Detector $detector
	 */
	private function __construct($detector) {
		$this->obj_404_detector = $detector;
	}

	/**
	 * Returns singleton instance
	 *
	 * @param WP_Optimize_404_Detector $detector Helper class to be able to call the 404 request detector
	 * @return WP_Optimize_Performance
	 */
	public static function get_instance($detector) {
		static $instance = null;
		if (null === $instance) {
			$instance = new WP_Optimize_Performance($detector);
		}
		return $instance;
	}

	/**
	 * Hook content generators
	 *
	 * @return void
	 */
	public function init() {

		add_filter('site_health_navigation_tabs', array($this, 'add_health_tab'));

		add_action('site_health_tab_content', array($this, 'site_health_tab_content'));

		add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));

		// Network admin dashboard (multisite)
		if (is_multisite()) {
			add_action('wp_network_dashboard_setup', array($this, 'add_dashboard_widgets'));
		}
	}

	/**
	 * Hook the handler class that will manage 404 requests
	 *
	 * @return void
	 */
	public function hook_404_handler() {
		add_action('template_redirect', array($this, 'maybe_handle_404_request'));
	}

	/**
	 * Detect if request is a 404 not found error, and handle it using WP_Optimize_404_Detector class
	 *
	 * @return void
	 */
	public function maybe_handle_404_request() {
		if (is_404()) {
			$this->obj_404_detector->handle_request();
		}
	}

	/**
	 * Add widget to admin dashboard
	 *
	 * @return void
	 */
	public function add_dashboard_widgets() {
		wp_add_dashboard_widget('wp_optimize_performance', __('Performance', 'wp-optimize'), array($this, 'print_dashboard_widget'));
	}

	/**
	 * Generate the admin widget content
	 *
	 * @return string
	 */
	private function dashboard_widget() {
		$html = $this->get_404_requests_summary();
		
		if (empty($html)) {
			$html = esc_html__('No performance issues found', 'wp-optimize');
		}

		return $html;
	}

	/**
	 * Build an HTML summary to be shown in a dashboard widget
	 *
	 * @return string
	 */
	private function get_404_requests_summary() {
		$detector = $this->obj_404_detector;

		$counts = $detector->get_suspicious_requests_count();
		if ($counts['total_requests'] > $detector->get_dashboard_alert_request_count_threshold()) {
			$url_count = $counts['unique_urls'];
			$total_count = $counts['total_requests'];

			$admin_url = is_multisite() ? network_admin_url('admin.php') : admin_url('admin.php');

			$performance_url = esc_url(add_query_arg(array('page' => 'wpo_performance'), $admin_url));

			$url_label = _n('URL', 'URLs', $url_count, 'wp-optimize');

			return sprintf(
				// translators: %1$d is count of unique URLs, %2$s is "URL" or "URLs", %3$d is count of 404 requests, %4$s is the performance page URL.
				__('<b>%1$d</b> %2$s generated <b>%3$d</b> 404 Not Found requests <a href="%4$s">Check</a>', 'wp-optimize'),
				$url_count,
				esc_html($url_label),
				$total_count,
				$performance_url
			);
		}

		return "";
	}

	/**
	 * Actually print the widget contents
	 *
	 * @return void
	 */
	public function print_dashboard_widget() {
		echo $this->dashboard_widget(); // phpcs:ignore WordPress.Security.EscapeOutput -- Output is already escaped
	}

	/**
	 * Add a tab on the site health check
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function add_health_tab($tabs) {
		$tabs['wpo_performance'] = 'WP-Optimize ' . esc_html__('Performance', 'wp-optimize');

		return $tabs;
	}

	/**
	 * Display information in the site health report
	 *
	 * @return void
	 */
	public function site_health_tab_content($tab) {
		if ('wpo_performance' === $tab) {
			WP_Optimize()->include_template('performance/site-health.php', false, array(
				'alerts' => $this->dashboard_widget()
			));
		}
	}
}

endif;
