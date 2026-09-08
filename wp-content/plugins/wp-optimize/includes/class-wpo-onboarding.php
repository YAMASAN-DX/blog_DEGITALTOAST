<?php

use Updraftplus\Wp_Optimize\Wizard\Onboarding\Onboarding;

if (!defined('ABSPATH')) die('No direct access allowed');

require_once(WPO_PLUGIN_MAIN_PATH . 'vendor/team-updraft/lib-onboarding-wizard/autoload.php');

/**
 * Class WPO_Onboarding
 */
class WPO_Onboarding {

	const MAILING_LIST_FREE_ID    = 132;

	const MAILING_LIST_PREMIUM_ID = 133;

	const MAILING_LIST_ENDPOINT   = 'https://teamupdraft.com/?fluentcrm=1&route=contact&hash=69902751-58c5-460b-bd9f-456d62033c2b';

	private $is_premium = false;

	private $prefix = 'wp-optimize';

	private $caller_slug = 'wp-optimize';

	private $upgrade_url = '';

	private $is_multisite;

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
		$this->is_premium = WP_Optimize::is_premium();
		$this->is_multisite = is_multisite();

		if ($this->is_multisite) {
			$this->prefix = 'wp-optimize-mu';
		}
		$this->upgrade_url = WP_Optimize()->premium_version_link;
	}

	/**
	 * Get a singleton instance
	 *
	 * @return WPO_Onboarding
	 */
	public static function instance(): WPO_Onboarding {
		static $instance = null;
		if (null === $instance) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Setup hooks.
	 *
	 * @return void
	 */
	public function init(): void {

		add_action( $this->prefix.'_onboarding_update_options', array($this, 'update_step_settings'), 10, 2 );
		add_filter( $this->prefix.'_onboarding_steps', array($this, 'load_steps'));

		$this->setup_onboarding();
	}

	/**
	 * Get Features Status
	 *
	 * @param array $settings    Settings data
	 * @param array $step_fields Step Fields data
	 *
	 * @return void
	 */
	public function update_step_settings(array $settings, array $step_fields): void {
		$step_ids = array_flip(array_column($step_fields, 'id'));

		if (empty($settings)) {
			return;
		}
		foreach ($settings as $setting) {
			if (empty($setting['id'])) continue;

			if (!isset($step_ids[$setting['id']])) continue;

			$is_lock = isset($setting['is_lock']) ? (bool) $setting['is_lock'] : false;
			if ($is_lock) {
				continue;
			}

			$id = (string) $setting['id'];
			$value = isset($setting['value']) ? (bool) $setting['value'] : false;

			$this->apply_setting($id, $value);
		}
	}

	/**
	 * Dispatch a setting change to its handler.
	 *
	 * @param string $id    Setting identifier.
	 * @param bool   $value Whether to enable or disable the setting.
	 * @return void
	 */
	private function apply_setting(string $id, bool $value): void {
		$handlers = array(
			'enable_caching_onboarding'            => array($this, 'apply_caching_setting'),
			'enable_minify_onboarding'             => array($this, 'apply_minify_setting'),
			'enable_image_optimization_onboarding' => array($this, 'apply_image_setting'),
		);

		if (isset($handlers[$id])) {
			call_user_func($handlers[$id], $value);
		}
	}

	/**
	 * Enable or disable page caching.
	 *
	 * Some related handling is intentionally commented out pending further work.
	 *
	 * @param bool $value True to enable, false to disable.
	 * @return void
	 */
	private function apply_caching_setting(bool $value): void {
		$page_cache = WP_Optimize()->get_page_cache();

		if ($value) {
			$page_cache->enable(true);
			// $this->enable_gzip();
			// $this->enable_browser_cache();
		} else {
			$page_cache->disable();
			// WP_Optimize()->get_gzip_compression()->disable();
			// WP_Optimize()->get_browser_cache()->disable();
		}

		if ($this->is_premium) {
			// $this->set_show_avatars($value);
			WP_Optimize_LCP_Settings::instance()->update_setting($value);
		}
	}

	/**
	 * Enable gzip compression.
	 *
	 * @return void
	 */
	private function enable_gzip(): void {
		WP_Optimize()->get_gzip_compression()->enable_gzip_command_handler(array('enable' => true));
	}

	/**
	 * Enable browser cache using saved expiry options.
	 *
	 * @return void
	 */
	private function enable_browser_cache(): void {
		$options = WP_Optimize()->get_options();
		WP_Optimize()->get_browser_cache()->enable_browser_cache_command_handler(array(
			'browser_cache_expire_days'  => $options->get_option('browser_cache_expire_days', '28'),
			'browser_cache_expire_hours' => $options->get_option('browser_cache_expire_hours', '0'),
		));
	}

	/**
	 * Enable or disable avatar display (premium only).
	 *
	 * @param bool $value True to show avatars, false to hide.
	 * @return void
	 */
	private function set_show_avatars(bool $value): void {
		$commands = new WP_Optimize_Cache_Commands_Premium();
		if (is_callable(array($commands, 'change_show_avatars'))) {
			$commands->change_show_avatars(array('show_avatars' => $value));
		}
	}

	/**
	 * Enable or disable CSS/JS minification.
	 *
	 * @param bool $value True to enable, false to disable.
	 * @return void
	 */
	private function apply_minify_setting(bool $value): void {
		$params = array('enabled' => $value);

		if ($this->is_premium) {
			$params['enable_capo_js']           = $value;
			$params['host_local_google_fonts']  = $value;
		}

		wp_optimize_minify_config()->update($params);
	}

	/**
	 * Enable or disable image optimization and related premium features like lazy load and image dimensions.
	 *
	 * @param bool $value True to enable, false to disable.
	 * @return void
	 */
	private function apply_image_setting(bool $value): void {
		Updraft_Smush_Manager()->update_smush_options(array('autosmush' => $value));

		WP_Optimize()->get_webp_instance()->save_webp_settings(array('webp_conversion' => $value));

		if (!$this->is_premium) return;

		WP_Optimize()->get_options()->save_lazy_load_settings(array(
			'lazyload' => array(
				'images'          => $value,
				'iframes'         => $value,
				'backgrounds'     => $value,
				'youtube_preview' => $value,
				'skip_classes'    => '',
			),
		));

		WP_Optimize()->get_options()->update_option('image_dimensions', (int) $value);
	}

	/**
	 * Load the steps for the onboarding process.
	 *
	 * Builds and returns the steps depending on the user's
	 * premium status and license connection.
	 *
	 * @param array $steps Unused parameter for compatibility.
	 *
	 * @return array Ordered an array of steps for the onboarding wizard.
	 */
	public function load_steps($steps = array()): array {
		// Build steps
		$steps = array(); // To override all previous steps.

		// Step 1: Feature settings
		$steps[] = $this->features_step();

		// Step 2: License step (only for premium users without a connected license)
		if ($this->should_add_license_step()) {
			$steps[] = $this->license_step();
		}

		// Step 3: Newsletter signup
		$steps[] = $this->newsletter_step();

		// Step 4: Recommended plugins
		$steps[] = $this->plugins_install_step();

		// Step 5: Go Premium step (only for non-premium users)
		if (!$this->is_premium) {
			$steps[] = $this->go_premium_step();
		}

		// Step 6: Final step
		$steps[] = $this->last_step();

		return $steps;
	}

	/**
	 * Determine if the license step should be added.
	 *
	 * @return bool True if the plugin is premium but the license is not connected.
	 */
	private function should_add_license_step(): bool {
		return $this->is_premium && !$this->is_license_connected();
	}

	/**
	 * Build the license activation step for the onboarding wizard.
	 *
	 * This step includes fields for email and password, conditional
	 * titles/subtitles based on license status, and a button to
	 * activate the license.
	 *
	 * @return array Step configuration including ID, type, icon, titles, subtitles, fields, and button info.
	 */
	private function license_step(): array {
		return array(
			'id'       => 'license',
			'type'     => 'license',
			'icon'     => 'user-lock',
			'title'    => __('Connect and activate your license', 'wp-optimize'),
			'title_conditional' => array(
				'licenseActivated' => __('License activated!', 'wp-optimize'),
				'isUpdating' => __('Activating your Premium license...', 'wp-optimize'),
			),
			'subtitle' => __('Please enter your TeamUpdraft credentials to start using Premium features.', 'wp-optimize'),
			'subtitle_conditional' => array(
				'licenseActivated' => '',
				'isUpdating' => '',
			),
			'fields'   => array(
				array(
					'id'    => 'registration_email',
					'type'  => 'email',
					'label' => __('Email', 'wp-optimize'),
				),
				array(
					'id'    => 'registration_password',
					'type'  => 'password',
					'label' => __('Password', 'wp-optimize'),
				),
			),
			'button'   => array(
				'id'   => 'activate',
				'label'=> __('Confirm and activate', 'wp-optimize'),
				'icon' => 'EastRoundedIcon',
			),
			'skip_step' => true
		);
	}

	/**
	 * Build the step for the best-practice features in the onboarding wizard.
	 *
	 * @return array Step data including title, subtitle, feature fields, button info, and skip step info.
	 */
	private function features_step(): array {
		$features = $this->get_feature_settings();
		return array(
			'id'       => 'page_features',
			'type'     => 'settings',
			'title'    => __('Your site is already faster!', 'wp-optimize'),
			'subtitle' => __('We enabled these settings optimised for your site.', 'wp-optimize').' '.__('Turn off anything you don\'t need.', 'wp-optimize'),
			'fields'   => $features,
			'button'   => array(
				'id'   => 'save',
				'label'=> __('Save and continue', 'wp-optimize'),
				'icon' => 'EastRoundedIcon',
			),
			'skip_step' => array(
				'icon'    => 'info',
				'tooltip' => array(
					'text' => __('All above features will be disabled if you skip.', 'wp-optimize'),
				),
			),
		);
	}

	/**
	 * Build the step for a newsletter in the onboarding wizard.
	 *
	 * @return array Step data including title, subtitle, email fields, and button info.
	 */
	private function newsletter_step(): array {
		$email_fields = $this->get_email_fields();
		return array(
			'id'       => 'email',
			'type'     => 'email',
			'icon'     => 'mail',
			'title'    => __('Get lightning-fast insights!', 'wp-optimize'),
			'subtitle' => __('Join our newsletter for speed-optimization tips and best practices.', 'wp-optimize').' '.__('Delivered straight to your inbox.', 'wp-optimize'),
			'fields'   => $email_fields,
			'button'   => array(
				'id'   => 'save',
				'label'=> __('Save and continue', 'wp-optimize'),
				'icon' => 'EastRoundedIcon',
			),
			'skip_step' => true
		);
	}

	/**
	 * Build the step for the recommended plugins installation in the onboarding wizard.
	 *
	 * @return array Step data including title, subtitle, plugin fields, and button info.
	 */
	private function plugins_install_step(): array {
		return array(
			'id'             => 'plugins',
			'type'           => 'plugins',
			'icon'           => 'plugin',
			'first_run_only' => false,
			'title'          => __('Recommended for your setup', 'wp-optimize'),
			'title_conditional' => array(
				'all_installed' => __('Best-practice plugins enabled', 'wp-optimize'),
			),
			'subtitle'       => __('We\'ve carefully handpicked these plugins to match your website\'s setup, so everything works just the way it should.', 'wp-optimize'),
			'subtitle_conditional' => array(
				'all_installed' => __('Wow, your site already meets all our plugin recommendations, let\'s move on!', 'wp-optimize'),
			),
			'fields'         => array(
				array(
					'id'    => 'plugins',
					'type'  => 'plugins',
				),
			),
			'button'         => array(
				'id'    => 'save',
				'label' => __('Install and continue', 'wp-optimize'),
				'icon' => 'EastRoundedIcon',
			),
			'skip_step' => true
		);
	}

	/**
	 * Build the "Go Premium" onboarding step array.
	 *
	 * @return array Step data including title, subtitle, bullets, and button info.
	 */
	private function go_premium_step(): array {
		$go_premium_step_bullets = $this->get_go_premium_bullets();
		return array(
			'id'        => 'go_premium',
			'type'      => 'go_premium',
			'icon'      => 'bolt',
			'title'     => __('Upgrade to Premium', 'wp-optimize'),
			'subtitle'  => __('The complete optimization suite with safe defaults and expert help.', 'wp-optimize'),
			'bullets'   => $go_premium_step_bullets,
			'enable_premium_btn' => true,
			'premium_btn_text' => __('Upgrade to Premium', 'wp-optimize'),
			'skip_step' => true
		);
	}

	/**
	 * Build the final "Completed" onboarding step array.
	 *
	 * @return array Step data including title, subtitle, bullets, and finish button info.
	 */
	private function last_step(): array {
		list($last_step_subtitle, $last_step_subtitle_is_installing) = $this->get_last_step_subtitles();

		$last_step_bullets = $this->get_last_step_bullets();

		return array(
			'id'        => 'completed',
			'type'      => 'completed',
			'icon'      => 'CheckRoundedIcon',
			'title'     => __('You\'re all set!', 'wp-optimize'),
			'title_conditional' => array(
				'isInstalling' => __('Almost done, finalizing...', 'wp-optimize'),
			),
			'subtitle'  => $last_step_subtitle,
			'subtitle_conditional' => array(
				'isInstalling' => $last_step_subtitle_is_installing
			),
			'bullets'   => $last_step_bullets,
			'button'  => array(
				'id'    => 'finish',
				'label' => __('Go to settings', 'wp-optimize'),
			),
		);
	}

	/**
	 * Check if the plugin license is connected via the Updraft updater instance.
	 *
	 * @return bool True if the license is connected, false otherwise.
	 */
	private function is_license_connected(): bool {
		global $updraft_updater_instance;

		if (!isset($updraft_updater_instance)) {
			return false;
		}

		return (bool) $updraft_updater_instance->is_connected();
	}

	/**
	 * Get bullet points for the "Go Premium" step.
	 *
	 * Each bullet highlights a benefit of upgrading to the Premium version.
	 *
	 * @return array Array of bullet strings, each wrapped in its own array.
	 */
	private function get_go_premium_bullets(): array {
		return array(
			array(
				__('Ranking high', 'wp-optimize')
			),
			array(
				__('Loading at top speed', 'wp-optimize')
			),
			array(
				__('Turning more visits into sales', 'wp-optimize'),
			),
			array(
				__('Serving visitors & customers better', 'wp-optimize'),
			),
		);
	}

	/**
	 * Get subtitles for the last onboarding step.
	 *
	 * Provides two strings:
	 * 1. Standard subtitle for the completed step.
	 * 2. Subtitle showed when redirecting to settings (with a link to the plugin page).
	 *
	 * @return array Array containing two strings: [standard_subtitle, installing_subtitle].
	 */
	private function get_last_step_subtitles(): array {

		$subtitle = __('WP-Optimize is running.', 'wp-optimize');
		$subtitle .= ' ';
		$subtitle .= __('You can review and adjust any settings anytime from the settings page.', 'wp-optimize');

		$installing = __('Setting things up in the background...', 'wp-optimize') . '<br>';
		$installing .= __('This will only take a moment.', 'wp-optimize');

		return array($subtitle, $installing);
	}

	/**
	 * Get the list of feature bullets shown in the final onboarding step.
	 *
	 * Returns premium-only feature bullet points.
	 * If the user is not premium, an empty array is returned.
	 *
	 * @return array List of bullet point groups.
	 */
	private function get_last_step_bullets(): array {
		if (!$this->is_premium) {
			return array();
		}

		return array(
			array(
				__('Lazy loading', 'wp-optimize'),
				__('Remove unused images', 'wp-optimize'),
			),
			array(
				__('Multisite optimization', 'wp-optimize'),
				__('Preload key requests', 'wp-optimize'),
			),
			array(
				__('User and role-based cache', 'wp-optimize'),
				__('Premium support and more', 'wp-optimize'),
			),
		);
	}

	/**
	 * Get email-related form fields for the onboarding wizard.
	 *
	 * Returns configuration for email input and consent checkbox fields.
	 *
	 * @return array List of email field definitions.
	 */
	private function get_email_fields(): array {
		return array(
			array(
				'id'      => 'email_reports_mailinglist',
				'key'     => 'email_reports_mailinglist',
				'type'    => 'email',
				'label'   => __('Email', 'wp-optimize'),
				'default' => '',
			),
			array(
				'id'      => 'tips_tricks_mailinglist',
				'key'     => 'tips_tricks_mailinglist',
				'type'    => 'checkbox',
				'label'   => __('I agree to receive emails with tips, updates and marketing content.',  'wp-optimize').' '.__('I understand I can unsubscribe at any time.', 'wp-optimize'),
				'default' => false,
				'show_privacy_link' => true,
			),
		);
	}

	/**
	 * Get the list of feature settings for the onboarding wizard.
	 *
	 * Generates an array of feature configuration options, including
	 * labels, types, defaults, and details.
	 *
	 * @return array List of feature setting definitions.
	 */
	private function get_feature_settings(): array {
		$label_details = __('Details', 'wp-optimize');

		$default_config = array(
			'type'    => 'checkbox',
			'subtype' => 'switch',
			'layout'  => 'card',
			'show_details_label' => $label_details,
			'hide_details_label' => $label_details,
			'default' => true,
		);

		return array(
			array_merge($default_config, $this->get_cache_feature()),
			array_merge($default_config, $this->get_minify_feature()),
			array_merge($default_config, $this->get_image_feature()),
		);
	}

	/**
	 * Build the cache feature definition for the onboarding wizard.
	 *
	 * Covers page caching.
	 * For premium users, it also includes the Auto LCP preload description.
	 *
	 * @return array Feature field definition array.
	 */
	private function get_cache_feature(): array {
		$details = __('Enables page caching.', 'wp-optimize');

		if ($this->is_premium) {
			$details .= ' '.__('Automatically improves Largest Contentful Paint (LCP) when supported.', 'wp-optimize');
		}

		$details .= ' '.__('You can turn these off individually anytime.', 'wp-optimize');
		return array(
			'id'      => 'enable_caching_onboarding',
			'key'     => 'enable_caching_onboarding',
			'label'   => __('Faster pages', 'wp-optimize'),
			'icon'    => 'speed',
			'summary' => __('Pages refresh in the background, so return visits are always fast.', 'wp-optimize'),
			'details' => $details,
		);
	}

	/**
	 * Build the minification feature definition for the onboarding wizard.
	 *
	 * Covers CSS and JS minification and file bundling.
	 * For premium users, it also includes Capo.js and local Google Fonts hosting.
	 *
	 * @return array Feature field definition array.
	 */
	private function get_minify_feature(): array {
		$details = __('Minifies CSS and JavaScript, and bundles related files together.', 'wp-optimize');
		$details .= ' '.__('Payment provider scripts are excluded automatically.', 'wp-optimize');

		if ($this->is_premium) {
			$details .= ' '.__('Includes Capo.js for smarter script loading and hosts Google Fonts locally to reduce external requests.', 'wp-optimize');
		}

		return array(
			'id'      => 'enable_minify_onboarding',
			'key'     => 'enable_minify_onboarding',
			'icon'    => 'devices',
			'label'   => __('Lighter on every device', 'wp-optimize'),
			'summary' => __('Smaller files reach browsers faster, especially on mobile.', 'wp-optimize'),
			'details' => $details,
		);
	}

	/**
	 * Build the image optimization feature definition for the onboarding wizard.
	 *
	 * Covers image compression and WebP conversion.
	 * For premium users, it also includes lazy loading and image dimensions.
	 *
	 * @return array Feature field definition array.
	 */
	private function get_image_feature(): array {
		$details = __('Bundles image compression and WebP conversion.', 'wp-optimize');
		$details .= ' '.__('New uploads are compressed automatically and originals are backed up.', 'wp-optimize');

		if ($this->is_premium) {
			$details .= ' '.__('Also adds image dimensions and lazy loading to improve perceived speed.', 'wp-optimize');
		}

		$details .= ' '.__('You can change this anytime.', 'wp-optimize');

		return array(
			'id'      => 'enable_image_optimization_onboarding',
			'key'     => 'enable_image_optimization_onboarding',
			'icon'    => 'images',
			'label'   => __('Optimised media', 'wp-optimize'),
			'summary' => __('We\'ll compress your images.', 'wp-optimize').' '.__('Same quality, faster load.', 'wp-optimize'),
			'details' => $details,
		);
	}

	/**
	 * Array of UTM parameters for the onboarding wizard.
	 *
	 * @param string $content UTM content.
	 * @param string $format  UTM creative_format.
	 *
	 * @return array
	 */
	private function get_utm_params_to_override($content = 'onboarding', $format = 'text'): array {
		$type = $this->is_premium ? 'prem' : 'free';
		return array(
			'utm_content'  => $content,
			'utm_campaign' => sprintf('paac-%s-onboarding-wizard', $type),
			'utm_creative_format'  => $format,
		);
	}

	/**
	 * Initialize the onboarding
	 *
	 * @return void
	 */
	public function setup_onboarding(): void {
		$onboarding = new Onboarding();
		if ($onboarding::is_onboarding_active($this->prefix, $this->caller_slug)) {

			$support_link = $this->is_premium ? WP_Optimize_Utils::add_utm_params('https://teamupdraft.com/support/premium-support/', $this->get_utm_params_to_override('premium-support')) : esc_url('https://wordpress.org/support/plugin/wp-optimize/');

			$onboarding->is_pro                         = $this->is_premium;
			$onboarding->logo_path                      = esc_url(trailingslashit(WPO_PLUGIN_URL) . 'images/notices/wp_optimize_logo.png');
			$onboarding->prefix                         = $this->prefix;
			$onboarding->plugin_name                    = $this->is_premium ? 'WP-Optimize Premium' : 'WP-Optimize';
			$onboarding->mailing_list                   = array($this->is_premium ? self::MAILING_LIST_PREMIUM_ID : self::MAILING_LIST_FREE_ID);
			$onboarding->mailing_list_endpoint          = self::MAILING_LIST_ENDPOINT;
			$onboarding->caller_slug                    = $this->caller_slug;
			$onboarding->capability                     = $this->is_multisite ? 'manage_network_options' : 'manage_options';
			$onboarding->support_url                    = $support_link;
			$onboarding->privacy_url_label              = __('Privacy Policy.', 'wp-optimize');
			$onboarding->privacy_statement_url          = WP_Optimize_Utils::add_utm_params('https://teamupdraft.com/privacy/', $this->get_utm_params_to_override('privacy-statement'));
			$onboarding->forgot_password_url            = WP_Optimize_Utils::add_utm_params('https://teamupdraft.com/my-account/lost-password/', $this->get_utm_params_to_override('forgot-password'));
			$onboarding->documentation_url              = WP_Optimize_Utils::add_utm_params('https://teamupdraft.com/documentation/wp-optimize/', $this->get_utm_params_to_override('documentation'));
			$onboarding->upgrade_url                    = WP_Optimize_Utils::add_utm_params($this->upgrade_url, $this->get_utm_params_to_override('upgrade-to-premium', 'button'), true);
			$onboarding->page_prefix                    = $this->caller_slug;
			$onboarding->version                        = WPO_VERSION;
			$onboarding->languages_dir                  = WPO_PLUGIN_MAIN_PATH . 'languages';
			$onboarding->text_domain                    = 'wp-optimize';
			$onboarding->exit_wizard_text               = __('Exit setup', 'wp-optimize');
			$onboarding->reload_settings_page_on_finish = true;
			$onboarding->udmupdater_muid = 2;
			$onboarding->udmupdater_slug = 'wp-optimize-premium';

			$onboarding->init();

		}
	}

	/**
	 * Enable the onboarding wizard for the plugin.
	 *
	 * This method performs two actions:
	 *
	 * 1. Sets an option using site option functions, so it works for both single and multisite to trigger the onboarding
	 *    wizard the next time a WP-Optimize page loads.
	 * 2. Removes the installation source flag, ensuring that the plugin does not
	 *    treat the current activation as originating from the onboarding wizard.
	 *
	 * WordPress Internally, uses the site wrappers for update_option() and delete_option()
	 * to ensure compatibility with both single-site and multisite environments.
	 *
	 * @return void
	 */
	public function activate_onboarding_wizard(): void {
		// to enable onboarding wizard on WP-Optimize pages
		update_site_option($this->prefix.'_start_onboarding', true);

		// to reset that this plugin was installed from the onboarding wizard
		delete_site_option('teamupdraft_installation_source_'.$this->caller_slug);
	}
}
