<?php

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (!permission_exists('language_model_prompt_template_delete')) {
	echo "access denied";
	exit;
}

$id = $_GET['id'] ?? '';

if (!is_uuid($id)) {
	header("Location: prompt_templates.php");
	exit;
}

$array['language_model_prompt_templates'][0]['language_model_prompt_template_uuid'] = $id;
$database->delete($array);

header("Location: prompt_templates.php");
exit;

