<?php

class SiteGuard_Menu_Dashboard extends SiteGuard_Base {
	function __construct() {
		$this->render_page();
	}
	private function render_status_icon( $enabled, $img_path ) {
		$filename = $enabled ? 'enabled.svg' : 'disabled.svg';
		$alt_text = $enabled ? __( 'Enabled', 'siteguard' ) : __( 'Disabled', 'siteguard' );
		printf(
			'<img class="siteguard-status-icon" src="%1$s" width="24" height="24" alt="%2$s">',
			esc_url( $img_path . $filename ),
			esc_attr( $alt_text )
		);
	}
	function render_page() {
		global $siteguard_config, $siteguard_login_history;
		$img_path                  = SITEGUARD_URL_PATH . 'images/';
		$admin_filter_enable       = $siteguard_config->get( 'admin_filter_enable' );
		$renamelogin_enable        = $siteguard_config->get( 'renamelogin_enable' );
		$captcha_enable            = $siteguard_config->get( 'captcha_enable' );
		$same_error_enable         = $siteguard_config->get( 'same_login_error' );
		$loginlock_enable          = $siteguard_config->get( 'loginlock_enable' );
		$loginalert_enable         = $siteguard_config->get( 'loginalert_enable' );
		$fail_once_enable          = $siteguard_config->get( 'loginlock_fail_once' );
		$disable_xmlrpc_enable     = $siteguard_config->get( 'disable_xmlrpc_enable' );
		$disable_pingback_enable   = $siteguard_config->get( 'disable_pingback_enable' );
		$block_author_query_enable = $siteguard_config->get( 'block_author_query_enable' );
		$updates_notify_enable     = $siteguard_config->get( 'updates_notify_enable' );
		$waf_exclude_rule_enable   = $siteguard_config->get( 'waf_exclude_rule_enable' );
		echo '<div class="wrap">';
		echo '<img src="' . $img_path . 'sg_wp_plugin_logo_40.png" alt="SiteGuard Logo" />';
		echo '<h2>' . esc_html__( 'Dashboard', 'siteguard' ) . "</h2>\n";
		$siteguard_page_link = '<a href="' . esc_url( __( 'https://www.jp-secure.com/siteguard_wp_plugin_en/', 'siteguard' ) ) . '" target="_blank">' . esc_html__( 'SiteGuard WP Plugin page', 'siteguard' ) . '</a>';
		echo '<div class="siteguard-description">'
			. sprintf(
				/* translators: %1$s: Link to the SiteGuard WP Plugin page. */
				esc_html__( 'Documentation, FAQs, and more information about SiteGuard WP Plugin are available on the %1$s.', 'siteguard' ),
				$siteguard_page_link
			)
			. '</div>';
		echo '<h3>' . esc_html__( 'Settings Status', 'siteguard' ) . "</h3>\n";
		$error = siteguard_check_multisite();
		if ( is_wp_error( $error ) ) {
			echo '<p class="description">';
			echo esc_html( $error->get_error_message() );
			echo '</p>';
		}
		?>
		<table class="siteguard-form-table">
		<tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $admin_filter_enable, $img_path ); ?>
		<a href="?page=siteguard_admin_filter"><?php esc_html_e( 'Admin Page IP Filter', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Restricts wp-admin access to IP addresses that have successfully logged in.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $renamelogin_enable, $img_path ); ?>
		<a href="?page=siteguard_rename_login"><?php esc_html_e( 'Rename Login', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Changes the URL of the login page.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $captcha_enable, $img_path ); ?>
		<a href="?page=siteguard_captcha"><?php esc_html_e( 'CAPTCHA', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Adds CAPTCHA to login, comment, password reset, and user registration forms.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $same_error_enable, $img_path ); ?>
		<a href="?page=siteguard_same_error"><?php esc_html_e( 'Same Login Error Message', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Displays a generic message instead of detailed login error messages.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $loginlock_enable, $img_path ); ?>
		<a href="?page=siteguard_login_lock"><?php esc_html_e( 'Login Lock', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Temporarily locks out IP addresses after repeated failed login attempts.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $loginalert_enable, $img_path ); ?>
		<a href="?page=siteguard_login_alert"><?php esc_html_e( 'Login Alert', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Sends an email notification when a user logs in.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $fail_once_enable, $img_path ); ?>
			<a href="?page=siteguard_fail_once"><?php esc_html_e( 'Fail Once', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Requires the first login attempt to fail, even when the credentials are correct.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $disable_pingback_enable || '1' == $disable_xmlrpc_enable, $img_path ); ?>
			<a href="?page=siteguard_protect_xmlrpc"><?php esc_html_e( 'Protect XML-RPC', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Prevents abuse of XML-RPC and pingbacks.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $block_author_query_enable, $img_path ); ?>
		<a href="?page=siteguard_author_query"><?php esc_html_e( 'Block Author Query', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Blocks author queries that may expose usernames.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $updates_notify_enable, $img_path ); ?>
			<a href="?page=siteguard_updates_notify"><?php esc_html_e( 'Update Notifications', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Sends administrators an email when WordPress core, plugins, or themes need updates.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
		<?php $this->render_status_icon( '1' == $waf_exclude_rule_enable, $img_path ); ?>
		<a href="?page=siteguard_waf_tuning_support"><?php esc_html_e( 'WAF Tuning Support', 'siteguard' ); ?></a></th>
			<td><?php esc_html_e( 'Creates WAF exclusion rules for SiteGuard Server Edition.', 'siteguard' ); ?></td>
		</tr><tr>
		<th scope="row">
			<span class="siteguard-status-icon-spacer" aria-hidden="true"></span>
			<a href="?page=siteguard_login_history"><?php echo esc_html__( 'Login History', 'siteguard' ); ?></a>
			<td><?php esc_html_e( 'Displays login history.', 'siteguard' ); ?></td>
		</tr>
		</table>
		<hr />
		</div>
		<?php
	}
}
