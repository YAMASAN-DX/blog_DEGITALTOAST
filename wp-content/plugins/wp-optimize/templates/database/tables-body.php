<?php if (!defined('ABSPATH')) die('No direct access allowed'); ?>

<tbody id="the-list">
<?php
	
	foreach ($table_list_object_format as $index => $tablestatus) {
		printf('<tr data-tablename="%1$s" data-type="%2$s" data-optimizable="%3$s" data-blog_id="%4$s">',
			esc_attr($tablestatus->Name),
			esc_attr($tablestatus->Engine),
			$tablestatus->is_optimizable ? 1 : 0,
			$is_multisite_mode ? esc_attr($tablestatus->blog_id) : '');
		printf('<td data-colname="%1$s">%2$s</td>', esc_attr__('No.', 'wp-optimize'), esc_html(number_format_i18n($table_list[$index]['index'])));
		printf('<td data-tablename="%1$s" data-colname="%2$s">%3$s', esc_attr($tablestatus->Name), esc_attr__('Table', 'wp-optimize'), esc_html($tablestatus->Name));

		if (!empty($tablestatus->plugin_status)) {
			if ($tablestatus->wp_core_table) {
				printf('<br><span>%s</span> ', esc_html__('Belongs to:', 'wp-optimize'));
				printf('<span>%s</span>', esc_html__('WordPress core', 'wp-optimize'));
			} elseif (false !== stripos($tablestatus->Name, 'actionscheduler_')) {
				printf('<br><span>%s</span> ', esc_html__('This table is used by many plugins for batch processing.', 'wp-optimize'));
				printf('<span>%s</span>', esc_html__('Thus, it cannot be deleted.', 'wp-optimize'));
			} else {
				echo '<div class="table-plugins">';
				printf('<span>%s</span> ', esc_html__('Known plugins that use this table name:', 'wp-optimize'));
				foreach ($tablestatus->plugin_status as $plugins_status) {
					$plugin = $plugins_status['plugin'];
					$status = $plugins_status['status'];

					echo '<br>';
					
					if (in_array($plugin, $closed_plugins)) {
					  continue;
					}

					if ('sfwd-lms' === $plugin) {
						$wp_optimize->wp_optimize_url('https://www.learndash.com/', '', '<span>LearnDash</span>');
					} else {
						$wp_optimize->wp_optimize_url('https://wordpress.org/plugins/'.$plugin.'/', '', '<span>'.esc_html($plugin).'</span>');
					}

					if (false === $status['installed']) {
						printf(' <span class="status">[%s]</span>', esc_html__('not installed', 'wp-optimize'));
					} elseif (false === $status['active']) {
						printf(' <span class="status">[%s]</span>', esc_html__('inactive', 'wp-optimize'));
					}
				}
				echo '</div>';
			}
		}

		echo "</td>\n";

		printf('<td data-colname="%1$s" data-sort="%2$s">%3$s</td>', esc_attr__('Records', 'wp-optimize'), esc_attr(intval($tablestatus->Rows)), esc_html(number_format_i18n($tablestatus->Rows)));
		printf('<td data-colname="%1$s" data-sort="%2$s">%3$s</td>', esc_attr__('Data Size', 'wp-optimize'), esc_attr(intval($tablestatus->Data_length)), esc_html($wp_optimize->format_size($tablestatus->Data_length)));
		printf('<td data-colname="%1$s" data-sort="%2$s">%3$s</td>', esc_attr__('Index Size', 'wp-optimize'), esc_attr(intval($tablestatus->Index_length)), esc_html($wp_optimize->format_size($tablestatus->Index_length)));

		if ($tablestatus->is_optimizable) {
			printf('<td data-colname="%1$s" data-optimizable="1">%2$s</td>', esc_attr__('Type', 'wp-optimize'), esc_html($tablestatus->Engine));
			printf('<td data-colname="%1$s" data-sort="%2$s">', esc_attr__('Overhead', 'wp-optimize'), esc_attr(intval($tablestatus->Data_free)));
			$font_colour = ($optimize_db ? (($tablestatus->Data_free > $small_overhead_size) ? '#0000FF' : '#004600') : (($tablestatus->Data_free > $small_overhead_size) ? '#9B0000' : '#004600'));
			printf('<span style="color:%1$s;">%2$s</span>', esc_attr($font_colour), esc_html($wp_optimize->format_size($tablestatus->Data_free)));
			echo "</td>\n";
		} else {
			printf('<td data-colname="%1$s" data-optimizable="0">%2$s</td>', esc_attr__('Type', 'wp-optimize'), esc_html($tablestatus->Engine));
			printf('<td data-colname="%s">', esc_attr__('Overhead', 'wp-optimize'));
			?>
			<span style="color:#0000FF;">-</span>
			</td>
			<?php
		}

		printf('<td data-colname="%1$s">%2$s</td>', esc_attr__('Actions', 'wp-optimize'), wp_kses_post(apply_filters('wpo_tables_list_additional_column_data', '', $tablestatus)));

		echo "</tr>\n";
	}
?>
</tbody>
<tfoot>
	<tr class="thead">
		<th><?php esc_html_e('Total:', 'wp-optimize'); ?></th>
		<th>
		<?php
			// translators: %s is the number of tables
			echo esc_html(sprintf(_n('%s Table', '%s Tables', $no, 'wp-optimize'), $no));
		?>
		</th>
		<th><?php echo esc_html($row_usage); ?></th>
		<th><?php echo esc_html($data_usage); ?></th>
		<th><?php echo esc_html($index_usage); ?></th>
		<th>-</th>
		<th>
			<?php $font_colour = (($optimize_db) ? (($overhead_usage > $small_overhead_size) ? '#0000FF' : '#004600') : (($overhead_usage > $small_overhead_size) ? '#9B0000' : '#004600')); ?>
			<span style="color:<?php echo esc_attr($font_colour); ?>"><?php echo esc_html($overhead_usage_formatted); ?></span>
		</th>
		<th><?php esc_html_e('Actions', 'wp-optimize'); ?></th>
	</tr>
</tfoot>
