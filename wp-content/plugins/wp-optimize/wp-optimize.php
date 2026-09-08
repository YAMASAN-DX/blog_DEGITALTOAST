<?php
/**
Plugin Name: WP-Optimize - Clean, Compress, Cache
Plugin URI: https://teamupdraft.com/wp-optimize
Description: WP-Optimize makes your site fast and efficient. It cleans the database, compresses images and caches pages. Fast sites attract more traffic and users.
Version: 4.6.1
Requires at least: 4.9
Requires PHP: 7.2
Update URI: https://wordpress.org/plugins/wp-optimize/
Author: TeamUpdraft, DavidAnderson
Author URI: https://teamupdraft.com/wp-optimize?utm_source=wpo-wp-dir&utm_medium=referral&utm_campaign=plugin-dir&utm_content=teamupdraft&utm_creative_format=author
Text Domain: wp-optimize
Domain Path: /languages
License: GPLv2 or later
 */

if (!defined('ABSPATH')) die('No direct access allowed');

// Check to make sure if WP_Optimize is already call and returns.
if (!class_exists('WP_Optimize')) :
define('WPO_VERSION', '4.6.1');
define('WPO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPO_PLUGIN_MAIN_PATH', plugin_dir_path(__FILE__));
define('WPO_PLUGIN_SLUG', plugin_basename(__FILE__));
define('WPO_PREMIUM_NOTIFICATION', false);
define('WPO_REQUIRED_PHP_VERSION', '7.2');
define('WPO_REQUIRED_WP_VERSION', '4.9');
if (!defined('WPO_USE_WEBP_CONVERSION')) define('WPO_USE_WEBP_CONVERSION', true);
require_once(WPO_PLUGIN_MAIN_PATH.'includes/fragments/input-processing.php');

class WP_Optimize {

	public $premium_version_link = 'https://teamupdraft.com/wp-optimize/pricing/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_creative_format=overlay';

	private $template_directories;

	protected static $_instance = null;

	protected $_cache_init_status = null;

	/**
	 * An array of schedule types
	 *
	 * @param boolean $placeholder Determines whether a placeholder select option should be included or not
	 *
	 * @return array An array of schedule types
	 */
	public static function get_schedule_types($placeholder = false) {
		$schedule_types = array(
			'wpo_daily' => __('Daily', 'wp-optimize'),
			'wpo_weekly' => __('Weekly', 'wp-optimize'),
			'wpo_fortnightly' => __('Fortnightly', 'wp-optimize'),
			'wpo_monthly' => __('Monthly', 'wp-optimize'),
		);

		$schedule_types = apply_filters('wp_optimize_schedule_types', $schedule_types);
		if (true === $placeholder) {
			$schedule_types = array_merge(array('' => __('Select schedule', 'wp-optimize')), $schedule_types);
		}
		return $schedule_types;
	}

	/**
	 * Class constructor
	 */
	public function __construct() {

		spl_autoload_register(array($this, 'loader'));

		// Don't process when accessing without normal WordPress core loading.
		if (!defined('WPINC')) return;

		$bypass_instance = $this->get_bypass_instance();
		if ($bypass_instance->should_bypass()) {
			// Show a bypass notice in the admin bar
			$bypass_instance->show_admin_notice();
			if (!headers_sent()) {
				header('WPO-Bypass-Mode: active');
			}
			return;
		}

		// Checks if premium is installed along with plugins needed.
		add_action('plugins_loaded', array($this, 'plugins_loaded'), 1);
		
		register_activation_hook(__FILE__, array('WPO_Activation', 'actions'));
		register_deactivation_hook(__FILE__, array('WPO_Deactivation', 'actions'));
		register_uninstall_hook(__FILE__, array('WPO_Uninstall', 'actions'));

		WPO_Page_Optimizer::instance()->maybe_initialise();

		$this->load_admin();
		add_action('admin_init', array($this, 'admin_init'));

		add_filter("plugin_action_links_".plugin_basename(__FILE__), array($this, 'plugin_settings_link'));
		add_action('wpo_cron_event2', array($this, 'cron_action'));
		add_filter('cron_schedules', array($this, 'cron_schedules'));

		if (!$this->get_options()->get_option('installed-for', false)) $this->get_options()->update_option('installed-for', time());

		add_action('admin_footer-upload.php', array($this, 'add_smush_popup_template'));

		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

		add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));

		if ($this->get_options()->get_option('404_detector', 0)) {
			WP_Optimize_Performance::get_instance($this->get_404_detector())->hook_404_handler();
		}

		$this->load_ajax_handler();

		// Show update to Premium notice for non-premium multisite.
		add_action('wpo_additional_options', array($this, 'show_multisite_update_to_premium_notice'));

		// Action column (show repair button, if needed).
		add_filter('wpo_tables_list_additional_column_data', array($this, 'tables_list_additional_column_data'), 15, 2);

		/**
		 * Add action for display Images > Compress images tab.
		 */
		add_action('wp_optimize_admin_page_wpo_images_smush', array($this, 'admin_page_wpo_images_smush'));

		include_once(WPO_PLUGIN_MAIN_PATH.'includes/updraftcentral/updraftcentral.php');

		include_once(WPO_PLUGIN_MAIN_PATH.'includes/backward-compatibility-functions.php');
				
		register_shutdown_function(array($this, 'log_fatal_errors'));

		add_action('wpo_admin_before_closing_wrap', array($this, 'load_modal_template'), 20);

		add_action('upgrader_process_complete', array($this, 'detect_active_plugins_and_themes_updates'), 10, 2);

		$import_done_hooks = array(
			'import_end', // WordPress importer
			'pmxi_after_xml_import', // wp all import
		);

		$db_update_hooks = apply_filters('wp_optimize_db_update_hooks', $import_done_hooks);

		foreach ($db_update_hooks as $hook) {
			add_action($hook, array($this, 'maybe_schedule_update_record_count_event'));
		}
		add_action('wpo_update_record_count_event', array($this->get_db_info(), 'wpo_update_record_count'));

		add_action('wpo_reset_stats_counter', array($this, 'reset_stats_counters'));

		$this->schedule_reset_stats_counters_event();

	}

	/**
	 * Reset stats counter at the start of each month
	 */
	public function reset_stats_counters() {
		// Save current total cleaned as previous month data
		$options = $this->get_options();
		$last_month_total_cleaned = $options->get_option('total-cleaned-current-month');
		$options->update_option('total-cleaned-previous-month', $last_month_total_cleaned);
		$options->update_option('total-cleaned-current-month', '0');
		$this->schedule_reset_stats_counters_event();
	}

	/**
	 * Schedule the reset of stat's counter at the start of each month
	 */
	private function schedule_reset_stats_counters_event() {
		if (!wp_next_scheduled('wpo_reset_stats_counter')) {
			$next_reset = strtotime('first day of next month midnight');
			wp_schedule_single_event($next_reset, 'wpo_reset_stats_counter');
		}
	}

	/**
	 * Returns Onboarding class instance
	 *
	 * @return WPO_Onboarding
	 */
	public function get_onboarding() {
		return WPO_Onboarding::instance();
	}

	/**
	 * Check if minimum requirements for WP-Optimize is met or not.
	 *
	 * @return bool
	 */
	public function is_minimum_requirement_met() {
		global $wp_version;
		return (version_compare(WPO_REQUIRED_PHP_VERSION, PHP_VERSION, '<=') && version_compare(WPO_REQUIRED_WP_VERSION, $wp_version, '<='));
	}

	/**
	 * Add admin notice about minimum server requirements.
	 */
	public function add_notice_minimum_requirements_not_met() {
		add_action('admin_notices', array($this, 'output_minimum_requirements_notice'));
	}

	/**
	 * Deactivate the plugin programmatically
	 */
	public function deactivate_plugin() {
		if (!function_exists('deactivate_plugins')) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		deactivate_plugins(plugin_basename(__FILE__));
	}

	/**
	 * Die after stating minimum requirements
	 */
	public function die_minimum_requirement_not_met() {
		$message = $this->get_minimum_requirements_notice_message();
		$message .= ' <a href="'.esc_url(admin_url('plugins.php')).'">'.esc_html__('Back to Plugins.', 'wp-optimize').'</a>';
		wp_die(wp_kses_post($message));
	}

	/**
	 * Autoloads classes.
	 *
	 * @param string $class_name The name of the class.
	 */
	public function loader($class_name) {
		$dirs = $this->get_class_directories();

		foreach ($dirs as $dir) {
			$class_file = WPO_PLUGIN_MAIN_PATH . trailingslashit($dir) . 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';
			if (file_exists($class_file)) {
				require_once($class_file);
				return;
			}

			$interface_file = WPO_PLUGIN_MAIN_PATH . trailingslashit($dir) . 'interface-' . str_replace('_', '-', strtolower($class_name)) . '.php';
			if (file_exists($interface_file)) {
				require_once($interface_file);
				return;
			}
		}

		// Include PHP Minify - https://github.com/matthiasmullie/minify
		if (strpos($class_name, 'MatthiasMullie') !== false) {
			$class_name_parts = explode('\\', $class_name);
			$class_file = WPO_PLUGIN_MAIN_PATH.'vendor/'.strtolower($class_name_parts[0]).'/'.strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name_parts[1])).'/src/'.implode('/', array_slice($class_name_parts, 2)).'.php';
			if (file_exists($class_file)) {
				require_once($class_file);
				return;
			}
		}
		
		if ('Minify_HTML' === $class_name) {
			require_once WPO_PLUGIN_MAIN_PATH.'vendor/mrclay/minify/lib/Minify/HTML.php';
		}
	}

	/**
	 * Returns an array of class directories
	 *
	 * @return array
	 */
	private function get_class_directories() {
		return array(
			'cache',
			'compatibility',
			'includes',
			'includes/tables',
			'includes/list-tables',
			'includes/gravatars',
			'includes/lcp',
			'minify',
			'optimizations',
			'webp',
			'includes/updraftcentral',
		);
	}

	/**
	 * Initialize Admin class to load admin UI
	 */
	private function load_admin() {
		$this->get_admin_instance();
	}

	/**
	 * Returns Bypass class instance
	 *
	 * @return WP_Optimize_Bypass
	 */
	private function get_bypass_instance() {
		return WP_Optimize_Bypass::instance();
	}

	/**
	 * Returns Admin class instance
	 *
	 * @return WP_Optimize_Admin
	 */
	public function get_admin_instance() {
		return WP_Optimize_Admin::instance();
	}
	
	/**
	 * Detect when an active plugin or theme is updated, and trigger an action
	 *
	 * @param object $upgrader_object
	 * @param array  $options
	 * @return void
	 */
	public function detect_active_plugins_and_themes_updates($upgrader_object, $options) {
		if (empty($options) || !isset($options['type'])) return;
		
		$should_purge_cache = false;
		$skin = $upgrader_object->skin;
		if ('plugin' === $options['type']) {
			// A plugin is updated using the default update system (upgrader_overwrote_package is used for the upload method)
			$plugins = array();
			if (!empty($options['plugin'])) {
				$plugins = array($options['plugin']);
			} elseif (!empty($options['plugins']) && is_array($options['plugins'])) {
				$plugins = $options['plugins'];
			}

			foreach ($plugins as $plugin) {
				if (!empty($plugin) && ! is_wp_error($upgrader_object->result)) {
					if (is_plugin_active($plugin)) {
						$should_purge_cache = true;
						break;
					}
				}
			}
		} elseif ('theme' === $options['type']) {
			$active_theme = get_stylesheet();
			$parent_theme = get_template();
			// A theme is updated using the upload system
			if (isset($options['action']) && 'install' === $options['action'] && isset($skin->options['overwrite']) && 'update-theme' === $skin->options['overwrite']) {
				$updated_theme = $upgrader_object->result['destination_name'];
				// Check if the theme is in use
				if ($active_theme === $updated_theme || $parent_theme === $updated_theme) {
					$should_purge_cache = true;
				}
			// A theme is updated using the classic update system
			} elseif (isset($options['action']) && 'update' === $options['action'] && isset($options['themes']) && is_array($options['themes'])) {
				// Check if the theme is in use
				if (in_array($active_theme, $options['themes']) || in_array($parent_theme, $options['themes'])) {
					$should_purge_cache = true;
				}
			}
		}

		/**
		 * Action executed when an active theme or plugin was updated
		 */
		if ($should_purge_cache) do_action('wpo_active_plugin_or_theme_updated');

	}

	/**
	 * Sets a flag to indicate an import action is done, if needed
	 */
	public function maybe_schedule_update_record_count_event() {
		if (!wp_next_scheduled('wpo_update_record_count_event')) {
			wp_schedule_single_event(time() + 60, 'wpo_update_record_count_event');
		}
	}
			
	public function admin_page_wpo_images_smush() {
		$options = Updraft_Smush_Manager()->get_smush_options();
		$custom = 90 >= $options['image_quality'] && 65 <= $options['image_quality'];
		$sites = $this->get_sites();
		$compression_server_hint = Updraft_Smush_Manager()->get_compression_server_hint();
		$this->include_template('images/smush.php', false, array('smush_options' => $options, 'custom' => $custom, 'sites' => $sites, 'does_server_allow_local_webp_conversion' => $this->get_server_compatibility_instance()->does_server_allow_local_webp_conversion(), 'compression_server_hint' => $compression_server_hint));
		$this->add_smush_popup_template();
	}

	public static function instance() {
		if (empty(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function get_optimizer() {
		return WP_Optimizer::instance();
	}

	/**
	 * Adds 3rd party plugin compatibilities.
	 */
	public function load_compatibilities() {

		if (class_exists('Polylang')) {
			WPO_Polylang_Compatibility::instance();
		}

		WPO_Page_Builder_Compatibility::instance();
		WPO_KD_Submissions_Compatibility::instance();

		if (class_exists('Custom_Permalinks')) {
			WPO_Custom_Permalink_Compatibility::instance();
		}

		if (class_exists('TRP_Translate_Press')) {
			WPO_TranslatePress_Compatibility::instance();
		}

		do_action('wpo_load_compatibilities');
	}

	/**
	 * Get and instantiate WP_Optimize_Minify
	 *
	 * @return WP_Optimize_Minify
	 */
	public function get_minify() {
		return WP_Optimize_Minify::instance();
	}

	public function get_options() {
		return WP_Optimize_Options::instance();
	}

	public function get_notices() {
		return WP_Optimize_Notices::instance();
	}

	/**
	 * Get and instantiate WP_Optimize_Delay_JS
	 *
	 * @return WP_Optimize_Delay_JS
	 */
	private function get_delay_js() {
		return WP_Optimize_Delay_JS::instance();
	}

	/**
	 * Returns instance of WPO_Page_Optimizer class.
	 *
	 * @return WPO_Page_Optimizer
	 */
	public function get_page_optimizer() {
		return WPO_Page_Optimizer::instance();
	}

	/**
	 * Returns instance 404 Detector Cron class.
	 *
	 * @return WP_Optimize_404_Detector_Cron
	 */
	private function get_404_detector_cron() {
		return WP_Optimize_404_Detector_Cron::get_instance();
	}

	/**
	 * Returns instance of WPO_Page_Cache class.
	 *
	 * @return WPO_Page_Cache
	 */
	public function get_page_cache() {
		return WPO_Page_Cache::instance();
	}

	/**
	 * Returns instance of WP_Optimize_WebP class.
	 *
	 * @return WP_Optimize_WebP
	 */
	public function get_webp_instance() {
		return WP_Optimize_WebP::get_instance();
	}

	/**
	 * Returns instance of WP_Optimize_Server_Compatibility class.
	 *
	 * @return WP_Optimize_Server_Compatibility
	 */
	public function get_server_compatibility_instance(): WP_Optimize_Server_Compatibility {
		return WP_Optimize_Server_Compatibility::get_instance();
	}

	/**
	 * Create instance of WP_Optimize_Browser_Cache.
	 *
	 * @return WP_Optimize_Browser_Cache
	 */
	public function get_browser_cache() {
		return WP_Optimize_Browser_Cache::instance();
	}

	/**
	 * Returns WP_Optimize_Database_Information instance.
	 *
	 * @return WP_Optimize_Database_Information
	 */
	public function get_db_info() {
		return WP_Optimize_Database_Information::instance();
	}

	/**
	 * Returns instance of WP_Optimize_Gzip_Compression.
	 *
	 * @return WP_Optimize_Gzip_Compression
	 */
	public function get_gzip_compression() {
		return WP_Optimize_Gzip_Compression::instance();
	}

	/**
	 * Create instance of WP_Optimize_Htaccess.
	 *
	 * @param string $htaccess_file absolute path to htaccess file, by default it use .htaccess in WordPress root directory.
	 * @return WP_Optimize_Htaccess
	 */
	public static function get_htaccess($htaccess_file = '') {
		return new WP_Optimize_Htaccess($htaccess_file);
	}

	/**
	 * Return instance of Updraft_Logger
	 *
	 * @return Updraft_Logger
	 */
	public function get_logger() {
		return Updraft_Logger::instance();
	}

	/**
	 * Check if the current page belongs to WP-Optimize.
	 *
	 * @return bool
	 */
	public function is_wpo_page() {
		$current_screen = get_current_screen();

		return (bool) preg_match('/wp\-optimize/i', $current_screen->id);
	}

	/**
	 * Enqueue scripts and styles on WP-Optimize pages.
	 */
	public function admin_enqueue_scripts() {
		global $wp_version;
		$enqueue_version = $this->get_enqueue_version();
		$min_or_not = $this->get_min_or_not_string();
		$min_or_not_internal = $this->get_min_or_not_internal_string();

		// Register or enqueue common scripts
		wp_register_script('wp-optimize-send-command', WPO_PLUGIN_URL.'js/send-command'.$min_or_not_internal.'.js', array(), $enqueue_version);
		wp_localize_script('wp-optimize-send-command', 'wp_optimize_send_command_data', array('nonce' => wp_create_nonce('wp-optimize-ajax-nonce')));
		wp_register_script('wp-optimize-block-ui', WPO_PLUGIN_URL.'js/blockUI'.$min_or_not_internal.'.js', array('jquery'), $enqueue_version);
		wp_enqueue_style('wp-optimize-global', WPO_PLUGIN_URL.'css/wp-optimize-global'.$min_or_not_internal.'.css', array(), $enqueue_version);

		// load scripts and styles only on WP-Optimize pages.
		if (!$this->is_wpo_page()) return;

		wp_enqueue_script( 'jquery-ui-tooltip' );
		// Defeat other plugins/themes which dump their jQuery UI CSS onto our settings page
		wp_deregister_style('jquery-ui');
		wp_enqueue_style('jquery-ui', WPO_PLUGIN_URL.'css/jquery-ui.custom' . $min_or_not_internal . '.css', array(), $enqueue_version);


		wp_enqueue_script('select2', WPO_PLUGIN_URL . 'js/select2/select2' . $min_or_not . '.js', array('jquery'), $enqueue_version);
		wp_enqueue_style('select2', WPO_PLUGIN_URL . 'css/select2/select2' . $min_or_not . '.css', array(), $enqueue_version);
				
		wp_enqueue_script('jquery-serialize-json', WPO_PLUGIN_URL.'js/serialize-json/jquery.serializejson'.$min_or_not.'.js', array('jquery'), $enqueue_version);

		wp_register_script('updraft-queue-js', WPO_PLUGIN_URL.'js/queue'.$min_or_not_internal.'.js', array(), $enqueue_version);

		wp_enqueue_script('wp-optimize-modal', WPO_PLUGIN_URL.'js/modal'.$min_or_not_internal.'.js', array('jquery', 'backbone', 'wp-util'), $enqueue_version);
		wp_enqueue_script('wp-optimize-cache-js', WPO_PLUGIN_URL.'js/cache'.$min_or_not_internal.'.js', array('wp-optimize-send-command', 'smush-js', 'wp-optimize-heartbeat-js', 'wp-optimize-block-ui'), $enqueue_version);
		wp_enqueue_script('wp-optimize-admin-js', WPO_PLUGIN_URL.'js/wpoadmin'.$min_or_not_internal.'.js', array('jquery', 'updraft-queue-js', 'wp-optimize-send-command', 'smush-js', 'wp-optimize-modal', 'wp-optimize-cache-js', 'wp-optimize-heartbeat-js'), $enqueue_version);
		wp_enqueue_style('wp-optimize-admin-css', WPO_PLUGIN_URL.'css/wp-optimize-admin'.$min_or_not_internal.'.css', array(), $enqueue_version);
		// Using table sorter to help with organising the DB size on Table Information
		// https://github.com/tofsjonas/sortable/
		wp_enqueue_script('sortable-js', WPO_PLUGIN_URL.'js/sortable/sortable'.$min_or_not.'.js', array('wp-optimize-send-command'), $enqueue_version);
		wp_enqueue_script('sortable-a11y-js', WPO_PLUGIN_URL.'js/sortable/sortable.a11y'.$min_or_not.'.js', array('wp-optimize-send-command'), $enqueue_version);

		wp_enqueue_style('sortable-css', WPO_PLUGIN_URL.'css/sortable/sortable'.$min_or_not_internal.'.css', array(), $enqueue_version);

		$js_variables = $this->wpo_js_translations();
		$js_variables['loggers_classes_info'] = $this->get_loggers_classes_info();
		wp_localize_script('wp-optimize-admin-js', 'wpoptimize', $js_variables);
		
		do_action('wpo_premium_scripts_styles', $min_or_not_internal, $min_or_not, $enqueue_version);

		$status_report_dependencies = array('wp-optimize-admin-js');

		// Only include wp-api-fetch if WP >= 5.0
		if (version_compare($wp_version, '5.0', '>=')) {
			$status_report_dependencies[] = 'wp-api-fetch';
		}

		wp_enqueue_script('wp-optimize-status-report', WPO_PLUGIN_URL.'js/status'.$min_or_not_internal.'.js', $status_report_dependencies, $enqueue_version);

		wp_enqueue_script('js-zip', WPO_PLUGIN_URL.'/js/jszip/jszip' . $min_or_not . '.js', array(), $enqueue_version);

	}

	/**
	 * Enqueue any required front-end scripts
	 *
	 * @return void
	 */
	public function frontend_enqueue_scripts() {
		if (!$this->current_user_can('manage_options') || !is_admin_bar_showing()) return;
		$enqueue_version = $this->get_enqueue_version();
		$min_or_not_internal = $this->get_min_or_not_internal_string();

		// Register or enqueue common scripts
		wp_enqueue_style('wp-optimize-global', WPO_PLUGIN_URL.'css/wp-optimize-global'.$min_or_not_internal.'.css', array(), $enqueue_version);
	}

	/**
	 * Load Task Manager
	 *
	 * @return Updraft_Smush_Manager
	 */
	public function get_task_manager() {
		include_once(WPO_PLUGIN_MAIN_PATH.'vendor/team-updraft/common-libs/src/updraft-tasks/class-updraft-tasks-activation.php');

		Updraft_Tasks_Activation::check_updates();

		include_once(WPO_PLUGIN_MAIN_PATH . 'vendor/team-updraft/common-libs/src/updraft-tasks/class-updraft-task-meta.php');
		include_once(WPO_PLUGIN_MAIN_PATH . 'vendor/team-updraft/common-libs/src/updraft-tasks/class-updraft-task-options.php');
		include_once(WPO_PLUGIN_MAIN_PATH . 'vendor/team-updraft/common-libs/src/updraft-tasks/class-updraft-task.php');
				
		include_once(WPO_PLUGIN_MAIN_PATH . 'includes/class-updraft-smush-task.php');
		include_once(WPO_PLUGIN_MAIN_PATH . 'includes/class-updraft-smush-manager.php');

		return Updraft_Smush_Manager();
	}

	/**
	 * Indicate whether we have an associated instance of WP-Optimize Premium or not.
	 *
	 * @returns bool
	 */
	public static function is_premium() {
		if (file_exists(WPO_PLUGIN_MAIN_PATH.'premium.php') && function_exists('WP_Optimize_Premium')) {
			$wp_optimize_premium = WP_Optimize_Premium();
			if (is_a($wp_optimize_premium, 'WP_Optimize_Premium')) return true;
		}
		return false;
	}

	/**
	 * Check if script running on Apache web server. $is_apache is set in wp-includes/vars.php. Also returns true if the server uses litespeed.
	 *
	 * @return bool
	 */
	public function is_apache_server() {
		global $is_apache;
		return (bool) $is_apache;
	}

	/**
	 * Check if script running on IIS web server.
	 *
	 * @return bool
	 */
	public function is_IIS_server() {
		global $is_IIS, $is_iis7;
		return $is_IIS || $is_iis7;
	}

	/**
	 * Check if Apache module or modules active.
	 *
	 * @param string|array $module - single Apache module name or list of Apache module names.
	 *
	 * @return bool|null - if null, the result was indeterminate
	 */
	public function is_apache_module_loaded($module) {
		if (!$this->is_apache_server()) return false;
				
		if (!function_exists('apache_get_modules')) return null;

		$module_loaded = true;

		if (is_array($module)) {
			foreach ($module as $single_module) {
				if (!in_array($single_module, apache_get_modules())) {
					$module_loaded = false;
					break;
				}
			}
		} else {
			$module_loaded = in_array($module, apache_get_modules());
		}

		return $module_loaded;
	}

	/**
	 * Checks if this is the premium version and loads it. It also ensures that if the free version is installed then it is disabled with an appropriate error message.
	 */
	public function plugins_loaded() {

		if (!$this->is_minimum_requirement_met()) {
			$this->add_notice_minimum_requirements_not_met();
			$this->deactivate_plugin();
			WPO_Deactivation::actions();
			return;
		}

		if (is_admin() && $this->current_user_can()) {
			WP_Optimize_Heartbeat::get_instance();
		}

		if ($this->get_server_compatibility_instance()->does_server_handle_cache()) {
			add_filter('wp_optimize_admin_page_wpo_cache_tabs', array($this, 'filter_cache_tabs'), 99, 1);

			// If newly migrated to a server that handles cache, disable wpo cache
			$cache = $this->get_page_cache();
			if ($cache->is_enabled()) {
				$cache->disable();
			}
		}

		add_filter('robots_txt', array($this, 'robots_txt'), 99, 1);

		// Run Premium loader if it exists
		if (file_exists(WPO_PLUGIN_MAIN_PATH.'premium.php') && !class_exists('WP_Optimize_Premium')) {
			include_once(WPO_PLUGIN_MAIN_PATH.'premium.php');
		}

		if (!self::is_premium()) {
			$this->get_server_compatibility_instance()->maybe_disable_unsupported_table_optimization();
			add_action('auto_option_settings', array($this->get_options(), 'auto_option_settings'));
		}

		// load defaults
		$this->get_options()->set_default_options();

		// Initialize loggers.
		add_action('init', array($this, 'setup_loggers'));

		if ($this->is_active('premium') && false !== ($free_plugin = $this->is_active('free'))) {
			if (!function_exists('deactivate_plugins')) include_once(ABSPATH.'wp-admin/includes/plugin.php');
			deactivate_plugins($free_plugin);

			// If WPO_ADVANCED_CACHE is defined, we empty advanced-cache.php to regenerate later. Otherwise, it contains the path to free.
			if (defined('WPO_ADVANCED_CACHE') && WPO_ADVANCED_CACHE) {
				$advanced_cache_filename = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';

				if (!is_file($advanced_cache_filename) && wp_is_writable(dirname($advanced_cache_filename)) || (is_file($advanced_cache_filename) && wp_is_writable($advanced_cache_filename))) {
					file_put_contents($advanced_cache_filename, '');
				}
			}

					// Registers the notice letting the user know it cannot be active if premium is active.
			add_action('admin_notices', array($this, 'show_admin_notice_premium'));
			return;
		}

		add_action('init', array($this, 'init'));

		// add_filter('updraftcentral_host_plugins', array($this, 'attach_updraftcentral_host'));
		// if (file_exists(WPO_PLUGIN_MAIN_PATH.'central/factory.php')) include_once(WPO_PLUGIN_MAIN_PATH.'central/factory.php');
	}

	/**
	 * Methods needed to be run in init hook.
	 *
	 * @return void
	 */
	public function init() {
		$this->load_language_file();
		$this->schedule_plugin_cron_tasks();
		$this->init_page_cache();
		$this->get_minify();
		$this->get_delay_js();

		// Load 3rd party plugin compatibilities.
		$this->load_compatibilities();

		$this->run_updates();

		// We need this here because webp can be unavailable because of server moves
		// This deletes already converted webp images and original image file when a media is deleted
		WP_Optimize_WebP_Images::get_instance();

		// Loads the task manager
		$this->get_task_manager();

		// Include WebP
		if (WPO_USE_WEBP_CONVERSION) {
			$this->get_webp_instance();
		}

		$this->get_onboarding()->init();
	}

	/**
	 * Loads the language file.
	 *
	 * @return void
	 */
	private function load_language_file() {
		load_plugin_textdomain('wp-optimize', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	/**
	 * Attach this wp-optimize plugin as host of the UpdraftCentral libraries
	 * (e.g. "central" folder)
	 *
	 * @param array $hosts List of plugins having the "central" library integrated into them
	 *
	 * @return array
	 */
	public function attach_updraftcentral_host($hosts) {
		$hosts[] = 'wp-optimize';
		return $hosts;
	}

	/**
	 * Filter cache tabs (when the server handles it or do not allow it, like Kinsta)
	 *
	 * @param  array $tabs An array of tabs
	 *
	 * @return array $tabs An array of tabs
	 */
	public function filter_cache_tabs($tabs) {
		unset($tabs['preload']);
		unset($tabs['advanced']);
		unset($tabs['gzip']);
		unset($tabs['settings']);
		return $tabs;
	}

	/**
	 * Check whether one of free/Premium is active (whether it is this instance or not)
	 *
	 * @param string $which - 'free' or 'premium'
	 *
	 * @return string|boolean - plugin path (if installed) or false if not
	 */
	public function is_active($which = 'free') {
		$active_plugins = $this->get_active_plugins();
		foreach ($active_plugins as $file) {
			if ('wp-optimize.php' == basename($file)) {
				$plugin_dir = WP_PLUGIN_DIR.'/'.dirname($file);
				if (('free' === $which && !file_exists($plugin_dir.'/premium.php')) || ('free' !== $which && file_exists($plugin_dir.'/premium.php'))) return $file;
			}
		}
		return false;
	}

	/**
	 * Gets an array of plugins active on either the current site, or site-wide
	 *
	 * @return array - a list of plugin paths (relative to the plugin directory)
	 */
	private function get_active_plugins() {
		// Gets all active plugins on the current site
		$active_plugins = (array) get_option('active_plugins', array());

		if (is_multisite()) {
			$network_active_plugins = get_site_option('active_sitewide_plugins');
			if (!empty($network_active_plugins)) {
				$network_active_plugins = array_keys($network_active_plugins);
				$active_plugins = array_merge($active_plugins, $network_active_plugins);
			}
		}

		return $active_plugins;
	}

	/**
	 * This function checks whether a specific plugin is installed, and returns information about it
	 *
	 * @param  string $name Specify "Plugin Name" to return details about it.
	 * @return array        Returns an array of details such as if installed, the name of the plugin and if it is active.
	 */
	public function is_installed($name) {

		// Needed to have the 'get_plugins()' function
		include_once(ABSPATH.'wp-admin/includes/plugin.php');

		// Gets all plugins available
		$get_plugins = get_plugins();

		$active_plugins = $this->get_active_plugins();

		$plugin_info = array();
		$plugin_info['installed'] = false;
		$plugin_info['active'] = false;

		// Loops around each plugin available.
		foreach ($get_plugins as $key => $value) {
			// If the plugin name matches that of the specified name, it will gather details.
			if ($value['Name'] !== $name && $value['TextDomain'] !== $name) continue;
			$plugin_info['installed'] = true;
			$plugin_info['name'] = $key;
			$plugin_info['version'] = $value['Version'];
			if (in_array($key, $active_plugins)) {
				$plugin_info['active'] = true;
			}
			break;
		}
		return $plugin_info;
	}

	/**
	 * This is a notice to show users that premium is installed
	 */
	public function show_admin_notice_premium() {
		echo '<div id="wp-optimize-premium-installed-warning" class="error"><p>'.esc_html__('WP-Optimize (Free) has been de-activated, because WP-Optimize Premium is active.', 'wp-optimize').'</p></div>';
		if (isset($_GET['activate'])) unset($_GET['activate']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only unsetting value, not using it.
	}

	/**
	 * Show update to Premium notice for non-premium multisite.
	 */
	public function show_multisite_update_to_premium_notice() {
		if (!is_multisite() || self::is_premium()) return;

		echo '<p><a href="'.esc_url($this->premium_version_link). '&utm_content=multisite-upsell' .'">'.esc_html__('New feature: WP-Optimize Premium can now optimize all sites within a multisite install, not just the main one.', 'wp-optimize').'</a></p>';
	}

	public function admin_init() {
		$pagenow = $GLOBALS['pagenow'];

		$this->register_template_directories();

		if (('index.php' === $pagenow && $this->current_user_can('update_plugins')) || ('index.php' === $pagenow && defined('WP_OPTIMIZE_FORCE_DASHNOTICE') && WP_OPTIMIZE_FORCE_DASHNOTICE)) {
			$options = $this->get_options();

			$dismissed_until = $options->get_option('dismiss_dash_notice_until', 0);

			if (file_exists(WPO_PLUGIN_MAIN_PATH . 'index.html')) {
				$installed = filemtime(WPO_PLUGIN_MAIN_PATH . 'index.html');
				$installed_for = (time() - $installed);
			}

			if (($installed && time() > $dismissed_until && $installed_for > (14 * 86400) && !defined('WP_OPTIMIZE_NOADS_B')) || (defined('WP_OPTIMIZE_FORCE_DASHNOTICE') && WP_OPTIMIZE_FORCE_DASHNOTICE)) {
				add_action('all_admin_notices', array($this, 'show_admin_notice_upgraded'));
			}
		}

		if ($this->is_wp_smush_installed()) {
			add_filter('transient_wp-smush-conflict_check', array($this, 'modify_wp_smush_conflict_check'), 9, 1);
		}
		if ($this->get_options()->get_option('404_detector', 0)) {
			WP_Optimize_Performance::get_instance($this->get_404_detector())->init();
		}
	}

	/**
	 * Checks whether the WP Smush plugin is active or not
	 *
	 * @return bool
	 */
	private function is_wp_smush_installed() {
		return is_plugin_active('wp-smushit/wp-smush.php');
	}

	/**
	 * Remove WPO plugin name from WP Smushit transient value
	 *
	 * @return array $active_plugins
	 */
	public function modify_wp_smush_conflict_check($active_plugins) {
		// This can be boolean value since it is return value of get_transient
		if (!is_array($active_plugins)) return $active_plugins;

		if (false !== ($key = array_search('WP-Optimize - Clean, Compress, Cache', $active_plugins))) {
			unset($active_plugins[$key]);
		}
		return $active_plugins;
	}

	/**
	 * Get the installation or update notice instance
	 *
	 * @return WP_Optimize_Install_Or_Update_Notice
	 */
	public function get_install_or_update_notice() {
		static $instance = null;
		if (is_a($instance, 'WP_Optimize_Install_Or_Update_Notice')) return $instance;
		$instance = new WP_Optimize_Install_Or_Update_Notice();
		return $instance;
	}

	/**
	 * Display an admin notice for an upgraded version.
	 */
	public function show_admin_notice_upgraded() {
		$this->include_template('notices/thanks-for-using-main-dash.php', false, array(
			'is_premium' => WP_Optimize::is_premium()
		));
	}

	/**
	 * Returns required capability string
	 *
	 * @return string
	 */
	public function capability_required() {
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';

		$filtered_capability = apply_filters('wp_optimize_capability_required', $capability);

		return is_string($filtered_capability) ? $filtered_capability : $capability;
	}

	/**
	 * Check if the current user has a given capability.
	 * If no capability is provided, it checks for the default capability required to access WP-Optimize.
	 *
	 * @param string|null $capability The capability to check. Null = use default.
	 * @return bool
	 */
	public function current_user_can($capability = null) {
		if (null === $capability) {
			$capability = $this->capability_required(); // falls back to default
		}

		if (current_user_can($capability)) {
			return true;
		}

		return false;
	}

	/**
	 * Returns array of translations used in javascript code.
	 *
	 * @return array
	 */
	public function wpo_js_translations() {
		$log_message = __('For more details, please check your logs configured in logging destinations settings.', 'wp-optimize');
		$translations = array(
			'automatic_backup_before_optimizations' => __('Automatic backup before optimizations', 'wp-optimize'),
			'error_unexpected_response' => __('An unexpected response was received.', 'wp-optimize'),
			'optimization_complete' => __('Optimization complete', 'wp-optimize'),
			'with_warnings' => __('(with warnings - open the browser console for more details)', 'wp-optimize'),
			'optimizing_table' => __('Optimizing table:', 'wp-optimize'),
			'run_optimizations' => __('Run optimizations', 'wp-optimize'),
			'table_optimization_timeout' => 120000,
			'add' => __('Add', 'wp-optimize'),
			'cancel' => __('Cancel', 'wp-optimize'),
			'cancelling' => __('Cancelling...', 'wp-optimize'),
			'enable' => __('Enable', 'wp-optimize'),
			'disable' => __('Disable', 'wp-optimize'),
			'please_select_settings_file' => __('Please, select settings file.', 'wp-optimize'),
			'are_you_sure_you_want_to_remove_logging_destination' => __('Are you sure you want to remove this logging destination?', 'wp-optimize'),
			'fill_all_settings_fields' => __('Before saving, you need to complete the currently incomplete settings (or remove them).', 'wp-optimize'),
			// translators: %s is the table name
			'table_was_not_repaired' => __('%s was not repaired.', 'wp-optimize') . ' ' . $log_message,
			// translators: %s is the table name
			'table_was_not_deleted' => __('%s was not deleted.', 'wp-optimize') . ' ' . $log_message,
			// translators: %s is the table name
			'table_was_not_converted' => __('%s was not converted to InnoDB.', 'wp-optimize') . ' ' . $log_message,
			'please_use_positive_integers' => __('Please use positive integers.', 'wp-optimize'),
			'please_use_valid_values' => __('Please use valid values.', 'wp-optimize'),
			'update' => __('Update', 'wp-optimize'),
			'run_now' => __('Run now', 'wp-optimize'),
			'starting_preload' => __('Started preload...', 'wp-optimize'),
			'loading_urls' => __('Loading URLs...', 'wp-optimize'),
			'current_cache_size' => __('Current cache size:', 'wp-optimize'),
			'number_of_files' => __('Number of files:', 'wp-optimize'),
			'toggle_info' => __('Show information', 'wp-optimize'),
			'delete_file' => __('Delete', 'wp-optimize'),
			'deleting' => __('Deleting...', 'wp-optimize'),
			'added_to_list' => __('Added to the list', 'wp-optimize'),
			'added_notice' => __('The file was added to the list', 'wp-optimize'),
			'save_notice' => __('Save the changes', 'wp-optimize'),
			'saving' => __('Saving...', 'wp-optimize'),
			'clearing_cache' => __('Clearing cache...', 'wp-optimize'),
			'creating_cache' => __('Creating cache...', 'wp-optimize'),
			'page_refresh' => __('Refreshing the page to reflect changes...', 'wp-optimize'),
			'cache_file_not_found' => __('Cache file was not found.', 'wp-optimize'),
			'settings_have_been_deleted_successfully' => __('WP-Optimize settings have been deleted successfully.', 'wp-optimize'),
			'loading_data' => __('Loading data...', 'wp-optimize'),
			'spinner_src' => esc_url(admin_url('images/spinner-2x.gif')),
			'logo_src' => esc_url(WPO_PLUGIN_URL.'images/notices/wp_optimize_logo.png'),
			'settings_page_url' => is_multisite() ? network_admin_url('admin.php?page=wpo_settings') : admin_url('admin.php?page=wpo_settings'),
			'sites' => $this->get_sites(),
			'user_always_ignores_table_deletion_warning' => (bool) get_user_meta(get_current_user_id(), 'wpo-ignores-table-deletion-warning', true),
			'user_always_ignores_post_meta_deletion_warning' => (bool) get_user_meta(get_current_user_id(), 'wpo-ignores-post-meta-deletion-warning', true),
			'user_always_ignores_orphaned_relationship_data_deletion_warning' => (bool) get_user_meta(get_current_user_id(), 'wpo-ignores-orphaned-relationship-data-deletion-warning', true),
			'post_meta_tweak_completed' => __('The tweak has been performed.', 'wp-optimize'),
			'no_minified_assets' => __('No minified files are present', 'wp-optimize'),
			'network_site_url' => network_site_url(),
			'export_settings_file_name' => 'wpoptimize-settings-'.sanitize_title(get_bloginfo('name')).'.json',
			'import_select_file' => __('You have not yet selected a file to import.', 'wp-optimize'),
			'import_invalid_json_file' => __('Error: The chosen file is corrupt.', 'wp-optimize') . ' ' . __('Please choose a valid WP-Optimize export file.', 'wp-optimize'),
			'importing' => __('Importing...', 'wp-optimize'),
			'importing_data_from' => __('This will import data from:', 'wp-optimize'),
			'exported_on' => __('Which was exported on:', 'wp-optimize'),
			'continue_import' => __('Do you want to carry out the import?', 'wp-optimize'),
			'select_destination' => __('Select destination', 'wp-optimize'),
			'show' => __('Show', 'wp-optimize'),
			'hide' => __('Hide', 'wp-optimize'),
			'please_wait' => __('Please wait a moment...', 'wp-optimize'),
			'clipboard_failed' => __('Copy to clipboard failed, please do it manually', 'wp-optimize'),
			'clipboard_success' => __('System status has been copied to the clipboard', 'wp-optimize'),
			'show_information' => __('Show information', 'wp-optimize'),
			'hide_information' => __('Hide information', 'wp-optimize'),
			'data_not_available' => __('Not available', 'wp-optimize'),
			'something_wrong_try_again' => __('Something went wrong; please try again.', 'wp-optimize')
		);
		$filtered_translations = apply_filters('wpo_js_translations', $translations);
		return is_array($filtered_translations) ? $filtered_translations : $translations;
	}

	/**
	 * Add settings link on plugin page
	 *
	 * @param  array $links Passing through the URL to be used within the HREF.
	 * @return array        Returns the Links.
	 */
	public function plugin_settings_link($links) {

		$admin_page_url = $this->get_options()->admin_page_url();
		$settings_page_url = $this->get_options()->admin_page_url('wpo_settings');

		if (!self::is_premium()) {
			$premium_link = '<a href="' . esc_url($this->premium_version_link) . '&utm_content=plugin-page' . '" target="_blank">' . __('Premium', 'wp-optimize') . '</a>';
			array_unshift($links, $premium_link);
		}

		$settings_link = '<a href="' . esc_url($settings_page_url) . '">' . __('Settings', 'wp-optimize') . '</a>';
		array_unshift($links, $settings_link);

		$optimize_link = '<a href="' . esc_url($admin_page_url) . '">' . __('Optimize', 'wp-optimize') . '</a>';
		array_unshift($links, $optimize_link);
		return $links;
	}

	/**
	 * Action wpo_tables_list_additional_column_data. Output button Optimize in the action column.
	 *
	 * @param string $content    String for output to column
	 * @param object $table_info Object with table info.
	 *
	 * @return string
	 */
	public function tables_list_additional_column_data($content, $table_info) {
		if ($table_info->is_needing_repair) {
			$content .= '<div class="wpo_button_wrap">'
				. '<button class="button button-secondary run-single-table-repair" data-table="' . esc_attr($table_info->Name) . '">' . __('Repair', 'wp-optimize') . '</button>'
				. '<img class="optimization_spinner visibility-hidden" src="' . esc_attr(admin_url('images/spinner-2x.gif')) . '" width="20" height="20" alt="...">' // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- N/A
				. '<span class="optimization_done_icon dashicons dashicons-yes visibility-hidden"></span>'
				. '</div>';
		}

		// table belongs to plugin.
		if ($table_info->can_be_removed) {
			$content .= '<div>'
				. '<button class="button button-secondary run-single-table-delete" data-table="' . esc_attr($table_info->Name) . '">' . __('Remove', 'wp-optimize') . '</button>'
				. '<img class="optimization_spinner visibility-hidden" src="' . esc_attr(admin_url('images/spinner-2x.gif')) . '" width="20" height="20" alt="...">' // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- N/A
				. '<span class="optimization_done_icon dashicons dashicons-yes visibility-hidden"></span>'
				. '</div>';
		}

		// Add option for MyISAM to InnoDB conversion.
		if ('MyISAM' === $table_info->Engine) {
			$content .= '<div class="wpo_button_convert wpo_button_wrap">'
				. '<button class="button button-secondary toinnodb" data-table="' . esc_attr($table_info->Name) . '">' . __('Convert to InnoDB', 'wp-optimize') . '</button>'
				. '<img class="optimization_spinner visibility-hidden" src="' . esc_attr(admin_url('images/spinner-2x.gif')) . '" width="20" height="20" alt="...">' // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- N/A
				. '<span class="optimization_done_icon dashicons dashicons-yes visibility-hidden"></span>'
				. '</div>';
						
		}
				
		return $content;
	}

	/**
	 * Initialize WP-Optimize page cache.
	 */
	private function init_page_cache() {
		if ($this->get_page_cache()->config->get_option('enable_page_caching', false)) {
			if ((!(defined('WP_CLI') && WP_CLI)) && (!defined('DOING_AJAX') || !DOING_AJAX)) {
				$this->_cache_init_status = $this->get_page_cache()->enable();
			}
		}
	}

	/**
	 * Get init_page_cache() status.
	 *
	 * @return bool|WP_Error
	 */
	public function get_init_page_cache_status() {
		return $this->_cache_init_status;
	}

	/**
	 * Schedules cron event based on selected schedule type
	 *
	 * @return void
	 */
	public function cron_activate() {
		$gmt_offset = (int) (3600 * get_option('gmt_offset'));

		$options = $this->get_options();

		if (false === $options->get_option('schedule')) {
			$options->set_default_options();
		} else {
			if ('true' === $options->get_option('schedule')) {
				if (!wp_next_scheduled('wpo_cron_event2')) {
					$schedule_type = $options->get_option('schedule-type', 'wpo_weekly');

					// Backward compatibility
					if ('wpo_otherweekly' === $schedule_type) $schedule_type = 'wpo_fortnightly';

					$this_time = (86400 * 7);

					switch ($schedule_type) {
						case "wpo_daily":
							$this_time = 86400;
							break;

						case "wpo_weekly":
							$this_time = (86400 * 7);
							break;

						case "wpo_fortnightly":
							$this_time = (86400 * 14);
							break;

						case "wpo_monthly":
							$this_time = (86400 * 30);
							break;
					}

					add_action('wpo_cron_event2', array($this, 'cron_action'));
					$result = wp_schedule_event((current_time("timestamp", 0) + $this_time - $gmt_offset), $schedule_type, 'wpo_cron_event2');
					$this->log('running wp_schedule_event()');
					if (is_wp_error($result)) {
						$error_msg = $result->get_error_message();
						$this->log($error_msg);
						$this->log(print_r($result, true)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Using for debugging purpose, logged into separate file
					} else {
						$this->log($result);
					}
				}
			}
		}
	}

	/**
	 * Unschedules WP-Optimize cron events on deactivation.
	 *
	 * @return void
	 */
	public function wpo_cron_deactivate() {
		if (is_multisite()) {
			$sites = $this->get_sites();
			foreach ($sites as $site) {
				switch_to_blog($site->blog_id);
				$this->unschedule_wpo_cron_jobs();
				restore_current_blog();
			}
		} else {
			$this->unschedule_wpo_cron_jobs();
		}
	}

	/**
	 * Unschedules all WP-Optimize cron jobs on the current site.
	 *
	 * @return void
	 */
	private function unschedule_wpo_cron_jobs() {
		$cron_jobs = _get_cron_array();
		foreach ($cron_jobs as $job) {
			foreach (array_keys($job) as $hook) {
				if (preg_match('/^wpo_/', $hook)) wp_unschedule_hook($hook);
			}
		}
	}

	/**
	 * Scheduler public functions to update schedulers
	 *
	 * @param  array $schedules An array of schedules being passed.
	 * @return array            An array of schedules being returned.
	 */
	public function cron_schedules($schedules) {
		$schedules['wpo_daily'] = array('interval' => 86400, 'display' => 'Once Daily');
		$schedules['wpo_weekly'] = array('interval' => 86400 * 7, 'display' => 'Once Weekly');
		$schedules['wpo_fortnightly'] = array('interval' => 86400 * 14, 'display' => 'Once Every Fortnight');
		$schedules['wpo_monthly'] = array('interval' => 86400 * 30, 'display' => 'Once Every Month');
		return $schedules;
	}

	/**
	 * Returns count of overdue cron jobs.
	 *
	 * @return integer
	 */
	public function howmany_overdue_crons() {
		$how_many_overdue = 0;
		if (function_exists('_get_cron_array') || (is_file(ABSPATH.WPINC.'/cron.php') && include_once(ABSPATH.WPINC.'/cron.php') && function_exists('_get_cron_array'))) {
			$crons = _get_cron_array();
			if (is_array($crons)) {
				$timenow = time();
				foreach ($crons as $jt => $job) {
					if ($jt < $timenow) {
						$how_many_overdue++;
					}
				}
			}
		}
		return $how_many_overdue;
	}

	/**
	 * Run updates on plugin activation.
	 */
	public function run_updates() {
		include_once(WPO_PLUGIN_MAIN_PATH.'includes/class-wp-optimize-updates.php');
		WP_Optimize_Updates::check_updates();
	}

	/**
	 * Returns warning about overdue crons.
	 *
	 * @param int $howmany count of overdue crons
	 * @return string
	 */
	public function show_admin_warning_overdue_crons($howmany) {
		$ret = '<div class="updated below-h2"><p>';
		// translators: %d is number of overdue cron jobs
		$ret .= '<strong>'.esc_html__('Warning', 'wp-optimize').':</strong> '.sprintf(esc_html__('WordPress has a number (%d) of scheduled tasks which are overdue.', 'wp-optimize'), $howmany).' '. esc_html__('Unless this is a development site, this probably means that the scheduler in your WordPress install is not working.', 'wp-optimize').' <a target="_blank" href="'.esc_url(apply_filters('wpoptimize_com_link', "https://teamupdraft.com/blog/the-scheduler-in-wordpress-is-not-working-what-should-i-do/")).'">'.esc_html__('Read this page for a guide to possible causes and how to fix it.', 'wp-optimize').'</a>';
		$ret .= '</p></div>';
		return $ret;
	}

	/**
	 * Normalizes path string
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	private function wp_normalize_path($path) {
		// Wp_normalize_path is not present before WP 3.9.
		if (function_exists('wp_normalize_path')) return wp_normalize_path($path);
		// Taken from WP 4.6.
		$path = str_replace('\\', '/', $path);
		$path = preg_replace('|(?<=.)/+|', '/', $path);
		if (':' === substr($path, 1, 1)) {
			$path = ucfirst($path);
		}
		return $path;
	}

	/**
	 * Returns templates directory path
	 *
	 * @return string
	 */
	public function get_templates_dir() {
		$templates_dir = $this->wp_normalize_path(WPO_PLUGIN_MAIN_PATH.'templates');
		$filtered_templates_dir = apply_filters('wp_optimize_templates_dir', $templates_dir);
		return is_string($filtered_templates_dir) ? $filtered_templates_dir : $templates_dir;
	}

	/**
	 * Returns templates URL
	 *
	 * @return string
	 */
	public function get_templates_url() {
		$templates_url = WPO_PLUGIN_URL . 'templates';
		$filtered_templates_url = apply_filters('wp_optimize_templates_url', $templates_url);
		return is_string($filtered_templates_url) ? $filtered_templates_url : $templates_url;
	}

	/**
	 * Return or output view content
	 *
	 * @param string  $path                   - path to template, usually relative to templates/ within the WP-O directory
	 * @param boolean $return_instead_of_echo - what to do with the results
	 * @param array	  $extract_these		  - key/value pairs for substitution into the scope of the template
	 *
	 * @return string|void
	 */
	public function include_template($path, $return_instead_of_echo = false, $extract_these = array()) {
		if ($return_instead_of_echo) ob_start();

		if (preg_match('#^([^/]+)/(.*)$#', $path, $matches)) {
			$prefix = $matches[1];
			$suffix = $matches[2];
			if (isset($this->template_directories[$prefix])) {
				$template_file = $this->template_directories[$prefix].'/'.$suffix;
			}
		}

		if (!isset($template_file)) {
			$template_file = WPO_PLUGIN_MAIN_PATH.'templates/'.$path;
		}

		$template_file = apply_filters('wp_optimize_template', $template_file, $path);

		do_action('wp_optimize_before_template', $path, $template_file, $return_instead_of_echo, $extract_these);

		if (!file_exists($template_file)) {
			error_log("WP Optimize: template not found: ".$template_file); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Using for debugging purpose such as incomplete installation
			echo esc_html__('Error:', 'wp-optimize').' '.esc_html__('template not found', 'wp-optimize')." (".esc_html($path).")";
		} else {
				extract($extract_these);
				// The following are useful variables which can be used in the template.
				// They appear as unused, but may be used in the $template_file.
				$wpdb = $GLOBALS['wpdb'];// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $wpdb might be used in the included template
				$wp_optimize = $this;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $wp_optimize might be used in the included template
				$optimizer = $this->get_optimizer();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $optimizer might be used in the included template
				$options = $this->get_options();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $options might be used in the included template
				$wp_optimize_notices = $this->get_notices();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $wp_optimize_notices might be used in the included template
				include $template_file;
		}

		do_action('wp_optimize_after_template', $path, $template_file, $return_instead_of_echo, $extract_these);

		if ($return_instead_of_echo) return ob_get_clean();
	}

	/**
	 * Build a list of template directories (stored in self::$template_directories)
	 */
	private function register_template_directories() {

		$template_directories = array();

		$templates_dir = $this->get_templates_dir();

		if ($dh = opendir($templates_dir)) {
			while (($file = readdir($dh)) !== false) {
				if ('.' === $file || '..' === $file) continue;
				if (is_dir($templates_dir.'/'.$file)) {
					$template_directories[$file] = $templates_dir.'/'.$file;
				}
			}
				closedir($dh);
		}

		// Optimal hook for most extensions to hook into.
		$this->template_directories = apply_filters('wp_optimize_template_directories', $template_directories);

	}

	/**
	 * Message to debug
	 *
	 * @param string $message Message to insert into the log.
	 * @param array  $context array with variables used in $message like in template,
	 * 						  for ex.
	 *						  $message = 'Hello {message}';
	 * 						  $context = ['message' => 'world']
	 * 						  'Hello world' string will be saved in log.
	 */
	public function log($message, $context = array()) {
		$this->get_logger()->debug($message, $context);
	}

	/**
	 * Format Bytes Into KB/MB
	 *
	 * @param  mixed   $bytes    Number of bytes to be converted.
	 * @param  integer $decimals the number of decimal digits
	 * @return string            return the correct format size.
	 */
	public function format_size($bytes, $decimals = 2) {
		if (!is_numeric($bytes)) return __('N/A', 'wp-optimize');

		if (1073741824 <= $bytes) {
			$bytes = number_format($bytes / 1073741824, $decimals) . ' GB';
		} elseif (1048576 <= $bytes) {
			$bytes = number_format($bytes / 1048576, $decimals) . ' MB';
		} elseif (1024 <= $bytes) {
			$bytes = number_format($bytes / 1024, $decimals) . ' KB';
		} elseif (1 < $bytes) {
			$bytes = $bytes . ' bytes';
		} elseif (1 === (int) $bytes) {
			$bytes = $bytes . ' byte';
		} else {
			$bytes = '0 bytes';
		}

		return $bytes;
	}

	/**
	 * Format a timestamp into a human-readable date time
	 *
	 * @param int	 $timestamp Epoch timestamp to convert
	 * @param string $separator String separator
	 * @return string
	 */
	public function format_date_time($timestamp, $separator = ' @ ') {
		return date_i18n(get_option('date_format'). $separator .get_option('time_format'), ($timestamp + get_option('gmt_offset') * 3600));
	}

	/**
	 * Executed this function on cron event.
	 *
	 * @return void
	 */
	public function cron_action() {

		$optimizer = $this->get_optimizer();
		$options = $this->get_options();

		$this->log('WPO: Starting cron_action()');
		$options->update_option('last-optimized', time());
		if ('true' === $options->get_option('schedule')) {
			$this_options = $options->get_option('auto');

			// Currently the output of the optimizations is not saved/used/logged.
			$optimizer->do_optimizations($this_options, 'auto');
		}

	}

	/**
	 * Schedule cron tasks used by plugin.
	 *
	 * @return void
	 */
	private function schedule_plugin_cron_tasks() {
		if (!wp_next_scheduled('wpo_weekly_cron_tasks')) {
			wp_schedule_event(current_time("timestamp", 0), 'wpo_weekly', 'wpo_weekly_cron_tasks');
		}

		add_action('wpo_weekly_cron_tasks', array($this, 'do_weekly_cron_tasks'));

		// Run 404 detector cron.
		$this->get_404_detector_cron();
	}

	/**
	 * Do plugin background tasks.
	 *
	 * @return void
	 */
	public function do_weekly_cron_tasks() {
		// add tasks here.
		$this->get_db_info()->update_plugin_json();
	}

	/**
	 * This will customize a URL with a correct Affiliate link
	 * This function can be updated to suit any URL as longs as the URL is passed
	 *
	 * @param string        $url	                - URL to check to see if it is an updraftplus match.
	 * @param ?string       $text	                - Text to be entered within the href a tags.
	 * @param ?string       $html	                - Any specific HTML to be added. Supplied parameter will not be escaped in this function. Provide escaped, safe HTML
	 * @param string|array	$attrs                  - Specify the HTML attributes as an array or string. Use the array format for multiple attributes (e.g., array( "class" => "lorem-ipsum", "title" => "Highlighting text" )), and use the string format for a single attribute (e.g., 'class="lorem-ipsum"').
	 * @param bool	        $return_instead_of_echo - if set, then the result will be returned, not echo-ed.
	 * @return string|void
	 */
	public function wp_optimize_url($url, $text = '', $html = '', $attrs = '', $return_instead_of_echo = false) {
		// Check if the URL is UpdraftPlus.
		$url = $this->maybe_add_affiliate_params($url);		// Return URL - check if there is HTML such as images.
		
		// Check if the variable $text is empty (null value included), otherwise assign $html.
		$content = empty($text) ? $html : esc_html($text);
		
		// Check if $attrs is an array to convert the attributes into a string line.
		$str_attrs = '';
		if (is_array($attrs)) {
			foreach ($attrs as $attr => $value) {
				$str_attrs .= $attr . '="' . esc_attr($value) . '" ';
			}
		} else {
			// If $attrs is empty, the `explode` function will only return an empty array.
			$attrs = explode('=', $attrs);
			// Check if $attrs in positions 1 and 2 are not empty and exist; otherwise, return an empty string.
			$str_attrs = !empty($attrs[0]) && !empty($attrs[1]) ? $attrs[0] . '="' . esc_attr(str_replace('"', '', $attrs[1])) . '"' : '';
		}
		
		// Check if it is necessary to add a target value if the url is external
		$is_external_url = $this->is_external_url($url);
		if ($is_external_url) {
			$str_attrs = preg_replace('/\s+target="_blank"/i', '', $str_attrs);
			$str_attrs .= ' target="_blank"';
		}
		
		$result = sprintf(
			'<a href="%s" %s>%s</a>',
			esc_url($url),
			$str_attrs,
			wp_kses_post($content)
		);

		if ($return_instead_of_echo) return $result;
		echo wp_kses_post($result);
	}
	
	/**
	 * Check if a URL is external
	 *
	 * @param string $url
	 * @return bool
	 */
	public function is_external_url($url) {
		if (empty($url)) {
			return false;
		}
		
		$current_domain = wp_parse_url(home_url(), PHP_URL_HOST);
		$url = wp_parse_url($url, PHP_URL_HOST);

		// Compare the domains and return true if they are different
		return $current_domain !== $url;
	}

	/**
	 * Get a URL with an eventual affiliate ID
	 *
	 * @param string $url
	 * @return string
	 */
	public function maybe_add_affiliate_params($url) {
		// Check if the URL is UpdraftPlus.
		if (false !== strpos($url, '//updraftplus.com')) {
			// Set URL with Affiliate ID.
			$url = add_query_arg(array('afref' => $this->get_notices()->get_affiliate_id()), $url);

			// Apply filters.
			$url = apply_filters('wpoptimize_updraftplus_com_link', $url);
		}
		$filtered_url = apply_filters('wpoptimize_maybe_add_affiliate_params', $url);
		return is_string($filtered_url) ? $filtered_url : $url;
	}

	/**
	 * Setup WPO logger(s)
	 */
	public function setup_loggers() {

		$logger = $this->get_logger();
		$loggers = $this->wpo_loggers();

		if (!empty($loggers)) {
			foreach ($loggers as $_logger) {
				$logger->add_logger($_logger);
			}
		}

		add_action('wp_optimize_after_optimizations', array($this, 'after_optimizations_logger_action'));
	}

	/**
	 * Run logger actions after all optimizations done
	 */
	public function after_optimizations_logger_action() {
		$loggers = $this->get_logger()->get_loggers();
		if (!empty($loggers)) {
			foreach ($loggers as $logger) {
				if (is_a($logger, 'Updraft_Email_Logger')) {
					$logger->flush_log();
				}
			}
		}
	}

	/**
	 * Returns list of WPO loggers instances
	 * Apply filter wp_optimize_loggers
	 *
	 * @return array
	 */
	public function wpo_loggers() {

		$loggers = array();
		$loggers_classes_by_id = array();
		$options_keys = array();

		$loggers_classes = $this->get_loggers_classes();

		foreach ($loggers_classes as $logger_class => $source) {
			$loggers_classes_by_id[strtolower($logger_class)] = $logger_class;
		}

		$options = $this->get_options();
				
		$saved_loggers = $options->get_option('logging');
		$logger_additional_options = $options->get_option('logging-additional');

		// create loggers classes instances.
		if (!empty($saved_loggers)) {
			// check for previous version options format.
			$keys = array_keys($saved_loggers);

			// if options stored in old format then reformat it.
			if (false === is_numeric($keys[0])) {
				$_saved_loggers = array();
					foreach ($saved_loggers as $logger_id => $enabled) {
						if ($enabled) {
							$_saved_loggers[] = $logger_id;
						}
					}

					// fill email with admin.
					if (array_key_exists('updraft_email_logger', $saved_loggers) && $saved_loggers['updraft_email_logger']) {
						$logger_additional_options['updraft_email_logger'] = array(
							get_option('admin_email')
						);
					}

					$saved_loggers = $_saved_loggers;
			}

			foreach ($saved_loggers as $i => $logger_id) {

				if (!array_key_exists($logger_id, $loggers_classes_by_id)) continue;

				$logger_class = $loggers_classes_by_id[$logger_id];

				$logger = new $logger_class();

				$logger_options = $logger->get_options_list();

				if (!empty($logger_options)) {
					foreach (array_keys($logger_options) as $option_name) {
						if (array_key_exists($option_name, $options_keys)) {
							$options_keys[$option_name]++;
						} else {
							$options_keys[$option_name] = 0;
						}

						$option_value = isset($logger_additional_options[$option_name][$options_keys[$option_name]]) ? $logger_additional_options[$option_name][$options_keys[$option_name]] : '';

						// if options in old format then get correct value.
						if ('' === $option_value && array_key_exists($logger_id, $logger_additional_options)) {
							$option_value = array_shift($logger_additional_options[$logger_id]);
						}

						$logger->set_option($option_name, $option_value);
					}
				}

				// check if logger is active.
				$active = (!is_array($logger_additional_options) || (array_key_exists('active', $logger_additional_options) && empty($logger_additional_options['active'][$i]))) ? false : true;

				if ($active) {
					$logger->enable();
				} else {
					$logger->disable();
				}

				$loggers[] = $logger;
			}
		}

		$filtered_loggers = apply_filters('wp_optimize_loggers', $loggers);
		return is_array($filtered_loggers) ? $filtered_loggers : $loggers;
	}

	/**
	 * Returns associative array with logger class name in a key and path to class file in a value.
	 *
	 * @return array
	 */
	public function get_loggers_classes() {
		$loggers_classes = array(
			'Updraft_PHP_Logger' => WPO_PLUGIN_MAIN_PATH . 'includes/class-updraft-php-logger.php',
			'Updraft_Email_Logger' => WPO_PLUGIN_MAIN_PATH . 'includes/class-updraft-email-logger.php',
			'Updraft_Ring_Logger' => WPO_PLUGIN_MAIN_PATH . 'includes/class-updraft-ring-logger.php'
		);

		$loggers_classes = apply_filters('wp_optimize_loggers_classes', $loggers_classes);

		if (!empty($loggers_classes)) {
			foreach ($loggers_classes as $logger_class => $logger_file) {
				if (!class_exists($logger_class)) {
					if (is_file($logger_file)) {
						include_once($logger_file);
					}
				}
			}
		}

		return $loggers_classes;
	}

	/**
	 * Returns information about all loggers classes.
	 *
	 * @return array
	 */
	public function get_loggers_classes_info() {
		$loggers_classes = $this->get_loggers_classes();

		$loggers_classes_info = array();

		if (!empty($loggers_classes)) {
			foreach (array_keys($loggers_classes) as $logger_class_name) {

				if (!class_exists($logger_class_name)) continue;

				$logger_id = strtolower($logger_class_name);
				$logger_class = new $logger_class_name();

				$loggers_classes_info[$logger_id] = array(
					'description' => $logger_class->get_description(),
					'available' => $logger_class->is_available(),
					'allow_multiple' => $logger_class->is_allow_multiple(),
					'options' => $logger_class->get_options_list()
				);
			}
		}

		return $loggers_classes_info;
	}

	/**
	 * Returns true if optimization works in multisite mode
	 *
	 * @return boolean
	 */
	public function is_multisite_mode() {
		return (is_multisite() && self::is_premium());
	}

	/**
	 * Returns true if the current user can run optimizations.
	 *
	 * @return bool
	 */
	public function can_run_optimizations(): bool {
		// we don't check permissions for cron jobs.
		if (defined('DOING_CRON') && DOING_CRON) return true;

		if (self::is_premium() && false === user_can(get_current_user_id(), 'wpo_run_optimizations')) return false;
		return true;
	}

	/**
	 * Returns true if current user can manage plugin options.
	 *
	 * @return bool
	 */
	public function can_manage_options() {
		if (self::is_premium() && false == user_can(get_current_user_id(), 'wpo_manage_settings')) return false;
		return true;
	}

	/**
	 * Returns list of all sites in multisite
	 *
	 * @return array
	 */
	public function get_sites() {
		$sites = array();
		if (function_exists('get_sites')) {
			$sites = get_sites(array('network_id' => null, 'deleted' => 0, 'number' => 999999));
		}
		return $sites;
	}

	/**
	 * Returns script memory limit in megabytes.
	 *
	 * @param string|bool $memory_limit
	 * @return int
	 */
	public function get_memory_limit($memory_limit = false) {
		// Returns in megabytes
		if (false == $memory_limit) $memory_limit = ini_get('memory_limit');
		$memory_limit = rtrim($memory_limit);

		return $this->return_bytes($memory_limit);
	}

	/**
	 * Returns free memory in bytes.
	 *
	 * @return int
	 */
	public function get_free_memory() {
		return $this->get_memory_limit() - memory_get_usage();
	}

	/**
	 * Checks PHP memory_limit and WP_MAX_MEMORY_LIMIT values and return minimal.
	 *
	 * @return int memory limit in bytes.
	 */
	public function get_script_memory_limit() {
		$memory_limit = $this->get_memory_limit();

		if (defined('WP_MAX_MEMORY_LIMIT')) {
			$wp_memory_limit = $this->get_memory_limit(WP_MAX_MEMORY_LIMIT);

			if ($wp_memory_limit > 0 && $wp_memory_limit < $memory_limit) {
				$memory_limit = $wp_memory_limit;
			}
		}

		return $memory_limit;
	}

	/**
	 * Returns max packet size for database.
	 *
	 * @return int|string
	 */
	public function get_max_packet_size() {
		global $wpdb;
		static $mp = 0;

		if ($mp > 0) return $mp;

		$mp = (int) $wpdb->get_var("SELECT @@session.max_allowed_packet");
		// Default to 1MB
		$mp = (is_numeric($mp) && $mp > 0) ? $mp : 1048576;
		// 32MB
		if ($mp < 33554432) {
			$save = $wpdb->show_errors(false);
			@$wpdb->query("SET GLOBAL max_allowed_packet=33554432");// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- suppress errors from displaying
			$wpdb->show_errors($save);

			$mp = (int) $wpdb->get_var("SELECT @@session.max_allowed_packet");
			// Default to 1MB
			$mp = (is_numeric($mp) && $mp > 0) ? $mp : 1048576;
		}

		return $mp;
	}

	/**
	 * Converts shorthand memory notation value to bytes.
	 * From http://php.net/manual/en/function.ini-get.php
	 *
	 * @param string $val shorthand memory notation value.
	 *
	 * @return string
	 */
	public function return_bytes($val) {
		$val = trim($val);
		$last = strtolower($val[strlen($val)-1]);
		$val = (int) $val;
		switch ($last) {
			case 'g':
			$val *= 1024;
			// no break
			case 'm':
			$val *= 1024;
			// no break
			case 'k':
			$val *= 1024;
		}

		return $val;
	}

	/**
	 * Log fatal errors to defined log destinations.
	 */
	public function log_fatal_errors() {
		$last_error = error_get_last();

		if (isset($last_error['type']) && E_ERROR === $last_error['type']) {
			$this->get_logger()->critical($last_error['message']);
		}
	}

	/**
	 * Close browser connection and continue script work. - Taken from UpdraftPlus
	 *
	 * @param string $txt Response to browser; this must be JSON (or if not, alter the Content-Type header handling below)
	 * @return void
	 */
	public function close_browser_connection($txt = '') {
		if (!headers_sent()) {
			// Close browser connection so that it can resume AJAX polling
			header('Content-Length: '.(empty($txt) ? '0' : 4+strlen($txt)));
			header('Content-Type: application/json');
			header('Connection: close');
			header('Content-Encoding: none');
		}

		if (session_id()) session_write_close();
		echo "\r\n\r\n";
		echo $txt; // phpcs:ignore WordPress.Security.EscapeOutput -- Output is already escaped
		// These two added - 19-Feb-15 - started being required on local dev machine, for unknown reason (probably some plugin that started an output buffer).
		$ob_level = ob_get_level();
		while ($ob_level > 0) {
			ob_end_flush();
			$ob_level--;
		}
		flush();
		if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
		if (function_exists('litespeed_finish_request')) litespeed_finish_request();
	}

	/**
	 * Try to change PHP script time limit.
	 */
	public function change_time_limit() {
		$time_limit = (defined('WP_OPTIMIZE_SET_TIME_LIMIT') && WP_OPTIMIZE_SET_TIME_LIMIT > 15) ? WP_OPTIMIZE_SET_TIME_LIMIT : 1800;

		// phpcs:disable
		// Generic.PHP.NoSilencedErrors.Discouraged -- Try to reduce the chances of PHP self-terminating via reaching max_execution_time.
		// Squiz.PHP.DiscouragedFunctions.Discouraged -- Try to reduce the chances of PHP self-terminating via reaching max_execution_time.
		@set_time_limit($time_limit);
		// phpcs:enable
	}

	/**
	 * Does the request come from UDC
	 *
	 * @return boolean
	 */
	public function is_updraft_central_request() {
		return defined('UPDRAFTCENTRAL_COMMAND') && UPDRAFTCENTRAL_COMMAND;
	}

	/**
	 * Does the data need to be included in this request. Currently only true if the request is made from UpdraftCentral.
	 *
	 * @return boolean
	 */
	public function template_should_include_data() {
		/**
		 * Filters whether data should be included in certain templates or not.
		 */
		return (bool) apply_filters('wpo_template_should_include_data', $this->is_updraft_central_request());
	}

	/**
	 * Load the templates for the modal window
	 */
	public function load_modal_template() {
		$this->include_template('modal.php');
	}

	/**
	 * Adds compress popup template
	 */
	public function add_smush_popup_template() {
		if ($this->current_user_can()) {
			$compression_server_hint = Updraft_Smush_Manager()->get_compression_server_hint();
			$this->include_template('images/smush-popup.php', false, array('compression_server_hint' => $compression_server_hint));
		}
	}

	/**
	 * Delete transients and semaphores data from options table.
	 */
	public function delete_transients_and_semaphores() {
		global $wpdb;

		$masks = array(
			'updraft_locked_wpo_%',
			'updraft_unlocked_wpo_%',
			'updraft_last_lock_time_wpo_%',
			'updraft_semaphore_wpo_%',
			'wpo_locked_%',
			'wpo_unlocked_%',
			'wpo_last_lock_time_%',
			'wpo_semaphore_%',
			'_transient_timeout_wpo_%',
			'_transient_wpo_%',
			'updraft_lock_wpo_%',
			'wpo_last_scheduled_%',
		);

		$where_parts = array();
		foreach ($masks as $mask) {
			$where_parts[] = "(`option_name` LIKE '{$mask}')";
		}

		$where_clause = implode(' OR ', $where_parts);
		$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE %s", $where_clause));
	}

	/**
	 * Prevents bots from indexing plugins list
	 *
	 * @param string $output
	 * @return string
	 */
	public function robots_txt($output) {
		$upload_dir = wp_upload_dir();
		$path = wp_parse_url($upload_dir['baseurl']);
		$output .= "\nUser-agent: *";
		$output .= "\nDisallow: " . str_replace($path['scheme'].'://'.$path['host'], '', $upload_dir['baseurl']) . "/wpo/wpo-plugins-tables-list.json\n";
		return $output;
	}

	/**
	 * Returns desired enqueue version string
	 *
	 * @return string Enqueue version as string
	 */
	public function get_enqueue_version() {
		return (defined('WP_DEBUG') && WP_DEBUG) ? WPO_VERSION.'.'.time() : WPO_VERSION;
	}

	/**
	 * Returns script suffix string
	 *
	 * @return string empty or `.min` suffix string
	 */
	public function get_min_or_not_string() {
		return (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
	}

	/**
	 * Returns script suffix string with WPO_VERSION
	 *
	 * @return string empty or min suffix with wpo_version string
	 */
	public function get_min_or_not_internal_string() {
		return (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '-' . str_replace('.', '-', WPO_VERSION) . '.min';
	}

	/**
	 * Instantiate Ajax handling class
	 */
	private function load_ajax_handler() {
		WPO_Ajax::get_instance();
	}

	/**
	 * Get instance of WP_Optimize_Table_Management
	 *
	 * @return WP_Optimize_Table_Management
	 */
	public function get_table_management() {
		return WP_Optimize_Table_Management::get_instance();
	}

	/**
	 * Get instance of WP_Optimize_404_Detector
	 *
	 * @return WP_Optimize_404_Detector
	 */
	public function get_404_detector() {
		return WP_Optimize_404_Detector::get_instance();
	}

	/**
	 * Returns the message used to notify the user that the PHP version or WordPress version doesn't meet the requirements.
	 *
	 * @return string
	 */
	public function get_minimum_requirements_notice_message() {
		// translators: %1$s is minimum required PHP version, %2$s is minimum required WordPress version
		return sprintf(esc_html__('WP-Optimize requires a minimum PHP version of %1$s and WordPress version of %2$s or higher', 'wp-optimize'), esc_html(WPO_REQUIRED_PHP_VERSION), esc_html(WPO_REQUIRED_WP_VERSION));
	}

	/**
	 * Output notice when PHP version or WordPress version is not meet for WP-Optimize.
	 *
	 * @return void
	 */
	public function output_minimum_requirements_notice() {
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo $this->get_minimum_requirements_notice_message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is already escaped ?></p>
		</div>
		<?php
	}
}

/**
 * Unschedule specific tasks:
 * `wpo_cron_event2` => DB optimizations
 * `wpo_weekly_cron_tasks` => ex: plugin.json auto update
 *
 * @return void
 */
function wpo_cron_deactivate() {
	WP_Optimize()->log('running wpo_cron_deactivate()');
	wp_clear_scheduled_hook('wpo_cron_event2');
	wp_clear_scheduled_hook('wpo_weekly_cron_tasks');
}

function WP_Optimize() {
	return WP_Optimize::instance();
}

endif;

$GLOBALS['wp_optimize'] = WP_Optimize();
