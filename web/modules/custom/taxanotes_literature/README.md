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

This module holds the pieces of code that configuration cannot express.

The breadcrumb of a reference page is *Home › Literature › the reference*
(`src/Breadcrumb/ReferenceBreadcrumbBuilder.php`, ahead of core's
path-based builder). The reference page itself renders the default display
(the recipe sets bibcite's `reference_page_view_mode` to `default`): the
citation line first, then the abstract, keywords, taxa, attachment, DOI,
URL, notes and the export links as labelled rows, every field the citation
already carries hidden.

`taxanotes_literature.post_update.php` carries platform changes to the
`taxanotes_literature` view, which the recipe creates only once, to an
existing instance (`drush updatedb`, which the instance scripts run): the
Literature table as Year, Title, Authors, Type with Apply and Reset,
applied only while the instance still has the earlier layout.

The `taxanotes_citation` text filter (`src/Plugin/Filter/FilterCitation.php`)
renders the Scratchpads inline citations: a text cites a reference as
`[bib]nid[/bib]` (the Drupal 7 biblio filter markup, the nid of the biblio
node), which becomes a numbered link to the migrated reference, and the
references the text cites are listed under it in citation order, as the
biblio filter did. The nid is resolved through the reference's
`field_scratchpads_nid` (the import fills it); a nid no reference carries is
left as written. The recipe enables the filter on `basic_html`, the format
the migrations give the Scratchpads texts, after `filter_html`.

`hook_views_data_alter()` gives the target id columns of
`field_taxonomic_name` and of the bibcite `author` field the core
`entity_reference` Views filter (an autocomplete on the taxon name or the
contributor name), so the Literature page can be filtered by taxon and by
author on the field tables alone. A Views relationship to the taxon or the
contributor would list a reference once per taxon or author it has, because
Views selects the related entity's id in every row.

Nothing in this module names an instance.
