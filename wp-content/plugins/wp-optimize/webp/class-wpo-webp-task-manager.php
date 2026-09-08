<?php

if (!defined('ABSPATH')) die('Access denied.');

if (!class_exists('Updraft_Task_Manager_1_4')) require_once(WPO_PLUGIN_MAIN_PATH . 'vendor/team-updraft/common-libs/src/updraft-tasks/class-updraft-task-manager.php');

if (!class_exists('WPO_Webp_Task_Manager')) :

class WPO_Webp_Task_Manager extends Updraft_Task_Manager_1_4 {

	/**
	 * Maximum number of images to collect per batch.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Logs a message using the WebP optimization instance.
	 *
	 * @param string $message    The message to log.
	 * @param string $error_type Optional. The type of error. Default 'info'.
	 * @return void
	 */
	public function log($message, $error_type = 'info'): void {
		$webp_instance = WP_Optimize()->get_webp_instance();
		$webp_instance->log($message, $error_type);
	}

	/**
	 * Ensures singleton instance
	 */
	private function __construct() {
		parent::__construct();
	}

	/**
	 * Instance of WP_Optimize_Webp_Task_Manager.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $_instance = null;
		if (null === $_instance) {
			$_instance = new self();
		}
		return $_instance;
	}

	/**
	 * Convert already compressed images to webp format
	 *
	 * @return void
	 */
	public function webp_convert_compressed_images(): void {
		$task_type = 'webp-convert-compressed-images-task';

		$creating_tasks_semaphore = new Updraft_Semaphore_3_0('wpo_' . $task_type);
		$lock = $creating_tasks_semaphore->lock();
		if (!$lock) return;

		if (is_multisite()) {
			$sites = WP_Optimize()->get_sites();
			foreach ($sites as $site) {
				$blog_id = $site->blog_id;
				switch_to_blog($blog_id);
				$this->create_webp_convert_compressed_image_task($task_type, $blog_id);
				restore_current_blog();
			}
		} else {
			$this->create_webp_convert_compressed_image_task($task_type, 1);
		}

		$creating_tasks_semaphore->release();

		$this->process_queue($task_type);
	}

	/**
	 * Creates tasks for converting compressed images to WebP format
	 *
	 * @param string $task_type The type identifier for the conversion task
	 * @param int    $blog_id   The ID of the blog/site where the images should be converted
	 *
	 * @return void
	 */
	private function create_webp_convert_compressed_image_task(string $task_type, int $blog_id) {
		$this->clean_up_old_tasks($task_type);
		$images = $this->get_compressed_images_to_convert();
		$images = $this->exclude_gifs($images);
		foreach ($images as $image) {
			$description = sprintf(
				'Webp Conversion of Compressed Image with ID - %d, Blog ID : %d',
				$image['post_id'],
				$blog_id
			);
			$options = array(
				'attachment_id' => $image['post_id'],
				'blog_id' => $blog_id,
				'attachment_source' => $image['source'],
				'anonymous_user_allowed' => wp_doing_cron() || (defined('WP_CLI') && WP_CLI)
			);
			WPO_Webp_Convert_Image_Task::create_task($task_type, $description, $options);
		}
	}

	/**
	 * Get compressed images to convert
	 *
	 * @return array<array<string, int|string>> Array of arrays containing
	 * 'post_id' (int) The ID of the attachment post, and
	 * 'source' (string) The file path of the attachment
	 */
	private function get_compressed_images_to_convert(): array {
		$args = array(
			'meta_query' => $this->get_compressed_images_meta_query(),
			'post_type' => 'attachment',
			'numberposts' => self::BATCH_SIZE,
		);

		$page = 1;
		$collected_count = 0;
		$filtered_post_ids = array();

		while ($collected_count < self::BATCH_SIZE) {
			$args['paged'] = $page;
			$query = get_posts($args);

			if (empty($query)) {
				break;
			}

			foreach ($query as $post) {
				if ($collected_count >= self::BATCH_SIZE) {
					break 2;
				}

				$source = get_attached_file($post->ID);
				if (false === $source) {
					continue;
				}

				$destination = WPO_WebP_Utils::get_destination_path($source);
				if (file_exists($destination)) {
					$this->backfill_webp_conversion_meta($post->ID);
					continue;
				}

				$filtered_post_ids[] = array(
					'post_id' => $post->ID,
					'source'  => $source
				);
				$collected_count++;
			}
			$page++;
		}

		return $filtered_post_ids;
	}

	/**
	 * Backfill the webp conversion meta for attachments that already have
	 * a WebP file present at the destination path.
	 *
	 * @param int $post_id The attachment post ID.
	 * @return void
	 */
	private function backfill_webp_conversion_meta($post_id): void {
		update_post_meta($post_id, '_wpo-webp-conversion-complete', true);
	}

	/**
	 * Meta query array for getting compressed images not yet converted to WebP
	 *
	 * @return array<int|string, array<int|string, array<string, string>|string>|string>
	 */
	private function get_compressed_images_meta_query(): array {
		return array(
			'relation' => 'AND',
			array(
				'key'     => '_wpo-smush-complete',
				'compare' => '=',
				'value'   => '1',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => '_wpo-webp-conversion-complete',
					'compare' => 'NOT EXISTS',
					'value'   => '',
				),
				array(
					'key'     => '_wpo-webp-conversion-complete',
					'compare' => '!=',
					'value'   => '1',
				)
			)
		);
	}

	/**
	 * Exclude GIF files from the array
	 *
	 * @param array<array<string, int|string>> $images An array of arrays containing:
	 *                                                 'post_id' (int) The ID of the attachment post
	 *                                                 'source' (string) The file path of the attachment
	 *
	 * @return array<array<string, int|string>>
	 */
	private function exclude_gifs(array $images): array {
		$allowed_extensions = array_diff(WPO_Image_Utils::get_allowed_extensions(), array('gif'));
		return array_values(array_filter($images, function($image) use ($allowed_extensions) {
			/** @var string $source */
			$source = $image['source'];
			$ext = WPO_Image_Utils::get_extension($source);
			return in_array($ext, $allowed_extensions, true);
		}));
	}
}
endif;
