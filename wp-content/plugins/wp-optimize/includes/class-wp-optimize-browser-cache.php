<?php

if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * Class WP_Optimize_Browser_Cache
 */
class WP_Optimize_Browser_Cache {

	use WP_Optimize_HTTP_Error_Codes_Trait;

	private $_htaccess;

	private $_options;

	private $_wp_optimize;

	/**
	 * Browser cache section in htaccess will be wrapped with this comment
	 *
	 * @var string
	 */
	private $_htaccess_section_comment = 'WP-Optimize Browser Cache';

	/**
	 * WP_Optimize_Browser_Cache constructor.
	 */
	public function __construct() {
		$this->_wp_optimize = WP_Optimize();

		$this->_htaccess = new WP_Optimize_Htaccess();

		$this->_options = $this->_wp_optimize->get_options();
	}

	/**
	 * Returns singleton instance object
	 *
	 * @return WP_Optimize_Browser_Cache Returns `WP_Optimize_Browser_Cache` object
	 */
	public static function instance() {
		static $_instance = null;
		if (null === $_instance) {
			$_instance = new self();
		}
		return $_instance;
	}

	/**
	 * Check headers for Cache-Control and Etag. And if they are exist return true.
	 *
	 * @return bool|WP_Error
	 **/
	public function is_enabled() {

		static $is_enabled;
		if (isset($is_enabled)) return $is_enabled;

		$headers = $this->get_stylesheet_headers();

		if (is_wp_error($headers)) return $headers;

		if (array_key_exists('cache-control', $headers) && array_key_exists('expires', $headers)) {
			$is_enabled = true;
		} else {
			$is_enabled = false;
		}

		if ($this->is_browser_cache_section_exists() && false === $this->_wp_optimize->is_apache_module_loaded(array('mod_expires', 'mod_headers'))) {
			$is_enabled = new WP_Error('Browser cache', __('We successfully updated your .htaccess file.', 'wp-optimize') . ' ' . __('But it seems one of Apache modules - mod_expires or mod_headers is not active.', 'wp-optimize'));
		}

		return $is_enabled;
	}

	/**
	 * Enable browser cache - add settings into .htaccess.
	 *
	 * @param int $expiry_days
	 * @param int $expiry_hours
	 */
	public function enable(int $expiry_days, int $expiry_hours) {
		$this->_htaccess->update_commented_section($this->prepare_browser_cache_section($expiry_days, $expiry_hours), $this->_htaccess_section_comment);
		$this->_htaccess->write_file();
		$this->_options->update_option('enable_browser_cache', true);
	}

	/**
	 * Disable cache - remove settings from .htaccess added in enable() function.
	 */
	public function disable() {
		$this->_htaccess->remove_commented_section($this->_htaccess_section_comment);
		$this->_htaccess->write_file();
		$this->_options->update_option('enable_browser_cache', false);
	}

	/**
	 * Check if browser cache option is set to true then add section with browser cache settings into .htaccess.
	 */
	public function restore() {
		$expire_days = absint($this->_options->get_option('browser_cache_expire_days', 0));
		$expire_hours = absint($this->_options->get_option('browser_cache_expire_hours', 0));

		$enabled = 0 !== $expire_days || 0 !== $expire_hours;

		if ($enabled && $this->_htaccess->is_writable()) $this->enable($expire_days, $expire_hours);
	}

	/**
	 * Check if section with browser cache settings already exists.
	 *
	 * @return bool
	 */
	public function is_browser_cache_section_exists() {
		return $this->_htaccess->is_commented_section_exists($this->_htaccess_section_comment);
	}

	/**
	 * Handle for enable_browser_cache command used in WP_Optimize_Commands.
	 *
	 * @param array $params - ['browser_cache_expire' => '1 month 15 days 2 hours' || '' - for disable cache]
	 * @return array
	 */
	public function enable_browser_cache_command_handler(array $params): array {
		$expire_days = absint($params['browser_cache_expire_days']);
		$expire_hours = absint($params['browser_cache_expire_hours']);

		$current_expire_days = absint($this->_options->get_option('browser_cache_expire_days', 0));
		$current_expire_hours = absint($this->_options->get_option('browser_cache_expire_hours', 0));

		$section_updated = false;

		$enable = 0 !== $expire_days || 0 !== $expire_hours;

		/**
		 * If we don't need to do anything in .htaccess then return message.
		 */
		if ($enable === $this->_htaccess->is_commented_section_exists()
			&& $expire_days === $current_expire_days
			&& $expire_hours === $current_expire_hours
		) {
			if ($enable) {
				$message = __('Browser static caching settings already exists in the .htaccess file', 'wp-optimize');
			} else {
				$message = '';
			}

			return array(
				'success' => true,
				'enabled' => $enable,
				'message' => $message,
			);
		}

		if ($this->_htaccess->is_writable()) {
			// update commented section

			if ($enable) {
				$this->enable($expire_days, $expire_hours);
			} else {
				$this->disable();
			}

			// read updated file.
			$this->_htaccess->read_file();
			// check if section added or removed successfully.
			$section_exists = $this->_htaccess->is_commented_section_exists();
			// set correct $section-updated flag.
			$section_updated = $enable === $section_exists;
		}

		if ($section_updated) {
			$enabled = $this->is_enabled();

			// save $expire value to options.
			$this->_options->update_option('browser_cache_expire_days', $expire_days);
			$this->_options->update_option('browser_cache_expire_hours', $expire_hours);

			if (is_wp_error($enabled)) {
				return array(
					'success' => true,
					'enabled' => $enabled,
					'error_message' => $enabled->get_error_message(),
				);
			} else {
				return array(
					'success' => true,
					'enabled' => $enabled,
					'message' => __('We successfully updated your .htaccess file.', 'wp-optimize'),
				);
			}
		} else {
			$cache_section = $this->prepare_browser_cache_section($expire_days, $expire_hours);

			if ($enable) {
				// translators: %s is a file name
				$message = sprintf(__("We can't update your %s file.", 'wp-optimize'), $this->_htaccess->get_filename()) . ' ' . __('Please try to add following lines manually:', 'wp-optimize');
				$output = htmlentities($this->_htaccess->get_section_begin_comment() . PHP_EOL .
					join(PHP_EOL, $this->_htaccess->get_flat_array($cache_section)).
					PHP_EOL . $this->_htaccess->get_section_end_comment());
			} else {
				// translators: %s is a file name
				$message = sprintf(__("We can't update your %s file.", 'wp-optimize'), $this->_htaccess->get_filename()) . ' ' . __('Please try to remove following lines manually:', 'wp-optimize');
				$output = htmlentities($this->_htaccess->get_section_begin_comment() . PHP_EOL .
					' ... ... ... '.
					PHP_EOL . $this->_htaccess->get_section_end_comment());
			}

			return array(
				'success' => false,
				'enabled' => $this->is_enabled(),
				'error_message' => $message,
				'output' => $output,
			);
		}
	}

	/**
	 * Use $days an $hours values to build correct time interval as a string like '2 days 3 hours' or empty string if date is empty.
	 *
	 * @param int $days
	 * @param int $hours
	 * @return string
	 */
	private function prepare_interval(int $days, int $hours): string {

		if (0 === $days && 0 === $hours) {
			return '';
		}

		$parts = array();

		// if hours value more than one day then fix it.
		$days += floor($hours / 24);
		$hours = $hours % 24;

		$years = floor($days / 365);
		$days = $days % 365;
		$months = floor($days / 30);
		$days = $days % 30;

		if ($years > 0) {
			$parts[] = $years . ($years > 1 ? ' years' : ' year');
		}

		if ($months > 0) {
			$parts[] = $months . ($months > 1 ? ' months' : ' month');
		}

		if ($days > 0) {
			$parts[] = $days . ($days > 1 ? ' days' : ' day');
		}

		if ($hours > 0) {
			$parts[] = $hours . ($hours > 1 ? ' hours' : ' hour');
		}

		return join(' ', $parts);
	}

	/**
	 * Converts a human-readable time string into total seconds
	 *
	 * @param int $days
	 * @param int $hours
	 * @return string Total number of seconds
	 */
	private function convert_to_seconds(int $days, int $hours): string {
		// Split the string into parts
		$total_seconds = $days * 24 * 60 * 60 + $hours * 60 * 60;

		return strval($total_seconds);
	}

	/**
	 * Build browser cache section array.
	 *
	 * @param int $expire_days
	 * @param int $expire_hours
	 * @return array
	 */
	public function prepare_browser_cache_section(int $expire_days, int $expire_hours): array {
		$expire = $this->prepare_interval($expire_days, $expire_hours);
		$max_age = $this->convert_to_seconds($expire_days, $expire_hours);

		return array(
			array(
				'<IfModule mod_setenvif.c>',
				'SetEnvIf Request_URI "/wp-admin/" WPO_NO_CACHE',
				'</IfModule>',
			),
			array(
				'<IfModule mod_expires.c>',
				'ExpiresActive On',
				'ExpiresByType text/css "access '.$expire.'"',
				'ExpiresByType text/html "access '.$expire.'"',
				'ExpiresByType image/gif "access '.$expire.'"',
				'ExpiresByType image/png "access '.$expire.'"',
				'ExpiresByType image/jpg "access '.$expire.'"',
				'ExpiresByType image/jpeg "access '.$expire.'"',
				'ExpiresByType image/webp "access '.$expire.'"',
				'ExpiresByType image/x-icon "access '.$expire.'"',
				'ExpiresByType application/pdf "access '.$expire.'"',
				'ExpiresByType application/javascript "access '.$expire.'"',
				'ExpiresByType text/x-javascript "access '.$expire.'"',
				'ExpiresByType application/x-shockwave-flash "access '.$expire.'"',
				'ExpiresByType application/json "access 0 seconds"',
				'ExpiresByType application/xml "access 0 seconds"',
				'ExpiresDefault "access '.$expire.'"',
				'</IfModule>',
			),
			'',
			array(
				'<IfModule mod_headers.c>',
				array(
					'<FilesMatch "\.(ico|jpe?g|png|gif|webp|swf)$">',
					'Header set Cache-Control "public, max-age='.$max_age.'" env=!WPO_NO_CACHE',
					'</FilesMatch>',
				),
				array(
					'<FilesMatch "\.(css)$">',
					'Header set Cache-Control "public, max-age='.$max_age.'" env=!WPO_NO_CACHE',
					'</FilesMatch>',
				),
				array(
					'<FilesMatch "\.(js)$">',
					'Header set Cache-Control "private, max-age='.$max_age.'" env=!WPO_NO_CACHE',
					'</FilesMatch>',
				),
				array(
					'<FilesMatch "\.(x?html?)$">',
					'Header set Cache-Control "private, must-revalidate, max-age='.$max_age.'" env=!WPO_NO_CACHE',
					'</FilesMatch>',
				),
				array(
					'<FilesMatch "\.php$">',
					'Header set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"',
					'</FilesMatch>',
				),
				'</IfModule>',
			),
			array(
				'<IfModule mod_headers.c>',
				'Header unset Expires env=WPO_NO_CACHE',
				'</IfModule>',
			),
			'',
			'#Disable ETag',
			'FileETag None',
		);
	}
}
