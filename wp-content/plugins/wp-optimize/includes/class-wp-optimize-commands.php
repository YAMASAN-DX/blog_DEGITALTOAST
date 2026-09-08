<?php

if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * All commands that are intended to be available for calling from any sort of control interface (e.g. wp-admin, UpdraftCentral) go in here. All public methods should either return the data to be returned, or a WP_Error with associated error code, message and error data.
 */
class WP_Optimize_Commands {

	private $optimizer;

	protected $options;

	private $wpo_sites; // used in get_optimizations_info command.

	public function __construct() {
		$this->optimizer = WP_Optimize()->get_optimizer();
		$this->options = WP_Optimize()->get_options();
	}

	public function get_version() {
		return WPO_VERSION;
	}

	public function enable_or_disable_feature($data) {
	
		$type = (string) $data['type'];
		$enable = (bool) $data['enable'];
	
		$options = array($type => $enable);

		return $this->optimizer->trackback_comment_actions($options);
	}
	
	public function save_manual_run_optimization_options($sent_options) {
		return $this->options->save_sent_manual_run_optimization_options($sent_options);
	}

	public function get_status_box_contents() {
		$status_data = $this->get_all_status_data();

		return WP_Optimize()->include_template('database/status-box-contents.php', true, $status_data);
	}

	/**
	 * Get the database tabs information.
	 *
	 * @return string auto cleanup content.
	 */
	public function get_settings_auto_cleanup_contents() {
		$settings_cleanup_data = $this->get_wp_optimize_schedule_cleanup_settings_data();

		return WP_Optimize()->include_template('database/settings-auto-cleanup.php', true, array('show_innodb_option' => WP_Optimize()->template_should_include_data() && $this->optimizer->show_innodb_force_optimize(), 'settings_cleanup_data' => $settings_cleanup_data));
	}

	/**
	 * Get the settings tab information.
	 *
	 * @return string logging settings content.
	 */
	public function get_logging_settings_contents() {
		$loggers_data = $this->get_wp_optimize_logging_settings_data();
		return WP_Optimize()->include_template('settings/settings-logging.php', true, array('loggers_data' => $loggers_data));
	}
	
	/**
	 * Get the database tabs information
	 *
	 * @return string database table optimization rendered content
	 */
	public function get_optimizations_table() {

		$optimization_data = $this->get_wp_optimize_data();

		return WP_Optimize()->include_template('database/optimizations-table.php', true, $optimization_data);
	}

	/**
	 * [For UpdraftCentral] Returns the file content containing the list of unused images
	 *
	 * @return WP_Error|void
	 */
	public function download_csv() {
		if (!WP_Optimize()->current_user_can()) {
			return $this->request_error('insufficient_privilege');
		}
	
		WP_Optimize()->get_optimizer()->get_optimization('images')->output_csv();
	}

	/**
	 * [For UpdraftCentral] Retrieves the tabs and their corresponding contents, assets basing
	 * from the current page or section requested (e.g. database, images, cache, minify or settings)
	 *
	 * @param array $params Parameters needed for the requested action
	 * @return array|WP_Error
	 */
	public function get_section($params) {
		if (!WP_Optimize()->current_user_can()) {
			return $this->request_error('insufficient_privilege');
		}

		$section_id = $params['section'];
		$section = $this->get_section_to_tab_name($section_id);
		$tabs = WP_Optimize()->get_admin_instance()->get_tabs($section);

		// We remove the UpdraftCentral management tab when managing WP-Optimize
		// plugin from the UpdraftCentral dashboard if it exists.
		if (isset($tabs['updraftcentral'])) unset($tabs['updraftcentral']);

		$this->load_action_hooks_for_content($section_id);
		return array(
			'tabs' => $tabs,
			'tabs_content' => $this->get_tab_contents($tabs, $section),
			'assets' => $this->get_localized_variables(),
			'wposmush' => $this->get_wposmush(),
			'is_premium' => WP_Optimize::is_premium(),
			'modal_template' => WP_Optimize()->include_template('modal.php', true),
		);
	}

	/**
	 * [For UpdraftCentral] Retrieves the list of items to optimize from the current database
	 *
	 * @return array|WP_Error
	 */
	public function get_database_tabs_info() {
		if (!WP_Optimize()->current_user_can()) {
			return $this->request_error('insufficient_privilege');
		}

		return $this->get_database_tabs();
	}

	/**
	 * [For UpdraftCentral] Processes specific Smush commands from ajax requests
	 *
	 * @param array $params Parameters needed for the requested action
	 * @return array|WP_Error
	 */
	public function updraft_smush_ajax($params) {
		if (!WP_Optimize()->current_user_can()) {
			return $this->request_error('insufficient_privilege');
		}

		$subaction = $params['subaction'];
		$data = $params['data'];

		$allowed_commands = Updraft_Smush_Manager_Commands::get_allowed_ajax_commands();
		if (in_array($subaction, $allowed_commands)) {
			if (in_array($subaction, array('get_smush_logs', 'process_bulk_smush'))) {
				switch ($subaction) {
					case 'get_smush_logs':
						$results = $this->get_smush_logs();
						break;
					case 'process_bulk_smush':
						$results = $this->process_bulk_smush($data);
						break;
					default:
						break;
				}
			} else {
				if (!empty($data)) {
					$results = call_user_func(array(Updraft_Smush_Manager()->commands, $subaction), $data);
				} else {
					$results = call_user_func(array(Updraft_Smush_Manager()->commands, $subaction));
				}
			}
			
			if (is_wp_error($results)) {
				return $this->request_error($results->get_error_code(), null, array(
					'status' => true,
					'error_message' => $results->get_error_message(),
					'error_data' => $results->get_error_data(),
				));
			} else {
				return $results;
			}
		} else {
			return $this->request_error('command_not_found');
		}
	}

	/**
	 * [For UpdraftCentral] Processes general WP-Optimize commands from ajax requests
	 *
	 * @param array $params Parameters needed for the requested action
	 * @return array|WP_Error
	 */
	public function handle_ajax_requests($params) {
		if (!WP_Optimize()->current_user_can()) {
			return $this->request_error('insufficient_privilege');
		}

		$subaction = $params['subaction'];
		$data = $params['data'];

		$response = array();
		if (is_multisite() && !WP_Optimize()->current_user_can('manage_network_options')) {
			$allowed_multisite_commands = apply_filters('wpo_multisite_allowed_commands', array('check_server_status', 'compress_single_image', 'restore_single_image'));

			if (!in_array($subaction, $allowed_multisite_commands)) {
				return $this->request_error('network_admin_only');
			}
		}

		$dismiss_actions = array(
			'dismiss_dash_notice_until',
			'dismiss_season',
			'dismiss_page_notice_until',
			'dismiss_notice',
			'dismiss_review_notice',
		);

		if (in_array($subaction, $dismiss_actions)) {
			$options = WP_Optimize()->get_options();

			// Some commands that are available via AJAX only.
			if (in_array($subaction, array('dismiss_dash_notice_until', 'dismiss_season'))) {
				$options->update_option($subaction, (time() + 366 * 86400));
			} elseif (in_array($subaction, array('dismiss_page_notice_until', 'dismiss_notice'))) {
				$options->update_option($subaction, (time() + 84 * 86400));
			} elseif ('dismiss_review_notice' === $subaction) {
				if (empty($data['dismiss_forever'])) {
					$options->update_option($subaction, time() + 84 * 86400);
				} else {
					$options->update_option($subaction, 100 * (365.25 * 86400));
				}
			}

		} else {
			$commands = new WP_Optimize_Commands();

			$minify_commands = new WP_Optimize_Minify_Commands();
			if (!is_callable(array($commands, $subaction)) && is_callable(array($minify_commands, $subaction))) {
				$commands = $minify_commands;
			}

			if (WP_Optimize::is_premium()) {
				$cache_commands = new WP_Optimize_Cache_Commands_Premium();
			} else {
				$cache_commands = new WP_Optimize_Cache_Commands();
			}

			if (!is_callable(array($commands, $subaction)) && is_callable(array($cache_commands, $subaction))) {
				$commands = $cache_commands;
			}

			// Check if command is valid
			if (!is_callable(array($commands, $subaction))) {
				return $this->request_error('command_not_found');
			} else {
				$response = call_user_func(array($commands, $subaction), $data);
				if (is_wp_error($response)) {
					return $this->request_error($response->get_error_code(), null, array(
						'error_message' => $response->get_error_message(),
						'error_data' => $response->get_error_data(),
					));
				} elseif (empty($response)) {
					$response = array(
						'result' => null
					);
				} else {
					if (isset($response['status_box_contents'])) {
						$response['status_box_contents'] = str_replace(array("\n", "\t"), '', $response['status_box_contents']);
					}
				}
			}
		}

		return $response;
	}

	/**
	 * [For UpdraftCentral] Retrieves a list of posts to purge as options to be displayed under
	 * cache - advanced settings
	 *
	 * @param array $params Parameters needed for the requested action
	 * @return array|WP_Error
	 */
	public function get_posts_list($params) {
		if (!WP_Optimize()->current_user_can()) {
			return $this->request_error('insufficient_privilege');
		}

		$response = array();
		if (WP_Optimize::is_premium()) {
			$commands = new WP_Optimize_Cache_Commands_Premium();
			if (is_callable(array($commands, 'get_posts_list'))) {
				$response = $commands->get_posts_list($params);
			}
		}

		return $response;
	}

	/**
	 * [For UpdraftCentral] loads the settings page
	 *
	 * @return void
	 */
	public function output_dashboard_settings_tab() {
		$wpo_admin = WP_Optimize()->get_admin_instance();

		if (WP_Optimize()->can_manage_options()) {
			$loggers_data = $this->get_wp_optimize_logging_settings_data();
			$settings_trackback_data = $this->get_wp_optimize_trackback_toggle_settings_data();
			$settings_comments_data = $this->get_wp_optimize_comments_toggle_settings_data();
			$settings_general_data = $this->get_wp_optimize_general_settings_data();

			WP_Optimize()->include_template('settings/settings.php', false, array('loggers_data' => $loggers_data, 'settings_trackback_data' => $settings_trackback_data, 'settings_comments_data' => $settings_comments_data, 'settings_general_data' => $settings_general_data));
		} else {
			$wpo_admin->prevent_manage_options_info();
		}
	}

	/**
	 * [For UpdraftCentral] Retrieves Smush JS variables
	 *
	 * @return array
	 */
	private function get_wposmush() {
		if (!class_exists('Updraft_Smush_Manager')) return array();

		$js_variables = Updraft_Smush_Manager()->smush_js_translations();
		$js_variables['ajaxurl'] = admin_url('admin-ajax.php');
		$js_variables['features'] = Updraft_Smush_Manager()->get_features();

		return $js_variables;
	}

	/**
	 * [For UpdraftCentral] Execute smush process in bulk
	 *
	 * @param array $data Parameters to be pass as argument for the requested action
	 * @return string
	 */
	private function process_bulk_smush($data) {
		ob_start();
		Updraft_Smush_Manager()->commands->process_bulk_smush($data);
		$content = ob_get_contents();
		ob_end_clean();

		return $content;
	}

	/**
	 * [For UpdraftCentral] Retrieves the content of a Smush log file
	 *
	 * @return string|false|WP_Error
	 */
	private function get_smush_logs() {
		$logfile = Updraft_Smush_Manager()->get_logfile_path();
		if (!file_exists($logfile)) {
			 Updraft_Smush_Manager()->write_log_header();
		}

		if (is_file($logfile)) {
			return file_get_contents($logfile);
		} else {
			return $this->request_error('log_file_not_exist');
		}
	}

	/**
	 * [For UpdraftCentral] Loads the WP-Optimize hooks needed to pull the tab contents for
	 * the requested page/section
	 *
	 * @param string $section_id The id of the page or section where the content is to be pulled from
	 * @return void
	 */
	private function load_action_hooks_for_content($section_id) {
		$wpo = WP_Optimize();
		$wpo_admin = $wpo->get_admin_instance();

		// Database
		if ('database' === $section_id) {
			add_action('wp_optimize_admin_page_WP-Optimize_optimize', array($wpo_admin, 'output_database_optimize_tab'), 20);
			add_action('wp_optimize_admin_page_WP-Optimize_tables', array($wpo_admin, 'output_database_tables_tab'), 20);
			add_action('wp_optimize_admin_page_WP-Optimize_settings', array($wpo_admin, 'output_database_settings_tab'), 20);
		}

		// Images
		if ('images' === $section_id) {
			add_action('wp_optimize_admin_page_wpo_images_smush', array($wpo, 'admin_page_wpo_images_smush'));

			if (!WP_Optimize::is_premium()) {
				add_action('wp_optimize_admin_page_wpo_images_unused', array($wpo_admin, 'admin_page_wpo_images_unused'));
				add_action('wp_optimize_admin_page_wpo_images_lazyload', array($wpo_admin, 'admin_page_wpo_images_lazyload'));
			}
		}

		// Cache
		if ('cache' === $section_id) {
			add_action('wp_optimize_admin_page_wpo_cache_cache', array($wpo_admin, 'output_page_cache_tab'), 20);
			add_action('wp_optimize_admin_page_wpo_cache_preload', array($wpo_admin, 'output_page_cache_preload_tab'), 20);
			add_action('wp_optimize_admin_page_wpo_cache_advanced', array($wpo_admin, 'output_page_cache_advanced_tab'), 20);
			add_action('wp_optimize_admin_page_wpo_cache_gzip', array($wpo_admin, 'output_cache_gzip_tab'), 20);
			add_action('wp_optimize_admin_page_wpo_cache_settings', array($wpo_admin, 'output_cache_settings_tab'), 20);
		}

		// Minify
		if ('minify' === $section_id) {
			if (class_exists('WP_Optimize_Minify_Admin')) {
				do_action('wp_optimize_register_admin_content');
			}
		}

		// Settings
		if ('settings' === $section_id) {
			add_action('wp_optimize_admin_page_wpo_settings_settings', array($this, 'output_dashboard_settings_tab'), 20);
		}
	}

	/**
	 * [For UpdraftCentral] Execute the hooks needed to pull the content of the tabs under the requested page/section
	 *
	 * @param array  $tabs    The list of tabs available under the current page/section
	 * @param string $section The id of the page or section
	 * @return array
	 */
	private function get_tab_contents($tabs, $section) {
		$contents = array();

		foreach ($tabs as $tab_id => $tab_description) {
			ob_start();
			// output wrap div for tab with id #wp-optimize-nav-tab-contents-'.$section.'-'.$tab_id
			echo '<div class="wp-optimize-nav-tab-contents" id="wp-optimize-nav-tab-'.esc_attr($section).'-'.esc_attr($tab_id).'-contents">';
			
			echo '<div class="postbox wpo-tab-postbox">';

			// call action for generate tab content.
			do_action('wp_optimize_admin_page_'.$section.'_'.$tab_id);
			
			// closes postbox.
			echo '</div><!-- END .postbox -->';
			// closes tab wrapper.
			echo '</div><!-- END .wp-optimize-nav-tab-contents -->';
			$tab_content = ob_get_contents();
			ob_end_clean();

			$contents[$tab_id] = $tab_content;
		}

		return $contents;
	}

	/**
	 * [For UpdraftCentral] Replaces the names/id used in UpdraftCentral with the actual name used in WP-Optimize
	 *
	 * @param string $section_id The id of the page or section
	 * @return string
	 */
	private function get_section_to_tab_name($section_id) {
		$tab = '';
		switch ($section_id) {
			case 'database':
				$tab = 'WP-Optimize';
				break;
			default:
				$tab = 'wpo_'.$section_id;
				break;
		}
		return $tab;
	}

	/**
	 * [For UpdraftCentral] Retrieves the localized scripts/variables (e.g. translations, etc.) used by WP-Optimize
	 *
	 * @return array
	 */
	private function get_localized_variables() {
		$wpo = WP_Optimize();
		$js_variables = $wpo->wpo_js_translations();
		$js_variables['loggers_classes_info'] = $wpo->get_loggers_classes_info();
		$js_variables['ajaxurl'] = admin_url('admin-ajax.php');

		$localized_variables = array(
			'wpoptimize' => $js_variables,
			'wp_optimize_send_command_data' => array('nonce' => wp_create_nonce('wp-optimize-ajax-nonce')),
			'wpo_heartbeat_ajax' => array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('heartbeat-nonce'),
				'interval' => WPO_Ajax::HEARTBEAT_INTERVAL
			),
		);

		if (WP_Optimize::is_premium()) {
			$localized_variables = array_merge($localized_variables, array(
				'wp_optimize_minify_premium' => array(
					'home_url' => home_url()
				)
			));
		}

		return array(
			'localize' => $localized_variables,
		);
	}

	/**
	 * [For UpdraftCentral] Returns a WP_Error response for the current request
	 *
	 * @param string $code            The error code
	 * @param ?string $message_key     The key to use to query any translatable message if applicable
	 * @param array  $additional_data Any additional data to return
	 * @return WP_Error
	 */
	public function request_error($code, $message_key = null, $additional_data = array()) {
		global $updraftcentral_host_plugin;

		$error_message = $updraftcentral_host_plugin->retrieve_show_message($code);
		if (!is_null($message_key) && $code !== $message_key) {
			$error_message = $updraftcentral_host_plugin->retrieve_show_message($message_key);
		}

		$data = array(
			'result' => false,
			'error_code' => $code,
			'error_message' => $error_message,
			'error' => true,
			'code' => $code,
			'message' => $error_message,
		);

		if (!empty($additional_data)) {
			$data = array_merge($data, $additional_data);
		}

		return new WP_Error($code, $error_message, $data);
	}

	/**
	 * Pulls and return the "WP Optimize" template contents. Primarily used for UpdraftCentral
	 * content display through ajax request.
	 *
	 * @return array An array containing the WPO translations and the "WP Optimize" tab's rendered contents
	 */
	public function get_wp_optimize_contents() {
		$status_data = $this->get_all_status_data();
		$optimization_data = $this->get_wp_optimize_data();
		$content = WP_Optimize()->include_template('database/optimize-table.php', true, array('optimize_db' => false, 'load_data' => WP_Optimize()->template_should_include_data(), 'does_server_allow_table_optimization' => WP_Optimize()->get_server_compatibility_instance()->does_server_allow_table_optimization(), 'optimizations_table_data' => $optimization_data, 'status_data' => $status_data));

		if (WP_Optimize()->is_updraft_central_request()) {
			$content .= $this->get_status_box_contents();
		}

		return array(
			'content' => $content,
			'translations' => $this->get_js_translation()
		);
	}

	/**
	 * Pulls and return the "Table Information" template contents. Primarily used for UpdraftCentral
	 * content display through ajax request.
	 *
	 * @return array An array containing the WPO translations and the "Table Information" tab's rendered contents
	 */
	public function get_table_information_contents() {
		$table_list_data = $this->get_table_list_data();
		$content = WP_Optimize()->include_template('database/tables.php', true, array('optimize_db' => false, 'load_data' => WP_Optimize()->template_should_include_data(), 'table_list_data' => $table_list_data['data']));

		return array(
			'content' => $content,
			'translations' => $this->get_js_translation()
		);
	}

	/**
	 * Pulls and return the "Settings" template contents. Primarily used for UpdraftCentral
	 * content display through ajax request.
	 *
	 * @return array An array containing the WPO translations and the "Settings" tab's rendered contents
	 */
	public function get_settings_contents() {
		$admin_settings = '<form action="#" method="post" enctype="multipart/form-data" name="settings_form" id="settings_form">';

		$settings_general_data = $this->get_wp_optimize_general_settings_data();
		$admin_settings .= WP_Optimize()->include_template('database/settings-general.php', true, $settings_general_data);

		$settings_cleanup_data = $this->get_wp_optimize_schedule_cleanup_settings_data();
		$admin_settings .= WP_Optimize()->include_template('database/settings-auto-cleanup.php', true, array('show_innodb_option' => WP_Optimize()->template_should_include_data() && $this->optimizer->show_innodb_force_optimize(), 'settings_cleanup_data' => $settings_cleanup_data));

		$loggers_data = $this->get_wp_optimize_logging_settings_data();
		$admin_settings .= WP_Optimize()->include_template('settings/settings-logging.php', true, array('loggers_data' => $loggers_data));
		
		$admin_settings .= '<input id="wp-optimize-settings-save" class="button button-primary" type="submit" name="wp-optimize-settings" value="' . esc_attr__('Save settings', 'wp-optimize') .'" />';
		$admin_settings .= '</form>';

		$settings_trackback_data = $this->get_wp_optimize_trackback_toggle_settings_data();
		$settings_comments_data = $this->get_wp_optimize_comments_toggle_settings_data();
		$admin_settings .= WP_Optimize()->include_template('settings/settings-trackback-and-comments.php', true, array('settings_trackback_data' => $settings_trackback_data, 'settings_comments_data' => $settings_comments_data));

		$content = $admin_settings;

		return array(
			'content' => $content,
			'translations' => $this->get_js_translation()
		);
	}

	/**
	 * Returns array of translations used by the WPO plugin. Primarily used for UpdraftCentral
	 * consumption.
	 *
	 * @param array $params Optional parameters for the translation request
	 * @return array The WPO translations
	 */
	public function get_js_translation($params = array()) {
		$translations = WP_Optimize()->wpo_js_translations();

		// Make sure that we include the loggers classes info whenever applicable before
		// returning the translations to UpdraftCentral.
		if (is_callable(array(WP_Optimize(), 'get_loggers_classes_info'))) {
			$translations['loggers_classes_info'] = WP_Optimize()->get_loggers_classes_info();
		}

		if (isset($params['return_formatted_response'])) {
			return array(
				'error' => false,
				'data' => array(
					'translations' => $translations,
				)
			);
		}

		return $translations;
	}

	/**
	 * Save settings command.
	 *
	 * @param string $data
	 * @return array
	 */
	public function save_settings($data) {
		
		parse_str(stripslashes($data), $posted_settings);

		$saved_settings = $this->options->save_settings($posted_settings);
		// We now have $posted_settings as an array.
		return array(
			'save_results' => $saved_settings,
			'status_box_contents' => $this->get_status_box_contents(),
			'optimizations_table' => $this->get_optimizations_table(),
			'settings_auto_cleanup_contents' => $this->get_settings_auto_cleanup_contents(),
			'logging_settings_contents' => $this->get_logging_settings_contents(),
		);
	}

	/**
	 * Wipe settings command.
	 *
	 * @return bool|int
	 */
	public function wipe_settings() {
		$result = $this->options->wipe_settings();

		$onboarding = WP_Optimize()->get_onboarding();
		$onboarding->activate_onboarding_wizard();

		return $result;
	}

	/**
	 * Save lazy load settings.
	 *
	 * @param string $data
	 * @return array
	 */
	public function save_lazy_load_settings($data) {
		parse_str(stripslashes($data), $posted_settings);

		return array(
			'save_result' => $this->options->save_lazy_load_settings($posted_settings)
		);
	}

	/**
	 * This sends the selected tick value over to the save function
	 * within class-wp-optimize-options.php
	 *
	 * @param  array $data An array of data that includes true or false for click option.
	 * @return array
	 */
	public function save_auto_backup_option($data) {
		return array('save_auto_backup_option' => $this->options->save_auto_backup_option($data));
	}

	/**
	 * Save option which sites to optimize in multisite mode.
	 *
	 * @param array $data Array of settings.
	 * @return bool
	 */
	public function save_site_settings($data) {
		if (!isset($data['wpo-sites'])) {
			// this means no site selected
			$data['wpo-sites'] = array();
		}
		return $this->options->save_wpo_sites_option($data['wpo-sites']);
	}

	/**
	 * Perform the requested optimization
	 *
	 * @param  array $params Should have keys 'optimization_id' and 'data'.
	 * @return array
	 */
	public function do_optimization($params) {
		
		if (!isset($params['optimization_id'])) {
			$results = array(
				'result' => false,
				'messages' => array(),
				'errors' => array(
					__('No optimization was indicated.', 'wp-optimize')
				)
			);
		} else {
			$optimization_id = $params['optimization_id'];
			$data = $params['data'] ?? array();
			$include_ui_elements = $data['include_ui_elements'] ?? false;
			
			$optimization = $this->optimizer->get_optimization($optimization_id, $data);
	
			$result = is_a($optimization, 'WP_Optimization') ? $optimization->do_optimization() : null;

			$results = array(
				'result' => $result,
				'messages' => array(),
				'errors' => array(),
			);

			if ($include_ui_elements) {
				$results['status_box_contents'] = $this->get_status_box_contents();
			}
			
			if (is_wp_error($optimization)) {
				$results['errors'][] = $optimization->get_error_message().' ('.$optimization->get_error_code().')';
			}
			
			if ($include_ui_elements && $optimization->get_changes_table_data()) {
				$table_list = $this->get_table_list();
				$results['table_list'] = $table_list['table_list'];
				$results['total_size'] = $table_list['total_size'];
			}
		}
		return $results;
	}

	/**
	 * Preview command, used to show information about data should be optimized in popup tool.
	 *
	 * @param array $params Should have keys 'optimization_id', 'offset' and 'limit'.
	 *
	 * @return array
	 */
	public function preview($params) {
		if (!isset($params['optimization_id'])) {
			$results = array(
				'result' => false,
				'messages' => array(),
				'errors' => array(
					__('No optimization was indicated.', 'wp-optimize')
				)
			);
		} else {
			$optimization_id = $params['optimization_id'];
			$data = $params['data'] ?? array();
			$params['offset'] = isset($params['offset']) ? (int) $params['offset'] : 0;
			$params['limit'] = isset($params['limit']) ? (int) $params['limit'] : 50;

			$optimization = $this->optimizer->get_optimization($optimization_id, $data);

			if (is_a($optimization, 'WP_Optimization')) {
				if (isset($params['site_id'])) {
					$optimization->switch_to_blog((int) $params['site_id']);
				}
				$result = $optimization->preview($params);
			} else {
				$result = null;
			}

			$results = array(
				'result' => $result,
				'messages' => array(),
				'errors' => array()
			);
		}

		return $results;
	}

	/**
	 * Get information about requested optimization.
	 *
	 * @param array $params Should have keys 'optimization_id' and 'data'.
	 * @return array
	 */
	public function get_optimization_info($params) {
		if (!isset($params['optimization_id'])) {
			$results = array(
				'result' => false,
				'messages' => array(),
				'errors' => array(
					__('No optimization was indicated.', 'wp-optimize')
				)
			);
		} else {
			$optimization_id = $params['optimization_id'];
			$data = $params['data'] ?? array();
			$include_ui_elements = $data['include_ui_elements'] ?? false;

			$optimization = $this->optimizer->get_optimization($optimization_id, $data);
			$result = is_a($optimization, 'WP_Optimization') ? $optimization->get_optimization_info() : null;

			$results = array(
				'result' => $result,
				'messages' => array(),
				'errors' => array(),
			);

			if ($include_ui_elements) {
				$results['status_box_contents'] = $this->get_status_box_contents();
			}
		}

		return $results;
	}

	/**
	 * Get the data for the tables tab
	 *
	 * @param array $data
	 * @return array
	 */
	public function get_table_list($data = array()) {
		if (isset($data['refresh_plugin_json']) && filter_var($data['refresh_plugin_json'], FILTER_VALIDATE_BOOLEAN)) WP_Optimize()->get_db_info()->update_plugin_json();

		$table_list_data = $this->get_table_list_data();

		if (WP_Optimize()->is_updraft_central_request()) {
			return $table_list_data;
		}

		$size = $this->optimizer->get_current_db_size();

		$tables_data = array(
			'table_list' => WP_Optimize()->include_template('database/tables-body.php', true, $table_list_data['data']),
			'total_size' => $size[0],
		);
	
		$filtered_tables_data = apply_filters('wpo_get_tables_data', $tables_data);
		if (!empty($filtered_tables_data) && is_array($filtered_tables_data)) return $filtered_tables_data;
		return $tables_data;
	}

	/**
	 * Get the database tabs information
	 *
	 * @return array
	 */
	public function get_database_tabs() {
		return array_merge(array('optimizations' => $this->get_optimizations_table(), 'does_server_allow_table_optimization' => WP_Optimize()->get_server_compatibility_instance()->does_server_allow_table_optimization()), $this->get_table_list());
	}

	/**
	 * Do action wp_optimize_after_optimizations
	 * used in ajax request after all optimizations completed
	 *
	 * @return boolean
	 */
	public function optimizations_done() {

		$this->options->update_option('total-cleaned', 0);
		// Run action after all optimizations completed.
		do_action('wp_optimize_after_optimizations');

		return true;
	}

	/**
	 * Return information about all optimizations.
	 *
	 * @param  array $params
	 * @return array
	 */
	public function get_optimizations_info($params) {
		$this->wpo_sites = $params['wpo-sites'] ?? 0;

		add_filter('get_optimization_blogs', array($this, 'get_optimization_blogs_filter'));

		$results = array();
		$optimizations = $this->optimizer->get_optimizations();

		foreach ($optimizations as $optimization_id => $optimization) {
			if (false === $optimization->display_in_optimizations_list()) continue;

			$results[$optimization_id] = $optimization->get_settings_html();
		}

		return $results;
	}

	/**
	 * Filter for get_optimizations_blogs function, used in get_optimizations_info command.
	 * Not intended for direct usage as a command (is used internally as a WP filter)
	 *
	 * The class variable $wpo_sites is used for performing the filtering.
	 *
	 * @param array $sites - unfiltered list of sites
	 * @return array - after filtering
	 */
	public function get_optimization_blogs_filter($sites) {
		$sites = array();

		if (!empty($this->wpo_sites)) {
			foreach ($this->wpo_sites as $site) {
				if ('all' !== $site) $sites[] = $site;
			}
		}

		return $sites;
	}

	/**
	 * Checks overdue crons and return message
	 *
	 * @return array
	 */
	public function check_overdue_crons() {
		$overdue_crons = WP_Optimize()->howmany_overdue_crons();

		if ($overdue_crons >= 4) {
			return array('m' => WP_Optimize()->show_admin_warning_overdue_crons($overdue_crons));
		}

		return array();
	}

	/**
	 * Enable or disable Gzip compression.
	 *
	 * @param array $params - ['enable' => true|false]
	 * @return array
	 */
	public function enable_gzip_compression($params) {
		return WP_Optimize()->get_gzip_compression()->enable_gzip_command_handler($params);
	}

	/**
	 * Get the current gzip compression status
	 *
	 * @return array
	 */
	public function get_gzip_compression_status() {
		$status = WP_Optimize()->get_gzip_compression()->is_gzip_compression_enabled(true);
		return is_wp_error($status) ? array('error' => __('We could not determine if Gzip compression is enabled.', 'wp-optimize'), 'code' => $status->get_error_code(), 'message' => $status->get_error_message()) : array('status' => $status);
	}

	/**
	 * Import WP-Optimize settings.
	 *
	 * @param array $params array with 'settings' item where 'settings' json-encoded string.
	 *
	 * @return array - the results of the import operation
	 */
	public function import_settings($params) {
		if (empty($params['settings'])) {
			return array('errors' => array(__('Please upload a valid settings file.', 'wp-optimize')));
		}

		$settings = json_decode($params['settings'], true);

		// check if valid json file posted
		if (JSON_ERROR_NONE !== json_last_error() || empty($settings)) {
			return array('errors' => array(__('Please upload a valid settings file.', 'wp-optimize')));
		}

		$cache_settings = $settings['cache_settings'];
		$minify_settings = $settings['minify_settings'];
		$smush_settings = $settings['smush_settings'];
		$database_settings = $settings['database_settings'];

		$cache = WP_Optimize()->get_page_cache();
		$cache->create_folders();
		$cache_enable_result = null;
		if ($cache_settings['enable_page_caching']) {
			$cache_enable_result = $cache->enable();
		}

		$wpo_browser_cache = WP_Optimize()->get_browser_cache();
		if (isset($cache_settings['enable_browser_cache']) && $cache_settings['enable_browser_cache']) {
			$browser_cache = array(
				'browser_cache_expire_days' => $cache_settings['browser_cache_expire_days'],
				'browser_cache_expire_hours' => $cache_settings['browser_cache_expire_hours']
			);
			$wpo_browser_cache->enable_browser_cache_command_handler($browser_cache);
		}

		$message = '';
		$cache_result = WP_Optimize()->get_page_cache()->config->update($cache_settings);
		$minify_result = WP_Optimize()->get_minify()->minify_commands->save_minify_settings($minify_settings);
		$smush_result = WP_Optimize()->get_task_manager()->commands->update_smush_options($smush_settings);
		$webp_result = WP_Optimize()->get_task_manager()->commands->update_webp_options($smush_settings);
		$this->save_settings($database_settings);

		if (is_wp_error($cache_enable_result)) {
			$message .= $cache_enable_result->get_error_message() . PHP_EOL;
		}

		if (is_wp_error($cache_result)) {
			$message .= $cache_result->get_error_message() . PHP_EOL;
		}

		if (!$minify_result['success']) {
			$message .= isset($minify_result['message']) ? $minify_result['message'] . PHP_EOL : '';
			$message .= isset($minify_result['error']) ? $minify_result['error'] . PHP_EOL : '';
		}

		if (is_wp_error($smush_result)) {
			$message .= $smush_result->get_error_message() . PHP_EOL;
		}

		if (is_wp_error($webp_result)) {
			$message .= $webp_result->get_error_message() . PHP_EOL;
		}

		return array(
			'success' => true,
			'message' => empty($message) ? __('The settings were imported successfully.', 'wp-optimize') : $message
		);
	}

	/**
	 * Dismiss install or updated notice
	 *
	 * @return array
	 */
	public function dismiss_install_or_update_notice() {
		if (!is_a(WP_Optimize()->get_install_or_update_notice(), 'WP_Optimize_Install_Or_Update_Notice') || !is_callable(array(WP_Optimize()->get_install_or_update_notice(), 'dismiss'))) {
			return array('errors' => array('The notice could not be dismissed. The method "dismiss" on the object instance "install_or_update_notice" does not seem to exist.'));
		}

		if (!WP_Optimize()->get_install_or_update_notice()->dismiss()) {
			return array('errors' => array('The notice could not be dismissed. The settings could not be updated'));
		}

		return array();
	}

	/**
	 * Run images trash command.
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	public function images_trash_command($params) {
		if (!class_exists('WP_Optimize_Images_Trash_Manager_Commands')) {
			return array(
				'errors' => array('WP_Optimize_Images_Trash_Manager_Commands class not found'),
			);
		}

		// get posted command.
		$trash_command = $params['images_trash_command'] ?? '';
		// check if command is allowed.
		$allowed_commands = WP_Optimize_Images_Trash_Manager_Commands::get_allowed_ajax_commands();

		if (!in_array($trash_command, $allowed_commands)) {
			return array(
				'errors' => array('No such command found'),
			);
		}

		$results = call_user_func(array(WP_Optimize_Images_Trash_Manager()->commands, $trash_command), $params);

		if (is_wp_error($results)) {
			$results = array(
				'errors' => array($results->get_error_message()),
			);
		}

		return $results;
	}

	/**
	 * Power tweak handling
	 *
	 * @param array $params
	 * @return array
	 */
	public function power_tweak($params) {
		global $wp_optimize_premium;
		if (!is_a($wp_optimize_premium, 'WP_Optimize_Premium') || !property_exists($wp_optimize_premium, 'power_tweaks') || !isset($params['sub_action'])) return array(
			'errors' => array(__('No such command found', 'wp-optimize')),
		);
		
		$action = $params['sub_action'];
		$data = $params['data'] ?: array();
		if (!isset($data['tweak'])) return array(
			'errors' => array(__('No tweak provided', 'wp-optimize'))
		);

		$tweak = sanitize_title($data['tweak']);
		$pt = $wp_optimize_premium->power_tweaks;
		switch($action) {
			case 'activate':
				$result = $pt->activate($tweak);
				break;
			case 'deactivate':
				$result = $pt->deactivate($tweak);
				break;
			case 'run':
				$result = $pt->run($tweak);
				break;
		}
		if ($result && !is_wp_error($result)) {
			return is_array($result) ? array_merge(array('success' => true), $result) : array('success' => true, 'message' => $result);
		} else {
			// translators: %s is an action/command
			$error_message = is_wp_error($result) ? $result->get_error_message() : sprintf(__('The command %s failed', 'wp-optimize'), $action);
			return array(
				'success' => false,
				'errors' => array($error_message)
			);
		}
	}
	
	/**
	 * Ignores the table deletion warning for the current user
	 *
	 * @return array
	 */
	public function user_ignores_table_deletion_warning() {
		return array(
			'success' => update_user_meta(get_current_user_id(), 'wpo-ignores-table-deletion-warning', true)
		);
	}

	/**
	 * Ignores the post meta deletion warning for the current user
	 *
	 * @return array
	 */
	public function user_ignores_post_meta_deletion_warning() {
		return array(
			'success' => update_user_meta(get_current_user_id(), 'wpo-ignores-post-meta-deletion-warning', true)
		);
	}

	/**
	 * Ignores the orphaned relationship data deletion warning for the current user
	 *
	 * @return array
	 */
	public function user_ignores_orphaned_relationship_data_deletion_warning() {
		return array(
			'success' => update_user_meta(get_current_user_id(), 'wpo-ignores-orphaned-relationship-data-deletion-warning', true)
		);
	}

	/**
	 * Exports unused images as a CSV file to the `uploads` folder
	 *
	 * @return array
	 */
	public function export_csv() {
		WP_Optimization_images::instance()->output_csv();
		return array(
			'success' => true
		);
	}

	/**
	 * Build the HTML for the status report tab
	 *
	 * @return array
	 */
	public function generate_status_report() {
		$status_data = $this->get_wp_optimize_status_report_data();

		$html = WP_Optimize()->include_template('status/status-page-ajax.php', true, array(
			'report_data' => $status_data['report_data']
		));
		
		return array(
			'success' => true,
			'html' => $html,
			'replaceable_md_tags' => $status_data['replaceable_md_tags']
		);
	}

	/**
	 * Gz compress text logs using `gzencode` to generate valid gzip files
	 *
	 * @return array
	 */
	public function generate_logs_zip() {
		$wpo_server_information = new WP_Optimize_Server_Information();

		$logs = $wpo_server_information->get_logs();

		$gz_available = function_exists('gzencode');
		$data = array();
		foreach ($logs as $path => $content) {
			if ($gz_available) {
				$log_content = gzencode($content);
				
				if (false === $log_content) {
					$log_content = $content;
					$gz_available = false;
				}
			} else {
				$log_content = $content;
			}
				
			$data[] = array(
				'src' => base64_encode($log_content),
				'name' => basename($path),
				'compressed' => $gz_available
			);
		}

		return array(
			'success' => true,
			'data' => $data
		);
	}
	
	/**
	 * Save images dimensions options
	 *
	 * @param array $settings Settings data
	 * @return array
	 */
	public function save_images_dimension_option($settings) {
		$options = WP_Optimize()->get_options();

		if (!empty($settings["images_dimensions"]) && "true" === $settings["images_dimensions"]) {
			$is_updated = $options->update_option('image_dimensions', 1);
		} else {
			$is_updated = $options->update_option('image_dimensions', 0);
		}

		if ($is_updated || $options->update_option('image_dimensions_ignore_classes', sanitize_text_field($settings['ignore_classes']))) {
			wpo_cache_flush();
		}

		return array(
			'success' => true
		);
	}

	/**
	 * Truncate 404 Logs Table
	 *
	 * @return array
	 */
	public function truncate_404_logs() {
		global $wpdb;
		$log_table_name = WP_Optimize_Table_404_Detector::get_instance()->get_table_name();
		$success = false;
		$message = __('No 404 logs found.', 'wp-optimize');
		// Check if the table has any rows
		$log_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" .esc_sql($log_table_name) . "`");
		if ($log_count > 0) {
			if ($wpdb->query("TRUNCATE TABLE `" .esc_sql($log_table_name) . "`")) {
				$success = true;
				// translators: %s is the number of logs deleted
				$message = sprintf(_n('%s entry deleted from the 404 error log.', '%s entries deleted from the 404 error log.', $log_count, 'wp-optimize'), $log_count);
			} else {
				$message = __('An error occurred while deleting the 404 logs.', 'wp-optimize');
			}
		}

		return array(
			'success' => $success,
			'message' => $message
		);
	}

	// UDC specific endpoints.

	/**
	 * Get multiple widgets data.
	 *
	 * @param array $args Args.
	 *
	 * @return array
	 */
	public function get_widgets_data($args) {
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
			}
		}

		return array('error' => false, 'data' => $data);
	}

	// Helper functions.

	/**
	 * Get the data for tables.
	 *
	 * @return array
	 */
	public function get_table_list_data() {
		$wp_optimize = WP_Optimize();

		$size = $this->optimizer->get_current_db_size();

		$table_list = array();

		// Check for InnoDB tables.
		// Check for windows servers.
		$tablesstatus = $wp_optimize->get_optimizer()->get_tables();
		$is_multisite_mode = $wp_optimize->is_multisite_mode();
		$no = 0;
		$row_usage = 0;
		$data_usage = 0;
		$index_usage = 0;
		$overhead_usage = 0;
		$small_overhead_size = 1048576;

		foreach ($tablesstatus as $tablestatus) {
			$table_info = array();

			$table_info['index'] = ++$no;
			$table_info['table_name'] = $tablestatus->Name;
			$table_info['Engine'] = $tablestatus->Engine;
			$table_info['is_optimizable'] = $tablestatus->is_optimizable ? 1 : 0;
			$table_info['blog_id'] = $tablestatus->blog_id;
			$table_info['plugin_status'] = $tablestatus->plugin_status;
			$table_info['Rows'] = intval($tablestatus->Rows);
			$table_info['Data_length'] = intval($tablestatus->Data_length);
			$table_info['Formatted_Data_length'] = $wp_optimize->format_size($tablestatus->Data_length);
			$table_info['Index_length'] = $tablestatus->Index_length;
			$table_info['Formatted_Index_length'] = $wp_optimize->format_size($tablestatus->Index_length);
			$table_info['Data_free'] = intval($tablestatus->Data_free);
			$table_info['Formatted_Data_free'] = $wp_optimize->format_size($tablestatus->Data_free);
			$table_info['Data_should_be_cleared'] = intval($tablestatus->Data_free) > $small_overhead_size ? 1 : 0;
			$table_info['wp_core_table'] = $tablestatus->wp_core_table;

			$table_list[] = $table_info;

			$row_usage += $tablestatus->Rows;
			$data_usage += $tablestatus->Data_length;
			$index_usage += $tablestatus->Index_length;
			$overhead_usage += $tablestatus->Data_free;
		}

		$db_info = $this->optimizer->get_current_db_size();

		return array(
			'error' => false,
			'data' => array(
				'is_multisite_mode' => $is_multisite_mode,
				'closed_plugins' => array('404-monitor', 'redirections'),
				'database_name' => DB_NAME,
				'database_size' => $db_info[0],
				'optimize_db' => false,
				'table_list_object_format' => $tablesstatus,
				'load_data' => WP_Optimize()->template_should_include_data(),
				'table_list' => $table_list,
				'total_size' => $size[0],
				'small_overhead_size' => $small_overhead_size,
				'no' => $no,
				'no_fomatted' => number_format_i18n($no),
				'row_usage' => number_format_i18n($row_usage),
				'data_usage' => $wp_optimize->format_size($data_usage),
				'index_usage' => $wp_optimize->format_size($index_usage),
				'tables_count' => count($table_list),
				// translators: %s is the number of table(s).
				'formatted_tables_count' => sprintf(_n('%s Table', '%s Tables', count($table_list), 'wp-optimize'), number_format_i18n(count($table_list))),
				'overhead_usage' => $overhead_usage,
				'overhead_usage_formatted' => $wp_optimize->format_size($overhead_usage),
				'translations' => array(
					'database_name' => __('Database Name', 'wp-optimize'),
					'refresh_data' => __('Refresh Data', 'wp-optimize'),
					'refreshing' => __('Refreshing...', 'wp-optimize'),
					'number' => __('No.', 'wp-optimize'),
					'table' => __('Table', 'wp-optimize'),
					'records' => __('Records', 'wp-optimize'),
					'data_size' => __('Data Size', 'wp-optimize'),
					'index_size' => __('Index Size', 'wp-optimize'),
					'type' => __('Type', 'wp-optimize'),
					'overhead' => __('Overhead', 'wp-optimize'),
					'total' => __('Total', 'wp-optimize'),
					'search_tables' => __('Search tables', 'wp-optimize'),
					'total_size_of_database' => __('Total size of database', 'wp-optimize'),
				),
			)
		);
	}

	/**
	 * Get the data of wp-optimize widget.
	 *
	 * @return array
	 */
	private function get_wp_optimize_data() {
		$wp_optimize = WP_Optimize();

		$optimizations = $this->optimizer->sort_optimizations($this->optimizer->get_optimizations());
		$does_server_allow_table_optimization = $wp_optimize->get_server_compatibility_instance()->does_server_allow_table_optimization();

		$optimizations_data = array();

		foreach ($optimizations as $id => $optimization) {
			if ('optimizetables' == $id && false === $does_server_allow_table_optimization) continue;
			// If we don't want to show optimization on the first tab.
			if (false === $optimization->display_in_optimizations_list()) continue;
			// This is an array, with attributes dom_id, activated, settings_label, info; all values are strings.
			$optimization_data = $optimization->get_settings_html();

			$optimization_data['info_raw'] = $optimization_data['info'];

			$optimization_data['info'] = '<div>' . preg_replace('/<a\b[^>]*>(.*?)<\/a>/i', '$1', $optimization_data['info']) . '</div>';

			$optimization_data['disable_optimization_button'] = false;
			$optimization_data['run_sort_order'] = $optimization->get_run_sort_order();

			// Check if the DOM is optimize-db to generate a list of tables.
			if ('optimize-db' == $optimization_data['dom_id']) {
				$table_list = $this->optimizer->get_table_information();

				// Make sure that optimization_table_inno_db is set.
				if ($table_list['inno_db_tables'] > 0 && 0 === $table_list['is_optimizable'] && 0 === $table_list['non_inno_db_tables']) {
					$optimization_data['disable_optimization_button'] = true;
					$optimization_data['activated'] = '';
				}
			}

			$optimization_data['id'] = $id;
			$optimizations_data[] = $optimization_data;
		}

		// Backup checkbox name.
		$backup_checkbox_name = 'enable-auto-backup';

		return array(
			'items' => $optimizations_data,
			'optimize_button_multiple_caption' => __('Optimize selected', 'wp-optimize'),
			'optimize_button_caption' => apply_filters('wpo_run_button_caption', __('Optimize all', 'wp-optimize')),
			'backup_checkbox_name' => $backup_checkbox_name,
			'backup_enabled' => 'true' == $this->options->get_option($backup_checkbox_name, 'false'),
			'backup_checkbox_label' => __('Take backup before optimize', 'wp-optimize'),
		);
	}

	/**
	 * Get wpo status data.
	 *
	 * @return array
	 */
	private function get_status_data() {
		$status_data = $this->get_all_status_data();

		$data = array(
			'statuses' => array(),
			'test' => $status_data,
		);

		// Schedule optimization.
		$schedule_optimization_description = __('Scheduled optimization is currently disabled.', 'wp-optimize');
		$schedule_optimization_icon = 'error';

		if (!empty($status_data['scheduled_optimizations_enabled'])) {
			if ('Never' === $status_data['last_optimized']) {
				$schedule_optimization_description = __('There was no scheduled optimization.', 'wp-optimize');
				$schedule_optimization_icon = 'warning';
			} else {
				$lastopt = $status_data['last_optimized'];
				if (is_numeric($status_data['last_optimized'])) {
					$lastopt = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $status_data['last_optimized'] + ( get_option('gmt_offset') * HOUR_IN_SECONDS ));
				}
				// translators: %s is the last schedule optimization run date.
				$schedule_optimization_description = sprintf(esc_html__('Last scheduled optimization was at %s', 'wp-optimize'), esc_html($lastopt));
				$schedule_optimization_icon = 'success';
			}
		}

		$data['statuses'][] = array(
			'title' => __('Schedule Optimization', 'wp-optimize'),
			'description' => $schedule_optimization_description,
			'icon' => $schedule_optimization_icon,
		);

		// Schedule cleaning.
		$data['statuses'][] = array(
			'title' => __('Schedule Cleaning', 'wp-optimize'),
			'description' => empty($status_data['scheduled_optimizations_enabled']) ? __('Scheduled cleaning is currently disabled.', 'wp-optimize') : __('Scheduled cleaning is enabled.', 'wp-optimize'),
			'icon' => empty($status_data['scheduled_optimizations_enabled']) ? 'warning' : 'success',
		);

		// Data retention.
		$data['statuses'][] = array(
			'title' => __('Data Retention', 'wp-optimize'),
			'description' => empty($status_data['retention_enabled']) ? __('The system is not keeping recent data or any revisions.', 'wp-optimize') : __('Data retention enabled.', 'wp-optimize'),
			'icon' => empty($status_data['retention_enabled']) ? 'error' : 'success',
		);

		return $data;
	}

	/**
	 * Get all status data.
	 *
	 * @return array
	 */
	private function get_all_status_data() {
		$wp_optimize = WP_Optimize();

		$retention_enabled = $this->options->get_option('retention-enabled', 'false') === 'true';
		$retention_period = $this->options->get_option('retention-period', '2');
		$admin_page_url = $this->options->admin_page_url();

		$revisions_retention_enabled = $this->options->get_option('revisions-retention-enabled', 'false') === 'true';
		$revisions_retention_count = $this->options->get_option('revisions-retention-count', '2');

		$last_optimized = $this->options->get_option('last-optimized', 'Never');

		if (!empty($last_optimized) && is_numeric($last_optimized)) {
			$last_optimized = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_optimized + (get_option('gmt_offset') * HOUR_IN_SECONDS));
		}

		$scheduled_optimizations_enabled = false;

		if (WP_Optimize::is_premium()) {
			$wp_optimize_premium = WP_Optimize_Premium();
			$scheduled_optimizations = $wp_optimize_premium->get_scheduled_optimizations();

			if (!empty($scheduled_optimizations)) {
				foreach ($scheduled_optimizations as $optimization) {
					if (isset($optimization['status']) && 1 === absint($optimization['status'])) {
						$scheduled_optimizations_enabled = true;
						break;
					}
				}
			}
		} else {
			$scheduled_optimizations_enabled = $this->options->get_option('schedule', 'false') == 'true';
		}

		$total_cleaned = null;
		$total_cleanup_size = '';
		$corrupted_tables_count = null;
		$next_optimization_timestamp = 0;

		if ($scheduled_optimizations_enabled) {

			$next_optimization_timestamp = wp_next_scheduled('wpo_cron_event2');
			$filtered_next_optimization_timestamp = apply_filters('wpo_cron_next_event', $next_optimization_timestamp);
			$next_optimization_timestamp = is_int($filtered_next_optimization_timestamp) ? $filtered_next_optimization_timestamp : $next_optimization_timestamp;

			if ($next_optimization_timestamp) {

				$next_optimization_timestamp = $next_optimization_timestamp + 60 * 60 * get_option('gmt_offset');

				$wp_optimize->cron_activate();
			}

			$total_cleaned = $this->options->get_option('total-cleaned');
			$total_cleaned_num = floatval($total_cleaned);

			if ($total_cleaned_num > 0) {
				$total_cleanup_size = $wp_optimize->format_size($total_cleaned);
			}

			$corrupted_tables_count = $this->options->get_option('corrupted-tables-count', 0);
		}

		return array(
			'last_optimized' => $last_optimized,
			'total_cleaned' => $total_cleaned,
			'total_cleanup_size' => $total_cleanup_size,
			'corrupted_tables_count' => $corrupted_tables_count,
			'admin_page_url' => $admin_page_url,
			'retention_enabled' => $retention_enabled,
			'retention_period' => $retention_period,
			'revisions_retention_enabled' => $revisions_retention_enabled,
			'revisions_retention_count' => $revisions_retention_count,
			'scheduled_optimizations_enabled' => $scheduled_optimizations_enabled,
			'next_optimization_timestamp' => $next_optimization_timestamp,
		);
	}

	/**
	 * Get wp-optimize tab top nav data.
	 *
	 * @return array
	 */
	private function get_wp_optimize_top_nav_data() {
		return array(
			'title' => __('Performance Overview', 'wp-optimize'),
			'table_info' => __('Table Info', 'wp-optimize'),
			'performance_settings' => __('Performance Settings', 'wp-optimize'),
		);
	}

	/**
	 * Get wp-optimize tab top nav settings page data.
	 *
	 * @return array
	 */
	private function get_wp_optimize_settings_top_nav_data() {
		return array(
			'title' => __('Performance Settings', 'wp-optimize'),
			'settings_saved_prompt' => __('Changes saved automatically', 'wp-optimize'),
			'settings_not_saved_prompt' => __('Changes not saved - please fill all the fields', 'wp-optimize'),
		);
	}

	/**
	 * Get if wp-optimize premium is installed.
	 *
	 * @return array
	 */
	private function get_is_premium_data() {
		$is_premium = WP_Optimize::is_premium();
		$upgrade_to_premium_data = array();

		if (false === $is_premium) {
			$upgrade_to_premium_data = array(
				'heading' => __('WP-Optimize premium', 'wp-optimize'),
				'checklist' => array(
					__('Optimize individual tables', 'wp-optimize'),
					__('Sophisticated scheduling', 'wp-optimize'),
					__('Lazy loading', 'wp-optimize'),
					__('Optimization preview', 'wp-optimize'),
					__('More caching options', 'wp-optimize'),
					__('Remove unwanted images', 'wp-optimize'),
					__('Multi language & currency support', 'wp-optimize'),
				),
				'cta' => array(
					'text' => __('Upgrade Now', 'wp-optimize'),
					'url' => 'https://teamupdraft.com/wp-optimize/pricing/',
				),
			);
		}

		return array(
			'is_premium' => $is_premium,
			'upgrade_to_premium_data' => $upgrade_to_premium_data,
		);
	}

	/**
	 * WIP: Get system overview data.
	 *
	 * @return array
	 */
	private function get_system_overview_data() {
		$wp_optimize = WP_Optimize();
		$status_data = $this->get_all_status_data();

		// Data.
		$data = array(
			'data' => array(),
		);

		// Lazy load.
		$lazy_load_settings = $this->options->get_option('lazyload', array(
			'images' => false,
			'iframes' => false,
			'skip_classes' => '',
		));

		$lazy_load_enabled = $lazy_load_settings['images'] || $lazy_load_settings['iframes'];

		if (WP_Optimize::is_premium()) {
			$lazy_load_enabled = $lazy_load_enabled || $lazy_load_settings['backgrounds'];
		}

		$data['data'][] = array(
			'title' => __('Lazy loading', 'wp-optimize'),
			'description' => $lazy_load_enabled ? __('Enabled', 'wp-optimize') : __('Disabled', 'wp-optimize'),
			'status' => $lazy_load_enabled ? 'success' : 'error',
		);

		// Page caching.
		$cache = $wp_optimize->get_page_cache();
		$page_caching_enabled = $cache->is_enabled();
		$data['data'][] = array(
			'title' => __('Page caching', 'wp-optimize'),
			'description' => $page_caching_enabled ? __('Enabled', 'wp-optimize') : __('Disabled', 'wp-optimize'),
			'status' => $page_caching_enabled ? 'success' : 'error',
		);

		// Schedule cleaning.
		$data['data'][] = array(
			'title' => __('Scheduled clean-up', 'wp-optimize'),
			'description' => empty($status_data['scheduled_optimizations_enabled']) ? __('Disabled', 'wp-optimize') : __('Enabled', 'wp-optimize'),
			'status' => empty($status_data['scheduled_optimizations_enabled']) ? 'error' : 'success',
		);

		if (!empty($status_data['scheduled_optimizations_enabled'])) {
			$data['data'][] = array(
				'title' => __('Next scheduled date', 'wp-optimize'),
				'description' => esc_html(gmdate(get_option('date_format') . ' ' . get_option('time_format'), $status_data['next_optimization_timestamp'])),
				'status' => '',
			);
		}

		return $data;
	}

	/**
	 * Get wp-optimize general settings data.
	 *
	 * @return array
	 */
	private function get_wp_optimize_general_settings_data() {
		$retention_period = max((int) $this->options->get_option('retention-period', '2'), 1);
		$retention_enabled = 'true' === $this->options->get_option('retention-enabled');

		$revisions_retention_count = (int) $this->options->get_option('revisions-retention-count', '2');
		$revisions_retention_enabled = 'true' === $this->options->get_option('revisions-retention-enabled');

		$minify_or_cache_enabled = WP_Optimize()->get_page_cache()->is_enabled() || wp_optimize_minify_config()->get('enabled');

		return array(
			'retention_period' => $retention_period,
			'retention_enabled' => $retention_enabled,
			'revisions_retention_count' => $revisions_retention_count,
			'revisions_retention_enabled' => $revisions_retention_enabled,
			'minify_or_cache_enabled' => $minify_or_cache_enabled,
		);
	}

	/**
	 * Get wp-optimize trackback settings data.
	 *
	 * @return array
	 */
	private function get_wp_optimize_trackback_toggle_settings_data() {
		$wp_optimize = WP_Optimize();

		$trackback_action_arr = $this->options->get_option('trackbacks_action', array());

		$enabled = false;
		$last_action_timestamp = '';
		$empty = true;

		if (!empty($trackback_action_arr)) {
			$empty = false;
			$last_action_timestamp = $wp_optimize->format_date_time($trackback_action_arr['timestamp']);
			if ($trackback_action_arr['action']) {
				$enabled = true;
			}
		}

		return array(
			'enabled' => $enabled,
			'last_action_timestamp' => $last_action_timestamp,
			'empty' => $empty
		);
	}

	/**
	 * Get wp-optimize comments settings data.
	 *
	 * @return array
	 */
	private function get_wp_optimize_comments_toggle_settings_data() {
		$wp_optimize = WP_Optimize();

		$comments_action_arr = $this->options->get_option('comments_action', array());

		$enabled = false;
		$last_action_timestamp = '';
		$empty = true;

		if (!empty($comments_action_arr)) {
			$empty = false;
			$last_action_timestamp = $wp_optimize->format_date_time($comments_action_arr['timestamp']);
			if ($comments_action_arr['action']) {
				$enabled = true;
			}
		}

		return array(
			'enabled' => $enabled,
			'last_action_timestamp' => $last_action_timestamp,
			'empty' => $empty
		);
	}

	/**
	 * Get wp-optimize logging settings.
	 *
	 * @return array
	 */
	private function get_wp_optimize_logging_settings_data() {
		$wp_optimize = WP_Optimize();

		$logger_classes_info = $wp_optimize->get_loggers_classes_info();

		$logger_classes = array();
		$logger_classes_fields = array();

		foreach ($logger_classes_info as $logger_class => $logger_class_data) {
			if (empty($logger_class_data['available'])) {
				continue;
			}

			$logger_classes[] = array(
				'value' => $logger_class,
				'name' => $logger_class_data['description'],
			);

			// For now we only have one option for all the fields, therefore taking only one.
			foreach ($logger_class_data['options'] as $name => $value) {
				$logger_class_field_data = array(
					'name' => $name,
					'validator' => '',
					'placeholder' => '',
					'controller_value' => $logger_class,
				);

				if (is_array($value)) {
					$logger_class_field_data['validator'] = isset($value[1]) ? $value[1] : '';
					$logger_class_field_data['placeholder'] = isset($value[0]) ? $value[0] : '';
				} else {
					$logger_class_field_data['placeholder'] = $value;
				}

				$logger_classes_fields[] = $logger_class_field_data;
			}
		}

		$loggers_data = array(
			'loggers_classes_info' => $logger_classes_info,
			'logger_classes' => $logger_classes,
			'logger_classes_fields' => $logger_classes_fields,
			'existing_loggers' => array(),
		);

		$loggers = $wp_optimize->wpo_loggers();

		foreach ($loggers as $logger) {
			$logger_data = array(
				'id' => strtolower(get_class($logger)),
				'status' => ($logger->is_enabled() && $logger->is_available()) ? 'active' : 'inactive',
				'status_text' => ($logger->is_enabled() && $logger->is_available()) ? __('Active', 'wp-optimize') : __('Inactive', 'wp-optimize'),
				'existing_logger_classes' => array(),
				'existing_logger_classes_fields' => array(),
				'description' => $logger->get_description(),
				'options_text' => $logger->get_options_text(),
				'options_list' => array(),
				'is_enabled' => $logger->is_enabled(),
				'is_available' => $logger->is_available()
			);

			$options_list = $logger->get_options_list();
			$options_values = $logger->get_options_values();

			$options = array();

			if (!empty($options_list)) {
				foreach ($options_list as $option_name => $placeholder) {
					// check if settings item defined as array.
					if (is_array($placeholder)) {
						$validate = $placeholder[1];
						$placeholder = $placeholder[0];
					} else {
						$validate = '';
					}

					$data_validate_attr = ('' !== $validate ? 'data-validate="'.esc_attr($validate).'"' : '');

					$options[$option_name] = array(
						'name' => $option_name,
						'placeholder' => $placeholder,
						'value' => $options_values[$option_name],
						'data_validate_attr' => $data_validate_attr,
					);

				}
				$logger_data['options_list'] = $options;
			}

			foreach ($loggers_data['logger_classes'] as $classes) {
				$logger_data['existing_logger_classes'][] = array(
					'value' => $classes['value'],
					'name' => $classes['name'],
					'selected' => $logger_data['id'] === $classes['value'],
				);
			}

			foreach ($loggers_data['logger_classes_fields'] as $field) {
				// If the field has existing value fill it else add as it is.
				if (!empty($options[$field['name']])) {
					$field['value'] = $options[$field['name']]['value'];
					$field['selected'] = true;
					$logger_data['existing_logger_classes_fields'][] = $field;
				} else {
					$logger_data['existing_logger_classes_fields'][] = $field;
				}
			}

			$loggers_data['existing_loggers'][] = $logger_data;
		}

		return $loggers_data;
	}

	/**
	 * Get wp-optimize schedule cleanup settings data.
	 *
	 * @return array
	 */
	private function get_wp_optimize_schedule_cleanup_settings_data() {
		$is_premium = WP_Optimize::is_premium();

		$return_data = array(
			'is_premium' => $is_premium,
			'data' => array(),
		);
		$scheduled_optimizations_enabled = false;
		$last_optimized_timestamp = (int) $this->options->get_option('last-optimized', 0);
		$next_optimization_timestamp = 0;
		$wp_optimize = WP_Optimize();
		$is_udc_request = $wp_optimize->is_updraft_central_request();

		if (!$is_premium) {
			$schedule_enabled = $this->options->get_option('schedule');
			$scheduled_optimizations_enabled = 'true' === $schedule_enabled;
	
			$schedule_type_saved_id = $this->options->get_option('schedule-type', 'wpo_weekly');
	
			// Backwards compatibility:
			if ('wpo_otherweekly' == $schedule_type_saved_id) $schedule_type_saved_id = 'wpo_fortnightly';

			$schedule_types = WP_Optimize::get_schedule_types();

			$schedule_options = array_map(
				function ($value, $key) use ($schedule_type_saved_id) {
					return array(
						'id'       => $key,
						'label'    => $value,
						'selected' => ($key === $schedule_type_saved_id)
					);
				},
				$schedule_types,
				array_keys($schedule_types)
			);
	
			$wpo_auto_options = $this->options->get_option('auto');
	
			$optimizations = $this->optimizer->sort_optimizations($this->optimizer->get_optimizations());
	
			$optimizations_data = array();

			$does_server_allow_table_optimization = $wp_optimize->get_server_compatibility_instance()->does_server_allow_table_optimization();

			foreach ($optimizations as $id => $optimization) {
				if (empty($optimization->available_for_auto)) continue;

				if ('optimizetables' == $id && false === $does_server_allow_table_optimization) continue;
	
				$auto_id = $optimization->get_auto_id();
	
				$auto_dom_id = 'wp-optimize-auto-'.$auto_id;
	
				$setting_activated = (empty($wpo_auto_options[$auto_id]) || 'false' == $wpo_auto_options[$auto_id]) ? false : true;
	
				$optimizations_data[] = array(
					'id' => $auto_id,
					'dom_id' => $auto_dom_id,
					'activated' => $setting_activated,
					'label' => $optimization->get_auto_option_description(),
				);
			}

			$return_data['data'] = array(
				'schedule_options' => $schedule_options,
				'schedule_type_saved_id' => $schedule_type_saved_id,
				'optimizations' => $optimizations_data,
				'premium_version_link' => $wp_optimize->premium_version_link,
				'enable_schedule' => $schedule_enabled,
			);
		} else {
			$wp_optimize_premium = WP_Optimize_Premium();
			$auto_options = $wp_optimize_premium->get_scheduled_optimizations();
			$auto_optimizations = $wp_optimize_premium->get_auto_optimizations();

			if (!empty($auto_options)) {
				foreach ($auto_options as $optimization) {
					if (isset($optimization['status']) && 1 == $optimization['status']) {
						$scheduled_optimizations_enabled = true;
						break;
					}
				}
			}

			$all_provided_optimizations = array_values($auto_optimizations);
			$all_schedule_types = WP_Optimize::get_schedule_types();
			$week_days = WP_Optimize_Premium::get_week_days();
			$days = WP_Optimize_Premium::get_days();
			$weeks = array('1st' => __('1st', 'wp-optimize'), '2nd' => __('2nd', 'wp-optimize'));

			$optimizations = array();

			foreach ($auto_options as $index => $event) {
				$optimization = array(
					'index' => $index,
				);
				if (!isset($event['optimization'])) {
					$event['optimization'] = array();
				}
				foreach ($event as $event_key => $value) {
					switch ($event_key) {
						case 'optimization':
							$optimization['optimization'] = array();
							foreach ($all_provided_optimizations as $this_optimization) {
								if (in_array($this_optimization['id'], $value)) {
									$this_optimization['selected'] = true;
								}
								$optimization['optimization'][] = $this_optimization;
							}
							break;
						case 'schedule_type':
							$optimization['schedule_types'] = array();
							foreach ($all_schedule_types as $key => $this_schedule_value) {
								$this_schedule_type = array();
								$this_schedule_type['label'] = $this_schedule_value;
								$this_schedule_type['selected'] = $key === $value;
								$this_schedule_type['value'] = $key;
								$optimization['schedule_types'][] = $this_schedule_type;
							}
							break;
						case 'date':
							$optimization['date'] = $value;
							break;
						case 'time':
							$optimization['time'] = $value;
							break;
						case 'week':
							$optimization['weeks'] = array();
							foreach ($weeks as $key => $this_week_value) {
								$this_week = array();
								$this_week['label'] = $this_week_value;
								$this_week['selected'] = absint($key) === absint($value);
								$this_week['value'] = $key;
								$optimization['weeks'][] = $this_week;
							}
							break;
						case 'day':
							$optimization['days'] = array();
							foreach ($week_days as $key => $this_week_day_value) {
								$this_week_day = array();
								$this_week_day['label'] = $this_week_day_value;
								$this_week_day['selected'] = absint($key) === absint($value);
								$this_week_day['value'] = $key;
								$optimization['days'][] = $this_week_day;
							}
							break;
						case 'day_number':
							$optimization['day_numbers'] = array();
							foreach ($days as $key => $this_day_value) {
								$this_day = array();
								$this_day['label'] = $this_day_value;
								$this_day['selected'] = absint($key) === absint($value);
								$this_day['value'] = $key;
								$optimization['day_numbers'][] = $this_day;
							}
							break;
						case 'status':
							$optimization['status'] = '1' === $value;
							break;
					}
				}
				$optimizations[] = $optimization;
			}

			$return_data['data'] = array(
				'optimizations' => $optimizations,
				'provided_optimizations' => $all_provided_optimizations,
				'provided_schedule_types' => $all_schedule_types,
				'today_date' => gmdate("Y-m-d"),
				'weeks' => $weeks,
				'week_days' => $week_days,
				'days' => $days,
				'is_auto_innodb' => $this->options->get_option('auto-innodb'),
				'scheduled_optimizations' => $auto_options,
				'auto_optimizations' => $auto_optimizations,
				'warning_url' => $wp_optimize->wp_optimize_url('https://teamupdraft.com/documentation/wp-optimize/topics/database-optimization/faqs/', __('Warning: you should read the FAQ about the risks of this operation first.', 'wp-optimize'), '', '', true)
			);
		}

		$return_data['data']['enabled'] = $scheduled_optimizations_enabled;
		$return_data['data']['next_optimization'] = '';

		if ($scheduled_optimizations_enabled) {
			$next_optimization_timestamp = apply_filters('wpo_cron_next_event', wp_next_scheduled('wpo_cron_event2'));

			if ($next_optimization_timestamp) {
				$return_data['data']['next_optimization'] = $is_udc_request ? (int) $next_optimization_timestamp : esc_html(gmdate(get_option('date_format') . ' ' . get_option('time_format'), $next_optimization_timestamp + 60 * 60 * get_option('gmt_offset')));
			}
		}

		if ($is_udc_request && $last_optimized_timestamp) {
			$return_data['data']['last_optimized'] = (int) $last_optimized_timestamp;
		}

		// Add the translations.
		$return_data['data']['translations'] = array(
			'scheduled_optimization' => __('Scheduled Optimization', 'wp-optimize'),
			'next_schedule_run' => __('Next scheduled run', 'wp-optimize'),
			'no_scheduled_optimization' => __('No scheduled optimization', 'wp-optimize'),
			'add_optimization_tasks' => __('Add optimization tasks', 'wp-optimize'),
			'select_all' => __('Select all', 'wp-optimize'),
			'unselect_all' => __('Un-Select All', 'wp-optimize'),
			'select_schedule' => __('Select schedule', 'wp-optimize'),
			'run_an_optimization_every' => __('Run an optimization every', 'wp-optimize'),
			'add_optimization' => __('Add optimization', 'wp-optimize'),
		);

		return $return_data;
	}

	/**
	 * Get wp-optimize status report data.
	 *
	 * @return array
	 */
	private function get_wp_optimize_status_report_data() {
		$system_status = WP_Optimize_System_Status_Report::get_instance();
		$report_data = $system_status->generate_report();
		$replaceable_md_tags = $system_status->get_replaceable_md_tags();

		return array('report_data' => $report_data, 'replaceable_md_tags' => $replaceable_md_tags);
	}

	/**
	 * Get data for the UDC reports.
	 *
	 * @return array
	 */
	public function get_report_data() {
		$wp_optimize = WP_Optimize();
		$schedule_types = WP_Optimize::get_schedule_types();
		$scheduled_optimizations_enabled = $this->options->get_option('schedule', 'false') == 'true';
		$schedule_frequency = '';
		$unused_posts_images = 0;
		$optimized_images_percentage = 0;

		if (WP_Optimize::is_premium()) {
			$wp_optimize_premium = WP_Optimize_Premium();
			$scheduled_optimizations = $wp_optimize_premium->get_scheduled_optimizations();

			if (!empty($scheduled_optimizations)) {
				foreach ($scheduled_optimizations as $optimization) {
					if (isset($optimization['status']) && 1 === absint($optimization['status'])) {
						$scheduled_optimizations_enabled = true;
						$schedule_frequency = $schedule_types[$optimization['schedule_type']] ?? '';
						break;
					}
				}
			}

			// Force calculate unused images.
			WP_Optimization_images::instance()->get_optimization_info();
			$unused_posts_images = WP_Optimization_images::instance()->get_unused_images_count();
			$optimized_images_percentage = WP_Optimize_Bulk_Smush::get_instance()->get_optimized_images_percentage();

		} elseif ($scheduled_optimizations_enabled) {
			$schedule_type_saved_id = $this->options->get_option('schedule-type', 'wpo_weekly');

			// Backwards compatibility:
			if ('wpo_otherweekly' == $schedule_type_saved_id) $schedule_type_saved_id = 'wpo_fortnightly';

			$schedule_frequency = $schedule_types[$schedule_type_saved_id] ?? '';
		}

		$total_cleaned = $this->options->get_option('total-cleaned-previous-month');
		$total_cleanup_size = $wp_optimize->format_size($total_cleaned);

		$cache = $wp_optimize->get_page_cache();
		$page_caching_enabled = $cache->is_enabled();

		$minification_enabled = wp_optimize_minify_config()->get('enabled') ?? false;

		return array(
			'report_data' => array(
				'is_premium' => WP_Optimize::is_premium(),
				'scheduled_optimizations_enabled' => $scheduled_optimizations_enabled,
				'schedule_frequency' => $schedule_frequency,
				'total_cleaned' => $total_cleaned,
				'total_cleanup_size' => $total_cleanup_size,
				'unused_posts_images' => $unused_posts_images,
				'page_caching_enabled' => $page_caching_enabled,
				'minification_enabled' => $minification_enabled,
				'optimized_images_percentage' => $optimized_images_percentage . '%',
			),
			'variables' => array(
				/* translators: %s is the cleaned up memory size. */
				'performance_highlights' => sprintf(__('%s saved', 'wp-optimize'), $total_cleanup_size),
			),
		);
	}
}
