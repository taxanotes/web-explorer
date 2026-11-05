# taxanotes_front

The front page, the About page and the front-page blocks of a Taxanotes
instance. Everything instance-specific is **configuration**
(`taxanotes_front.settings`): the front page title and introduction, the
About page title and body, and a footer text. The `taxanotes_front` recipe
seeds them from `instances/<slug>/instance.yml` (recipe inputs) and places
the blocks; the texts are editable afterwards at
`/admin/config/system/taxanotes-front` (Configuration > System > Taxanotes
front page). Nothing in this module names an instance.

| What | Where |
|---|---|
| `/front` | the front page body (`front_intro`); `system.site:page.front` points here. Title: `front_title`, or "Welcome to *site name*" |
| `/about` | placeholder About page (`about_title`, `about_body`) until the Scratchpads pages are migrated |
| block `taxanotes_front_text` | shows one of the texts (setting `text_key`: `front_intro`, `about_body`, `footer_text`) |
| block `taxanotes_front_search` | a GET form to the taxa view (`/taxa?name=…`) |
| block `taxanotes_front_summary` | published taxa in all and per main rank, with links to the tree and the list |
| block `taxanotes_front_header_search` | the Scratchpads header search: a text box with *All / Taxonomy*, a GET form to `/find` |
| `/find?q=…&facet=all\|taxonomy` | *Taxonomy* redirects to `/taxa?name=…`; *All* is a results page of the taxa, references, species pages and pages whose name or title contains the terms (ten each, a link to the full filtered list), no search index |
| block `taxanotes_front_recent` | the three columns of the Scratchpads front page: *Recent Pages* (the first column's node type is a block setting, `page` by default, page again when the type is absent), *Recently added literature*, *Recently added taxon descriptions* (each links to its taxon's Descriptions tab); three items with "author - date" (the date alone for content the import created), "None yet." when empty; template `taxanotes-front-recent-columns.html.twig` |
| main menu | static links *Classification* (`/taxonomy-tree`) and *About* (`/about`); *Home* is the standard profile's, *Taxa* the taxa view's |

Texts are rendered as `#markup` (Drupal's admin XSS filter applies); keep
them to simple HTML.
