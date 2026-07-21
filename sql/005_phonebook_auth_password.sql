-- ============================================================================
-- synphony-phonebook : migration 005
-- Adds a readable `password` column to v_phonebook_auth so the access page can
-- always show a ready-to-paste URL (with credentials) for each phone make.
--
-- Consistent with FusionPBX, which stores SIP/device provisioning passwords in
-- plain text in the same database. These are low-value, read-only phonebook
-- logins served only over HTTPS.
--
-- The old `password_hash` column is left in place (nullable, unused) for
-- backward compatibility; the endpoint accepts either.
--
-- Run as the fusionpbx DB owner:
--     sudo -u postgres psql -d fusionpbx -f 005_phonebook_auth_password.sql
--
-- Idempotent.
-- ============================================================================

BEGIN;
ALTER TABLE v_phonebook_auth ADD COLUMN IF NOT EXISTS password text;
COMMIT;
