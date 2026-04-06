'use strict';

class Acids {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('.acids').length ) {
			this.setup();
			this.events();
        } else {
            return;
        }
	}

	setup() {
        //any general setup code (ex. getting window width) can go here.
        //console.log('Initializing');

	}

	events() {
        //console.log('Ready to party');

		//const c = require('../vivus.js');

		var acids = $('.acids > *');

		new Vivus('dopamine', {duration: 300});
		new Vivus('gaba', {duration: 500});
		new Vivus('phenylalanine', {duration: 600});
		new Vivus('cysteine', {duration: 140});
		new Vivus('glycine', {duration: 700});
		new Vivus('serotonin', {duration: 600});
		new Vivus('tryptophan', {duration: 400});
		new Vivus('tyrosine', {duration: 200});

		// function moveDiv() {
		//
		//    acids.each(function() {
		// 	   var el = $(this),
		// 		   h = 20,
		// 		   w = 20,
		// 		   nh = Math.floor(Math.random() * h),
		// 		   nw = Math.floor(Math.random() * w);
		//
		//         el.animate({ marginLeft: nh, marginTop: nw }, 15000);
		//     });
		// };
		//
		// setInterval(moveDiv, 0);
	}

}

module.exports = Acids;
