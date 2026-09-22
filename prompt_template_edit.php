<?php

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

/*
if (!permission_exists('language_model_prompt_template_edit')) {
    echo "access denied";
    exit;
}
*/

$language = new text;
$text = $language->get();

/*
 * SAVE ACTION
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {

    $is_new = empty($_POST['language_model_prompt_template_uuid']);

    if ($is_new && !permission_exists('language_model_prompt_template_add')) {
        echo "access denied";
        exit;
    }
    if (!$is_new && !permission_exists('language_model_prompt_template_edit')) {
        echo "access denied";
        exit;
    }

    $uuid = $is_new ? uuid() : $_POST['language_model_prompt_template_uuid'];

    $prompt = str_replace(["\r\n", "\r"], "\n", $_POST['template_prompt'] ?? '');

	$record = [
	    'language_model_prompt_templates' => [[
	        'language_model_prompt_template_uuid' => $uuid,
	        'domain_uuid' => $_SESSION['domain_uuid'],
	        'template_name' => $_POST['template_name'] ?? '',
	        'template_category' => $_POST['template_category'] ?? '',
	        'template_model' => $_POST['template_model'] ?? '',
	        'template_description' => $_POST['template_description'] ?? '',
	        'template_enabled' => $_POST['template_enabled'] ?? 'false',
	        'template_prompt' => $prompt,
	    ]]
	];

    $database->save($record);

    header("Location: prompt_template_edit.php?id=" . $uuid);
    exit;
}

/*
 * COPY ACTION
 */
if (!empty($_GET['action']) && $_GET['action'] === 'copy' && !empty($_GET['id'])) {

    if (!permission_exists('language_model_prompt_template_add')) {
        echo "access denied";
        exit;
    }

    $row = $database->select(
        "select * from v_language_model_prompt_templates where language_model_prompt_template_uuid = :id",
        ['id' => $_GET['id']],
        'row'
    );

    if (!empty($row)) {

        unset($row['language_model_prompt_template_uuid']);

        $row['template_name'] .= ' (Copy)';
        $new_uuid = uuid();

        $record = [
		    'language_model_prompt_templates' => [[
		        'language_model_prompt_template_uuid' => $new_uuid,
		        'domain_uuid' => $row['domain_uuid'],
		        'template_language' => $row['template_language'],
		        'template_category' => $row['template_category'],
		        'template_subcategory' => $row['template_subcategory'],
		        'template_name' => $row['template_name'],
		        'template_prompt' => $row['template_prompt'],
		        'template_model' => $row['template_model'],
		        'template_enabled' => $row['template_enabled'],
		        'template_description' => $row['template_description']
		    ]]
		];

		$database->save($record);

	    header("Location: prompt_template_edit.php?id=" . $new_uuid);
	    exit;
    }
}


/*
 * DELETE ACTION
 */
if (!empty($_POST['action']) && $_POST['action'] === 'delete') {

    if (!permission_exists('language_model_prompt_template_delete')) {
        echo "access denied";
        exit;
    }

    $id = $_POST['language_model_prompt_template_uuid'] ?? $_GET['id'] ?? null;

    if (!empty($id)) {
	    $sql = "delete from v_language_model_prompt_templates
	            where language_model_prompt_template_uuid = :id";
	    $database->execute($sql, ['id' => $id]);
    }

    header("Location: prompt_templates.php");
    exit;
}


/*
 * PERMISSIONS
 */
if (!(permission_exists('language_model_prompt_template_add')
	|| permission_exists('language_model_prompt_template_edit'))) {
	echo "access denied";
	exit;
}

$language_model_prompt_template_uuid = $_GET['id'] ?? '';

$row = [];

if (!empty($language_model_prompt_template_uuid)) {

    $sql = "
        select *
        from v_language_model_prompt_templates
        where language_model_prompt_template_uuid = :uuid
    ";

    $row = $database->select(
        $sql,
        ['uuid' => $language_model_prompt_template_uuid],
        'row'
    );
}

$document['title'] = $text['label-language_model_prompt_template'];
require_once "resources/header.php";

echo "<form method='post'>";

echo "<input type='hidden' name='language_model_prompt_template_uuid' value='".escape($row['language_model_prompt_template_uuid'] ?? '')."'>";

echo "<div class='action_bar' id='action_bar'>";
echo "<div class='heading'><b>".$text['label-language_model_prompt_template']."</b></div>";
echo "<div class='actions'>";

echo button::create([
	'type' => 'button',
	'label' => $text['button-back'],
	'icon' => $settings->get('theme', 'button_icon_back'),
	'link' => 'prompt_templates.php'
]);

if (!empty($row['language_model_prompt_template_uuid'])) {
    echo button::create([
	    'type' => 'button',
	    'label' => $text['button-copy'],
	    'icon' => $settings->get('theme','button_icon_copy'),
	    'link' => 'prompt_template_edit.php?action=copy&id=' . $row['language_model_prompt_template_uuid']
    ]);
}

echo button::create([
    'type' => 'submit',
    'label' => $text['button-save'],
    'icon' => $settings->get('theme','button_icon_save')
]);

echo "</div>";
echo "<div style='clear:both;'></div>";
echo "</div>";

echo "<div class='card'>";

echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>";
echo "<colgroup>";
echo "<col style='width:20%;'>";
echo "<col style='width:80%;'>";
echo "</colgroup>";

echo "<tr><td class='vncellreq'>Name</td><td class='vtable'>
<input class='formfld' style='width:100%;' name='template_name' value='".escape($row['template_name'] ?? '')."'>
</td></tr>";

echo "<tr><td class='vncellreq'>Category</td><td class='vtable'>
<input class='formfld' style='width:100%;' name='template_category' value='".escape($row['template_category'] ?? '')."'>
</td></tr>";

echo "<tr><td class='vncellreq'>Model</td><td class='vtable'>
<input class='formfld' style='width:100%;' name='template_model' value='".escape($row['template_model'] ?? '')."'>
</td></tr>";

echo "<tr><td class='vncell'>Description</td><td class='vtable'>
<input class='formfld' style='width:100%;' name='template_description' value='".escape($row['template_description'] ?? '')."'>
</td></tr>";

echo "<tr><td class='vncell'>Enabled</td><td class='vtable'>
<select class='formfld' name='template_enabled'>
<option value='true' ".(($row['template_enabled'] ?? false) ? 'selected' : '').">true</option>
<option value='false' ".(!(($row['template_enabled'] ?? false)) ? 'selected' : '').">false</option>
</select>
</td></tr>";

echo "<tr><td class='vncellreq' valign='top'>Prompt</td><td class='vtable'>
<textarea class='formfld' style='width:100%;height:500px;' name='template_prompt'>".escape($row['template_prompt'] ?? '')."</textarea>
</td></tr>";

echo "</table>";

echo "</div>";

echo "</form>";

require_once "resources/footer.php";
?>
