<?php
class SiteGuard_Menu_INIT extends SiteGuard_Base {
	function __construct() {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
	}
	function menu_styles() {
		wp_enqueue_style( 'siteguard-menu', SITEGUARD_URL_PATH . 'css/siteguard-menu.css' );
	}
	function add_pages() {
		//$icon = SITEGUARD_URL_PATH . 'images/plugin-icon.png';
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="15 23 180 250"><path fill="currentColor" d="M109.21835,25.548228 V 271.48515 c 5.75,-2.43 25.21165,-10.74515 34.15165,-16.49515 10.29,-6.62 22.8,-15.01 34.1,-28.69 14.14,-17.12 14.9,-36.12 14.9,-36.12 l 0.09,-48.46354 -39.05218,-16.86136 -0.0191,69.85988 -9.6809,4.09728 0.0191,-88.64641 48.75403,21.07201 L 192.5,67.33 Z M 99.556384,25.341068 17.71,67.44 l -0.216484,63.46617 48.606718,21.03719 0.113795,47.05416 -9.775218,-4.10201 0.0633,-36.54356 L 17.453516,141.49852 17.6,187.68 c 0,0 -0.08,13.61 8.91,29.77 4.96,8.92 15.82,21.92 37.91,36.07 14.13,9.05 29.195437,15.59515 35.145437,17.98515 l -0.06905,-123.69787 -42.955146,-18.43852 -0.09621,-41.110362 9.769005,-4.274029 -0.04811,38.761191 33.320462,14.47029 z"/></svg>' );
		$page = add_menu_page(
			__( 'SiteGuard', 'siteguard' ),
			__( 'SiteGuard', 'siteguard' ),
			'manage_options',
			'siteguard',
			array( $this, 'menu_dashboard' ),
			$icon
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Dashboard', 'siteguard' ),
			esc_html__( 'Dashboard', 'siteguard' ),
			'manage_options',
			'siteguard',
			array( $this, 'menu_dashboard' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Admin Page IP Filter', 'siteguard' ),
			esc_html__( 'Admin Page IP Filter', 'siteguard' ),
			'manage_options',
			'siteguard_admin_filter',
			array( $this, 'menu_admin_filter' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Rename Login', 'siteguard' ),
			esc_html__( 'Rename Login', 'siteguard' ),
			'manage_options',
			'siteguard_rename_login',
			array( $this, 'menu_rename_login' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );
		add_action( 'load-' . $page, array( $this, 'load_rename_login_early' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'CAPTCHA', 'siteguard' ),
			esc_html__( 'CAPTCHA', 'siteguard' ),
			'manage_options',
			'siteguard_captcha',
			array( $this, 'menu_captcha' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Same Login Error Message', 'siteguard' ),
			esc_html__( 'Same Login Error Message', 'siteguard' ),
			'manage_options',
			'siteguard_same_error',
			array( $this, 'menu_same_error' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Login Lock', 'siteguard' ),
			esc_html__( 'Login Lock', 'siteguard' ),
			'manage_options',
			'siteguard_login_lock',
			array( $this, 'menu_login_lock' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Login Alert', 'siteguard' ),
			esc_html__( 'Login Alert', 'siteguard' ),
			'manage_options',
			'siteguard_login_alert',
			array( $this, 'menu_login_alert' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Fail Once', 'siteguard' ),
			esc_html__( 'Fail Once', 'siteguard' ),
			'manage_options',
			'siteguard_fail_once',
			array( $this, 'menu_fail_once' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Protect XML-RPC', 'siteguard' ),
			esc_html__( 'Protect XML-RPC', 'siteguard' ),
			'manage_options',
			'siteguard_protect_xmlrpc',
			array( $this, 'menu_protect_xmlrpc' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Block Author Query', 'siteguard' ),
			esc_html__( 'Block Author Query', 'siteguard' ),
			'manage_options',
			'siteguard_author_query',
			array( $this, 'menu_block_author_query' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Update Notifications', 'siteguard' ),
			esc_html__( 'Update Notifications', 'siteguard' ),
			'manage_options',
			'siteguard_updates_notify',
			array( $this, 'menu_updates_notify' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'WAF Tuning Support', 'siteguard' ),
			esc_html__( 'WAF Tuning Support', 'siteguard' ),
			'manage_options',
			'siteguard_waf_tuning_support',
			array( $this, 'menu_waf_tuning_support' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );

		$page = add_submenu_page(
			'siteguard',
			esc_html__( 'Login History', 'siteguard' ),
			esc_html__( 'Login History', 'siteguard' ),
			'manage_options',
			'siteguard_login_history',
			array( $this, 'menu_login_history' )
		);
		add_action( 'admin_print_styles-' . $page, array( $this, 'menu_styles' ) );
	}

	function menu_dashboard() {
		include 'siteguard-menu-dashboard.php';
		$dashboard_menu = new SiteGuard_Menu_Dashboard();
	}
	function menu_login_history() {
		// include( 'siteguard-menu-login-history.php' );   -- already included SiteGuard::__construct --
		$login_history_menu = new SiteGuard_Menu_Login_History();
	}
	function menu_admin_filter() {
		include 'siteguard-menu-admin-filter.php';
		$admin_filter_menu = new SiteGuard_Menu_Admin_Filter();
	}
	function load_rename_login_early() {
		require_once SITEGUARD_PATH . 'admin/siteguard-menu-rename-login.php';
		SiteGuard_Menu_Rename_Login::pre_handle_post();
	}

	function menu_rename_login() {
		include_once 'siteguard-menu-rename-login.php';
		$rename_login_menu = new SiteGuard_Menu_Rename_Login();
	}
	function menu_captcha() {
		include 'siteguard-menu-captcha.php';
		$captcha_menu = new SiteGuard_Menu_CAPTCHA();
	}
	function menu_same_error() {
		include 'siteguard-menu-same-error.php';
		$same_error_menu = new SiteGuard_Menu_Same_Error();
	}
	function menu_login_lock() {
		include 'siteguard-menu-login-lock.php';
		$login_lock_menu = new SiteGuard_Menu_Login_Lock();
	}
	function menu_login_alert() {
		include 'siteguard-menu-login-alert.php';
		$login_alert_menu = new SiteGuard_Menu_Login_Alert();
	}
	function menu_fail_once() {
		include 'siteguard-menu-fail-once.php';
		$fail_once_menu = new SiteGuard_Menu_Fail_Once();
	}
	function menu_protect_xmlrpc() {
		include 'siteguard-menu-protect-xmlrpc.php';
		$protect_xmlrpc_menu = new SiteGuard_Menu_Protect_XMLRPC();
	}
	function menu_block_author_query() {
		include 'siteguard-menu-author-query.php';
		$block_author_query = new SiteGuard_Menu_Author_Query();
	}
	function menu_updates_notify() {
		include 'siteguard-menu-updates-notify.php';
		$waf_updates_notify_menu = new SiteGuard_Menu_Updates_Notify();
	}
	function menu_waf_tuning_support() {
		include 'siteguard-menu-waf-tuning-support.php';
		$waf_tuning_support_menu = new SiteGuard_Menu_WAF_Tuning_Support();
	}
}
