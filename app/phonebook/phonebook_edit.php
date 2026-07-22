<?php
/*
	synphony-phonebook : phonebook_edit.php  (add / edit a contact)

	Add or edit is domain-scoped: a saved contact is always written with the
	current $_SESSION['domain_uuid']; editing an existing row first confirms it
	belongs to this domain. CSRF-protected; all output escaped.
*/

//includes
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//permission
	if (!(permission_exists('phonebook_add') || permission_exists('phonebook_edit'))) {
		echo "access denied";
		exit;
	}

//multi-lingual
	$language = new text;
	$text = $language->get();

	$database = new database;
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';

//figure out which record (if any) we are editing
	$id = '';
	if (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) {
		$id = $_REQUEST['id'];
	}

//---- handle the save --------------------------------------------------------
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//CSRF
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid security token.', 'negative');
			header('Location: phonebook.php');
			exit;
		}

		$is_new = ($id === '');

		//collect + trim input
		$contact_name         = trim($_POST['contact_name'] ?? '');
		$contact_organization = trim($_POST['contact_organization'] ?? '');
		$phone_number         = trim($_POST['phone_number'] ?? '');
		$phone_number2        = trim($_POST['phone_number2'] ?? '');
		$enabled              = (($_POST['enabled'] ?? '') === 'true') ? 'true' : 'false';

		//minimal validation
		if ($contact_name === '' || $phone_number === '') {
			message::add('A name and a phone number are required.', 'negative');
			header('Location: phonebook_edit.php'.($id !== '' ? '?id='.urlencode($id) : ''));
			exit;
		}

		//if editing, confirm the row belongs to this domain before touching it
		if ($id !== '') {
			$sql = "select domain_uuid from v_phonebook where phonebook_uuid = :uuid";
			$check = $database->select($sql, ['uuid'=>$id], 'row');
			$owns = !empty($check) && (
				$check['domain_uuid'] === $domain_uuid
				|| (permission_exists('phonebook_domain') && empty($check['domain_uuid']))
			);
			if (!$owns) {
				message::add('Contact not found in this domain.', 'negative');
				header('Location: phonebook.php');
				exit;
			}
		} else {
			$id = uuid();
		}

		//Save via a direct prepared statement. We deliberately avoid
		//$database->save() here: it reformats numeric-looking fields and strips
		//a leading zero from phone numbers (e.g. 07898... -> 7898...). Prepared
		//params store the value exactly as entered. $enabled is already strictly
		//'true'/'false', so it is safe to inline as a boolean literal.
		$enabled_sql = ($enabled === 'true') ? 'true' : 'false';
		if ($is_new) {
			$database->execute(
				"insert into v_phonebook "
				. "(phonebook_uuid, domain_uuid, contact_name, contact_organization, phone_number, phone_number2, enabled) "
				. "values (:uuid, :d, :name, :org, :num, :num2, ".$enabled_sql.")",
				['uuid'=>$id, 'd'=>$domain_uuid, 'name'=>$contact_name, 'org'=>$contact_organization,
				 'num'=>$phone_number, 'num2'=>$phone_number2]
			);
		} else {
			$database->execute(
				"update v_phonebook set contact_name = :name, contact_organization = :org, "
				. "phone_number = :num, phone_number2 = :num2, enabled = ".$enabled_sql.", update_date = now() "
				. "where phonebook_uuid = :uuid and domain_uuid = :d",
				['name'=>$contact_name, 'org'=>$contact_organization, 'num'=>$phone_number,
				 'num2'=>$phone_number2, 'uuid'=>$id, 'd'=>$domain_uuid]
			);
		}

		message::add('Contact saved.');
		header('Location: phonebook.php');
		exit;
	}

//---- load an existing record for the form -----------------------------------
	$contact_name = $contact_organization = $phone_number = $phone_number2 = '';
	$enabled = 'true';
	if ($id !== '') {
		$sql = "select * from v_phonebook where phonebook_uuid = :uuid "
			. "and ( domain_uuid = :domain_uuid ";
		$parameters = ['uuid'=>$id, 'domain_uuid'=>$domain_uuid];
		if (permission_exists('phonebook_domain')) {
			$sql .= "or domain_uuid is null ";
		}
		$sql .= ")";
		$row = $database->select($sql, $parameters, 'row');
		if (empty($row)) {
			message::add('Contact not found in this domain.', 'negative');
			header('Location: phonebook.php');
			exit;
		}
		$contact_name         = $row['contact_name'] ?? '';
		$contact_organization = $row['contact_organization'] ?? '';
		$phone_number         = $row['phone_number'] ?? '';
		$phone_number2        = $row['phone_number2'] ?? '';
		$enabled = ($row['enabled'] === true || $row['enabled'] === 't' || $row['enabled'] === 'true') ? 'true' : 'false';
	}

//token for the form
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//render
	$document['title'] = 'Phonebook Contact';
	require_once "resources/header.php";

	echo "<form method='post' action='phonebook_edit.php'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".($id !== '' ? 'Edit Contact' : 'Add Contact')."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>'Back','icon'=>'chevron-left','link'=>'phonebook.php']);
	echo button::create(['type'=>'submit','label'=>'Save','icon'=>'check','id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left'>Name</td>\n";
	echo "	<td class='vtable' align='left'><input class='formfld' type='text' name='contact_name' maxlength='255' value='".escape($contact_name)."' required></td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left'>Organisation</td>\n";
	echo "	<td class='vtable' align='left'><input class='formfld' type='text' name='contact_organization' maxlength='255' value='".escape($contact_organization)."'></td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left'>Number</td>\n";
	echo "	<td class='vtable' align='left'><input class='formfld' type='text' name='phone_number' maxlength='255' value='".escape($phone_number)."' required></td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left'>Second number</td>\n";
	echo "	<td class='vtable' align='left'><input class='formfld' type='text' name='phone_number2' maxlength='255' value='".escape($phone_number2)."'></td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left'>Enabled</td>\n";
	echo "	<td class='vtable' align='left'><select class='formfld' name='enabled'>\n";
	echo "		<option value='true'".($enabled === 'true' ? " selected" : "").">true</option>\n";
	echo "		<option value='false'".($enabled === 'false' ? " selected" : "").">false</option>\n";
	echo "	</select></td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "<br>\n";

	if ($id !== '') {
		echo "<input type='hidden' name='id' value='".escape($id)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

	require_once "resources/footer.php";
?>
