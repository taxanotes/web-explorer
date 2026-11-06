/**
 * @file
 * Builds the lazily loaded jqTree and opens it at the selected node.
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // All routes are built with Drupal.url() so a site served from a sub-path
  // (base path) works too. The tree page path comes from the settings
  // (drupalSettings.nodeTree.path, attached by the page and the block).
  var CHILDREN_URL = Drupal.url('node_tree/api/getImmediateChildrenOfParent/');
  var EXPAND_URL = Drupal.url('node_tree/api/getExpandedTreePathToNode/');
  var CONTENT_URL = Drupal.url('node_tree/api/getNodeTreeContent/');

  function treeUrl() {
    var path = (drupalSettings.nodeTree && drupalSettings.nodeTree.path) || 'node-tree';
    return Drupal.url(path + '/node/');
  }

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
   * Builds the jqTree inside #node_tree.
   *
   * If the URL is a permalink (<path>/node/<id>), or the page is a tree
   * node's page (the block passes the node's identifier in
   * drupalSettings.nodeTree.selected), the tree is opened down to that node,
   * which is selected and scrolled into view within the tree; the rest loads
   * on demand.
   */
  Drupal.behaviors.nodeTreeOpenTreeAt = {
    attach: function (context) {
      once('node_tree', '#node_tree', context).forEach(function (container) {
        var $container = $(container);
        var $tree = $('<div id="id_node_tree"></div>').attr('data-url', CHILDREN_URL);
        $container.append($tree);
        var onTreePage = $('.node-tree-content').length > 0;
        var id = getIdFromUrl() || (!onTreePage && drupalSettings.nodeTree && drupalSettings.nodeTree.selected) || null;
        if (id) {
          $container.prepend('<p class="node-tree-loading">' + Drupal.t('Loading the tree at the selected node…') + '</p>');
          $.getJSON(EXPAND_URL + encodeURIComponent(id), function (data) {
            $container.find('.node-tree-loading').remove();
            $tree.tree({ data: data, autoOpen: false, dragAndDrop: false, dataUrl: dataUrl });
            var target = $tree.tree('getNodeById', id);
            if (target) {
              if (onTreePage) {
                // selectNode fires tree.select, which renders the node.
                bindEvents($tree);
                $tree.tree('selectNode', target);
              }
              else {
                // On a node's page the selection is only a highlight: bind
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
   * node's page must not jump to its sidebar when the tree opens.
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
        var path = treeUrl() + encodeURIComponent(node.id);
        // Outside the tree page (the block in a sidebar, say) there is no
        // content panel: selecting a node opens its tree permalink instead.
        if (!$('.node-tree-content').length) {
          window.location.assign(path);
          return;
        }
        // The permalink load selects the node the URL already names; do not
        // push a second, identical history entry for it (compare decoded, a
        // typed identifier keeps its colons while the pushed one is encoded).
        if (decodeURIComponent(window.location.pathname) !== decodeURIComponent(path)) {
          window.history.pushState('permalink-to-' + node.id, node.name, path);
        }
        showContent(node.id);
      }
    });
  }

  function showContent(id) {
    $('.node-tree-content').html('<p class="node-tree-loading">' + Drupal.t('Loading…') + '</p>');
    $.getJSON(CONTENT_URL + encodeURIComponent(id))
      .done(function (response) {
        var $panel = $('.node-tree-content').html(response.data);
        if (response.title) {
          $panel.prepend($('<h2 class="node-tree-title"></h2>').text(response.title));
        }
      })
      .fail(function () {
        $('.node-tree-content').html('<p>' + Drupal.t('Not found.') + '</p>');
      });
  }

  // The identifier of a permalink URL (<path>/node/<id>), or null.
  function getIdFromUrl() {
    var base = treeUrl();
    var pathname = window.location.pathname;
    if (pathname.indexOf(base) !== 0) {
      return null;
    }
    var id = pathname.substring(base.length).split('/')[0];
    return id ? decodeURIComponent(id) : null;
  }

})(jQuery, Drupal, drupalSettings, once);
