<?php

class SiteGuard_Htaccess extends SiteGuard_Base {
	const HTACCESS_PERMISSION = 0604;
	const HTACCESS_MARK_START = '#SITEGUARD_PLUGIN_SETTINGS_START';
	const HTACCESS_MARK_END   = '#SITEGUARD_PLUGIN_SETTINGS_END';

	// Temporary directory used by test_htaccess(), and how long one may live
	// before it is considered abandoned. The lifetime has to stay well above
	// the two wp_remote_get timeouts below, so that a directory belonging to a
	// self-test that is still running is never swept away by another run.
	const TEST_DIR_PREFIX  = 'siteguard-test-';
	const TEST_DIR_MAX_AGE = 300;

	function __construct() {
	}
	static function get_htaccess_file() {
		return ABSPATH . '.htaccess';
	}
	static function get_tmp_dir() {
		return SITEGUARD_PATH . 'tmp/';
	}
	static function is_writable_htaccess() {
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) ) {
			return false;
		}
		if ( file_exists( self::get_htaccess_file() ) ) {
			return is_writable( self::get_htaccess_file() );
		}
		return is_writable( ABSPATH );
	}

	// Diagnostic reason for the most recent test_htaccess() failure, as an array
	// like array( 'code' => 'http_status', 'url' => ..., 'status' => 403 ). An empty
	// array means success (or not yet run). SiteGuard_RenameLogin reads this to
	// record why it fell back to stub (.php) mode, so administrators can see the
	// cause on the settings screen.
	public static $last_reason = array();

	static function test_htaccess() {
		self::$last_reason = array();
		if ( ! self::is_writable_htaccess() ) {
			$is_nginx          = isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' );
			self::$last_reason = array( 'code' => $is_nginx ? 'nginx' : 'not_writable' );
			return false;
		}

		// Sweep orphaned test directories left behind by previous runs whose
		// wp_remote_get timed out before cleanup could execute.
		self::cleanup_orphaned_test_dirs();


		$test_dir_name    = self::TEST_DIR_PREFIX . uniqid();
		$test_dir_path    = ABSPATH . $test_dir_name;
		$htaccess_path    = $test_dir_path . '/.htaccess';
		$php_file_path    = $test_dir_path . '/test.php';
		// The test directory is created under ABSPATH, which is served at the
		// WordPress Address (siteurl), NOT necessarily the Site Address (home).
		// On "WordPress in its own directory" installs (e.g. core in /wp, site at
		// root) these differ, so home_url() would build a URL that does not map to
		// the test directory and the self-test would always 404. Use the raw
		// siteurl (get_option avoids the rename-login site_url filter) so the URL
		// matches ABSPATH. For ordinary installs siteurl == home, so no change.
		$base_url         = rtrim( get_option( 'siteurl' ), '/' );
		$test_url         = $base_url . '/' . $test_dir_name . '/test.html';
		$php_content      = '<?php echo "SUCCESS";';
		$htaccess_content = "RewriteEngine On\nRewriteRule ^test\\.html$ test.php [L]";

		$cleanup = function () use ( $htaccess_path, $php_file_path, $test_dir_path ) {
			if ( file_exists( $htaccess_path ) ) {
				@unlink( $htaccess_path );
			}
			if ( file_exists( $php_file_path ) ) {
				@unlink( $php_file_path );
			}
			if ( is_dir( $test_dir_path ) ) {
				@rmdir( $test_dir_path );
			}
		};

		if ( ! @mkdir( $test_dir_path, 0755 ) ) {
			self::$last_reason = array( 'code' => 'mkdir' );
			return false;
		}

		if ( false === @file_put_contents( $php_file_path, $php_content ) || false === @file_put_contents( $htaccess_path, $htaccess_content ) ) {
			$cleanup();
			self::$last_reason = array( 'code' => 'write' );
			return false;
		}

		$args     = array(
			'timeout'   => 10,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		);
		$response = wp_remote_get( $test_url, $args );

		// On success the .htaccess rewrite turned test.html into test.php (SUCCESS).
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && 'SUCCESS' === wp_remote_retrieve_body( $response ) ) {
			$cleanup();
			return true;
		}

		// The rewrite test failed. Probe test.php directly (before cleanup) to tell
		// apart "the .htaccess was ignored" from "the test files were unreachable":
		// if test.php itself returns SUCCESS, the directory and PHP are reachable
		// and only the RewriteRule had no effect (AllowOverride None / mod_rewrite
		// off). If test.php is also unreachable, the URL did not map to the test
		// directory at all (subdirectory install, routing, or access restriction).
		$php_url   = $base_url . '/' . $test_dir_name . '/test.php';
		$php_probe = wp_remote_get( $php_url, $args );
		$probe_ok  = ! is_wp_error( $php_probe ) && 200 === wp_remote_retrieve_response_code( $php_probe ) && 'SUCCESS' === wp_remote_retrieve_body( $php_probe );

		$cleanup();

		if ( is_wp_error( $response ) ) {
			self::$last_reason = array(
				'code'   => 'wp_error',
				'url'    => $test_url,
				'detail' => $response->get_error_message(),
			);
			return false;
		}
		if ( $probe_ok ) {
			self::$last_reason = array(
				'code' => 'htaccess_ignored',
				'url'  => $test_url,
			);
			return false;
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			self::$last_reason = array(
				'code' => 'bad_body',
				'url'  => $test_url,
			);
			return false;
		}
		self::$last_reason = array(
			'code'   => 'http_status',
			'url'    => $test_url,
			'status' => $status,
		);
		return false;
	}
	private static function cleanup_orphaned_test_dirs() {
		$orphans = glob( ABSPATH . self::TEST_DIR_PREFIX . '*', GLOB_ONLYDIR );
		if ( empty( $orphans ) ) {
			return;
		}
		// Only sweep directories old enough that no self-test can still be
		// waiting on them. Several self-tests can be in flight at once (the
		// upgrade path calls feature_on() on every request until the version is
		// recorded), and deleting a directory that another run has just created
		// makes that run's request 404 — which it would read as "the .htaccess
		// rewrite does not work here" and fall back to stub (.php) mode even
		// though .htaccess is perfectly usable.
		$threshold = time() - self::TEST_DIR_MAX_AGE;
		foreach ( $orphans as $dir ) {
			$mtime = @filemtime( $dir );
			if ( false !== $mtime && $mtime > $threshold ) {
				continue;
			}
			$entries = @scandir( $dir );
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					$path = $dir . DIRECTORY_SEPARATOR . $entry;
					if ( is_file( $path ) ) {
						@unlink( $path );
					}
				}
			}
			@rmdir( $dir );
		}
	}
	static function get_htaccess_new_file() {
		return tempnam( self::get_tmp_dir(), 'htaccess_' );
	}
	static function make_tmp_dir() {
		$dir = self::get_tmp_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			siteguard_error_log( "make tempdir failed: $dir" );
			return false;
		}
		// Defense-in-depth against directory listing if .htaccess is ignored
		// (e.g. Apache configured with AllowOverride None). Do not chmod the
		// file — making it read-only blocks WordPress plugin overwrite/upgrade.
		$index_file = $dir . 'index.html';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, '' );
		}
		$htaccess_file = $dir . '.htaccess';

		if ( file_exists( $htaccess_file ) ) {
			$lines = file( $htaccess_file );
			$res   = preg_grep( '/IfModule authz_core_module/', $lines );
			if ( ! empty( $res ) ) {
				return true;
			}
		}

		if ( $handle = @fopen( $htaccess_file, 'w' ) ) {
			fwrite( $handle, '<IfModule authz_core_module>' . "\n" );
			fwrite( $handle, '    Require all denied' . "\n" );
			fwrite( $handle, '</IfModule>' . "\n" );
			fwrite( $handle, '<IfModule !authz_core_module>' . "\n" );
			fwrite( $handle, '    Order deny,allow' . "\n" );
			fwrite( $handle, '    Deny from all' . "\n" );
			fwrite( $handle, '</IfModule>' . "\n" );
			fclose( $handle );
		}

		return true;
	}
	static function is_exists_setting( $mark ) {
		$result = false;
		if ( '' === $mark ) {
			$mark_start = self::HTACCESS_MARK_START;
			$mark_end   = self::HTACCESS_MARK_END;
		} else {
			$mark_start = $mark . '_START';
			$mark_end   = $mark . '_END';
		}
		$current_file = self::get_htaccess_file();
		if ( ! file_exists( $current_file ) ) {
			return $result;
		}
		$fr = @fopen( $current_file, 'r' );
		if ( null === $fr ) {
			return $result;
		}
		$line_num   = 0;
		$start_line = 0;
		$end_line   = 0;
		while ( ! feof( $fr ) ) {
			$line = fgets( $fr, 4096 );
			++$line_num;
			if ( false !== strpos( $line, $mark_start ) ) {
				$start_line = $line_num;
			}
			if ( false !== strpos( $line, $mark_end ) ) {
				$end_line = $line_num;
				if ( $start_line > 0 && ( $end_line - $start_line ) > 1 ) {
					$result = true;
				}
				break;
			}
		}
		@fclose( $fr );

		return $result;
	}
	static function check_permission( $flag_create = true ) {
		$file = self::get_htaccess_file();
		if ( true === $flag_create ) {
			self::get_apply_permission( $file );
		}
		if ( ! is_readable( $file ) ) {
			siteguard_error_log( "file not readable: $file" );
			return false;
		}
		if ( ! is_writable( $file ) ) {
			siteguard_error_log( "file not writable: $file" );
			return false;
		}
		$path = pathinfo( $file, PATHINFO_DIRNAME );
		if ( ! is_writable( $path ) ) {
			siteguard_error_log( 'directory not writable: ' . $path );
			return false;
		}
		return true;
	}
	static function get_apply_permission_itr( $file ) {
		clearstatcache();
		$perm = intval( substr( sprintf( '%o', fileperms( $file ) ), -4 ), 8 );
		return $perm;
	}
	static function get_apply_permission( $file ) {
		$perm = self::HTACCESS_PERMISSION;
		if ( file_exists( $file ) ) {
			$perm = self::get_apply_permission_itr( $file );
		} else {
			@touch( $file );
		}
		@chmod( $file, $perm );
		return $perm;
	}
	static function clear_settings( $mark ) {
		// On Nginx (or any environment where .htaccess is not in use), the
		// rebuild is a no-op. Skipping here avoids creating the in-plugin
		// tmp/ directory and any tempnam fragments that would not be
		// protected from web access.
		if ( ! self::is_writable_htaccess() ) {
			return true;
		}
		if ( ! self::make_tmp_dir() ) {
			return false;
		}
		if ( '' === $mark ) {
			$mark_start = self::HTACCESS_MARK_START;
			$mark_end   = self::HTACCESS_MARK_END;
		} else {
			$mark_start = $mark . '_START';
			$mark_end   = $mark . '_END';
		}
		$flag_settings = false;
		$current_file  = self::get_htaccess_file();
		if ( ! file_exists( $current_file ) ) {
			return false;
		}
		$perm = self::get_apply_permission( $current_file );

		if ( ! self::check_permission( false ) ) {
			return false;
		}
		$fr = @fopen( $current_file, 'r' );
		if ( null === $fr ) {
			siteguard_error_log( "fopen failed: $current_file" );
			return false;
		}
		$new_file = self::get_htaccess_new_file();
		$fw       = @fopen( $new_file, 'w' );
		if ( null === $fw ) {
			siteguard_error_log( "fopen failed: $new_file" );
			@unlink( $new_file );
			fclose( $fr );
			return false;
		}
		while ( ! feof( $fr ) ) {
			$line = fgets( $fr, 4096 );
			if ( false !== strpos( $line, $mark_start ) ) {
				$flag_settings = true;
			}
			if ( false === $flag_settings ) {
				fputs( $fw, $line, 4096 );
			}
			if ( true == $flag_settings && false !== strpos( $line, $mark_end ) ) {
				$flag_settings = false;
			}
		}
		fclose( $fr );
		fclose( $fw );
		@chmod( $new_file, $perm );
		if ( ! rename( $new_file, $current_file ) ) {
			siteguard_error_log( "rename failed: $new_file $current_file" );
			@unlink( $new_file );
			return false;
		}
		return true;
	}
	function update_settings( $mark, $data ) {
		// See note in clear_settings(): skip on Nginx where .htaccess is unused.
		if ( ! self::is_writable_htaccess() ) {
			return true;
		}
		if ( ! self::make_tmp_dir() ) {
			return false;
		}
		$flag_write    = false;
		$flag_through  = true;
		$flag_wp       = false;
		$flag_wp_set   = false;
		$wp_settings   = '';
		$mark_start    = $mark . '_START';
		$mark_end      = $mark . '_END';
		$mark_wp_start = '# BEGIN WordPress';
		$mark_wp_end   = '# END WordPress';
		$current_file  = self::get_htaccess_file();
		$perm          = self::get_apply_permission( $current_file );
		if ( ! self::check_permission( false ) ) {
			return false;
		}
		$fr = @fopen( $current_file, 'r' );
		if ( null === $fr ) {
			siteguard_error_log( "fopen failed: $current_file" );
			return false;
		}
		$new_file = self::get_htaccess_new_file();
		if ( ! is_writable( $new_file ) ) {
			siteguard_error_log( "file not writable: $new_file" );
			@unlink( $new_file );
			fclose( $fr );
			return false;
		}
		$fw = @fopen( $new_file, 'w' );
		if ( null === $fw ) {
			siteguard_error_log( "fopen failed: $new_file" );
			@unlink( $new_file );
			fclose( $fr );
			return false;
		}
		while ( ! feof( $fr ) ) {
			$line = fgets( $fr, 4096 );

			// Save WordPress settings.
			// WordPress settings has to be written after SiteGuard settings.
			if ( false === $flag_write && false == $flag_wp_set && false !== strpos( $line, $mark_wp_start ) ) {
				$flag_wp     = true;
				$flag_wp_set = true;
			}
			if ( $flag_wp_set ) {
				$wp_settings .= $line;
				if ( false !== strpos( $line, $mark_wp_end ) ) {
					$flag_wp_set = false;
				}
				continue;
			}

			if ( false === $flag_write && false !== strpos( $line, $mark_start ) ) {
				fwrite( $fw, $line, strlen( $line ) );
				fwrite( $fw, $data, strlen( $data ) );
				$flag_write   = true;
				$flag_through = false;
				// continue;
			}
			if ( false === $flag_write && false !== strpos( $line, self::HTACCESS_MARK_END ) ) {
				fwrite( $fw, $mark_start . "\n", strlen( $mark_start ) + 1 );
				fwrite( $fw, $data, strlen( $data ) );
				fwrite( $fw, $mark_end . "\n", strlen( $mark_end ) + 1 );
				$flag_write = true;
			}
			if ( false === $flag_through && false !== strpos( $line, $mark_end ) ) {
				$flag_through = true;
			}
			if ( $flag_through ) {
				fwrite( $fw, $line, strlen( $line ) );
				if ( false === $flag_wp && false !== strpos( $line, $mark_wp_start ) ) {
					$flag_wp = true;
				}
			}
		}
		if ( false === $flag_write ) {
			fwrite( $fw, "\n" . self::HTACCESS_MARK_START . "\n", strlen( self::HTACCESS_MARK_START ) + 2 );
			fwrite( $fw, $mark_start . "\n", strlen( $mark_start ) + 1 );
			fwrite( $fw, $data, strlen( $data ) );
			fwrite( $fw, $mark_end . "\n", strlen( $mark_end ) + 1 );
			fwrite( $fw, self::HTACCESS_MARK_END . "\n", strlen( self::HTACCESS_MARK_END ) + 1 );
		}
		if ( '' != $wp_settings ) {       // Write saved WordPress Settings
			fwrite( $fw, "\n", 1 );
			fwrite( $fw, $wp_settings, strlen( $wp_settings ) );
			fwrite( $fw, "\n", 1 );
		} elseif ( false === $flag_wp ) { // Write empty WordPress Settings
			fwrite( $fw, "\n", 1 );
			fwrite( $fw, $mark_wp_start . "\n", strlen( $mark_wp_start ) + 1 );
			fwrite( $fw, $mark_wp_end . "\n", strlen( $mark_wp_end ) + 1 );
			fwrite( $fw, "\n", 1 );
		}
		fclose( $fr );
		fclose( $fw );
		@chmod( $new_file, $perm );
		if ( ! rename( $new_file, $current_file ) ) {
			siteguard_error_log( "rename failed: $new_file $current_file" );
			@unlink( $new_file );
			return false;
		}
		return true;
	}
}
