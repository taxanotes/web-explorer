(function ($, Drupal) {
  /*
  Drupal.behaviors.node_tree = {
    attach: function (context, settings) {
      // expecting to remove this if testing confirms everything still works
    }
  };
  */

  // taxonomy-tree/node/12345
  Drupal.behaviors.node_tree_open_tree_at = {
    attach: function (context, settings) {

      var nodeId = getNodeIdFromUrl();

      if (nodeId !== undefined) {

        newdiv2.text('Loading tree expanded to selected taxon...');
        $('#node_tree').append(newdiv2);
        $.get('/node_tree/api/getExpandedTreePathToNode/' + nodeId, getExpandedTreePathToNodeCallback);
      }
      else {
        var newdiv2 = $(document.createElement('div')).attr('id', 'id_node_tree');
        newdiv2.attr('data-url', '/node_tree/api/getImmediateChildrenOfParent/');
        $('#node_tree').append(newdiv2);

        var id_node_tree = $('#id_node_tree').tree({
        });

        id_node_treeEvents(id_node_tree);
      }
    }
  };

  // e.g. species name https://taxanotes.ddev.site/taxonomy-tree/node/guid
  // guid

  /**
   * 
   * @param {*} response 
   */
  var getExpandedTreePathToNodeCallback = function (response) {
    // https://github.com/mbraak/jqTree/issues/301#issuecomment-133307420

    var newdiv2 = $(document.createElement('div')).attr('id', 'id_node_tree');
    newdiv2.attr('data-url', '/node_tree/api/getImmediateChildrenOfParent/');
    $('#id_node_tree').append(newdiv2);

    //$('#id_node_tree').attr('data-url', '/node_tree/api/getImmediateChildrenOfParentnode_tree/');

    var id_node_tree = $('#id_node_tree').tree({
      data: response,
      autoOpen: true,
      saveState: true,
    });

    id_node_treeEvents(id_node_tree);

    $("<div id='reloadtreemessage'><p>Permalink view. <a href='/taxonomy-tree'>click here</a> to re-explore tree</p><div>").insertAfter("#id_node_tree");
  }


  /**
   * 
   * @param {*} id_node_tree 
   */
  var id_node_treeEvents = function (id_node_tree) {
    id_node_tree.on(
      'tree.select',
      function (event) {
        if (event.node) {
          // https://mbraak.github.io/jqTree/#event-tree-select

          // change browser address bar URL to link directly to the node, for saving in bookmarks, history, sharing
          // https://stackoverflow.com/a/3503206/227926
          var node = event.node;
          window.history.pushState('permalink-to-' + node.id, node.name, '/taxonomy-tree/node/' + node.id);

          // https://mbraak.github.io/jqTree/#functions-loaddata
          //

          //$.get('/node_tree/api/getImmediateChildrenOfParentnode_tree/', { node: node.id }, getImmediateChildrenOfParentnode_treeCallback );

          // get the node_tree node content for display on the right hand side

          $.get('/node_tree/api/getNodeTreeContent/' + node.id, node_treeContentCallback);

          taxon = node.name; // node.name is the node title field

          if (taxon !== '') {
            var params = {
              'taxon': taxon,
            }

            console.log('[' + taxon + ']');

            /*
            $.get('/node_tree/api/noticeProgressnode_treeata', callbackNoticeProgressnode_treeata );

            $.get('/node_tree/externalapi', params, getnode_treeataCallback);  // TO DO  - to remove 
            */
          }
          else {
            // event.node is null
            // a node was deselected
            // e.previous_node contains the deselected node
          }

          event.stopPropagation();
          return false;
        }
      }
    );

    // need event for /node_tree/api/getImmediateChildrenOfParentnode_tree/
  }

  /**
   * 
   * @param {*} response 
   */
  var node_treeContentCallback = function (response) {
    console.log(response);
    // Replaces #node_treecontentdisplay with article - #node_treecontentdisplay doesn't exist?? 

    $('.node_treecontentdisplay').html(response.data);
  }


  /**
   * 
   * @param {*} response 
   */
  var getnode_treeataDisplayCallback = function (response) {
    //console.log(response);

    var responseAsObject = JSON.parse(response);

    $('.node_treeisplaycontainer').empty();

    $('.node_treeisplaycontainer').html(responseAsObject.html);

  }

  /**
   * 
   * @returns 
   */
  var getNodeIdFromUrl = function () {
    var path = window.location.pathname;

    // [A-Z0-9]
    // var regexForDataPortalPath = /\/data\-portal\/node\/\d+/m;
    var regexForDataPortalPath = /\/taxonomy\-tree\/node\/[A-Z0-9]+/m;

    var nodeUrlFrags = path.match(regexForDataPortalPath);

    if (nodeUrlFrags) {
      var nodeId = path.split("/")[3];
    }

    return nodeId;
  }

})(jQuery, Drupal);