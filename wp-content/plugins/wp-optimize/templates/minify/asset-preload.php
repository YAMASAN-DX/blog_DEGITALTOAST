<?php if (!defined('ABSPATH')) die('No direct access allowed'); ?>
<h3><?php esc_html_e('Preload key requests / assets', 'wp-optimize'); ?></h3>
<div class="wpo-fieldgroup">
	<p class="wpo_min-bold-green wpo_min-rowintro">
		<?php esc_html_e('Preload critical assets to improve loading speed.', 'wp-optimize'); ?>
		<?php $wp_optimize->wp_optimize_url('https://teamupdraft.com/documentation/wp-optimize/topics/minification/faqs/how-do-i-pre-load-critical-assets/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=learn-about-preloading-key-requests&utm_creative_format=text', __('Learn more about preloading key requests.', 'wp-optimize')); ?>
	</p>
	<fieldset>
		<legend class="screen-reader-text">
		<?php esc_html_e('Preload key requests', 'wp-optimize'); ?>
		</legend>
		<p><strong><?php esc_html_e('Preload key requests is a premium feature.', 'wp-optimize'); ?></strong> <a href="<?php echo esc_url(WP_Optimize()->premium_version_link); ?>&utm_content=preload-key-requests"><?php esc_html_e('Find out more here.', 'wp-optimize'); ?></a></p>
	</fieldset>
</div>
