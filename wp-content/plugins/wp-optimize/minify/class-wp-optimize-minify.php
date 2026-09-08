<?php
if (!defined('ABSPATH')) die('No direct access allowed');

define('WP_OPTIMIZE_MINIFY_VERSION', '2.6.5');
define('WP_OPTIMIZE_MINIFY_DIR', dirname(__FILE__));
if (!defined('WP_OPTIMIZE_SHOW_MINIFY_ADVANCED')) define('WP_OPTIMIZE_SHOW_MINIFY_ADVANCED', false);

class WP_Optimize_Minify {

	/**
	 * Minify commands object
	 *
	 * @var WP_Optimize_Minify_Commands
	 */
	public $minify_commands;

	/**
	 * @var bool
	 */
	private $enabled;

	/**
	 * Constructor - Initialize actions and filters
	 *
	 * @return void
	 */
	public function __construct() {

		$this->minify_commands = new WP_Optimize_Minify_Commands();

		if (!class_exists('WP_Optimize_Minify_Config')) {
			include WP_OPTIMIZE_MINIFY_DIR.'/class-wp-optimize-minify-config.php';
		}

		$this->enabled = wp_optimize_minify_config()->is_enabled();

		$this->load_admin();

		add_filter('wpo_cache_admin_bar_menu_items', array($this, 'admin_bar_menu'), 30);
		
		if (WP_Optimize::is_premium()) {
			$this->load_premium();
		}

		/**
		 * Directory that stores the cache, including gzipped files and mobile specific cache
		 */
		if (!defined('WPO_CACHE_MIN_FILES_DIR')) define('WPO_CACHE_MIN_FILES_DIR', untrailingslashit(WP_CONTENT_DIR).'/cache/wpo-minify');
		if (!defined('WPO_CACHE_MIN_FILES_URL')) define('WPO_CACHE_MIN_FILES_URL', untrailingslashit(WP_CONTENT_URL).'/cache/wpo-minify');

		if (!class_exists('WP_Optimize_Minify_Cache_Functions')) {
			include WP_OPTIMIZE_MINIFY_DIR.'/class-wp-optimize-minify-cache-functions.php';
		}

		$this->load_frontend();

		// cron job to delete old wpo_min cache
		add_action('wpo_minify_purge_old_cache', array('WP_Optimize_Minify_Cache_Functions', 'purge_old'));
		
		add_action('wp_loaded', array($this, 'schedule_or_unschedule_purge_old_cache_event'));

		// Handle minify cache purging.
		add_action('wp_loaded', array($this, 'handle_purge_minify_cache'));

	}

	/**
	 * Schedule or unschedule purge old cache event.
	 *
	 * @return void
	 */
	public function schedule_or_unschedule_purge_old_cache_event() {
		if ($this->enabled) {
			$this->schedule_purge_old_cache_event();
		} else {
			$this->unschedule_purge_old_cache_event();
		}
	}

	/**
	 * Returns singleton instance object
	 *
	 * @return WP_Optimize_Minify Returns `WP_Optimize_Minify` object
	 */
	public static function instance() {
		static $_instance = null;
		if (null === $_instance) {
			$_instance = new self();
		}
		return $_instance;
	}

	/**
	 * Admin toolbar processing
	 *
	 * @param array        $menu_items
	 * @return array
	 */
	public function admin_bar_menu($menu_items) {
		$wpo_minify_options = wp_optimize_minify_config()->get();

		if (!$wpo_minify_options['enabled'] || !WP_Optimize()->current_user_can('manage_options') || !($wpo_minify_options['enable_css'] || $wpo_minify_options['enable_js'])) return $menu_items;
		
		$act_url = WP_Optimize_Utils::get_url_without_cache_purge_params();
		$cache_path = WP_Optimize_Minify_Cache_Functions::cache_path();
		$cache_size_info = '<h4>'.__('Minify cache', 'wp-optimize').'</h4><span><span class="label">'.__('Cache size:', 'wp-optimize').'</span> <span class="stats">'.esc_html(WP_Optimize_Minify_Cache_Functions::get_cachestats($cache_path['cachedir'])).'</span></span>';

		$menu_items[] = array(
			'id'    => 'wpo_minify_cache_stats',
			'title' => $cache_size_info,
			'meta'  => array(
				'class' => 'wpo-cache-stats',
			),
			'parent' => 'wpo_purge_cache',
		);

		$menu_items[] = array(
			'parent' => 'wpo_purge_cache',
			'id' => 'purge_minify_cache',
			'title' => __('Purge minify cache', 'wp-optimize'),
			'href' => add_query_arg('_wpo_purge_minify_cache', wp_create_nonce('wpo_purge_minify_cache'), $act_url),
		);
		return $menu_items;
	}

	/**
	 * Check if purge single page action sent and purge cache.
	 */
	public function handle_purge_minify_cache() {
		$wpo_minify_options = wp_optimize_minify_config()->get();
		if (!$wpo_minify_options['enabled'] || !WP_Optimize()->current_user_can('manage_options')) return;

		$is_cache_purged = TeamUpdraft\WP_Optimize\Includes\Fragments\fetch_superglobal('get', 'wpo_minify_cache_purged');
		if (null !== $is_cache_purged) {
			if (is_admin()) {
				$notice_function = 'notice_purge_minify_cache_' . ('false' === $is_cache_purged ? 'failure' : 'success');
				add_action('admin_notices', array($this, $notice_function));
				return;
			} else {
				$message =  'false' === $is_cache_purged ? __('Failed to purge the minify cache.', 'wp-optimize') : __('Minify cache purged', 'wp-optimize');
				printf('<script>window.onload = function() {alert("%s");}</script>', esc_js($message));
				return;
			}
		}

		$nonce = TeamUpdraft\WP_Optimize\Includes\Fragments\verify_nonce('_wpo_purge_minify_cache', 'wpo_purge_minify_cache');
		
		if ($nonce) {
			$success = false;

			// Purge minify
			$results = $this->minify_commands->purge_minify_cache();
			if ("caches cleared" === $results['result']) $success = true;

			// remove nonce from url and reload page.
			$redirect_url = WP_Optimize_Utils::get_url_without_cache_purge_params();
			$redirect_url = add_query_arg('wpo_minify_cache_purged', $success ? $success : 'false', $redirect_url);
			wp_redirect($redirect_url);
			
			exit;

		}
	}

	/**
	 * Load the admin class
	 *
	 * @return void
	 */
	private function load_admin() {
		if (!is_admin()) return;
		new WP_Optimize_Minify_Admin();
	}

	/**
	 * Load the frontend class
	 *
	 * @return void
	 */
	private function load_frontend() {
		if ($this->enabled) {
			new WP_Optimize_Minify_Front_End();
		}
	}

	/**
	 * Load the premium class
	 *
	 * @return void
	 */
	private function load_premium() {
		new WP_Optimize_Minify_Premium();
	}

	/**
	 * Run during activation
	 * Increment cache first as it will save files to that dir
	 *
	 * @return void
	 */
	public function plugin_activate() {
		// increment cache time
		if (class_exists('WP_Optimize_Minify_Cache_Functions')) {
			WP_Optimize_Minify_Cache_Functions::cache_increment();
		}
	}

	/**
	 * If the WP cron event for scheduling purging of the minify cache does not exist, then create it
	 */
	private function schedule_purge_old_cache_event() {
		if (!wp_next_scheduled('wpo_minify_purge_old_cache')) {
			wp_schedule_event(time() + 43200, 'daily', 'wpo_minify_purge_old_cache');
		}
	}

	/**
	 * Unschedule purging of the minify cache
	 *
	 * @return void
	 */
	private function unschedule_purge_old_cache_event() {
		// old cache purge event cron
		wp_clear_scheduled_hook('wpo_minify_purge_old_cache');
	}
	
	/**
	 * Run during plugin deactivation
	 *
	 * @return void
	 */
	public function plugin_deactivate() {
		if (class_exists('WP_Optimize_Minify_Cache_Functions') && WP_Optimize()->get_page_cache()->should_purge) {
			WP_Optimize_Minify_Cache_Functions::purge_temp_files();
			WP_Optimize_Minify_Cache_Functions::purge_old();
			WP_Optimize_Minify_Cache_Functions::purge_others();
		}

		$this->unschedule_purge_old_cache_event();
	}

	/**
	 * Run during plugin uninstall
	 *
	 * @return void
	 */
	public function plugin_uninstall() {
		// remove options from DB
		if (!function_exists('wp_optimize_minify_config')) {
			include WP_OPTIMIZE_MINIFY_DIR.'/class-wp-optimize-minify-config.php';
		}
		wp_optimize_minify_config()->purge();
		// remove minified files
		if (class_exists('WP_Optimize_Minify_Cache_Functions')) {
			WP_Optimize_Minify_Cache_Functions::purge();
			WP_Optimize_Minify_Cache_Functions::purge_others();
		}
	}

	/**
	 * Shows success notice for purge minify cache
	 */
	public function notice_purge_minify_cache_success() {
		$this->show_notice(__('The minify cache was successfully purged.', 'wp-optimize'), 'success');
	}

	/**
	 * Shows failure notice for purge minify cache
	 */
	public function notice_purge_minify_cache_failure() {
		$this->show_notice(__('Failed to purge the minify cache.', 'wp-optimize'), 'error');
	}

	/**
	 * Show notification in WordPress admin.
	 *
	 * @param string $message HTML (no further escaping is performed)
	 * @param string $type    error, warning, success, or info
	 */
	public function show_notice($message, $type) {
		global $current_screen;
		
		if ($current_screen && is_callable(array($current_screen, 'is_block_editor')) && $current_screen->is_block_editor()) :
		?>
			<script>
				window.addEventListener('load', function() {
					(function(wp) {
						if (window.wp && wp.hasOwnProperty('data') && 'function' == typeof wp.data.dispatch) {
							wp.data.dispatch('core/notices').createNotice(
								'<?php echo esc_js($type); ?>',
								'<?php echo wp_kses_post($message); ?>',
								{
									isDismissible: true,
								}
							);
						}
					})(window.wp);
				});
			</script>
		<?php else : ?>
			<div class="notice wpo-notice notice-<?php echo esc_attr($type); ?> is-dismissible">
				<p><?php echo wp_kses_post($message); ?></p>
			</div>
		<?php
		endif;
	}

	/**
	 * Check if current user can purge cache.
	 *
	 * @return bool
	 */
	public function can_purge_cache() {
		return WP_Optimize_Utils::current_user_can_purge_cache();
	}
}
