'use strict';

class ClickFunctions {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('*[data-click-target]').length ) {
			this.events();
        } else {
            return;
        }
	}

	events() {

		$('*[data-click-target]').click(function(event) {
			event.preventDefault();
			//event.stopProgagation();
			var el = $(this);

            var trigger = el.attr('data-click-target'),
                bc = el.attr('data-click-bodyClass'),
                oc = el.attr('data-click-class'),
                target = $("#" + trigger);

			if( el.hasClass('js-header-item') ){
				$('.menu-mask').attr('data-click-target', trigger);
			} else if(el.hasClass('form-terms')) {
				$('.terms-screen').attr('data-click-target', trigger);
			} else {
				$('.filter-mask').attr('data-click-target', trigger);
			}

            if( target.hasClass('is-active') ) {
                target.removeClass('is-active');
				$('body').removeClass(bc);
            } else {
               target.addClass('is-active');
			   $('body').addClass(bc);
            }
        });

	}

}

module.exports = ClickFunctions;
