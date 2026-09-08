<?php

	if (!defined('ABSPATH')) die('No direct access allowed');

?>

<h3 class="wpo-first-child"><?php esc_html_e('Status', 'wp-optimize'); ?></h3>

<div class="wpo-fieldgroup" id="wp_optimize_status_box">
	<p>
	<?php
	if ('Never' !== $last_optimized) {
		// translators: %s is the last scheduled optimization date.
		printf(esc_html__('Last scheduled optimization was at %s', 'wp-optimize'), '<span style="color: #004600; font-weight:bold;">' . esc_html($last_optimized) . '</span>');
	} else {
		echo esc_html__('There was no scheduled optimization', 'wp-optimize');
	}
	?>
		<br>

	<?php

	if ($scheduled_optimizations_enabled) {
		printf(
			// translators: 1: Opening strong tag, 2: Opening color span tag, 3: Closing color span tag, 4: Closing strong tag
			esc_html__('%1$sScheduled cleaning %2$senabled%3$s%4$s', 'wp-optimize'),
			'<strong>',
			'<span style="color: #009B24;">',
			'</span>',
			'</strong>'
		);
		echo '&nbsp;';
		
		if ($next_optimization_timestamp) {

			$date = new DateTime("@".$next_optimization_timestamp);
			esc_html_e('Next schedule:', 'wp-optimize');
			echo ' ';
			echo '<span style="font-color: #004600">';
			echo esc_html(gmdate(get_option('date_format') . ' ' . get_option('time_format'), $next_optimization_timestamp));
			echo '</span>';
			echo ' - <a id="wp_optimize_status_box_refresh" href="#">'.esc_html__('Refresh', 'wp-optimize').'</a>';
		}
	} else {
		printf(
			// translators: 1: Opening strong tag, 2: Closing strong tag
			esc_html__('%1$sScheduled cleaning disabled%2$s', 'wp-optimize'),
			'<strong>',
			'</strong>'
		);
	}
	echo '<br>';

	if ('true' === $retention_enabled) {
		echo '<strong><span style="font-color: #0000FF;">';
		// translators: %s is number of weeks
		printf(esc_html__('Keeping last %s weeks data', 'wp-optimize'), esc_html($retention_period));
		echo '</span></strong>';
	} else {
		echo '<strong>'.esc_html__('Not keeping recent data', 'wp-optimize').'</strong>';
	}
	
	echo '<br>';

	if ('true' === $revisions_retention_enabled) {
		echo '<strong><span style="font-color: #0000FF;">';
		// translators: %s is number of revisions
		printf(esc_html__('Keeping last %s revisions', 'wp-optimize'), esc_html($revisions_retention_count));
		echo '</span></strong>';
	} else {
		echo '<strong>'.esc_html__('Not keeping any revisions', 'wp-optimize').'</strong>';
	}
	?>
	</p>

	<p>
	<?php
		$total_cleaned_num = floatval($total_cleaned);

		if ($total_cleaned_num > 0) {
			printf(
				// translators: 1: Opening h5 tag, 2: Formatted size in colored span, 3: Closing h5 tag
				esc_html__('%1$sTotal clean up overall: %2$s%3$s', 'wp-optimize'),
				'<h5>',
				'<span style="color: #004600;">' . esc_html($total_cleanup_size) . '</span>',
				'</h5>'
			);
		}
	?>
	</p>

	<?php

	if ($corrupted_tables_count > 0) {
	?>
	<p>
		<span style="color: #E07575;">
		<?php
			echo esc_html(
				sprintf(
					// translators: %s: Number of corrupted tables
					_n(
						'Your database has %s corrupted table.',
						'Your database has %s corrupted tables.',
						$corrupted_tables_count,
						'wp-optimize'
					),
					$corrupted_tables_count
				)
			);
		?>
		</span><br>
		<a href="<?php echo esc_url($admin_page_url); ?>&tab=wp_optimize_tables" onclick="jQuery('.wpo-pages-menu > a').first().trigger('click'); jQuery('#wp-optimize-nav-tab-wpo_database-tables').trigger('click'); return false;"><?php esc_html_e('Repair corrupted tables here.', 'wp-optimize'); ?></a>
	</p>
	<?php } ?>
</div>
