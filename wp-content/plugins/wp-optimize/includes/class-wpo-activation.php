<?php
if (!defined('ABSPATH')) die('Access denied.');

if (!class_exists('WPO_Activation')) :

class WPO_Activation {

	/**
	 * Actions to be performed upon plugin activation
	 *
	 * @return void
	 */
	public static function actions(): void {
		self::check_minimum_requirements();
		self::check_user_capability();
		self::handle_activation_type();

		WP_Optimize()->get_options()->set_default_options();
		WP_Optimize()->get_minify()->plugin_activate();
		WP_Optimize()->get_gzip_compression()->restore();
		WP_Optimize()->get_browser_cache()->restore();
		WP_Optimize()->get_table_management()->create_plugin_tables();
		WP_Optimize()->get_webp_instance()->plugin_activate();

		self::init_batch_processing();
		self::maybe_init_premium();
	}

	/**
	 * Check if minimum requirements are met, deactivate and die if not
	 *
	 * @return void
	 */
	private static function check_minimum_requirements(): void {
		if (!WP_Optimize()->is_minimum_requirement_met()) {
			WP_Optimize()->add_notice_minimum_requirements_not_met();
			WP_Optimize()->deactivate_plugin();
			WP_Optimize()->die_minimum_requirement_not_met();
		}
	}

	/**
	 * Check if the current user has permission to activate the plugin on multisite
	 *
	 * @return void
	 */
	private static function check_user_capability(): void {
		if (is_multisite() && !WP_Optimize()->current_user_can('manage_network_options')) {
			self::deactivate_and_die();
		}
	}

	/**
	 * Check if this is a reactivation or a new activation and set up accordingly
	 *
	 * @return void
	 */
	private static function handle_activation_type(): void {
		if (!self::is_reactivated()) {
			self::set_as_newly_activated();
			WP_Optimize()->get_onboarding()->activate_onboarding_wizard();
		}
	}

	/**
	 * When non network admin tries to activate plugin, deactivate it and die with a message
	 *
	 * @return void
	 */
	private static function deactivate_and_die(): void {
		deactivate_plugins(plugin_basename(WPO_PLUGIN_MAIN_PATH . 'wp-optimize.php'));
		wp_die(esc_html__('Only Network Administrator can activate the WP-Optimize plugin.', 'wp-optimize') .
			' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('go back', 'wp-optimize') . '</a>');
	}

	/**
	 * Decides whether the plugin is newly installed and activated or already installed and reactivated
	 *
	 * @return bool
	 */
	private static function is_reactivated(): bool {
		return (bool) WP_Optimize()->get_options()->get_option('last-optimized');
	}

	/**
	 * Set plugin option `newly-activated` as `true`
	 *
	 * @return void
	 */
	private static function set_as_newly_activated(): void {
		WP_Optimize()->get_options()->update_option('newly-activated', true);
	}

	/**
	 * Make use of Task Manager library
	 *
	 * @return void
	 */
	private static function init_batch_processing(): void {
		if (!class_exists('Updraft_Tasks_Activation')) {
			require_once(WPO_PLUGIN_MAIN_PATH . 'vendor/team-updraft/common-libs/src/updraft-tasks/class-updraft-tasks-activation.php');
		}
		Updraft_Tasks_Activation::init(WPO_PLUGIN_SLUG);
		Updraft_Tasks_Activation::reinstall_if_needed();
	}

	/**
	 * Initialize premium plugin if the premium version is installed
	 *
	 * @return void
	 */
	private static function maybe_init_premium(): void {
		if (self::is_premium()) {
			self::init_premium();
		}
	}

	/**
	 * Decides whether activate plugin is premium version or not
	 *
	 * @return bool
	 */
	private static function is_premium(): bool {
		return file_exists(WPO_PLUGIN_MAIN_PATH . 'premium.php');
	}

	/**
	 * Run premium plugin activation actions
	 *
	 * @return void
	 */
	private static function init_premium(): void {
		if (!class_exists('WP_Optimize_Premium')) {
			include_once(WPO_PLUGIN_MAIN_PATH . 'premium.php');
		}
		WP_Optimize_Premium()->plugin_activation_actions();
	}
}
endif;
