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
| main menu | static links *Classification* (`/taxonomy-tree`) and *About* (`/about`); *Home* is the standard profile's, *Taxa* the taxa view's |

Texts are rendered as `#markup` (Drupal's admin XSS filter applies); keep
them to simple HTML.
