<?php

if (!defined('ABSPATH')) die('Access denied.');

if (!class_exists('Updraft_Task_1_2')) require_once(WPO_PLUGIN_MAIN_PATH . 'vendor/team-updraft/common-libs/src/updraft-tasks/class-updraft-task.php');

if (!class_exists('WPO_Webp_Convert_Image_Task')) :

class WPO_Webp_Convert_Image_Task extends Updraft_Task_1_2 {

	/**
	 * Default options.
	 */
	public function get_default_options() {
		return array();
	}

	/**
	 * Run webp conversion for compressed images
	 *
	 * @return bool
	 */
	public function run() {
		$blog_id = $this->get_option('blog_id');
		$attachment_id = $this->get_option('attachment_id');
		$source = $this->get_option('attachment_source');

		if (is_multisite()) switch_to_blog($blog_id);
		$images = WPO_Image_Utils::get_attachment_files($attachment_id);
		$images['original'] = $source;

		foreach ($images as $image) {
			WPO_WebP_Utils::do_webp_conversion($image);
		}

		$destination = WPO_WebP_Utils::get_destination_path($source);
		if (file_exists($destination)) {
			update_post_meta($attachment_id, '_wpo-webp-conversion-complete', true);
		}

		if (is_multisite()) restore_current_blog();
		return true;
	}
}
endif;
