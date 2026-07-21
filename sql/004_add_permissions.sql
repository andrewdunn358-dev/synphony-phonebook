-- ============================================================================
-- synphony-phonebook : migration 004
-- Grants the phonebook permissions to the built-in groups, deterministically
-- (so we do not depend on FusionPBX's "App Defaults" run).
--
-- Model:
--   superadmin -> all six permissions, INCLUDING phonebook_domain
--                 (phonebook_domain = "see/manage across all domains").
--   admin      -> management of THEIR OWN domain only: view/add/edit/delete/
--                 access. Deliberately NOT phonebook_domain, so a domain admin
--                 can never see another tenant's book.
--   user       -> nothing (regular extension users don't manage the phonebook;
--                 they just receive it on their handset).
--
-- Run as the fusionpbx DB owner:
--     sudo -u postgres psql -d fusionpbx -f 004_add_permissions.sql
--
-- Idempotent (guarded by NOT EXISTS). After running, affected users must log
-- out and back in for the new permissions to load into their session.
-- ============================================================================

BEGIN;

-- superadmin: all six (cross-tenant)
INSERT INTO v_group_permissions
    (group_permission_uuid, domain_uuid, permission_name, permission_protected, permission_assigned, group_name, group_uuid)
SELECT gen_random_uuid(), NULL, p.permission_name, 'false', 'true', g.group_name, g.group_uuid
FROM v_groups g
CROSS JOIN (VALUES
    ('phonebook_view'), ('phonebook_add'), ('phonebook_edit'),
    ('phonebook_delete'), ('phonebook_access'), ('phonebook_domain')
) AS p(permission_name)
WHERE g.group_name = 'superadmin' AND g.domain_uuid IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM v_group_permissions x
      WHERE x.permission_name = p.permission_name
        AND x.group_name = g.group_name
        AND x.domain_uuid IS NULL
  );

-- admin: own-domain management only (NO phonebook_domain -> isolation preserved)
INSERT INTO v_group_permissions
    (group_permission_uuid, domain_uuid, permission_name, permission_protected, permission_assigned, group_name, group_uuid)
SELECT gen_random_uuid(), NULL, p.permission_name, 'false', 'true', g.group_name, g.group_uuid
FROM v_groups g
CROSS JOIN (VALUES
    ('phonebook_view'), ('phonebook_add'), ('phonebook_edit'),
    ('phonebook_delete'), ('phonebook_access')
) AS p(permission_name)
WHERE g.group_name = 'admin' AND g.domain_uuid IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM v_group_permissions x
      WHERE x.permission_name = p.permission_name
        AND x.group_name = g.group_name
        AND x.domain_uuid IS NULL
  );

COMMIT;
