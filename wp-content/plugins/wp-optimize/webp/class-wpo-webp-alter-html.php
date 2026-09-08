<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('WPO_WebP_Alter_HTML')) :

class WPO_WebP_Alter_HTML {

	/**
	 * HTML tags that may contain image-related attributes eligible for WebP replacement.
	 */
	const SUPPORTED_TAGS = array('img', 'source', 'input', 'iframe', 'div', 'li', 'link', 'a', 'section', 'video');

	/**
	 * Regex pattern to identify image-related attribute names such as src, srcset,
	 * poster, and common data-* attributes used for lazy loading or responsive images.
	 */
	const IMAGE_ATTRIBUTE_PATTERN = '#^(src|srcset|poster|(data-[^=]*(lazy|small|slide|img|large|src|thumb|source|set|bg-url)[^=]*))$#i';

	/**
	 * Constructor — private to enforce singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Returns singleton instance.
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
	 * Alter HTML to replace image-related attributes with WebP URLs where available.
	 *
	 * @param string $html HTML document as string.
	 * @return string
	 */
	public function alter_html(string $html): string {
		if (apply_filters('wpo_disable_webp_alter_html', false)) {
			return $html;
		}

		// MAX_FILE_SIZE is defined in simple_html_dom — ensure it has a safe default.
		WP_Optimize_Utils::define_maxfile_size_constant();

		$dom = WP_Optimize_Utils::get_simple_html_dom_object($html);

		if (false === $dom) {
			return $this->get_skipped_html($html);
		}

		$this->replace_image_attributes($dom);

		return $dom->save();
	}

	/**
	 * Walks all supported tags in the DOM and replaces image attribute values
	 * with their WebP counterparts when available.
	 *
	 * @param simplehtmldom\HtmlDocument $dom A simple_html_dom instance.
	 *
	 * @return void
	 */
	private function replace_image_attributes($dom): void {
		foreach (self::SUPPORTED_TAGS as $tag) {
			foreach ($dom->find($tag) as $elem) {
				$attributes = $elem->getAllAttributes();
				foreach ($attributes as $attr_name => $attr_value) {
					if ($this->is_image_attribute($attr_name)) {
						$elem->setAttribute($attr_name, $this->handle_attribute($attr_value));
					}
				}
			}
		}
	}

	/**
	 * Returns the original HTML annotated with a comment explaining why
	 * the WebP alteration was skipped.
	 *
	 * @param string $html The original HTML document.
	 * @return string
	 */
	private function get_skipped_html($html): string {
		if (strlen($html) > MAX_FILE_SIZE) {
			return $html . "\n" . "<!-- Alter HTML was skipped because the HTML is too big to process! " .
				"(limit is set to " . MAX_FILE_SIZE . " bytes) -->";
		}
		return $html . "\n" . "<!-- Alter HTML was skipped because the helper library refused to process the html -->";
	}

	/**
	 * Returns the URL with a .webp extension appended if a WebP version exists on disk.
	 *
	 * @param string $url URL of the original image.
	 * @return string
	 */
	private function maybe_replace_url($url): string {
		if ($this->is_webp_version_available($url)) {
			$url .= '.webp';
		}
		return $url;
	}

	/**
	 * Processes a srcset attribute value, replacing each image URL with its
	 * WebP counterpart when available.
	 *
	 * A srcset value is a comma-separated list of entries such as
	 * "image.jpg 520w" or "image.jpg 2x" or just "image.jpg".
	 *
	 * @param string $attr_value The raw srcset attribute value.
	 * @return string
	 */
	private function handle_srcset($attr_value): string {
		// $attr_value is ie: <img data-x="1.jpg 1000w, 2.jpg">
		$srcset_arr = explode(',', $attr_value);
		foreach ($srcset_arr as $i => $srcset_entry) {
			// $srcset_entry is ie "image.jpg 520w", but can also lack width, ie just "image.jpg"
			// it can also be ie "image.jpg 2x"
			$srcset_entry = trim($srcset_entry);
			$entry_parts = preg_split('/\s+/', $srcset_entry, 2);

			if (is_array($entry_parts) && 2 === count($entry_parts)) {
				list($src, $descriptors) = $entry_parts;
			} else {
				$src = $srcset_entry;
				$descriptors = null;
			}

			$url = $this->maybe_replace_url($src);
			$srcset_arr[$i] = $url . (isset($descriptors) ? ' ' . $descriptors : '');
		}
		return implode(', ', $srcset_arr);
	}

	/**
	 * Determines whether an attribute value resembles a srcset declaration
	 * (contains a width or pixel-density descriptor).
	 *
	 * @param string $value The attribute value to inspect.
	 * @return bool
	 */
	private function looks_like_srcset($value): bool {
		return (bool) preg_match('#\s\d*(w|x)#', $value);
	}

	/**
	 * Dispatches attribute value handling based on whether it looks like a
	 * srcset or a plain image source URL.
	 *
	 * @param string $value The attribute value.
	 * @return string
	 */
	private function handle_attribute($value): string {
		if ($this->looks_like_srcset($value)) {
			return $this->handle_srcset($value);
		}
		return $this->maybe_replace_url($value);
	}

	/**
	 * Determines whether an attribute name is image-related and should be
	 * considered for WebP replacement.
	 *
	 * @param string $attr_name The attribute name.
	 * @return bool
	 */
	private function is_image_attribute($attr_name): bool {
		return (bool) preg_match(self::IMAGE_ATTRIBUTE_PATTERN, $attr_name);
	}

	/**
	 * Checks whether a WebP version of the given image URL exists on disk.
	 *
	 * @param string $url The original image URL.
	 * @return bool
	 */
	private function is_webp_version_available($url): bool {
		$filename = WP_Optimize_Utils::get_file_path($url);
		if (empty($filename)) {
			return false;
		}
		return file_exists($filename . '.webp');
	}
}

endif;
