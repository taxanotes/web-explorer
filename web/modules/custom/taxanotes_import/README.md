# Taxanotes import

Migrations that bring taxa into the shared `taxon` content type (recipe
`recipes/taxanotes_taxon`) and the literature into bibcite references linked
to them (recipe `recipes/taxanotes_literature`): the Drupal 7 Scratchpads
database track (below) and the sheet track, the Scratchpads taxonomy
importer spreadsheet an instance owner keeps editing (`drush
taxanotes:import-sheet`, "Sheet track" at the end).

## Drupal 7 Scratchpads track

Fourteen plugin migrations in group `taxanotes`, run in this order (the
taxa first, then the literature, then the pass that links the two, then the
images and species pages, then the pages, blog entries and their menu
links):

| id | what it does |
|---|---|
| `taxanotes_d7_user` | one user per D7 account (uid kept), with the phpass password hash and the roles mapped onto the `recipes/taxanotes_users` roles |
| `taxanotes_d7_taxon` | one `taxon` node per term of the source vocabulary, keyed on the Scratchpads GUID; all scalar fields, GUIDs of parent / accepted name kept as strings |
| `taxanotes_d7_taxon_parent` | second pass: resolves `field_parent_taxon_reference` and `field_accepted_name` through the first migration's id map |
| `taxanotes_d7_taxon_revision` | the term revision history (Scratchpads `taxonomy_revision` tables) as node revisions, oldest first; the D7 current revision becomes the node's default revision |
| `taxanotes_d7_biblio_contributor` | one `bibcite_contributor` per `biblio_contributor_data` row (cid kept as the id): last, first and middle names, prefix, suffix |
| `taxanotes_d7_biblio_keyword` | one `bibcite_keyword` per `biblio_keyword_data` row (kid kept) |
| `taxanotes_d7_file` | one file entity per attachment of a biblio node (`field_file`), per image placed on a species page or tagged with a taxon, and per file an inline `<img>` of a page, blog entry or species page text points at (see "Inline images"), that exists under `source_files_dir`, copied to `public://scratchpads/<D7 path>`; a file not on this machine skips its row with a message |
| `taxanotes_d7_biblio` | one `bibcite_reference` per biblio node, keyed on the node uuid (kept in `field_scratchpads_uuid`; the nid in `field_scratchpads_nid`, which the `[bib]nid[/bib]` citations in texts are resolved through): the 14 publication types mapped onto bibcite's, every `biblio_*` column onto the bibcite field of the same name, authors (role and category), keywords, the taxa it names (`field_taxonomic_name`, through the taxon migration by GUID) and the attachments |
| `taxanotes_d7_taxon_reference` | second pass over the taxa that point at a biblio node: `field_reference` (entity reference to the bibcite reference) from the shadow `field_reference_guid` through the reference migration, and the nomenclature references `field_synonymy_reference` / `field_designation_reference` (below); the shadow fields stay |
| `taxanotes_d7_media_image`, `taxanotes_d7_spm` | the images as media items and the species pages (see "Species pages and images") |
| `taxanotes_d7_page`, `taxanotes_d7_blog` | one Basic page per D7 `page` node and one `blog_post` per D7 `blog` node (recipe `recipes/taxanotes_pages`), keyed on the node uuid: title, body (format mapped as the species pages, inline images pointed at the imported files), author through the user migration (uid 1 for the D7 site owner and deleted accounts), created/changed/status/promote/sticky, the vocabulary taxa the node names in `field_taxonomic_names`; revisions are not migrated |
| `taxanotes_d7_menu_link` | one main-menu link (`menu_link_content`) per D7 `main-menu` link to a page or blog node, pointing at the migrated node, and per D7 link to the blog listing (`blog`, pointing at `/blog`), with the link's own title, enabled state and the D7 weight + 50 (D7 weights run -50..50; the D11 menu's own entries sit at 0..10, so the pages follow them in their D7 order); the parent is not migrated (every pilot link is top level) |

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

### Nomenclature fields

Beyond the ITIS set, a Scratchpads vocabulary may carry term fields of its
own: Mormyrids has the type material (`field_description_taxa`), type
locality, original combination, free-text synonym lists (objective and
subjective) and taxonomic notes; Anthophila the synonymy and designation
(by whom, in which reference, on which page), the family-level name
origins, the type material notes (`field_type_species`) and the biological
notes. They land in the optional nomenclature fields of the taxon type
(`recipes/taxanotes_taxon`; the two references, which point at bibcite
references, in `recipes/taxanotes_literature`), the same on every instance:
a field a vocabulary lacks stays empty and hidden. The table is
`ScratchpadsD7Base::EXTRA_FIELDS` (D7 name => D11 name and kind): three
misleading D7 names are renamed (`field_description_taxa` ->
`field_type_material`, `field_type_species` -> `field_type_material_notes`,
`field_designation_method` -> `field_designation_reference`, and
`field_synonyms` -> `field_synonyms_homotypic`, beside
`field_synonyms_heterotypic`). A D7 `text` of 255 characters is a `string`,
a longer `text` or a `text_long` a formatted `text_long` (format NULL and
plain_text -> plain_text, filtered_html -> basic_html, full_html stays), a
`node_reference` an entity reference to the bibcite reference, resolved by
`taxanotes_d7_taxon_reference` through the biblio migration by node uuid
(like the protologue; not revisioned). The source plugins discover which of
the table's fields the vocabulary has (`extraFields()`, from
`field_config_instance`) and load their values once, every delta in the
term's own language (else `und`, else the first), as whole-field
properties named after the D11 field: a string, a list of `{value,
format}`, or for a reference `<name>_guid` / `<name>_nid`. A vocabulary
without them (Myriatrix) adds no property, so its row hashes do not change;
on a vocabulary with them every row re-imports once. A term field the
table does not know is not migrated; `scripts/verify-instance.php` lists
such fields (`d7 term fields not migrated`).

The taxon page shows these fields, with the synonyms (the published child
taxa whose usage is invalid; `field_accepted_name` stays the D7 "associated
accepted name", `field_aan_<vid>`, which Scratchpads sets on most synonyms
and which the page shows on the synonym's side), in the Nomenclature block
of `taxanotes_front`.

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

**A platform change that re-imports the current revision.** When the
taxon import re-saves a node with changed values (new fields, as above) it
writes a sync revision above the D7 current revision. The next revision
import re-imports that D7 current revision too (its hash changed as well):
the destination updates the mapped revision in place, makes it the default
revision again and deletes the sync placeholder, so the history is exactly
the D7 history after the two runs. Node::preSaveRevision() keeps the stored
log of a revision saved in place when the new log is empty (a Scratchpads
log usually is); the destination clears it on the revision table after
the save, so the placeholder's text does not survive.

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
biblio node points at, plus the image files the instance uses (below), and
resolves each under `source_files_dir`
(`$settings['taxanotes_import']['source_files_dir']`, from `.env`
`SOURCE_FILES_DIR` or `instance.yml` `source.files`; under ddev
`.ddev/docker-compose.scratchpads-files.local.yaml` (gitignored, copied from
its `.dist`) bind-mounts the Scratchpads
files directory read-only at `/mnt/scratchpads/myriatrix/files`). A file
that is not there skips its row with the message *Source file is not
available locally*, an image the image toolkit cannot render (TIFF) with
*Image type the image toolkit cannot render* (`drush migrate:messages
taxanotes_d7_file` lists them; `scripts/verify-instance.php` counts them),
and the reference or media item imports without it; a later run with the
files present picks them up. Files are copied, never moved, into
`public://scratchpads/<D7 relative path>` and reused when already there.
The instance scripts run this migration only when the directory is
configured; `scripts/list-source-files.php` prints the paths it would copy.

`taxanotes_d7_taxon_reference` uses the taxon source with
`with_reference_only: true` (only the terms whose D7 `field_reference` is
set) and the `entity:node` destination with `overwrite_properties`, like the
parent pass; rolling it back keeps the links. Rolling back
`taxanotes_d7_biblio` deletes the references (the taxa and their shadow
fields are untouched; `field_reference` then points at nothing until the
reference import and this pass with `--update` run again). The `--update`
matters: this pass has `track_changes` and the taxon rows did not change in
D7, so after any biblio rollback and re-import a plain `migrate:import
taxanotes_d7_taxon_reference` reports 0 items and leaves the links dangling
(and the re-imported references get fresh entity ids, so their
`/bibcite/reference/<id>` URLs change; citekeys and uuids stay). The instance
scripts never roll back, so they run the plain import; after a hand rollback
run `drush migrate:import taxanotes_d7_biblio && drush migrate:import
taxanotes_d7_taxon_reference --update`.

A hand run of `taxanotes_d7_file` or `taxanotes_d7_media_image` on an instance
whose files are not on the machine (no `source_files_dir`) writes one *not
available locally* message per file (828 on Mormyrids) and marks the rows
ignored; harmless, the messages list what the server rsync must deliver, and
a later run with the files present imports them. The instance scripts skip
the two migrations in that case, so this happens only by hand.

**Ids and the one-way cutover.** Contributors (`cid`), keywords (`kid`), files
(`fid`) and media (`mid` = fid) keep their D7 ids as entity ids, and the
`entity:*` destinations update an existing entity of that id. While the
Scratchpads site is still edited and the Drupal 11 instance is read-only (the
plan's cutover), re-imports are safe. If both sites were edited for a while,
a new D7 row could take the id of an entity created on the Drupal 11 side and
the next import would overwrite it; the taxa and species pages (keyed on
uuid, fresh node ids) cannot collide. Keying the four migrations on uuid
with fresh ids (the `migration_lookup`s already resolve the links) is the
change to make before any two-way period.

**Re-runs on a long-lived instance.** `taxanotes_d7_user` and
`taxanotes_d7_taxon_parent` use `track_changes`; an instance imported with an
older version of a source plugin (Myriatrix, imported before the plugins
grew their literature and species columns) carries older row hashes, so the
first run of the current code re-saves every user and parent row once
("76 updated", "32,485 updated"; the values are the same, every checksum
identical) and later runs are 0 items. The other migrations are 0 items on
every re-run.

### Species pages and images

`scratchpads_d7_spm` selects the current revision of every `spm` node (the
Scratchpads taxon description; revisions are not migrated) keyed on the node
uuid, which the `taxon_description` node also takes as its uuid. The text
sections are discovered from `field_config_instance` (every `text_long` on
the type: the 36 standard SPM sections, plus `field_electric_organ_discharge`
on Mormyrids), each read with the node language rule and mapped by the
migration onto the field of the same name (`filtered_html` becomes
`basic_html`, `full_html` stays, anything else `basic_html`). The taxon is
the lowest-delta term of the instance vocabulary in `field_taxonomic_name`
(terms of other vocabularies, the Myriatrix morphology terms, and any further
taxon are dropped; a page whose taxon is not in the instance is skipped with
a message). `field_media` is mapped through the media migration.

`scratchpads_d7_image` selects the image files (`file_managed.type = image`)
that are placed on an spm node (`field_media`) or tagged with a term of the
instance vocabulary (`field_taxonomic_name` on the file), the same rule as
the image branch of the file source; `taxanotes_d7_media_image` turns each
into an Image media item whose id is the D7 fid, wrapping the file entity the
file migration created (an image whose file was skipped there is skipped
here with a message), with the taxa (by GUID), the Creative Commons licence
(the Scratchpads creative_commons numbers 1 all rights reserved, 2 CC BY, 3
CC BY-SA, 4 CC BY-ND, 5 CC BY-NC, 6 CC BY-NC-SA, 7 CC BY-NC-ND, 8 CC0, 9
public domain), the creator as the credit, the description, and the
description as plain text (else the file name) for the alt text. Media
thumbnails are derived by core on save. Rolling back `taxanotes_d7_spm` and
`taxanotes_d7_media_image` deletes the pages and media items and leaves the
taxa, the literature and the file entities untouched.

### Inline images

The Scratchpads media module put images into the texts of pages, blog
entries and species pages as `<img>` tags with the file id in a class
(`img__fid__<fid>`) and an absolute `src` on the D7 host, often an image
style path (`sites/<site>/files/styles/large/public/<path>`). Left as
they are, the basic_html format replaces each with its "image removed"
marker (only images of this site are allowed). So:
- `taxanotes_d7_file` also imports every file an inline image points at
  (`inline_bundles`, default page, blog and spm: every text field of those
  bundles is scanned; the fid from the class, else `file_managed` by the
  `public://` path the src gives);
- the page, blog and species page sources rewrite each such `src` to the
  imported file's URL (`public://scratchpads/<D7 path>`: the original,
  not the style derivative) before the row is hashed, so a body with images
  re-imports once and then stays unchanged. An image whose file is not in
  `file_managed` keeps its src (and shows the marker). On a machine without
  the D7 files the src is rewritten all the same; the image appears once
  the files are there and `taxanotes_d7_file` has run.

## Sheet track (the Scratchpads taxonomy importer spreadsheet)

Every Scratchpads site exports and imports its classification as the
*taxonomy importer* template, an `.xls` workbook with a `Template` data sheet
(44 columns: Term name, Parent Term Name, GUID, Parent GUID, the associated
accepted name, the vernacular-name columns, Authors, Page number, Rank, the
Reference GUID/NID/Title, Unacceptability Reason, Unit indicator/name 1..4,
Usage, the nomenclature columns and the Synonymy/Designation reference
columns) and a `PermittedValues` lookup sheet. An instance owner who keeps
the classification in that sheet sends it back whenever it changed, and
`drush taxanotes:import-sheet FILE` imports it, matching on GUID, as the
Scratchpads importer did, with the difference that it can be run again on
every new sheet. Three plugin migrations (tag `sheet`, group `taxanotes`),
all on the source `scratchpads_sheet`:

| id | what it does |
|---|---|
| `taxanotes_sheet_taxon` | one `taxon` node per row, every column the type has a field for, the GUIDs of parent, accepted name and references kept in the shadow string fields; destination `taxanotes_entity_node` (below) |
| `taxanotes_sheet_taxon_link` | second pass: `field_parent_taxon_reference` and `field_accepted_name` by GUID |
| `taxanotes_sheet_taxon_reference` | third pass, the rows with a Reference, Synonymy reference or Designation reference GUID: `field_reference`, `field_synonymy_reference`, `field_designation_reference` to the bibcite references of the site (`field_scratchpads_uuid`) |

**The file.** `.xls`, `.xlsx` or `.ods` through PhpSpreadsheet (formulas are
not calculated, numbers come as the cell shows them), `.csv` through PHP's
own parser; the two readers yield the same rows, so a CSV export of the
workbook imports identically. The worksheet is `Template` (`--sheet`, or the
first sheet when there is none of that name). The first non-empty row is the
header, matched to the template's column names case-insensitively, ignoring
underscores, hyphens and the space before a digit (`Unit name1`). A
template column the sheet lacks would clear its field on every taxon (the
sheet is the owner's whole record of a taxon), so the command refuses such a
sheet after the analysis unless `--allow-missing-columns` says the clearing
is meant (a missing column without a field, Taxonomic revisions say, loses
nothing); a column the taxon type has no field for (Taxonomic revisions, the
vernacular Source GUID/NID/Title) is read, named in the report and not
imported; a column the template does not know is listed and ignored. Cells
are trimmed (no-break spaces too), an empty cell is NULL; a CSV may be
comma-, semicolon- or tab-delimited (sniffed from the header line). Only
the notes columns keep markup: a cell with markup (the exporter keeps the
`<em>` of italicised names) lands in a formatted text field as
`basic_html`, a plain cell as `plain_text`; every other column is plain
text, tags removed and entities decoded (the exporter writes `<em>` into
"Synonymy by" and "Designation by" too, whose fields would show the tags),
then cut to the field's 255 characters with a warning message and a line in
the report's headline, so the owner can shorten the cell. Unit
indicator/name 1..4 fold into `field_unit_names` as the D7 track does; the
vernacular columns into one `field_vernacular_names` item when the name is
set. The reader is `src/Sheet/SheetReader.php`, the rules
`src/Sheet/SheetRows.php` (pure PHP, unit-testable, shared by the source
plugin, the Drush report and the Kernel test).

**Row identity** (`SheetRows`; every decision is a message of
`taxanotes_sheet_taxon` and a line of the report):
- a GUID carried by one row is that row's id (`field_guid`);
- rows that share a GUID and are identical are one row (the first imported);
- rows that share a GUID and a Term name but differ are a conflict: the
  first is imported, the others dropped with a message for the owner;
- rows that share a GUID under *different* names (rows copied on the
  Scratchpads site with the uuid kept; Anthophila has 10) are different
  taxa: the row whose name matches the taxon already carrying the GUID on
  the site keeps it, else the first row; every other row gets the derived id
  `uuid5(GUID + "|" + Term name)`;
- a row without a GUID is the taxon of that name on the site when exactly
  one has it, else the derived id `uuid5(Term name + "|" + Parent GUID or
  Parent Term Name)`;
- a row without a Term name is skipped;
- Parent GUID empty but Parent Term Name given: the unique row of that name
  in the sheet, else the unique taxon of that name on the site, else the
  parent stays empty.
Derived ids are RFC 4122 version 5 UUIDs under the namespace
`SheetRows::UUID_NAMESPACE` (`125888c5-4c25-4c86-a677-fae57f40e37a`; never
change it), so the same row gets the same id in every run and in every later
sheet, and a GUID-less or duplicate row is updated, not created twice. The
owner can end the derivation by giving the row its own GUID in the next
sheet: the derived node then stays as a separate taxon (the report lists
it among the taxa not in the sheet), to be deleted or merged by hand.

**`--match-names`.** The sheet's GUIDs are the Feeds GUIDs of the Scratchpads
site *as it was when the sheet was exported*. A Taxanotes instance built from
an older dump may carry other GUIDs for the same terms when the owner
re-imported the classification on Scratchpads in between (every term then
got a new GUID: Anthophila's 2022 dump against its December 2025 sheet, 0
GUIDs in common). Matching on GUID alone would then create every row again
beside the existing taxon. With `--match-names`, a row whose GUID no taxon on
the site carries updates the taxon of the same Term name when that name is
unique in the sheet and on the site, else the taxon with the same name *and*
Authors when that pair is unique on both sides; the taxon takes the row's
GUID (`field_guid`, revisioned like any field), so the next sheet matches it
by GUID. A name the sheet or the site has twice (a homonym pair) is never
folded: the row is imported as a new taxon and listed under "ambiguous". Off
by default; the report says how many rows it would match, so run
`--report-only` first. After such a re-keying run the D7 track is finished
for that instance: `taxanotes_d7_taxon` maps the old GUIDs and would write
them back. The taxa the sheet does not list keep their 2022 parent GUID
while their parent took the sheet's, and the tree (which lists children by
`field_parent_guid`, the node_tree `parent_id_field`) would lose them, so
after the link pass the command rewrites `field_parent_guid` from the parent
reference on every taxon the sheet does not list whose parent now carries
another GUID (`src/Sheet/ParentGuidSync.php`; a revision "Imported from
sheet <file>: parent GUID ... re-keyed to ..." where the current revision is
history, in place on a placeholder); the analysis says how many beforehand.

**What the import does.** The first pass writes title, `field_guid` = the
row id, `field_parent_guid` /
`field_parent_term_name` (the parent as given, or as resolved by name),
`field_rank`, `field_usage`, `field_unacceptability_reason`,
`field_unit_names`, `field_authors`, `field_page_number`,
`field_accepted_guid`/`_tid`, `field_reference_guid`/`_nid`/`_title`,
`field_term_description`, `field_vernacular_names` and the nomenclature
fields the sheet carries (`field_type_material_notes`,
`field_family_level_name_origins`, `field_designation_by`,
`field_synonymy_by`, `field_taxonomic_notes`, `field_biological_notes`).
A created taxon is published and owned by uid 1; an existing one keeps its
status and owner (the sheet has no such columns: `create_only_properties`
of the destination), so a taxon unpublished on the site stays unpublished.
`field_legacy_tid`, `field_weight`, `created` and the fields the template
has no column for are left alone. The node is found through the D7 track's
id map, else by `field_guid` (process plugin `taxanotes_guid_lookup`, which
asks the given migrations' id maps in order and then the field, and records
a message when nothing answers); none means a new node. The second pass
resolves the parent and the accepted name the same way (the sheet's own
rows first, so a parent that is itself new in this sheet links); a GUID the
sheet and the site do not know leaves the link empty with a message. The
third pass resolves the three reference GUIDs to bibcite references through
the literature migration's id map, else `field_scratchpads_uuid`; the sheet
carries no titles, so a reference the site does not have cannot be created
here: the link stays empty, the shadow fields keep the GUID and NID, and the
message names the row. A junior homonym comes across as the owner records
it: Usage `invalid`, Unacceptability Reason `junior homonym`, the valid
parent in Parent GUID, nothing else.

**Revisions.** All three passes use the destination `taxanotes_entity_node`.
The first pass has the D7 track's placeholder prefixes but a log that is
*not* one of them: a created node's first revision and the revision a
changed row adds to a node whose current revision is real history (a D7
revision, an edit made here, an earlier sheet import) are logged "Imported
from sheet <file> row N", author uid 1, the time of the run. A row that
changes nothing saves nothing: no revision, the log (a placeholder stays a
placeholder) and `changed` untouched. A node whose current revision is still
a D7 placeholder ("Migrated from Scratchpads GUID ...", an instance that
never ran the revision import) is updated in place as core does. The two
link passes apply the same rule to their fields only (`overwrite_properties`)
and count "Imported from sheet " among their placeholders: a changed link
lands in the revision this run's first pass made (or an earlier sheet's), so
a first import gives every taxon one revision; a link that differs on any
other revision, say a taxon re-parented by hand on the site, becomes a new
revision "Imported from sheet <file> row N" of its own, so every sheet change
stays visible in the history. Run the D7 track to completion (including
`taxanotes_d7_taxon_revision`) before the first sheet import, or the
revision import later keeps a sheet revision it does not know as history.

**Repeatability.** `drush taxanotes:import-sheet` runs the three migrations
with `--update` semantics (every row is processed; a value edited on the
site is set back to the sheet's whether or not the row changed) and clears
each migration's messages first, so `migrate:messages` is the report of the
last run. The same sheet twice: 0 created, 0 updated, every row unchanged.
`drush migrate:import taxanotes_sheet_taxon` without `--update` processes
only the rows whose hash changed (`track_changes`). Rolling
`taxanotes_sheet_taxon` back deletes the nodes the sheet created and keeps
the ones it updated (core's rollback action for updated entities); the two
link passes have `overwrite_properties` and their rollback keeps the links.
Without a sheet configured the source's requirements check fails, and Drush
then hides the three migrations: `migrate:status --tag=sheet` prints an
empty table and `migrate:import taxanotes_sheet_taxon` says only "No
migrations found". Configure `$settings['taxanotes_import']['sheet_path']`
to see them there, or use `drush taxanotes:import-sheet FILE`, which reports
a missing or unreadable file itself.

**What it cannot do.** Create literature (no titles in the sheet: add the
references here, then run the import again, which fills the links); delete
or unpublish taxa the sheet no longer lists (the report counts them and
lists the first 20, every one with `--list-all`; the owner decides); import
the Taxonomic revisions column or the vernacular Source columns (no field);
publish or unpublish (the sheet has no status column: a created taxon is
published, an existing one keeps its status); keep a string cell over 255
characters (cut, with a warning and a headline line); recognise a renamed
taxon without its GUID (it becomes a new taxon, the old one stays); fold a
name the sheet or the site has twice (listed as ambiguous, imported as new:
the owner decides which of the pair to keep).

**Running it.**

```
drush taxanotes:import-sheet /path/to/sheet.xls --report-only [--list-all]   # analysis only
drush taxanotes:import-sheet /path/to/sheet.xls [--match-names] [--sheet=Template] [--allow-missing-columns]
drush cr
drush migrate:messages taxanotes_sheet_taxon                     # identity notes, cut cells
drush migrate:messages taxanotes_sheet_taxon_link                # unresolved parents / accepted names
drush migrate:messages taxanotes_sheet_taxon_reference           # references the site lacks
```

The report (`--report-only`, and the first part of every import) gives: rows,
GUID styles (md5 = Scratchpads/Feeds, uuid = terms made by hand on the site),
the duplicate GUIDs with the derived ids, the GUID-less rows, the parents
given by name, the columns without a field, the cells with markup; against
the site: rows matching an existing taxon by GUID (and by name with
`--match-names`), new rows, existing taxa not in the sheet, parent and
accepted-name GUIDs that resolve nowhere, the reference GUIDs the site has
and lacks (with NIDs). The import then prints, per migration, rows
processed, nodes created / updated / unchanged, rows skipped / failed, and
the first messages. Snapshot the database first (`ddev snapshot`, or the
hosting toolkit's `backup_db`); a stuck "Importing" status from an
interrupted run is reset with a note. The source reads the path from the
state key `taxanotes_import.sheet_path` the command sets for its run (and
clears), else from `$settings['taxanotes_import']['sheet_path']` (with
`sheet` and `sheet_match_names`) for an instance whose sheet lives at a fixed
place; the migration YAML names no file, so one codebase serves every
instance. Where the file goes: `instances/<slug>/data-imports/sheets/` locally
(gitignored), `/var/www/09/<domain>/source/sheets/` on a server, mode 0600,
never in git.

**Checks.** The Kernel test `tests/src/Kernel/SheetImportTest.php` imports
`tests/fixtures/sheet.csv` twice (a root, children, a synonym with accepted
name, a junior homonym, a duplicate GUID pair, a GUID-less row, a non-GUID
in the GUID cell, `<em>` in a notes cell and in two plain-string cells,
no-break spaces; two taxa pre-existing as D7 placeholders, one re-parented
by hand and one unpublished between the runs) and checks the fields, the
links, the derived ids, that the first run gives every taxon one revision
and leaves an unchanged placeholder untouched, and that the second run adds
one revision to the changed node and one to the re-parented one and nothing
to the others. From the repository root, inside ddev:

```
SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/taxanotes_import/tests/src/Kernel
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,test,profile,theme,info,yml web/modules/custom/taxanotes_import
vendor/bin/phpstan analyse        # phpstan.neon.dist at the root: level 5 over this module
```

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
drush migrate:import taxanotes_d7_media_image
drush migrate:import taxanotes_d7_spm
drush migrate:import taxanotes_d7_page
drush migrate:import taxanotes_d7_blog
drush migrate:import taxanotes_d7_menu_link
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
