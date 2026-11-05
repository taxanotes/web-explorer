# Taxanotes import

Migrations that bring taxa into the shared `taxon` content type (recipe
`recipes/taxanotes_taxon`) and the literature into bibcite references linked
to them (recipe `recipes/taxanotes_literature`).

## Drupal 7 Scratchpads track

Nine plugin migrations in group `taxanotes`, run in this order (the taxa
first, then the literature, then the pass that links the two):

| id | what it does |
|---|---|
| `taxanotes_d7_user` | one user per D7 account (uid kept), with the phpass password hash and the roles mapped onto the `recipes/taxanotes_users` roles |
| `taxanotes_d7_taxon` | one `taxon` node per term of the source vocabulary, keyed on the Scratchpads GUID; all scalar fields, GUIDs of parent / accepted name kept as strings |
| `taxanotes_d7_taxon_parent` | second pass: resolves `field_parent_taxon_reference` and `field_accepted_name` through the first migration's id map |
| `taxanotes_d7_taxon_revision` | the term revision history (Scratchpads `taxonomy_revision` tables) as node revisions, oldest first; the D7 current revision becomes the node's default revision |
| `taxanotes_d7_biblio_contributor` | one `bibcite_contributor` per `biblio_contributor_data` row (cid kept as the id): last, first and middle names, prefix, suffix |
| `taxanotes_d7_biblio_keyword` | one `bibcite_keyword` per `biblio_keyword_data` row (kid kept) |
| `taxanotes_d7_file` | one file entity per attachment of a biblio node (`field_file`) that exists under `source_files_dir`, copied to `public://scratchpads/<D7 path>`; a file not on this machine skips its row with a message |
| `taxanotes_d7_biblio` | one `bibcite_reference` per biblio node, keyed on the node uuid (kept in `field_scratchpads_uuid`): the 14 publication types mapped onto bibcite's, every `biblio_*` column onto the bibcite field of the same name, authors (role and category), keywords, the taxa it names (`field_taxonomic_name`, through the taxon migration by GUID) and the attachments |
| `taxanotes_d7_taxon_reference` | second pass over the taxa with a protologue: `field_reference` (entity reference to the bibcite reference) from the shadow `field_reference_guid` through the reference migration; the shadow fields stay |

Source plugin `scratchpads_d7_taxon` is built on core migrate's `SqlBase`
(not on the `migrate_drupal` d7 sources, which are deprecated in Drupal 11.3
and removed in 12). It selects the terms of one vocabulary and adds `guid`
(`feeds_item.guid`, falling back to `taxonomy_term_data.uuid` for terms
created by hand, i.e. never imported), `parent_guid`, `parent_name`,
`accepted_guid`, protologue reference, page URL, revision timestamps and the
vernacular-name field collection, all in one SQL query, plus `published`
(see below). The D7 database is the `migrate` connection (`$databases['migrate']`,
the SqlBase default); `migrate_drupal` does not need to be installed.

Instance configuration lives in the site's `settings.local.php`, which
`scripts/new-instance.sh` generates from `instances/<slug>/instance.yml`
(`source.vocabulary`, `source.database`, `source.unpublished_roots`) and
`instances/<slug>/.env` (the credentials):

```php
$databases['migrate']['default'] = [ /* the D7 database */ ];
$settings['taxanotes_import'] = [
  'source_vocabulary' => 'myriatrix',   // D7 vocabulary machine name
  // Root terms whose whole subtree is imported unpublished. Optional;
  // defaults to ['Deleted taxa'], the Scratchpads convention.
  'unpublished_roots' => ['Deleted taxa'],
  // The D7 public files directory as PHP reaches it (instance.yml
  // source.files): the literature attachments are copied from it. Optional;
  // without it taxanotes_d7_file skips every file with a message.
  'source_files_dir' => '/mnt/scratchpads/myriatrix/files',
];
```

Scratchpads fields are translatable, so a term (or a vernacular-name
collection item) can carry a row per language for the same field: Myriatrix
has `en`/`und` pairs with equal values, Mormyrids a term with an `en` and a
`fr` row holding different authors and page numbers. Every field join picks
exactly one row per term (`ScratchpadsD7Base::joinFieldTable()`): the row in
the term's own language, else the `und` row, else the alphabetically first
language (what the D7 site shows on its default path). Without this a term
yielded one source row per language combination, i.e. the same GUID twice,
and the last row won. A site that never enabled entity_translation for terms
has no `taxonomy_term_data.language` column (the 2022 Anthophila dump; its
field rows are all `und`): the join then has no own language to prefer and
takes `und` first (`termsHaveLanguage()`), and `scripts/verify-instance.php`
applies the same rule.

The parts of the scientific name, which Scratchpads stores as four field
pairs (`field_unit_indicator1..4`, `field_unit_name1..4`), land in one
multi-value custom field, `field_unit_names` (sub-fields `indicator`, `name`;
cardinality 4). The source plugins read the eight D7 columns as they are and
fold them into the property `unit_names` (`ScratchpadsD7Base::unitNames()`):
one item per position whose name is non-empty after trimming, in position
order, a blank indicator as NULL; both migrations map it with `sub_process`.
A taxon called *Mormyrus caballus bumbanus* is three items, `Mormyrus`,
`caballus`, `bumbanus`; a variety carries its marker in the third item's
indicator (`var.`). The eight D7 columns are part of the `track_changes` row
hash, so a unit edited in Drupal 7 re-imports the term. An instance built
before this field existed is converted in place: snapshot the database,
`drush recipe recipes/taxanotes_taxon` (adds the field beside the old ones),
`migrate:import taxanotes_d7_taxon_revision --update`, then
`migrate:import taxanotes_d7_taxon --update` (that order: the revision
import fills the default revision too, so the taxon import creates no sync
revision), then `scripts/convert-unit-names.php` (deletes and purges the
eight old fields, reloads the `/taxa` view). Run the imports in the
foreground, one at a time; if one is interrupted it stays "Importing":
`migrate:reset-status <id>` and run the same `--update` import again (it
resumes the rows still marked needs-update), or restore the snapshot. The
script's header has the details.

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
An account whose D7 `changed` is 0 (never changed since registration, or a
dump without the column) gets its `created` time as `changed`; core would
otherwise stamp the import time. Passwords are the D7 `$S$` hashes, stored pre-hashed; core's Password
Compatibility (`phpass`) module, part of Drupal 11 core and installed by the
standard profile, verifies them on login, after which core re-hashes with
the current algorithm. Roles: `maintainer` and `administrator` ->
`administrator`, `editor` -> `editor`, `contributor` -> `contributor` (the
`static_map` in `taxanotes_d7_user.yml`); apply `recipes/taxanotes_users`
first so the roles exist: the source's `required_roles` configuration lists
them and the import refuses to run (requirements check) while any is
missing. Two cautions for later runs: a second import of an account that has
logged in here would write the D7 phpass hash back over the bcrypt re-hash
(and over any password changed here), so before a second user sync remove
`pass` from the process section or restrict it with `overwrite_properties`;
and rolling the user migration back deletes the accounts (their taxon
revisions keep pointing at the deleted uids), so follow a user rollback and
re-import with `migrate:import taxanotes_d7_taxon_revision --update`.

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
Fields D7 does not revision (GUID, legacy tid, parent, weight, status,
author) keep the node's current value in every revision (the weight column
of the D7 revision rows is always 0: sibling order is set by drag-and-drop
without a revision). For the same reason the **current** revision's values
(name, description, every ITIS field, accepted name, reference, page URL,
vernacular names) are read from the current tables (`taxonomy_term_data`,
`field_data_*`), not from its `field_revision_*` rows: a Scratchpads term can
be saved without a new revision, after which `field_data_*` is newer than the
current revision's row and is what the D7 site renders (4 Mormyrids terms;
none on Myriatrix). The default revision therefore always carries what
`taxanotes_d7_taxon` imported, and both migrations hash the same values.
Revision authors: a migrated account keeps its uid; D7 uid 0 (anonymous, e.g.
an import process) stays uid 0; D7 uid 1 and deleted accounts become the
local administrator (uid 1). Rolling the revision migration
back deletes the non-default revisions; the default revision cannot be
deleted, so its log is reset to "Scratchpads revision history rolled back",
which the next import replaces again. Run it after both taxon passes (it
requires them) and after `taxanotes_d7_user` (optional dependency: without
it every revision author is uid 1).

The process pipeline maps whole fields, never sub-properties
(`field_page_url: page_url_item`, built in the source plugin, rather than
`field_page_url/uri`): core records a NULL sub-property as an empty
destination named `field_page_url/uri`, which is not a field, so the
revision silently kept the value loaded from the current revision (748
historic revisions carried their node's current page URL until this was
fixed). Keep it that way when adding fields. `changed` of the D7 current
revision is the term's latest revision timestamp, the same value the node
import writes, so the two migrations agree on the default revision.

**Re-importing taxa after the history exists.** `taxanotes_d7_taxon` uses
the destination `taxanotes_entity_node` (core `entity:node` plus one rule):
when a re-import changes a node whose current revision is real history (an
imported D7 revision, or an edit made here), the new values are saved as a
new default revision with the log "Synced from Scratchpads GUID ..." (uid 1,
time of the run) instead of overwriting that revision in place; placeholder
revisions (logs "Migrated from Scratchpads GUID ...", "Synced from
Scratchpads GUID ...", "Scratchpads revision history rolled back") are
updated in place as core does, and a row that changes nothing (a forced
`--update`) adds no revision and keeps the log. A sync revision is itself a
placeholder: the next revision import replaces it with the D7 revision that
caused the change (`replace_log_prefixes`), so a node's history stays
exactly its D7 history. Without this rule, a pass-1 re-import after a D7
edit would have overwritten the D7 current revision's log with the
placeholder text and the next revision import would then have deleted that
genuine revision.

**Rollback order.** Rolling back `taxanotes_d7_taxon` deletes the nodes
with all their revisions but leaves the revision migration's id map in
place (its rows still hash as imported), so after re-importing both taxon
passes a plain `migrate:import taxanotes_d7_taxon_revision` processes 0
rows. Either roll the revision migration back first (slow: 577 s for
39,429 rows, see above) or, after a pass-1 rollback and re-import, run the
revision import with `--update` (a map row whose revision no longer exists
falls back to creating it). The revision destination's rollback skips
revisions that no longer exist.

### Literature

`scratchpads_d7_biblio` (built on `ScratchpadsD7Base` for the GUID rule)
selects the current revision of every biblio node (`node` joined to `biblio`
on `node.vid`) with every `biblio_*` column, and loads once per import the
contributors of each revision (`biblio_contributor`, rank order), the keyword
ids, the taxa (`field_data_field_taxonomic_name`: one row set per language,
the node's own language first, else `und`, else the first, like the term
fields; one link per distinct term of the instance vocabulary, with its
GUID) and the attachments (`field_data_field_file`, same language rule).
The migration maps the D7 publication type ids onto bibcite's reference
types (100 book, 101 book_chapter, 102 journal_article, 103 conference_paper,
104 conference_proceedings, 105 newspaper_article, 106 magazine_article, 108
thesis, 109 report, 120 personal, 121 manuscript, 124 unpublished, 125
database, 129 miscellaneous; anything else miscellaneous), the author rows
onto bibcite's contributor roles (`auth_type`: 1 author, 2 secondary_author,
... 14 editor, 22 advisor) and categories (`auth_category` 1..5), strips the
HTML italics Scratchpads keeps in titles (bibcite titles are plain text),
turns biblio's "in press" year 9999 into no year, keeps the citekey (the D7
nid, the `.bib` key), copies dates as the strings biblio stored and gives
abstracts the `basic_html` format. Contributors carry only their name parts:
bibcite composes the name it shows from them (`full_name_pattern`, set by
the recipe to include the middle initials D7 stores) and would re-parse and
overwrite the parts if the computed `name` were set.

`scratchpads_d7_file` lists the `file_managed` rows that `field_file` on a
biblio node points at and resolves each under `source_files_dir`
(`$settings['taxanotes_import']['source_files_dir']`, from `instance.yml`
`source.files`; under ddev `.ddev/docker-compose.scratchpads-files.yaml`
bind-mounts the Scratchpads files directory read-only at
`/mnt/scratchpads/myriatrix/files`). A file that is not there skips its row
with the message *Source file is not available locally* (`drush
migrate:messages taxanotes_d7_file` lists them; `scripts/verify-instance.php`
counts them), and the reference imports without it; a later run with the
files present picks them up. Files are copied, never moved, into
`public://scratchpads/<D7 relative path>` and reused when already there.

`taxanotes_d7_taxon_reference` uses the taxon source with
`with_reference_only: true` (only the terms whose D7 `field_reference` is
set) and the `entity:node` destination with `overwrite_properties`, like the
parent pass; rolling it back keeps the links. Rolling back
`taxanotes_d7_biblio` deletes the references (the taxa and their shadow
fields are untouched; `field_reference` then points at nothing until the
reference import and this pass with `--update` run again).

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
drush recipe recipes/taxanotes_literature
drush migrate:import taxanotes_d7_biblio_contributor
drush migrate:import taxanotes_d7_biblio_keyword
drush migrate:import taxanotes_d7_file
drush migrate:import taxanotes_d7_biblio
drush migrate:import taxanotes_d7_taxon_reference
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
