$().ready(function() {
	$('.show-modal').click(function (e) {
		var $this = $(this);
		if ($this.is('a')) {
			e.preventDefault();
		}
		var $modal_id = $this.attr('data-modal-id') || 'main';
		var $modal = $('#' + $modal_id + 'Modal');
		if($modal.length) {
			$modal.find('.modalOKButton').data('modal-origin', $this);

			var param = $this.attr('data-modal-param');
			if(param) {
				var ary = param.split(',');
				for (var index = 0; index < ary.length && index < 9; ++index) {
					var value = ary[index];
					$($modal).find('span.modalP' + (index+1)).text(value);
				}
			}
			scroll(0, 0);
			$modal.modal('show');
		} else {
			// Just in case we forgot the dialog box
			var conf = confirm("Are you sure?");
			if (conf === true) {
				window.location = href;
			}
		}
		return false;
	});

	$('.modalOKButton').click(function(e) {
		var $this = $(this);
		var $origin = $this.data('modal-origin');
		if ($origin.is('a')) {
			window.location = $origin.attr('href');
		} else {
			$origin.next('input[type=hidden]').attr('value', 1);
			$origin.closest('form').submit();
		}
		return false;
	});
	$('select.multiselect').multiselect({
		includeSelectAllOption: true,
		maxHeight: 400,
		enableCaseInsensitiveFiltering: true
	});

	// snmp configuration
	$('#snmp-config').hide();
	$('#snmp-config #snmp-form-edit').hide();
	$('#snmp-config #snmp-form-add').hide();
	$('.cfg-snmp-edit').click(function(e) {
		var $id = $(this).data('record-id');
		
		$('#snmp-config').show();
		$('#snmp-config #snmp-form-add').hide();
		$('#snmp-config #snmp-form-edit').show();
		$('tr.highlight-fx td').removeClass('highlight');
		$('tr#cfg-snmp-oid-'+ $id +' td').addClass(('highlight'));
		$('#oid_id').val($('#oid_id_'+ $id).html()); 
		$('#oid_name').val($('#oid_name_'+ $id).html()); 
		$('#oid_label').val($('#oid_label_'+ $id).html()); 
		$('#oid_string').val($('#oid_string_'+ $id).html()); 
		$('#oid_conversion').val($('#oid_conversion_'+ $id).html()); 
		$('#oid_status_up').val($('#oid_status_up_'+ $id).html()); 
		$('#oid_status_warning').val($('#oid_status_warning_'+ $id).html()); 
		$('#oid_status_error').val($('#oid_status_error_'+ $id).html()); 
	});

	$('.cfg-snmp-add-new').click(function(e) {
		
		$('#snmp-config').show();
		$('#snmp-config #snmp-form-edit').hide();
		$('#snmp-config #snmp-form-add').show();
		$('tr.highlight-fx td').removeClass('highlight');
		$('#oid_id').val('0'); 
		$('#oid_name').val(''); 
		$('#oid_label').val(''); 
		$('#oid_string').val(''); 
		$('#oid_conversion').val(''); 
		$('#oid_status_up').val(''); 
		$('#oid_status_warning').val(''); 
		$('#oid_status_error').val(''); 
	});

	psm_flash_message();
	psm_tooltips();
});

function psm_xhr(mod, params, method, on_complete, options) {
	method = (typeof method == 'undefined') ? 'GET' : method;

	var xhr_options = {
		data: params,
		type: method,
		success: on_complete,
		error: function(jqjqXHR, textStatus, errorThrown) {
			psm_flash_message(errorThrown);
		}
	};
	$.extend(xhr_options, options);

	var result = $.ajax('index.php?xhr=1&mod=' + mod, xhr_options);

	return result;
}

function psm_saveLayout(layout) {
	var params = {
		action: 'saveLayout',
		layout: layout
	};
	psm_xhr('server_status', params, 'POST');
}

function psm_tooltips() {
	$('input[data-toggle="tooltip"]').tooltip({
		'trigger':'hover',
		'placement': 'right',
		'container': 'body'
	});
	$('i[data-toggle="tooltip"]').tooltip({
		'trigger':'hover',
		'placement': 'bottom'
	});
}

function psm_goTo(url) {
	window.location = url;
}

function trim(str) {
    return str.replace(/^\s+|\s+$/g,"");
}

//left trim
function ltrim(str) {
	return str.replace(/^\s+/,"");
}

//right trim
function rtrim(str) {
	return str.replace(/\s+$/,"");
}

function psm_flash_message(message) {
	var flashmessage = $('#flashmessage');
	if(flashmessage.length){
		if(typeof message != 'undefined') {
			flashmessage.html(message);
		}
		var t = flashmessage.html();
		var c = trim(t);
		var t = c.replace('&nbsp;', '');
		if(t){
			flashmessage.slideDown();
		}
	}
}