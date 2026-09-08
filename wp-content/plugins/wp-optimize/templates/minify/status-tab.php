<?php if (!defined('ABSPATH')) die('No direct access allowed'); ?>
<div id="wp-optimize-minify-status" class="wpo_section wpo_group<?php echo (!$wpo_minify_options['enabled']) ? ' wpo-feature-is-disabled' : ''; ?>">
	<form>
		<div class="wpo-info wpo-show">
			<a class="wpo-info__trigger" href="#"><span class="dashicons dashicons-sos"></span> <?php esc_html_e('How to use the minify feature', 'wp-optimize'); ?> <span class="wpo-info__close"><?php esc_html_e('Close', 'wp-optimize'); ?></span></a>
			<div class="wpo-info__content">
				<p><strong><?php esc_html_e('Not sure how to use the Minify feature?', 'wp-optimize'); ?></strong> <br><?php esc_html_e('Watch our how to video below.', 'wp-optimize'); ?></p>
				<div class="wpo-video-preview">
					<a href="https://vimeo.com/1015123805" data-embed="https://player.vimeo.com/video/1015123805?color=df6926&title=0&byline=0&portrait=0" target="_blank"><img src="<?php echo esc_url(trailingslashit(WPO_PLUGIN_URL) . 'images/notices/video-thumb.jpg'); ?>" alt="<?php esc_attr_e('Minify video preview', 'wp-optimize');?>"></a>
				</div>
				<small>(<?php esc_html_e('Loads a video hosted on vimeo.com', 'wp-optimize'); ?>) - <?php $wp_optimize->wp_optimize_url('https://vimeo.com/1015123805', __('Open the video in a new window', 'wp-optimize')); ?></small>
				<p><a target="_blank" href="<?php echo esc_url(WP_Optimize()->maybe_add_affiliate_params('https://teamupdraft.com/documentation/wp-optimize/topics/minification/faqs/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=read-the-documentation-minification&utm_creative_format=text')); ?>"><?php esc_html_e('Read the documentation', 'wp-optimize'); ?></a></p>
			</div>
		</div>
		<div id="wpo_settings_warnings"></div>
		<?php if ($show_information_notice) : ?>
			<div class="notice notice-warning wpo-warning is-dismissible below-h2 wpo_section_notice_minify wpo-show">
				<p>
					<span class="dashicons dashicons-shield"></span>
					<strong><?php esc_html_e('CSS, JavaScript and HTML minification is an advanced feature.', 'wp-optimize'); ?></strong><br>
					<?php echo esc_html_x('While enabling it will work just fine for most sites, it might need specific configuration to work properly on your website.', '"it" refers to the Minify feature.', 'wp-optimize'); ?><br>
					<?php echo esc_html_x('If you encounter an issue and are not sure what to do, disable the feature and ask for help on the support forum.', '"it" refers to the Minify feature.', 'wp-optimize'); ?>
					<?php echo esc_html_x('We will do our best to help you configure it.', '"it" refers to the Minify feature.', 'wp-optimize'); ?>
					<a target="_blank" href="<?php echo esc_url(WP_Optimize()->maybe_add_affiliate_params('https://teamupdraft.com/documentation/wp-optimize/topics/minification/faqs/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=read-the-documentation-minification&utm_creative_format=notice')); ?>"><?php esc_html_e('Read the documentation', 'wp-optimize'); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<div class="wpo-fieldgroup wpo-show">
			<div class="switch-container">
				<label class="switch">
					<input
						name="enabled"
						id="wpo_min_enable_minify"
						class="wpo-save-setting"
						type="checkbox"
						value="true"
						<?php checked($wpo_minify_options['enabled']); ?>
					>
					<span class="slider round"></span>
				</label>
				<label for="wpo_min_enable_minify">
					<?php
						esc_html_e('Enable Minify', 'wp-optimize');
					?>
				</label>
			</div>

			<p><?php esc_html_e('If this is turned on, then the default settings are that JavaScript and CSS on this website will be concatenated and minified and HTML will be minified.', 'wp-optimize'); ?> <?php esc_html_e('You can adjust the settings in the tabs above to control this to meet your requirements.', 'wp-optimize'); ?></p>

			<?php if (!empty($active_minify_plugins)) : ?>
				<div class="notice notice-error">
					<p>
						<?php
							// translators: %s - list of active minify plugins
							$message = sprintf(esc_html__('It looks like you already have an active minify plugin (%s) installed.', 'wp-optimize'), implode(', ', $active_minify_plugins));
							$message .= ' ';
							$message .= esc_html__('Having more than one active plugin to minify front end assets might cause unexpected results and waste of resources.', 'wp-optimize');
							echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped	-- Output already escaped
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) : ?>
				<div class="notice notice-warning">
					<p>
						<span class="dashicons dashicons-info"></span>
						<?php
							echo ' ';
							// translators: %s is the constant name SCRIPT_DEBUG
							printf(esc_html__('The constant %s is set to true, so no JavaScript or CSS file will be minified.', 'wp-optimize'), '<code>SCRIPT_DEBUG</code>');
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="wpo-minify-features">

				<div class="wpo-fieldgroup__subgroup">
					<div class="switch-container">
						<label class="switch">
							<input
								name="html_minification"
								id="wpo_min_enable_minify_html"
								class="wpo-save-setting"
								type="checkbox"
								value="true"
								<?php checked($wpo_minify_options['html_minification']);?>
							>
							<span class="slider round"></span>
						</label>
						<label for="wpo_min_enable_minify_html">
							<?php esc_html_e('Process HTML (works only when cache pre-loading)', 'wp-optimize'); ?>
							<?php // Note: the comment added by WPO regarding caching will not be removed (it's added later in the process) ?>
							<?php
								$message = __('All HTML will be minified.', 'wp-optimize');
								$message .= ' ';
								$message .= __('It takes effect only when cache pre-loading because it takes time.', 'wp-optimize');
							?>
						</label>
						<span tabindex="0" class="wpo-tooltip" data-tooltip="<?php echo esc_attr($message);?>"><span class="dashicons dashicons-editor-help"></span> </span>
					</div>
				</div>

				<div class="wpo-fieldgroup__subgroup">
					<div class="switch-container">
						<label class="switch">
							<input
								name="enable_js"
								id="wpo_min_enable_minify_js"
								class="wpo-save-setting"
								type="checkbox"
								value="true"
								data-tabname="js"
								<?php checked($wpo_minify_options['enable_js']);?>
							>
							<span class="slider round"></span>
						</label>
						<label for="wpo_min_enable_minify_js">
							<?php esc_html_e('Process JavaScript files', 'wp-optimize'); ?>
						</label>
						<span tabindex="0" class="wpo-tooltip" data-tooltip="<?php esc_attr_e('The JavaScript files will be combined and minified to lower the number and size of requests.', 'wp-optimize');?>"><span class="dashicons dashicons-editor-help"></span> </span>
						<a href="#" class="js--wpo-goto" data-tab="js"><?php esc_html_e('Settings', 'wp-optimize'); ?></a>
					</div>
				</div>

				<div class="wpo-fieldgroup__subgroup">
					<div class="switch-container">
						<label class="switch">
							<input
								name="enable_css"
								id="wpo_min_enable_minify_css"
								class="wpo-save-setting"
								type="checkbox"
								value="true"
								data-tabname="css"
								<?php checked($wpo_minify_options['enable_css']);?>
							>
							<span class="slider round"></span>
						</label>
						<label for="wpo_min_enable_minify_css">
							<?php esc_html_e('Process CSS files', 'wp-optimize'); ?>
						</label>
						<span tabindex="0" class="wpo-tooltip" data-tooltip="<?php esc_attr_e('The stylesheets will be combined and minified to lower the number and size of requests.', 'wp-optimize');?>"><span class="dashicons dashicons-editor-help"></span> </span>
						<a href="#" class="js--wpo-goto" data-tab="css"><?php esc_html_e('Settings', 'wp-optimize'); ?></a>
					</div>
				</div>			
			</div>
		</div>

		<div class="wpo-fieldgroup">
			<div class="wpo-save-btn-container actions">
				<input
					class="button button-primary purge_minify_cache <?php echo $can_purge_the_cache ? '' : 'disabled'; ?>"
					type="submit"
					value="<?php wp_optimize_minify_config()->always_purge_everything() ? esc_attr_e('Purge the minified files', 'wp-optimize') : esc_attr_e('Reset the minified files', 'wp-optimize'); ?>"
					<?php echo $can_purge_the_cache ? '' : 'disabled'; ?>
				>
				<img class="wpo_spinner" src="<?php echo esc_url(admin_url('images/spinner-2x.gif')); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- N/A ?>" alt="...">
				<span class="save-done dashicons dashicons-yes display-none"></span>
			</div>
			<p>
				<span><?php esc_html_e("The new minified files will be regenerated when visiting your website's pages.", "wp-optimize"); ?> <?php $wp_optimize->wp_optimize_url('https://teamupdraft.com/documentation/wp-optimize/topics/minification/faqs/what-does-reset-the-minified-files-do/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=what-does-purge-the-minified-files-do&utm_creative_format=text', __('Read more about what this does in our FAQs.', 'wp-optimize')); ?> (<?php esc_html_e('This will also purge the page cache', 'wp-optimize'); ?>)</span>
			</p>

			<?php esc_html_e('Minify cache size:', 'wp-optimize'); ?>
			<ul class="ul-disc">
				<li><?php esc_html_e('Current cache:', 'wp-optimize'); ?>
					<strong id="wpo_min_cache_size">
						<?php
							if ($wpo_minify_options['enabled']) {
								echo esc_html(WP_Optimize_Minify_Cache_Functions::get_cachestats($cache_dir));
							} else {
								esc_html_e('No minified files are present', 'wp-optimize');
							}
						?>
					</strong>
					<a href="#" class="js--wpo-goto" data-tab="advanced"><?php esc_html_e('View the files', 'wp-optimize'); ?></a>
				</li>
				<li>
					<?php esc_html_e('Total cache:', 'wp-optimize'); ?>
					<strong id="wpo_min_cache_total_size">
						<?php
							if ($wpo_minify_options['enabled']) {
								echo esc_html(WP_Optimize_Minify_Cache_Functions::get_cachestats(WPO_CACHE_MIN_FILES_DIR));
							} else {
								esc_html_e('No minified files are present', 'wp-optimize');
							}
						?>
					</strong>
					<strong tabindex="0" class="wpo-tooltip" data-tooltip="<?php esc_attr_e('This includes the older, non-expired cache, as well as the temporary files used to generate the minified files.', 'wp-optimize');?>"><span class="dashicons dashicons-editor-help"></span></strong>
					<a href="#" class="wpo-minify-download-metas-button"><?php esc_html_e('Download debug log', 'wp-optimize'); ?></a>
				</li>
			</ul>
			<p>
				<?php esc_html_e('Last Minify cache update:', 'wp-optimize'); ?>
				<strong id="wpo_min_cache_time">
					<?php
					if (empty($wpo_minify_options['last-cache-update'])) {
						esc_html_e('Never.', 'wp-optimize');
					} elseif (!$wpo_minify_options['enabled']) {
						echo '-';
					} else {
						echo esc_html(WP_Optimize_Minify_Cache_Functions::format_date_time($wpo_minify_options['last-cache-update']));
					}
					?>
				</strong>
			</p>
			<?php if ($wpo_minify_options['debug']) : ?>
				<div class="wpo-save-btn-container actions">
					<input
						class="button minify_increment_cache"
						type="button"
						value="<?php esc_attr_e('Increment cache', 'wp-optimize'); ?>"
					>
					<img class="wpo_spinner" src="<?php echo esc_url(admin_url('images/spinner-2x.gif')); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- N/A ?>" alt="...">
					<span class="save-done dashicons dashicons-yes display-none"></span>
					<span tabindex="0" class="wpo-tooltip" data-tooltip="<?php esc_attr_e('This will reset the files generated by minify, but use the existing minify temporary files.', 'wp-optimize');?>" style="display: inline-block;"><span class="dashicons dashicons-editor-help"></span></span>
					<strong tabindex="0" data-tooltip="<?php esc_attr_e('This will reset the files generated by minify, but use the existing minify temporary files.', 'wp-optimize');?>"><span class="dashicons dashicons-editor-help"></span></strong>
				</div>
				<?php
					// This is only necessary if the everything isn't purged
					if (!wp_optimize_minify_config()->always_purge_everything()) :
				?>
					<div class="wpo-save-btn-container actions">
						<input
							class="button purge_all_minify_cache"
							type="button"
							value="<?php esc_attr_e('Delete all the files generated by minifcation', 'wp-optimize'); ?>"
						>
						<img class="wpo_spinner" src="<?php echo esc_url(admin_url('images/spinner-2x.gif')); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- N/A ?>" alt="...">
						<span class="save-done dashicons dashicons-yes display-none"></span>
						<span tabindex="0" class="wpo-tooltip" data-tooltip="<?php esc_attr_e('If you are using an unsupported cache plugin, then you will also need to purge your page cache when doing this.', 'wp-optimize');?>" style="display: inline-block;"><span class="dashicons dashicons-editor-help"></span></span>
						<strong tabindex="0" data-tooltip="<?php esc_attr_e('If you are using an unsupported cache plugin, then you will also need to purge your page cache when doing this.', 'wp-optimize');?>"><span class="dashicons dashicons-editor-help"></span></strong>
					</div>
				<?php
					endif;
				?>
			<?php endif; ?>
		</div>
	</form>
</div><!-- end #wp-optimize-minify-status -->
