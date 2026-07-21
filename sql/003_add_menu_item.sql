-- ============================================================================
-- synphony-phonebook : migration 003
-- Adds the "Phonebook" item to the FusionPBX menu, in the same section as the
-- other apps, and makes it visible to the superadmin group.
--
-- Portable + idempotent: it reads the target menu and parent from the existing
-- Call Block menu row (so it works on any install without hard-coded menu
-- UUIDs), and it will not create a duplicate if run twice (guarded on the
-- fixed menu-item `uuid`, which matches the phonebook_view permission's menu
-- uuid in app_config.php).
--
-- Run as the fusionpbx DB owner:
--     sudo -u postgres psql -d fusionpbx -f 003_add_menu_item.sql
--
-- After running, users must log out and back in (the menu is built at login).
--
-- NOTE on groups: grants menu visibility to `superadmin` and `admin`, matching
-- the permissions granted in migration 004 (admin manages their own domain;
-- superadmin manages across all). `user` is intentionally excluded.
-- ============================================================================

BEGIN;

WITH cb AS (
    SELECT menu_uuid, menu_item_parent_uuid
    FROM v_menu_items
    WHERE menu_item_link ILIKE '%call_block/call_block.php%'
    LIMIT 1
),
new_item AS (
    INSERT INTO v_menu_items (
        menu_item_uuid, menu_uuid, menu_item_parent_uuid, uuid,
        menu_item_title, menu_item_link, menu_item_icon, menu_item_category,
        menu_item_protected, menu_item_order
    )
    SELECT
        gen_random_uuid(), cb.menu_uuid, cb.menu_item_parent_uuid,
        'b2d4f6a8-1c3e-4570-8a9b-0d1e2f3a4b50',
        'Phonebook', '/app/phonebook/phonebook.php', 'address-book', 'internal', 'false', 17
    FROM cb
    WHERE NOT EXISTS (
        SELECT 1 FROM v_menu_items WHERE uuid = 'b2d4f6a8-1c3e-4570-8a9b-0d1e2f3a4b50'
    )
    RETURNING menu_item_uuid, menu_uuid
)
INSERT INTO v_menu_item_groups (menu_item_group_uuid, menu_uuid, menu_item_uuid, group_name, group_uuid)
SELECT gen_random_uuid(), n.menu_uuid, n.menu_item_uuid, g.group_name, g.group_uuid
FROM new_item n
JOIN v_groups g ON g.group_name IN ('superadmin', 'admin') AND g.domain_uuid IS NULL;

COMMIT;
