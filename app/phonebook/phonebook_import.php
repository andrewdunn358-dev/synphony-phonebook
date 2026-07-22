<?php
/*
	synphony-phonebook : phonebook_import.php

	Bulk-import contacts into the current domain's phonebook from a CSV file.

	- Accepts a header row (columns matched case-insensitively: name, number,
	  organization, number2/mobile) or, if no header is recognised, positional
	  columns: name, number, organization, number2.
	- Domain-scoped: rows are always inserted with the current session domain,
	  so an import can only ever populate the tenant you're in.
	- Optional "replace" wipes this domain's existing contacts first (needs the
	  delete permission).
	- Prepared inserts (no SQL injection); output escaped; CSRF-protected.
	- ?template=1 downloads a starter CSV.
*/

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!permission_exists('phonebook_add')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$database    = new database;
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';

//--- downloadable template ---------------------------------------------------
	if (($_GET['template'] ?? '') === '1') {
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="phonebook_template.csv"');
		echo "name,number,organization,number2\r\n";
		echo "John Smith,01912223333,Acme Ltd,07700900123\r\n";
		echo "Jane Doe,02072224444,,\r\n";
		exit;
	}

//--- process an upload -------------------------------------------------------
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid security token.', 'negative');
			header('Location: phonebook_import.php');
			exit;
		}

		if (empty($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			message::add('Please choose a CSV file to upload.', 'negative');
			header('Location: phonebook_import.php');
			exit;
		}
		if (($_FILES['csv_file']['size'] ?? 0) > 5 * 1024 * 1024) {
			message::add('File too large (max 5 MB).', 'negative');
			header('Location: phonebook_import.php');
			exit;
		}

		//read the CSV
		$rows = [];
		if (($h = fopen($_FILES['csv_file']['tmp_name'], 'r')) !== false) {
			while (($data = fgetcsv($h, 0, ',')) !== false) {
				$rows[] = $data;
			}
			fclose($h);
		}
		if (empty($rows)) {
			message::add('The file was empty or could not be read.', 'negative');
			header('Location: phonebook_import.php');
			exit;
		}

		//detect a header row and build a column map
		$first = array_map(function ($v) { return strtolower(trim((string)$v)); }, $rows[0]);
		$map = ['name'=>null, 'number'=>null, 'org'=>null, 'number2'=>null];
		$has_header = false;
		foreach ($first as $i => $col) {
			if (in_array($col, ['name','contact_name','full name','fullname','contact','contact name'], true)) {
				if ($map['name'] === null) { $map['name'] = $i; } $has_header = true;
			} elseif (in_array($col, ['number','phone','phone_number','telephone','tel','phone number','phone1','number1'], true)) {
				if ($map['number'] === null) { $map['number'] = $i; } $has_header = true;
			} elseif (in_array($col, ['organization','organisation','company','org','contact_organization'], true)) {
				if ($map['org'] === null) { $map['org'] = $i; } $has_header = true;
			} elseif (in_array($col, ['number2','phone2','mobile','phone_number2','second number','mobile number','mobile phone'], true)) {
				if ($map['number2'] === null) { $map['number2'] = $i; } $has_header = true;
			}
		}
		if ($has_header) {
			$data_rows = array_slice($rows, 1);
			if ($map['name'] === null)   { $map['name'] = 0; }
			if ($map['number'] === null) { $map['number'] = 1; }
		} else {
			$map = ['name'=>0, 'number'=>1, 'org'=>2, 'number2'=>3];
			$data_rows = $rows;
		}

		//optional: clear this domain's contacts first
		if (($_POST['replace'] ?? '') === 'true' && permission_exists('phonebook_delete')) {
			$database->execute("delete from v_phonebook where domain_uuid = :d", ['d'=>$domain_uuid]);
		}

		//insert
		$imported = 0; $skipped = 0; $limit = 5000;
		foreach ($data_rows as $row) {
			if ($imported >= $limit) { break; }
			$name    = trim((string)($row[$map['name']] ?? ''));
			$number  = trim((string)($row[$map['number']] ?? ''));
			$org     = ($map['org'] !== null)     ? trim((string)($row[$map['org']] ?? ''))     : '';
			$number2 = ($map['number2'] !== null) ? trim((string)($row[$map['number2']] ?? '')) : '';
			if ($name === '' || $number === '') { $skipped++; continue; }
			$database->execute(
				"insert into v_phonebook "
				. "(phonebook_uuid, domain_uuid, contact_name, contact_organization, phone_number, phone_number2, enabled) "
				. "values (:uuid, :d, :n, :o, :p, :p2, true)",
				['uuid'=>uuid(), 'd'=>$domain_uuid, 'n'=>$name, 'o'=>$org, 'p'=>$number, 'p2'=>$number2]
			);
			$imported++;
		}

		$msg = "Imported ".$imported." contact(s).";
		if ($skipped > 0) { $msg .= " Skipped ".$skipped." row(s) with no name or number."; }
		if ($imported >= $limit) { $msg .= " Import capped at ".$limit."; split larger files."; }
		message::add($msg);
		header('Location: phonebook.php');
		exit;
	}

//--- render the upload form --------------------------------------------------
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

	$document['title'] = 'Import Phonebook';
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>Import Phonebook</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>'Back','icon'=>'chevron-left','link'=>'phonebook.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<p>Upload a CSV to bulk-add contacts to <b>this domain's</b> phonebook. "
		. "Include a header row with columns <code>name</code>, <code>number</code>, and optionally "
		. "<code>organization</code> and <code>number2</code> — or just put those four in order with no header. "
		. "Rows without a name and a number are skipped.</p>\n";

	echo "<p>Need a starting point? <a href='phonebook_import.php?template=1'>Download a template CSV</a>.</p>\n";

	echo "<form method='post' action='phonebook_import.php' enctype='multipart/form-data'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left'>CSV file</td>\n";
	echo "	<td class='vtable' align='left'><input type='file' name='csv_file' accept='.csv,text/csv' required></td>\n";
	echo "</tr>\n";

	if (permission_exists('phonebook_delete')) {
		echo "<tr>\n";
		echo "	<td class='vncell' valign='top' align='left'>Replace existing</td>\n";
		echo "	<td class='vtable' align='left'>\n";
		echo "		<select class='formfld' name='replace'>\n";
		echo "			<option value='false'>No — add to the existing contacts</option>\n";
		echo "			<option value='true'>Yes — delete this domain's contacts first</option>\n";
		echo "		</select>\n";
		echo "	</td>\n";
		echo "</tr>\n";
	}

	echo "</table>\n";
	echo "<br>\n";
	echo button::create(['type'=>'submit','label'=>'Import','icon'=>'upload','id'=>'btn_import']);
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

	require_once "resources/footer.php";
?>
