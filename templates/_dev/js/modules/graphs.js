'use strict';

//const $ = require('jquery');

class Graphs {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('.bar-graph').length ) {
			this.bar();
			this.round();
        } else {
            return;
        }
	}

	events() {

		var bar = $('.bar-graph');

		bar.each(function(){

			var el = $(this),
				p = el.attr('data-graph'),
				fill = el.find('.graph-fill'),
				text = el.find('.js-percent'),
				textPos = el.find('.graph p'),
				counter = { var: 0 };

			if( el.hasClass('bar-graph--horizontal') ) {
				fill.css('width', p + '%');
				//textPos.css('left', p + '%');

				TweenMax.to(counter, 4, { var: p,
					onUpdate: function () {
						var num = Math.ceil(counter.var);
						text.html(num);
					},
					ease:Circ.easeOut
				});
			} else {
				fill.css('height', p + '%');
				//textPos.css('bottom', p + '%');
				TweenMax.to(counter, 4, { var: p,
					onUpdate: function () {
						var num = Math.ceil(counter.var);
						text.html(num);
					},
					ease:Circ.easeOut
				});
			}
		});

	}

	bar() {
		var bar = $('.bar-graph'),
			dt = 0.5;

		function count(el, to, del) {
			var counter = { var: 0 };
			TweenMax.to(counter, 3, { var: to,
				onUpdate: function () {
					var num = Math.ceil(counter.var);
					el.html(num);
				},
				ease: Power4.easeOut
			}).delay(del);
		}

		bar.each(function(){
			var el = $(this),
				p = el.attr('data-graph'),
				bar = el.find('.graph-fill'),
				text = el.find('.js-percent'),
				active = false;

				function checksvg() {
					if(el.hasClass('in-view')){
						if(active == false){
							if( el.hasClass('bar-graph--horizontal') ) {
								TweenMax.to(bar, 3, {width: p + '%', ease: Elastic.easeOut.config(1, 0.5)}).delay(dt);
							} else {
								TweenMax.to(bar, 3, {height: p + '%', ease: Elastic.easeOut.config(1, 0.5)}).delay(dt);
							}
							count(text, p, dt);
							active = true;
						}
					} else {
						if(active == true){
							if( el.hasClass('bar-graph--horizontal') ) {
								TweenMax.to(bar, 0.2, {width: '0%'});
							} else {
								TweenMax.to(bar, 0.2, {height: '0%'});
							}
							count(text, '0', 0);
							active = false;
						}
					}
				}

			$(window).on("resize scroll", function(){
				checksvg();
			});
			$(document).ready(function(){
				checksvg();
			});

			$('.tabs__tab').click(function(){
				setTimeout(function(){
					checksvg();
				}, 100);
			});

		});
	}


	round() {

		var round = $('.round-graph'),
			dt = 0.5;

		TweenLite.to(".outer", 0, {drawSVG:"0%"});

		function count(el, to, del) {
			var counter = { var: 0 };
			TweenMax.to(counter, 3, { var: to,
				onUpdate: function () {
					var num = Math.ceil(counter.var);
					el.html(num);
				},
				ease: Power4.easeOut
			}).delay(del);
		}

		round.each(function(){
			var el = $(this),
				p = el.attr('data-graph'),
				svg = el.find('svg .outer'),
				text = el.find('.js-percent'),
				active = false;

				function checksvg() {
					if(el.hasClass('in-view')){
						if(active == false){
							TweenMax.to(svg, 4, {drawSVG: p + '%', ease: Elastic.easeOut.config(1, 0.5)}).delay(dt);
							count(text, p, dt);
							active = true;
						}
					} else {
						if(active == true){
							TweenLite.to(svg, 1, {drawSVG: '0%'});
							count(text, '0', 0);
							active = false;
						}
					}
				}

			$(window).on("resize scroll", function(){
				checksvg();
			});
			$(document).ready(function(){
				checksvg();
			});

			$('.tabs__tab').click(function(){
				setTimeout(function(){
					checksvg();
				}, 100);
			});
		});
	}

}

module.exports = Graphs;
