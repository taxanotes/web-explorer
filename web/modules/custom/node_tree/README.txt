node_tree

The lazily loaded taxon tree (jqTree) of the taxon content type: the
/taxonomy-tree page, the JSON endpoints, the tree block, and the lineage
breadcrumb of taxon and species pages (src/Breadcrumb/TaxonBreadcrumbBuilder.php:
Home > root > ... > parent > the taxon, the species page's taxon lineage).
On a taxon page the block opens the tree at the current taxon (the lineage
expanded, the taxon selected and scrolled into view within the block, the
rest loading on demand); the GUID travels in drupalSettings.nodeTree.selected.
