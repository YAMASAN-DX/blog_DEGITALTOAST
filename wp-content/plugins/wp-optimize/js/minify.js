 (function ($) {
	var wp_optimize = window.wp_optimize || {};
	var block_ui = wp_optimize.block_ui;
	var send_command = wp_optimize.send_command;
	var refresh_frequency = wpoptimize.refresh_frequency || 30000;
	var heartbeat = WP_Optimize_Heartbeat();
	var heartbeat_agents = [];

	if (!send_command) {
		console.error('WP-Optimize Minify: wp_optimize.send_command is required.');
		return;
	}

	var minify = {};

	/**
	 * Initializing the minify feature and events
	 */
	minify.init = function () {
		
		var minify = this;
		this.enabled = false;

		$(document).on('wp-optimize/minify/toggle-status', function(e, params) {
			if (params.hasOwnProperty('enabled')) {
				$('[data-whichpage="wpo_minify"]').toggleClass('is-enabled', params.enabled)
				minify.enabled = params.enabled;
				if (minify.enabled) minify.getFiles();
			}
		});

		/**
		 * The standard handler for clearing the cache. Safe to use
		 */
		$('.purge_minify_cache').on('click', function(e) {
			e.preventDefault();
			block_ui(wpoptimize.clearing_cache);
			send_command('purge_minify_cache', null, function(response) {
				if (response.hasOwnProperty('files')) {
					minify.updateFilesLists(response.files);
					minify.updateStats(response.files);
				}
			}).always(function() {
				$.unblockUI();
			});
		});

		/**
		 * Removes the entire cache dir.
		 * Use with caution, as cached html may still reference those files.
		 */
		$('.purge_all_minify_cache').on('click', function() {
			block_ui(wpoptimize.clearing_cache);
			send_command('purge_all_minify_cache', null, function(response) {
				if (response.hasOwnProperty('files')) {
					minify.updateFilesLists(response.files);
					minify.updateStats(response.files);
				}
			}).always(function() {
				$.unblockUI();
			});
		});

		/**
		 * Forces minify to create a new cache, safe to use
		 */
		$('.minify_increment_cache').on('click', function() {
			block_ui(wpoptimize.creating_cache);
			send_command('minify_increment_cache', null, function(response) {
				if (response.hasOwnProperty('files')) {
					minify.updateFilesLists(response.files);
					minify.updateStats(response.files);
				}
			}).always(function() {
				$.unblockUI();
			});
		});

		// ======= SLIDERS ========
		// Generic slider save
		$('#wp-optimize-nav-tab-wpo_minify-status-contents form :input, #wp-optimize-nav-tab-wpo_minify-js-contents form :input, #wp-optimize-nav-tab-wpo_minify-css-contents form :input, #wp-optimize-nav-tab-wpo_minify-font-contents form :input, #wp-optimize-nav-tab-wpo_minify-analytics-contents form :input, #wp-optimize-nav-tab-wpo_minify-settings-contents form :input, #wp-optimize-nav-tab-wpo_minify-advanced-contents form :input').on('change', function() {
			$(this).closest('form').data('need_saving', true);
		});
		
		$('input[type=checkbox].wpo-save-setting').on('change', function(e) {
			var input = $(this),
			val = input.prop('checked'),
			name = input.prop('name'),
			data = {};
			data[name] = val;
			block_ui(wpoptimize.saving);
			send_command('save_minify_settings', data, function(response) {
				if (response.success) {
					input.trigger('wp-optimize/minify/saved_setting');
					if (response.hasOwnProperty('files')) {
						minify.updateFilesLists(response.files);
						minify.updateStats(response.files);
					}
				} else {
					console.log('Settings not saved', data)
				}
			}).always(function() {
				$.unblockUI();
			});
		});

		// Slider enable minify
		$('#wpo_min_enable_minify').on('wp-optimize/minify/saved_setting', function() {
			this.enabled = $(this).prop('checked');
			$(document).trigger('wp-optimize/minify/toggle-status', {enabled: this.enabled});
		});
		
		// Toggle wpo-feature-is-disabled class
		$('#wpo_min_enable_minify').on('wp-optimize/minify/saved_setting', function() {
			
			$(this).closest('.wpo_section').toggleClass('wpo-feature-is-disabled', !$(this).is(':checked'));
		});
		
		// Toggle wpo-feature-is-disabled class
		$('#wpo_min_enable_minify_css, #wpo_min_enable_minify_js')
			// Update UI when setting is saved
			.on('wp-optimize/minify/saved_setting', function() {
				toggle_minify_tab_section($(this));
			})
			// Initialize UI on page load
			.each(function() {
				toggle_minify_tab_section($(this));
			});
		
		/**
		 * Toggle the UI state of a minify tab section based on the associated checkbox state.
		 *
		 * - For JS minification, only elements marked with `.show-if-enabled` are toggled.
		 *
		 * @param {jQuery} $el The checkbox element controlling the minified setting
		 *
		 * @return {void}
		 */
		function toggle_minify_tab_section($el) {
			var tab_name = $el.data('tabname');
			var tab_id = '#wpo_section_' + tab_name;
			var is_checked = $el.is(':checked');
			
			var $section = $el.is('#wpo_min_enable_minify_js') ? $(tab_id + ' .show-if-enabled') : $(tab_id);
			
			$('#wp-optimize-nav-tab-wrapper__wpo_minify a[data-tab="' + tab_name + '"] span.disabled').toggleClass('hidden', is_checked);
			
			$section.toggleClass('wpo-disabled-section', !is_checked);
		}
		
		// slider enable Debug mode
		$('#wpo_min_enable_minify_debug').on('wp-optimize/minify/saved_setting', function() {
			// Refresh the page as it's needed to show the extra options
			block_ui(wpoptimize.page_refresh);
			location.href = $('#wp-optimize-nav-tab-wpo_minify-advanced').prop('href');
		});

		// Edit default exclusions
		$('#wpo_min_edit_default_exclutions').on('wp-optimize/minify/saved_setting', function() {
			// Show exclusions section
			$('.wpo-minify-default-exclusions').toggleClass('hidden', !$(this).prop('checked'));
		});

		var disable_google_fonts_processing = $('#disable_google_fonts_processing');

		function handle_disable_google_fonts_processing() {
			$('#wp-optimize-nav-tab-wpo_minify-font-contents .google_fonts_option:not(.skip-disable-processing)').prop('disabled', disable_google_fonts_processing.is(':checked'));
		}

		disable_google_fonts_processing.on('change', handle_disable_google_fonts_processing);

		handle_disable_google_fonts_processing();

		// Save settings
		$('.wp-optimize-save-minify-settings').on('click', function(e) {
			e.preventDefault();
			var btn = $(this),
				spinner = btn.next(),
				success_icon = spinner.next(),
				$need_refresh_btn = null;
			
			spinner.show();
			block_ui(wpoptimize.saving);

			var data = {};
			
			var merge_state = {
				js: null,
				css: null
			};

			var tabs = $('[data-whichpage="wpo_minify"] .wp-optimize-nav-tab-contents form');
			tabs.each(function() {
				var tab = $(this);
				if (true === tab.data('need_saving')) {
					data = Object.assign(data, gather_data(tab));
					tab.data('need_saving', false);
					
					if (typeof data.enable_merging_of_js !== 'undefined') {
						merge_state.js = data.enable_merging_of_js === 'true';
					}
					
					if (typeof data.enable_merging_of_css !== 'undefined') {
						merge_state.css = data.enable_merging_of_css === 'true';
					}
				}
			});

			send_command('save_minify_settings', data, function(response) {
				if (response.hasOwnProperty('error')) {
					// show error
					console.log(response.error);
					wp_optimize.notices.show_notice(response.success, response.error);
				} else {
					$('.wpo-error__enabling-cache').addClass('wpo_hidden').find('p').text('');
				}
				
				if (response.hasOwnProperty('files')) {
					minify.updateFilesLists(response.files);
					minify.updateStats(response.files);
				}
				
				$.each(merge_state, function(type, enabled) {
					if (null !== enabled) {
						toggle_minify_notice(type, enabled, false);
					}
				});

				spinner.hide();
				success_icon.show();
				setTimeout(function() {
					success_icon.fadeOut('slow', function() {
						success_icon.hide();
					});
				}, 5000);
			}).always(function() {
				$.unblockUI();
			});
		})
		
		// Dismiss information notice
		$('.wpo_section_notice_minify').on('click', '.notice-dismiss', function(e) {
			e.preventDefault();
			toggle_minify_notice('minify', null, true);
		});
		
		$('.wpo_section_notice_js').on('click', '.notice-dismiss', function(e) {
			e.preventDefault();
			toggle_minify_notice('js', null, true);
		});
		
		$('.wpo_section_notice_css').on('click', '.notice-dismiss', function(e) {
			e.preventDefault();
			toggle_minify_notice('css', null, true);
		});
		
		/**
		 * Toggle visibility of a WP-Optimize minify notice and persist user preference.
		 *
		 * @param {string} type - Notice type ('minify', 'js', 'css').
		 * @param {boolean|null} merging_enabled  - Whether the JS/CSS merging checkbox is enabled (true = checked, false = unchecked, null = not applicable).
		 * @param {boolean} force_hide - Whether to hide forcefully (true) or (false).
		 *
		 * @returns {void}
		 */
		function toggle_minify_notice(type, merging_enabled, force_hide) {
			var $notice = $('.wpo_section_notice_' + type);
			if (!$notice.length) return;
			
			send_command('toggle_minify_notice', { type: type, merging_enabled: merging_enabled, force_hide: force_hide }, function(response) {
				if (response.hasOwnProperty('success') && response.success === true) {
					if (response.hasOwnProperty('hide') && response.hide === true) {
						$notice.stop(true, true).slideUp(300);
					}
					
					if (response.hasOwnProperty('hide') && response.hide === false) {
						$notice.stop(true, true).slideDown(300);
					}
				}
				if (response.hasOwnProperty('message') && response.success !== true) {
					wp_optimize.notices.show_notice('error', response.message);
				}
			});
		}

		// Show logs
		$('#wpo_min_jsprocessed, #wpo_min_cssprocessed').on('click', '.log', function(e) {
			e.preventDefault();
			$(this).nextAll('.wpo_min_log').slideToggle('fast');
			var link_text = $(this).text().trim();
			if (wpoptimize.show_information === link_text) {
				$(this).text(wpoptimize.hide_information);
			} else {
				$(this).text(wpoptimize.show_information);
			}
		});

		// Delete log file
		$('#wpo_min_jsprocessed, #wpo_min_cssprocessed').on('click', '.delete-file', function(e) {
			e.preventDefault();

			var filename = $(this).data('filename');
			
			if ('' === filename || undefined === filename) {
				alert(wpoptimize.cache_file_not_found);
				return false;
			}

			block_ui(wpoptimize.deleting);
			send_command('delete_minify_cache_file', filename, function(response) {
				if('' !== response.files) {
					minify.updateFilesLists(response.files);
					minify.updateStats(response.files);
				}
				if('' !== response.result) {
					alert(response.result);
				}
				if('' !== response.error) {
					alert(response.error);
				}
			}).always(function() {
				$.unblockUI();
			});
		});

		// Trigger combined meta.json file download
		$('.wpo-minify-download-metas-button').on('click', function(e) {
			e.preventDefault();
			send_command('get_minify_meta_files', null, function(response) {
				if (response.hasOwnProperty('error')) {
					// show error
					alert(response.error);
					return;
				}

				var link = document.body.appendChild(document.createElement('a'));
				link.setAttribute('download', 'combined_metas.json');
				link.setAttribute('style', "display:none;");
				link.setAttribute('href', 'data:text/json' + ';charset=UTF-8,' + encodeURIComponent(JSON.stringify(response.combined_metas)));
				link.click();
			})
		});

		// Handle js excludes
		$('#wpo_min_jsprocessed').on('click', '.exclude', function(e) {
			e.preventDefault();
			get_url_and_tab_saving($(this), '#exclude_js', 'js');
		});

		// Handle css excludes
		$('#wpo_min_cssprocessed').on('click', '.exclude', function(e) {
			e.preventDefault();
			get_url_and_tab_saving($(this), '#exclude_css', 'css');
		});

		// Handle defer
		$('#wpo_min_jsprocessed').on('click', '.defer', function(e) {
			e.preventDefault();
			get_url_and_tab_saving($(this), '#async_js', 'js');
		});

		// Handle async loading
		$('#wpo_min_cssprocessed').on('click', '.async', function(e) {
			e.preventDefault();
			get_url_and_tab_saving($(this), '#async_css', 'css');
		});
		
		/**
		 * Get url from an element and then save it
		 *
		 * @param {HTMLElement} el
		 * @param {string} target_el_id
		 * @param {string} tab_name
		 *
		 * @return void
		 */
		function get_url_and_tab_saving(el, target_el_id, tab_name) {
			var file_url = el.data('url');
			if ('' === file_url || undefined === file_url) {
				return;
			}
			
			add_url_to_next_line(target_el_id, file_url);
			tab_need_saving(tab_name);
			highlight_excluded_item(el);
		}
		
		/**
		 * Add url to the next line of the element textarea
		 *
		 * @param {string} target_el_id
		 * @param {string} file_url
		 *
		 * @return void
		 */
		function add_url_to_next_line(target_el_id, file_url) {
			var $textarea = $(target_el_id);
			var list_of_files = $textarea.val().trim();
			if ('' !== list_of_files) {
				list_of_files += '\n';
			}
			list_of_files += file_url;
			$textarea.val(list_of_files);
		}
		
		/**
		 *
		 * @param {string} tab_name Name of the tab that need saving
		 */
		function tab_need_saving(tab_name) {
			$('#wp-optimize-nav-tab-wpo_minify-' + tab_name + '-contents form').data('need_saving', true);
		}

		/**
		 * Update UI after excluding the file
		 *
		 * @param {HTMLElement} el Target element
		 */
		function highlight_excluded_item(el) {
			el.closest('.wpo_min_log').prev().removeClass('hidden').addClass('updated').slideDown();
			el.text(wpoptimize.added_to_list);
			el.removeClass('exclude');
			el.parent().addClass('disable-list-item');
			el.replaceWith($('<span>' + el.text() + '</span>'));
		}

		$('#wp-optimize-minify-advanced').on('click', '.save-exclusions', function(e) {
			e.preventDefault();
			$('.wp-optimize-save-minify-settings').first().trigger('click');
		});

		// Set the initial `enabled` value
		this.enabled = $('#wpo_min_enable_minify').prop('checked');
		$(document).trigger('wp-optimize/minify/toggle-status', {enabled: this.enabled});
		
		// When loading the page and minify is disabled, make sure that the status tab is active.
		if (!this.enabled && !$('#wp-optimize-nav-tab-wrapper__wpo_minify a[data-tab="status"]').is('.nav-tab-active')) {
			$('#wp-optimize-nav-tab-wrapper__wpo_minify a[data-tab="status"]').trigger('click');
		}

		// Enable / disable defer_jquery
		function check_defer_status( e ) {
			$('input[name="enable_defer_js"]').each(function(index, element) {
				$(element).closest('fieldset').removeClass('selected').find('.defer-js-settings').slideUp('fast');
			});
			$('input[name="enable_defer_js"]:checked').closest('fieldset').addClass('selected').find('.defer-js-settings').slideDown('fast');
		}

		$('input[name="enable_defer_js"]').on('change', check_defer_status);
		
		check_defer_status();

		/**
		 * Minify Preloader functionality
		 */
		var run_minify_preload_btn = $('#wp_optimize_run_minify_preload'),
			minify_preload_status_el = $('#wp_optimize_preload_minify_status');

		run_minify_preload_btn.on('click', function() {
			var btn = $(this),
				is_running = btn.data('running'),
				status = minify_preload_status_el.text();

			btn.prop('disabled', true);

			if (is_running) {
				btn.data('running', false);

				heartbeat.cancel_agents(heartbeat_agents);
				
				send_command(
					'cancel_minify_preload',
					null,
					function(response) {
						if (response && response.hasOwnProperty('message')) {
							minify_preload_status_el.text(response.message);
						}
					}
				).always(function() {
						btn.val(wpoptimize.run_now);
						btn.prop('disabled', false);
				});
			} else {
				minify_preload_status_el.text(wpoptimize.starting_preload);
				btn.data('running', true);
				send_command(
					'run_minify_preload',
					null,
					null,
					true,
					{
						timeout: 3000 // set a timeout in case the server doesn't support our close browser connection function.
					}
				).always(function(response) {
					try {
						var resp = wpo_parse_json(response);
					} catch (e) {
					}

					if (resp && resp.error) {

						var error_text = wpoptimize.error_unexpected_response;

						if (typeof resp.error != 'function') {
							error_text = resp.error;
						} else if (resp.status) {
							error_text = resp.status + ': ' + resp.statusText;
						}

						alert(error_text);

						minify_preload_status_el.text(status);
						btn.prop('disabled', false);
						btn.data('running', false);

						return;
					}

					minify_preload_status_el.text(wpoptimize.loading_urls);
					btn.val(wpoptimize.cancel);
					btn.prop('disabled', false);
					run_update_minify_preload_status();
				});
			}
		});

		/**
		 * If already running then update status
		 */
		if (run_minify_preload_btn.data('running')) {
			run_update_minify_preload_status();
		}

		/**
		 * Create heartbeat agent action for update preloader status.
		 *
		 * @return void
		 */
		function run_update_minify_preload_status() {
			var agent = heartbeat.add_agent({
				command: 'get_minify_preload_status',
				callback: update_minify_preload_status,
				_keep: false
			});

			if (null !== agent) heartbeat_agents.push(agent);
		}

		/**
		 * Update minify preload status ajax action.
		 *
		 * @return void
		 */
		function update_minify_preload_status(response) {
			if (response.done) {
				run_minify_preload_btn.val(wpoptimize.run_now);
				run_minify_preload_btn.data('running', false);
			} else {
				run_minify_preload_btn.val(wpoptimize.cancel);
				run_minify_preload_btn.data('running', true);
				run_update_minify_preload_status();
			}
			minify_preload_status_el.text(response.message);
			update_minify_size_information(response);
		}

		/**
		 * Run update information about minify size.
		 *
		 * @return void
		 */
		function update_minify_size_information(response) {
			$('#wpo_min_cache_size').text(response.size);
			$('#wpo_min_cache_total_size').text(response.total_size);
		}

		// switch for visibility (analytics setting tab).
		var $analytics = $('#wpo-analytics-hidden-content');

		$('#wpo_enable_analytics').on('change', function () {
			$analytics.css('display', $(this).prop('checked') ? 'block' : 'none');
		});

		return this;
	}

	/**
	 * Get the list of files generated by Minify and update the markup.
	 */
	minify.getFiles = function() {
		// Only run if the feature is enabled
		if (!this.enabled) return;

		var data = {
			stamp: new Date().getTime()
		};

		send_command('get_minify_cached_files', data, function(response) {
			minify.updateFilesLists(response);
			minify.updateStats(response);
		});

		if (refresh_frequency) setTimeout(minify.getFiles.bind(this), refresh_frequency);
	}

	minify.updateFilesLists = function(data) {
		if (null === data) return;
		// reset
		var wpominarr = [];

		// js
		if (data.js.length > 0) {
			$(data.js).each(function () {
				wpominarr.push(this.uid);
				if ($('#'+this.uid).length == 0) {
					$('#wpo_min_jsprocessed ul.processed').append('\
					<li id="'+this.uid+'">\
						<span class="filename"><a href="'+this.file_url+'" target="_blank">'+this.filename+'</a> ('+this.fsize+')</span>\
						<a href="#" class="log">' + wpoptimize.show_information + '</a>\
						<a href="#" class="delete-file" data-filename="' + this.filename + '">' + wpoptimize.delete_file + '</a>\
						<div class="hidden save_notice">\
							<p>' + wpoptimize.added_notice + '</p>\
							<p><button class="button button-primary save-exclusions">' + wpoptimize.save_notice + '</button></p>\
						</div>\
						<div class="hidden wpo_min_log">'+this.log+'</div>\
					</li>\
				');
				}
			});
		}

		$('#wpo_min_jsprocessed ul.processed .no-files-yet').toggle(!data.js.length);

		// css
		if (data.css.length > 0) {
			$(data.css).each(function () {
				wpominarr.push(this.uid);
				if ($('#'+this.uid).length == 0) {
					$('#wpo_min_cssprocessed ul.processed').append('\
					<li id="'+this.uid+'">\
						<span class="filename"><a href="'+this.file_url+'" target="_blank">'+this.filename+'</a> ('+this.fsize+')</span>\
						<a href="#" class="log">' + wpoptimize.show_information + '</a>\
						<a href="#" class="delete-file" data-filename="' + this.filename + '">' + wpoptimize.delete_file + '</a>\
						<div class="hidden save_notice">\
							<p>' + wpoptimize.added_to_list + '</p>\
							<p><button class="button button-primary save-exclusions">' + wpoptimize.save_notice + '</button></p>\
						</div>\
						<div class="hidden wpo_min_log">'+this.log+'</div>\
					</li>\
				');
				}
			});
		}
		$('#wpo_min_cssprocessed ul.processed .no-files-yet').toggle(!data.css.length);

		// Remove <li> if it's not in the files array
		$('#wpo_min_jsprocessed ul.processed > li, #wpo_min_cssprocessed ul.processed > li').each(function () {
			if (-1 == jQuery.inArray($(this).attr('id'), wpominarr)) {
				if (!$(this).is('.no-files-yet')) {
					$(this).remove();
				}
			}
		});
	};

	minify.updateStats = function(data) {
		if (data.cachesize.length > 0) {
			$("#wpo_min_cache_size").html(this.enabled ? data.cachesize : wpoptimize.no_minified_assets);
			$("#wpo_min_cache_total_size").html(this.enabled ? data.total_cache_size : wpoptimize.no_minified_assets);
			$("#wpo_min_cache_time").html(this.enabled ? data.cacheTime : '-');
			$("#wpo_min_cache_path").html(data.cachePath);
		}
	};

	 /**
	  *  Gather data from the given form
	  *
	  * @param {HTMLFormElement} form
	  *
	  * @returns {Array} Array of collected data from the form
	  */
	 function gather_data(form) {
		 var data = $(form).serializeArray().reduce(form_serialize_reduce_cb, {});
		 $(form).find('input[type="checkbox"]').each(function (i) {
			 var name = $(this).prop("name");
			 if (name.includes('[]')) {
				 if (!$(this).is(':checked')) return;
				 var newName = name.replace('[]', '');
				 if (!data[newName]) data[newName] = [];
				 data[newName].push($(this).val());
			 } else {
				 data[name] = $(this).is(':checked') ? 'true' : 'false';
			 }
		 });
		 return data;
	 }

	/**
	 * Reduces the form elements array into an object
	 *
	 * @param {Object} collection An empty object
	 * @param {*} item form input element as array element
	 *
	 * @returns {Object} collection An object of form data
	 */
	function form_serialize_reduce_cb(collection, item) {
		 // Ignore items containing [], which we expect to be returned as arrays
		 if (item.name.includes('[]')) return collection;
		 collection[item.name] = item.value;
		 return collection;
	 }

	 // Gather minify settings from all tabs
	 wp_optimize.minify_settings = function() {
		var tabs = $('[data-whichpage="wpo_minify"] .wp-optimize-nav-tab-contents form');
		var data = {}
		tabs.each(function() {
			var tab = $(this);
			data = Object.assign(data, gather_data(tab));
		});
		return data;
	}

	wp_optimize.minify = minify;

})(jQuery);