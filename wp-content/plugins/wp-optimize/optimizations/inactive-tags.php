<?php

if (!defined('ABSPATH')) die('No direct access allowed');

class WP_Optimization_tags extends WP_Optimization {

	public $available_for_auto = false;

	public $auto_default = false;

	public $ui_sort_order = 12000;

	public function optimize() {
	
	}
	public function get_info() {

	}

	/**
	 * Returns settings label
	 *
	 * @return string
	 */
	public function settings_label() {
		return __('Remove unused tags', 'wp-optimize');
	}

	/**
	 * Return description
	 *
	 * @return string
	 */
	public function get_auto_option_description() {
		return __('Removes unused tags', 'wp-optimize');
	}
}
