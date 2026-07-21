-- ============================================================================
-- synphony-phonebook : migration 003
-- Adds the "Phonebook" item to the FusionPBX menu, alongside the other apps,
-- visible to superadmin + admin, with its title in the portal languages.
--
-- IMPORTANT: FusionPBX reads a menu item's displayed title from
-- v_menu_languages (per portal language), NOT from v_menu_items.menu_item_title.
-- If the row for the user's language is missing, the item is hidden. So we
-- insert the title for both en-us and en-gb.
--
-- Portable: reads the target menu + parent from the existing Call Block row, so
-- no install-specific UUIDs are hard-coded.
--
-- Self-repairing + idempotent: three guarded statements. Re-running tops up any
-- missing group or language rows even if the menu item already exists.
--
-- Run as the fusionpbx DB owner:
--     sudo -u postgres psql -d fusionpbx -f 003_add_menu_item.sql
--
-- After running, users must log out and back in (the menu is built at login).
-- ============================================================================

BEGIN;

-- 1. The menu item, in the same menu/section as Call Block. Idempotent
--    (guarded on the fixed `uuid`, which matches the phonebook_view menu uuid
--    in app_config.php).
INSERT INTO v_menu_items (
    menu_item_uuid, menu_uuid, menu_item_parent_uuid, uuid,
    menu_item_title, menu_item_link, menu_item_icon, menu_item_category,
    menu_item_protected, menu_item_order
)
SELECT
    gen_random_uuid(), cb.menu_uuid, cb.menu_item_parent_uuid,
    'b2d4f6a8-1c3e-4570-8a9b-0d1e2f3a4b50',
    'Phonebook', '/app/phonebook/phonebook.php', 'address-book', 'internal', 'false', 17
FROM (
    SELECT menu_uuid, menu_item_parent_uuid
    FROM v_menu_items
    WHERE menu_item_link ILIKE '%call_block/call_block.php%'
    LIMIT 1
) cb
WHERE NOT EXISTS (
    SELECT 1 FROM v_menu_items WHERE uuid = 'b2d4f6a8-1c3e-4570-8a9b-0d1e2f3a4b50'
);

-- 2. Group visibility: superadmin + admin. Idempotent per group.
INSERT INTO v_menu_item_groups (menu_item_group_uuid, menu_uuid, menu_item_uuid, group_name, group_uuid)
SELECT gen_random_uuid(), mi.menu_uuid, mi.menu_item_uuid, g.group_name, g.group_uuid
FROM v_menu_items mi
JOIN v_groups g ON g.group_name IN ('superadmin', 'admin') AND g.domain_uuid IS NULL
WHERE mi.uuid = 'b2d4f6a8-1c3e-4570-8a9b-0d1e2f3a4b50'
  AND NOT EXISTS (
      SELECT 1 FROM v_menu_item_groups x
      WHERE x.menu_item_uuid = mi.menu_item_uuid AND x.group_name = g.group_name
  );

-- 3. Displayed title per language (this is what actually makes it visible).
--    Idempotent per language.
INSERT INTO v_menu_languages (menu_language_uuid, menu_uuid, menu_item_uuid, menu_language, menu_item_title)
SELECT gen_random_uuid(), mi.menu_uuid, mi.menu_item_uuid, l.lang, 'Phonebook'
FROM v_menu_items mi
CROSS JOIN (VALUES ('en-us'), ('en-gb')) AS l(lang)
WHERE mi.uuid = 'b2d4f6a8-1c3e-4570-8a9b-0d1e2f3a4b50'
  AND NOT EXISTS (
      SELECT 1 FROM v_menu_languages x
      WHERE x.menu_item_uuid = mi.menu_item_uuid AND x.menu_language = l.lang
  );

COMMIT;
