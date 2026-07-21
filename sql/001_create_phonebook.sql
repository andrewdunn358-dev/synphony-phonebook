-- ============================================================================
-- synphony-phonebook : migration 001
-- Creates the per-domain shared phonebook table.
--
-- Run as the fusionpbx database owner, e.g.:
--     sudo -u postgres psql -d fusionpbx -f 001_create_phonebook.sql
--
-- This is READ-SAFE to review and IDEMPOTENT: it uses IF NOT EXISTS, so
-- running it twice does nothing the second time. It creates ONE new table and
-- some indexes. It does NOT touch any existing FusionPBX table.
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- v_phonebook : one row per contact, owned by a single domain.
--
-- Isolation: every row carries domain_uuid. The XML endpoint only ever selects
-- rows WHERE domain_uuid = <the domain the caller's token maps to>, so one
-- tenant can never see another's entries.
--
-- Simple by design: name + number is the core. contact_organization and a
-- second number are optional. `extra` (jsonb) holds any per-client custom
-- fields (e.g. a garage's vehicle details) without a schema change and without
-- affecting other tenants. Handsets only ever render name + number(s); `extra`
-- is for the GUI and future features (caller-ID name, screen-pop), not the
-- phone directory.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v_phonebook (
    phonebook_uuid       uuid         NOT NULL DEFAULT gen_random_uuid(),
    domain_uuid          uuid         NOT NULL,
    contact_name         text         NOT NULL,
    contact_organization text,
    phone_number         text         NOT NULL,
    phone_number2        text,
    extra                jsonb,
    enabled              boolean      NOT NULL DEFAULT true,
    insert_date          timestamptz  NOT NULL DEFAULT now(),
    update_date          timestamptz,
    CONSTRAINT v_phonebook_pkey PRIMARY KEY (phonebook_uuid),
    CONSTRAINT v_phonebook_domain_fkey
        FOREIGN KEY (domain_uuid)
        REFERENCES v_domains (domain_uuid)
        ON DELETE CASCADE
);

-- Fast per-domain listing (the GUI and the XML feed both filter by domain).
CREATE INDEX IF NOT EXISTS v_phonebook_domain_idx
    ON v_phonebook (domain_uuid);

-- Supports future inbound caller-ID lookups (match an incoming number to a name).
CREATE INDEX IF NOT EXISTS v_phonebook_number_idx
    ON v_phonebook (domain_uuid, phone_number);

-- Hand ownership to the FusionPBX application role. The portal/app connects to
-- PostgreSQL as `fusionpbx` (confirmed: it owns v_domains and the other v_*
-- tables), so the new table must be owned by the same role or the GUI cannot
-- read/write it. As owner, that role has all privileges — no separate GRANT
-- is needed.
ALTER TABLE v_phonebook OWNER TO fusionpbx;

COMMIT;

-- ---------------------------------------------------------------------------
-- NOTES
--
-- * gen_random_uuid() is a core function in PostgreSQL 13+. If this box runs an
--   older PostgreSQL and the migration errors on that default, either run
--   `CREATE EXTENSION IF NOT EXISTS pgcrypto;` first, or remove the DEFAULT on
--   phonebook_uuid — the application supplies the UUID itself, so the column
--   does not strictly need a database-side default.
--
-- * The per-domain authentication credential (the token the phones present) is
--   deliberately NOT in this migration. It lands in migration 002 alongside the
--   XML endpoint, so this first step is just the contact data and easy to review.
--
-- * This table will also be registered in the FusionPBX app manifest
--   (app_config.php) when the app is built, so FusionPBX's own upgrade tooling
--   is aware of it.
-- ---------------------------------------------------------------------------
