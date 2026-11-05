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
`taxanotes_taxon`, `taxanotes_users` and `taxanotes_literature`: the taxon
type, the roles, the literature (bibcite references linked to taxa, the
Literature page and blocks), the tree, the import module, the site identity,
then the front page, the About page, the main menu and the taxon page
blocks), sets the themes and applies the theme's own recipe when it has one
(`recipes/taxanotes_classic`, see "The classic view"), installs the logo, runs the eleven `taxanotes_import` migrations
(taxa, then literature, then the images and the species pages) when `.env`
names a Scratchpads database, rebuilds caches and prints the URL. A second run on the same instance changes
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

The D7 files directory of an instance (attachments, images) is a per-developer
bind mount, not committed: copy
`.ddev/docker-compose.scratchpads-files.local.yaml.dist` to
`.ddev/docker-compose.scratchpads-files.local.yaml` (gitignored; the `.dist`
mounts the sibling `../../scratchpads-d7/data/files` checkout of Myriatrix at
`/mnt/scratchpads/myriatrix/files`, add a volume line per instance whose files
you have), `ddev restart`, and point `source.files.ddev` in `instance.yml` (or
`SOURCE_FILES_DIR` in `.env`) at the container path. Without the file the
instance scripts skip the file and image migrations and say so.

## `instance.yml` schema

```yaml
slug: mormyrids                    # directory name under instances/; default for names below
site:
  name: 'Mormyridae - …'           # system.site:name (recipe input taxanotes_base.site_name)
  slogan: '…'                      # system.site:slogan (may be empty)
  theme: taxanotes_classic         # default theme (enabled and set by the script): the
                                   # classic view, or olivero (the alternative)
  colour: '047DB8'                 # accent colour of the classic view, six hex digits (the
                                   # Drupal 7 site's scratchpads_colour); default 047DB8
  front_first_column: page         # node type listed in the classic front page's first
                                   # column (Drupal 7 front_page_block_1_node_type: page or
                                   # blog); default page, the fallback while the type is absent
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
  files:                           # optional: the D7 public files directory as PHP reaches it in
    ddev: /mnt/scratchpads/myriatrix/files   # this environment -> $settings['taxanotes_import']['source_files_dir'];
                                   # the literature attachments and the images are copied from it
                                   # (taxanotes_d7_file, taxanotes_d7_media_image). .env SOURCE_FILES_DIR
                                   # overrides it. Neither set: the scripts skip those two migrations with
                                   # a line saying so; references and species pages import without them.
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
again), `deploy <domain> live <version>` (the toolkit's `backup_db` dump,
docroot flip, php-fpm reload, `updb`, `cr`), then the themes, the recipe
with the instance texts, the logo from `settings/assets/logo.png`, the
eleven migrations (files and images only when `instance.env`
`SOURCE_FILES_DIR` names the server's copy of the D7 files), a cache rebuild
and the `drush cron` crontab line. `load-source-db.sh` loads the Scratchpads
dump as `s_<slug>` beforehand (keep the dump 0600 and delete it afterwards:
it holds the users table). The headers of both scripts have the details.
The next release of an existing site is the same command with the new
version: the release step, `composer install`, `deploy` (its dump is the
pre-upgrade state, the one to `restore_db` with a rollback to the previous
release), then the recipe installs what the new release adds and the new
migrations run, with the site serving the new code throughout; everything
already in place is a no-op.

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
| taxon page (`/node/<nid>`) | authors under the name, rank, status, parent, vernacular names, protologue (a link to the reference entity), description; then *Classification*: a link to the taxon in the tree and its child taxa; then *Literature*: the references that name the taxon | view display `node.taxon.default` (taxanotes_taxon, taxanotes_literature), views `taxanotes_taxon_page`, `taxanotes_literature` |
| literature (`/literature`, main menu *Literature*) | the references of the instance: title, authors, year, type, taxa; filters on title, year, type, taxon and author (autocompletes) | view `taxanotes_literature` (recipe `taxanotes_literature`) |
| front page, below | *Recently added literature* beside the recent taxa | block `views_block:taxanotes_literature-recent` |

Blocks are placed in the instance's default theme (`site.theme`); the
regions above are Olivero's, the classic theme's are named alike. Pages,
media and biographies have no blocks yet: they come with their migrations.

## The classic view

`site.theme: taxanotes_classic` (the three pilots) gives the instance the
Scratchpads (Drupal 7) front end: `web/themes/custom/taxanotes_classic`, a
theme of its own (no base theme, the Starterkit template set, the
Scratchpads GPL-2 stylesheets ported), plus `recipes/taxanotes_classic`,
which the scripts apply after `taxanotes_front` whenever
`recipes/<site.theme>/recipe.yml` exists. The script sets the themes
**after** the front recipe: the recipe places its blocks in the default
theme of the moment (Olivero on a fresh install), and installing another
theme copies those blocks into it (`block_theme_initialize()`, ids
`<theme>_<block id>`, regions of the same name), so an instance that moves
from Olivero to the classic view and one built fresh with it have the same
blocks, and the Olivero ones stay as they were: `site.theme: olivero`
still gives the Olivero front page.

| Where (classic) | What | Source |
|---|---|---|
| header (accent colour) | logo (60 px) + site name + slogan; the search box with *All / Taxonomy*; the tabbed main menu (stacked behind a *Menu* button on a phone) | branding block; block `taxanotes_front_header_search` → `/find` (Taxonomy: `/taxa?name=`; All: a results page across taxa, literature, species pages, pages) |
| slide top | the account links (*Log in*) as a tab on the dark strip | account menu block |
| front page | welcome title + text (4 columns) beside the slideshow (8; the four newest images, thumbnails as a pager; absent without images); then *Recent Pages* / *Recently added literature* / *Recently added taxon descriptions*, three items each, "None yet." when empty | `taxanotes_front.settings`, view `taxanotes_slideshow` (image styles 619×463, 154×115), block `taxanotes_front_recent` (first column's node type from `site.front_first_column` → recipe input `taxanotes_classic.first_column` → the block setting, `page` by default, falls back to page when the type is absent) |
| taxon page | the name with the authors as a subtitle; tabs *Overview* (the fields as a stripy group, the classification block) / *Descriptions* / *Images* / *Literature*, a tab only where its block has content; the classification tree in the grey sidebar | `js/classic.js` builds the tabs from the content blocks `taxanotes_classic_preprocess_block()` marks; block `taxanotes_classic_taxon_tree` |
| footer (grey) | the main menu's links inline, the `footer_text` licence line, "Powered by Drupal" | blocks `taxanotes_classic_footer_menu`, `taxanotes_front_text`, `powered` |
| colour | `site.colour` → recipe input `taxanotes_classic.colour` → `taxanotes_classic.settings:colour` → `--sp-colour` as the `style` attribute of the `html` element (inline, so it beats the stylesheet's default) and a `scratchpad-colour-<hex>` body class (the Drupal 7 scratchpads_colour module's palette logic: the header, links, headings and block title bars take the colour; the menu tabs and the search button are a 30 % black overlay on it, white when active) | theme setting, editable at `/admin/appearance/settings/taxanotes_classic` |

The Olivero front page blocks (sidebar search, tree and numbers; recent and
updated taxa; newest images; recent literature and species pages) are
switched off in the classic theme by the recipe (`setStatus: false` on their
copies), not deleted: the block layout can turn any of them back on, and
`css/front.css` lays them out side by side under the columns.

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
tables, the ones the Scratchpads site renders, listing every mismatch; a
sha256 per content table (`node_field_data` without `vid`/`changed`, every
`node__field_*` table without `revision_id`) so a before/after of the Myriatrix
control is a checksum, not a count; and, once the literature recipe is
applied, the literature: D7/D11 counts of references (per type),
contributors, keywords, author and keyword rows, taxon links, protologue
links and attachments (with the number of files the import could not find
locally), and a **comparison of every reference's** title, year, type and
author list against its D7 biblio node; and, once the species recipe is
applied, the species pages and images: D7/D11 counts of species pages, their
taxa, the images the import selects (placed on a species page or tagged with
a taxon of the vocabulary, renderable by the image toolkit, present under the
files directory), their taxon links and licences, the images placed on pages,
the bytes copied, and a **comparison of every species page's** title, status,
taxon and text sections against its D7 spm node. The checksums cover the
taxon nodes only, so a species page import leaves them unchanged.

`scripts/list-source-files.php` prints the D7 files the import would copy
(relative paths, with `sizes` the bytes too): the list to rsync to the
server's `SOURCE_FILES_DIR` before the files and media migrations run there.

## Instances

| slug | site directory | hostnames (ddev / production) | source |
|---|---|---|---|
| `myriatrix` | `web/sites/default` (built by hand before this script; its `settings.local.php` is hand-written) | taxanotes-dev.ddev.site / myriatrix.taxanotes.net | D7 vocabulary `myriatrix`, database `myriatrix` (ddev) / `s_myriatrix` (production) |
| `mormyrids` | `web/sites/mormyrids` | mormyrids.taxanotes-dev.ddev.site / mormyrids.taxanotes.net | D7 vocabulary `mormyridae`, database `mormyrids` (ddev) / `s_mormyrids` (production) |
| `anthophila` | `web/sites/anthophila` | anthophila.taxanotes-dev.ddev.site / anthophila.taxanotes.net (no DNS yet) | D7 vocabulary `bee_family_genus_groups` (2022 dump), database `anthophila` (ddev) / `s_anthophila` (production) |
