(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // All routes are built with Drupal.url() so an instance served from a
  // sub-path (base path) works too.
  var CHILDREN_URL = Drupal.url('node_tree/api/getImmediateChildrenOfParent/');
  var EXPAND_URL = Drupal.url('node_tree/api/getExpandedTreePathToNode/');
  var CONTENT_URL = Drupal.url('node_tree/api/getNodeTreeContent/');
  var TREE_URL = Drupal.url('taxonomy-tree/node/');

  // jqTree requests its lazy loads with cache: false, which appends a
  // `_=<timestamp>` parameter and defeats the page cache for anonymous
  // visitors. The endpoints are cache-tagged and vary on the `node` query
  // argument, so plain GETs are correct.
  $.ajaxPrefilter(function (options) {
    if (options.url && options.url.indexOf(CHILDREN_URL) === 0) {
      options.cache = true;
    }
  });

  /**
   * Builds the jqTree inside #node_tree. If the URL is
   * /taxonomy-tree/node/<guid> the tree is opened down to that taxon.
   */
  Drupal.behaviors.node_tree_open_tree_at = {
    attach: function (context) {
      once('node_tree', '#node_tree', context).forEach(function (container) {
        var $container = $(container);
        var $tree = $('<div id="id_node_tree"></div>').attr('data-url', CHILDREN_URL);
        $container.append($tree);
        var guid = getGuidFromUrl();
        if (guid) {
          $container.prepend('<p class="node-tree-loading">' + Drupal.t('Loading tree expanded to selected taxon…') + '</p>');
          $.getJSON(EXPAND_URL + encodeURIComponent(guid), function (data) {
            $container.find('.node-tree-loading').remove();
            $tree.tree({ data: data, autoOpen: false, dragAndDrop: false, dataUrl: dataUrl });
            bindEvents($tree);
            var target = $tree.tree('getNodeById', guid);
            if (target) {
              // selectNode fires tree.select, which renders the taxon.
              $tree.tree('selectNode', target);
              if (target.element && target.element.scrollIntoView) {
                // Scroll within the tree column only, so the content column
                // stays in view.
                target.element.scrollIntoView({ block: 'nearest' });
              }
            }
          });
        }
        else {
          $tree.tree({ dataUrl: dataUrl, dragAndDrop: false });
          bindEvents($tree);
        }
      });
    }
  };

  // jqTree calls this with the node being opened (or null for the roots).
  function dataUrl(node) {
    return node ? CHILDREN_URL + '?node=' + encodeURIComponent(node.id) : CHILDREN_URL;
  }

  function bindEvents($tree) {
    $tree.on('tree.select', function (event) {
      if (event.node) {
        var node = event.node;
        var path = TREE_URL + encodeURIComponent(node.id);
        // The permalink load selects the node the URL already names; do not
        // push a second, identical history entry for it (compare decoded, a
        // typed LSID keeps its colons while the pushed one is encoded).
        if (decodeURIComponent(window.location.pathname) !== decodeURIComponent(path)) {
          window.history.pushState('permalink-to-' + node.id, node.name, path);
        }
        showContent(node.id);
      }
    });
  }

  function showContent(guid) {
    $('.node_treecontentdisplay').html('<p class="node-tree-loading">' + Drupal.t('Loading…') + '</p>');
    $.getJSON(CONTENT_URL + encodeURIComponent(guid))
      .done(function (response) {
        var $panel = $('.node_treecontentdisplay').html(response.data);
        if (response.title) {
          $panel.prepend($('<h2 class="node-tree-taxon-title"></h2>').text(response.title));
        }
      })
      .fail(function () {
        $('.node_treecontentdisplay').html('<p>' + Drupal.t('Taxon not found.') + '</p>');
      });
  }

  function getGuidFromUrl() {
    var match = window.location.pathname.match(/\/taxonomy-tree\/node\/([^/]+)/);
    return match ? decodeURIComponent(match[1]) : null;
  }

})(jQuery, Drupal, drupalSettings, once);
