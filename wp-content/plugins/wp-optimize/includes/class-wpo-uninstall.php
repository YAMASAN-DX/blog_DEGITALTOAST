<?php
if (!defined('ABSPATH')) die('Access denied.');

if (!class_exists('WPO_Uninstall')) :

class WPO_Uninstall {

	/**
	 * Actions to be performed upon plugin uninstallation
	 *
	 * @return void
	 */
	public static function actions(): void {
		self::require_dependency();

		self::maybe_delete_premium_cache_data();
		if (self::is_plugin_active()) {
			return;
		}

		WP_Optimize()->get_gzip_compression()->disable();
		WP_Optimize()->get_browser_cache()->disable();
		WP_Optimize()->get_options()->delete_all_options();
		WP_Optimize()->get_minify()->plugin_uninstall();
		WP_Optimize()->get_options()->wipe_settings();
		WP_Optimize()->delete_transients_and_semaphores();
		WP_Optimize()->get_table_management()->delete_plugin_tables();
		Updraft_Tasks_Activation::uninstall(WPO_PLUGIN_SLUG);
		self::delete_wpo_folder();
		self::delete_htaccess_file_in_uploads_folder();
		self::cron_deactivate();
	}

	/**
	 * `wpo_delete_files` defined in dependency
	 *
	 * @return void
	 */
	private static function require_dependency(): void {
		require_once WPO_PLUGIN_MAIN_PATH . 'cache/file-based-page-cache-functions.php';
	}

	/**
	 * Delete cache data created by premium-only features.
	 *
	 * Only runs when premium is not active, to avoid wiping premium data
	 * while the premium version is still installed.
	 *
	 * @return void
	 */
	private static function delete_premium_cache_data(): void {
		if (class_exists('WPO_Gravatar_Data')) {
			wpo_delete_files(WPO_Gravatar_Data::WPO_CACHE_GRAVATAR_DIR);
		}

		if (class_exists('WP_Optimize_Minify_Analytics')) {
			wpo_delete_files(WP_Optimize_Minify_Analytics::WPO_CACHE_GTAG_DIR);
		}

		if (class_exists('WP_Optimize_Lazy_Load')) {
			WP_Optimize_Lazy_Load::instance()->delete_image_cache();
		}
	}

	/**
	 * Returns absolute path to uploads folder
	 *
	 * @return string
	 */
	private static function get_upload_basedir(): string {
		$upload_dir = wp_get_upload_dir();
		return trailingslashit($upload_dir['basedir']);
	}

	/**
	 * Returns an array of sub folders in `uploads/wpo` folder
	 *
	 * @return array<string>
	 */
	private static function get_wpo_sub_folders(): array {
		$sub_folders =  array(
			'add-type',
			'content-digest',
			'crash-tester',
			'directory-index',
			'header-set',
			'images',
			'module-loaded',
			'rewrite',
			'server-signature',
			'logs',
		);

		/**
		 * @var array<string> $filtered_sub_folders
		 */
		$filtered_sub_folders = apply_filters('wpo_uploads_sub_folders', $sub_folders);
		return is_array($filtered_sub_folders) ? $filtered_sub_folders : $sub_folders;
	}

	/**
	 * Returns an array of known files in `uploads/wpo` root folder
	 *
	 * @return array<string>
	 */
	private static function get_wpo_root_files(): array {
		return array(
			'wpo-plugins-tables-list.json'
		);
	}

	/**
	 * Delete `uploads/wpo` folder, its known sub folders, and known files
	 *
	 * @return void
	 */
	public static function delete_wpo_folder(): void {
		self::require_dependency();
		$wpo_folder = self::get_wpo_folder();

		if (!is_dir($wpo_folder)) {
			return;
		}

		// Even though `wpo_delete_files()` can delete recursively, we can't be sure `wpo` folder
		// only contains our own files and folders, we need to be specific here about what is going to be deleted
		$wpo_sub_folders = self::get_wpo_sub_folders();
		foreach ($wpo_sub_folders as $folder) {
			wpo_delete_files($wpo_folder . $folder);
		}
		self::delete_wpo_root_files();

		self::maybe_delete_empty_folder($wpo_folder);
	}

	/**
	 * Removes given folder, if it is empty
	 *
	 * @param string $wpo_folder Path of folder to check, and delete
	 *
	 * @return void
	 */
	private static function maybe_delete_empty_folder(string $wpo_folder): void {
		// phpcs:disable
		// Generic.PHP.NoSilencedErrors.Discouraged -- suppress warning if it arises due to race condition
		// WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Not applicable in this context
		// If `wpo` is empty after deleting our own stuff, folder itself is ours, so remove it too
		$files = @scandir($wpo_folder);
		if (false !== $files && 2 === count($files)) {
			@rmdir($wpo_folder);
		}
		// phpcs:enable
	}
	
	/**
	 * Deletes the .htaccess file in the uploads folder if it exists and is empty.
	 *
	 * @return void
	 */
	private static function delete_htaccess_file_in_uploads_folder(): void {
		$htaccess_file = self::get_upload_basedir() . '.htaccess';
		if (is_file($htaccess_file) && 0 === filesize($htaccess_file)) {
			wp_delete_file($htaccess_file);
		}
	}

	/**
	 * Delete known root-level files
	 *
	 * @return void
	 */
	private static function delete_wpo_root_files(): void {
		$wpo_root_files = self::get_wpo_root_files();
		$wpo_folder = self::get_wpo_folder();
		foreach ($wpo_root_files as $file) {
			wpo_delete_files($wpo_folder . $file, false);
		}
	}

	/**
	 * Checks whether premium or free version of WPO plugin is active
	 *
	 * @return bool
	 */
	private static function is_plugin_active(): bool {
		return WP_Optimize()->is_active('premium') || WP_Optimize()->is_active();
	}

	/**
	 * Deletes premium cache data, if premium version of plugin is not active
	 *
	 * @return void
	 */
	private static function maybe_delete_premium_cache_data(): void {
		if (!WP_Optimize()->is_active('premium')) {
			self::delete_premium_cache_data();
		}
	}

	/**
	 * Deactivates cron jobs
	 *
	 * @return void
	 */
	private static function cron_deactivate(): void {
		wp_clear_scheduled_hook('process_smush_tasks');
		WP_Optimize()->wpo_cron_deactivate();
	}

	/**
	 * Full path to `wpo` folder in uploads directory
	 *
	 * @return string
	 */
	private static function get_wpo_folder(): string {
		return self::get_upload_basedir() . trailingslashit('wpo');
	}
}

endif;
