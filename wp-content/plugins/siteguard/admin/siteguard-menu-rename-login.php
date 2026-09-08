<?php

class SiteGuard_Menu_Rename_Login extends SiteGuard_Base {
	const   OPT_NAME_FEATURE           = 'renamelogin_enable';
	const   OPT_NAME_FEATURE_REDIRECT  = 'redirect_enable';
	const   OPT_NAME_RENAME_LOGIN_PATH = 'renamelogin_path';
	const   OPT_NAME_OLD_LOGIN_PATH    = 'oldlogin_path';
	const   OPT_NAME_STUB              = 'renamelogin_stub';
	const   OPT_NAME_RESCUE            = 'rescue_enable';

	private static $pre_processed  = false;
	private static $pre_errors     = array();
	private static $pre_slug_value = null;

	function __construct() {
		$this->render_page();
	}

	private static function get_disallowed_slugs() {
		$disallowed = array();
		$files      = scandir( ABSPATH );
		if ( false === $files ) {
			return array();
		}

		foreach ( $files as $file ) {
			// Ignore dot files, current and parent directories.
			if ( '.' === $file[0] ) {
				continue;
			}

			if ( is_dir( ABSPATH . $file ) ) {
				$disallowed[] = $file;
			} else {
				// For files, use the name without extension.
				$disallowed[] = pathinfo( $file, PATHINFO_FILENAME );
			}
		}
		return array_unique( $disallowed );
	}

	private static function stub_abs_path( $slug ) {
		$slug = trim( (string) $slug, '/' );
		if ( $slug === '' ) {
			$slug = 'login'; }
		return trailingslashit( ABSPATH ) . $slug . '.php';
	}

	public static function pre_handle_post() {
		if ( ! isset( $_POST['update'] ) || ! check_admin_referer( 'siteguard-menu-rename-login-submit' ) ) {
			return;
		}

		self::$pre_processed = true;
		global $siteguard_config, $siteguard_rename_login;

		$error   = false;
		$errors  = siteguard_check_multisite();
		if ( is_wp_error( $errors ) ) {
			self::$pre_errors[] = $errors->get_error_message();
			$error              = true;
		}

		$posted_feature = isset( $_POST[ self::OPT_NAME_FEATURE ] ) ? sanitize_text_field( $_POST[ self::OPT_NAME_FEATURE ] ) : '';
		if ( false === $error && ( '0' !== $posted_feature && '1' !== $posted_feature ) ) {
			self::$pre_errors[] = esc_html__( 'ERROR: Invalid input value.', 'siteguard' );
			$error              = true;
		}

		if ( false === $error ) {
			$posted_slug = sanitize_text_field( $_POST[ self::OPT_NAME_RENAME_LOGIN_PATH ] );
			if ( 1 !== preg_match( '/^[a-zA-Z0-9_-]+$/', $posted_slug ) ) {
				self::$pre_errors[]  = esc_html__( 'Only letters, numbers, hyphens, and underscores can be used for the login path.', 'siteguard' );
				self::$pre_slug_value = stripslashes( $posted_slug );
				$error               = true;
			}

			if ( false === $error && strlen( $posted_slug ) < 4 ) {
					self::$pre_errors[]   = esc_html__( 'The new login path must be at least 4 characters long.', 'siteguard' );
				self::$pre_slug_value = stripslashes( $posted_slug );
				$error                = true;
			}

			if ( false === $error ) {
				$current_slug = $siteguard_config->get( self::OPT_NAME_RENAME_LOGIN_PATH );
				if ( $posted_slug !== $current_slug ) {
					$disallowed_slugs = self::get_disallowed_slugs();
					if ( in_array( $posted_slug, $disallowed_slugs, true ) ) {
						/* translators: %s: slug */
							self::$pre_errors[]  = sprintf( esc_html__( '%s cannot be used for the new login path because it conflicts with an existing file or directory in the WordPress root.', 'siteguard' ), '<code>' . esc_html( $posted_slug ) . '</code>' );
						self::$pre_slug_value = stripslashes( $posted_slug );
						$error               = true;
					}
				}
			}
		}

		if ( false === $error ) {
			$old_feature           = $siteguard_config->get( self::OPT_NAME_FEATURE );
			$old_feature_redirect  = $siteguard_config->get( self::OPT_NAME_FEATURE_REDIRECT );
			$old_rename_login_path = $siteguard_config->get( self::OPT_NAME_RENAME_LOGIN_PATH );
			$old_old_login_path    = $siteguard_config->get( self::OPT_NAME_OLD_LOGIN_PATH );
			$old_rescue            = $siteguard_config->get( self::OPT_NAME_RESCUE );

			$new_feature           = $posted_feature;
			$new_feature_redirect  = isset( $_POST[ self::OPT_NAME_FEATURE_REDIRECT ] ) ? '1' : '0';
			$new_rename_login_path = $posted_slug;
			$new_rescue            = isset( $_POST[ self::OPT_NAME_RESCUE ] ) ? '1' : '0';

			$siteguard_config->set( self::OPT_NAME_FEATURE, $new_feature );
			$siteguard_config->set( self::OPT_NAME_FEATURE_REDIRECT, $new_feature_redirect );
			$siteguard_config->set( self::OPT_NAME_RENAME_LOGIN_PATH, $new_rename_login_path );
			$siteguard_config->set( self::OPT_NAME_OLD_LOGIN_PATH, $old_rename_login_path );
			$siteguard_config->set( self::OPT_NAME_RESCUE, $new_rescue );
			$siteguard_config->update();

			$result = true;
			if ( '0' === $new_feature ) {
				$result = $siteguard_rename_login->feature_off();
			} else {
				$result        = $siteguard_rename_login->feature_on();
				$stub_required = ! $siteguard_rename_login->can_use_htaccess();
				$stub_file     = self::stub_abs_path( $new_rename_login_path );
				$stub_fail     = (bool) get_transient( SiteGuard_RenameLogin::STUB_WRITE_FAIL_TRANSIENT );

				if ( false === $result || ( $stub_required && ( $stub_fail || ! file_exists( $stub_file ) ) ) ) {
					$siteguard_config->set( self::OPT_NAME_FEATURE, $old_feature );
					$siteguard_config->set( self::OPT_NAME_FEATURE_REDIRECT, $old_feature_redirect );
					$siteguard_config->set( self::OPT_NAME_RENAME_LOGIN_PATH, $old_rename_login_path );
					$siteguard_config->set( self::OPT_NAME_OLD_LOGIN_PATH, $old_old_login_path );
					$siteguard_config->set( self::OPT_NAME_RESCUE, $old_rescue );
					$siteguard_config->update();
					SiteGuard_RenameLogin::feature_off();
					$result = false;

					if ( $stub_required || $stub_fail ) {
						self::$pre_errors[] = esc_html__( 'ERROR: Failed to enable. WordPress cannot create the required file in the site root.', 'siteguard' )
							. ' ' . esc_html__( 'Please check that the WordPress root directory is writable, or contact your hosting provider.', 'siteguard' );
					} else {
						self::$pre_errors[] = esc_html__( 'ERROR: Failed to update settings. Please try again.', 'siteguard' );
					}
				} elseif ( '1' === $new_feature ) {
					$siteguard_rename_login->send_notify();
				}

				if ( $stub_fail ) {
					delete_transient( SiteGuard_RenameLogin::STUB_WRITE_FAIL_TRANSIENT );
				}
			}

			if ( true === $result ) {
				wp_safe_redirect( admin_url( 'admin.php?page=siteguard_rename_login&updated=1' ) );
				exit;
			}
		}
	}

	private function current_mode_description() {
		global $siteguard_config;

		if ( '1' === $siteguard_config->get( self::OPT_NAME_FEATURE ) ) {
			$slug     = trim( (string) $siteguard_config->get( self::OPT_NAME_RENAME_LOGIN_PATH ), '/' );
			$stub_on  = ( SITEGUARD_RENAME_MODE_STUB === $siteguard_config->get( self::OPT_NAME_STUB ) );
			$loginurl = rtrim( site_url(), '/' ) . '/' . ( $stub_on ? ( $slug . '.php' ) : $slug );
			if ( $stub_on ) {
				$reason = $this->stub_reason_text();
				return sprintf(
					'%s <code>%s</code><br>%s%s',
					esc_html__( 'Login URL:', 'siteguard' ),
					esc_url( $loginurl ),
					esc_html__( 'The login URL ends with .php because this server does not support .htaccess.', 'siteguard' ),
					'' !== $reason ? '<br>' . $reason : ''
				);
			} else {
				return sprintf(
					'%s <code>%s</code>',
					esc_html__( 'Login URL:', 'siteguard' ),
					esc_url( $loginurl )
				);
			}
		}
		$loginurl = rtrim( site_url(), '/' ) . '/wp-login.php';
		return sprintf(
			'%s <code>%s</code><br>%s',
			esc_html__( 'Login URL:', 'siteguard' ),
			$loginurl,
				esc_html__( 'This feature is currently off.', 'siteguard' )
		);
	}

	/**
	 * Human-readable explanation of why stub (.php) mode was chosen, based on the
	 * reason recorded by SiteGuard_RenameLogin::feature_on(). Returns '' when no
	 * reason is available. The returned string may contain pre-escaped HTML.
	 */
	private function stub_reason_text() {
		global $siteguard_config;
		$reason = $siteguard_config->get( self::OPT_NAME_STUB . '_reason' );
		if ( ! is_array( $reason ) || empty( $reason['code'] ) ) {
			return '';
		}
		$url = isset( $reason['url'] ) ? '<code>' . esc_html( $reason['url'] ) . '</code>' : '';
		switch ( $reason['code'] ) {
			case 'nginx':
				return esc_html__( 'Reason: the server is Nginx, which does not use .htaccess.', 'siteguard' );
			case 'server_software':
				return esc_html__( 'Reason: the server is not Apache/LiteSpeed, so .htaccess is not used.', 'siteguard' );
			case 'not_writable':
				return esc_html__( 'Reason: the .htaccess file (or the WordPress directory) is not writable.', 'siteguard' );
			case 'mkdir':
				return esc_html__( 'Reason: a temporary test directory could not be created in the WordPress directory (check write permission).', 'siteguard' );
			case 'write':
				return esc_html__( 'Reason: the temporary test files could not be written.', 'siteguard' );
			case 'htaccess_ignored':
				return sprintf(
					/* translators: %s: test URL */
					esc_html__( 'Reason: the .htaccess file is present but the server is ignoring it (for example AllowOverride is set to None, or mod_rewrite is not enabled for this directory), so the rewrite for %s had no effect.', 'siteguard' ),
					$url
				);
			case 'wp_error':
				return sprintf(
					/* translators: 1: test URL, 2: error message */
					esc_html__( 'Reason: the self-test request to %1$s failed (%2$s). The server may be unable to reach its own URL (loopback).', 'siteguard' ),
					$url,
					esc_html( isset( $reason['detail'] ) ? $reason['detail'] : '' )
				);
			case 'http_status':
				$status = (int) ( isset( $reason['status'] ) ? $reason['status'] : 0 );
				if ( 401 === $status || 403 === $status ) {
					$hint = esc_html__( 'It may be blocked by an access restriction such as Basic authentication or an IP restriction.', 'siteguard' );
				} elseif ( 404 === $status ) {
					$hint = esc_html__( 'The test URL was not found, for example because WordPress is installed in a subdirectory so the self-test URL does not map to it, or the request is routed elsewhere.', 'siteguard' );
				} elseif ( 429 === $status || 503 === $status ) {
					$hint = esc_html__( 'The request was rate-limited or temporarily blocked. This is usually a server-side rate limit or anti-bot/access-control protection (separate from .htaccess), not a sign that .htaccess is broken. Try again after a short while.', 'siteguard' );
				} elseif ( $status >= 300 && $status < 400 ) {
					$hint = esc_html__( 'The request was redirected (for example HTTP to HTTPS, or a canonical redirect).', 'siteguard' );
				} else {
					$hint = '';
				}
				return sprintf(
					/* translators: 1: test URL, 2: HTTP status code */
					esc_html__( 'Reason: the self-test request to %1$s returned HTTP %2$s instead of 200.', 'siteguard' ),
					$url,
					esc_html( (string) $status )
				) . ( '' !== $hint ? ' ' . $hint : '' );
			case 'bad_body':
				return sprintf(
					/* translators: %s: test URL */
					esc_html__( 'Reason: the self-test request to %s did not return the expected result; the .htaccess rewrite did not take effect (it may be disabled by AllowOverride or overridden by another rule).', 'siteguard' ),
					$url
				);
		}
		return '';
	}

	function render_page() {
		global $siteguard_config;

		$opt_val_feature           = $siteguard_config->get( self::OPT_NAME_FEATURE );
		$opt_val_feature_redirect  = $siteguard_config->get( self::OPT_NAME_FEATURE_REDIRECT );
		$opt_val_rename_login_path = ( null !== self::$pre_slug_value )
			? self::$pre_slug_value
			: $siteguard_config->get( self::OPT_NAME_RENAME_LOGIN_PATH );
		$opt_val_rescue            = $siteguard_config->get( self::OPT_NAME_RESCUE );

		foreach ( self::$pre_errors as $msg ) {
			echo '<div class="error settings-error"><p><strong>' . wp_kses_post( $msg ) . '</strong></p></div>';
		}

		echo '<div class="wrap">';
		echo '<img src="' . SITEGUARD_URL_PATH . 'images/sg_wp_plugin_logo_40.png" alt="SiteGuard Logo" />';
		echo '<h2>' . esc_html__( 'Rename Login', 'siteguard' ) . '</h2>';

		if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) {
			echo '<div class="updated"><p><strong>';
			esc_html_e( 'Options saved.', 'siteguard' );
			echo '</strong></p></div>';
		}

		echo '<div class="notice notice-info" style="padding:8px 12px;margin-top:10px;">' . wp_kses_post( $this->current_mode_description() ) . '</div>';

		$conflict = SiteGuard_RenameLogin::get_login_url_conflict();
		if ( null !== $conflict ) {
			$class = ! empty( $conflict['fatal_risk'] ) ? 'notice notice-error' : 'notice notice-warning';
			echo '<div class="' . esc_attr( $class ) . '" style="padding:8px 12px;margin-top:10px;">'
				. wp_kses_post( SiteGuard_RenameLogin::conflict_message( $conflict ) )
				. '</div>';
		}

		$documentation_link = '<a href="' . esc_url( __( 'https://www.jp-secure.com/siteguard_wp_plugin_en/howto/rename_login/', 'siteguard' ) ) . '" target="_blank">' . esc_html__( 'online documentation', 'siteguard' ) . '</a>';
		echo '<div class="siteguard-description">'
			. sprintf(
				/* translators: %1$s: Link to the online documentation. */
				esc_html__( 'See the %1$s.', 'siteguard' ),
				$documentation_link
			)
			. '</div>';
		?>
		<form name="form1" method="post" action="">
		<table class="form-table">
		<tr>
		<th scope="row" colspan="2">
			<ul class="siteguard-radios">
				<li>
					<input type="radio" name="<?php echo self::OPT_NAME_FEATURE; ?>" id="<?php echo self::OPT_NAME_FEATURE . '_on'; ?>" value="1" <?php checked( $opt_val_feature, '1' ); ?> >
					<label for="<?php echo self::OPT_NAME_FEATURE . '_on'; ?>"><?php echo esc_html_e( 'ON', 'siteguard' ); ?></label>
				</li><li>
					<input type="radio" name="<?php echo self::OPT_NAME_FEATURE; ?>" id="<?php echo self::OPT_NAME_FEATURE . '_off'; ?>" value="0" <?php checked( $opt_val_feature, '0' ); ?> >
					<label for="<?php echo self::OPT_NAME_FEATURE . '_off'; ?>"><?php echo esc_html_e( 'OFF', 'siteguard' ); ?></label>
				</li>
			</ul>
			<?php
			echo '<p class="description">';
			esc_html_e( 'Works automatically on most servers. If activation fails, please check file permissions or contact your hosting provider.', 'siteguard' );
			echo '</p>';
			?>
		</th>
		</tr><tr>
		<th scope="row"><label for="<?php echo self::OPT_NAME_RENAME_LOGIN_PATH; ?>"><?php esc_html_e( 'New Login Path', 'siteguard' ); ?></label></th>
		<td>
			<?php
			$stub_on = ( SITEGUARD_RENAME_MODE_STUB === $siteguard_config->get( self::OPT_NAME_STUB ) );
			echo esc_url( site_url() ) . '/';
			?>
			<input type="text" name="<?php echo self::OPT_NAME_RENAME_LOGIN_PATH; ?>" id="<?php echo self::OPT_NAME_RENAME_LOGIN_PATH; ?>" value="<?php echo esc_attr( $opt_val_rename_login_path ); ?>" >
			<?php
			if ( '1' === $opt_val_feature && $stub_on ) {
				echo '.php';
			}
			echo '<p class="description">';
			if ( $stub_on ) {
				esc_html_e( 'The actual login URL will include .php at the end.', 'siteguard' );
			} else {
				esc_html_e( 'Letters, numbers, hyphens, and underscores only.', 'siteguard' );
			}
			echo '</p>';
			?>
		</td>
		</tr><tr>
		<th scope="row"><?php esc_html_e( 'Option', 'siteguard' ); ?></th>
		<td>
			<p>
				<input type="checkbox" name="<?php echo self::OPT_NAME_FEATURE_REDIRECT; ?>" id="<?php echo self::OPT_NAME_FEATURE_REDIRECT; ?>" value="1" <?php checked( $opt_val_feature_redirect, '1' ); ?> >
					<label for="<?php echo self::OPT_NAME_FEATURE_REDIRECT; ?>"><?php esc_html_e( 'Do not redirect from the admin page to the login page.', 'siteguard' ); ?></label>
			</p>
			<p>
				<input type="checkbox" name="<?php echo self::OPT_NAME_RESCUE; ?>" id="<?php echo self::OPT_NAME_RESCUE; ?>" value="1" <?php checked( $opt_val_rescue, '1' ); ?> >
				<label for="<?php echo self::OPT_NAME_RESCUE; ?>"><?php echo esc_html__( 'Enable login rescue', 'siteguard' ); ?> (<code><?php echo esc_url( add_query_arg( 'siteguard_rescue', '1', site_url( '/' ) ) ); ?></code>)</label>
				<br><span class="description"><?php esc_html_e( 'Users can request the current login URL by solving a CAPTCHA. For security, a confirmation is always shown regardless of whether the address exists.', 'siteguard' ); ?></span>
			</p>
		</td>
		</tr>
		</table>
		<input type="hidden" name="update" value="Y">
		<div class="siteguard-description">
		<?php esc_html_e( 'This protects your site by changing the login page URL, making it harder for automated attacks to find it. The default URL is “login_&lt;5 random digits&gt;” and can be customized.', 'siteguard' ); ?>
		</div>
		<hr />
		<?php
		wp_nonce_field( 'siteguard-menu-rename-login-submit' );
		submit_button();
		?>
		</form>
		</div>
		<?php
	}
}
