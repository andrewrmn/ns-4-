'use strict';

class Tabs {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('.tabs').length ) {
			this.setup();
			this.events();
        } else {
            return;
        }
	}

	setup() {
		//any general setup code (ex. getting window width) can go here.
		// console.log('Tabs intialized');
	}

	events() {

		var tab = $('.tabs .tab__bd'),
        	activeTab = $('.tabs .is-active .tab__bd');

        function findActiveTab() {
            tab.each( function() {
                var tabParent = $(this).parent();
                if( tabParent.hasClass('is-active') ) {
                    $(this).slideDown();
                } else {
                    $(this).slideUp();
                }
            });
        }

        $(document).ready(function() {
            findActiveTab();
        });

        $('*[data-click-group]').on('click touchstart:not(touchmove)', function() {
            findActiveTab();
        });

	}

}

module.exports = Tabs;
