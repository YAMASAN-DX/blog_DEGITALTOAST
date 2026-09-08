<?php
/*
This function based on Really Simple CAPTCHA 1.8.
modify matters
* add Hiragana ( Japanese ) CAPTCHA
* add random line
* store answer files in a non-public directory

Base-Plugin Name: Really Simple CAPTCHA
Base-Plugin URI: http://contactform7.com/captcha/
Base-Description: Really Simple CAPTCHA is a CAPTCHA module intended to be called from other plugins. It is originally created for my Contact Form 7 plugin.
Base-Author: Takayuki Miyoshi
Base-Version: 1.8
Base-Author URI: http://ideasilo.wordpress.com/
*/

/*
	Copyright 2007-2014 Takayuki Miyoshi

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.
*/

class SiteGuardReallySimpleCaptcha extends SiteGuard_Base {
	/* Mode of character set alphabet(en) or hiragana(jp) */
	protected $lang_mode;

	/* Length of a word in an image */
	protected $char_length;

	/* Directory for public CAPTCHA images (also stores answer files; see $ans_dir) */
	protected $tmp_dir;

	/* Directory for answer files. Same path as $tmp_dir; the files use the
	 * .php extension with a "<?php exit; ?>" prefix so HTTP requests return
	 * an empty response while PHP can still read the contents via the
	 * filesystem. This avoids depending on .htaccess (Apache-only) or
	 * sys_get_temp_dir() (unreliable on some shared/Nginx hosts). */
	protected $ans_dir;

	/* Array of CAPTCHA image size. Width and height */
	protected $img_size;

	/* Coordinates for a text in an image */
	protected $base;

	/* Font size */
	protected $font_size;

	/* Width of a character */
	protected $font_char_width;

	/* Image type. 'png', 'gif' or 'jpeg' */
	protected $img_type;

	/* Mode of temporary image files */
	protected $file_mode;

	/* Mode of temporary answer text files */
	protected $answer_file_mode;

	/* PHP stub file prefix written to answer files. When the file is
	 * requested over HTTP the PHP runtime processes the file, exits, and
	 * returns an empty response — preventing the hash from leaking. The
	 * plugin strips this prefix when reading the file via the filesystem. */
	const ANSWER_FILE_PREFIX = '<?php exit; ?>';

	/**
	 * Directory holding the CAPTCHA images and answer files.
	 *
	 * Static so that the capability check can ask for the path without building
	 * an instance, and so the path is written down in exactly one place.
	 */
	public static function get_tmp_dir() {
		return path_join( WP_CONTENT_DIR, 'siteguard' );
	}

	public function __construct() {
		$this->lang_mode        = 'jp';
		$this->char_length      = 4;
		$this->tmp_dir          = self::get_tmp_dir();
		$this->ans_dir          = $this->tmp_dir; // answer files live alongside images; see $ans_dir doc.
		$this->img_size         = array( 72, 24 );
		$this->base             = array( 6, 18 );
		$this->font_size        = 14;
		$this->font_char_width  = 15;
		$this->img_type         = 'png';
		$this->file_mode        = 0444;
		$this->answer_file_mode = 0440;
	}

		/**
		 * Generate and return a random word.
		 */
	public function generate_random_word() {
		$chars_en = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$chars_jp = 'あいうえおかきくけこさしすせそたちつてとなにのひふへまみむもやゆよらりん';

		$chars = ( 'jp' === $this->lang_mode ) ? $chars_jp : $chars_en;
		$word  = '';

		$chars_size = mb_strlen( $chars );
		for ( $i = 0; $i < $this->char_length; $i++ ) {
			$pos   = siteguard_rand( 0, $chars_size - 1 );
			$char  = mb_substr( $chars, $pos, 1 );
			$word .= $char;
		}

		return $word;
	}

		/**
		 * Generate CAPTCHA image and corresponding answer file.
		 *
		 * @return string|bool The image filename (e.g. 12345.png) or false on failure. //
		 */
	public function generate_image( $prefix, $word ) {
		// Same test as SiteGuard_CAPTCHA::is_image_rendering_available(), repeated
		// here so this file stays independent of the plugin classes. Callers are
		// expected to skip the CAPTCHA when the server cannot draw it; this makes
		// sure a caller that forgets gets `false` and a missing image rather than
		// a call to an undefined function and a 500 on the login page.
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) || ! function_exists( 'imagepng' ) ) {
			return false;
		}
		if ( ! $this->make_tmp_dir() ) {
			return false;
		}

			$this->cleanup();

			/* Array of fonts. Randomly picked up per character */
		if ( 'jp' == $this->lang_mode ) {
			$fonts = array(
				__DIR__ . '/mplus-TESTFLIGHT-058/mplus-1c-hiragana-black.ttf',
				__DIR__ . '/mplus-TESTFLIGHT-058/mplus-1m-hiragana-bold.ttf',
				__DIR__ . '/mplus-TESTFLIGHT-058/mplus-1mn-hiragana-light.ttf',
				__DIR__ . '/mplus-TESTFLIGHT-058/mplus-1p-hiragana-medium.ttf',
				__DIR__ . '/mplus-TESTFLIGHT-058/mplus-2c-hiragana-thin.ttf',
				__DIR__ . '/mplus-TESTFLIGHT-058/mplus-2m-hiragana-thin.ttf',
				__DIR__ . '/mplus-TESTFLIGHT-058/mplus-2p-hiragana-bold.ttf',
			);
		} else {
			$fonts = array(
				__DIR__ . '/gentium/GenBkBasR.ttf',
				__DIR__ . '/gentium/GenBkBasI.ttf',
				__DIR__ . '/gentium/GenBkBasBI.ttf',
				__DIR__ . '/gentium/GenBkBasB.ttf',
			);
		}

			$dir      = trailingslashit( $this->tmp_dir );
			$filename = null;

		if ( $im = imagecreatetruecolor( $this->img_size[0], $this->img_size[1] ) ) {
				$bg = imagecolorallocate( $im, 255, 255, 255 );
				$fg = imagecolorallocate( $im, 0, 0, 0 );
				imagefill( $im, 0, 0, $bg );

				// random lines
			for ( $i = 0; $i < 5; $i++ ) {
					$color = imagecolorallocate( $im, 196, 196, 196 );
					imageline(
						$im,
						siteguard_rand( 0, $this->img_size[0] - 1 ),
						siteguard_rand( 0, $this->img_size[1] - 1 ),
						siteguard_rand( 0, $this->img_size[0] - 1 ),
						siteguard_rand( 0, $this->img_size[1] - 1 ),
						$color
					);
			}

				$x = $this->base[0] + siteguard_rand( -2, 2 );

				$gd_info   = gd_info();
				$word_size = mb_strlen( $word );
			for ( $i = 0; $i < $word_size; $i++ ) {
					$font = $fonts[ array_rand( $fonts ) ];
					$font = $this->normalize_path( $font );
				if ( ! empty( $gd_info['JIS-mapped Japanese Font Support'] ) ) {
					$char = mb_convert_encoding( mb_substr( $word, $i, 1 ), 'SJIS', 'UTF-8' );
				} else {
					$char = mb_substr( $word, $i, 1 );
				}
				imagettftext( $im, $this->font_size, siteguard_rand( -12, 12 ), $x, $this->base[1] + siteguard_rand( -2, 2 ), $fg, $font, $char );
				$x += $this->font_char_width;
			}

				$written = false;
			switch ( $this->img_type ) {
				case 'jpeg':
						$filename = sanitize_file_name( $prefix . '.jpeg' );
						$file     = $this->normalize_path( $dir . $filename );
						$written  = imagejpeg( $im, $file );
					break;
				case 'gif':
						$filename = sanitize_file_name( $prefix . '.gif' );
						$file     = $this->normalize_path( $dir . $filename );
						$written  = imagegif( $im, $file );
					break;
				case 'png':
				default:
						$filename = sanitize_file_name( $prefix . '.png' );
						$file     = $this->normalize_path( $dir . $filename );
						$written  = imagepng( $im, $file );
			}

				imagedestroy( $im );
			// The return value used to be ignored, so a failed write still handed
			// the caller a filename and the form pointed at an image that was
			// never created.
			if ( ! $written ) {
				siteguard_error_log( 'failed to write image file (' . $file . '). :' . __FILE__ );
				return false;
			}
				@chmod( $file, $this->file_mode );
		}

			$this->generate_answer_file( $prefix, $word );

			return $filename;
	}

		/**
		 * Generate answer file corresponding to CAPTCHA image.
		 * Written as a .php file with a "<?php exit; ?>" prefix so HTTP
		 * requests for the file return an empty response.
		 */
	public function generate_answer_file( $prefix, $word ) {
		$dir         = trailingslashit( $this->ans_dir );
		$answer_file = $this->normalize_path( $dir . sanitize_file_name( $prefix . '.php' ) );

		if ( $fh = @fopen( $answer_file, 'w' ) ) {
			$word = strtoupper( $word );
			$salt = wp_generate_password( 64 );
			$hash = hash_hmac( 'md5', $word, $salt );
			fwrite( $fh, self::ANSWER_FILE_PREFIX . $salt . '|' . $hash );
			fclose( $fh );
			@chmod( $answer_file, $this->answer_file_mode );
		} else {
			siteguard_error_log( 'failed to open file (' . $answer_file . '). : ' . __FILE__ );
		}
	}

		/**
		 * Check a response against the code kept in the (private) answer file.
		 */
	public function check( $prefix, $response, $remove = false ) {
		if ( 0 == strlen( $prefix ) ) {
			return false;
		}

		$response = str_replace( array( ' ', "\t" ), '', $response );
		$response = strtoupper( $response );

		$dir  = trailingslashit( $this->ans_dir );
		$file = $this->normalize_path( $dir . sanitize_file_name( $prefix . '.php' ) );

		if ( @is_readable( $file ) && ( $code = file_get_contents( $file ) ) ) {
			if ( 0 === strpos( $code, self::ANSWER_FILE_PREFIX ) ) {
				$code = substr( $code, strlen( self::ANSWER_FILE_PREFIX ) );
			}
			$code = explode( '|', $code, 2 );

			if ( isset( $code[0], $code[1] ) ) {
				$salt = $code[0];
				$hash = $code[1];
				if ( hash_hmac( 'md5', $response, $salt ) == $hash ) {
					if ( $remove ) {
						$this->remove( $prefix );
					}
					return true;
				}
			}
		}

		if ( $remove ) { //
			$this->remove( $prefix );
		}
		return false;
	}

		/**
		 * Remove temporary files with given prefix.
		 * Images from public dir, answer file from private dir.
		 */
	public function remove( $prefix ) {
		// remove images
		$img_suffixes = array( '.jpeg', '.gif', '.png' );
		foreach ( $img_suffixes as $suffix ) {
			$dir      = trailingslashit( $this->tmp_dir );
			$filename = sanitize_file_name( $prefix . $suffix );
			$file     = $this->normalize_path( $dir . $filename );
			if ( @is_file( $file ) ) {
				@unlink( $file );
			}
		}

		// remove answer
		$dir  = trailingslashit( $this->ans_dir );
		$file = $this->normalize_path( $dir . sanitize_file_name( $prefix . '.php' ) );
		if ( @is_file( $file ) ) {
			@unlink( $file );
		}
	}

		/**
		 * Clean up dead files older than given minutes (images + answers).
		 */
	public function cleanup( $minutes = 60 ) {
		return $this->cleanup_dir(
			$this->tmp_dir,
			'/^[0-9]+\.(png|gif|jpeg|php)$/',
			$minutes
		);
	}

	private function cleanup_dir( $dir_base, $pattern, $minutes ) {
		$dir = trailingslashit( $dir_base );
		$dir = $this->normalize_path( $dir );

		if ( ! @is_dir( $dir ) || ! @is_readable( $dir ) ) {
			return 0;
		}

		$is_win = ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) );
		if ( ! ( $is_win ? win_is_writable( $dir ) : @is_writable( $dir ) ) ) {
			return 0;
		}

		$count = 0;
		if ( $handle = @opendir( $dir ) ) {
			while ( false !== ( $filename = readdir( $handle ) ) ) {
				if ( ! preg_match( $pattern, $filename ) ) {
					continue;
				}
				$file = $this->normalize_path( $dir . $filename );
				$stat = @stat( $file );
				if ( $stat && ( $stat['mtime'] + $minutes * 60 ) < time() ) {
					if ( ! @unlink( $file ) ) {
						@chmod( $file, 0644 );
						@unlink( $file );
					}
					$count += 1;
				}
			}
				closedir( $handle );
		}
			return $count;
	}

		/**
		 * Make the directory used for CAPTCHA images and answer files.
		 * Answer files protect themselves via a "<?php exit; ?>" prefix
		 * (see generate_answer_file), so this directory does not require
		 * .htaccess or restricted filesystem permissions.
		 */
	public function make_tmp_dir() {
		$dir = $this->normalize_path( trailingslashit( $this->tmp_dir ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			siteguard_error_log( 'failed to make directory (' . $dir . '). :' . __FILE__ );
			return false;
		}
		// wp_mkdir_p() reports success for a directory that already exists, whoever
		// owns it. Without this, a directory created by WP-CLI under another user
		// passed every check while the web server could not write a single file
		// into it -- the image and the answer file both silently failed and no
		// response could ever be accepted.
		if ( ! is_writable( $dir ) ) {
			siteguard_error_log( 'directory is not writable (' . $dir . '). :' . __FILE__ );
			return false;
		}
		// minimal index to avoid directory listing (harmless on Nginx with autoindex off)
		$index_file = $this->normalize_path( $dir . 'index.html' );
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, '' );
			@chmod( $index_file, 0444 );
		}

		// copy dummy image if missing
		$dmy_src_file = SITEGUARD_PATH . 'images/dummy.png';
		$dmy_dst_file = $dir . 'dummy.png';
		if ( ! file_exists( $dmy_dst_file ) ) {
			@copy( $dmy_src_file, $dmy_dst_file );
		}

		return true;
	}

		/**
		 * Normalize a filesystem path.
		 */
	private function normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '|/+|', '/', $path );
		return $path;
	}

		/**
		 * set $this->lang_mode
		 */
	public function set_lang_mode( $mode ) {
		if ( 'jp' === $mode || 'en' === $mode ) {
			$this->lang_mode = $mode;
		}
	}
}
