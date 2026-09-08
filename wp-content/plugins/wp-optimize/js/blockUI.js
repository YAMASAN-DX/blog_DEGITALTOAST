var wp_optimize = window.wp_optimize || {};

/**
 * Blocks UI
 *
 * @param {string} message Information to show in blockUI
 * @param {Object} options Display options
 * @param {integer} timeout (optional)
 * @param {boolean} is_popup_blocking_screen (optional) when is true then it is impossible to close the popup by clicking outside
 */
wp_optimize.block_ui = function (message, options, timeout, is_popup_blocking_screen) {

	var font_size = 'medium';
	if (typeof message === 'string') {
		if (message.length < 30) {
			font_size = 'large';
		} else if (message.length > 100) {
			font_size = 'small';
		}
	}

	var $ = jQuery,
	logo_src = (typeof wpoptimize !== 'undefined' && wpoptimize.logo_src) || (typeof wposmush !== 'undefined' && wposmush.logo_src),
		params = {
			css: {
				width: '300px',
				border: 'none',
				'border-radius': '10px',
				left: 'calc(50% - 150px)',
				padding: '20px'
			},
			message: '<div class="wp_optimize_blink_animation"><img src="'+logo_src+'" height="80" width="80"><div class="wp_optimize_blink_animation-message_text--'+font_size+'">'+message+'</div></div>'
		};

	options = options || {};

	if (timeout) {
		setTimeout(jQuery.unblockUI, timeout);
	} else if (!is_popup_blocking_screen) {
		options.onOverlayClick = jQuery.unblockUI;
	}

	params = $.extend(params, options);

	$.blockUI(params);
}
