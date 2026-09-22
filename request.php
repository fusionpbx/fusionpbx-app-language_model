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

// Turn off ALL output buffering
while (ob_get_level() > 0) {
	ob_end_clean();
}
ini_set('display_errors', 0);

// Set headers BEFORE any framework code
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

// Now include files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

// Check permissions
if (!permission_exists('language_model_view')) {
	echo "access denied";
	exit;
}

// After framework processes, re-set headers (framework may have overridden them)
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header_remove('Content-Security-Policy');
header_remove('X-Frame-Options');
header_remove('X-Content-Type-Options');
header_remove('Referrer-Policy');

// Clear any buffered framework output
while (ob_get_level() > 0) {
	ob_end_clean();
}
ob_implicit_flush(true);

// Get the form values
$model = $_REQUEST['model'] ?? '';
$request_text = $_REQUEST['request_text'] ?? '';
$stream = $_REQUEST['stream'];

// Convert the stream value to boolean
if (isset($_REQUEST['stream']) && $_REQUEST['stream'] === 'true') {
	$stream = true;
} else {
	$stream = false;
}

// Initialize the language mode
$assistant = new language_model($stream);

// Process the request
if (permission_exists('language_model_upload') && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
	$file = $_FILES['file'];

	if ($file['error'] !== UPLOAD_ERR_OK) {
		echo "File upload error: " . $file['error'];
		exit;
	}

	$file_content_base64 = base64_encode(file_get_contents($file['tmp_name']));

	$request_data['prompt'] = $assistant->build_prompt('maintenance', $request_text);
	$request_data['images'][] = $file_content_base64;
	$response = $assistant->request($model, $request_data);
}
else {
	$request_data['prompt'] = $assistant->build_prompt('maintenance', $request_text);
	$response = $assistant->request($model, $request_data);
}

if (!$stream) {
	echo $response;
}
