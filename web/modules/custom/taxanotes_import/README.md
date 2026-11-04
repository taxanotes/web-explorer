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
];
```

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
