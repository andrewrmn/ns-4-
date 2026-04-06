'use strict';

class Test {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		this.setup();
		this.events();
	}

	setup() {
        //any general setup code (ex. getting window width) can go here.
        console.log('Initializing');

	}

	events() {

        console.log('Ready to party');

	}

}

module.exports = Test;
