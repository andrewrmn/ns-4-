'use strict';

class ProductFilter {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('.pf').length ) {
			this.filter();
			this.filterMenus();
        } else {
			return;
		}
	}

	filter() {

		function updateCount(target) {
			var el = target,
				parent = el.parents('.has-sub-nav'),
				countDisplay = parent.find('.cat-count'),
				count = parent.find('.tag-filter:not(.in-active)').length;

			if (count > 0) {
				countDisplay.text(count).removeClass('is-hidden');
			} else {
				countDisplay.text(count).addClass('is-hidden');
			}
		}

		function updateSanescoSectionVisibility() {
			var $sanesco = $('.products-sanesco');
			if (!$sanesco.length) {
				return;
			}
			var visibleCount = 0;
			if (activeTags.length <= 0) {
				$sanesco.removeClass('is-hidden');
			} else {
				visibleCount = $sanesco.find('.product-preview').filter(function () {
					var $p = $(this);
					return !$p.hasClass('in-active') && !$p.hasClass('un-active');
				}).length;
				if (visibleCount === 0) {
					$sanesco.addClass('is-hidden');
				} else {
					$sanesco.removeClass('is-hidden');
				}
			}
		}

		//console.log('here');
		var products = $('.product-preview'),
			tagFilter = $('.tag-filter'),
			activeTags = [],
			first = true,
			newTag = [],
			availableTags = [];

		tagFilter.click(function() {

			var filter = $(this),
				tag = filter.attr('data-tag-filter'),
				uncheck = false;

			if( filter.hasClass('js-symptom-link') ){
				if( $(this).hasClass('in-active') ){
					console.log('hit it');
					//console.log('Has Modal');
					if( $('#symptom-modal').hasClass('is-active') ){
						//console.log('hit');
						$('#symptom-modal').removeClass('is-active');
						$('body').removeClass('terms-modal');
					} else {
						$('#symptom-modal').addClass('is-active');
						$('body').addClass('terms-modal');
					}
				} else {
					console.log('not hit');
				}
			}

			if( filter.hasClass('in-active') ){
				filter.removeClass('in-active');
				activeTags.push(tag);
			} else {
				filter.addClass('in-active');
				var index = activeTags.indexOf(tag);
				activeTags.splice(index, 1);
				uncheck = true;
			}

			// function hasItems(superset, subset) {
			// 	return subset.every(function (value) {
			// 		console.log(subset);
			// 		return (superset.indexOf(value) >= 0);
			// 	});
			// }

			// https://jsfiddle.net/kf6zy6bx/9/
			function findOne(arr2, arr) {
				return arr.some(function (v) {
					return arr2.indexOf(v) >= 0;
				});
			}

			function matchAll(superset, subset) {
			 	return subset.every(function (value) {
			 		return (superset.indexOf(value) >= 0);
			 	});
			}

			//console.log(activeTags);

			if (activeTags.length == 1) {
				availableTags = [];
				//console.log('First Filters');
				products.each(function(){
	                var product = $(this),
						tags = $(this).attr('data-tags'),
	                    tArr = tags.split(', '),
						length = tArr.length;

					if( findOne(tArr, activeTags) ){
						product.addClass('is-active').removeClass('in-active un-active');
						availableTags.push(tags);
						Array.prototype.push.apply(availableTags, tArr);
					} else {
						product.addClass('in-active').removeClass('un-active');
					}
	            });
			} else {
				//console.log('Multiple Filters');

				// Search for active products & determine if they have the newly selected filter
				// Hide them if they dont

				// Clear availableTags
				availableTags = [];

				$('.product-preview.is-active').each(function(){
					var product = $(this),
						tags = $(this).attr('data-tags'),
	                    tArr = tags.split(', '),
						length = tArr.length;

					if( matchAll(tArr, activeTags) ){
						//console.log('still has all');
						product.addClass('is-active').removeClass('un-active');
						availableTags.push(tArr);
						Array.prototype.push.apply(availableTags, tArr);
					} else {
						//console.log('missing one or more');
						product.addClass('un-active');
					}
				});
			}

			//console.log(availableTags);

			// Search active products
			// Create an array of all tags assigns to active products
			// Check array against filters and disable filters that aren't in array
			tagFilter.each(function(){
				var t = $(this),
					a = t.attr('data-tag-filter'),
					ta = a.split(', ');

				if( findOne(ta, availableTags) ){
					t.removeClass('disabled');
					//xwconsole.log('match = ' + ta);
					return;
				} else {
					t.addClass('disabled');
				}
			});




			//console.log(activeTags.length);
			$('.pf__hd .filter-count').text(activeTags.length);

			first = false;

			if (activeTags.length <= 0) {
				products.removeClass('in-active').addClass('is-active');
			//	console.log('Length is 0');
				first = true;
			}
			updateCount(filter);
			updateSanescoSectionVisibility();

		});

        $('.js-reset-filters').click(function() {
			var el = $(this);
			first = true;
			el.addClass('is-active');
            $('.filter-count').text('0');
            products.removeClass('in-active un-active').addClass('is-active');
            tagFilter.addClass('in-active').removeClass('disabled');
            activeTags = [];
			availableTags = [];
			$('.cat-count').text('0').addClass('is-hidden');
			setTimeout(function(){
				el.removeClass('is-active');
			}, 3000);
			updateSanescoSectionVisibility();
        });

	}


	filterMenus() {
		var subNavs = $('.js-product-cat-nav'),
			body = $('body'),
			mask = $('.filter-mask'),
			hasNav = $('.has-sub-nav'),
			pf = $('.pf');


		subNavs.click(function(){
			var par = $(this).parent('.has-sub-nav');

			if( par.hasClass('is-active') ){
				hasNav.removeClass('is-active');
				par.removeClass('is-active');
				body.removeClass('product-menu-open');
			} else {
				hasNav.removeClass('is-active');
				par.addClass('is-active');
				body.addClass('product-menu-open');
			}
		});

		mask.click(function() {
			hasNav.removeClass('is-active');
			body.removeClass('product-menu-open');
		});

		$('.pf__apply').click(function() {
			hasNav.removeClass('is-active');
			body.removeClass('product-menu-open');
			pf.removeClass('is-active');
		});

		$('.js-filter-toggle').click(function() {
			hasNav.removeClass('is-active');
			body.removeClass('product-menu-open');

			if( pf.hasClass('is-active') ){
				pf.removeClass('is-active');
				body.removeClass('pf-nav-is-open');
			} else {
				hasNav.removeClass('is-active');
				pf.addClass('is-active');
				body.addClass('pf-nav-is-open');
			}
		});
	}
}

module.exports = ProductFilter;
