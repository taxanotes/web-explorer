(function ($, Drupal, once) {
  'use strict';

  var CHILDREN_URL = '/node_tree/api/getImmediateChildrenOfParent/';

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
          $.getJSON('/node_tree/api/getExpandedTreePathToNode/' + encodeURIComponent(guid), function (data) {
            $container.find('.node-tree-loading').remove();
            $tree.tree({ data: data, autoOpen: false, dragAndDrop: false, dataUrl: dataUrl });
            bindEvents($tree);
            var target = $tree.tree('getNodeById', guid);
            if (target) {
              // selectNode fires tree.select, which renders the taxon.
              $tree.tree('selectNode', target);
              if (target.element && target.element.scrollIntoView) {
                target.element.scrollIntoView({ block: 'center' });
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
        window.history.pushState('permalink-to-' + node.id, node.name, '/taxonomy-tree/node/' + encodeURIComponent(node.id));
        showContent(node.id);
      }
    });
  }

  function showContent(guid) {
    $('.node_treecontentdisplay').html('<p class="node-tree-loading">' + Drupal.t('Loading…') + '</p>');
    $.getJSON('/node_tree/api/getNodeTreeContent/' + encodeURIComponent(guid))
      .done(function (response) {
        $('.node_treecontentdisplay').html(response.data);
      })
      .fail(function () {
        $('.node_treecontentdisplay').html('<p>' + Drupal.t('Taxon not found.') + '</p>');
      });
  }

  function getGuidFromUrl() {
    var match = window.location.pathname.match(/\/taxonomy-tree\/node\/([^/]+)/);
    return match ? decodeURIComponent(match[1]) : null;
  }

})(jQuery, Drupal, once);
