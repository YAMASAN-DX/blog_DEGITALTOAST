<?php

class SiteGuard_AdminFilter extends SiteGuard_Base {
	public static $htaccess_mark = '#==== SITEGUARD_ADMIN_FILTER_SETTINGS';
	function __construct() {
		define( 'SITEGUARD_TABLE_LOGIN', 'siteguard_login' );
		add_action( 'wp_login', array( $this, 'handler_wp_login' ), 1, 2 );
		add_action( 'init', array( $this, 'enforce_admin_filter' ), 0 );
	}

	static function get_mark() {
		return self::$htaccess_mark;
	}

	function init() {
		global $wpdb, $siteguard_config;
		$table_name = $wpdb->prefix . SITEGUARD_TABLE_LOGIN;
		$sql        = 'CREATE TABLE ' . $table_name . " (
                        ip_address varchar(40) NOT NULL DEFAULT '',
                        status INT NOT NULL DEFAULT 0,
                        count INT NOT NULL DEFAULT 0,
                        last_login_time datetime,
                        UNIQUE KEY ip_address (ip_address)
                )
                CHARACTER SET 'utf8';";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$siteguard_config->set( 'admin_filter_exclude_path', 'admin-ajax.php,load-styles.php,load-scripts.php,site-health.php' );
		$siteguard_config->set( 'admin_filter_enable', '0' );
		$siteguard_config->update();
	}

	function handler_wp_login( $login, $current_user ) {
		global $siteguard_config;
		if ( '' == $current_user->user_login ) {
			return;
		}
		if ( 1 == $siteguard_config->get( 'admin_filter_enable' ) ) {
			$this->feature_on( $this->get_ip() );
		}
	}

	function cvt_exclude( $exclude ) {
		return str_replace( ',', '|', $exclude );
	}

	function cvt_status_for_1_2_5( $ip_address ) {
		global $wpdb;
		$table_name = $wpdb->prefix . SITEGUARD_TABLE_LOGIN;
		$wpdb->update( $table_name, array( 'status' => 0 ), array( 'ip_address' => $ip_address ) );
	}

	private function cleanup_and_upsert_success_ip( $ip_address ) {
		global $wpdb;
		$table_name = $wpdb->prefix . SITEGUARD_TABLE_LOGIN;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE status = %d AND last_login_time < (SYSDATE() - INTERVAL 1 DAY)",
				SITEGUARD_LOGIN_SUCCESS
			)
		);

		// upsert
		$now_str = current_time( 'mysql' );
		$data    = array(
			'ip_address'      => $ip_address,
			'status'          => SITEGUARD_LOGIN_SUCCESS,
			'count'           => 0,
			'last_login_time' => $now_str,
		);
		$exists  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM $table_name WHERE ip_address = %s LIMIT 1",
				$ip_address
			)
		);
		if ( $exists ) {
			$wpdb->update( $table_name, $data, array( 'ip_address' => $ip_address ) );
		} else {
			$wpdb->insert( $table_name, $data );
		}
	}

	function feature_on( $ip_address ) {
		$this->cleanup_and_upsert_success_ip( $ip_address );
		return true;
	}

	static function feature_off() {
		return true;
	}

	function enforce_admin_filter() {
		global $wpdb, $siteguard_config;

		if ( '1' != $siteguard_config->get( 'admin_filter_enable' ) ) {
			return;
		}

		$req_path = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( ! is_string( $req_path ) ) {
			return;
		}
		$req_path = untrailingslashit( $req_path );
		$site_path = untrailingslashit( parse_url( site_url(), PHP_URL_PATH ) ?: '' );
		$admin_prefix = $site_path . '/wp-admin';
		if ( 0 !== strpos( $req_path, $admin_prefix ) ) {
			return;
		}

		$exclude = (string) $siteguard_config->get( 'admin_filter_exclude_path' );
		$parts   = array_filter( array_map( 'trim', explode( ',', $exclude ) ) );
		if ( ! empty( $parts ) ) {
			$escaped = array_map(
				function ( $p ) {
					return preg_quote( $p, '#' );
				},
				$parts
			);
			$regex = '#^' . preg_quote( $admin_prefix, '#' ) . '/(?:' . implode( '|', $escaped ) . ')(?:$|/|\?)#i';
			if ( preg_match( $regex, $req_path ) ) {
				return;
			}
		}

		if ( is_user_logged_in() ) {
			return;
		}

		$ip = $this->get_ip();

		$server_ip = $this->get_server_ip();
		if ( $ip === '127.0.0.1' || $ip === '::1' || ( $server_ip !== false && $ip === $server_ip ) ) {
			return;
		}

		$table = $wpdb->prefix . SITEGUARD_TABLE_LOGIN;
		$ok    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM $table WHERE ip_address = %s AND status = %d AND last_login_time >= (SYSDATE() - INTERVAL 1 DAY) LIMIT 1",
				$ip,
				SITEGUARD_LOGIN_SUCCESS
			)
		);
		if ( $ok ) {
			return;
		}

		status_header( 404 );
		nocache_headers();
		exit;
	}
}
