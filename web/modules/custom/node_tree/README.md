# Node tree

A lazily loaded tree of nodes, built from a parent reference field. For a
classification, a folder structure, an organisation chart: any node type
where each node names its parent.

## What it does

- **A tree page** (`/node-tree` by default) with the tree on the left and
  the selected node, rendered in full, on the right. Every node has a
  permalink, `/node-tree/node/<identifier>`, that opens the tree down to it
  with the node selected; the browser's history follows the selection.
- **A tree block** ("Node tree") that starts at the roots, or, on a tree
  node's page, opens with the node's lineage expanded and the node selected
  and scrolled into view (the rest still loading on demand). Selecting a
  node in the block opens its permalink. A second block ("Node tree:
  selected node") is the panel the selection is rendered into, so a site
  can compose a tree page of its own from the two blocks.
- **JSON endpoints** the tree loads from, usable by anything else:
  - `/node_tree/api/getImmediateChildrenOfParent?node=<identifier>`: the
    published children of a node (the roots without `node`), each with its
    identifier, title, published child count and whether it is expandable.
  - `/node_tree/api/getExpandedTreePathToNode/<identifier>`: the tree from
    the root down to the node, every level with all its siblings.
  - `/node_tree/api/getNodeTreeContent/<identifier>`: the node rendered in
    its full view mode, with its title and URL.
- **A lineage breadcrumb**: on a tree node's page, Home › root › … › parent
  › the node, replacing the path-based breadcrumb; and the same on the
  pages of nodes of other types that point at a tree node (a "describes"
  field, such as a species page's taxon).
- **Drush commands**: `node_tree:link-parents` fills the parent reference
  of every node from a parent identifier field (after an import that
  carries identifiers but no references); `node_tree:status` shows the
  settings and the node counts per type.

Children load one level at a time, with one indexed SQL statement per
level, so a tree of tens of thousands of nodes opens at once. Everything
is cached with the node list of each configured type, and the endpoints
are plain cacheable GETs.

## Requirements

- Drupal 10.3 or 11, the core Node module.
- [jqTree](https://mbraak.github.io/jqTree/) 1.6.2 (Apache-2.0). It is not
  part of the module, as drupal.org does not allow Apache-2.0 code in a
  project's repository. Either install it under the Drupal root as
  `libraries/jqtree/tree.jquery.js` and `libraries/jqtree/jqtree.css` (the
  two files of the `jqtree` npm package at that version, for example with
  `npm pack jqtree@1.6.2` or a download from
  <https://cdn.jsdelivr.net/npm/jqtree@1.6.2/>), or do nothing: without the
  local files the module loads the library from the jsDelivr CDN at the
  same version. The status report says which is in use.

## Configuration

Administration › Configuration › Content authoring › Node tree
(`/admin/config/content/node-tree`, permission *Administer the node tree*).
The module does nothing until at least one node type is chosen.

| Setting | What it is | Default |
|---|---|---|
| Node types | The node types whose nodes form the tree. | none |
| Parent field | The entity reference field (to a node) that names a node's parent; a node without a value is a root. | `field_parent` |
| Identifier field | What identifies a node in the tree URLs and JSON: `uuid`, or a field with one stable value per node (a GUID, a code). | `uuid` |
| Parent identifier field | Optional: a field on the child holding the identifier of its parent. The children of a node are then found through this field alone, and `node_tree:link-parents` fills the parent field from it. | empty |
| Weight field | Optional: an integer field that orders siblings (lowest first, then by title). | empty |
| Describes field | Optional: an entity reference field on other node types that points at the tree node a page is about, so the page gets that node's lineage as its breadcrumb. | empty |
| Tree page path | The path of the tree page; permalinks are `<path>/node/<identifier>`. | `node-tree` |
| Tree page title | | *Node tree* |
| Lineage breadcrumb | Whether tree nodes (and the pages that describe one) get the lineage breadcrumb. | on |

The same settings can be set in a recipe:

```yaml
config:
  actions:
    node_tree.settings:
      simpleConfigUpdate:
        bundles: [taxon]
        parent_field: field_parent_taxon_reference
        id_field: field_guid
        parent_id_field: field_parent_guid
        weight_field: field_weight
        describes_field: field_taxonomic_name
        path: taxonomy-tree
        title: 'Taxonomy tree'
        breadcrumb: true
```

Then place the *Node tree* block where the tree should appear (a sidebar,
the front page) and link to the tree page (route `node_tree.page`).

## Drush

```
drush node_tree:status
drush node_tree:link-parents [--bundle=<type>] [--dry-run]
```

`link-parents` needs the parent identifier field. For every node of the
configured types (or of `--bundle`) whose parent identifier names a node,
the parent field is set to that node when it is not already; nodes whose
identifier matches nothing are listed. Unpublished nodes take part.

## Theming

The tree page is `.node-tree-page` with `#node_tree.node-tree-column` and
`.node-tree-content.node-tree-content-column`; the block is
`#node_tree.node-tree-block`; the loading text is `.node-tree-loading`, the
selected node's title `.node-tree-title`. jqTree's own classes
(`.jqtree-tree`, `.jqtree-element`, `.jqtree-selected`, …) style the tree
itself. `css/node_tree.css` gives the page its two columns (stacked under
800 px) and the block a scrolling box.

## Licence

GNU General Public License, version 2 or later, like Drupal itself.
jqTree, which the module uses but does not contain, is Apache-2.0.

## Credits

Written by Rob Davis ([therobyouknow](https://www.drupal.org/u/therobyouknow))
for [Taxanotes](https://github.com/taxanotes), the Drupal 11 successor to
the Natural History Museum's Scratchpads, where it is the classification
tree of every site (the tinytax block, the taxonomy page and the
taxonomy_breadcrumb of Scratchpads, made generic). jqTree is by Marco
Braak.
