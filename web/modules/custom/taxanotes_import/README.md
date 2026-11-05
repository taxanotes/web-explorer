# Taxanotes import

Migrations that bring taxa into the shared `taxon` content type (recipe
`recipes/taxanotes_taxon`).

## Drupal 7 Scratchpads track

Four plugin migrations in group `taxanotes`, run in this order:

| id | what it does |
|---|---|
| `taxanotes_d7_user` | one user per D7 account (uid kept), with the phpass password hash and the roles mapped onto the `recipes/taxanotes_users` roles |
| `taxanotes_d7_taxon` | one `taxon` node per term of the source vocabulary, keyed on the Scratchpads GUID; all scalar fields, GUIDs of parent / accepted name kept as strings |
| `taxanotes_d7_taxon_parent` | second pass: resolves `field_parent_taxon_reference` and `field_accepted_name` through the first migration's id map |
| `taxanotes_d7_taxon_revision` | the term revision history (Scratchpads `taxonomy_revision` tables) as node revisions, oldest first; the D7 current revision becomes the node's default revision |

Source plugin `scratchpads_d7_taxon` is built on core migrate's `SqlBase`
(not on the `migrate_drupal` d7 sources, which are deprecated in Drupal 11.3
and removed in 12). It selects the terms of one vocabulary and adds `guid`
(`feeds_item.guid`, falling back to `taxonomy_term_data.uuid` for terms
created by hand, i.e. never imported), `parent_guid`, `parent_name`,
`accepted_guid`, protologue reference, page URL, revision timestamps and the
vernacular-name field collection, all in one SQL query, plus `published`
(see below). The D7 database is the `migrate` connection (`$databases['migrate']`,
the SqlBase default); `migrate_drupal` does not need to be installed.

Instance configuration lives in `settings.local.php`:

```php
$databases['migrate']['default'] = [ /* the D7 database */ ];
$settings['taxanotes_import'] = [
  'source_vocabulary' => 'myriatrix',   // D7 vocabulary machine name
  // Root terms whose whole subtree is imported unpublished. Optional;
  // defaults to ['Deleted taxa'], the Scratchpads convention.
  'unpublished_roots' => ['Deleted taxa'],
];
```

Node `status` is mapped from the source property `published`: 0 for a term
that is, or descends from, one of the `unpublished_roots`, 1 for everything
else. The lookup walks the D7 hierarchy in memory; the value is part of the
`track_changes` row hash, so moving a subtree into or out of "Deleted taxa"
in D7 re-imports every affected descendant on the next run. The D7 term
`weight` is mapped to `field_weight`, which `node_tree` uses as the sibling
sort key (then title). The source plugin also overrides `count()` (one
indexed `COUNT(*)` on `taxonomy_term_data` instead of the full join query),
so `drush migrate:status` answers in well under a second.

The migration id `guid` is a SQL expression, which the id map cannot be
joined on; both migrations therefore set `track_changes: true` and
`ignore_map: true` (either alone keeps the map out of the source query, the
second makes it explicit). Do not remove them.

### Users

Source plugin `scratchpads_d7_user` reads `users`, `users_roles` and `role`.
uid 0 is never a row and uid 1 is skipped (on every instance uid 1 is the
local administrator; core's `entity:user` destination would keep its
password but replace its name, mail and roles with the D7 site owner's);
`include_root: true` in the source configuration migrates it anyway. D7 uid
1's revisions map to uid 1 through the fallback in the revision migration.
Passwords are the D7 `$S$` hashes, stored pre-hashed; core's Password
Compatibility (`phpass`) module, part of Drupal 11 core and installed by the
standard profile, verifies them on login, after which core re-hashes with
the current algorithm. Roles: `maintainer` and `administrator` ->
`administrator`, `editor` -> `editor`, `contributor` -> `contributor` (the
`static_map` in `taxanotes_d7_user.yml`); apply `recipes/taxanotes_users`
first so the roles exist.

### Revisions

Source plugin `scratchpads_d7_taxon_revision` yields one row per
`taxonomy_term_data_revision` row of the vocabulary, ordered by
`revision_id`, with the revisioned values read from the `field_revision_*`
tables and `is_current` = 1 for the revision `taxonomy_term_data.revision_id`
points at. The destination `taxanotes_entity_revision` (a subclass of core's
`entity_revision`) saves every row as a new node revision; the `is_current`
row becomes the default revision and the placeholder revision that
`taxanotes_d7_taxon` wrote (log "Migrated from Scratchpads GUID ...") is
deleted, so the history holds exactly the D7 revisions in order, with the
D7 timestamp, author and log, and the current field values do not change.
Fields D7 does not revision (GUID, legacy tid, parent, status, author) keep
the node's current value in every revision. Rolling the revision migration
back deletes the non-default revisions; the default revision cannot be
deleted, so its log is reset to "Scratchpads revision history rolled back",
which the next import replaces again. Run it after both taxon passes (it
requires them) and after `taxanotes_d7_user` (optional dependency: without
it every revision author is uid 1).

## Local ddev setup for the D7 track

`.ddev/docker-compose.scratchpads.yaml` attaches the web container to the
`scratchpads2_default` Docker network of the local Scratchpads stack, so
`$databases['migrate']` can use host `scratchpads.mysql`. Being part of the
compose project, the attachment survives `ddev restart` and container
recreates (a manual `docker network connect` does not). The D7 stack must be
up before `ddev start`; instances without a local D7 stack delete that file.

Run:

```
drush recipe recipes/taxanotes_taxon
drush recipe recipes/taxanotes_users
drush migrate:status --group=taxanotes
drush migrate:import taxanotes_d7_user
drush migrate:import taxanotes_d7_taxon
drush migrate:import taxanotes_d7_taxon_parent
drush migrate:import taxanotes_d7_taxon_revision --feedback=2000
```

All migrations are idempotent (`track_changes: true`): re-running imports
only rows whose source hash changed. Rolling back the parent pass removes
only its id-map rows (its map rows carry `rollback_action = preserve`
because it updates nodes it did not create): `field_parent_taxon_reference`
and `field_accepted_name` stay set on the nodes. Rolling back
`taxanotes_d7_taxon` deletes the nodes.

Do not run `drush cr`, apply recipes or start a second import while an
import runs on the same site: check `drush migrate:status --group=taxanotes`
shows *Idle* first (a `drush cr` during the parent pass once caused a
`cache_entity` deadlock that left the migration stuck in *Importing*;
`drush migrate:reset-status` clears that).

To redo a handful of taxa (for example after changing a process step), roll
back **both** passes for those GUIDs before importing them again; `--update`
on the parent pass alone fails with a duplicate-key error when its map row
points at a node that no longer exists:

```
drush migrate:rollback taxanotes_d7_taxon_parent --idlist=<guid>,<guid>
drush migrate:rollback taxanotes_d7_taxon --idlist=<guid>,<guid>
drush migrate:import taxanotes_d7_taxon --idlist=<guid>,<guid>
drush migrate:import taxanotes_d7_taxon_parent --idlist=<guid>,<guid>
```

(`--idlist` splits on `,` and then on `:`; pass `--idlist-delimiter='|'` for
LSID GUIDs, which contain colons.)
