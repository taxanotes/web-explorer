# jqTree 1.6.2

[jqTree](https://mbraak.github.io/jqTree/) by Marco Braak, copyright 2021,
licensed under the Apache License, Version 2.0 (the notice is at the top of
`tree.jquery.js`). The two files are `tree.jquery.js` and `jqtree.css` of the
`jqtree` npm package at that version, unchanged apart from the removed
`//# sourceMappingURL=tree.jquery.js.map` comment at the end of
`tree.jquery.js` (the source map is not shipped, so browsers would ask for
a file that is not there whenever devtools are open).

The `node_tree` module (`web/modules/custom/node_tree`) serves the library
from here (`/libraries/jqtree/`); when the directory is absent it falls back
to the jsDelivr CDN at the same version. The library is kept outside the
module because drupal.org does not allow Apache-2.0 code in a project's
repository.
