<?php
/*
	synphony-phonebook : xml.php

	Public remote-phonebook endpoint. A desk phone fetches this URL and presents
	a per-domain HTTP Basic credential. The endpoint verifies the credential,
	resolves the domain it belongs to, and returns vendor-specific remote-
	phonebook XML containing ONLY that domain's contacts.

	Security notes:
	  - Database access uses FusionPBX's database class (PDO prepared
	    statements, bound parameters) — no string-built SQL.
	  - The password is verified with password_verify() against a bcrypt hash.
	  - Every value written into the XML is escaped.
	  - No portal session is required (phones cannot log in); the per-domain
	    credential IS the access boundary, so one tenant cannot read another's.

	MIT licensed. See repo root.
*/

//----------------------------------------------------------------------------
// Bootstrap FusionPBX (same pattern as app/provision): gives us the database
// class and config without enforcing a portal login.
//----------------------------------------------------------------------------
	require_once dirname(__DIR__, 2) . "/resources/require.php";

//----------------------------------------------------------------------------
// Helpers
//----------------------------------------------------------------------------
	function phonebook_unauthorized() {
		header('WWW-Authenticate: Basic realm="Phonebook"');
		header('HTTP/1.1 401 Unauthorized');
		header('Content-Type: text/plain; charset=utf-8');
		echo "Authentication required.\n";
		exit;
	}

	// XML-escape a value for safe inclusion in element text.
	function pb_x($s) {
		return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

//----------------------------------------------------------------------------
// Read HTTP Basic credentials. PHP-FPM behind nginx does not always populate
// PHP_AUTH_USER, so fall back to parsing the raw Authorization header.
//----------------------------------------------------------------------------
	$auth_user = $_SERVER['PHP_AUTH_USER'] ?? null;
	$auth_pw   = $_SERVER['PHP_AUTH_PW'] ?? null;

	if ($auth_user === null) {
		$header = $_SERVER['HTTP_AUTHORIZATION']
			?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
			?? '';
		if (stripos($header, 'Basic ') === 0) {
			$decoded = base64_decode(substr($header, 6), true);
			if ($decoded !== false && strpos($decoded, ':') !== false) {
				list($auth_user, $auth_pw) = explode(':', $decoded, 2);
			}
		}
	}

	if (empty($auth_user) || $auth_pw === null || $auth_pw === '') {
		phonebook_unauthorized();
	}

//----------------------------------------------------------------------------
// Look up the credential (prepared statement) and verify the password.
//----------------------------------------------------------------------------
	$database = new database;

	$sql = "select domain_uuid, password, password_hash "
		. "from v_phonebook_auth "
		. "where username = :username and enabled = true";
	$parameters = ['username' => $auth_user];
	$row = $database->select($sql, $parameters, 'row');

	// Accept the readable password (current) or fall back to a bcrypt hash
	// (legacy credentials created before migration 005).
	$auth_ok = false;
	if (!empty($row)) {
		if (isset($row['password']) && $row['password'] !== null && $row['password'] !== '') {
			$auth_ok = hash_equals((string)$row['password'], (string)$auth_pw);
		} elseif (!empty($row['password_hash'])) {
			$auth_ok = password_verify($auth_pw, $row['password_hash']);
		}
	} else {
		// Constant-ish time for a missing username (blunts enumeration by timing).
		hash_equals('0000000000000000', (string)$auth_pw);
	}
	if (!$auth_ok) {
		phonebook_unauthorized();
	}
	$domain_uuid = $row['domain_uuid'];

//----------------------------------------------------------------------------
// Decide the output format. Prefer an explicit &type= (whitelisted); otherwise
// sniff the User-Agent; default to Yealink.
//----------------------------------------------------------------------------
	$allowed = ['yealink', 'grandstream', 'fanvil', 'snom'];
	$type = strtolower($_REQUEST['type'] ?? '');
	if (!in_array($type, $allowed, true)) {
		$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
		if (strpos($ua, 'grandstream') !== false)   { $type = 'grandstream'; }
		elseif (strpos($ua, 'fanvil') !== false)     { $type = 'fanvil'; }
		elseif (strpos($ua, 'snom') !== false)       { $type = 'snom'; }
		else                                         { $type = 'yealink'; }
	}

//----------------------------------------------------------------------------
// Fetch this domain's enabled contacts (prepared statement).
//----------------------------------------------------------------------------
	$sql = "select contact_name, contact_organization, phone_number, phone_number2 "
		. "from v_phonebook "
		. "where domain_uuid = :domain_uuid and enabled = true "
		. "order by contact_name asc";
	$parameters = ['domain_uuid' => $domain_uuid];
	$contacts = $database->select($sql, $parameters, 'all');
	if (!is_array($contacts)) {
		$contacts = [];
	}

	// Build a display name (append organisation in brackets when present).
	function pb_display_name($c) {
		$name = $c['contact_name'] ?? '';
		if (!empty($c['contact_organization'])) {
			$name .= ' (' . $c['contact_organization'] . ')';
		}
		return $name;
	}

//----------------------------------------------------------------------------
// Emit the XML.
//----------------------------------------------------------------------------
	// Yealink, Fanvil and Snom share one structure (DirectoryEntry / Name /
	// Telephone); only the root element differs. Grandstream is separate.
	function pb_emit_directory($contacts, $root) {
		header('Content-Type: text/xml; charset=utf-8');
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo "<".$root.">\n";
		echo "  <Title>Phonebook</Title>\n";
		foreach ($contacts as $c) {
			echo "  <DirectoryEntry>\n";
			echo "    <Name>" . pb_x(pb_display_name($c)) . "</Name>\n";
			if (isset($c['phone_number']) && $c['phone_number'] !== '') {
				echo "    <Telephone>" . pb_x($c['phone_number']) . "</Telephone>\n";
			}
			if (!empty($c['phone_number2'])) {
				echo "    <Telephone>" . pb_x($c['phone_number2']) . "</Telephone>\n";
			}
			echo "  </DirectoryEntry>\n";
		}
		echo "</".$root.">\n";
	}

	switch ($type) {

		case 'grandstream':
			header('Content-Type: text/xml; charset=utf-8');
			echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			echo "<AddressBook>\n";
			echo "  <version>1</version>\n";
			foreach ($contacts as $c) {
				echo "  <Contact>\n";
				echo "    <FirstName>" . pb_x(pb_display_name($c)) . "</FirstName>\n";
				echo "    <LastName></LastName>\n";
				if (isset($c['phone_number']) && $c['phone_number'] !== '') {
					echo "    <Phone type=\"Work\">\n";
					echo "      <phonenumber>" . pb_x($c['phone_number']) . "</phonenumber>\n";
					echo "      <accountindex>0</accountindex>\n";
					echo "    </Phone>\n";
				}
				if (!empty($c['phone_number2'])) {
					echo "    <Phone type=\"Mobile\">\n";
					echo "      <phonenumber>" . pb_x($c['phone_number2']) . "</phonenumber>\n";
					echo "      <accountindex>0</accountindex>\n";
					echo "    </Phone>\n";
				}
				echo "  </Contact>\n";
			}
			echo "</AddressBook>\n";
			break;

		case 'fanvil':
			pb_emit_directory($contacts, 'FanvilIPPhoneDirectory');
			break;

		case 'snom':
			pb_emit_directory($contacts, 'SnomIPPhoneDirectory');
			break;

		case 'yealink':
		default:
			pb_emit_directory($contacts, 'YealinkIPPhoneDirectory');
			break;

	}
