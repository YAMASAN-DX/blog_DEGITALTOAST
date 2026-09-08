<?php

namespace TeamUpdraft\WP_Optimize\Includes\Fragments;

if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * Return the value of a member of a superglobal, after slash-stripping and sanitisation.
 *
 * When using, it is recommended that if the $type or $sanitisation parameters are not used then a code comment is added to state the reason.
 *
 * If the specified key is not found, or the resulting value does not match the expected type, the default value is returned instead.
 * An error is logged if the type does not match, but no exception is thrown.
 *
 * Previously this function could throw a TypeError when the fetched value did not match the expected type.
 * This behavior has been removed in favor of returning the $default and logging the error, to improve fault tolerance.
 *
 * @param String		$superglobal  - should be one of 'get', 'post', 'request', 'cookie' or 'server'; case insensitive
 * @param String		$key		  - the key to fetch from the superglobal array
 * @param String|Null	$type		  - If specified, must match gettype() on the returned value; otherwise, $default is returned and an error is logged.
 * @param Callable|Null $sanitisation - the sanitisation function to run the result through (any function), with the first parameter being the putative value. Any $default value will not be sanitised, which allows different cases to be distinguished as described above.
 * @param Mixed			$default	  - value to return if the key is not found or type mismatched
 *
 * @return Mixed
 *
 * @see https://developer.wordpress.org/apis/security/sanitizing/
 * @see https://www.php.net/manual/en/function.gettype.php
 */
function fetch_superglobal($superglobal, $key, $type = null, $sanitisation = null, $default = null) {

	$superglobal = '_'.strtoupper($superglobal);

	// N.B. Superglobals can only be dereferenced by variable variables in the global scope; this is why we have to use $GLOBALS
	if (!is_array($GLOBALS[$superglobal]) || !isset($GLOBALS[$superglobal][$key])) {
		$putative_return = $default;
	} else {
		$putative_return = stripslashes_deep($GLOBALS[$superglobal][$key]);
		if (null !== $sanitisation) {
			try {
				$putative_return = call_user_func($sanitisation, $putative_return);
			} catch (\Throwable $e) {
				$backtrace_summary = \WP_Optimize_Utils::get_backtrace_summary();
				error_log(sprintf('WP-Optimize: fetch_superglobal() failed due to: "%s" [%s]', $e->getMessage(), $backtrace_summary));// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Using for debugging purpose
				return $default;
			}
		}
	}

	if (null !== $type && strtolower(gettype($putative_return)) !== strtolower($type)) {
		$backtrace_summary = \WP_Optimize_Utils::get_backtrace_summary();
		error_log(sprintf('WP-Optimize: fetch_superglobal() failed due to type mismatch - expected "%s", received "%s" [%s]', $type, gettype($putative_return), $backtrace_summary)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Using for debugging purpose
		return $default;
	}
	
	return $putative_return;
}

/**
 * Used to verify a nonce
 *
 * @param string     $name   Nonce name
 * @param string|int $action Nonce action
 *
 * @return bool
 */
function verify_nonce($name, $action = -1) {
	$name = fetch_superglobal('request', $name, null, 'sanitize_key');
	if (null === $name) {
		return false;
	}
	if (function_exists('wp_verify_nonce')) {
		if (wp_verify_nonce($name, $action)) {
			return true;
		}
	}

	$backtrace_summary = \WP_Optimize_Utils::get_backtrace_summary();
	error_log(sprintf('WP-Optimize: verify_nonce() failed for action "%s" [%s]', $action, $backtrace_summary)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Using for debugging purpose

	return false;
}
