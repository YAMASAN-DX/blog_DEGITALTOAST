<?php

function siteguard_error_log( $message ) {
	// Use PHP's error_log() so logs go to the server-configured destination
	// (typically outside the web root) instead of a plugin-directory file
	// that would be exposed on Nginx (no .htaccess support).
	error_log( '[SiteGuard] ' . $message );
}

function siteguard_error_dump( $title, $obj ) {
	ob_start();
	var_dump( $obj );
	$msg = ob_get_contents();
	ob_end_clean();
	siteguard_error_log( "$title: $msg" );
}

function siteguard_rand( $min = null, $max = null ) {
	$ret = 0;
	if ( $min === null or $max === null ) {
		$ret = mt_rand();
	} else {
		$ret = mt_rand( $min, $max );
	}
	return $ret;
}

/**
 * Whether the current request is the plugin's own .htaccess self-test request.
 *
 * The self-test asks the server for a file inside a temporary directory under
 * ABSPATH. When that file cannot be served — the directory was already removed,
 * or the rewrite under test is ignored — the request falls through to
 * WordPress, which boots the plugin as usual. Such a request must not perform
 * any .htaccess bookkeeping of its own: the run that issued it is in the middle
 * of rebuilding the very state it would inspect, and re-entering upgrade() from
 * here starts another self-test, and another WordPress boot, and so on.
 *
 * @return bool
 */
function siteguard_is_self_test_request() {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}
	return ( false !== strpos( (string) $_SERVER['REQUEST_URI'], SiteGuard_Htaccess::TEST_DIR_PREFIX ) );
}

function siteguard_check_multisite() {
	if ( ! is_multisite() ) {
		return true;
	}
	$message = esc_html__( 'This plugin does not support WordPress multisite.', 'siteguard' );
	$error   = new WP_Error( 'siteguard', $message );
	return $error;
}

class SiteGuard_Base {
	function __construct() {
	}
	function is_switch_value( $value ) {
		if ( '0' === $value || '1' === $value ) {
			return true;
		}
		return false;
	}
	function cvt_camma2ret( $value ) {
		$result = str_replace( ' ', '', $value );
		return str_replace( ',', "\r\n", $result );
	}
	function cvt_ret2camma( $exclude ) {
		$result = str_replace( ' ', '', $exclude );
		$result = str_replace( ',', '', $result );
		$result = preg_replace( '/(\r\n){2,}/', "\r\n", $result );
		$result = preg_replace( '/\r\n$/', '', $result );
		$result = str_replace( "\r\n", ',', $result );
		$result = str_replace( "\r", ',', $result );
		return str_replace( "\n", ',', $result );
	}
	function check_module( $name, $default = false ) {
		return true;
		// It does not work WP-CLI
		// if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
		// return ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) !== false || strpos( $_SERVER['SERVER_SOFTWARE'], 'LiteSpeed' ) !== false);
		// } else {
		// return $default;
		// }

		// It does not work in FastCGI well.
		// $module = 'mod_' . $name;
		// return apache_mod_loaded( $module, $default );
		// if ( function_exists('phpinfo') ) {
		// ob_start( );
		// phpinfo(8);
		// $phpinfo = ob_get_clean( );
		// if ( false !== strpos( $phpinfo, $module ) ) {
		// return true;
		// }
		// }
		// return $default;
	}
	function is_private_ip( $ip ) {
		$private_ips = array(
			'10.0.0.0,10.255.255.255',
			'172.16.0.0,172.31.255.255',
			'192.168.0.0,192.168.255.255',
		);

		$long_ip = ip2long( $ip );
		if ( -1 !== $long_ip && false !== $long_ip ) {
			$long_ip = sprintf( '%u', $long_ip );
			foreach ( $private_ips as $private_ip ) {
				list( $start, $end ) = explode( ',', $private_ip );
				$long_start          = ip2long( $start );
				$long_start          = sprintf( '%u', $long_start );
				$long_end            = ip2long( $end );
				$long_end            = sprintf( '%u', $long_end );
				if ( $long_ip >= $long_start && $long_ip <= $long_end ) {
					return true;
				}
			}
		}
		return false;
	}
	function get_server_ip() {
		if ( isset( $_SERVER['SERVER_ADDR'] ) ) {
			$ip = sanitize_text_field( $_SERVER['SERVER_ADDR'] );
			if ( preg_match( '/^[0-9.:]+$/', $ip ) ) {
				return $ip;
			}
		}

		$host = parse_url( home_url(), PHP_URL_HOST );
		if ( false !== $host && null !== $host ) {
			putenv( 'RES_OPTIONS=retrans:1 retry:1 timeout:2 attempts:1' );
			$ip = @gethostbyname( $host );
			if ( $ip !== $host && '127.0.0.1' !== $ip && '::1' !== $ip ) {
				if ( preg_match( '/^[0-9.:]+$/', $ip ) ) {
					return $ip;
				}
			}
		}
		return false;
	}
	function get_ip() {
		if (
		! isset( $_SERVER['REMOTE_ADDR'] )
		|| ! is_string( $_SERVER['REMOTE_ADDR'] )
		|| '' === $_SERVER['REMOTE_ADDR']
		) {
			siteguard_error_log( 'Your webserver is misconfigured. REMOTE_ADDR is not set.' );
			return '0.0.0.0';
		}
		return sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
	}
}
