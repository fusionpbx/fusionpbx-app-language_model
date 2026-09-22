<?php
/*
 FusionPBX
 Version: MPL 1.1

 The contents of this file are subject to the Mozilla Public License Version
 1.1 (the "License"); you may not use this file except in compliance with
 the License. You may obtain a copy of the License at
 http://www.mozilla.org/MPL/

 Software distributed under the License is distributed on an "AS IS" basis,
 WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 for the specific language governing rights and limitations under the
 License.

 The Original Code is FusionPBX

 The Initial Developer of the Original Code is
 Mark J Crane <markjcrane@fusionpbx.com>
 Portions created by the Initial Developer are Copyright (C) 2026
 the Initial Developer. All Rights Reserved.

 Contributor(s):
 Mark J Crane <markjcrane@fusionpbx.com>
*/

// Include files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

// Check permissions
if (!permission_exists('language_model_view')) {
	echo "access denied";
	exit;
}

// Add multi-lingual support
$language = new text;
$text = $language->get();

// Get the settings
$theme_button_icon_edit = $settings->get('theme', 'button_icon_edit');
$theme_button_icon_add = $settings->get('theme', 'button_icon_add');
$theme_button_icon_upload = $settings->get('theme', 'button_icon_upload');
$theme_button_icon_cancel = $settings->get('theme', 'button_icon_cancel');
$theme_button_icon_delete = $settings->get('theme', 'button_icon_delete');
$theme_button_icon_all = $settings->get('theme', 'button_icon_all');
$theme_button_icon_search = $settings->get('theme', 'button_icon_search');
$theme_button_icon_download = $settings->get('theme', 'button_icon_download');
$theme_button_icon_play = $settings->get('theme', 'button_icon_play');
$theme_button_icon_reset = $settings->get('theme', 'button_icon_reset');

// Use a paperclip icon
$theme_button_icon_upload = 'fa-solid fa-paperclip';

// Get the form values
$request_text = $_REQUEST['request_text'] ?? '';

// Get available models
$stream = false;
$assistant = new language_model($stream);
$models = $assistant->get_models();
//view_array($models);

// Create token
$object = new token;
$token = $object->create($_SERVER['PHP_SELF']);

// Include the header
$document['title'] = $text['title-language_model'];
require_once "resources/header.php";

// Show the content
echo "<div class='action_bar' id='action_bar'>\n";
echo "	<div class='heading'><b>".$text['title-language_model']."</b></div>\n";
echo "	<div class='actions'>\n";

if (permission_exists('language_model_prompt_template_view')) {
    echo button::create([
	    'type' => 'link',
	    'label' => $text['label-language_model_prompt_templates'],
	    'icon' => $settings->get('theme','button_icon_list'),
	    'link' => 'prompt_templates.php'
    ]);
}

if (permission_exists('language_model_upload')) {
	echo 	"<input name='action' type='hidden' value='upload'>\n";
	echo 	"<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "<select name=\"model\" id=\"model\" class='formfld'>\n";
	echo "<option value=\"\"></option>\n";
	if (!empty($models)) {
		foreach($models as $field) {
			if (!empty($model) && $model == $field['model']) {
				echo "<option value='".escape($field['model'])."' selected='selected'>".escape($field['model'])."</option>\n";
			}
			else {
				echo "<option value='".escape($field['model'])."'>".escape($field['model'])."</option>\n";
			}
		}
	}
	echo "</select>";

	echo button::create(['type'=>'button','label'=>$text['button-upload'],'icon'=>$theme_button_icon_upload,'id'=>'btn_upload','onclick'=>"$(this).fadeOut(250, function(){ $('span#form_upload').fadeIn(250); document.getElementById('ulfile').click(); });"]);
	echo "					<span id='form_upload_span' style='display: none;'>\n";
	echo button::create(['type' => 'button', 'label' => $text['button-cancel'], 'icon' => $theme_button_icon_cancel, 'id' => 'btn_upload_cancel', 'onclick' => "document.getElementById('form_upload_span').style.display='none'; document.getElementById('form_upload').reset(); document.getElementById('filename').value=''; document.getElementById('btn_upload').style.display='inline';"]);
	echo "					<input type='text' class='txt' style='width: 100px; cursor: pointer;' id='filename' placeholder='Select...' readonly onclick=\"document.getElementById('ulfile').click(); this.blur();\" onfocus='this.blur();'>\n";
	echo "					<input type='file' id='ulfile' name='file' style='display: none;' accept='.png,.jpg,.jpeg' onchange=\"document.getElementById('filename').value = this.files[0]?.name || '';\">\n";
	echo "				</span>\n";
}

echo button::create(['type'=>'onclick','label'=>$text['button-send'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','onclick'=>'send_request()']);
echo "	</div>\n";
//echo "	<div style='clear: both;'>".$text['description-language_model']."</div>\n";
echo "</div>\n";
echo "<br />\n";

echo "<div class='card'>\n";
echo "		<table style='width: 100%;'>\n";
echo "			<tr>\n";
echo "				<td class=\"vtable3\" style='width: 100%;'>\n";
echo "					<pre><div id=\"response\" style='width: 80%;'></div></pre>\n";
echo "				</td>\n";
echo "			</tr>\n";
echo "			<tr>\n";
echo "				<td class=\"vtable\">\n";
echo "					<textarea name='request_text' id='request_text' class='formfld' rows='8' style='width: 100%; height: 100%;' placeholder='".escape($text['label-assistant_message'] ?? '')."' >".htmlspecialchars($request_text, ENT_QUOTES, 'UTF-8')."</textarea>\n";
echo "				</td>\n";
echo "			</tr>\n";
echo "		</table>\n";
echo "		<br /><br />\n";
echo "</div>\n";

?>

<script>

async function send_request() {
	const model = document.getElementById('model').value;
	const request_text = document.getElementById('request_text').value;
	const response_div = document.getElementById('response');

	// Clear previous output
	response_div.innerHTML = '';
	window.renderedLength = 0;  // Track how many chars have been displayed
	window.fullResponse = '';    // Accumulate all response content

	const formData = new FormData();
	const file_input = document.getElementById('ulfile');
	formData.append("stream", true);
	formData.append("model", model);
	formData.append("request_text", request_text);

	if (file_input.files.length > 0) {
		const file = file_input.files[0];
		const allowed_types = ['image/png', 'image/jpeg'];
		if (allowed_types.includes(file.type)) {
			formData.append('file', file);
		} else {
			console.log('Error: Only PNG or JPEG files are allowed.');
			return;
		}
	}

	try {
		const response = await fetch("request.php", {
			method: "POST",
			body: formData
		});

		if (!response.ok) {
			throw new Error(`HTTP error! status: ${response.status}`);
		}

		const reader = response.body.getReader();
		let buffer = '';

		while (true) {
			const { done, value } = await reader.read();

			if (done) break;

			buffer += new TextDecoder().decode(value);

			// Process complete lines only
			let newlineIndex;
			while ((newlineIndex = buffer.indexOf('\n')) >= 0) {
				const line = buffer.slice(0, newlineIndex).trim();
				buffer = buffer.slice(newlineIndex + 1);

				if (!line) continue;

				let jsonString = line;
				if (jsonString.startsWith('data: ')) {
					jsonString = jsonString.slice(6).trim();
				}

				try {
					const data = JSON.parse(jsonString);
					let content = '';

					if (data.response) {
						content = data.response;
					} else if (data.choices && data.choices[0]?.delta?.content) {
						content = data.choices[0].delta.content;
					}

					if (content) {
						window.fullResponse += content;
						render_new_content(content);
					}

					if (data.done === true || (data.choices && data.choices[0]?.delta?.finish_reason === 'stop')) {
						buffer = '';  // Clear any remaining buffer
						break;
					}

				} catch (e) {
					// Incomplete JSON, skip
				}
			}
		}
	}
	catch (error) {
		console.error('Error:', error);
		response_div.textContent = 'Error: Failed to fetch response';
	}
}

function render_new_content(new_content) {
	const response_div = document.getElementById('response');

	// Escape the new content and append it
	response_div.innerHTML += escape_html(new_content);

	// Auto-scroll to bottom
	response_div.scrollTop = response_div.scrollHeight;
}

function escape_html(str) {
	return String(str)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

</script>

<br />
<br />

<?php

//include the footer
require_once "resources/footer.php";
