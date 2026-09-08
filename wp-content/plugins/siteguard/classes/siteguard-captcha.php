<?php

require_once SITEGUARD_PATH . 'really-simple-captcha/siteguard-really-simple-captcha.php';

class SiteGuard_CAPTCHA extends SiteGuard_Base {
	protected $captcha;
	protected $prefix;
	protected $word;

	private $last_check_result = null;
	private $last_check_prefix = null;

	/**
	 * Whether this PHP can render a CAPTCHA image at this moment.
	 *
	 * The requirements are checked on activation and on the settings page, but
	 * the environment can change afterwards: PHP is updated, the site is moved,
	 * GD is rebuilt without FreeType, the functions are listed in
	 * disable_functions -- or the plugin was activated through WP-CLI, whose SAPI
	 * has GD while php-fpm does not. The stored setting then says the feature is
	 * on while generate_image() would be a call to an undefined function, taking
	 * the login page down with a 500. So the capability is checked at run time,
	 * every request, right where the hooks are registered. The three functions
	 * are the ones generate_image() cannot do without.
	 */
	public static function is_image_rendering_available() {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagettftext' )
			&& function_exists( 'imagepng' );
	}

	/**
	 * Whether the image and answer files can actually be written.
	 *
	 * Activating through WP-CLI as a different user than the web server (root is
	 * the usual case) creates wp-content/siteguard/ owned by that user, and the
	 * web server can then create nothing inside it. The image write fails, the
	 * answer file is never written, and every response is rejected -- the login
	 * page becomes impossible to pass. make_tmp_dir() used to report success in
	 * that state because the directory already existed, so neither activation nor
	 * the settings page noticed.
	 *
	 * The directory is created on first use, so until it exists the question is
	 * whether it can be created, which is a property of the parent. Memoized: the
	 * answer cannot change within a request, and this is on the front-end path.
	 */
	public static function is_answer_dir_writable() {
		static $writable = null;
		if ( null !== $writable ) {
			return $writable;
		}
		$dir      = SiteGuardReallySimpleCaptcha::get_tmp_dir();
		$writable = is_dir( $dir ) ? is_writable( $dir ) : is_writable( WP_CONTENT_DIR );
		return $writable;
	}

	/**
	 * Whether a CAPTCHA can be produced and verified at all right now.
	 *
	 * Both halves have to hold together: presenting a CAPTCHA that cannot be
	 * checked, or demanding one that cannot be drawn, locks everyone out of the
	 * login page. This is the single answer used by the hook registration, by the
	 * login URL rescue form and by the requirement check on the settings page, so
	 * those three can never disagree with each other.
	 */
	public static function is_captcha_available() {
		return self::is_image_rendering_available() && self::is_answer_dir_writable();
	}

	function __construct() {
		global $siteguard_config;
		// The capability test belongs to this condition and not to any single
		// handler: the form and its verification are registered together, so a
		// server that cannot draw the image simply gets no CAPTCHA instead of a
		// form that demands characters nobody can read. captcha_enable is left
		// alone on purpose -- a temporary state of the environment is not a
		// reason to rewrite a stored setting, and the feature comes back by
		// itself once the server can render again.
		if ( '1' == $siteguard_config->get( 'captcha_enable' ) && 'xmlrpc.php' != basename( $_SERVER['SCRIPT_NAME'] ) && ! is_admin() && self::is_captcha_available() ) {
			$this->captcha = new SiteGuardReallySimpleCaptcha();

			add_filter( 'shake_error_codes', array( $this, 'handler_shake_error_codes' ) );

			// for login
			if ( '0' !== $siteguard_config->get( 'captcha_login' ) ) {
				add_filter( 'login_form', array( $this, 'handler_login_form' ) );
				add_filter( 'wp_authenticate_user', array( $this, 'handler_wp_authenticate_user' ), 1, 2 );
			}
			// for lost password
			if ( '0' !== $siteguard_config->get( 'captcha_lostpasswd' ) ) {
				add_filter( 'lostpassword_form', array( $this, 'handler_lostpassword_form' ) );
				add_filter( 'lostpassword_post', array( $this, 'handler_lostpassword_post' ), 1 );
			}
			// for register user
			if ( '0' !== $siteguard_config->get( 'captcha_registuser' ) ) {
				add_filter( 'register_form', array( $this, 'handler_register_form' ) );
				add_action( 'registration_errors', array( $this, 'handler_registration_errors' ), 10, 3 );
			}
			// for comment
			if ( '0' !== $siteguard_config->get( 'captcha_comment' ) ) {
				add_action( 'comment_form_after_fields', array( $this, 'handler_comment_form' ), 1 );
				add_action( 'comment_form_logged_in_after', array( $this, 'handler_comment_form' ), 1 );
				add_action( 'comment_form', array( $this, 'handler_comment_form' ) );
				add_filter( 'preprocess_comment', array( $this, 'handler_process_comment_post' ) );
				add_action( 'wp_footer', array( $this, 'comment_captcha_reload_script' ) );
			}
		}
		if ( '1' == $siteguard_config->get( 'same_login_error' ) ) {
			add_filter( 'login_errors', array( $this, 'handler_login_errors' ) );
		}
		// A security feature must not switch itself off quietly.
		if ( is_admin() && '1' == $siteguard_config->get( 'captcha_enable' ) && ! self::is_captcha_available() ) {
			add_action( 'admin_notices', array( $this, 'handler_admin_notices_captcha_unavailable' ) );
		}
	}

	function handler_admin_notices_captcha_unavailable() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// The two causes need different answers: one is for the hosting provider,
		// the other the administrator can fix, so name the directory.
		if ( ! self::is_image_rendering_available() ) {
			$message = esc_html__( 'SiteGuard WP Plugin: CAPTCHA is turned on, but this server cannot render the CAPTCHA image, so CAPTCHA is not being applied. Please contact your hosting provider about the required image rendering support.', 'siteguard' );
		} else {
			$message = sprintf(
				/* translators: %s: Filesystem path of the CAPTCHA working directory. */
				esc_html__( 'SiteGuard WP Plugin: CAPTCHA is turned on, but the directory %s cannot be written to by the web server, so CAPTCHA is not being applied. Please change the owner of this directory to the user the web server runs as.', 'siteguard' ),
				esc_html( SiteGuardReallySimpleCaptcha::get_tmp_dir() )
			);
		}
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo $message;
		echo ' ';
		echo esc_html__( 'The setting has been left as it is, and CAPTCHA resumes automatically once the problem is resolved.', 'siteguard' );
		echo '</p></div>';
	}
	private function check_captcha_with_cache() {
		$current_prefix = isset( $_POST['siteguard_captcha_prefix'] ) ? $_POST['siteguard_captcha_prefix'] : '';
		if ( $this->last_check_prefix !== $current_prefix ) {
			$this->last_check_result = null;
			$this->last_check_prefix = $current_prefix;
		}
		if ( null !== $this->last_check_result ) {
			return $this->last_check_result;
		}
	
		$is_ok = false;
		if ( array_key_exists( 'siteguard_captcha', $_POST ) && array_key_exists( 'siteguard_captcha_prefix', $_POST ) ) {
			$is_ok = $this->captcha->check( $_POST['siteguard_captcha_prefix'], $_POST['siteguard_captcha'], true );
		}
	
		$this->last_check_result = $is_ok;
		return $is_ok;
	}
	function check_requirements() {
		$error = siteguard_check_multisite();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$error = $this->check_extensions();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$error = $this->check_image_access();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$error = $this->check_support_freetype();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		return true;
	}

	function check_extensions() {
		$error_extensions = array();
		$extensions       = array(
			'mbstring',
			'gd',
		);
		foreach ( $extensions as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$error_extensions[] = $extension;
			}
		}
		if ( empty( $error_extensions ) ) {
			return true;
		}

		$message = esc_html__( 'This feature requires additional server components. Please contact your hosting provider to enable it.', 'siteguard' );

		$error = new WP_Error( 'siteguard_captcha', $message );
		return $error;
	}

	function check_image_access() {
		if ( is_object( $this->captcha ) ) {
			$ret = $this->captcha->make_tmp_dir();
		} else {
			$captcha = new SiteGuardReallySimpleCaptcha();
			$ret     = $captcha->make_tmp_dir();
		}
		if ( false === $ret ) {
			$message = esc_html__( 'Failed to write the CAPTCHA image file.', 'siteguard' );
			$error   = new WP_Error( 'siteguard_captcha', $message );
			return $error;
		}

		return true;
	}

	function check_support_freetype() {
		// The same three functions the run-time gate uses, so that the settings
		// page cannot report the feature as usable while it is being skipped on
		// the login page. imagettftext() alone missed a server that has GD and
		// FreeType but hides imagepng() behind disable_functions.
		if ( self::is_image_rendering_available() ) {
			return true;
		}
		$message = esc_html__( 'Your server does not support the image rendering required for this feature. Please contact your hosting provider.', 'siteguard' );
		$error   = new WP_Error( 'siteguard_captcha', $message );
		return $error;
	}

	function handler_login_errors( $error ) {
		if ( strlen( $error ) > 0 && false === strpos( $error, esc_html__( 'ERROR: LOGIN LOCKED', 'siteguard' ) ) ) {
			$error = esc_html__( 'ERROR: Please check your input and try again.', 'siteguard' );
		}
		return $error;
	}

	function handler_shake_error_codes( $shake_error_codes ) {
		array_push( $shake_error_codes, 'siteguard-captcha-error' );
		return $shake_error_codes;
	}

	function init() {
		global $siteguard_config;
		$errors = $this->check_requirements();
		if ( ! is_wp_error( $errors ) ) {
			$switch = '1';
		} else {
			$switch = '0';
		}
		$siteguard_config->set( 'captcha_enable', $switch );

		$language = get_bloginfo( 'language' );
		if ( 'ja' == $language ) {
			$mode = '1'; // hiragana
		} else {
			$mode = '2'; // alphanumeric
		}
		$siteguard_config->set( 'captcha_login', $mode );
		$siteguard_config->set( 'captcha_comment', $mode );
		$siteguard_config->set( 'captcha_lostpasswd', $mode );
		$siteguard_config->set( 'captcha_registuser', $mode );

		if ( true === siteguard_check_multisite() ) {
			$siteguard_config->set( 'same_login_error', '1' );
		} else {
			$siteguard_config->set( 'same_login_error', '0' );
		}
		$siteguard_config->update();
	}

	function get_captcha() {
		$result  = '<p>';
		$result .= '<img src="' . WP_CONTENT_URL . '/siteguard/' . $this->prefix . '.png" alt="CAPTCHA">';
		$result .= '</p><p>';
		$result .= '<label for="siteguard_captcha">' . esc_html__( 'Please enter the characters shown above.', 'siteguard' ) . '</label><br />';
		$result .= '<input type="text" name="siteguard_captcha" id="siteguard_captcha" class="input" value="" size="10" aria-required="true" />';
		$result .= '<input type="hidden" name="siteguard_captcha_prefix" id="siteguard_captcha_prefix" value="' . $this->prefix . '" />';
		$result .= '</p>';

		return $result;
	}

	function put_captcha() {
		$this->word   = $this->captcha->generate_random_word();
		$this->prefix = siteguard_rand();
		$this->captcha->generate_image( $this->prefix, $this->word );
		echo $this->get_captcha();
	}

	function handler_login_form() {
		global $siteguard_config;
		( '2' === $siteguard_config->get( 'captcha_login' ) ) ? $this->captcha->set_lang_mode( 'en' ) : $this->captcha->set_lang_mode( 'jp' );
		$this->put_captcha();
	}

	function handler_comment_form( $post_id ) {
		global $siteguard_config;
		if ( defined( 'SITEGUARD_PUT_COMMENT_FORM' ) ) {
			return;
		}
		( '2' === $siteguard_config->get( 'captcha_comment' ) ) ? $this->captcha->set_lang_mode( 'en' ) : $this->captcha->set_lang_mode( 'jp' );
		$this->put_captcha();
		define( 'SITEGUARD_PUT_COMMENT_FORM', '1' );
	}

	function handler_lostpassword_form() {
		global $siteguard_config;
		( '2' === $siteguard_config->get( 'captcha_lostpasswd' ) ) ? $this->captcha->set_lang_mode( 'en' ) : $this->captcha->set_lang_mode( 'jp' );
		$this->put_captcha();
	}

	function handler_register_form() {
		global $siteguard_config;
		( '2' == $siteguard_config->get( 'captcha_registuser' ) ) ? $this->captcha->set_lang_mode( 'en' ) : $this->captcha->set_lang_mode( 'jp' );
		$this->put_captcha();
	}

	function handler_wp_authenticate_user( $user, $password ) {
		if ( array_key_exists( 'siteguard_captcha', $_POST ) && array_key_exists( 'siteguard_captcha_prefix', $_POST ) ) {
			if ( $this->check_captcha_with_cache() ) {
				return $user;
			}
		}
		$error = new WP_Error();
		$error->add( 'siteguard-captcha-error', esc_html__( 'ERROR: Invalid CAPTCHA.', 'siteguard' ) );
		return $error;
	}

	function add_captcha_error() {
			return new WP_Error( 'siteguard-captcha-error', esc_html__( 'ERROR: Invalid CAPTCHA.', 'siteguard' ) );
	}

	function handler_lostpassword_post() {
		if ( array_key_exists( 'siteguard_captcha', $_POST ) && array_key_exists( 'siteguard_captcha_prefix', $_POST ) ) {
			if ( $this->check_captcha_with_cache() ) {
				return;
			}
		}
		add_filter( 'allow_password_reset', array( $this, 'add_captcha_error' ) );
	}

	function handler_registration_errors( $errors, $sanitized_user_login, $user_email ) {
		if ( array_key_exists( 'siteguard_captcha', $_POST ) && array_key_exists( 'siteguard_captcha_prefix', $_POST ) ) {
			if ( $this->check_captcha_with_cache() ) {
				return $errors;
			}
		}
		$new_errors = new WP_Error();
		$new_errors->add( 'siteguard-captcha-error', esc_html__( 'ERROR: Invalid CAPTCHA.', 'siteguard' ) );
		return $new_errors;
	}

	function handler_process_comment_post( $comment ) {
		if ( is_admin() ) {
			return $comment;
		}
		if ( array_key_exists( 'siteguard_captcha', $_POST ) && array_key_exists( 'siteguard_captcha_prefix', $_POST ) ) {
			if ( ! empty( $_POST['siteguard_captcha'] ) ) {
				if ( $this->check_captcha_with_cache( ) ) {
					return $comment;
				}
			}
		}
		wp_die( esc_html__( 'ERROR: Invalid CAPTCHA.', 'siteguard' ), esc_html( 'ERROR'), array( 'back_link' => true ) );
	}
	public function comment_captcha_reload_script( ) {
		if ( is_singular() && comments_open() ) {
			?>
			<script>
			window.addEventListener('pageshow', function(event) {
				var isBackForward = false;
				if (window.performance && typeof performance.getEntriesByType === 'function') {
					var perfEntries = performance.getEntriesByType('navigation');
					isBackForward = perfEntries.length > 0 && perfEntries[0].type === 'back_forward';
				}
				if (event.persisted || isBackForward) {
					window.location.reload();
				}
			});
			</script>
			<?php
		}
	}
}
