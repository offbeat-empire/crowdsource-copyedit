var csce = jQuery;
var ucc_csce_ajax_request = null;
var ucc_csce_selected_text = null;

csce(document).ready(function() {

	ucc_csce_ajaxurl = ucc_csce_localizations['ajaxurl'];

	csce("#ucc-csce-copyedit").show();
	csce("#ucc-csce-copyedit-wrapper").show();
 	csce.fn.button.noConflict()
	var dialog = csce("#ucc-csce-copyedit-wrapper").dialog({
		autoOpen: false,
		width: 360,
		modal: true,
		dialogClass: "copyedit-modal",
		buttons: {
			"Submit" : {
				text: "Submit",
				id: "submit-button",
				class: "btn btn-primary",
				click: function() {
					copyedit_author       = csce("#ucc-csce-copyedit-author").val();
					copyedit_author_email = csce("#ucc-csce-copyedit-author-email").val();
					original_text         = csce("#ucc-csce-original-text").val();
					edited_text           = csce("#ucc-csce-edited-text").val();
					notes                 = csce("#ucc-csce-notes").val();
					post_id        = csce("#ucc-csce-post-id").val();
					nonce          = csce("#ucc-csce-nonce").val();

					var form_data = {
						'copyedit_author': copyedit_author,
						'copyedit_author_email': copyedit_author_email,
						'original_text': original_text,
						'edited_text': edited_text,
						'notes': notes,
						'post_id': post_id,
						'nonce': nonce
					}

					if (ucc_csce_ajax_request)
						ucc_csce_ajax_request.abort();

					var data = {
						'action': 'ucc_csce_copyedit',
						'ucc_csce': form_data
					}

					ucc_csce_ajax_request = csce.post(ucc_csce_ajaxurl, data, function(response) {
						retval = csce.parseJSON(response);

						csce("#ucc-csce-copyedit-messages").html('');

						if (retval['success']) {
							csce("#ucc-csce-copyedit-messages").html(retval['message']);
							csce("#ucc-csce-new-copyedit").hide();
							csce(".ui-dialog-buttonpane").hide();
							setTimeout(function() {
								csce(dialog).dialog("close")
								resetForm();
							},3000);
						} else {
							csce("#ucc-csce-copyedit-messages").html(retval['errors']);
						}
					});
				}
			},
			"Exit": {
				id: "cancel-button",
				text: "Cancel",
				class: "btn",
				click: function() {
					csce(this).dialog("close");
					resetForm();
				}
			}
		},
		close: function() {
			csce(this).dialog("close");
			resetForm();
			csce(".ui-button").focus();
		}
	});

	csce("#ucc-csce-copyedit").button().click(function(event) {
		event.preventDefault();
		csce("#ucc-csce-copyedit-wrapper").dialog("open");
		csce(".ui-button").addClass('menu-toggle');
		csce(".ui-button").show();
	});

	csce("#content").mouseup(function() {
		s = getSelectedText();
		if (s) {
			csce("#ucc-csce-original-text").val(s);
			csce("#ucc-csce-edited-text").val(s);
		}
	});

	function getSelectedText() {
		if (window.getSelection())
			return window.getSelection();
		if (document.getSelection())
			return document.getSelection();
		if (document.selection)
			return document.selection.createRange().text;
		return false;
	}

	function resetForm() {
		csce("#ucc-csce-copyedit-messages").html('');
		csce("#ucc-csce-new-copyedit").show();
		csce(".ui-dialog-buttonpane").show();
		csce("#ucc-csce-original-text").val('');
		csce("#ucc-csce-edited-text").val('');
		csce("#ucc-csce-notes").val('');
	}
})
