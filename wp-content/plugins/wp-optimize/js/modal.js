/*
How to use the modal

wp_optimize.modal.open({
	className: 'a-class', // A class name, added to the main modal container
	events: {}, // An object containing the events added to the modal. See Backbonejs View events syntax.
	content: function() {
		return ''; // the Content method returns html or jQuery objects which will be added to the content area of the modal
	},
	... // Other methods used by the custom events
})
*/

var wp_optimize = window.wp_optimize || {};

// WordPress v4.9 uses Backbone v1.3.3, which does not support the preinitialize() hook.
// Therefore, we are adding a patch to enable support for the preinitialize() hook.
(function() {
	var OriginalView = Backbone.View;
	Backbone.View = function(options) {
		if (typeof this.preinitialize === 'function') {
		this.preinitialize.apply(this, arguments);
		}
		return OriginalView.apply(this, arguments);
	};
	Backbone.View.prototype = OriginalView.prototype;
	Backbone.View.extend = OriginalView.extend;
})();

(function($, wp) {
	'use strict';
	var modal = {};
	modal.views = {};

	/**
	 * Main modal View
	 */
	modal.views.modal = Backbone.View.extend({
		tagName: 'div',
		template: wp.template('wpo-modal'),
		/**
		 * Extend default values
		 */
		preinitialize: function() {
			this.events = _.extend(this.events || {}, {
				'click .wpo-modal--close': 'close'
			});
			this.className = this.className ? 'wpo-modal--container ' + this.className : 'wpo-modal--container ';
		},
		render: function() {
			this.$el.append(this.template());
			this.trigger('rendered');
		},
		initialize: function() {
			this.trigger('initialize');
			this.render();
			this.$content = this.$el.find('.wpo-modal--content');
			// Append the content area with the content provided by the child object
			if ('function' === typeof this.content) {
				this.$content.append(this.content());
			}
		},
		close: function() {
			$('body').removeClass('wpo-modal-is-opened');
			this.remove();
		}
	});

	/**
	 * Public method to create and open the modal
	 */
	modal.open = function(options) {
		var view_options = _.extend(options || {}, {});
		var modalView = modal.views.modal.extend(view_options);
		var m = new modalView();
		m.$el.appendTo('body');
		m.$('.wpo-modal').focus();
		$('body').addClass('wpo-modal-is-opened');
		return m;
	}

	wp_optimize.modal = modal;
})(jQuery, window.wp);