<?php
if (!defined('ABSPATH')) {
	die('No direct access allowed');
}

/**
 * Adds compatibility for Polylang plugin.
 */
class WPO_Polylang_Compatibility {

	/**
	 * Instance of this class
	 *
	 * @var WPO_Polylang_Compatibility|null
	 */
	protected static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('wpo_single_post_cache_deleted', array($this, 'delete_post_cache_for_all_languages'));
		add_action('wpo_single_post_feed_cache_deleted', array($this, 'delete_post_feed_cache_for_all_languages'));
	}

	/**
	 * Returns singleton instance.
	 *
	 * @return WPO_Polylang_Compatibility
	 */
	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Gets translated post IDs excluding the original post.
	 *
	 * @param int $post_id The original post ID.
	 * @return array<string, int> List of translated post IDs, excluding the original.
	 */
	private function get_related_translations($post_id) {
		if (!function_exists('pll_get_post_translations')) {
			return array();
		}

		$translations = pll_get_post_translations($post_id);

		return array_filter($translations, function ($id) use ($post_id) {
			return $id !== $post_id;
		});
	}

	/**
	 * Deletes cache files for all connected languages for a post.
	 *
	 * @param int $deleted_post_id Post id whose cache file is already deleted.
	 * @return void
	 */
	public function delete_post_cache_for_all_languages($deleted_post_id) {
		$related_ids = $this->get_related_translations($deleted_post_id);

		foreach ($related_ids as $post_id) {
			WPO_Page_Cache::really_delete_single_post_cache($post_id);
		}

		if (!empty($related_ids)) {
			WPO_Page_Cache::instance()->file_log("Cache for associated Polylang translation posts for Title: {{title}} have been purged", $deleted_post_id);
		}
	}

	/**
	 * Deletes cache files for all connected languages for a post feed.
	 *
	 * @param int $deleted_post_id Post id whose cache file for feed is already deleted.
	 * @return void
	 */
	public function delete_post_feed_cache_for_all_languages($deleted_post_id) {
		$related_ids = $this->get_related_translations($deleted_post_id);

		foreach ($related_ids as $post_id) {
			WPO_Page_Cache::really_delete_post_feed_cache($post_id);
		}

		if (!empty($related_ids)) {
			WPO_Page_Cache::instance()->file_log("Cache for associated Polylang translation posts' feeds for Title: {{title}} have been purged", $deleted_post_id);
		}
	}
}
