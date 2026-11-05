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
| the Scratchpads `global.css` and `tabs.css`, ported (GPL-2, Natural History Museum, London; notices in the files), the Inter font, the tab border image, the Scratchpads logo as the default logo | `css/global.css`, `css/tabs.css`, `fonts/`, `images/`, `logo.png` |
| the accent colour: `taxanotes_classic.settings:colour` (six hex digits, default 047DB8) → `--sp-colour` on `:root` + body class `scratchpad-colour-<hex>` | `taxanotes_classic.theme`, `theme-settings.php`, `config/` |
| the front page: welcome + slideshow grid, the three columns | `css/front.css`, `js/classic.js` (slideshow) |
| the taxon page: tabs from the content blocks, the authors as the title's subtitle, the stripy field group | `css/taxon.css`, `js/classic.js`, `taxanotes_classic_preprocess_block()` |
| the phone menu toggle | `js/classic.js`, `css/tabs.css` |

Regions are named after Olivero's (`header`, `primary_menu`,
`secondary_menu`, `highlighted`, `breadcrumb`, `content_above`, `content`,
`sidebar`, `content_below`, `footer_top`, `footer_bottom`, plus `search` and
`help`): when an instance switches from Olivero, `block_theme_initialize()`
copies its blocks into the regions of the same name.
