-- ============================================================================
-- synphony-phonebook : migration 002
-- Per-domain authentication for the XML endpoint.
--
-- One credential (username + bcrypt password hash) per domain. The phone
-- presents these as HTTP Basic auth; the endpoint looks the username up here,
-- verifies the password, and serves ONLY that credential's domain. This is the
-- tenant-isolation boundary — a phone can't reach another domain's book
-- without that domain's credential.
--
-- Run as the fusionpbx DB owner:
--     sudo -u postgres psql -d fusionpbx -f 002_create_phonebook_auth.sql
--
-- IDEMPOTENT and creates ONE new table. Touches nothing existing.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS v_phonebook_auth (
    phonebook_auth_uuid  uuid         NOT NULL DEFAULT gen_random_uuid(),
    domain_uuid          uuid         NOT NULL,
    username             text         NOT NULL,
    password_hash        text,   -- legacy/optional; readable password added in migration 005
    enabled              boolean      NOT NULL DEFAULT true,
    insert_date          timestamptz  NOT NULL DEFAULT now(),
    update_date          timestamptz,
    CONSTRAINT v_phonebook_auth_pkey PRIMARY KEY (phonebook_auth_uuid),
    -- usernames are globally unique (we look a phone up by username)
    CONSTRAINT v_phonebook_auth_username_key UNIQUE (username),
    -- one credential per domain
    CONSTRAINT v_phonebook_auth_domain_key UNIQUE (domain_uuid),
    CONSTRAINT v_phonebook_auth_domain_fkey
        FOREIGN KEY (domain_uuid)
        REFERENCES v_domains (domain_uuid)
        ON DELETE CASCADE
);

ALTER TABLE v_phonebook_auth OWNER TO fusionpbx;

COMMIT;

-- Passwords are stored as bcrypt hashes. They can be generated in-database with
-- pgcrypto's crypt()/gen_salt('bf') (PHP's password_verify() reads bcrypt fine),
-- or by the GUI later. See docs/install.md for seeding a test credential.
