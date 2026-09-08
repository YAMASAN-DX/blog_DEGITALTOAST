<?php
if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * This is a small glue class, which makes available all the commands in WP_Optimize_Commands, and translates the response from WP_Optimize_Commands (which is either data to return, or a WP_Error) into the format used by UpdraftCentral.
 */
class UpdraftCentral_WP_Optimize_Commands extends UpdraftCentral_Commands {
	
	private $commands;

	private $wp_optimize_updraftcentral_commands;

	/**
	 * Class constructor
	 */
	public function __construct() {
		$this->commands = new WP_Optimize_Commands();
		$this->wp_optimize_updraftcentral_commands = new WP_Optimize_UpdraftCentral_Commands();
	}

	/**
	 * Magic method to pass on the command to WP_Optimize_Commands
	 *
	 * @param string $name		- command name
	 * @param array	 $arguments	- command parameters
	 *
	 * @return array - response
	 */
	public function __call($name, $arguments) {
	
		if (is_callable(array($this->wp_optimize_updraftcentral_commands, $name))) {
			$this->commands = $this->wp_optimize_updraftcentral_commands;
		} elseif (!is_callable(array($this->commands, $name))) {
			return $this->_generic_error_response('wp_optimize_no_such_command', $name);
		}
		
		if (count($arguments) > 0) {
			$arguments[0] = $arguments[0] ?? array();
		}

		$result = call_user_func_array(array($this->commands, $name), $arguments);
		
		if (is_wp_error($result)) {
			return $this->_generic_error_response($result->get_error_code(), $result->get_error_data());
		} else {
			return $this->_response($result);
		}
	}
}
