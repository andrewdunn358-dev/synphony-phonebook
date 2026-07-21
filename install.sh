#!/usr/bin/env bash
#
# synphony-phonebook installer
# ----------------------------------------------------------------------------
# One-shot, idempotent install/upgrade of the phonebook app onto a FusionPBX
# box. Safe to re-run: every step is guarded, so running it again just brings
# the box up to date.
#
# Usage (as root, from anywhere):
#     git clone https://github.com/andrewdunn358-dev/synphony-phonebook.git /opt/synphony-phonebook
#     sudo bash /opt/synphony-phonebook/install.sh
#
# To update later:
#     cd /opt/synphony-phonebook && git pull && sudo bash install.sh
#
# Overrides (optional):
#     FUSIONPBX_ROOT=/var/www/fusionpbx   FUSIONPBX_DB=fusionpbx
# ----------------------------------------------------------------------------
set -euo pipefail

WEBROOT="${FUSIONPBX_ROOT:-/var/www/fusionpbx}"
DB="${FUSIONPBX_DB:-fusionpbx}"
REPO_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=============================================="
echo " synphony-phonebook install"
echo "   web root : $WEBROOT"
echo "   database : $DB"
echo "   repo     : $REPO_DIR"
echo "=============================================="

# --- sanity checks ----------------------------------------------------------
if [ "$(id -u)" -ne 0 ]; then
    echo "!! Please run as root (sudo)." >&2
    exit 1
fi
if [ ! -d "$WEBROOT/app" ]; then
    echo "!! $WEBROOT/app not found - is this a FusionPBX box?" >&2
    echo "   Set FUSIONPBX_ROOT if FusionPBX lives elsewhere." >&2
    exit 1
fi
if ! command -v psql >/dev/null 2>&1; then
    echo "!! psql not found on PATH." >&2
    exit 1
fi

# --- 1. database migrations (each is idempotent) ----------------------------
# Order: tables first, then permissions, then the menu item.
for f in \
    001_create_phonebook.sql \
    002_create_phonebook_auth.sql \
    004_add_permissions.sql \
    003_add_menu_item.sql
do
    echo "==> applying sql/$f"
    sudo -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q -f "$REPO_DIR/sql/$f"
done

# --- 2. deploy the application files -----------------------------------------
echo "==> deploying app files to $WEBROOT/app/phonebook"
mkdir -p "$WEBROOT/app/phonebook"
cp -r "$REPO_DIR/app/phonebook/." "$WEBROOT/app/phonebook/"
chown -R www-data:www-data "$WEBROOT/app/phonebook"

echo
echo "=============================================="
echo " Done."
echo "  - tables, permissions and menu item are in place"
echo "  - app files deployed"
echo
echo " NEXT: log out of the FusionPBX portal and back in"
echo "       (menu + permissions load at login), then open"
echo "       Apps -> Phonebook."
echo "=============================================="
