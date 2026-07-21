<?php
/*
	synphony-phonebook : phonebook_access.php

	Manage the per-domain remote-phonebook login and show a ready-to-paste URL
	for each phone make.

	Notes:
	  - Uses a direct prepared INSERT/UPDATE rather than $database->save(),
	    because save() enforces a per-table permission ('phonebook_auth_add')
	    that this app does not define, which would silently skip the write.
	  - The password is stored readably (plain text) so the ready-to-use URL can
	    always be shown -- consistent with how FusionPBX stores SIP/device
	    provisioning passwords. These are low-value, read-only phonebook logins
	    served only over HTTPS.
	  - CSRF-protected; scoped to the current session domain.
*/

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!permission_exists('phonebook_access')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$database    = new database;
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
	$domain_name = $_SESSION['domain_name'] ?? '';

	//renders the generate / regenerate button form
	function phonebook_generate_form($is_first, $token) {
		$label   = $is_first ? 'Generate phonebook access' : 'Regenerate password';
		$confirm = $is_first
			? 'Generate phonebook access for this domain?'
			: 'Regenerate the password? Existing phones will need the new URL/password.';
		$out  = "<form method='post' action='phonebook_access.php' onsubmit=\"return confirm('".$confirm."');\">\n";
		$out .= "	<input type='hidden' name='action' value='generate'>\n";
		$out .= "	<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		$out .= "	".button::create(['type'=>'submit','label'=>$label,'icon'=>'key'])."\n";
		$out .= "</form>\n";
		return $out;
	}

//----------------------------------------------------------------------------
// Handle generate / regenerate
//----------------------------------------------------------------------------
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {

		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid security token.', 'negative');
			header('Location: phonebook_access.php');
			exit;
		}

		$existing = $database->select(
			"select phonebook_auth_uuid, username from v_phonebook_auth where domain_uuid = :domain_uuid",
			['domain_uuid'=>$domain_uuid], 'row'
		);

		//username: keep existing on regenerate, else derive from the domain label
		if (!empty($existing)) {
			$auth_uuid = $existing['phonebook_auth_uuid'];
			$username  = $existing['username'];
		} else {
			$auth_uuid = uuid();
			$parts = explode('.', $domain_name);
			$base  = preg_replace('/[^a-z0-9]/', '', strtolower($parts[0] ?? ''));
			if ($base === '') { $base = 'phonebook'; }
			$username = $base;
			$taken = $database->select(
				"select count(*) as c from v_phonebook_auth where username = :u",
				['u'=>$username], 'row'
			);
			if (!empty($taken) && (int)$taken['c'] > 0) {
				$username = $base.'-'.substr(bin2hex(random_bytes(2)), 0, 4);
			}
		}

		//readable, URL-safe password
		$password = bin2hex(random_bytes(6));   // 12 hex chars

		//direct prepared upsert
		if (!empty($existing)) {
			$database->execute(
				"update v_phonebook_auth set username = :username, password = :password, "
				. "enabled = true, update_date = now() where phonebook_auth_uuid = :uuid",
				['username'=>$username, 'password'=>$password, 'uuid'=>$auth_uuid]
			);
		} else {
			$database->execute(
				"insert into v_phonebook_auth (phonebook_auth_uuid, domain_uuid, username, password, enabled) "
				. "values (:uuid, :domain_uuid, :username, :password, true)",
				['uuid'=>$auth_uuid, 'domain_uuid'=>$domain_uuid, 'username'=>$username, 'password'=>$password]
			);
		}

		message::add('Phonebook access saved.');
		header('Location: phonebook_access.php');
		exit;
	}

//----------------------------------------------------------------------------
// Current credential
//----------------------------------------------------------------------------
	$current = $database->select(
		"select username, password, enabled from v_phonebook_auth where domain_uuid = :domain_uuid",
		['domain_uuid'=>$domain_uuid], 'row'
	);

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//----------------------------------------------------------------------------
// Render
//----------------------------------------------------------------------------
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

	if (empty($current) || empty($current['username'])) {

		echo "<p><b>No phonebook login has been created for this domain yet.</b> "
			. "Click below to generate one — you'll then get the username, password and a "
			. "ready-to-paste URL for each phone make.</p>\n";
		echo phonebook_generate_form(true, $token);

	} else {

		$username = $current['username'];
		$password = $current['password'] ?? '';

		//credentialed base URL (username:password already embedded)
		$hostpart = rawurlencode($username).':'.rawurlencode($password).'@'.$domain_name;
		$base_url = "https://".$hostpart."/app/phonebook/xml.php";
		$plain_url = "https://".$domain_name."/app/phonebook/xml.php";

		//login
		echo "<table class='list'>\n";
		echo "<tr class='list-header'><th>Login</th><th>Value</th></tr>\n";
		echo "<tr><td>Username</td><td><code>".escape($username)."</code></td></tr>\n";
		echo "<tr><td>Password</td><td><code>".escape($password)."</code></td></tr>\n";
		echo "</table>\n";
		echo "<br>\n";

		//ready-to-paste URLs per make
		echo "<p><b>Ready-to-use URL — pick your phone make.</b> The username and password are "
			. "already built in, so you can paste the whole line straight into the phone's "
			. "Remote Phonebook URL field.</p>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'><th>Phone make</th><th>Remote phonebook URL</th></tr>\n";
		$vendors = ['Yealink'=>'yealink', 'Grandstream'=>'grandstream', 'Fanvil'=>'fanvil', 'Snom'=>'snom'];
		foreach ($vendors as $label => $type) {
			echo "<tr><td>".escape($label)."</td><td><code>".escape($base_url.'?type='.$type)."</code></td></tr>\n";
		}
		echo "<tr><td>Any / auto-detect</td><td><code>".escape($base_url)."</code></td></tr>\n";
		echo "</table>\n";
		echo "<br>\n";

		echo "<p style='color:#888;'>If your phone has separate username and password boxes rather than a "
			. "single URL field, use the plain URL <code>".escape($plain_url)."</code> together with the "
			. "username and password above.</p>\n";

		echo phonebook_generate_form(false, $token);
	}

	require_once "resources/footer.php";
?>
