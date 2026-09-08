<?php

if (!defined('ABSPATH')) die('Access denied.');

if (!class_exists('WP_Optimize_Utils')) :

class WP_Optimize_Utils {

	/**
	 * Returns the folder path for log files
	 *
	 * @return string - Folder path for log files with trailing slash
	 */
	public static function get_log_folder_path() {
		$upload_base = self::get_base_upload_dir();
		if (!is_dir($upload_base . 'wpo/logs')) {
			wp_mkdir_p($upload_base . 'wpo/logs');
		}
		// Ensure index.php in log folder to stop directory listing
		if (!file_exists($upload_base . 'wpo/logs/index.php')) {
			file_put_contents($upload_base . 'wpo/logs/index.php', "<?php");
		}
		return $upload_base . 'wpo/logs/';
	}

	/**
	 * Generates a log file name based on the given prefix.
	 *
	 * @param string $prefix The prefix to be added to the log file name.
	 * @return string The generated log file name.
	 */
	public static function get_log_file_name($prefix) {
		$secret = defined('AUTH_KEY') ? AUTH_KEY : 'WP_Optimize';
		return $prefix . '-' . substr(md5($secret), 0, 20) . '.log';
	}

	/**
	 * Returns the file path for the log file.
	 *
	 * @param string $prefix The prefix to be added to the log file name.
	 * @return string The file path for the log file.
	 */
	public static function get_log_file_path($prefix) {
		return self::get_log_folder_path() . self::get_log_file_name($prefix);
	}

	/**
	 * Returns WordPress GMT offset in seconds.
	 *
	 * @return int
	 */
	public static function get_gmt_offset() {
		$timezone_string = get_option('timezone_string');

		if (!empty($timezone_string)) {
			$timezone = new DateTimeZone($timezone_string);
			$gmt_offset = $timezone->getOffset(new DateTime());
		} else {
			$gmt_offset_option = (int) get_option('gmt_offset');
			$gmt_offset = 3600 * $gmt_offset_option;
		}

		return $gmt_offset;
	}

	/**
	 * Returns the folder path for the upload directory with trailing slash
	 *
	 * @return string - Folder path for the upload directory with trailing slash
	 */
	public static function get_base_upload_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit($upload_dir['basedir']);
	}

	/**
	 * Returns file path relative to the `wp-content` directory
	 *
	 * @param string $path
	 * @return string
	 */
	public static function get_wp_relative_path($path): string {
		$path = wp_normalize_path($path);
		$content_dir = wp_normalize_path(WP_CONTENT_DIR);
	
		$pos = strpos($path, $content_dir);
		if (false === $pos) {
			return $path;
		}
	
		return substr($path, strlen($content_dir)+1) ?: '';
	}

	/**
	 * Get the file path
	 *
	 * @param string $url
	 * @return string
	 */
	public static function get_file_path($url) {
		if (is_multisite()) {
			if (function_exists('get_main_site_id')) {
				$site_id = get_main_site_id();
			} else {
				$network = get_network();
				$site_id = $network->site_id;
			}
			switch_to_blog($site_id);
		}
		$upload_dir = wp_upload_dir();
		$uploads_url = trailingslashit($upload_dir['baseurl']);
		$uploads_dir = trailingslashit($upload_dir['basedir']);
		if (is_multisite()) {
			restore_current_blog();
		}
		$possible_urls = array(
			WP_CONTENT_URL => WP_CONTENT_DIR,
			WP_PLUGIN_URL => WP_PLUGIN_DIR,
			$uploads_url => $uploads_dir,
			get_template_directory_uri() => get_template_directory(),
			untrailingslashit(includes_url()) => ABSPATH . WPINC,
		);
		$file = '';
		foreach ($possible_urls as $possible_url => $path) {
			$pos = strpos($url, $possible_url);
			if (0 === $pos) {
				$file = substr_replace($url, $path, $pos, strlen($possible_url));
				break;
			}
		}
		return $file;
	}

	/**
	 * Returns folder staticstics - size and file count
	 *
	 * @param string $folder
	 * @return array
	 */
	public static function get_folder_stats($folder, $files_to_ignore = array()) {
		clearstatcache();

		$size = 0;
		$file_count = 0;

		if (is_dir($folder)) {
			try {
				$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
				
				foreach ($dir as $file) {
					if (!empty($files_to_ignore) && is_array($files_to_ignore) && in_array($file->getFilename(), $files_to_ignore)) continue;
					try {
						if ($file->isFile()) {
							$size += $file->getSize();
							$file_count++;
						}
					} catch (RuntimeException $e) {
						error_log($e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Catching exception for debugging purpose
					}
				}
			} catch (UnexpectedValueException $e) {
				error_log($e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Catching exception for debugging purpose
			}
		}

		return array(
			'size' => $size,
			'file_count' => $file_count,
		);
	}

	/**
	 * Fetches the content of a remote file via HTTP request.
	 *
	 * @param string $url  The URL of the remote file to retrieve.
	 * @param array $args Request arguments passed to wp_remote_get()
	 * @return string|false The content of the remote file on success, or false on failure.
	 */
	public static function get_remote_file_content($url, $args = array()) {
		$response = wp_safe_remote_get($url, $args);
		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return false;
		
		$body = wp_remote_retrieve_body($response);
		if (empty($body)) return false;
		
		return $body;
	}

	/**
	 * Parse tag attributes and return array with them.
	 *
	 * @param string $tag
	 * @return array
	 */
	public static function parse_attributes($tag) {
		$attributes = array();

		$_attributes = wp_kses_hair($tag, wp_allowed_protocols());

		if (empty($_attributes)) return $attributes;

		foreach ($_attributes as $key => $value) {
			$attributes[$key] = $value['value'];
		}

		return $attributes;
	}

	/**
	 * Checks whether supplied string is a valid html document or not
	 *
	 * @param string $html - HTML document as string
	 * @return bool
	 */
	public static function is_valid_html($html) {
		global $wp_query;
		
		// is_feed() works only when $wp_query is set, and it raises a warning otherwise.
		if (isset($wp_query) && is_feed()) return false;

		// To prevent issue with `simple_html_dom` class
		// Exit if it doesn't look like HTML
		// https://github.com/rosell-dk/webp-express/issues/228
		if (!preg_match("#^\\s*<#", $html)) return false;

		if ('' === $html) return false;
		return true;
	}

	/**
	 * Include simple html dom script if not available
	 */
	public static function maybe_include_simple_html_dom() {
		if (!function_exists('str_get_html')) {
			require_once WPO_PLUGIN_MAIN_PATH . 'vendor/simplehtmldom/simplehtmldom/simple_html_dom.php';
		}
	}

	/**
	 * Returns simplehtmldom\HtmlDocument object
	 *
	 * @param string $html_buffer - HTML document as string
	 * @return simplehtmldom\HtmlDocument | false
	 */
	public static function get_simple_html_dom_object($html_buffer) {
		self::maybe_include_simple_html_dom();
		return str_get_html($html_buffer, false, false,
			get_option('blog_charset'), false, DEFAULT_BR_TEXT,
			DEFAULT_SPAN_TEXT, false);
	}

	/**
	 * Unserialize data
	 *
	 * @param string        $serialized_data Data to be unserialized, should be one that is already serialized
	 * @param boolean|array $allowed_classes Either an array of class names which should be accepted, false to accept no classes, or true to accept all classes
	 * @param integer       $max_depth       The maximum depth of structures permitted during unserialization, and is intended to prevent stack overflows
	 * @return mixed Unserialized data can be any of types (integer, float, boolean, string, array or object)
	 */
	public static function unserialize($serialized_data, $allowed_classes = false, $max_depth = 0) {
		// phpcs:ignore PHPCompatibility.FunctionUse.NewFunctionParameters.unserialize_optionsFound -- Used in PHP 7.0+
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- suppress PHP warning in case of failure
		return @unserialize(trim($serialized_data), array('allowed_classes' => $allowed_classes, 'max_depth' => $max_depth));
	}

	/**
	 * Checks whether supplied data is serialized or not, and if so, unserializes it
	 *
	 * @param string        $serialized_data Data to be unserialized, should be one that is already serialized
	 * @param boolean|array $allowed_classes Either an array of class names which should be accepted, false to accept no classes, or true to accept all classes
	 * @param integer       $max_depth       The maximum depth of structures permitted during unserialization, and is intended to prevent stack overflows
	 * @return mixed Unserialized data can be any of types (integer, float, boolean, string, array or object)
	 */
	public static function maybe_unserialize($serialized_data, $allowed_classes = false, $max_depth = 0) {
		if (!is_serialized($serialized_data)) return $serialized_data;

		return self::unserialize($serialized_data, $allowed_classes, $max_depth);
	}
			
	/**
	 * Get associative array with tag attributes and their values and build tag attribute string.
	 *
	 * @param array $attributes
	 * @return string
	 */
	public static function build_attributes($attributes) {
		$_attributes = array();

		if (!empty($attributes)) {
			foreach ($attributes as $key => $value) {
				$_attributes[] = $key . '="' . esc_attr($value) . '"';
			}
		}

		return join(' ', $_attributes);
	}

	/**
	 * Get user_agent for desktop or mobile used for different requests via wp_remote_get
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	public static function get_user_agent($type = 'desktop') {
		global $wp_version;

		$user_agent = sprintf(
			'WP-Optimize/%s WordPress/%s (%s; %s)',
			WPO_VERSION,
			$wp_version,
			('mobile' === $type) ? 'Mobile' : 'Desktop',
			home_url('/')
		);

		if ('gfont' === $type) {
			// Custom UA returns TTF format. We use modern UA to get WOFF2 which is better for web
			$user_agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
		}

		return apply_filters('wpo_user_agent', $user_agent, $type);
	}

	/**
	 * Builds a one-line summary string from debug backtrace.
	 *
	 * Intended for use in error_log() for identifying the caller's context.
	 * Example output: C:Test_Class|F:test_function()|L:100
	 *
	 * @return string
	 */
	public static function get_backtrace_summary() {
		$debug_backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Used for debugging purposes
		$caller = $debug_backtrace[2] ?? array();

		$class = $caller['class'] ?? 'N/A';
		$function = $caller['function'] ?? 'N/A';
		$line   = $debug_backtrace[1]['line'] ?? 'N/A';

		return sprintf('C:%s|F:%s()|L:%s', $class, $function, $line);
	}

	/**
	 * Check if the server is using HTTP/1.x
	 *
	 * @return bool
	 */
	public static function is_request_protocol_http1(): bool {
		$protocol = isset($_SERVER['SERVER_PROTOCOL']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_PROTOCOL'])) : '';
		return stripos($protocol, 'HTTP/1.') !== false;
	}

	/**
	 * Wrapper for wp_delete_file() to ensure compatibility with WordPress versions prior to 6.1.
	 *
	 * In WordPress versions earlier than 6.1, wp_delete_file() returns void, in that case we check
	 * if the file is actually deleted or not to determine the boolean value to return
	 *
	 * @param string $file
	 * @return bool
	 */
	public static function wp_delete_file($file) {
		$wp_delete_file_result = wp_delete_file($file);

		// when wp_delete_file() returns void we check if the file is deleted
		if (null === $wp_delete_file_result) {
			$wp_delete_file_result = false === is_file($file);
		}

		return $wp_delete_file_result;
	}

	/**
	 * Add UTM parameters to a URL and return the modified URL.
	 *
	 * @param string  $url                 The original URL.
	 * @param array   $params              Optional UTM parameters.
	 * @param bool    $override_url_params if true, it will override existing parameters in the url if matched.
	 *
	 * @return string Modified URL with UTM parameters added.
	 */
	public static function add_utm_params($url, $params = array(), $override_url_params = false): string {
		$default_params = array(
			'utm_source'  => 'wpo-plugin',
			'utm_medium'  => 'referral',
		);

		$utm_params = wp_parse_args($params, $default_params);

		if ($override_url_params) {
			return esc_url(add_query_arg($utm_params, $url));
		}

		$parsed = wp_parse_url($url, PHP_URL_QUERY);
		$original_url_params = array();

		if (!empty($parsed)) {
			parse_str($parsed, $original_url_params);
		}

		foreach ($utm_params as $key => $value) {
			if (isset($original_url_params[$key])) {
				unset($utm_params[$key]);
			}
		}

		return esc_url(add_query_arg($utm_params, $url));
	}

	/**
	 * Defines MAX_FILE_SIZE constant if not already defined.
	 *
	 * @return void
	 */
	public static function define_maxfile_size_constant() {
		$maxfile_size = apply_filters('wpo_max_file_size', 600000);
		$maxfile_size = is_numeric($maxfile_size) && $maxfile_size > 0 ? (int) $maxfile_size : 600000;
		if (!defined('MAX_FILE_SIZE')) {
			define('MAX_FILE_SIZE', $maxfile_size);
		}
	}

	/**
	 * Check if the given array contains all key-value pairs of another array.
	 *
	 * @param array $needle   The array of key-value pairs to check for.
	 * @param array $haystack The array to check within.
	 * @return bool True if $haystack contains all key-value pairs of $needle, false otherwise.
	 */
	public static function array_contains($needle, $haystack): bool {

		if (!is_array($needle) || !is_array($haystack)) return false;

		foreach ($needle as $key => $value) {
			if (!array_key_exists($key, $haystack)) return false;

			if (is_array($value)) {
				if (!is_array($haystack[$key])) return false;
				if (!self::array_contains($value, $haystack[$key])) return false;
			} elseif ($haystack[$key] != $value) { // Loose comparison is intentional here to allow type juggling, e.g. '1' == 1 since we check sent form data with saved options where types can be different
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the current user has permission to purge cache.
	 *
	 * @return bool
	 */
	public static function current_user_can_purge_cache() {
		if (WP_Optimize::is_premium()) {
			return WP_Optimize()->current_user_can() || WP_Optimize_Premium()->can_purge_the_cache();
		} else {
			return WP_Optimize()->current_user_can();
		}
	}

	/**
	 * Get URL without purge cache-related parameters.
	 *
	 * @return string
	 */
	public static function get_url_without_cache_purge_params() {
		return remove_query_arg(array('_wpo_purge', 'wpo_minify_cache_purged', '_wpo_purge_minify_cache', 'wpo_all_pages_cache_purged', 'wpo_single_page_cache_purged'));
	}
}

endif;
