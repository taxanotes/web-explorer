# Taxanotes import

Migrations that bring taxa into the shared `taxon` content type (recipe
`recipes/taxanotes_taxon`).

## Drupal 7 Scratchpads track

Two plugin migrations, run in order, both keyed on the Scratchpads GUID:

| id | what it does |
|---|---|
| `taxanotes_d7_taxon` | one `taxon` node per term of the source vocabulary; all scalar fields, GUIDs of parent / accepted name kept as strings |
| `taxanotes_d7_taxon_parent` | second pass: resolves `field_parent_taxon_reference` and `field_accepted_name` through the first migration's id map |

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

## Local ddev setup for the D7 track

`.ddev/docker-compose.scratchpads.yaml` attaches the web container to the
`scratchpads2_default` Docker network of the local Scratchpads stack, so
`$databases['migrate']` can use host `scratchpads.mysql`. Being part of the
compose project, the attachment survives `ddev restart` and container
recreates (a manual `docker network connect` does not). The D7 stack must be
up before `ddev start`; instances without a local D7 stack delete that file.

Run:

```
drush migrate:status --group=taxanotes
drush migrate:import taxanotes_d7_taxon
drush migrate:import taxanotes_d7_taxon_parent
```

Both migrations are idempotent (`track_changes: true`): re-running imports
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
