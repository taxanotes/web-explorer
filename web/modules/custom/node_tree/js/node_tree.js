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
   * /taxonomy-tree/node/<guid>, or the page is a taxon page (the block
   * passes the taxon's GUID in drupalSettings.nodeTree.selected), the tree
   * is opened down to that taxon, which is selected and scrolled into view
   * within the tree; the rest loads on demand.
   */
  Drupal.behaviors.node_tree_open_tree_at = {
    attach: function (context) {
      once('node_tree', '#node_tree', context).forEach(function (container) {
        var $container = $(container);
        var $tree = $('<div id="id_node_tree"></div>').attr('data-url', CHILDREN_URL);
        $container.append($tree);
        var onTreePage = $('.node_treecontentdisplay').length > 0;
        var guid = getGuidFromUrl() || (!onTreePage && drupalSettings.nodeTree && drupalSettings.nodeTree.selected) || null;
        if (guid) {
          $container.prepend('<p class="node-tree-loading">' + Drupal.t('Loading tree expanded to selected taxon…') + '</p>');
          $.getJSON(EXPAND_URL + encodeURIComponent(guid), function (data) {
            $container.find('.node-tree-loading').remove();
            $tree.tree({ data: data, autoOpen: false, dragAndDrop: false, dataUrl: dataUrl });
            var target = $tree.tree('getNodeById', guid);
            if (target) {
              if (onTreePage) {
                // selectNode fires tree.select, which renders the taxon.
                bindEvents($tree);
                $tree.tree('selectNode', target);
              }
              else {
                // On a taxon page the selection is only a highlight: bind
                // the events after it, so it does not navigate, and without
                // the focus jqTree would set, which scrolls the page to the
                // tree (below the content on a phone).
                $tree.tree('selectNode', target, { mustSetFocus: false });
                bindEvents($tree);
              }
              scrollIntoTree(container, target.element);
            }
            else {
              bindEvents($tree);
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

  /**
   * Scrolls the tree's own scrollable box so that an element is visible.
   *
   * Only the box scrolls (the block has a max-height), never the page: a
   * taxon page must not jump to its sidebar when the tree opens.
   */
  function scrollIntoTree(container, element) {
    if (!element) {
      return;
    }
    var box = container;
    while (box && box !== document.body && window.getComputedStyle(box).overflowY !== 'auto' && window.getComputedStyle(box).overflowY !== 'scroll') {
      box = box.parentElement;
    }
    if (!box || box === document.body) {
      return;
    }
    var boxRect = box.getBoundingClientRect();
    var rect = element.getBoundingClientRect();
    if (rect.top < boxRect.top || rect.bottom > boxRect.bottom) {
      box.scrollTop += rect.top - boxRect.top - Math.max(0, (boxRect.height - rect.height) / 2);
    }
  }

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
        // Outside the tree page (the block in a sidebar, say) there is no
        // taxon panel: selecting a taxon opens its tree permalink instead.
        if (!$('.node_treecontentdisplay').length) {
          window.location.assign(path);
          return;
        }
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
