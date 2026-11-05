# Instances

Taxanotes is one codebase that runs several sites. A site is an **instance**:
the unchanged platform (modules, recipes, theme) plus three per-instance
things, all in `instances/<slug>/`:

| File | In git | Holds |
|---|---|---|
| `instance.yml` | yes | everything that describes the instance and is not secret (below) |
| `.env` | **no** (`**/.env` is gitignored) | hash salt, database credentials, site mail, optional admin password; template `instances/.env.example` |
| `data-imports/` | no (dumps, spreadsheets) | source data, when the source is a file rather than a live database |

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
database in the ddev db container, installs Drupal, applies
`recipes/taxanotes_base` (which pulls in `taxanotes_taxon` and
`taxanotes_users`), sets the themes, runs the four `taxanotes_import`
migrations when `.env` names a Scratchpads database, rebuilds caches and
prints the URL. A second run on the same instance changes nothing that is
already in place; a changed `instance.yml`/`.env` regenerates
`settings.local.php` (a hand-written one, like Myriatrix's, is left alone).

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
hostnames:                         # all hostnames that serve the instance; the first is the
  - mormyrids.taxanotes-dev.ddev.site   # canonical URL (https). Trusted-host patterns are built from them.
drupal:
  sites_subdir: mormyrids          # web/sites/<subdir> (default: slug); "default" = the ddev project's own site
  profile: standard                # install profile
  database: mormyrids              # the instance's own database name (credentials in .env)
source:                            # omit for an instance without a Scratchpads database
  type: scratchpads_d7
  vocabulary: mormyridae           # D7 vocabulary machine name -> $settings['taxanotes_import']['source_vocabulary']
  database: mormyrids              # D7 database name (credentials MIGRATE_DB_* in .env)
  unpublished_roots:               # root terms imported unpublished with their subtree
    - 'Deleted taxa'               #   -> $settings['taxanotes_import']['unpublished_roots']
```

`site.mail` is deliberately not here: addresses are not committed; `.env`
`SITE_MAIL` carries it (default `webmaster@example.com`).

## Adding an instance

1. `mkdir instances/<slug>` and write `instance.yml` (copy one of the existing
   ones; pick unused hostnames).
2. If the taxa come from a Scratchpads database, load its dump into the local
   D7 MySQL as a database of its own and create a user for it.
3. `scripts/new-instance.sh <slug>` once: it creates `.env` with a random hash
   salt and stops short of the import; fill in `MIGRATE_DB_USER` /
   `MIGRATE_DB_PASSWORD` (and `SITE_MAIL`, `ADMIN_PASSWORD` if wanted).
4. `scripts/new-instance.sh <slug>` again: installs, applies recipes, imports.
5. Commit `instances/<slug>/instance.yml`, `web/sites/sites.php` and
   `.ddev/config.instances.yaml` (and `web/sites/<subdir>/settings.php`, which
   is the unchanged template). Never `.env`, `settings.local.php`, `files/` or
   dumps.

## Instances

| slug | site directory | hostnames | source |
|---|---|---|---|
| `myriatrix` | `web/sites/default` (built by hand before this script; its `settings.local.php` is hand-written) | taxanotes-dev.ddev.site | D7 vocabulary `myriatrix`, database `myriatrix` |
| `mormyrids` | `web/sites/mormyrids` | mormyrids.taxanotes-dev.ddev.site | D7 vocabulary `mormyridae`, database `mormyrids` |
