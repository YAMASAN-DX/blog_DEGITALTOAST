<?php if (!defined('ABSPATH')) die('No direct access allowed'); ?>

<h3><?php esc_html_e('Scheduled clean-up settings', 'wp-optimize'); ?></h3>
<p>
	<a href="<?php echo esc_url($settings_cleanup_data['data']['premium_version_link']); ?>&utm_content=take-control-of-cleanups" target="_blank"><?php esc_html_e('Take control of clean-ups: Upgrade to Premium for a more powerful and flexible scheduler', 'wp-optimize'); ?></a>
</p>

<div class="wpo-fieldgroup wpo-fieldgroup--auto-cleanup">

	<p>

		<input name="enable-schedule" id="enable-schedule" type="checkbox" value="true" <?php checked($settings_cleanup_data['data']['enable_schedule'], 'true'); ?>>
		<label for="enable-schedule"><?php esc_html_e('Enable scheduled clean-up and optimization', 'wp-optimize'); ?></label>

	</p>

	<div id="wp-optimize-auto-options">

		<p>

			<?php esc_html_e('Select schedule type (default is Weekly)', 'wp-optimize'); ?><br>
			<select id="schedule_type" name="schedule_type" class="wpo-select2">

				<?php

				foreach ($settings_cleanup_data['data']['schedule_options'] as $option) {
				?>
					<option value="<?php echo esc_attr($option['id']); ?>" <?php selected($option['selected']); ?>><?php echo esc_html($option['label']); ?></option>
				<?php
				}

				?>

			</select>

		</p>

		<?php

		foreach ($settings_cleanup_data['data']['optimizations'] as $optimization) {

			$auto_id = $optimization['id'];

			$auto_dom_id = $optimization['dom_id'];

			$setting_activated = $optimization['activated'];
		?>
			<p>
				<input name="wp-optimize-auto[<?php echo esc_attr($auto_id); ?>]" id="<?php echo esc_attr($auto_dom_id); ?>" type="checkbox" value="true" <?php checked($setting_activated); ?>> <label for="<?php echo esc_attr($auto_dom_id); ?>"><?php echo esc_html($optimization['label']); ?></label>
			</p>
		<?php
		}
		?>

		<!-- disabled email notification
		<p>
			<label>
					<input name="enable-email" id="enable-email" type="checkbox" value ="true"  />
											</label>
		</p>
		<p>
			<label for="enable-email-address">
												<input name="enable-email-address" id="enable-email-address" type="text" value ="" />
			</label>
		</p> -->

	</div><!-- END #wp-optimize-auto-options -->
</div><!-- END .wpo-fieldgroup -->