<?php

class SiteGuard_Disable_XMLRPC extends SiteGuard_Base {
	public static $htaccess_mark = '#==== SITEGUARD_DISABLE_XMLRPC_SETTINGS';

	function __construct() {
		global $siteguard_config;
		if ( '1' === $siteguard_config->get( 'disable_xmlrpc_enable' ) ) {
			$this->add_filters();
		}
	}

	static function get_mark() {
		return self::$htaccess_mark;
	}

	function init() {
		global $siteguard_config;
		$siteguard_config->set( 'disable_xmlrpc_enable', '0' );
		$siteguard_config->update();
	}

	private function add_filters() {
		add_filter( 'xmlrpc_enabled', '__return_false', 99 );
		add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ), 99 );
		add_action( 'plugins_loaded', array( $this, 'early_block_xmlrpc' ), 0 );
	}

	public function remove_pingback_header( $headers ) {
		if ( isset( $headers['X-Pingback'] ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	public function early_block_xmlrpc() {
		global $siteguard_config;
		if ( '1' !== $siteguard_config->get( 'disable_xmlrpc_enable' ) ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			status_header( 403 );
			nocache_headers();
			exit;
		}
	}

	function feature_on() {
		global $siteguard_config;
		$siteguard_config->set( 'disable_xmlrpc_enable', '1' );
		$siteguard_config->update();
		$this->add_filters();
		return true;
	}

	static function feature_off() {
			global $siteguard_config;
			$siteguard_config->set( 'disable_xmlrpc_enable', '0' );
			$siteguard_config->update();
			return true;
	}
}
