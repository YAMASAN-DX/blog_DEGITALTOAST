<?php
if (!defined('ABSPATH')) die('No direct access allowed');

class WP_Optimize_UpdraftCentral_Commands {

	private $wp_optimize;

	private $wp_optimize_commands;

	private $optimizer;

	protected $options;

	/**
	 * Class constructor
	 */
	public function __construct() {
		$this->wp_optimize = WP_Optimize();
		$this->wp_optimize_commands = new WP_Optimize_Commands();
		$this->optimizer = $this->wp_optimize->get_optimizer();
		$this->options = $this->wp_optimize->get_options();
	}

	/**
	 * Get multiple widgets data.
	 *
	 * @param array $args Args.
	 *
	 * @return array
	 */
	public function get_widgets_data($args) {
		if (!current_user_can($this->wp_optimize->capability_required())) {
			// N.B. request_error already returns a WP_Error, so an is_wp_error() check is unnecessary.
			$error = $this->wp_optimize_commands->request_error('insufficient_privilege');

			return array(
				'error' => true,
				'data' => array(
					'code' => $error->get_error_code(),
					'message' => $error->get_error_message(),
				)
			);
		}

		// Widgets.
		$widgets = isset($args['widgets']) ? $args['widgets'] : array();

		// Return early if no widgets supplied.
		if (!is_array($widgets) || empty($widgets)) {
			return array('error' => false, 'data' => array());
		}

		// Get the data for each widget.
		$data = array();

		// Loop through the widgets and get their data.
		foreach ($widgets as $widget) {
			$method = 'get_' . $widget . '_data';

			if (method_exists($this, $method)) {
				$data[$widget] = $this->$method($args);
			} else {
				if ($result = $this->get_delegated_widgets_data($widget)) {
					$data[$widget] = $result;
				}
			}
		}

		return array('error' => false, 'data' => $data);
	}

	/**
	 * Retrieves the widget data if it exists under WP_Optimize_Commands
	 *
	 * @param string $widget Widget name
	 *
	 * @return array|boolean
	 */
	private function get_delegated_widgets_data($widget) {
		if (is_string($widget) && '' !== trim($widget) && is_callable(array($this->wp_optimize_commands, 'get_widgets_data'))) {

			$widget = trim($widget);
			$result = $this->wp_optimize_commands->get_widgets_data(array('widgets' => array($widget)));

			if (isset($result['data']) && isset($result['data'][$widget])) {
				return $result['data'][$widget];
			}
		}

		return false;
	}

	/**
	 * Get system status data for system overview for UDC.
	 *
	 * @return array
	 */
	private function get_system_status_data() {

		$cache = $this->wp_optimize->get_page_cache();
		$admin = $this->wp_optimize->get_admin_instance();
		$gzip_compression = $this->wp_optimize->get_gzip_compression();

		$schedule_cleanup = array();
		if ($result = $this->get_delegated_widgets_data('wp_optimize_schedule_cleanup_settings')) {
			$schedule_cleanup = $result;
		}

		$gzip_compression_enabled = $gzip_compression->is_gzip_compression_enabled(true);
		$gzip_compression_enabled = (!is_wp_error($gzip_compression_enabled) && $gzip_compression_enabled);
		$wpo_gzip_headers_information = $gzip_compression->get_headers_information();
		$wpo_gzip_headers_information = (!is_wp_error($wpo_gzip_headers_information)) ? $wpo_gzip_headers_information : array();
		$is_cloudflare_site = $admin->is_cloudflare_site();
		$is_gzip_compression_section_exists = $gzip_compression->is_gzip_compression_section_exists();
		$gzip_compression_enabled_by_wpo = $is_gzip_compression_section_exists && $gzip_compression_enabled && !$is_cloudflare_site && !(is_array($wpo_gzip_headers_information) && isset($wpo_gzip_headers_information['compression']) && 'brotli' === $wpo_gzip_headers_information['compression']);
		$is_premium = $schedule_cleanup['is_premium'] ?? false;
		$is_multisite_mode = $this->wp_optimize->is_multisite_mode();

		list($db_size, $total_gain) = $this->optimizer->get_current_db_size();

		$data = array(
			'is_premium' => $is_premium,
			'is_multisite' => $is_multisite_mode,
			'database' => array(
				'overload_size' => $total_gain,
				'db_size' => $db_size,
				'scheduled_cleanup' => $schedule_cleanup['data'] ?? array(),
				'auto_backup' => 'true' === $this->options->get_option('enable-auto-backup-scheduled', 'false'),
			),
			'images' => array(
				'image_optimisation' => (bool) $this->options->get_option('autosmush', 0),
				'webp_conversion' => (bool) $this->options->get_option('webp_conversion', 0),
				'lazyload' => $this->options->get_option('lazyload', array(
					'images' => false,
					'backgrounds' => false,
					'iframes' => false,
					'youtube_preview' => false,
					'skip_classes' => '',
				)),
				'image_dimensions' => (bool) $this->options->get_option('image_dimensions', 0),
				'pending_images' => Updraft_Smush_Manager()->get_uncompressed_images('false'),
				'unused_images' => array(),
				'last_scan' => (int) $this->options->get_option('unused_images_last_scan_detect_unused_images', 0),
			),
			'cache' => array(
				'page_cache' => $cache->is_enabled(),
				'gzip_compression' => array(
					'enabled' => $gzip_compression_enabled,
					'headers' => $wpo_gzip_headers_information,
					'section_exists' => $is_gzip_compression_section_exists,
					'enabled_by_wpo' => $gzip_compression_enabled_by_wpo,
					'is_apache' => $this->wp_optimize->is_apache_server(),
					'is_cloudflare' => $is_cloudflare_site,
				),
			),
			'minify' => wp_optimize_minify_config()->get(),
		);

		if ($is_premium) {
			$optimization_details = $this->wp_optimize_commands->get_optimization_info(array(
				'optimization_id' => 'images',
				'data' => array(
					'mode' => 'detect_unused_images',
					'forced' => true,
					'include_ui_elements' => false,
				)
			));
			$optimization_details = $optimization_details['result'] ?? null;

			if (is_object($optimization_details) && property_exists($optimization_details, 'meta')) {
				$data['images']['unused_images'] = $optimization_details->meta;
			}
		}

		return $data;
	}

	/**
	 * Toggle a feature on or off.
	 *
	 * @param  array $data Array of data.
	 * @return array Output and messages.
	 */
	public function toggle_feature($data) {
		if (!current_user_can($this->wp_optimize->capability_required())) {
			return $this->wp_optimize_commands->request_error('insufficient_privilege');
		}

		if (!is_array($data)) {
			return $this->wp_optimize_commands->request_error(
				'toggle_feature_error',
				null,
				array(
					'message' => __('Wrong data type received', 'wp-optimize'),
					'data'    => $data,
				)
			);
		}

		if (!isset($data['name'], $data['value'])) {
			return $this->wp_optimize_commands->request_error(
				'toggle_feature_error',
				null,
				array(
					'message' => __('Missing data in request', 'wp-optimize'),
					'data'    => $data,
				)
			);
		}

		$name = sanitize_key($data['name']);
		$value = filter_var($data['value'], FILTER_VALIDATE_BOOLEAN);

		try {
			switch ($name) {

				case 'page_cache':
					$cache = $this->wp_optimize->get_page_cache();

					if ($value) {
						$output = $cache->enable(true);
					} else {
						$output = $cache->disable();
					}

					if (is_wp_error($output)) {
						throw new Exception($output->get_error_message());
					}
					break;

				case 'minify_css':
				case 'minify_js':
					$minify_config = wp_optimize_minify_config();
					$current_settings = $minify_config->get();

					$new_settings = array(
						'enabled' => true,
					);

					if ('minify_css' === $name) {
						$new_settings['enable_css'] = $value;
						$new_settings['enable_css_minification'] = $value;
					}

					if ('minify_js' === $name) {
						$new_settings['enable_js'] = $value;
						$new_settings['enable_js_minification'] = $value;
					}

					$css_enabled  = $new_settings['enable_css'] ?? ($current_settings['enable_css'] ?? false);
					$js_enabled   = $new_settings['enable_js'] ?? ($current_settings['enable_js'] ?? false);
					$html_enabled = $current_settings['html_minification'] ?? false;

					if (!$css_enabled && !$js_enabled && !$html_enabled) {
						$new_settings['enabled'] = false;
					}

					$minify_config->update($new_settings);
					break;

				case 'image_optimisation':
					Updraft_Smush_Manager()->update_smush_options(
						array(
							'autosmush' => $value,
						)
					);
					break;

				case 'webp_conversion':
					$this->wp_optimize->get_webp_instance()->save_webp_settings(
						array(
							'webp_conversion' => $value,
						)
					);
					break;

				case 'gzip_compression':
					$response = $this->wp_optimize_commands->enable_gzip_compression(
						array(
							'enable' => $value,
						)
					);

					if (is_array($response) && false === $response['success']) {
						throw new Exception($response['message']);
					}
					break;

				case 'lazy_load':
					$this->options->save_lazy_load_settings(
						array(
							'lazyload' => array(
								'images'          => $value,
								'iframes'         => $value,
								'backgrounds'     => $value,
								'youtube_preview' => $value,
								'skip_classes'    => '',
							),
						)
					);
					break;

				case 'image_dimensions':
					$this->options->update_option('image_dimensions', (int) $value);
					break;

				case 'auto_backup_scheduled':
					$this->options->update_option(
						'enable-auto-backup-scheduled',
						$value ? 'true' : 'false'
					);
					break;

				default:
					return $this->wp_optimize_commands->request_error(
						'toggle_feature_error',
						null,
						array(
							'message' => __('Invalid feature name received', 'wp-optimize'),
							'feature' => $name,
						)
					);
			}

			return array(
				'settings' => $this->get_system_status_data(),
			);

		} catch (Throwable $e) {
			return $this->wp_optimize_commands->request_error(
				'toggle_feature_error',
				null,
				array(
					'message'  => $e->getMessage(),
					'feature'  => $name,
					'settings' => $this->get_system_status_data(),
				)
			);
		}
	}
}
