<?php
/*
	synphony-phonebook : phonebook.php  (list view)

	Lists the current domain's phonebook contacts. Domain scoping uses
	$_SESSION['domain_uuid']; a superadmin with phonebook_domain also sees
	global (null-domain) rows. All queries are parameterised.
*/

//includes
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//permission
	if (!permission_exists('phonebook_view')) {
		echo "access denied";
		exit;
	}

//multi-lingual (falls back to the key text if a translation is absent)
	$language = new text;
	$text = $language->get();

	$domain_uuid = $_SESSION['domain_uuid'] ?? '';

//handle a delete action (POST + CSRF token)
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid security token.', 'negative');
			header('Location: phonebook.php');
			exit;
		}
		if (permission_exists('phonebook_delete') && is_uuid($_POST['delete_uuid'] ?? '')) {
			$obj = new phonebook;
			$obj->delete([$_POST['delete_uuid']]);
			message::add('Contact deleted.');
		}
		header('Location: phonebook.php');
		exit;
	}

//fetch this domain's contacts
	$database = new database;
	$sql = "select phonebook_uuid, contact_name, contact_organization, "
		. "phone_number, phone_number2, enabled "
		. "from v_phonebook "
		. "where ( domain_uuid = :domain_uuid ";
	$parameters['domain_uuid'] = $domain_uuid;
	if (permission_exists('phonebook_domain')) {
		$sql .= "or domain_uuid is null ";
	}
	$sql .= ") order by contact_name asc";
	$contacts = $database->select($sql, $parameters, 'all');
	if (!is_array($contacts)) {
		$contacts = [];
	}

//prepare a token for the inline delete forms
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//render
	$document['title'] = 'Phonebook';
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>Phonebook</b></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('phonebook_access')) {
		echo button::create(['type'=>'button','label'=>'Phonebook access','icon'=>'key','link'=>'phonebook_access.php']);
	}
	if (permission_exists('phonebook_add')) {
		echo button::create(['type'=>'button','label'=>'Add','icon'=>'plus','link'=>'phonebook_edit.php']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<p>Contacts here are served to this domain's desk phones as a remote phonebook. Each domain sees only its own.</p>\n";

	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>Name</th>\n";
	echo "	<th>Organisation</th>\n";
	echo "	<th>Number</th>\n";
	echo "	<th>Second number</th>\n";
	echo "	<th>Enabled</th>\n";
	echo "	<th class='center'>Edit</th>\n";
	if (permission_exists('phonebook_delete')) {
		echo "	<th class='center'>Delete</th>\n";
	}
	echo "</tr>\n";

	if (empty($contacts)) {
		echo "<tr><td colspan='7'>No contacts yet.</td></tr>\n";
	}
	foreach ($contacts as $row) {
		$uuid = $row['phonebook_uuid'];
		$edit_link = "phonebook_edit.php?id=".urlencode($uuid);
		$enabled = ($row['enabled'] === true || $row['enabled'] === 't' || $row['enabled'] === 'true') ? 'true' : 'false';
		echo "<tr>\n";
		echo "	<td><a href='".$edit_link."'>".escape($row['contact_name'])."</a></td>\n";
		echo "	<td>".escape($row['contact_organization'])."</td>\n";
		echo "	<td>".escape($row['phone_number'])."</td>\n";
		echo "	<td>".escape($row['phone_number2'])."</td>\n";
		echo "	<td>".$enabled."</td>\n";
		echo "	<td class='center'>".button::create(['type'=>'button','label'=>'Edit','icon'=>'pencil-alt','link'=>$edit_link])."</td>\n";
		if (permission_exists('phonebook_delete')) {
			echo "	<td class='center'>\n";
			echo "		<form method='post' action='phonebook.php' onsubmit=\"return confirm('Delete this contact?');\" style='display:inline;'>\n";
			echo "			<input type='hidden' name='action' value='delete'>\n";
			echo "			<input type='hidden' name='delete_uuid' value='".escape($uuid)."'>\n";
			echo "			<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo "			".button::create(['type'=>'submit','label'=>'Delete','icon'=>'trash'])."\n";
			echo "		</form>\n";
			echo "	</td>\n";
		}
		echo "</tr>\n";
	}
	echo "</table>\n";

	require_once "resources/footer.php";
?>
