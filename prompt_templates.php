<?php

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (!permission_exists('language_model_prompt_template_view')) {
    echo "access denied";
    exit;
}

$language = new text;
$text = $language->get();

$list_row_edit_button = $settings->get('theme', 'list_row_edit_button', false);

$order_by = $_GET["order_by"] ?? '';
$order = $_GET["order"] ?? '';

$sql = "
    select
	    language_model_prompt_template_uuid,
	    template_name,
	    template_category,
	    template_model,
	    template_enabled,
	    template_description
    from v_language_model_prompt_templates
";

$sql .= order_by($order_by, $order);

$rows = $database->select($sql, null, 'all');
$num_rows = is_array($rows) ? count($rows) : 0;

/*
 * BULK DELETE ACTION
 */
if (!empty($_POST['action']) && $_POST['action'] === 'delete') {

    if (!permission_exists('language_model_prompt_template_delete')) {
        echo "access denied";
        exit;
    }

    if (!empty($_POST['templates'])) {
        foreach ($_POST['templates'] as $t) {
            if (!empty($t['uuid'])) {

                $sql = "delete from v_language_model_prompt_templates
                    where language_model_prompt_template_uuid = :id";

                $database->execute($sql, ['id' => $t['uuid']]);
            }
        }
    }
    header("Location: prompt_templates.php");
    exit;
}

/* Toggle Enable/Disable */
if (!empty($_POST['action']) && $_POST['action'] === 'toggle') {

    if (!permission_exists('language_model_prompt_template_edit')) {
        echo "access denied";
        exit;
    }

    if (!empty($_POST['templates'])) {
        foreach ($_POST['templates'] as $t) {
            if (!empty($t['uuid'])) {
                $sql = "update v_language_model_prompt_templates
                        set template_enabled = case
                            when template_enabled = true then false
                            else true
                        end
                        where language_model_prompt_template_uuid = :id";
                $database->execute($sql, ['id' => $t['uuid']]);
            }
        }
    }
    header("Location: prompt_templates.php");
    exit;
}

$document['title'] = $text['label-language_model_prompt_templates'];
require_once "resources/header.php";

echo "<div class='action_bar' id='action_bar'>";
echo "  <div class='heading'><b>".$text['label-language_model_prompt_templates']."</b><div class='count'>".$num_rows."</div></div>";
echo "  <div class='actions'>";

echo button::create([
	'type'=>'link',
	'label'=>$text['button-back'],
	'icon'=>$settings->get('theme', 'button_icon_back'),
	'link'=>'language_model.php'
]);

if (permission_exists('language_model_prompt_template_edit') && $num_rows > 0) {
    echo button::create([
        'type' => 'button',
        'label' => $text['button-toggle'],
        'icon' => $settings->get('theme','button_icon_toggle'),
        'id' => 'btn_toggle',
        'style' => 'display: none;',
        'onclick' => "document.getElementById('action').value='toggle'; document.getElementById('form_list').submit();"
    ]);
}

if (permission_exists('language_model_prompt_template_delete') && $num_rows > 0) {
    echo button::create([
	    'type' => 'button',
	    'label' => $text['button-delete'],
	    'icon' => $settings->get('theme','button_icon_delete'),
	    'id' => 'btn_delete',
	    'style' => 'display: none;',
	    'onclick' => "modal_open('modal-delete','btn_delete');"
    ]);
}

if (permission_exists('language_model_prompt_template_add')) {
	echo button::create([
	    'type' => 'link',
	    'label' => $text['button-add'],
	    'icon' => $settings->get('theme','button_icon_add'),
	    'link' => 'prompt_template_edit.php'
	]);
}

echo "  </div>";
echo "  <div style='clear: both;'></div>";
echo "</div>";

echo "<form id='form_list' method='post'>";
echo "<input type='hidden' id='action' name='action' value=''>";

echo "<div class='card'>";
echo "<table class='list'>";
echo "<tr class='list-header'>";

if (permission_exists('language_model_prompt_template_delete')) {
	echo "<th class='checkbox'><input type='checkbox' id='checkbox_all' onclick='list_all_toggle_custom(this);'></th>";
}

echo th_order_by('template_name', $text['label-name'], $order_by, $order);
echo th_order_by('template_category', $text['label-category'], $order_by, $order);
echo th_order_by('template_model', $text['label-model'], $order_by, $order);
echo th_order_by('template_enabled', $text['label-enabled'], $order_by, $order, null, "class='center'");
echo th_order_by('template_description', $text['label-description'], $order_by, $order, null, "class='hide-sm-dn'");
echo "</tr>";

if (!empty($rows)) {
    $x = 0;
    foreach ($rows as $row) {
        $list_row_url = "prompt_template_edit.php?id=".urlencode($row['language_model_prompt_template_uuid']);
        echo "<tr class='list-row' href='".$list_row_url."'>";
        if (permission_exists('language_model_prompt_template_delete')) {
            echo "<td class='checkbox'>";
            	echo "<input type='checkbox' name='templates[$x][uuid]' value='".$row['language_model_prompt_template_uuid']."' onclick='checkbox_on_change(this); list_action_visible();'>";
        }
        echo "<td><a href='".$list_row_url."'>".escape($row['template_name'])."</a></td>";
        echo "<td>".escape($row['template_category'])."</td>";
        echo "<td>".escape($row['template_model'])."</td>";
        echo "<td class='center'>".($row['template_enabled'] ? 'true' : 'false')."</td>";
        echo "<td class='description overflow hide-sm-dn'>".escape($row['template_description'])."</td>";
        echo "</tr>";
        $x++;
    }
}

echo "</table>";
echo "</div>";
echo "</form>";

/*
 * DELETE CONFIRM MODAL
 */
if (permission_exists('language_model_prompt_template_delete') && $num_rows > 0) {
    echo modal::create([
	    'id' => 'modal-delete',
	    'type' => 'delete',
	    'actions' => button::create([
	        'type' => 'button',
	        'label' => $text['button-continue'],
	        'icon' => $settings->get('theme', 'button_icon_delete'),
	        'onclick' => "modal_close(); document.getElementById('action').value='delete'; document.getElementById('form_list').submit();"
	    ])
    ]);
}

echo "<script>
function list_all_toggle_custom(source) {
    var checkboxes = document.querySelectorAll(\"input[name^='templates']\");
    checkboxes.forEach(function(cb) {
        cb.checked = source.checked;
    });
    list_action_visible();
}
</script>";

echo '<script>
function list_action_visible() {
    var any_checked = document.querySelectorAll("input[name^=\'templates\']:checked").length > 0;
    var ids = ["btn_delete", "btn_toggle"];
    ids.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = any_checked ? "" : "none";
    });
}
</script>';

require_once "resources/footer.php";
?>
