'use strict';

class Form {

	constructor( options ) {

		//setup any defaults
		this.defaults = {};

		//merge options with defaults
		this.settings = $.extend( true, {}, this.defaults, options );

		if( $('#form-submit').length ) {
			this.forms();
            $('.js-form-success').hide();
        } else {
			return;
		}
	}

	forms() {
        var submit = $('#form-submit'),
            form = $('.contact-form-main');

        $(document).ready(function(){
            $('.required').each(function(){
                var wrap =  $(this).parents('.field'),
                    input = wrap.find('input');

                if( $(this).text() == 'Email' ) {
                    //input.attr('type', 'email');
                }
            });
        });

        function validateEmail(email) {
            var re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(email);
        }
        function validateText(text) {
            var re = /^[a-zA-Z0-9-.!?'$ ]*$/;
            return re.test(text);
        }

		form.submit(function(ev) {

            ev.preventDefault();

            var vc = $('.required').length,
                passed = 0;

            $('.required').each(function(){
                var wrap = $(this).parents('.field'),
                    input = wrap.find('input'),
                    inputVal = input.val();

                if( $(this).text() == 'Email' ) {
                    console.log(validateEmail(inputVal));
                    if( validateEmail(inputVal) == false ) {
                        wrap.addClass('field-error');
                        wrap.append('<p class="error-message">Please enter a valid email address</p>');
                    } else {
                        wrap.removeClass('field-error');
                        passed++;
                    }
                } else {
                    console.log(validateText(inputVal));
                    if( validateText(inputVal) == false ) {
                        wrap.addClass('field-error');
                    } else {
                        wrap.removeClass('field-error');
                        passed++;
                    }
                }
            });

            console.log('required fields passed = ' + passed);

            if(passed < vc){
                return;
            }

            $('.form-wrap').addClass('is-thinking');

            $.ajax({
				type: 'POST',
				url: '/',
				data: form.serialize(),
				//dataType: "json",
				success: function (data) {
                    console.log("success");

					setTimeout(function(){
						$('.form-wrap').removeClass('is-thinking');
						form.hide();
						$('.js-form-success').show(400);
						$('html, body').animate({
							scrollTop: $('.js-form-success').offset().top
						}, 500);
					}, 1000);
				},
				error: function (data) {
					console.log( 'error', arguments );
                    //alert('error');

					// $('.form-wrap').addClass('is-thinking');
					// $('html, body').animate({
					// 	scrollTop: $('#ra-inquiry-form').offset().top
					// }, 500);

					setTimeout(function(){
						$('.form-wrap').removeClass('is-thinking');
					//	$('.form-error-message').removeClass('is-hidden');
					}, 300);
				}

                // if (response.success) {
                //     console.log("Success");
                // }
			});

        });

	}
}

module.exports = Form;
