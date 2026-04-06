'use strict';



class Carousel {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('.carousel').length ) {
			this.events();
        } else {
			return;
		}
	}

	events() {
		$('.carousel-synced').slick({
			slidesToShow: 1,
			slidesToScroll: 1,
			arrows: false,
			adaptiveHeight: true,
			fade: true,
			//asNavFor: '.carousel-synced-nav'
		});
		$('.carousel-synced-nav').slick({
			slidesToShow: 3,
			slidesToScroll: 1,
			asNavFor: '.carousel-synced',
			dots: true,
			centerMode: true,
			focusOnSelect: true,
			adaptiveHeight: true,
			infinite: true
		});

		$('.carousel-multiple').each(function(){
			var count = 4,
				countSm = 2,
				countMd = 3,
				countLg = 4,
				newCount = $(this).attr('data-slide-count');

			if( newCount ){
				count = newCount;
				countLg = newCount;

				if(newCount > 5) {
					countLg = 4;
				}
			}

			$(this).slick({
				infinite: true,
				slidesToShow: count,
				slidesToScroll: count,
				dots: true,
				arrows: false,
				responsive: [
					{
				      breakpoint: 500,
				      settings: {
				        slidesToShow: 1,
				        slidesToScroll: 1,
				        infinite: true,
				        dots: true
				      }
				  },
				    {
				      breakpoint: 600,
				      settings: {
				        slidesToShow: countSm,
				        slidesToScroll: countSm,
				        infinite: true,
				        dots: true
				      }
				  },
				  {
					breakpoint: 800,
					settings: {
					  slidesToShow: countMd,
					  slidesToScroll: countMd,
					  infinite: true,
					  dots: true
					}
				},
				{
				  breakpoint: 1000,
				  settings: {
					slidesToShow: countLg,
					slidesToScroll: countLg,
					infinite: true,
					dots: true
				  }
				}
				]
			});
		})


	}
}

module.exports = Carousel;
