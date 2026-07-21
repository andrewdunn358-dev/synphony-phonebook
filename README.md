# fusionpbx-app-phonebook

A multi-tenant, multi-vendor **remote phonebook** for FusionPBX. Provides a
per-domain shared address book that physical desk phones fetch over HTTPS as a
vendor-specific XML directory, plus a lightweight admin page inside the
FusionPBX portal for managing entries.

Built deliberately to replace the older community phonebook app (which was
flagged for SQL-injection and XSS issues) with a version that is secure by
design and survives FusionPBX upgrades.

> Status: **design / pre-build.** This README is the living design document.
> Nothing here should be deployed to a production box until the recon step is
> complete and the code has been reviewed.

---

## Goals

- **Available to every domain** on the FusionPBX box.
- **Strict per-tenant isolation** — a domain's phones only ever see that
  domain's contacts. No tenant can read another tenant's book.
- **Multi-vendor** — one endpoint serves the correct XML dialect to Yealink,
  Grandstream, and Fanvil/Snom handsets (extensible to more makes).
- **GUI-managed** — add/edit/delete contacts from the FusionPBX web portal.
- **Simple data model** — name + number to start; extensible without a rebuild.
- **Secure** — no SQL injection or XSS surface; credentials never in clear text.
- **Upgrade-safe** — lives in a repo so it can be redeployed with `git pull`
  after a FusionPBX upgrade, instead of being wiped like the old app was.

---

## Architecture

```
  Desk phone (Yealink / Grandstream / Fanvil / Snom)
        |  HTTPS GET  https://<cert-covered-host>/app/phonebook/xml.php
        |             (per-domain username:password in the URL / basic auth)
        v
  xml.php  ── authenticates token ──► resolves domain_uuid
        |   selects vendor format (User-Agent or &type=)
        |   reads ONLY that domain's rows via prepared statement
        v
  Vendor-specific XML  ──►  rendered in the handset's address book

  FusionPBX portal  ──►  app/phonebook (admin UI)  ──►  same table
```

- **Auth = the security boundary.** The per-domain credential identifies the
  domain. The endpoint serves the book for *that credential's* domain and no
  other. MAC-based identification was rejected because MAC addresses are not
  secret and could be guessed to pull another tenant's book.
- **Vendor detection = a separate concern.** Chosen from the phone's
  User-Agent, or forced by a `&type=` parameter set in each vendor's
  provisioning template. Adding a new make = adding one more formatter.

### New-domain onboarding

A new domain automatically *can* use the phonebook (the endpoint serves any
domain by `domain_uuid`), and it starts with its **own empty book** — it never
inherits another tenant's contacts. Before its phones can fetch anything it
needs a credential row in `v_phonebook_auth`.

Decision: **generate-on-demand from the GUI** (not automatic on domain
creation). The phonebook page, for a domain with no credential yet, offers a
one-click "Generate phonebook access" that mints the login and shows the
provisioning URL. This avoids minting credentials for domains that never use
the feature.

---

## Data model (draft — to be finalised after recon)

A single table, isolated per domain. Core is name + number; a JSON column
carries any per-client extras (e.g. a garage's custom fields) without schema
changes and without affecting other tenants.

| column          | type        | notes                                             |
|-----------------|-------------|---------------------------------------------------|
| phonebook_uuid  | uuid (PK)   |                                                   |
| domain_uuid     | uuid (FK)   | tenant isolation — indexed                        |
| contact_name    | text        | display name shown on the handset                 |
| contact_org     | text, null  | optional company/organisation                     |
| phone_number    | text        | primary number                                    |
| phone_number2   | text, null  | optional second number (e.g. mobile)              |
| extra           | jsonb, null | per-client custom fields (garage vehicle details) |
| enabled         | boolean     | soft show/hide                                    |
| insert_date     | timestamptz |                                                   |
| update_date     | timestamptz |                                                   |

Handsets can only render **name + number(s)** — this is a hard limit of the
remote-phonebook XML across all vendors. The `extra` field is stored for the
GUI and for future features, not for the phone directory.

### Related but separate: "details when a customer calls"

Showing a caller's account/vehicle/history is **not** the phonebook — the
handset has nowhere to display it. Two separate capabilities, fed from the
same data:

1. **Inbound caller-ID name lookup** (phones can do this): match the incoming
   number against the book and push a name to the phone screen, so it shows
   "John Smith" instead of a raw number.
2. **Screen-pop to a computer/CRM** (separate build): a popup with full
   customer detail, driven off the call event, not rendered by the handset.

Parked as future work; the data model already supports both.

---

## Security design

Written to avoid the exact issues flagged in the old community app.

- **No string-built SQL.** All database access uses PDO prepared statements
  with bound parameters (consistent with FusionPBX's own `database` class).
- **Output encoding everywhere.** Every value written into XML or the admin
  HTML is escaped with the correct encoder, so contact data cannot break the
  document or inject script.
- **Input whitelisting.** The vendor/`type` parameter must match a known set;
  the domain token must match a strict pattern; anything else is rejected.
- **Hashed per-domain tokens.** Credentials are stored hashed, compared in
  constant time, and only ever transmitted over HTTPS.
- **Portal-native GUI security.** The admin page uses FusionPBX's existing
  login, permission checks, and CSRF token — no bespoke auth.
- **Least privilege.** The read-only XML endpoint can run under a read-only
  database role.
- **No secrets in git.** Config (tokens, DB creds, hostnames) is excluded via
  `.gitignore` and never committed.

---

## Reachability / TLS

Phones use this **remotely**, so credentials must not travel in clear text.
The endpoint must be reached over **HTTPS on a hostname covered by the TLS
certificate** — not the bare IP (the wildcard `*.voip.synthesis-it.co.uk`
cert does not cover a bare IP; this is the same gap currently affecting the
Active Calls page). Resolving it once benefits both features.

---

## Proposed repo layout

```
fusionpbx-app-phonebook/
├── README.md                 # this document
├── LICENSE
├── .gitignore                # excludes any local config / secrets
├── sql/
│   └── 001_create_phonebook.sql
├── app/
│   └── phonebook/            # drops into FusionPBX /app
│       ├── xml.php           # the vendor-aware XML endpoint
│       ├── phonebook.php     # admin list view
│       ├── phonebook_edit.php
│       ├── app_config.php    # FusionPBX app manifest / permissions
│       └── resources/
│           └── classes/
│               └── phonebook.php   # data class (prepared statements)
├── provision/                # snippets to add the URL to each vendor template
└── docs/
    └── install.md
```

---

## Build sequence

1. **Recon** (read-only) — enumerate the handset fleet, confirm web root and
   nginx/TLS layout, capture a sample of the existing working phonebook XML.
2. **Data model** — finalise and create the table.
3. **XML endpoint** — build + test one vendor formatter, then the rest.
4. **GUI app** — portal-managed add/edit/delete.
5. **Provisioning + TLS** — push the URL to the fleet; resolve HTTPS hostname.
6. **Test per vendor, verify, harden.**

---

## Working model / deployment

- No direct SSH into the PBX; commands are written here and run on the box by
  the operator, output pasted back.
- Deploy by `git clone` / `git pull` of this repo into the FusionPBX app
  directory — upgrade-safe redeploy.
- All work targets the live box `synphony` (188.165.112.169). Changes are
  explained before they are run; nothing risky is batched without a plan.
