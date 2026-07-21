-- ============================================================================
-- synphony-phonebook : migration 006
-- Make v_phonebook_auth.password_hash nullable.
--
-- Migration 002 created password_hash as NOT NULL. Since migration 005 we store
-- the login in the readable `password` column and no longer write
-- password_hash, so new rows would violate the NOT NULL constraint and the
-- insert would fail (silently, because the app's execute() catches the DB
-- error). Dropping NOT NULL lets the credential save.
--
-- Run as the fusionpbx DB owner:
--     sudo -u postgres psql -d fusionpbx -f 006_phonebook_auth_password_hash_nullable.sql
--
-- Idempotent (dropping NOT NULL on an already-nullable column is a no-op).
-- ============================================================================

BEGIN;
ALTER TABLE v_phonebook_auth ALTER COLUMN password_hash DROP NOT NULL;
COMMIT;
