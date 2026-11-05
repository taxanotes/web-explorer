# taxanotes_literature

The literature of a Taxanotes instance is **configuration**: the bibcite
reference entity with `field_taxonomic_name` (the taxa a reference names),
`field_file` (attachments) and `field_scratchpads_uuid`, the taxon's
`field_reference` (the protologue), the `taxanotes_literature` view (the
`/literature` page, the Literature block of a taxon page, the "Recently
added literature" front page block) and the block placements, all installed
by `recipes/taxanotes_literature` (applied by `taxanotes_base`). The data
comes in through the `taxanotes_d7_biblio*`, `taxanotes_d7_file` and
`taxanotes_d7_taxon_reference` migrations of `taxanotes_import`.

This module holds the one piece of code that configuration cannot express:
`hook_views_data_alter()` gives the target id columns of
`field_taxonomic_name` and of the bibcite `author` field the core
`entity_reference` Views filter (an autocomplete on the taxon name or the
contributor name), so the Literature page can be filtered by taxon and by
author on the field tables alone. A Views relationship to the taxon or the
contributor would list a reference once per taxon or author it has, because
Views selects the related entity's id in every row.

Nothing in this module names an instance.
