<?php
/*
	synphony-phonebook : phonebook data class

	Holds server-side operations that must stay domain-scoped. Deletion here
	always constrains to the caller's current domain (unless they hold the
	phonebook_domain superadmin permission), so a crafted request cannot remove
	another tenant's rows.
*/

if (!class_exists('phonebook')) {
	class phonebook {

		private $database;
		private $domain_uuid;

		public function __construct() {
			$this->database = new database;
			$this->domain_uuid = $_SESSION['domain_uuid'] ?? null;
		}

		/**
		 * Delete phonebook contacts by uuid, scoped to the current domain.
		 * @param array $records  list of phonebook_uuid strings
		 */
		public function delete($records) {

			//permission
			if (!permission_exists('phonebook_delete')) {
				return;
			}

			//normalise + validate the incoming uuids
			if (!is_array($records)) {
				return;
			}
			$uuids = [];
			foreach ($records as $value) {
				//accept both ['uuid'=>x,'checked'=>true] and plain uuid strings
				if (is_array($value)) {
					if (empty($value['checked']) || $value['checked'] !== 'true') {
						continue;
					}
					$candidate = $value['uuid'] ?? '';
				} else {
					$candidate = $value;
				}
				if (is_uuid($candidate)) {
					$uuids[] = $candidate;
				}
			}
			if (empty($uuids)) {
				return;
			}

			//build a parameterised IN() list (never string-concatenate the values)
			$in = [];
			$parameters = [];
			foreach ($uuids as $i => $u) {
				$key = 'u'.$i;
				$in[] = ':'.$key;
				$parameters[$key] = $u;
			}

			$sql = "delete from v_phonebook "
				. "where phonebook_uuid in (".implode(', ', $in).") ";

			//domain scope: only superadmins with phonebook_domain may cross domains
			if (!permission_exists('phonebook_domain')) {
				$sql .= "and domain_uuid = :domain_uuid ";
				$parameters['domain_uuid'] = $this->domain_uuid;
			}

			$this->database->execute($sql, $parameters);
		}

	}
}
?>
