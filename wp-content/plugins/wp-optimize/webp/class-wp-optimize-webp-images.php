<?php
if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WP_Optimize_WebP_Images')) :

class WP_Optimize_WebP_Images {

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action('delete_attachment', array($this, 'delete_related_images'));
	}

	/**
	 * Returns singleton instance
	 *
	 * @return WP_Optimize_WebP_Images
	 */
	public static function get_instance(): self {
		static $instance = null;
		if (null === $instance) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Deletes related image sizes and alternate WebP format images
	 *
	 * @param int $attachment_id
	 * @return void
	 */
	public function delete_related_images($attachment_id): void {
		$meta = wp_get_attachment_metadata($attachment_id);
		if (false === $meta) {
			return;
		}

		$file_path = get_attached_file($attachment_id);
		if (false === $file_path) {
			return;
		}
		$file_info = pathinfo($file_path);
		$directory = $this->get_upload_directory($file_info['basename'], $meta);
		$filename = $file_info['filename'];
		$original_extension = '.' . ($file_info['extension'] ?? '');
		$sizes = empty($meta['sizes']) ? array() : $meta['sizes'];

		$images = $this->build_image_paths(
			$directory,
			$filename,
			$original_extension,
			$sizes
		);

		$this->delete_files($images);
	}

	/**
	 * Computes the upload directory path for the attachment
	 *
	 * @param string                                                         $basename The original file basename
	 * @param array<string, int|string|array<string, array<string, string>>> $meta     The attachment metadata
	 * @return string
	 */
	private function get_upload_directory($basename, $meta): string {
		$file = isset($meta['file']) && is_string($meta['file']) ? $meta['file'] : '';
		$sub_directory = '';
		if ('' !== $file) {
			$sub_directory = str_replace($basename, '', $file);
		}

		$uploads = wp_get_upload_dir();
		return $uploads['basedir'] . '/' . $sub_directory;
	}

	/**
	 * Builds a list of image file paths to delete, including both
	 * original size variants and their WebP counterparts
	 *
	 * @param string                               $directory          Upload directory path
	 * @param string                               $filename           Image filename without extension
	 * @param string                               $original_extension Original file extension with leading dot
	 * @param array<string, array<string, string>> $sizes              Registered image size definitions
	 * @return array<string> List of absolute file paths
	 */
	private function build_image_paths($directory, $filename, $original_extension, $sizes) {
		$webp_extension = '.webp';
		$images = array(
			$directory . $filename . $original_extension . $webp_extension,
		);

		$unscaled_filename = preg_replace('/-scaled$/', '', $filename);

		foreach ($sizes as $size) {
			$size_suffix = '-' . $size['width'] . 'x' . $size['height'];
			$images[] = $directory . $filename . $size_suffix . $original_extension;
			$images[] = $directory . $unscaled_filename . $size_suffix . $original_extension . $webp_extension;
		}

		return $images;
	}

	/**
	 * Deletes the given list of files from disk
	 *
	 * @param array<string> $files List of absolute file paths to delete
	 * @return void
	 */
	private function delete_files($files): void {
		foreach ($files as $file) {
			wp_delete_file($file);
		}
	}
}

endif;
