# Instances

Taxanotes is one codebase that runs several sites. A site is an **instance**:
the unchanged platform (modules, recipes, theme) plus three per-instance
things, all in `instances/<slug>/`:

| File | In git | Holds |
|---|---|---|
| `instance.yml` | yes | everything that describes the instance and is not secret (below) |
| `.env` | **no** (`**/.env` is gitignored; the script keeps it mode 600) | hash salt, database credentials, site mail, optional admin password; template `instances/.env.example` |
| `data-imports/` | **no** (`instances/*/data-imports/`, `*.sql`, `*.sql.gz` are gitignored) | source data: dumps, spreadsheets |
| `assets/` | **no** (`instances/*/assets/` is gitignored: image rights are not cleared for committing) | `logo.png`: the site logo; the script copies it to `web/sites/<subdir>/files/instance/logo.png` and points the theme's `logo.path` at it. No file: the theme's own logo |

Nothing else is per instance: no per-site config export, no per-site module
or recipe, no fork. Site configuration lives in the instance's database (and
its backups). Recipes are additive and are applied at creation; anything an
instance later changes through the UI stays in its database.

## Creating or updating a local instance

```
scripts/new-instance.sh <slug>
```

The script reads `instance.yml` and `.env` and performs, idempotently, the
local ddev multisite setup: registers the hostnames (`.ddev/config.instances.yaml`,
generated for all instances; ddev is restarted only when it changed), maps them
in `web/sites/sites.php` (generated block), creates `web/sites/<sites_subdir>/`
with the generic `settings.php` (a verbatim copy of
`scripts/templates/settings.php`, identical for every instance) and a
`settings.local.php` generated from `instance.yml` + `.env`, creates the
database in the ddev db container, installs Drupal, sets the themes, applies
`recipes/taxanotes_front` (which pulls in `taxanotes_base`, and through it
`taxanotes_taxon` and `taxanotes_users`: the taxon type, the roles, the tree,
the import module, the site identity, then the front page, the About page,
the main menu and the taxon page blocks), installs the logo, runs the four
`taxanotes_import` migrations when `.env` names a Scratchpads database,
rebuilds caches and prints the URL. A second run on the same instance changes
nothing that is already in place: blocks and views are created only when
missing (an instance may move or edit them), the site texts are seeded again
from `instance.yml` (a key left out keeps what the site has); a changed
`instance.yml`/`.env` regenerates `settings.local.php` (a hand-written one,
like Myriatrix's, is left alone).

A Drupal 7 source must be reachable from the web container: the local
Scratchpads stack's MySQL is on the `scratchpads2_default` Docker network that
`.ddev/docker-compose.scratchpads.yaml` attaches the web container to; load the
instance's dump as its own database there (see the mormyrids report in
`docs/planning/reports/`).

## `instance.yml` schema

```yaml
slug: mormyrids                    # directory name under instances/; default for names below
site:
  name: 'Mormyridae - …'           # system.site:name (recipe input taxanotes_base.site_name)
  slogan: '…'                      # system.site:slogan (may be empty)
  theme: olivero                   # default theme (enabled and set by the script)
  admin_theme: claro               # administration theme
  # Site texts (HTML), recipe inputs of taxanotes_front -> taxanotes_front.settings,
  # editable afterwards at /admin/config/system/taxanotes-front. Each is
  # optional: a key left out keeps the site's current text (a generic one on
  # the first apply).
  front_title: 'Welcome, …'        # front page title; empty/omitted: "Welcome to <name>"
  front_intro: |                   # front page introduction (the page body of /front)
    <p>…</p>
  about_body: |                    # body of the placeholder About page (/about)
    <p>…</p>
  footer_text: |                   # footer text (licence, credits)
    <p>…</p>
hostnames:                         # per environment; the first of each list is the canonical
  ddev:                            # URL (https) and trusted_host_patterns are built from the list
    - mormyrids.taxanotes-dev.ddev.site   # of the environment a script runs in. A plain list is
  production:                      # read as the ddev list.
    - mormyrids.taxanotes.net
drupal:
  sites_subdir: mormyrids          # web/sites/<subdir> (default: slug); "default" = the ddev project's own site
  profile: standard                # install profile (default: standard)
  database:                        # the instance's own database name (default: slug; credentials in .env);
    ddev: mormyrids                # a scalar means the same name in every environment, a map one
    production: dmormyrids_1       # per environment (the hosting toolkit names them d<slug>_1)
source:                            # omit for an instance without a Scratchpads database
  type: scratchpads_d7
  vocabulary: mormyridae           # D7 vocabulary machine name -> $settings['taxanotes_import']['source_vocabulary']
  database:                        # D7 database name (credentials MIGRATE_DB_* in .env); scalar or
    ddev: mormyrids                # per environment like drupal.database (s_<slug> on the hosting
    production: s_mormyrids        # server, loaded by scripts/hosting/load-source-db.sh)
  unpublished_roots:               # root terms imported unpublished with their subtree
    - 'Deleted taxa'               #   -> $settings['taxanotes_import']['unpublished_roots']
```

Defaults are applied in one place, `scripts/lib/instance.php` (`get` prints
the effective value, for the ddev environment unless another is named), so
every consumer, the scripts and the generated files alike, sees the same
instance.

`site.mail` is deliberately not here: addresses are not committed; `.env`
`SITE_MAIL` carries it (default `webmaster@example.com` on ddev, where mail
goes to Mailpit; the hosting script seeds `noreply@<domain>` and warns about an
example address). The base recipe empties `update.settings:notification.emails`:
updates arrive as releases, nobody is mailed about them. A host without a
mailer (server04 today) sends no password resets either: give a user a
password with `drush user:password NAME PASS` or a one-time link with
`drush user:login --uri=https://<domain> NAME`.

## Environments: ddev and production

The committed model is environment-aware only where it has to be, in the
hostnames:

| | ddev (local, this script) | production (the toolkit host) |
|---|---|---|
| layout | one checkout, one ddev project, Drupal multisite: `web/sites/<sites_subdir>/` per instance, routed by `web/sites/sites.php` | **one checkout per instance** under `/var/www/<category>/<domain>/` (plan decision D7), each on its own `sites/default`, an Apache vhost per domain |
| hostnames | `hostnames.ddev` | `hostnames.production` |
| database names | `drupal.database.ddev`, `source.database.ddev` (a scalar counts for both) | `drupal.database.production` = `d<slug>_1`, `source.database.production` = `s_<slug>` (the toolkit's naming; the hosting script checks it) |
| `web/sites/sites.php`, `.ddev/config.instances.yaml` | generated from `hostnames.ddev` of every instance | **not used**: a single-site checkout needs no map; both files are ddev-only artifacts and stay committed for the local project |
| secrets | `instances/<slug>/.env` | `/var/www/<category>/<domain>/settings/instance.env`, same keys, created by the hosting script with random passwords |
| `settings.local.php` | `instance.php settings-local <yml> <.env> ddev`, one file | `instance.php settings-local <yml> <env> production settings.migrate.php` plus a hosting block (file paths, READ COMMITTED), with the `migrate` connection in `settings.migrate.php` from `instance.php settings-migrate` (the toolkit's `backup_db`/`restore_db` grep the credentials and expect one connection per file); the committed `web/sites/default/settings.php` is the site's `settings.php` |
| `trusted_host_patterns` | the ddev list | the production list; a dev hostname never reaches a production site |

So `hostnames.production` and the production database names are schema (the
hosting script reads them), while the generated multisite map is a
convenience of the local setup. A third instance copies this shape: both
lists, its own `sites_subdir`.

## Creating or updating a hosted instance

```
scripts/hosting/new-instance.sh <slug> <version> [--tarball FILE | --git URL [--ref REF]] [--category NN] [--reinstall]
scripts/hosting/load-source-db.sh <slug> <dump.sql.gz> [--replace]
```

Both run on the server (Rob's provision-site toolkit layout under
`/var/www/<category>/<domain>/`, commands `create_site_structure`,
`create_release`, `backup_db`, `deploy` on PATH). `new-instance.sh` is the
production counterpart of `scripts/new-instance.sh`: the same definition,
recipes and migrations, applied step by step and only where something is
missing. It creates the release from a `git archive` tarball (until the
repository has a remote the server can reach; `--git`/`--ref` go through the
toolkit's `create_release`), the site structure, `settings/instance.env`, the
database `d<slug>_1` and user `u<slug>_1`, a SELECT-only user on `s_<slug>`,
the two settings files, the vhost (HTTP to HTTPS, basic auth on the whole
site, HSTS) with a self-signed certificate that certbot replaces, the
htpasswd file with one user `<slug>` (password printed once), Drupal (only
into an empty database; `--reinstall` dumps with `backup_db` and installs
again), the themes, the recipe with the instance texts, the logo from
`settings/assets/logo.png`, the four migrations, `deploy <domain> live
<version>` and the `drush cron` crontab line. `load-source-db.sh` loads the
Scratchpads dump as `s_<slug>` beforehand (keep the dump 0600 and delete it
afterwards: it holds the users table). The headers of both scripts have the
details; the next release of an existing site is the same command with the
new version (the release step, `composer install`, `deploy`), everything
else is a no-op.

## Adding an instance

1. `mkdir instances/<slug>` and write `instance.yml` (copy one of the existing
   ones; pick unused hostnames).
2. If the taxa come from a Scratchpads database, load its dump into the local
   D7 MySQL as a database of its own and create a user for it.
3. `scripts/new-instance.sh <slug>` once: it creates `.env` with a random hash
   salt and stops short of the import; fill in `MIGRATE_DB_USER` /
   `MIGRATE_DB_PASSWORD` (and `SITE_MAIL`, `ADMIN_PASSWORD` if wanted).
4. `scripts/new-instance.sh <slug>` again: installs, applies recipes, imports.
5. `ddev drush --uri=https://<ddev hostname> php:script scripts/verify-instance.php -- <vocabulary>`
   compares every term's current field values with the D7 source and prints
   the content checksums (see "Verifying" below).
6. Commit `instances/<slug>/instance.yml`, `web/sites/sites.php` and
   `.ddev/config.instances.yaml` (and `web/sites/<subdir>/settings.php`, which
   is the unchanged template). Never `.env`, `settings.local.php`, `files/`,
   `assets/`, `data-imports/` or dumps (`.gitignore` covers
   `instances/*/assets/`, `instances/*/data-imports/`, `*.sql`, `*.sql.gz`).

## The front page

`recipes/taxanotes_front` (applied by the script) gives every instance the
same public face, in the spirit of the Scratchpads front page, filled with the
instance's values:

| Where (Olivero) | What | Source |
|---|---|---|
| page title + content of `/front` (`system.site:page.front`) | `front_title` ("Welcome to <name>" when empty), `front_intro` | `taxanotes_front.settings` |
| sidebar, front page only | *Find a taxon* (search to `/taxa`), *Browse the classification* (the lazily loaded tree, `node_tree`), *In numbers* (taxa in all and per rank) | blocks `taxanotes_front_search`, `block-node-tree-hierarchicaltaxontree`, `taxanotes_front_summary` |
| below the content, front page only | *Recently added taxa*, *Recently updated taxa* (10 each, with a link to `/taxa`) | view `taxanotes_recent` |
| footer | `footer_text` | block `taxanotes_front_text` |
| main menu | Home (standard profile), Taxa (view `taxa`), Classification (`/taxonomy-tree`), About (`/about`, `about_body`) | static links of `taxanotes_front` |
| taxon page (`/node/<nid>`) | authors under the name, rank, status, parent, vernacular names, protologue, description; then *Classification*: a link to the taxon in the tree and its child taxa | view display `node.taxon.default` (taxanotes_taxon), view `taxanotes_taxon_page` |

Blocks are placed in the instance's default theme (`site.theme`); the
regions above are Olivero's, any other theme gets them in `content`.
Literature, pages, media and biographies have no blocks yet: they come with
their migrations.

The script never installs over data: Drupal is installed only into an
**empty** database. A database with tables that does not bootstrap (db
container down, broken `settings.local.php`, PHP fatal) or whose install never
finished stops the run with the reason; `scripts/new-instance.sh <slug>
--reinstall` is the one way to drop the tables and install again. Before any
drush write it also checks that drush resolves the instance URL to
`web/sites/<sites_subdir>` (an unmapped hostname lands on `sites/default`).

## Verifying

`scripts/verify-instance.php` (run with `drush php:script`, needs the
`migrate` connection) prints, as JSON: the D7/D11 counts (terms, parents,
orphans, roots, users, roles, revisions per node, accepted and vernacular
names, weights, ranks, usages); a **full comparison of every term's current
values** (name, weight, parent, the ITIS fields, accepted name, reference,
page URL, description, vernacular-name count) against the D7 `field_data_*`
tables, the ones the Scratchpads site renders, listing every mismatch; and a
sha256 per content table (`node_field_data` without `vid`/`changed`, every
`node__field_*` table without `revision_id`) so a before/after of the Myriatrix
control is a checksum, not a count.

## Instances

| slug | site directory | hostnames (ddev / production) | source |
|---|---|---|---|
| `myriatrix` | `web/sites/default` (built by hand before this script; its `settings.local.php` is hand-written) | taxanotes-dev.ddev.site / myriatrix.taxanotes.net | D7 vocabulary `myriatrix`, database `myriatrix` (ddev) / `s_myriatrix` (production) |
| `mormyrids` | `web/sites/mormyrids` | mormyrids.taxanotes-dev.ddev.site / mormyrids.taxanotes.net | D7 vocabulary `mormyridae`, database `mormyrids` (ddev) / `s_mormyrids` (production) |
