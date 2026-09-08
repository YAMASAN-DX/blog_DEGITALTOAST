<?php

class SiteGuard_Menu_Updates_Notify extends SiteGuard_Base {
	const   OPT_NAME_ENABLE  = 'updates_notify_enable';
	const   OPT_NAME_WPCORE  = 'notify_wpcore';
	const   OPT_NAME_PLUGINS = 'notify_plugins';
	const   OPT_NAME_THEMES  = 'notify_themes';

	function __construct() {
		$this->render_page();
	}
	function is_notify_value( $value ) {
		$items = array( '0', '1', '2' );
		if ( in_array( $value, $items ) ) {
			return true;
		}
		return false;
	}
	function render_page() {
		global $siteguard_config, $siteguard_updates_notify;

		$opt_val_enable  = $siteguard_config->get( self::OPT_NAME_ENABLE );
		$opt_val_wpcore  = $siteguard_config->get( self::OPT_NAME_WPCORE );
		$opt_val_plugins = $siteguard_config->get( self::OPT_NAME_PLUGINS );
		$opt_val_themes  = $siteguard_config->get( self::OPT_NAME_THEMES );
		if ( isset( $_POST['update'] ) && check_admin_referer( 'siteguard-menu-updates-notify-submit' ) ) {
			$error  = false;
			$errors = siteguard_check_multisite();
			if ( is_wp_error( $errors ) ) {
				echo '<div class="error settings-error"><p><strong>';
				echo esc_html( $errors->get_error_message() );
				echo '</strong></p></div>';
				$error = true;
			}
			// A radio group renders with nothing checked when its stored value is
			// missing or unrecognized, and a group with no selection is not sent
			// with the form. Reading $_POST directly then failed the validation
			// below, so the one page that can turn the feature off -- and clear
			// its cron event -- could not be saved on exactly the configurations
			// that need it. An absent value means the disabled choice: a group the
			// user did select is always posted, so nothing can be enabled by this.
			$post_enable  = isset( $_POST[ self::OPT_NAME_ENABLE ] ) ? sanitize_text_field( $_POST[ self::OPT_NAME_ENABLE ] ) : '0';
			$post_wpcore  = isset( $_POST[ self::OPT_NAME_WPCORE ] ) ? sanitize_text_field( $_POST[ self::OPT_NAME_WPCORE ] ) : '0';
			$post_plugins = isset( $_POST[ self::OPT_NAME_PLUGINS ] ) ? sanitize_text_field( $_POST[ self::OPT_NAME_PLUGINS ] ) : '0';
			$post_themes  = isset( $_POST[ self::OPT_NAME_THEMES ] ) ? sanitize_text_field( $_POST[ self::OPT_NAME_THEMES ] ) : '0';
			if ( ( false === $error )
				&& ( ( false === $this->is_switch_value( $post_enable ) )
				|| ( false === $this->is_switch_value( $post_wpcore ) )
				|| ( false === $this->is_notify_value( $post_plugins ) )
				|| ( false === $this->is_notify_value( $post_themes ) ) ) ) {
				echo '<div class="error settings-error"><p><strong>';
				esc_html_e( 'ERROR: Invalid input value.', 'siteguard' );
				echo '</strong></p></div>';
				$error = true;
			}
			if ( false === $error && '1' === $post_enable ) {
				$ret = $siteguard_updates_notify->check_requirements();
				if ( is_wp_error( $ret ) ) {
					echo '<div class="error settings-error"><p><strong>' . esc_html( $ret->get_error_message() ) . '</strong></p></div>';
					$error = true;
					$siteguard_config->set( self::OPT_NAME_ENABLE, '0' );
					$siteguard_config->update();
					// The stored setting now says OFF, so the cron event goes with
					// it. Without this an event from an earlier ON state keeps
					// sending notifications on a site whose settings page shows the
					// feature as off and refuses to turn it on.
					SiteGuard_UpdatesNotify::feature_off();
				}
			}
			if ( false === $error ) {
				$opt_val_enable  = $post_enable;
				$opt_val_wpcore  = $post_wpcore;
				$opt_val_plugins = $post_plugins;
				$opt_val_themes  = $post_themes;
				$siteguard_config->set( self::OPT_NAME_ENABLE, $opt_val_enable );
				$siteguard_config->set( self::OPT_NAME_WPCORE, $opt_val_wpcore );
				$siteguard_config->set( self::OPT_NAME_PLUGINS, $opt_val_plugins );
				$siteguard_config->set( self::OPT_NAME_THEMES, $opt_val_themes );
				$siteguard_config->update();
				if ( '1' === $opt_val_enable ) {
					SiteGuard_UpdatesNotify::feature_on();
				} else {
					SiteGuard_UpdatesNotify::feature_off();
				}
				?>
				<div class="updated"><p><strong><?php esc_html_e( 'Options saved.', 'siteguard' ); ?></strong></p></div>
				<?php
			}
		}

		echo '<div class="wrap">';
		echo '<img src="' . SITEGUARD_URL_PATH . 'images/sg_wp_plugin_logo_40.png" alt="SiteGuard Logo" />';
		echo '<h2>' . esc_html__( 'Update Notifications', 'siteguard' ) . '</h2>';
		$documentation_link = '<a href="' . esc_url( __( 'https://www.jp-secure.com/siteguard_wp_plugin_en/howto/updates_notify/', 'siteguard' ) ) . '" target="_blank">' . esc_html__( 'online documentation', 'siteguard' ) . '</a>';
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
			<input type="radio" name="<?php echo self::OPT_NAME_ENABLE; ?>" id="<?php echo self::OPT_NAME_ENABLE . '_on'; ?>" value="1" <?php checked( $opt_val_enable, '1' ); ?> >
			<label for="<?php echo self::OPT_NAME_ENABLE . '_on'; ?>"><?php esc_html_e( 'ON', 'siteguard' ); ?></label>
			</li><li>
			<input type="radio" name="<?php echo self::OPT_NAME_ENABLE; ?>" id="<?php echo self::OPT_NAME_ENABLE . '_off'; ?>" value="0" <?php checked( $opt_val_enable, '0' ); ?> >
			<label for="<?php echo self::OPT_NAME_ENABLE . '_off'; ?>"><?php esc_html_e( 'OFF', 'siteguard' ); ?></label>
			</li>
			</ul>
			<?php
			$error = $siteguard_updates_notify->check_requirements();
			if ( is_wp_error( $error ) ) {
				echo '<p class="description">';
				echo esc_html( $error->get_error_message() );
				echo '</p>';
			}
			?>
		</th>
		</tr><tr>
		<th scope="row"><?php esc_html_e( 'WordPress updates', 'siteguard' ); ?></th>
			<td>
				<input type="radio" name="<?php echo self::OPT_NAME_WPCORE; ?>" id="<?php echo self::OPT_NAME_WPCORE . '_0'; ?>" value="0" <?php checked( $opt_val_wpcore, '0' ); ?> >
				<label for="<?php echo self::OPT_NAME_WPCORE . '_0'; ?>"><?php esc_html_e( 'Disable', 'siteguard' ); ?></label>
				<br />
				<input type="radio" name="<?php echo self::OPT_NAME_WPCORE; ?>" id="<?php echo self::OPT_NAME_WPCORE . '_1'; ?>" value="1" <?php checked( $opt_val_wpcore, '1' ); ?> >
				<label for="<?php echo self::OPT_NAME_WPCORE . '_1'; ?>"><?php esc_html_e( 'Enable', 'siteguard' ); ?></label>
			</td>
		</tr><tr>
		<th scope="row"><?php esc_html_e( 'Plugin updates', 'siteguard' ); ?></th>
			<td>
				<input type="radio" name="<?php echo self::OPT_NAME_PLUGINS; ?>" id="<?php echo self::OPT_NAME_PLUGINS . '_0'; ?>" value="0" <?php checked( $opt_val_plugins, '0' ); ?> >
				<label for="<?php echo self::OPT_NAME_PLUGINS . '_0'; ?>"><?php esc_html_e( 'Disable', 'siteguard' ); ?></label>
				<br />
				<input type="radio" name="<?php echo self::OPT_NAME_PLUGINS; ?>" id="<?php echo self::OPT_NAME_PLUGINS . '_1'; ?>" value="1" <?php checked( $opt_val_plugins, '1' ); ?> >
				<label for="<?php echo self::OPT_NAME_PLUGINS . '_1'; ?>"><?php esc_html_e( 'All plugins', 'siteguard' ); ?></label>
				<br />
				<input type="radio" name="<?php echo self::OPT_NAME_PLUGINS; ?>" id="<?php echo self::OPT_NAME_PLUGINS . '_2'; ?>" value="2" <?php checked( $opt_val_plugins, '2' ); ?> >
				<label for="<?php echo self::OPT_NAME_PLUGINS . '_2'; ?>"><?php esc_html_e( 'Active plugins only', 'siteguard' ); ?></label>
			</td>
		</tr><tr>
		<th scope="row"><?php esc_html_e( 'Theme updates', 'siteguard' ); ?></th>
			<td>
				<input type="radio" name="<?php echo self::OPT_NAME_THEMES; ?>" id="<?php echo self::OPT_NAME_THEMES . '_0'; ?>" value="0" <?php checked( $opt_val_themes, '0' ); ?> >
				<label for="<?php echo self::OPT_NAME_THEMES . '_0'; ?>"><?php esc_html_e( 'Disable', 'siteguard' ); ?></label>
				<br />
				<input type="radio" name="<?php echo self::OPT_NAME_THEMES; ?>" id="<?php echo self::OPT_NAME_THEMES . '_1'; ?>" value="1" <?php checked( $opt_val_themes, '1' ); ?> >
				<label for="<?php echo self::OPT_NAME_THEMES . '_1'; ?>"><?php esc_html_e( 'All themes', 'siteguard' ); ?></label>
				<br />
				<input type="radio" name="<?php echo self::OPT_NAME_THEMES; ?>" id="<?php echo self::OPT_NAME_THEMES . '_2'; ?>" value="2" <?php checked( $opt_val_themes, '2' ); ?> >
				<label for="<?php echo self::OPT_NAME_THEMES . '_2'; ?>"><?php esc_html_e( 'Active themes only', 'siteguard' ); ?></label>
			</td>
		</tr>
		</table>
		<div class="siteguard-description">
			<?php esc_html_e( 'Keeping WordPress core, plugins, and themes up to date is a basic security practice. This feature checks for updates every 24 hours and emails administrators when updates are available.', 'siteguard' ); ?>
		</div>
		<hr />
		<input type="hidden" name="update" value="Y">

		<?php
		wp_nonce_field( 'siteguard-menu-updates-notify-submit' );
		submit_button();
		?>

		</form>
		</div>

		<?php
	}
}
