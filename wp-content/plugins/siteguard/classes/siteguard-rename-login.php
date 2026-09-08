<?php

require_once ABSPATH . '/wp-admin/includes/plugin.php';
require_once SITEGUARD_PATH . 'really-simple-captcha/siteguard-really-simple-captcha.php';

class SiteGuard_RenameLogin extends SiteGuard_Base {
	private $denied_login = false;

	/**
	 * True while the login URL conflict probe is running. The URL filters below
	 * pass their input through untouched during that window so the probe can
	 * observe what the *other* plugins on the same filter do. See
	 * probe_foreign_login_url().
	 *
	 * Static because feature_off() constructs a second instance of this class,
	 * whose filters are registered as well; a per-instance flag would leave that
	 * second instance rewriting the probe URL and the probe would report the
	 * plugin's own rewrite as a foreign one.
	 */
	private static $probing = false;

	/** Result of the conflict probe for this request. See get_login_url_conflict(). */
	private static $conflict         = null;
	private static $conflict_checked = false;

	protected static $incompatible_plugins = array(
		'WordPress HTTPS (SSL)' => 'wordpress-https/wordpress-https.php',
		'qTranslate X'          => 'qtranslate-x/qtranslate.php',
	);
	public static $htaccess_mark           = '#==== SITEGUARD_RENAME_LOGIN_SETTINGS';

	const STUB_WRITE_FAIL_TRANSIENT = 'siteguard_rl_stub_fail';

	// Set while feature_on() rebuilds the .htaccess block. clear_settings()
	// removes the block before update_settings() writes it back, and a
	// concurrent request landing in that window would see the block missing and
	// turn the feature off. See SiteGuard::htaccess_check().
	const HTACCESS_REBUILD_TRANSIENT = 'siteguard_rl_htaccess_rebuild';

	function __construct() {
		global $siteguard_config;

		add_filter( 'logout_url', array( $this, 'filter_logout_url' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'rewrite_adminbar_logout' ), 999 );
		add_action( 'admin_notices', array( $this, 'maybe_notice_stub_failed' ) );

		// Late priority: plugins that rewrite the login URL register their filters
		// at various points (plugins_loaded, init, wp_loaded), and admin_init runs
		// after all of them.
		add_action( 'admin_init', array( $this, 'check_login_url_conflict' ), 9999 );
		add_action( 'admin_notices', array( $this, 'maybe_notice_login_url_conflict' ) );

		if ( '1' === $siteguard_config->get( 'renamelogin_enable' ) ) {
			if ( null !== $this->get_active_incompatible_plugins() ) {
				$siteguard_config->set( 'renamelogin_enable', '0' );
				$siteguard_config->update();
				$this->feature_off();
			} else {
				$this->add_filter();
			}
		}

		add_action( 'template_redirect', array( $this, 'guard_disabled_entry' ), 0 );
		add_action( 'template_redirect', array( $this, 'handle_siteguard_rescue' ), 0 );
	}

	static function get_mark() {
		return self::$htaccess_mark;
	}

	function init() {
		global $siteguard_config;

		$this->denied_login = false;

		if ( '' === $siteguard_config->get( 'renamelogin_path' ) ) {
			$siteguard_config->set( 'renamelogin_path', 'login_' . sprintf( '%05d', siteguard_rand( 1, 99999 ) ) );
		}
		if ( '' === $siteguard_config->get( 'redirect_enable' ) ) {
			$siteguard_config->set( 'redirect_enable', '0' );
		}
		if ( '' === $siteguard_config->get( 'rescue_enable' ) ) {
			$siteguard_config->set( 'rescue_enable', '1' );
		}
		if ( '' === $siteguard_config->get( 'renamelogin_stub' ) ) {
			$siteguard_config->set( 'renamelogin_stub', SITEGUARD_RENAME_MODE_HTACCESS ); // Apache=0 / Nginx=1
		}
		$siteguard_config->update();

		if ( true === siteguard_check_multisite()
			&& null === $this->get_active_incompatible_plugins()
		) {
			$siteguard_config->set( 'renamelogin_enable', '1' );
			$siteguard_config->update();
			if ( ! $this->feature_on() ) {
				$siteguard_config->set( 'renamelogin_enable', '0' );
				$siteguard_config->update();
			}
		} else {
			$siteguard_config->set( 'renamelogin_enable', '0' );
			$siteguard_config->update();
		}
	}

	function get_active_incompatible_plugins() {
		$result = array();
		foreach ( self::$incompatible_plugins as $name => $path ) {
			if ( is_plugin_active( $path ) ) {
				$result[] = $name;
			}
		}
		return empty( $result ) ? null : $result;
	}

	function add_filter() {
		add_filter( 'plugins_loaded', array( $this, 'handler_plugins_loaded' ), 9999 );

		add_action( 'init', array( $this, 'guard_wp_login_direct_access' ), 0 );
		add_filter( 'login_init', array( $this, 'handler_login_init' ), 10, 2 );
		add_filter( 'site_url', array( $this, 'handler_site_url' ), 10, 2 );
		add_filter( 'network_site_url', array( $this, 'handler_site_url' ), 10, 2 );
		add_filter( 'wp_redirect', array( $this, 'handler_wp_redirect' ), 10, 2 );
		add_filter( 'register', array( $this, 'handler_register' ) );
		add_filter( 'auth_redirect_scheme', array( $this, 'handler_stop_redirect' ), 9999 );

		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	public function can_use_htaccess() {
		if ( ! isset( $_SERVER['SERVER_SOFTWARE'] )
			|| ( false === strpos( strtolower( $_SERVER['SERVER_SOFTWARE'] ), 'apache' ) && false === strpos( strtolower( $_SERVER['SERVER_SOFTWARE'] ), 'litespeed' ) )
		) {
			return false;
		}
		return SiteGuard_Htaccess::is_writable_htaccess();
	}

	private function is_stub_mode() {
		global $siteguard_config;
		return SITEGUARD_RENAME_MODE_STUB === $siteguard_config->get( 'renamelogin_stub' );
	}

	private function slug() {
		global $siteguard_config;
		$slug = trim( (string) $siteguard_config->get( 'renamelogin_path' ), '/' );
		return $slug === '' ? 'login' : $slug;
	}

	private function old_slug() {
		global $siteguard_config;
		$slug = trim( (string) $siteguard_config->get( 'oldlogin_path' ), '/' );
		return $slug === '' ? 'login' : $slug;
	}

	private function stub_filename() {
		return $this->slug() . '.php';
	}

	private function old_stub_filename() {
		return $this->old_slug() . '.php';
	}

	public function stub_abspath() {
		return trailingslashit( ABSPATH ) . $this->stub_filename();
	}

	private function old_stub_abspath() {
		return trailingslashit( ABSPATH ) . $this->old_stub_filename();
	}

	private function stub_url() {
		return rtrim( site_url(), '/' ) . '/' . $this->stub_filename();
	}

	private function install_stub() {
		$file = $this->stub_abspath();
		$code = "<?php\n/* Generated by SiteGuard WP Plugin */\nrequire_once __DIR__ . '/wp-login.php';\n";
		$ok   = @file_put_contents( $file, $code );
		if ( false === $ok ) {
			set_transient( self::STUB_WRITE_FAIL_TRANSIENT, 1, MINUTE_IN_SECONDS * 10 );
			return false;
		}
		@chmod( $file, 0644 );
		return true;
	}

	/**
	 * Make sure the stub file the current settings promise is in place, without
	 * rewriting it when it already is. Used to recover an install whose stub
	 * went missing while stub (.php) mode stayed recorded.
	 *
	 * @return bool
	 */
	public function ensure_stub() {
		if ( file_exists( $this->stub_abspath() ) ) {
			return true;
		}
		// This runs on every request, so a site whose root is not writable must
		// not retry (and record the failure) each time. install_stub() keeps the
		// transient for ten minutes, which paces the retries.
		if ( get_transient( self::STUB_WRITE_FAIL_TRANSIENT ) ) {
			return false;
		}
		return $this->install_stub();
	}

	private function remove_stub( $file ) {
		if ( file_exists( $file ) ) {
			// To prevent accidental deletion of important files, check if the file was generated by this plugin.
			$content = file_get_contents( $file, false, null, 0, 100 );
			if ( false !== $content && false !== strpos( $content, '/* Generated by SiteGuard WP Plugin */' ) ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Check if the current request is for the login page (renamed or original).
	 *
	 * @return bool
	 */
	public function is_login_request() {
		$req_path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		$script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( $_SERVER['SCRIPT_NAME'] ) : '';
		return ( $script_name === 'wp-login.php' || $req_path === '/' . $this->stub_filename() || $req_path === '/' . $this->slug() );
	}

	/**
	 * Whether REQUEST_URI targets a default login entry point (wp-login /
	 * wp-register) rather than the configured renamed slug.
	 *
	 * Every path segment is reduced to its "stem" (lowercased, any extension
	 * dropped) and compared against wp-login / wp-register. Checking every
	 * segment — not just the basename — is required because Apache MultiViews /
	 * content negotiation serves wp-login.php for an extensionless request, and
	 * with AcceptPathInfo anything after it becomes PATH_INFO:
	 *   /wp-login            -> wp-login.php
	 *   /wp-login/           -> wp-login.php (PATH_INFO "/")
	 *   /wp-login/anything   -> wp-login.php (PATH_INFO "/anything")  <-- basename is "anything"
	 *   /wp-login.php/x      -> wp-login.php (PATH_INFO "/x")
	 * A basename-only check misses the PATH_INFO variants (the basename is the
	 * trailing segment). Scanning every segment also covers the nested forms that
	 * WordPress core canonicalizes to wp-login.php (e.g. /abc/wp-login.php,
	 * //wp-login.php) and subdirectory installs (/cms/wp-login/x).
	 *
	 * The configured slug is exempt so a site that renamed its login to a reserved
	 * word (e.g. "wp-register", which the settings screen still permits because
	 * wp-register.php does not exist) is not locked out of its own login. Renamed
	 * slugs only contain [a-zA-Z0-9_-] (no dot), so the extension stripping never
	 * collides with a slug.
	 */
	private function targets_default_login() {
		$slug = strtolower( $this->slug() );
		$link = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( $_SERVER['REQUEST_URI'] ) : '';
		// Collapse leading/duplicate slashes before parse_url, otherwise //wp-login.php
		// is parsed as host=wp-login.php with a NULL path and would slip through.
		$link = preg_replace( '#^/+#', '/', $link );
		$path = (string) parse_url( $link, PHP_URL_PATH );
		$path = preg_replace( '#/+#', '/', $path );
		$path = urldecode( $path );
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg ) {
				continue;
			}
			$seg = strtolower( $seg );
			// Strip trailing whitespace, control characters and non-ASCII bytes so
			// variants like "wp-login.php " or "wp-login.php%C2%A0" (NBSP) are caught
			// after WordPress core URL normalization would treat them as wp-login.php.
			$seg  = preg_replace( '/[\s\x00-\x1f\x7f-\xff]+$/', '', $seg );
			$stem = preg_replace( '/\..*$/', '', $seg );
			if ( ( 'wp-login' === $stem || 'wp-register' === $stem ) && $stem !== $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Block direct access to the default login entry points at the init hook.
	 *
	 * Runs before login_init so that requests routed through index.php fallback
	 * (e.g. //wp-login.php on subdirectory installs) are stopped before WordPress
	 * core URL canonicalization can leak the renamed slug via wp_redirect.
	 */
	public function guard_wp_login_direct_access() {
		if ( ! $this->targets_default_login() ) {
			return;
		}
		// When wp-login.php is the executing script (a direct hit, or MultiViews
		// content negotiation serving it for /wp-login, /wp-login/x, etc.),
		// login_init fires and handler_login_init() renders the WordPress theme
		// 404 via set_404(). Defer to it so the visitor gets the themed 404 page
		// instead of a bare browser 404.
		//
		// Only the index.php fallback path is handled here with a bare 404 + exit:
		// e.g. //wp-login.php on a subdirectory install, where wp-login.php does
		// NOT execute (SCRIPT_NAME is index.php) and login_init never fires. That
		// case must stop now, before WordPress core canonicalizes the URL and
		// leaks the renamed slug via wp_redirect, and the main query is not yet
		// set up to render a theme template safely.
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? strtolower( basename( $_SERVER['SCRIPT_NAME'] ) ) : '';
		if ( 'wp-login.php' === $script ) {
			return;
		}
		status_header( 404 );
		nocache_headers();
		exit;
	}

	function handler_login_init() {
		// See targets_default_login() for rationale, including the renamed slug
		// exemption that keeps a "wp-login"/"wp-register" slug from 404ing the
		// site's own login page.
		if ( $this->targets_default_login() ) {
			$this->set_404();
		}
	}

	function convert_url( $link ) {
		if ( self::$probing ) {
			return $link;
		}
		$result = $link;
		$repl   = $this->is_stub_mode() ? $this->stub_filename() : $this->slug();

		if ( false !== strpos( $link, 'wp-login.php?action=register' ) && $this->denied_login ) {
			$this->set_404();
		} elseif ( false !== strpos( $link, 'wp-login.php' ) ) {
				$result = str_replace( 'wp-login.php', $repl, $link );
		}
		return $result;
	}

	function handler_site_url( $link ) {
		return $this->convert_url( $link ); }
	function handler_register( $link ) {
		return $this->convert_url( $link ); }
	function handler_wp_redirect( $link, $status_code ) {
		if ( ( ( strlen( $link ) <= 5 || 'http:' !== strtolower( substr( $link, 0, 5 ) ) ) && ( strlen( $link ) <= 6 || 'https:' !== strtolower( substr( $link, 0, 6 ) ) ) )
		|| ( isset( $_SERVER['HTTPS'] ) && strtolower( $_SERVER['HTTPS'] ) !== 'off' && 'https' === strtolower( substr( $link, 0, strpos( $link, '://' ) ) ) )
		|| ( ( ! isset( $_SERVER['HTTPS'] ) || strtolower( $_SERVER['HTTPS'] ) === 'off' ) && 'http' === strtolower( substr( $link, 0, strpos( $link, '://' ) ) ) ) ) {
			return $this->convert_url( $link );
		}
		return $link;
	}

	private function htaccess_body() {
		$slug = $this->slug();

		$parse_url = parse_url( site_url() );
		$base      = '/';
		if ( false !== $parse_url && isset( $parse_url['path'] ) && $parse_url['path'] !== '' ) {
			$base = rtrim( $parse_url['path'], '/' ) . '/';
		}

		$ht  = "<IfModule mod_rewrite.c>\n";
		$ht .= "    RewriteEngine on\n";
		$ht .= "    RewriteBase {$base}\n";
		$ht .= "    RewriteRule ^wp-signup\\.php 404-siteguard [L]\n";
		$ht .= "    RewriteRule ^wp-activate\\.php 404-siteguard [L]\n";
		$ht .= "    RewriteRule ^{$slug}(.*)$ wp-login.php\$1 [L]\n";
		$ht .= "</IfModule>\n";

		return $ht;
	}

	function feature_on() {
		// Announce the rebuild for its whole duration, so that requests arriving
		// while the .htaccess block is momentarily absent do not act on it.
		set_transient( self::HTACCESS_REBUILD_TRANSIENT, 1, MINUTE_IN_SECONDS );
		$result = $this->rebuild_feature();
		delete_transient( self::HTACCESS_REBUILD_TRANSIENT );
		return $result;
	}

	private function rebuild_feature() {
		global $siteguard_htaccess, $siteguard_config;

		// Remove .htaccess feature
		SiteGuard_Htaccess::clear_settings( self::$htaccess_mark );

		// Remove old stubs regardless of mode
		$this->remove_stub( $this->old_stub_abspath() );
		if ( $this->slug() !== $this->old_slug() ) {
			$this->remove_stub( $this->stub_abspath() );
		}

		// Decide between .htaccess mode and stub (.php) mode, recording the reason
		// when .htaccess cannot be used so the settings screen can explain why.
		$reason = $this->htaccess_unavailable_reason();
		if ( array() === $reason ) {
			$data = $this->htaccess_body();
			$mark = self::get_mark();
			$ok   = $siteguard_htaccess->update_settings( $mark, $data );
			if ( $ok ) {
				$siteguard_config->set( 'renamelogin_stub', SITEGUARD_RENAME_MODE_HTACCESS );
				$siteguard_config->set( 'renamelogin_stub_reason', array() );
				$siteguard_config->update();
				return true;
			}
			// Writing the .htaccess block failed unexpectedly; fall back to stub.
			$reason = array( 'code' => 'not_writable' );
		}

		if ( $this->install_stub() ) {
			$siteguard_config->set( 'renamelogin_stub', SITEGUARD_RENAME_MODE_STUB );
			$siteguard_config->set( 'renamelogin_stub_reason', $reason );
			$siteguard_config->update();
			siteguard_error_log( 'Rename Login fell back to stub (.php) mode. Reason: ' . wp_json_encode( $reason ) );
			return true;
		}

		return false;
	}

	/**
	 * Why .htaccess mode cannot be used right now. Returns an empty array when it
	 * can be used; otherwise an array like array( 'code' => ..., 'url' => ... )
	 * describing the cause (server software, write permission, or which stage of
	 * the .htaccess self-test failed). Used to explain stub (.php) fallback.
	 */
	private function htaccess_unavailable_reason() {
		if ( ! $this->can_use_htaccess() ) {
			$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';
			if ( '' !== $software && false !== stripos( $software, 'nginx' ) ) {
				return array( 'code' => 'nginx' );
			}
			if ( false === stripos( $software, 'apache' ) && false === stripos( $software, 'litespeed' ) ) {
				return array( 'code' => 'server_software', 'detail' => $software );
			}
			return array( 'code' => 'not_writable' );
		}
		if ( ! SiteGuard_Htaccess::test_htaccess() ) {
			return SiteGuard_Htaccess::$last_reason;
		}
		return array();
	}

	static function feature_off( $old_slug = null ) {
		// Remove .htaccess feature
		SiteGuard_Htaccess::clear_settings( self::$htaccess_mark );

		// Remove stubs
		$that = new self();
		$that->remove_stub( $that->old_stub_abspath() );
		if ( $that->slug() !== $that->old_slug() ) {
			$that->remove_stub( $that->stub_abspath() );
		}

		// reset mode
		global $siteguard_config;
		$siteguard_config->set( 'renamelogin_stub', SITEGUARD_RENAME_MODE_HTACCESS );
		$siteguard_config->update();

		return true;
	}

	public function guard_disabled_entry() {
		global $siteguard_config, $wp;

		if ( '1' === $siteguard_config->get( 'renamelogin_enable' ) ) {
			return;
		}

		$slug     = $this->slug();
		$req_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';

		$hit_slug = ( $req_path !== '' && rtrim( $req_path, '/' ) === '/' . $slug );
		$hit_stub = ( $req_path !== '' && rtrim( $req_path, '/' ) === '/' . $this->stub_filename() );

		if ( $hit_slug || $hit_stub ) {
			if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'logout' ) {
				wp_safe_redirect( wp_logout_url() );
				exit;
			}
			$this->set_404();
		}
	}

	public function handle_siteguard_rescue() {
		global $siteguard_config;

		if ( '1' !== $siteguard_config->get( 'renamelogin_enable' ) ) {
			return;
		}

		if ( '1' !== $siteguard_config->get( 'rescue_enable' ) ) {
			return;
		}
		if ( ! isset( $_GET['siteguard_rescue'] ) || '1' !== (string) $_GET['siteguard_rescue'] ) {
			return;
		}

		if ( $this->current_rescue_count() >= 3 ) {
			$this->fixed_delay();
			status_header( 429 );
			nocache_headers();
			$this->render_rescue_message( esc_html__( 'Request limit reached. Please try again later.', 'siteguard' ) );
			exit;
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$this->increment_rescue_counter();
			$this->process_rescue_post();
			exit;
		}
		$this->render_rescue_form();
		exit;
	}

	private function rescue_rate_key( $ip ) {
		return 'sg_rescue_count_' . md5( (string) $ip ); }
	private function increment_rescue_counter() {
		$ip  = $this->get_ip();
		$key = $this->rescue_rate_key( $ip );
		$cnt = (int) get_transient( $key );
		++$cnt;
		set_transient( $key, $cnt, HOUR_IN_SECONDS );
		return $cnt;
	}
	private function current_rescue_count() {
		$ip  = $this->get_ip();
		$key = $this->rescue_rate_key( $ip );
		return (int) get_transient( $key );
	}

	private function render_rescue_form( $errors = array(), $email_value = '' ) {
		// This form is what an administrator locked out of the login page has
		// left, and it draws the same CAPTCHA image. On a server that cannot
		// render one, generating it here would take the rescue path down with the
		// same 500 as the login page. Show the form without the CAPTCHA instead:
		// the rate limit of three attempts per hour per IP, the fixed delay and
		// the identical response whether or not the address exists all still
		// apply, so this does not turn into a usable oracle.
		$captcha_available = SiteGuard_CAPTCHA::is_captcha_available();
		$prefix            = '';
		$imgsrc            = '';
		if ( $captcha_available ) {
			$captcha  = new SiteGuardReallySimpleCaptcha();
			$language = get_bloginfo( 'language' );
			( strpos( $language, 'ja' ) === 0 ) ? $captcha->set_lang_mode( 'jp' ) : $captcha->set_lang_mode( 'en' );
			$prefix = siteguard_rand();
			$word   = $captcha->generate_random_word();
			$captcha->generate_image( $prefix, $word );
			$imgsrc = esc_url( WP_CONTENT_URL . '/siteguard/' . $prefix . '.png' );
		}

		$action = esc_url( add_query_arg( 'siteguard_rescue', '1', site_url( '/' ) ) );

		nocache_headers();
		echo '<!DOCTYPE html><html><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		echo '<meta name="robots" content="noindex,nofollow" />';
		echo '<title>' . esc_html__( 'Login URL Rescue', 'siteguard' ) . '</title>';
		echo '</head><body>';
		echo '<h1>' . esc_html__( 'Login URL Rescue', 'siteguard' ) . '</h1>';

		if ( ! empty( $errors ) ) {
			echo '<div role="alert" style="color:#b00;">';
			foreach ( (array) $errors as $e ) {
				echo '<p>' . esc_html( $e ) . '</p>';
			}
			echo '</div>';
		}

		echo '<form method="post" action="' . $action . '">';
		wp_nonce_field( 'siteguard_rescue', 'siteguard_rescue_nonce' );

		echo '<p><label>' . esc_html__( 'Administrator email address', 'siteguard' ) . '<br />';
		echo '<input type="email" name="siteguard_rescue_email" value="' . esc_attr( $email_value ) . '" required style="min-width:280px;" />';
		echo '</label></p>';

		if ( $captcha_available ) {
			echo '<p><img src="' . $imgsrc . '" alt="CAPTCHA" /></p>';
			echo '<p><label>' . esc_html__( 'Enter the characters shown above', 'siteguard' ) . '<br />';
			echo '<input type="text" name="siteguard_captcha" value="" size="10" required />';
			echo '</label></p>';
			echo '<input type="hidden" name="siteguard_captcha_prefix" value="' . esc_attr( $prefix ) . '" />';
		}

		echo '<p><button type="submit">' . esc_html__( 'Send email', 'siteguard' ) . '</button></p>';
		echo '</form>';

		echo '</body></html>';
	}

	private function uniform_delay( $start_ts_ms, $min_ms = 1800, $max_ms = 3200 ) {
		$target  = (int) wp_rand( $min_ms, $max_ms );
		$elapsed = (int) ( ( microtime( true ) * 1000 ) - $start_ts_ms );
		$remain  = $target - $elapsed;
		if ( $remain > 0 ) {
			usleep( $remain * 1000 );
		}
	}
	private function fixed_delay( $min_ms = 1800, $max_ms = 3200 ) {
		$target = (int) wp_rand( $min_ms, $max_ms );
		if ( $target > 0 ) {
			usleep( $target * 1000 );
		}
	}

	private function process_rescue_post() {
		$start = (int) round( microtime( true ) * 1000 );

		if ( ! isset( $_POST['siteguard_rescue_nonce'] ) || ! wp_verify_nonce( $_POST['siteguard_rescue_nonce'], 'siteguard_rescue' ) ) {
			status_header( 400 );
			$this->uniform_delay( $start );
			$this->render_rescue_form( array( esc_html__( 'Invalid request.', 'siteguard' ) ), isset( $_POST['siteguard_rescue_email'] ) ? sanitize_email( $_POST['siteguard_rescue_email'] ) : '' );
			return;
		}

		email_exists( 'dummy@example.com' );

		$email = isset( $_POST['siteguard_rescue_email'] ) ? sanitize_email( $_POST['siteguard_rescue_email'] ) : '';
		$cap   = isset( $_POST['siteguard_captcha'] ) ? sanitize_text_field( $_POST['siteguard_captcha'] ) : '';
		$pref  = isset( $_POST['siteguard_captcha_prefix'] ) ? sanitize_text_field( $_POST['siteguard_captcha_prefix'] ) : '';

		$errors = array();
		if ( empty( $email ) || ! is_email( $email ) ) {
			$errors[] = esc_html__( 'Please enter a valid email address.', 'siteguard' );
		}

		// Verify only what the form was able to present. render_rescue_form()
		// leaves the CAPTCHA out when the server cannot draw one, and demanding it
		// here would reject every submission and close the rescue path for good.
		if ( SiteGuard_CAPTCHA::is_captcha_available() ) {
			$captcha       = new SiteGuardReallySimpleCaptcha();
			$valid_captcha = ( $pref !== '' && $cap !== '' && $captcha->check( $pref, $cap, true ) );
			if ( ! $valid_captcha ) {
				$errors[] = esc_html__( 'Invalid CAPTCHA.', 'siteguard' );
			}
		}

		if ( ! empty( $errors ) ) {
			$this->uniform_delay( $start );
			$this->render_rescue_form( $errors, $email );
			return;
		}

		$user = get_user_by( 'email', $email );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			$url     = $this->get_login_url();
			$subject = esc_html__( 'WordPress: Login URL Rescue', 'siteguard' );
			$body    = sprintf(
				esc_html__( "You requested the login URL.\n\nURL: %s\n\nIf you did not request this, you can ignore this email.\n\n--\nSiteGuard WP Plugin", 'siteguard' ),
				$url
			);
			@wp_mail( $email, $subject, $body );
		}

		nocache_headers();
		$this->uniform_delay( $start );
		$this->render_rescue_message( esc_html__( 'An email has been sent if the address exists.', 'siteguard' ) );
	}

	private function render_rescue_message( $message ) {
		echo '<!DOCTYPE html><html><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		echo '<meta name="robots" content="noindex,nofollow" />';
		echo '<title>' . esc_html__( 'Login URL Rescue', 'siteguard' ) . '</title>';
		echo '</head><body>';
		echo '<h1>' . esc_html__( 'Login URL Rescue', 'siteguard' ) . '</h1>';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</body></html>';
	}

	public function get_login_url() {
		global $siteguard_config;
		if ( '0' === $siteguard_config->get( 'renamelogin_enable' ) ) {
			return rtrim( site_url(), '/' ) . '/wp-login.php';
		}
		if ( $this->is_stub_mode() ) {
			return $this->stub_url(); }
		return rtrim( site_url(), '/' ) . '/' . $this->slug();
	}

	function set_404() {
		global $wp_query;
		status_header( 404 );
		$wp_query->set_404();
		if ( ( ( $template = get_404_template() ) || ( $template = get_index_template() ) )
			&& ( $template = apply_filters( 'template_include', $template ) ) ) {
			include $template;
		}
		die;
	}

	function send_notify() {
			$subject = esc_html__( 'WordPress: Login page URL changed', 'siteguard' );
			$body    = sprintf( esc_html__( "Please bookmark the new login URL.\n\n%s\n\n--\nSiteGuard WP Plugin", 'siteguard' ), $this->get_login_url() );

		$user_query = new WP_User_Query( array( 'role' => 'Administrator' ) );
		if ( ! empty( $user_query->results ) ) {
			foreach ( $user_query->results as $user ) {
				$user_email = $user->get( 'user_email' );
				if ( true !== @wp_mail( $user_email, $subject, $body ) ) {
					siteguard_error_log( 'Failed send mail. To:' . $user_email . ' Subject:' . esc_html( $subject ) );
				}
			}
		}
	}

	function handler_stop_redirect( $scheme ) {
		global $siteguard_config;
		$redirect_enable = $siteguard_config->get( 'redirect_enable' );
		if ( $redirect_enable == 1 ) {
			if ( $user_id = wp_validate_auth_cookie( '', $scheme ) ) {
				return $scheme;
			}
			wp_safe_redirect( home_url() );
			exit;
		}
	}

	function handler_plugins_loaded() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request               = parse_url( $_SERVER['REQUEST_URI'] );
		$denied_slugs          = array( 'wp-register', 'wp-signup', 'wp-activate' );
		$denied_slugs_to_regex = implode( '|', $denied_slugs );

		$is_denied = false;
		if ( is_array( $request ) && isset( $request['path'] ) ) {
			$is_denied = preg_match( '#\/(' . $denied_slugs_to_regex . ')(\.php)?$#i', untrailingslashit( $request['path'] ) );
		}
		if ( $is_denied && ! is_admin() ) {
			$this->denied_login = true;
			// In stub mode, .htaccess rules are absent; block signup/activate directly.
			if ( $this->is_stub_mode() ) {
				status_header( 404 );
				nocache_headers();
				exit;
			}
		}
	}

	public function filter_logout_url( $logout_url, $redirect ) {
		global $siteguard_config;
		if ( '1' !== $siteguard_config->get( 'renamelogin_enable' ) ) {
			return $logout_url;
		}
		$base = $this->get_login_url();

		// Rebuild the logout URL from scratch to mirror WordPress core's
		// wp_logout_url(): urlencode redirect_to, then wrap with wp_nonce_url().
		//
		// We deliberately do NOT parse_str( $logout_url ) and re-emit its query:
		// the incoming $logout_url has already been passed through wp_nonce_url(),
		// which esc_html()-encodes it (so separators are "&amp;"). parse_str() then
		// splits on "&", mangling keys into "amp;redirect_to"/"amp;_wpnonce" and
		// url-decoding the value back to its raw form. add_query_arg() re-emits that
		// raw value, so an attacker-controlled redirect_to would break out of the
		// href printed (without esc_url) by wp_nonce_ays( 'log-out' ) -> XSS.
		//
		// wp_logout_url() applies the same urlencode() contract that consumers rely
		// on, and wp_nonce_url() re-adds the logout nonce, so building fresh keeps
		// the URL correctly (and singly) encoded.
		$args = array( 'action' => 'logout' );
		if ( ! empty( $redirect ) ) {
			$args['redirect_to'] = urlencode( $redirect );
		}
		return wp_nonce_url( add_query_arg( $args, $base ), 'log-out' );
	}

	public function rewrite_adminbar_logout( $wp_admin_bar ) {
		if ( ! is_user_logged_in() ) {
			return; }
		if ( ! is_object( $wp_admin_bar ) ) {
			return; }
		foreach ( array( 'logout', 'log-out' ) as $id ) {
			$node = $wp_admin_bar->get_node( $id );
			if ( ! $node || empty( $node->href ) ) {
				continue; }
			$new = $this->filter_logout_url( $node->href, '' );
			if ( $new && $new !== $node->href ) {
				$node->href = $new;
				$wp_admin_bar->add_node( $node );
			}
		}
	}

	/**
	 * Result of the login URL conflict probe for this request, or null when no
	 * other plugin was found to be changing the login page URL.
	 *
	 * Keys: 'url' (the login URL the other plugin produces), 'plugin' (its name,
	 * or '' when it could not be resolved), 'same_url' (bool) and 'fatal_risk'
	 * (bool). Populated by check_login_url_conflict() on admin_init.
	 *
	 * @return array|null
	 */
	public static function get_login_url_conflict() {
		return self::$conflict;
	}

	/**
	 * Run the login URL conflict probe once per admin request.
	 *
	 * Only admin screens are checked: the result is only ever shown to an
	 * administrator, and admin_init is the earliest hook that runs after every
	 * other plugin has registered its URL filters. admin-ajax.php also fires
	 * admin_init, so AJAX (and cron / REST) is excluded to keep the probe off
	 * background requests.
	 */
	public function check_login_url_conflict() {
		global $siteguard_config;

		if ( self::$conflict_checked ) {
			return;
		}
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '1' !== $siteguard_config->get( 'renamelogin_enable' ) ) {
			return;
		}

		self::$conflict_checked = true;
		self::$conflict         = $this->probe_foreign_login_url();
	}

	/**
	 * Detect whether another plugin is also changing the login page URL.
	 *
	 * Rather than checking for known plugins by name, this observes the symptom
	 * itself: every plugin that renames the login page has to rewrite the URLs
	 * WordPress generates for wp-login.php, and does so through the 'site_url'
	 * or 'login_url' filters. So an unfiltered wp-login.php URL is pushed
	 * through those filters with our own rewrite suspended (self::$probing); if
	 * "wp-login.php" is gone from the result, somebody else is rewriting it too.
	 *
	 * Requiring "wp-login.php" to disappear (rather than any change at all) is
	 * what keeps plugins that merely decorate site_url — multilingual plugins
	 * adding a language prefix, for instance — from being reported.
	 *
	 * @return array|null Conflict details, or null when nothing was detected.
	 */
	private function probe_foreign_login_url() {
		// Build the URL without site_url() so the filters under test are applied
		// exactly once, by us, below.
		$raw = rtrim( (string) get_option( 'siteurl' ), '/' ) . '/wp-login.php';

		$probes = array(
			'site_url'  => array( 'wp-login.php', null, null ),
			'login_url' => array( '', false ),
		);

		// The probe runs other plugins' filter callbacks. A failure in one of
		// them has to stay inside that one probe: it must not take the admin
		// screen down, and it must not skip the remaining hook — a plugin that
		// only filters login_url is exactly the kind this feature looks for.
		// self::$probing is cleared either way, or the URL rewriting would stay
		// disabled for the rest of the request.
		$found = null;
		self::$probing = true;
		foreach ( $probes as $hook => $args ) {
			$params = array_merge( array( $raw ), $args );
			try {
				$probed = call_user_func_array( 'apply_filters', array_merge( array( $hook ), $params ) );
			} catch ( Exception $e ) {
				siteguard_error_log( 'Login URL conflict probe failed on ' . $hook . ': ' . $e->getMessage() );
				continue;
			} catch ( Throwable $e ) {
				siteguard_error_log( 'Login URL conflict probe failed on ' . $hook . ': ' . $e->getMessage() );
				continue;
			}
			if ( ! is_string( $probed ) || '' === $probed || false !== strpos( $probed, 'wp-login.php' ) ) {
				continue;
			}
			// The conflict itself is established at this point. Failing to name
			// the plugin responsible must not discard it.
			$plugin = '';
			try {
				$plugin = $this->conflicting_plugin_names( $hook, $raw, $args );
			} catch ( Exception $e ) {
				siteguard_error_log( 'Naming the conflicting plugin failed: ' . $e->getMessage() );
			} catch ( Throwable $e ) {
				siteguard_error_log( 'Naming the conflicting plugin failed: ' . $e->getMessage() );
			}
			$found = array(
				'url'    => $probed,
				'plugin' => $plugin,
			);
			break;
		}
		self::$probing = false;

		if ( null === $found ) {
			return null;
		}

		$same_url = $this->is_same_login_url( $this->get_login_url(), $found['url'] );

		return array(
			'url'        => $found['url'],
			'plugin'     => $found['plugin'],
			'same_url'   => $same_url,
			// The fatal error only happens when our .htaccess rewrite makes the
			// core wp-login.php execute (declaring login_header() and friends)
			// while the other plugin, matching the very same URL, loads its own
			// copy of the login page on top of it. In stub (.php) mode our URL
			// ends in ".php", which no other plugin's slug matches.
			'fatal_risk' => ( $same_url && ! $this->is_stub_mode() ),
		);
	}

	/**
	 * Names of the active plugins whose callbacks on $hook_name remove
	 * wp-login.php from the URL. Each foreign callback is applied on its own so
	 * that a plugin which merely happens to filter the same hook is not blamed
	 * for another plugin's rewrite.
	 *
	 * @param string $hook_name Filter to inspect.
	 * @param string $raw       Unfiltered wp-login.php URL.
	 * @param array  $args      Remaining filter arguments.
	 * @return string Comma separated plugin names, or '' when none could be resolved.
	 */
	private function conflicting_plugin_names( $hook_name, $raw, $args ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook_name ] ) ) {
			return '';
		}

		$self_dir = wp_normalize_path( SITEGUARD_PATH );
		$names    = array();

		foreach ( $wp_filter[ $hook_name ] as $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}
			foreach ( $callbacks as $callback ) {
				if ( ! isset( $callback['function'] ) || ! is_callable( $callback['function'] ) ) {
					continue;
				}
				$file = $this->callback_file( $callback['function'] );
				if ( '' === $file || 0 === strpos( wp_normalize_path( $file ), $self_dir ) ) {
					continue;
				}
				$name = $this->plugin_name_by_file( $file );
				if ( '' === $name || in_array( $name, $names, true ) ) {
					continue;
				}

				$accepted = isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : 1;
				$params   = array_slice( array_merge( array( $raw ), $args ), 0, max( 1, $accepted ) );
				try {
					$result = call_user_func_array( $callback['function'], $params );
				} catch ( Exception $e ) {
					continue;
				} catch ( Throwable $e ) {
					continue;
				}
				if ( is_string( $result ) && false === strpos( $result, 'wp-login.php' ) ) {
					$names[] = $name;
				}
			}
		}

		return implode( ', ', $names );
	}

	/**
	 * Source file a filter callback is defined in, or '' when it cannot be
	 * resolved (internal functions, or anything Reflection refuses).
	 *
	 * @param mixed $callback Callback as stored in $wp_filter.
	 * @return string
	 */
	private function callback_file( $callback ) {
		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
				$ref   = new ReflectionMethod( $class, $callback[1] );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$ref = new ReflectionMethod( $callback );
			} elseif ( is_object( $callback ) && ! ( $callback instanceof Closure ) ) {
				$ref = new ReflectionMethod( $callback, '__invoke' );
			} else {
				$ref = new ReflectionFunction( $callback );
			}
		} catch ( Exception $e ) {
			return '';
		}

		$file = $ref->getFileName();
		return is_string( $file ) ? $file : '';
	}

	/**
	 * Name of the active plugin that owns $file, or '' when the file does not
	 * belong to one (a theme, a must-use plugin, or WordPress itself).
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private function plugin_name_by_file( $file ) {
		$plugin_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$file       = wp_normalize_path( $file );
		if ( 0 !== strpos( $file, $plugin_dir ) ) {
			return '';
		}

		$relative = substr( $file, strlen( $plugin_dir ) );
		$slug     = ( false !== strpos( $relative, '/' ) ) ? substr( $relative, 0, strpos( $relative, '/' ) ) : $relative;
		if ( '' === $slug ) {
			return '';
		}

		foreach ( get_plugins() as $plugin_file => $data ) {
			if ( $plugin_file !== $slug && 0 !== strpos( $plugin_file, $slug . '/' ) ) {
				continue;
			}
			if ( is_plugin_active( $plugin_file ) && ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return '';
	}

	/**
	 * Whether two login URLs would be requested by the same path. Trailing
	 * slashes are ignored (some plugins hand out a trailing slashed URL); a
	 * query string has to match as well, because a plugin that puts its slug in
	 * the query (e.g. /?secret) is not hit by a request for our path.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	private function is_same_login_url( $a, $b ) {
		$path_a  = untrailingslashit( (string) wp_parse_url( $a, PHP_URL_PATH ) );
		$path_b  = untrailingslashit( (string) wp_parse_url( $b, PHP_URL_PATH ) );
		$query_a = (string) wp_parse_url( $a, PHP_URL_QUERY );
		$query_b = (string) wp_parse_url( $b, PHP_URL_QUERY );

		return ( $path_a === $path_b && $query_a === $query_b );
	}

	/**
	 * Warning text for a detected conflict. Returns pre-escaped HTML.
	 *
	 * @param array $conflict As returned by get_login_url_conflict().
	 * @return string
	 */
	public static function conflict_message( $conflict ) {
		$url    = '<code>' . esc_html( $conflict['url'] ) . '</code>';
		$plugin = '' !== $conflict['plugin'] ? '<strong>' . esc_html( $conflict['plugin'] ) . '</strong>' : '';

		if ( ! empty( $conflict['fatal_risk'] ) ) {
			if ( '' !== $plugin ) {
				return sprintf(
					/* translators: 1: plugin name, 2: login URL */
					esc_html__( '%1$s is also changing the login page URL, to the same URL as SiteGuard (%2$s). In this state the login page can stop working with a PHP fatal error. Please turn off the login page URL change feature in one of the two plugins.', 'siteguard' ),
					$plugin,
					$url
				);
			}
			return sprintf(
				/* translators: %s: login URL */
				esc_html__( 'Another active plugin is also changing the login page URL, to the same URL as SiteGuard (%s). In this state the login page can stop working with a PHP fatal error. Please turn off the login page URL change feature in one of the two plugins.', 'siteguard' ),
				$url
			);
		}

		if ( '' !== $plugin ) {
			return sprintf(
				/* translators: 1: plugin name, 2: login URL */
				esc_html__( '%1$s is also changing the login page URL (%2$s). Two different login URLs stay available, which weakens this feature, and the two can conflict later. Please use the login page URL change feature in only one of the two plugins.', 'siteguard' ),
				$plugin,
				$url
			);
		}
		return sprintf(
			/* translators: %s: login URL */
			esc_html__( 'Another active plugin is also changing the login page URL (%s). Two different login URLs stay available, which weakens this feature, and the two can conflict later. Please use the login page URL change feature in only one of the two plugins.', 'siteguard' ),
			$url
		);
	}

	/**
	 * Admin notice for the case that actually breaks the site: both plugins
	 * pointing at the same URL while we serve it through the .htaccess rewrite.
	 * The milder cases are reported on the Rename Login screen only, so that a
	 * situation which is not currently breaking anything does not follow the
	 * administrator around the dashboard.
	 */
	public function maybe_notice_login_url_conflict() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// The Rename Login screen prints its own, always-visible block.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which admin screen is being rendered; no state is changed.
		$screen = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'siteguard_rename_login' === $screen ) {
			return;
		}
		$conflict = self::get_login_url_conflict();
		if ( null === $conflict || empty( $conflict['fatal_risk'] ) ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( self::conflict_message( $conflict ) ) . '</p></div>';
	}

	public function maybe_notice_stub_failed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		if ( get_transient( self::STUB_WRITE_FAIL_TRANSIENT ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'SiteGuard: Could not create the required login file. Please check file permissions or contact your hosting provider.', 'siteguard' );
			echo '</p></div>';
			delete_transient( self::STUB_WRITE_FAIL_TRANSIENT );
		}
	}
}
