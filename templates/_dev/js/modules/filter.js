'use strict';

class Filter {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('.js-filter-form-simple').length ) {
			//this.simple();
			this.rep();

        } else if( $('.js-filter-form-complex').length ) {
            this.complex();
        } else {
			return;
		}
	}

	complex() {
		console.log('complex filter');
		var city = $('.filter-form__city'),
			state = $('.filter-form__state'),
			zip = $('.filter-form__zip'),
			type = $('.filter-form__type'),
			filterSubmit = $('.filter-form__submit'),
			filterItems = $('.js-filter-list > *'),
			list = $('.js-filter-list');

			function updateResults() {
				// Values
				var cv = city.val().toLowerCase(),
					sv = state.val(),
					zv = zip.val(),
					tv = type.val(),
					hits = 0;

					$('.js-results').html('');

				filterItems.each(function(){
					var state = $(this).attr('data-filter-state'),
						city = $(this).attr('data-filter-city'),
						zip = $(this).attr('data-filter-zip'),
						type = $(this).attr('data-filter-type'),
						current = $(this),
						cityMatch = true,
						stateMatch = true,
						zipMatch = true,
						typeMatch = true;

					if( cv != '' ) {
						if( city == cv) {
							$('.js-results').append('<span>city</span>');
						} else {
							cityMatch = false;
						}
					}

					if( sv != '' ) {
						if( state == sv) {
							$('.js-results').append('<span>state</span>');
						} else {
							stateMatch = false;
						}
					}
					if( zv != '' ) {
						if( zip == zv) {
							$('.js-results').append('<span>zip</span>');
						} else {
							zipMatch = false;
						}
					}
					if( tv != '' ) {
						if( type == tv) {
							$('.js-results').append('<span>practitioner type</span>');
						} else {
							typeMatch = false;
						}
					}

					if( cityMatch == true && stateMatch == true && zipMatch == true && typeMatch == true ) {
						current.addClass('is-active');
						hits++;
					}
				});
				//console.log(hits);

				if(hits >= 1){
					$('.js-filter-text').html('We found matches by: ');
				} else {
					$('.js-results').html('');
					$('.js-filter-text').html('');

					$('.js-filter-result-message').html("<span class='section'>We're sorry but we did not find any physicians that matched your criteria. Please try to broaden your search or contact us to help get you in touch with a physician.</span>");
				}

			}

			filterSubmit.on('click', function(e){
				e.preventDefault();
				list.addClass('is-filtering');
				setTimeout(function(){
					filterItems.removeClass('is-active');
					updateResults();
					list.removeClass('is-filtering');
				}, 1200);

			});

	}

	rep() {
		var county = $('.filter-form__county'),
			state = $('.filter-form__state'),
			zip = $('.filter-form__zip'),
			filterSubmit = $('.filter-form__submit'),
			filterItems = $('.js-filter-list > .filter-item'),
			list = $('.js-filter-list');

			//filterItems.hide();

			function updateResults() {
				// Values
				var cv = county.val().toLowerCase().replace(/\s+/g, '-'),
					sv = state.val(),
					zv = zip.val(),
					hits = 0;

				filterItems.each(function(){
					var el = $(this),
						countyMatch = true,
						stateMatch = true,
						zipMatch = true

					// Zip
					if( zv != '' ) {
						if( el.hasClass('zip-' + zv) ) {
							console.log('zip match');
						} else {
							zipMatch = false;
						}
						//console.log('zip not empty');
					}


					// County
					if( cv != '' ) {
						if( !el.hasClass('county-' + cv) ) {
							countyMatch = false;
						}
						//console.log('county not empty');
					}

					console.log(cv);

					// State
					if( sv != '' ) {
						if(! el.hasClass('state-' + sv) ) {
							stateMatch = false;
						}
						console.log('state not empty');
					}

					if( countyMatch == true && stateMatch == true && zipMatch == true ) {
						el.addClass('is-active');
						hits++;
						console.log('we have a match');
					}
				});

				if( hits == 0 ){
					$('.js-filter-empty').removeClass('is-hidden');
				} else {
					$('.js-filter-empty').addClass('is-hidden');
				}
			}

			filterSubmit.on('click', function(e){
				$('.js-filter-default').hide();
				e.preventDefault();
				list.addClass('is-filtering');

				setTimeout(function(){
					filterItems.removeClass('is-active');
					updateResults();
					list.removeClass('is-filtering');
				}, 1000);

			});

	}

	simple() {
        console.log('Ready to party');

		var filterInput = $('.filter-form__input'),
			filterSubmit = $('.filter-form__submit'),
			filterItems = $('.js-filter-list > *'),
			list = $('.js-filter-list');

			function updateResults() {
				var vl = filterInput.val();
				$('.js-results').html(vl);

				filterItems.each(function(){
					var code = $(this).attr('data-filter-code');


					if( vl === code ){
						$(this).removeClass('is-hidden').addClass('is-active');
					} else {
						$(this).removeClass('is-active').addClass('is-hidden');
					}
				});

			}

			filterInput.on('keyup change', function(){
				list.addClass('is-filtering');
				setTimeout(function(){
					updateResults();
					list.removeClass('is-filtering');


				}, 1200);

			});

	}

}

module.exports = Filter;
