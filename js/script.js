jQuery(document).ready(function ($) {

	$('#tab-delivery')
		.on('click', '.mymail-multismtp-add', function () {

			var servers = $('.mymail-multismtp-server'),
				count = servers.length,
				el = servers.eq(0).clone().hide();


			el.find('input').each(function () {
				var _this = $(this);
				_this.attr('name', _this.attr('name').replace('[0]', '[' + count + ']'));
			});

			el.insertAfter(servers.last()).slideDown();
			return false;

		})
		.on('click', '.mymail-multismtp-remove', function () {

			var servers = $('.mymail-multismtp-server'),
				count = servers.length,
				el = $(this).parent().parent();

			if (count <= 1) return false;

			el.slideUp(function () {
				el.remove();
			});

			return false;
		});

});