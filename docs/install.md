# Install / deploy

One command installs or updates the whole app on a FusionPBX box: database
tables, the per-domain auth table, permissions, the menu item, and the web
files. Everything is idempotent, so re-running is safe.

## Fresh install

```bash
# clone (private repo: use a token or deploy key)
git clone https://github.com/andrewdunn358-dev/synphony-phonebook.git /opt/synphony-phonebook

# install
sudo bash /opt/synphony-phonebook/install.sh
```

Then **log out of the FusionPBX portal and back in** (permissions and the menu
load at login) and open **Apps → Phonebook**.

## Update an existing box

```bash
cd /opt/synphony-phonebook && git pull && sudo bash install.sh
```

## What the installer does

Runs, in order, the idempotent migrations in `sql/`:

1. `001_create_phonebook.sql` — `v_phonebook` contacts table (per-domain).
2. `002_create_phonebook_auth.sql` — `v_phonebook_auth` per-domain credential.
3. `004_add_permissions.sql` — grants: superadmin = all (incl. cross-tenant
   `phonebook_domain`); admin = own-domain management only.
4. `003_add_menu_item.sql` — adds the "Phonebook" menu item (superadmin +
   admin), placed alongside the other apps.

Then copies `app/phonebook/` into `<webroot>/app/phonebook/` and sets
ownership to `www-data`.

Overrides (optional environment variables):

- `FUSIONPBX_ROOT` (default `/var/www/fusionpbx`)
- `FUSIONPBX_DB` (default `fusionpbx`)

## Upgrade safety

Because the app lives in this repo, a FusionPBX upgrade that wipes the app
directory is recovered by re-running the installer (`git pull` + `install.sh`).
The tables are also declared in `app/phonebook/app_config.php`, so FusionPBX's
own schema tooling recognises them.

## Per-tenant setup (after install)

For each domain that should serve a phonebook to its handsets:

1. In the portal, switch to that domain, open **Apps → Phonebook**.
2. Add contacts (name + number).
3. Click **Phonebook access → Generate** to mint that domain's login and get
   its remote-phonebook URL.
4. Put the URL + username + password into that domain's handset provisioning.
