<?php if (!defined('ABSPATH')) die('No direct access allowed'); ?>

<header class="wpo-main-header">
	<p class="wpo-header-links">
		<span class="wpo-header-links__label"><?php esc_html_e('Useful links', 'wp-optimize'); ?></span>
		<?php $wp_optimize->wp_optimize_url('https://teamupdraft.com/wp-optimize/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=home&utm_creative_format=menu', __('Home', 'wp-optimize')); ?> |

		<?php $wp_optimize->wp_optimize_url('https://teamupdraft.com/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=teamupdraft&utm_creative_format=menu', 'TeamUpdraft'); ?> |
		
		<?php $wp_optimize->wp_optimize_url('https://teamupdraft.com/blog/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=blog&utm_creative_format=menu', __('Blogs', 'wp-optimize')); ?> |

		<?php $wp_optimize->wp_optimize_url('https://x.com/TeamUpdraftWP', __('Twitter / X', 'wp-optimize')); ?> |

		<?php $wp_optimize->wp_optimize_url('https://teamupdraft.com/support/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=support&utm_creative_format=menu', __('Support', 'wp-optimize')); ?> |

		<?php $wp_optimize->wp_optimize_url('https://david.dw-perspective.org.uk', __("Team lead", 'wp-optimize')); ?> |
		
		<?php $wp_optimize->wp_optimize_url('https://teamupdraft.com/documentation/wp-optimize/?utm_source=wpo-plugin&utm_medium=referral&utm_campaign=paac&utm_content=faqs&utm_creative_format=menu', __("Documentation", 'wp-optimize')); ?> |

		<?php $wp_optimize->wp_optimize_url('https://www.simbahosting.co.uk/s3/shop/', __("More plugins", 'wp-optimize')); ?>
	</p>

	<div class="wpo-logo__container">
		<img class="wpo-logo" src="<?php echo esc_url(trailingslashit(WPO_PLUGIN_URL) . 'images/notices/wp_optimize_logo.png'); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- N/A ?>" alt="" />
		<?php
			$sqlversion = (string) $wp_optimize->get_db_info()->get_version();
			echo '<strong>WP-Optimize '.(WP_Optimize::is_premium() ? esc_html__('Premium', 'wp-optimize') : '' ).' <span class="wpo-version">'.esc_html(WPO_VERSION).'</span></strong>';
		?>
		<span class="wpo-subheader"><?php esc_html_e('Make your site fast & efficient', 'wp-optimize'); ?></span>
	</div>
	<?php
	$wp_optimize->include_template('pages-menu.php', false, array('menu_items' => WP_Optimize()->get_admin_instance()->get_submenu_items()));
	?>
</header>
<?php
	if ($show_notices) {
		
		$installed = $wp_optimize->get_options()->get_option('installed-for', 0);
		$installed_for = time() - $installed;
		$advert = false;
		if ($installed && $installed_for > 28*86400 && $installed_for < 84*86400) {
			$advert = 'rate_plugin';
		}

		if ($installed && $installed_for > 14*86400) {
			// This is to display the notices.
			$wp_optimize_notices->do_notice($advert);
		}
	}
