# Taxanotes classic

The classic view: the Scratchpads (Drupal 7) front end reproduced on
Drupal 11, so a site owner sees their site as they know it. Every instance
with `site.theme: taxanotes_classic` in `instances/<slug>/instance.yml` gets
it through `recipes/taxanotes_classic` (see `instances/README.md`, "The
classic view").

| Part | Where |
|---|---|
| no base theme; the markup is core's module templates plus the Starterkit template set (`templates/`, copied from `core/themes/starterkit_theme`, which carries the classes core's module templates no longer have); `page.html.twig`, the branding block and the local tasks are this theme's own | `taxanotes_classic.info.yml`, `templates/` |
| the Omega zones and grid (960 px from 980 px, 1200 px from 1220 px, 720 px from 740 px, stacked below) with the Drupal 7 ids | `templates/layout/page.html.twig`, `css/layout.css` |
| the Scratchpads `global.css` and `tabs.css`, ported (GPL-2, Natural History Museum, London; notices in the files), the tab border image | `css/global.css`, `css/tabs.css`, `images/` |
| the Inter font (Medium), SIL Open Font License 1.1: the licence text accompanies the font files, as the OFL requires | `fonts/Inter/`, `fonts/Inter/LICENSE.txt` |
| the default logo: the Scratchpads logo from the GPL-2 Scratchpads theme (the repository's licence); every instance replaces it with its own (`instances/<slug>/assets/logo.png`, set as the theme's logo path by the instance scripts), so it is seen only on a site without one | `logo.png` |
| the accent colour: `taxanotes_classic.settings:colour` (six hex digits, default 047DB8) → `--sp-colour` as the `style` attribute of the `html` element (an inline declaration, so it beats the stylesheet's `:root` fallback) + body class `scratchpad-colour-<hex>` | `taxanotes_classic.theme`, `theme-settings.php`, `config/` |
| the front page: welcome + slideshow grid, the three columns | `css/front.css`, `js/classic.js` (slideshow) |
| the taxon page: tabs from the content blocks, the authors as the title's subtitle, the stripy field group (the species page in the Descriptions tab uses the same group, its images as a grid, no row title under the block bar); the Nomenclature section's rows continue the group; the lineage breadcrumb (node_tree); the classification tree opened at the taxon in a box that scrolls within itself | `css/taxon.css`, `js/classic.js`, `taxanotes_classic_preprocess_block()`, `templates/content/node--taxon-description.html.twig` |
| the reference page: the citation line, then labelled rows like the taxon's; the Literature page's filters as one row (title, year, type, taxon, author, Apply/Reset) | `css/taxon.css`, `css/global.css` |
| the footer logos row (block `taxanotes_front_footer_logos`, between the footer menu and the licence line; the platform ships no logo) | `taxanotes_front/css/taxanotes_front.css` |
| the phone menu toggle | `js/classic.js`, `css/tabs.css` |

Regions are named after Olivero's (`header`, `primary_menu`,
`secondary_menu`, `highlighted`, `breadcrumb`, `content_above`, `content`,
`sidebar`, `content_below`, `footer_top`, `footer_bottom`, plus `search` and
`help`): when an instance switches from Olivero, `block_theme_initialize()`
copies its blocks into the regions of the same name.
