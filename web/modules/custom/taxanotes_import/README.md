# Taxanotes import

Migrations that bring taxa into the shared `taxon` content type (recipe
`recipes/taxanotes_taxon`).

## Drupal 7 Scratchpads track

Two plugin migrations, run in order, both keyed on the Scratchpads GUID:

| id | what it does |
|---|---|
| `taxanotes_d7_taxon` | one `taxon` node per term of the source vocabulary; all scalar fields, GUIDs of parent / accepted name kept as strings |
| `taxanotes_d7_taxon_parent` | second pass: resolves `field_parent_taxon_reference` and `field_accepted_name` through the first migration's id map |

Source plugin `scratchpads_d7_taxon` extends core `d7_taxonomy_term` and adds
`guid` (`feeds_item.guid`, falling back to `taxonomy_term_data.uuid` for terms
created by hand, i.e. never imported), `parent_guid`, `parent_name`,
`accepted_guid`, protologue reference, page URL, revision timestamps and the
vernacular-name field collection, all in one SQL query.

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

Node `status` comes from the `scratchpads_published_status` process plugin:
0 for a term that is, or descends from, one of the `unpublished_roots`, 1
for everything else. The lookup walks the D7 hierarchy in memory and is not a
source column, so it does not change the `track_changes` hash of any row.
The source plugin also overrides `count()` (one indexed `COUNT(*)` on
`taxonomy_term_data` instead of the full join query), so `drush
migrate:status` answers in well under a second.

Run:

```
drush migrate:status --group=taxanotes
drush migrate:import taxanotes_d7_taxon
drush migrate:import taxanotes_d7_taxon_parent
```

Both migrations are idempotent (`track_changes: true`): re-running imports
only rows whose source hash changed. Rolling back the parent pass leaves the
nodes in place (it only cleared references); rolling back `taxanotes_d7_taxon`
deletes the nodes.

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
