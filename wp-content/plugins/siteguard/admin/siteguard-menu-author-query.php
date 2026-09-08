<?php

class SiteGuard_Menu_Author_Query extends SiteGuard_Base {
	const   OPT_NAME_FEATURE = 'block_author_query_enable';
	const   OPT_NAME_RESTAPI = 'disable_restapi_enable';
	const   OPT_NAME_EXCLUDE = 'disable_restapi_exclude';

	function __construct() {
		$this->render_page();
	}
	private function get_rest_api_namespaces() {
		$server     = rest_get_server();
		$namespaces = $server->get_namespaces();

		natcasesort( $namespaces );

		return array_values( $namespaces );
	}
	private function normalize_rest_api_namespace( $namespace ) {
		$namespace = trim( $namespace );
		return trim( $namespace, '/' );
	}
	private function is_rest_api_namespace_excluded( $namespace, $excluded_namespaces ) {
		$namespace = $this->normalize_rest_api_namespace( $namespace );
		if ( '' === $namespace ) {
			return false;
		}

		foreach ( $excluded_namespaces as $excluded_namespace ) {
			$excluded_namespace = $this->normalize_rest_api_namespace( $excluded_namespace );
			if ( '' === $excluded_namespace ) {
				continue;
			}
			if ( $namespace === $excluded_namespace || strpos( $namespace, "$excluded_namespace/" ) === 0 ) {
				return true;
			}
		}
		return false;
	}
	function render_page() {
		global $siteguard_config, $siteguard_author_query;

		$opt_val_feature = $siteguard_config->get( self::OPT_NAME_FEATURE );
		$opt_val_restapi = $siteguard_config->get( self::OPT_NAME_RESTAPI );
		$opt_val_exclude = $this->cvt_camma2ret( $siteguard_config->get( self::OPT_NAME_EXCLUDE ) );
		if ( isset( $_POST['update'] ) && check_admin_referer( 'siteguard-menu-block-author-query-submit' ) ) {
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
				$old_opt_val_restapi = $opt_val_restapi;
				$old_opt_val_exclude = $opt_val_exclude;
				$opt_val_feature     = sanitize_text_field( $_POST[ self::OPT_NAME_FEATURE ] );
				if ( isset( $_POST[ self::OPT_NAME_RESTAPI ] ) ) {
					$opt_val_restapi = '1';
				} else {
					$opt_val_restapi = '0';
				}
				$opt_val_exclude = stripslashes( sanitize_textarea_field( $_POST[ self::OPT_NAME_EXCLUDE ] ) );
				$siteguard_config->set( self::OPT_NAME_FEATURE, $opt_val_feature );
				$siteguard_config->set( self::OPT_NAME_RESTAPI, $opt_val_restapi );
				$siteguard_config->set( self::OPT_NAME_EXCLUDE, $this->cvt_ret2camma( $opt_val_exclude ) );
				$siteguard_config->update();
				$opt_val_exclude = $this->cvt_camma2ret( $opt_val_exclude );
				?>
				<div class="updated"><p><strong><?php esc_html_e( 'Options saved.', 'siteguard' ); ?></strong></p></div>
				<?php
			}
		}

		echo '<div class="wrap">';
		echo '<img src="' . SITEGUARD_URL_PATH . 'images/sg_wp_plugin_logo_40.png" alt="SiteGuard Logo" />';
		echo '<h2>' . esc_html__( 'Block Author Query', 'siteguard' ) . '</h2>';
		$documentation_link = '<a href="' . esc_url( __( 'https://www.jp-secure.com/siteguard_wp_plugin_en/howto/author_query/', 'siteguard' ) ) . '" target="_blank">' . esc_html__( 'online documentation', 'siteguard' ) . '</a>';
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
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Option', 'siteguard' ); ?></th>
					<td>
						<input type="checkbox" name="<?php echo self::OPT_NAME_RESTAPI; ?>" id="<?php echo self::OPT_NAME_RESTAPI; ?>" value="1" <?php checked( $opt_val_restapi, '1' ); ?> >
						<label for="<?php echo self::OPT_NAME_RESTAPI; ?>"><?php esc_html_e( 'Disable REST API', 'siteguard' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo self::OPT_NAME_EXCLUDE; ?>"><?php echo esc_html_e( 'Excluded REST API Namespaces', 'siteguard' ); ?></label></th>
					<td>
						<textarea name="<?php echo self::OPT_NAME_EXCLUDE; ?>" id="<?php echo self::OPT_NAME_EXCLUDE; ?>" class="siteguard-box-300" cols=40 rows=10 ><?php echo esc_textarea( $opt_val_exclude ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Specify REST API namespaces to exclude. Enter one per line. Existing plugin-based entries are still supported.', 'siteguard' ); ?></p></br>
						<script>
						function add_value() {
							const crlf = String.fromCharCode(13) + String.fromCharCode(10);
							const namespaces = document.form1.namespaces;
							const excludes = document.form1.disable_restapi_exclude;
							const num = namespaces.selectedIndex;
							if (num < 0) {
								return;
							}
							const sel = namespaces.options[num];
							const str = sel.value;
							const sep = excludes.value.trim() === '' ? '' : crlf;
							excludes.value = excludes.value + sep + str;
							namespaces.removeChild(sel);
						}
						</script>
						<select name="namespaces" class="siteguard-box-300" size="15">
						<?php
							$val_excludes = explode( "\r\n", $opt_val_exclude );
							$namespaces   = $this->get_rest_api_namespaces();
							if ( ! empty( $namespaces ) ) {
								foreach ( $namespaces as $namespace ) {
									if ( ! $this->is_rest_api_namespace_excluded( $namespace, $val_excludes ) ) {
										?>
										<option value="<?php echo esc_attr( $namespace ); ?>"><?php echo esc_html( $namespace ); ?></option>
										<?php
									}
								}
							}
						?>
						</select>
						<input type="button" value="<?php esc_attr_e( 'Add Excluded Namespace', 'siteguard' ); ?>" onclick="add_value()" />
						<p class="description"><?php esc_html_e( 'This list shows REST API namespaces registered on this site. Select a namespace and add it to the exclusion list.', 'siteguard' ); ?></p></br>
					</td>
				</tr>
			</table>
		<input type="hidden" name="update" value="Y">
		<div class="siteguard-description">
		<?php
		$author_query_example = '<code>' . esc_html( '/?author=123' ) . '</code>';
		printf(
			/* translators: %1$s: Example author query. */
			esc_html__( 'Prevents username leakage through author queries such as %1$s. You can also disable the REST API to prevent username leakage through REST API requests. If disabling the REST API causes compatibility issues, add the affected REST API namespaces to the exclusion list.', 'siteguard' ),
			$author_query_example
		);
		?>
		</div>
		<hr />
		<?php
		wp_nonce_field( 'siteguard-menu-block-author-query-submit' );
		submit_button();
		?>
		</form>
		</div>
		<?php
	}
}
