if (!window.jQuery) {
    document.write('<script src="//cdnjs.cloudflare.com/ajax/libs/jquery/2.1.1/jquery.min.js"><\/script>');
}

(function(){

	Raster_Admin.system = [
		{
			"name" : 'Logout',
			"link" : 'login/logout/fromraster',
			"type" : 'navigable'
		},
		{
			"name" : 'Settings',
			"data" : 'raster',
			"type" : 'data'
		},
		{
			"name" : 'Users',
			"data" : 'users',
			"type" : 'data'
		}
	];

	function build_buttons() {
		var holder = $('<div id="raster_tools" class="raster rtoolbar"></ul>');
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
			if (Raster_Admin.system[i].type == 'navigable') {
				account.append('<li class="raction" data-type="system" data-rel="'+data_rel+'">'+nice_name+'</li>');	
			} else {
				var data_type = Raster_Admin.system[i].data;
				account.append('<li class="raction" data-type="data" data-rel="'+data_type+'">'+nice_name+'</li>');
			}
			
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

	function do_edit(e) {
		e.preventDefault();
		var did = $(this).data('rel');
		var name = $(this).data('name');
		$('#raster_editor .body').html('<span>42 is the answer!</span>');
		var info = {
			"did":did,
			"name":name
		};
		
		var media = [];
		$.post(BASE + 'api/cms/edit_item', info, function(data){
			$('.modal #raster_editor .body').html(data);
		})
	}

	function media_control() {
		Raster_Admin.media = {}; 
		var croppicHeaderOptions = {
			uploadUrl:BASE+'api/cms/upload_media',
			cropData:{
				"dummyData":1,
				"dummyData2":"asdas"
			},
			// customUploadButtonId:'',
			cropUrl:BASE+'api/cms/crop_media',
			modal:false,
			loaderHtml:'<div class="loader">Watson!</div> ',
			onBeforeImgUpload: function(){ console.log('onBeforeImgUpload') },
			onAfterImgUpload: function(){ console.log('onAfterImgUpload') },
			onImgDrag: function(){ console.log('onImgDrag') },
			onImgZoom: function(){ console.log('onImgZoom') },
			onBeforeImgCrop: function(){ console.log('onBeforeImgCrop') },
			onAfterImgCrop:function(){ console.log('onAfterImgCrop') }
		}
		var id = $(this).attr('id');
		// croppicHeaderOptions.customUploadButtonId = id + '_upload';
		croppicHeaderOptions.outputUrlId = "input_"+id;
		Raster_Admin.media[id] = new Croppic(id, croppicHeaderOptions);
	}

	function do_add(e) {
		e.preventDefault();
		var name = $(this).data('name');
		$('#raster_editor .body').html('<span>This is the beginning of a beautiful friendship!</span>');
		var info = {
			"name":name
		};
		$.post(BASE + 'api/cms/add_item', info, function(data){
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
		$(document).on('click', '.raction', do_action);
		$(document).on('click', '.data_editor', do_edit);
		$(document).on('click', '.data_adder', do_add);
		$(document).on('click', '.media_object', media_control);
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

