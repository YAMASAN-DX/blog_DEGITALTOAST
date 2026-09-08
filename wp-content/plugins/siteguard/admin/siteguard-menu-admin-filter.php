<?php

class SiteGuard_Menu_Admin_Filter extends SiteGuard_Base {
	const OPT_NAME_FEATURE = 'admin_filter_enable';
	const OPT_NAME_EXCLUDE = 'admin_filter_exclude_path';

	function __construct() {
		$this->render_page();
	}

	function render_page() {
		global $siteguard_admin_filter, $siteguard_config;

		$opt_val_feature = $siteguard_config->get( self::OPT_NAME_FEATURE );
		$opt_val_exclude = $this->cvt_camma2ret( $siteguard_config->get( self::OPT_NAME_EXCLUDE ) );
		if ( isset( $_POST['update'] ) && check_admin_referer( 'siteguard-menu-admin-filter-submit' ) ) {
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
				$old_opt_val_feature = $opt_val_feature;
				$old_opt_val_exclude = $opt_val_exclude;
				$opt_val_feature     = sanitize_text_field( $_POST[ self::OPT_NAME_FEATURE ] );
				$opt_val_exclude     = stripslashes( sanitize_textarea_field( $_POST[ self::OPT_NAME_EXCLUDE ] ) );
				$siteguard_config->set( self::OPT_NAME_FEATURE, $opt_val_feature );
				$siteguard_config->set( self::OPT_NAME_EXCLUDE, $this->cvt_ret2camma( $opt_val_exclude ) );
				$siteguard_config->update();
				$result = true;
				if ( '0' === $opt_val_feature ) {
					$result = $siteguard_admin_filter->feature_off();
				} else {
					$result = $siteguard_admin_filter->feature_on( $this->get_ip() );
				}
				if ( true === $result ) {
					$opt_val_exclude = $this->cvt_camma2ret( $opt_val_exclude );
					?>
					<div class="updated"><p><strong><?php esc_html_e( 'Options saved.', 'siteguard' ); ?></strong></p></div>
					<?php
				} else {
					$opt_val_feature = $old_opt_val_feature;
					$opt_val_exclude = $old_opt_val_exclude;
					$siteguard_config->set( self::OPT_NAME_FEATURE, $opt_val_feature );
					$siteguard_config->set( self::OPT_NAME_EXCLUDE, $this->cvt_ret2camma( $opt_val_exclude ) );
					$siteguard_config->update();
					echo '<div class="error settings-error"><p><strong>';
					esc_html_e( 'ERROR: Failed to update settings. Please try again.', 'siteguard' );
					echo '</strong></p></div>';
				}
			}
		}

		echo '<div class="wrap">';
		echo '<img src="' . SITEGUARD_URL_PATH . 'images/sg_wp_plugin_logo_40.png" alt="SiteGuard Logo" />';
		echo '<h2>' . esc_html__( 'Admin Page IP Filter', 'siteguard' ) . '</h2>';
		$documentation_link = '<a href="' . esc_url( __( 'https://www.jp-secure.com/siteguard_wp_plugin_en/howto/admin_filter/', 'siteguard' ) ) . '" target="_blank">' . esc_html__( 'online documentation', 'siteguard' ) . '</a>';
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
			<label for="<?php echo self::OPT_NAME_FEATURE . '_on'; ?>" ><?php echo esc_html_e( 'ON', 'siteguard' ); ?></label>
			</li>
			<li>
			<input type="radio" name="<?php echo self::OPT_NAME_FEATURE; ?>" id="<?php echo self::OPT_NAME_FEATURE . '_off'; ?>" value="0" <?php checked( $opt_val_feature, '0' ); ?> >
			<label for="<?php echo self::OPT_NAME_FEATURE . '_off'; ?>" ><?php echo esc_html_e( 'OFF', 'siteguard' ); ?></label>
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
		<th scope="row"><label for="<?php echo self::OPT_NAME_EXCLUDE; ?>"><?php echo esc_html_e( 'Exclude Path', 'siteguard' ); ?></label></th>
		<td><textarea name="<?php echo self::OPT_NAME_EXCLUDE; ?>" id="<?php echo self::OPT_NAME_EXCLUDE; ?>" cols=40 rows=5 ><?php echo esc_textarea( $opt_val_exclude ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Enter the path after /wp-admin/ to exclude. One path per line.', 'siteguard' ); ?></p></td>
		</tr>
		</table>
		<input type="hidden" name="update" value="Y">
		<div class="siteguard-description">
		<?php
		esc_html_e(
			'Blocks unauthorized access to the admin area. Only computers that have previously logged in are allowed through. Access is automatically removed after 24 hours. Note: This protection covers WordPress pages only, not static files such as images or stylesheets. You can specify paths to exclude from this protection.',
			'siteguard'
		);
		?>
		</div>
		<hr />
		<?php
		wp_nonce_field( 'siteguard-menu-admin-filter-submit' );
		submit_button();
		?>
		</form>
		</div>

		<?php
	}
}
