<?php
/*
	synphony-phonebook : phonebook_access.php

	Manage the per-domain remote-phonebook credential (the username/password a
	handset presents to xml.php). One-click generate / regenerate. The plaintext
	password is shown ONCE, immediately after generation; only its bcrypt hash is
	stored. CSRF-protected; domain-scoped to the current session domain.
*/

//includes
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//permission
	if (!permission_exists('phonebook_access')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$database    = new database;
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
	$domain_name = $_SESSION['domain_name'] ?? '';

	$new_password = '';   // populated only on a fresh generation, shown once

//---- handle generate / regenerate -------------------------------------------
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {

		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid security token.', 'negative');
			header('Location: phonebook_access.php');
			exit;
		}

		//existing credential for this domain?
		$existing = $database->select(
			"select phonebook_auth_uuid, username from v_phonebook_auth where domain_uuid = :domain_uuid",
			['domain_uuid'=>$domain_uuid], 'row'
		);

		if (!empty($existing)) {
			$auth_uuid = $existing['phonebook_auth_uuid'];
			$username  = $existing['username'];           // keep the username on regenerate
		} else {
			$auth_uuid = uuid();
			//derive a readable username from the domain's first label
			$parts = explode('.', $domain_name);
			$base  = preg_replace('/[^a-z0-9]/', '', strtolower($parts[0] ?? ''));
			if ($base === '') { $base = 'phonebook'; }
			$username = $base;
			//ensure it is globally unique
			$taken = $database->select(
				"select count(*) as c from v_phonebook_auth where username = :username",
				['username'=>$username], 'row'
			);
			if (!empty($taken) && (int)$taken['c'] > 0) {
				$username = $base.'-'.substr(bin2hex(random_bytes(2)), 0, 4);
			}
		}

		//generate a strong password; store only the bcrypt hash
		$new_password = bin2hex(random_bytes(9));   // 18 hex chars
		$password_hash = password_hash($new_password, PASSWORD_DEFAULT);

		$array['phonebook_auth'][0]['phonebook_auth_uuid'] = $auth_uuid;
		$array['phonebook_auth'][0]['domain_uuid']         = $domain_uuid;
		$array['phonebook_auth'][0]['username']            = $username;
		$array['phonebook_auth'][0]['password_hash']       = $password_hash;
		$array['phonebook_auth'][0]['enabled']             = 'true';
		$database->save($array);
		unset($array);

		message::add('Phonebook access generated. Copy the password now — it is shown only once.');
		//fall through to render, showing $new_password once
	}

//---- current status ---------------------------------------------------------
	$current = $database->select(
		"select username, enabled from v_phonebook_auth where domain_uuid = :domain_uuid",
		['domain_uuid'=>$domain_uuid], 'row'
	);

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

	$url = 'https://'.$domain_name.'/app/phonebook/xml.php';

//render
	$document['title'] = 'Phonebook Access';
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>Phonebook Access</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>'Back','icon'=>'chevron-left','link'=>'phonebook.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<p>This is the login the desk phones use to fetch <b>".escape($domain_name)."</b>'s phonebook. "
		. "Each domain has its own; a phone cannot reach another domain's book without that domain's login.</p>\n";

	echo "<table class='list'>\n";
	echo "<tr class='list-header'><th>Setting</th><th>Value</th></tr>\n";
	echo "<tr><td>Remote phonebook URL</td><td><code>".escape($url)."</code></td></tr>\n";
	echo "<tr><td>Username</td><td>".(!empty($current) ? "<code>".escape($current['username'])."</code>" : "<i>none yet</i>")."</td></tr>\n";

	if ($new_password !== '') {
		echo "<tr><td>Password <b>(shown once)</b></td><td><code style='font-size:1.1em;'>".escape($new_password)."</code></td></tr>\n";
	} elseif (!empty($current)) {
		echo "<tr><td>Password</td><td><i>set (hidden) — regenerate to get a new one</i></td></tr>\n";
	}
	echo "</table>\n";
	echo "<br>\n";

	echo "<form method='post' action='phonebook_access.php' onsubmit=\"return confirm('".(!empty($current) ? "Regenerate the password? Existing phones will need the new one." : "Generate phonebook access for this domain?")."');\">\n";
	echo "	<input type='hidden' name='action' value='generate'>\n";
	echo "	<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "	".button::create(['type'=>'submit','label'=>(!empty($current) ? 'Regenerate password' : 'Generate phonebook access'),'icon'=>'key'])."\n";
	echo "</form>\n";

	echo "<br><p style='color:#888;'>Put the URL, username and password into each phone's Remote Phonebook settings for this domain. "
		. "The endpoint auto-detects the handset make; if needed you can force it by adding <code>?type=yealink</code> "
		. "(or grandstream / fanvil / snom) to the URL.</p>\n";

	require_once "resources/footer.php";
?>
