<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WP_Optimize_WebP')) :

class WP_Optimize_WebP {

	/**
	 * @var WP_Optimize_Htaccess
	 */
	private $_htaccess;

	/**
	 * Set to true when webp is enabled and vice versa
	 *
	 * @var boolean
	 */
	private $_should_use_webp;

	/**
	 * The logger for this instance
	 *
	 * @var Updraft_File_Logger
	 */
	private $logger;

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->_should_use_webp = (bool) WP_Optimize()->get_options()->get_option('webp_conversion');

		$this->logger = new Updraft_File_Logger($this->get_logfile_path());

		add_action('wpo_reset_webp_conversion_test_result', array($this, 'reset_webp_serving_method'));
		add_action('wpo_prune_webp_logs', array($this, 'prune_webp_logs'));
		$task_manager = WPO_Webp_Task_Manager::get_instance();
		add_action('wpo_webp_convert_compressed_images', array($task_manager, 'webp_convert_compressed_images'));
	}

	/**
	 * Returns singleton instance
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance = null;
		if (null === $instance) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Evaluate WebP conversion capability.
	 *
	 * @return array{is_available: bool, reason: string, message: string, converter_status: array<string, array<string>>, test_ran: bool}
	 */
	public function evaluate_webp_capability(): array {
		$converter_status = array();
		$test_ran = false;
		if ($this->is_only_shell_converters_available() && !$this->shell_functions_available()) {
			return array(
				'is_available' => false,
				'reason' => 'update_failed_no_shell_functions',
				'message' => __('Required WebP shell functions are not available on the server.', 'wp-optimize'),
				'converter_status' => $converter_status,
				'test_ran' => $test_ran,
			);
		}

		if ($this->should_run_webp_conversion_test()) {
			$test_ran = true;
			$converter_status = WPO_WebP_Test_Run::get_converter_status();
			if (!$this->is_webp_conversion_successful()) {
				return array(
					'is_available' => false,
					'reason' => 'update_failed_no_working_webp_converter',
					'message' => __('No working WebP converter was found on the server.', 'wp-optimize'),
					'converter_status' => $converter_status,
					'test_ran' => $test_ran,
				);
			}
		}

		return array(
			'is_available' => true,
			'reason' => '',
			'message' => '',
			'converter_status' => $converter_status,
			'test_ran' => $test_ran,
		);
	}

	/**
	 * Try to enable webp conversion, upon failure disable webp conversion
	 *
	 * @param array<string, string> $data - Data webp_conversion (true|false)
	 * @return WP_Error|bool - information about the operation or WP_Error object on failure
	 */
	private function configure_webp_conversion($data) {

		$options = array();
		$options['webp_conversion'] = isset($data['webp_conversion']) ? filter_var($data['webp_conversion'], FILTER_VALIDATE_BOOLEAN) : false;

		if ($options['webp_conversion']) {
			$capability = $this->evaluate_webp_capability();

			if (!$capability['is_available']) {
				$this->disable_webp_conversion();
				$message = $capability['message'];
				$reason = $capability['reason'];
				$this->log($message);
				return new WP_Error($reason, $message);
			}

			// Only update the test result and converters if the test actually ran
			if ($capability['test_ran']) {
				$options['webp_conversion_test'] = true;
				$options['webp_converters'] = $capability['converter_status']['working_converters'] ?? array();
			}

			// Run serving methods tests and set necessary option values
			// Not possible to test alter HTML since the test is browser-based
			$this->save_htaccess_rules();
			if (!$this->is_webp_redirection_possible()) {
				$this->empty_htaccess_file();
				$options['redirection_possible'] = 'false';
			} else {
				$options['redirection_possible'] = 'true';
			}
		}
		$smush_manager = Updraft_Smush_Manager::instance();
		$success = $smush_manager->update_smush_options($options);

		if (!$success) {
			$this->disable_webp_conversion();
			$message = __('WebP options could not be updated.', 'wp-optimize');
			$this->log($message);
			return new WP_Error('update_failed', $message);
		}

		// Set up daily CRON only when enabling WebP and Delete daily CRON when disabling WebP
		if ($options['webp_conversion']) {
			$this->init_webp_cron_scheduler();
		} else {
			$this->remove_webp_cron_schedules();
			$this->empty_htaccess_file();
		}
		return $success;
	}

	/**
	 * Save WebP settings and trigger related actions.
	 *
	 * @param array<string, string> $data WebP settings (expects 'webp_conversion').
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function save_webp_settings($data) {

		if (!is_array($data)) {
			return new WP_Error('invalid_data', __('Invalid WebP settings data.', 'wp-optimize'));
		}

		$result = $this->configure_webp_conversion($data);

		if (!is_wp_error($result)) {
			do_action('wpo_save_images_settings');
		}

		return $result;
	}

	/**
	 * Returns the path to the logfile
	 *
	 * @return string - file path
	 */
	private function get_logfile_path(): string {
		return WP_Optimize_Utils::get_log_file_path('webp');
	}

	/**
	 * Logging of interesting messages related to WebP.
	 *
	 * @param string $message Log message
	 * @param string $level   Log level (e.g., 'info', 'warning', 'error')
	 *
	 * @return void
	 */
	public function log(string $message, string $level = 'info'): void {
		$this->logger->log($message, $level);
	}

	/**
	 * Prunes the log file
	 *
	 * @return void
	 */
	public function prune_webp_logs(): void {
		$this->log("Pruning the WebP log file");
		$this->logger->prune_logs();
	}

	/**
	 * Test Run and find converter status
	 *
	 * @return void
	 */
	private function set_converter_status(): void {
		$converter_status = WPO_WebP_Test_Run::get_converter_status();
		if ($this->is_webp_conversion_successful()) {
			WP_Optimize()->get_options()->update_option('webp_conversion_test', true);
			WP_Optimize()->get_options()->update_option('webp_converters', $converter_status['working_converters']);
		}
	}

	/**
	 * If .htaccess redirection is not possible, attempts to use the alter_html method.
	 *
	 * @param string $buffer Page HTML
	 *
	 * @return string
	 */
	public function maybe_decide_webp_serve_method(string $buffer): string {
		if (!$this->is_webp_redirection_possible()) {
			$buffer = $this->maybe_use_alter_html($buffer);
		}
		return $buffer;
	}

	/**
	 * If alter HTML method is possible, apply it to the buffer.
	 *
	 * @param string $buffer Page HTML
	 *
	 * @return string
	 */
	private function maybe_use_alter_html(string $buffer): string {
		if ($this->is_alter_html_possible()) {
			$this->empty_htaccess_file();
			$buffer = WPO_WebP_Alter_HTML::get_instance()->alter_html($buffer);
		}
		return $buffer;
	}

	/**
	 * Even if the server supports .htaccess rewrite, sometimes it is not possible
	 * to serve webp images. This method determines whether webp redirection is possible.
	 *
	 * Also applies `wpo_force_webp_serve_using_altered_html` filter for users to be able to
	 * force the Altered HTML method.
	 *
	 * @return bool
	 */
	public function is_webp_redirection_possible(): bool {
		if (apply_filters('wpo_force_webp_serve_using_altered_html', false)) {
			return false;
		}

		$redirection_possible = WP_Optimize()->get_options()->get_option('redirection_possible');

		// If a previous test result exists, use it; otherwise, run the self test
		if (!empty($redirection_possible)) {
			return 'true' === $redirection_possible;
		}

		return $this->run_webp_serving_self_test();
	}
	
	/**
	 * Detect whether using alter HTML method is possible or not
	 *
	 * @return bool
	 */
	private function is_alter_html_possible(): bool {
		return WPO_WebP_Utils::is_browser_accepting_webp();
	}

	/**
	 * Initialize .htaccess
	 *
	 * @return void
	 */
	private function setup_htaccess_file(): void {
		if (null !== $this->_htaccess) return;
		$wp_uploads = wp_get_upload_dir();
		$htaccess_file = $wp_uploads['basedir'] . '/.htaccess';
		if (!file_exists($htaccess_file)) {
			file_put_contents($htaccess_file, '');
		}
		$this->_htaccess = new WP_Optimize_Htaccess($htaccess_file);
	}
	
	/**
	 * Save .htaccess rules
	 *
	 * @return void
	 */
	private function save_htaccess_rules(): void {
		$this->setup_htaccess_file();
		$this->add_webp_mime_type();
		$htaccess_comment_section = 'WP-Optimize WebP Rules';
		if ($this->_htaccess->is_commented_section_exists($htaccess_comment_section)) return;
		$this->_htaccess->update_commented_section($this->prepare_webp_htaccess_rules(), $htaccess_comment_section);
		$this->_htaccess->write_file();
		WP_Optimize()->get_options()->update_option('htaccess_has_webp_rules', true);
	}

	/**
	 * Empty .htaccess file
	 *
	 * @return void
	 */
	private function empty_htaccess_file(): void {
		// Setting default to true, so on initial run (when option is not yet present in the DB) we don't break the function here
		if (!WP_Optimize()->get_options()->get_option('htaccess_has_webp_rules', true)) return;
		$this->setup_htaccess_file();
		$htaccess_comment_sections = array(
			'WP-Optimize WebP Rules',
			'Register webp mime type',
		);
		foreach ($htaccess_comment_sections as $htaccess_comment_section) {
			if (!$this->_htaccess->is_commented_section_exists($htaccess_comment_section)) continue;
			$this->_htaccess->remove_commented_section($htaccess_comment_section);
			$this->_htaccess->write_file();
		}
		WP_Optimize()->get_options()->update_option('htaccess_has_webp_rules', false);
	}

	/**
	 * Prepare array of htaccess rules to use webp images.
	 *
	 * @return array<int, array<array<array<string>|string>|string>>
	 */
	private function prepare_webp_htaccess_rules() {
		return array(
			array(
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'',
				'# Redirect to existing converted image in same dir (if browser supports webp)',
				'RewriteCond %{HTTP_ACCEPT} image/webp',
				'RewriteCond %{REQUEST_FILENAME} (?i)(.*)(\.jpe?g|\.png)$',
				'RewriteCond %1%2\.webp -f',
				'RewriteRule (?i)(.*)(\.jpe?g|\.png)$ %1%2\.webp [T=image/webp,E=EXISTING:1,E=ADDVARY:1,L]',
				'',
				'# Make sure that browsers which does not support webp also gets the Vary:Accept header',
				'# when requesting images that would be redirected to webp on browsers that does.',
				array(
					'<IfModule mod_headers.c>',
					array(
						'<FilesMatch "(?i)\.(jpe?g|png)$">',
						'Header append "Vary" "Accept"',
						'</FilesMatch>',
					),
					'</IfModule>',
				),
				'',
				'</IfModule>',
				'',
			),
			array(
				'# Rules for handling requests for webp images',
				'# ---------------------------------------------',
				'',
				'# Set Vary:Accept header if we came here by way of our redirect, which set the ADDVARY environment variable',
				'# The purpose is to make proxies and CDNs aware that the response varies with the Accept header',
				'<IfModule mod_headers.c>',
				array(
					'<IfModule mod_setenvif.c>',
					'# Apache appends "REDIRECT_" in front of the environment variables defined in mod_rewrite, but LiteSpeed does not',
					'# So, the next lines are for Apache, in order to set environment variables without "REDIRECT_"',
					'SetEnvIf REDIRECT_EXISTING 1 EXISTING=1',
					'SetEnvIf REDIRECT_ADDVARY 1 ADDVARY=1',
					'',
					'Header append "Vary" "Accept" env=ADDVARY',
					'',
					'# Set X-WPO-WebP header for diagnose purposes',
					'Header set "X-WPO-WebP" "Redirected directly to existing webp" env=EXISTING',
					'</IfModule>',
				),
				'</IfModule>',
			),
		);
	}

	/**
	 * Add webp mime type to htaccess rules.
	 *
	 * @return void
	 */
	private function add_webp_mime_type(): void {
		$htaccess_comment_section = 'Register webp mime type';
		if ($this->_htaccess->is_exists() && !$this->_htaccess->is_commented_section_exists($htaccess_comment_section)) {
			$webp_mime_type = array(
				array(
					'<IfModule mod_mime.c>',
					'AddType image/webp .webp',
					'</IfModule>',
				),
			);
			$this->_htaccess->update_commented_section($webp_mime_type, $htaccess_comment_section);
			$this->_htaccess->write_file();
		}
	}

	/**
	 * Returns the path to the WebP test image file.
	 *
	 * @return string
	 */
	private function get_webp_test_image_path(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/wpo/images/wpo_logo_small.png.webp';
	}

	/**
	 * Checks whether a webp conversion test is successful or not
	 *
	 * @return bool
	 */
	private function is_webp_conversion_successful(): bool {
		return file_exists($this->get_webp_test_image_path());
	}

	/**
	 * Checks whether a sample webp conversion test should be run or not
	 *
	 * @return bool Returns true if the sample test should be run, false otherwise
	 */
	private function should_run_webp_conversion_test(): bool {
		return !$this->get_webp_conversion_test_result();
	}

	/**
	 * Returns webp conversion test result
	 *
	 * @return bool Returns the value of the webp_conversion_test saved in the options table
	 */
	public function get_webp_conversion_test_result(): bool {
		return (bool) WP_Optimize()->get_options()->get_option('webp_conversion_test');
	}

	/**
	 * Checks whether the webp redirection is possible or not and sets flag
	 *
	 * @return bool Returns true if webp is served successfully, false otherwise
	 */
	private function run_webp_serving_self_test(): bool {
		$self_test = WPO_WebP_Self_Test::get_instance();

		if ($self_test->is_webp_served()) {
			WP_Optimize()->get_options()->update_option('redirection_possible', 'true');
			return true;
		}
		WP_Optimize()->get_options()->update_option('redirection_possible', 'false');
		$this->empty_htaccess_file();
		return false;
	}

	/**
	 * Resets webp serving method by running self test, if needed purges cache and empties `uploads/.htaccess` file
	 *
	 * @return void
	 */
	public function reset_webp_serving_method(): void {
		if ($this->_should_use_webp) {
			$this->reset_webp_options();
			$this->run_self_test();
			list($old_redirection_possible, $new_redirection_possible) = $this->get_old_and_new_redirection_possibility();
			$this->maybe_purge_cache($old_redirection_possible, $new_redirection_possible);
			$this->maybe_empty_htaccess_file($new_redirection_possible);
		}
	}
	
	/**
	 * Resets WebP related options
	 *
	 * @return void
	 */
	private function reset_webp_options(): void {
		$options = WP_Optimize()->get_options();
		$options->update_option('old_redirection_possible', $options->get_option('redirection_possible'));
		$options->update_option('webp_conversion_test', false);
		$options->update_option('webp_converters', false);
		$options->update_option('redirection_possible', false);
		$this->remove_webp_test_image_file();
	}
	
	/**
	 * Running self test to find available converters and possibility of serving webp using redirection method
	 *
	 * @return void
	 */
	private function run_self_test(): void {
		$this->set_converter_status();
		if ($this->get_webp_conversion_test_result()) {
			$this->save_htaccess_rules();
			$this->run_webp_serving_self_test();
		} else {
			$this->disable_webp_conversion();
			$this->log("No working WebP converter was found on the server when running self-test, disabling WebP conversion");
		}
	}
	
	/**
	 * Gets old and new redirection possibility values
	 *
	 * @return array<string>
	 */
	private function get_old_and_new_redirection_possibility() {
		$options = WP_Optimize()->get_options();
		return array(
			$options->get_option('old_redirection_possible'),
			$options->get_option('redirection_possible'),
		);
	}
	
	/**
	 * Cache is cleared when there is a change in the potential for serving WebP using redirection.
	 *
	 * @param string $old_redirection_possible Previous redirection possibility value
	 * @param string $new_redirection_possible Current redirection possibility value
	 * @return void
	 */
	private function maybe_purge_cache($old_redirection_possible, $new_redirection_possible): void {
		if ($old_redirection_possible === $new_redirection_possible) {
			return;
		}

		$is_cache_purged = WP_Optimize()->get_page_cache()->purge();
		$log_old_value = empty($old_redirection_possible) ? 'null' : $old_redirection_possible;
		$log_new_value = empty($new_redirection_possible) ? 'null' : $new_redirection_possible;
		$this->log("Purging cache because redirection_possible value changed from: {$log_old_value} to {$log_new_value}");

		if ($is_cache_purged) {
			WP_Optimize()->get_page_cache()->file_log('Full Cache Purge due to change in the value of WebP redirection');
		}
	}
	
	/**
	 * Remove redirection rules from `uploads/.htaccess` file if redirection is not possible
	 *
	 * @param string $new_redirection_possible
	 * @return void
	 */
	private function maybe_empty_htaccess_file($new_redirection_possible): void {
		if ('false' === $new_redirection_possible) {
			$this->empty_htaccess_file();
		}
	}

	/**
	 * Initialize cron scheduler
	 *
	 * @return void
	 */
	public function init_webp_cron_scheduler(): void {
		if (!wp_next_scheduled('wpo_reset_webp_conversion_test_result')) {
			wp_schedule_event(time(), 'wpo_daily', 'wpo_reset_webp_conversion_test_result');
		}
		if (!wp_next_scheduled('wpo_prune_webp_logs')) {
			wp_schedule_event(time(), 'weekly', 'wpo_prune_webp_logs');
		}
		if (!wp_next_scheduled('wpo_webp_convert_compressed_images')) {
			wp_schedule_event(strtotime('midnight'), 'daily', 'wpo_webp_convert_compressed_images');
		}
	}

	/**
	 * Remove all cron schedules
	 *
	 * @return void
	 */
	public function remove_webp_cron_schedules(): void {
		wp_clear_scheduled_hook('wpo_reset_webp_conversion_test_result');
		wp_clear_scheduled_hook('wpo_prune_webp_logs');
		wp_clear_scheduled_hook('wpo_webp_convert_compressed_images');
	}

	/**
	 * Return the true if webp conversion is enabled and vice versa
	 *
	 * @return bool
	 */
	public function is_webp_conversion_enabled(): bool {
		return $this->_should_use_webp;
	}

	/**
	 * Set the webp_conversion option value to false and remove webp cron schedules
	 *
	 * @return void
	 */
	public function disable_webp_conversion(): void {
		$this->empty_htaccess_file();
		WP_Optimize()->get_options()->update_option("webp_conversion", false);
		$this->remove_webp_cron_schedules();
		$message = __('Disabling the webp conversion.', 'wp-optimize');
		$this->log($message);
	}

	/**
	 * Remove webp converted test image file
	 *
	 * @return void
	 */
	private function remove_webp_test_image_file(): void {
		$destination = $this->get_webp_test_image_path();
		wp_delete_file($destination);
	}

	/**
	 * Run during plugin deactivation
	 *
	 * @return void
	 */
	public function plugin_deactivate(): void {
		$this->empty_htaccess_file();
		$this->remove_webp_test_image_file();
	}

	/**
	 * Determines whether one of the PHP shell functions required for WebP conversion is available or not.
	 *
	 * @return bool
	 */
	public function shell_functions_available(): bool {
		$has_escapeshellarg = function_exists('escapeshellarg');
		$has_exec_or_passthru = $this->any_function_exists(array('exec', 'passthru'));
		$has_proc_functions = $this->all_functions_exist(array('proc_open', 'proc_close'));
		$has_popen_functions = $this->all_functions_exist(array('popen', 'pclose'));

		return $has_escapeshellarg && ($has_exec_or_passthru || $has_proc_functions || $has_popen_functions);
	}

	/**
	 * Checks if only shell converters available for WebP conversion.
	 *
	 * @return boolean
	 */
	private function is_only_shell_converters_available(): bool {
		$available_converters = WP_Optimize()->get_options()->get_option('webp_converters');
		/** @var array<string> $available_converters */
		$available_converters = is_array($available_converters) ? $available_converters : array();
		$converters_with_shell = WPO_WebP_Test_Run::get_converters_with_shell();
		$available_with_shell = array_intersect($available_converters, $converters_with_shell);

		return count($available_converters) > 0 && count($available_converters) === count($available_with_shell);
	}

	/**
	 * Determines whether one of the PHP shell functions required for WebP conversion is available or not.
	 *
	 * @deprecated 3.6.0
	 * @return bool
	 */
	public static function is_shell_functions_available(): bool {
		_deprecated_function(__METHOD__, '3.6.0', 'WP_Optimize_WebP::shell_functions_available');
		return WP_Optimize_WebP::get_instance()->shell_functions_available();
	}

	/**
	 * Check if all the functions from the list is available.
	 *
	 * @param array<string> $functions
	 * @return bool
	 */
	private function all_functions_exist($functions): bool {
		foreach ($functions as $function) {
			if (!function_exists($function)) return false;
		}
		return true;
	}

	/**
	 * Check if one of the functions from the list is available.
	 *
	 * @param array<string> $functions
	 * @return bool
	 */
	private function any_function_exists($functions): bool {
		foreach ($functions as $function) {
			if (function_exists($function)) return true;
		}
		return false;
	}

	/**
	 * Return true if webp conversion is enabled and vice versa.
	 *
	 * @return bool
	 */
	public function is_webp_enabled(): bool {
		_deprecated_function(__METHOD__, '4.5.4', 'WP_Optimize_WebP::is_webp_conversion_enabled');
		return $this->is_webp_conversion_enabled();
	}

	/**
	 * Actions to be performed upon plugin activation
	 *
	 * @return void
	 */
	public function plugin_activate(): void {
		if ($this->is_webp_conversion_enabled()) {
			$this->init_webp_cron_scheduler();
			$this->reset_webp_serving_method();
		}
	}
}

endif;
