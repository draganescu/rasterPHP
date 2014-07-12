if (!window.jQuery) {
    document.write('<script src="//cdnjs.cloudflare.com/ajax/libs/jquery/2.1.1/jquery.min.js"><\/script>');
}

(function(){

	Raster_Admin.system = [
		{
			"name" : 'Logout',
			"link" : 'login/logout/fromraster'
		},
		{
			"name" : 'Settings',
			"link" : 'login/settings'
		}
	];

	function build_buttons() {
		var holder = $('<div class="raster rtoolbar"></ul>');
		var data = $('<ul class="raster rbutton"><li class="raster rlabel">Page Data</li></ul>');
		var variables = $('<ul class="raster rbutton"><li class="raster rlabel">Page Content</li></ul>');
		var account = $('<ul class="raster rbutton"><li class="raster rlabel">Account</li></ul>');
		for (var i = Raster_Admin.page_data.length - 1; i >= 0; i--) {
			var nice_name = capitaliseFirstLetter(Raster_Admin.page_data[i].replace(/_/, ' '));
			var data_rel = Raster_Admin.page_data[i];
			data.append('<li class="raction" data-type="data" data-rel="'+data_rel+'">'+nice_name+'</li>');
		};
		for (var i = Raster_Admin.page_variables.length - 1; i >= 0; i--) {
			var nice_name = capitaliseFirstLetter(Raster_Admin.page_variables[i].replace(/_/, ' '));
			var data_rel = Raster_Admin.page_variables[i];
			variables.append('<li class="raction" data-type="variable" data-rel="'+data_rel+'">'+nice_name+'</li>');
		};
		for (var i = Raster_Admin.system.length - 1; i >= 0; i--) {
			var nice_name = Raster_Admin.system[i].name;
			var data_rel = Raster_Admin.system[i].link;
			account.append('<li class="raction" data-type="system" data-rel="'+data_rel+'">'+nice_name+'</li>');
		};
		holder.append(data);
		holder.append(variables);
		holder.append(account);
		return holder;
	}

	function create_popup() {
		var popup_template = '<div class="popup" id="raster_editor"> \
			<div class="header"> \
				<h4> \
					 \
				</h4> \
			</div> \
			<div class="body"> \
				 \
			</div> \
			<div class="footer"> \
				<a href="#" class="close button">Discard</a> \
			</div> \
		</div>';
		$("body").append(popup_template);
	}

	function do_action() {
		var rel = $(this).data('rel');
		var type = $(this).data('type');
		if (type == 'system') {
			$('#raster_editor .body').html('<span>Logging you out ...</span>');
			setTimeout(function(){
				window.location.href = BASE + rel;
			}, 1000);
			return true;
		};
		var name = $(this).text();
		$('#raster_editor .header h4').text('Editing ' + name + ' ' + type);
		$('#raster_editor .body').html('<span>Calculating the meaning of life.</span>');
		var info = {
			"page":Raster_Admin.page_name,
			"name":rel
		};
		$.post(BASE + 'api/cms/edit_' + type, info, function(data){
			$('#raster_editor .body').html(data);
		})
	}

	function setup_modals() {
		create_popup();
		// popup modals
		$("li.raction").click(function(event){
			var target = 'raster_editor';
			event.preventDefault();
			$("body").append('<div class="modal"></div>');
			$('.modal').css('position', 'absolute');
			$('.modal').css('top', '0px');
			$('.modal').css('left', '0px');
			$('.modal').css('height', $(document).height()+'px');
			$('#'+target).clone().appendTo('.modal');
			$('.modal .close').click(function(event){
				event.preventDefault();
				$('.modal').remove();
			});
			window.scrollTo(0, 0);
		});
		$(document).keyup(function(event){
			if ( event.keyCode == 27 ) {
				$('.modal').remove();
			}
		});
	}

	function hook_events() {
		$('.raction').click(do_action);
	}

	function capitaliseFirstLetter(string)
	{
	    return string.charAt(0).toUpperCase() + string.slice(1);
	}

	window.onload = function() {
	    $('body').append(build_buttons());
	    setup_modals();
	    hook_events();
	};
}());
