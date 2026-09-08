<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WPO_WebP_Self_Test')) :

class WPO_WebP_Self_Test {

	/**
	 * Relative path to the test image used for WebP serving verification.
	 *
	 * @var string
	 */
	const TEST_IMAGE_PATH = '/wpo/images/wpo_logo_small.png';

	/**
	 * The MIME type for WebP images, used as the Accept header value
	 * and for content-type verification.
	 *
	 * @var string
	 */
	const WEBP_MIME_TYPE = 'image/webp';

	/**
	 * Determines whether the content-type header indicates a WebP image.
	 *
	 * @param array<string, string> $headers An array of response headers
	 *
	 * @return bool
	 */
	private function has_webp_mime($headers): bool {
		return isset($headers['content-type']) && 0 === strcasecmp(self::WEBP_MIME_TYPE, $headers['content-type']);
	}

	/**
	 * Determines whether the Vary header includes Accept.
	 *
	 * @param array<string, string> $headers An array of response headers
	 *
	 * @return bool
	 */
	private function has_vary($headers): bool {
		return isset($headers['vary']) && preg_match('/accept/i', $headers['vary']);
	}

	/**
	 * Determines whether a WebP version is served when requested with
	 * an appropriate Accept header.
	 *
	 * @return bool
	 */
	public function is_webp_served(): bool {
		$args = array(
			'headers' => array(
				'accept' => self::WEBP_MIME_TYPE
			)
		);

		$upload_dir = wp_upload_dir();
		$url = $upload_dir['baseurl'] . self::TEST_IMAGE_PATH;

		$response = wp_remote_head($url, $args);

		if (is_wp_error($response) || 200 !== $response['response']['code']) {
			return false;
		}

		$headers = wp_remote_retrieve_headers($response);
		if (is_object($headers) && method_exists($headers, 'getAll')) {
			$headers = $headers->getAll();
			return $this->has_webp_mime($headers) && $this->has_vary($headers);
		}
		return false;
	}

	/**
	 * Returns singleton instance
	 *
	 * @return WPO_WebP_Self_Test
	 */
	public static function get_instance(): self {
		static $_instance = null;
		if (null === $_instance) {
			$_instance = new self();
		}
		return $_instance;
	}
}

endif;
