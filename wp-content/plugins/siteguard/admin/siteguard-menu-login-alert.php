<?php

class SiteGuard_Menu_Login_Alert extends SiteGuard_Base {
	const   OPT_NAME_FEATURE = 'loginalert_enable';
	const   OPT_NAME_SUBJECT = 'loginalert_subject';
	const   OPT_NAME_BODY    = 'loginalert_body';
	const   OPT_NAME_ADMIN   = 'loginalert_admin_only';

	function __construct() {
		$this->render_page();
	}
	function render_page() {
		global $siteguard_config;

		$opt_val_feature = $siteguard_config->get( self::OPT_NAME_FEATURE );
		$opt_val_subject = $siteguard_config->get( self::OPT_NAME_SUBJECT );
		$opt_val_body    = $siteguard_config->get( self::OPT_NAME_BODY );
		$opt_val_admin   = $siteguard_config->get( self::OPT_NAME_ADMIN );
		if ( isset( $_POST['update'] ) && check_admin_referer( 'siteguard-menu-login-alert-submit' ) ) {
			$error  = false;
			$errors = siteguard_check_multisite();
			if ( is_wp_error( $errors ) ) {
				echo '<div class="error settings-error"><p><strong>';
				echo esc_html( $errors->get_error_message() );
				echo '</strong></p></div>';
				$error = true;
			}
			if ( false === $error && false === $this->is_switch_value( $_POST[ self::OPT_NAME_FEATURE ] ) ) {
				echo '<div class="error settings-error"><p><strong>';
				esc_html_e( 'ERROR: Invalid input value.', 'siteguard' );
				echo '</strong></p></div>';
				$error = true;
			}
			if ( false === $error ) {
				$opt_val_feature = sanitize_text_field( $_POST[ self::OPT_NAME_FEATURE ] );
				$opt_val_subject = sanitize_text_field( $_POST[ self::OPT_NAME_SUBJECT ] );
				$opt_val_body    = $_POST[ self::OPT_NAME_BODY ];
				$opt_val_body    = str_replace( '%DA', 'PERCENT_DA', $opt_val_body );
				$opt_val_body    = sanitize_textarea_field( $opt_val_body );
				$opt_val_body    = str_replace( 'PERCENT_DA', '%DA', $opt_val_body );
				if ( isset( $_POST[ self::OPT_NAME_ADMIN ] ) ) {
					$opt_val_admin = '1';
				} else {
					$opt_val_admin = '0';
				}
				$siteguard_config->set( self::OPT_NAME_FEATURE, $opt_val_feature );
				$siteguard_config->set( self::OPT_NAME_SUBJECT, $opt_val_subject );
				$siteguard_config->set( self::OPT_NAME_BODY, $opt_val_body );
				$siteguard_config->set( self::OPT_NAME_ADMIN, $opt_val_admin );
				$siteguard_config->update();
				?>
				<div class="updated"><p><strong><?php esc_html_e( 'Options saved.', 'siteguard' ); ?></strong></p></div>
				<?php
			}
		}

		echo '<div class="wrap">';
		echo '<img src="' . SITEGUARD_URL_PATH . 'images/sg_wp_plugin_logo_40.png" alt="SiteGuard Logo" />';
		echo '<h2>' . esc_html__( 'Login Alert', 'siteguard' ) . '</h2>';
		$documentation_link = '<a href="' . esc_url( __( 'https://www.jp-secure.com/siteguard_wp_plugin_en/howto/login_alert/', 'siteguard' ) ) . '" target="_blank">' . esc_html__( 'online documentation', 'siteguard' ) . '</a>';
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
			$error = siteguard_check_multisite();
			if ( is_wp_error( $error ) ) {
				echo '<p class="description">';
				echo esc_html( $error->get_error_message() );
				echo '</p>';
			}
			?>
		</th>
		</tr><tr>
		<th scope="row"><label for="<?php echo self::OPT_NAME_SUBJECT; ?>"><?php esc_html_e( 'Subject', 'siteguard' ); ?></label></th>
		<td>
			<input type="text" name="<?php echo self::OPT_NAME_SUBJECT; ?>" id="<?php echo self::OPT_NAME_SUBJECT; ?>" size="50" value="<?php echo esc_attr( $opt_val_subject ); ?>" >
		</td>
		</tr><tr>
		<th scope="row"><label for="<?php echo self::OPT_NAME_BODY; ?>"><?php esc_html_e( 'Body', 'siteguard' ); ?></label></th>
		<td>
			<textarea name="<?php echo self::OPT_NAME_BODY; ?>" id="<?php echo self::OPT_NAME_BODY; ?>" cols="50" rows="5" ><?php echo esc_textarea( $opt_val_body ); ?></textarea>
		</td>
		</tr><tr>
		<th scope="row"><?php esc_html_e( 'Recipients', 'siteguard' ); ?></th>
		<td>
			<input type="checkbox" name="<?php echo self::OPT_NAME_ADMIN; ?>" id="<?php echo self::OPT_NAME_ADMIN; ?>" value="1" <?php checked( $opt_val_admin, '1' ); ?> >
			<label for="<?php echo self::OPT_NAME_ADMIN; ?>"><?php esc_html_e( 'Admin only', 'siteguard' ); ?></label>
		</td>
		</tr>
		</table>
		<input type="hidden" name="update" value="Y">
		<div class="siteguard-description">
			<?php esc_html_e( 'Sends an email notification when a user logs in, helping you notice unauthorized logins. If you receive a login alert for a login you do not recognize, check the account immediately. The subject and body can use these variables: Site Name:%SITENAME%, User Name:%USERNAME%, Date:%DATE%, Time:%TIME%, IP Address:%IPADDRESS%, User-Agent:%USERAGENT%, Referer:%REFERER%. XML-RPC logins are not reported.', 'siteguard' ); ?>
		</div>
		<hr />
		<?php
		wp_nonce_field( 'siteguard-menu-login-alert-submit' );
		submit_button();
		?>
		</form>
		</div>
		<?php
	}
}
