<?php

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (!permission_exists('language_model_prompt_template_add')) {
    echo "access denied";
    exit;
}

$id = $_GET['id'] ?? '';
$new_name = $_GET['name'] ?? '';

if (empty($id) || empty($new_name)) {
    header("Location: prompt_templates.php");
    exit;
}

$row = $database->select(
    "select * from v_language_model_prompt_templates where language_model_prompt_template_uuid = :id",
    ['id' => $id],
    'row'
);

if (empty($row)) {
    header("Location: prompt_templates.php");
    exit;
}

$insert = [
    'domain_uuid' => $_SESSION['domain_uuid'],
    'template_language' => $row['template_language'] ?? '',
    'template_category' => $row['template_category'] ?? '',
    'template_subcategory' => $row['template_subcategory'] ?? '',
    'template_name' => $new_name,
    'template_prompt' => $row['template_prompt'] ?? '',
    'template_model' => $row['template_model'] ?? '',
    'template_enabled' => $row['template_enabled'] ?? true,
    'template_description' => ($row['template_description'] ?? '') . ' (Copy)'
];

$database->execute("
	insert into v_language_model_prompt_templates (
	    language_model_prompt_template_uuid,
	    domain_uuid,
	    template_language,
	    template_category,
	    template_subcategory,
	    template_name,
	    template_prompt,
	    template_model,
	    template_enabled,
	    template_description,
	    insert_date
	) values (
	    uuid(),
	    :domain_uuid,
	    :template_language,
	    :template_category,
	    :template_subcategory,
	    :template_name,
	    :template_prompt,
	    :template_model,
	    :template_enabled,
	    :template_description,
	    now()
	)
", $insert);

header("Location: prompt_templates.php");
exit;
?>
