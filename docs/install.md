# Install / deploy

> Placeholder — filled in once the app is built. This documents how to deploy
> the phonebook app onto a FusionPBX box in an upgrade-safe way.

## Overview (planned)

1. Clone this repo somewhere on the box (e.g. `/opt/synphony-phonebook`).
2. Apply the database migration in `sql/` as the `fusionpbx` DB owner.
3. Symlink or copy `app/phonebook` into the FusionPBX app directory
   (`/var/www/fusionpbx/app/phonebook`) so it appears in the portal.
4. Run the FusionPBX app-defaults / upgrade step to register permissions.
5. Add the remote-phonebook URL to each vendor's provisioning template.
6. Redeploy after any FusionPBX upgrade with `git pull` + re-copy.

Nothing here is run yet — see README build sequence. All commands will be
written out and explained before being run on the live box.
