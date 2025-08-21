(function ($, Drupal) {
    Drupal.behaviors.node_tree = {
      attach: function (context, settings) {
      // expecting to remove this if testing confirms everything still works
    }
  };

  // data-portal/node/12345
  Drupal.behaviors.node_tree_open_tree_at = {
    attach: function (context, settings) {
      var nodeId = getNodeIdFromUrl();

      if ( nodeId !== undefined ) {
        var newdiv2 =  $(document.createElement('div')).attr('id', 'node_treetreeloading');
        newdiv2.text('Loading tree expanded to selected taxon...');
        $('#block-node_treehierarchicaltaxontree-2').append(newdiv2);
        $.get('/node_tree/api/getExpandedTreePathToNode/' + nodeId, getExpandedTreePathToNodeCallback );
      }
      else {
        var newdiv2 =  $(document.createElement('div')).attr('id', 'node_treetree');
        newdiv2.attr('data-url', '/node_tree/api/getImmediateChildrenOfParentnode_tree/');
        $('#block-node_treehierarchicaltaxontree-2').append(newdiv2);
          
        var node_treetree = $('#node_treetree').tree({
        });
      
        node_treeTreeEvents( node_treetree );
      }
    }
  };

  var getImmediateChildrenOfParentnode_treeCallback = function(response) {

  }


  // e.g. species name https://taxanotes.ddev.site/data-portal/node/guid
  // guid

  /**
   * 
   * @param {*} response 
   */
  var getExpandedTreePathToNodeCallback = function(response) {
    // https://github.com/mbraak/jqTree/issues/301#issuecomment-133307420
    $('#node_treetreeloading').remove();

    var newdiv2 =  $(document.createElement('div')).attr('id', 'node_treetree');
    newdiv2.attr('data-url', '/node_tree/api/getImmediateChildrenOfParentnode_tree/');
    $('#block-node_treehierarchicaltaxontree-2').append(newdiv2);

    //$('#node_treetree').attr('data-url', '/node_tree/api/getImmediateChildrenOfParentnode_tree/');

    var node_treetree = $('#node_treetree').tree({
      data: response,
      autoOpen: true,
      saveState: true,
    });

    node_treeTreeEvents( node_treetree );

    $( "<div id='reloadtreemessage'><p>Permalink view. <a href='/data-portal'>click here</a> to re-explore tree</p><div>" ).insertAfter( "#block-node_treehierarchicaltaxontree-2" );
  }


  /**
   * 
   * @param {*} node_treetree 
   */
  var node_treeTreeEvents = function( node_treetree ) {
    node_treetree.on(
      'tree.select',
      function(event) {
        if (event.node) {
          // https://mbraak.github.io/jqTree/#event-tree-select

          // change browser address bar URL to link directly to the node, for saving in bookmarks, history, sharing
          // https://stackoverflow.com/a/3503206/227926
          var node = event.node;
          window.history.pushState('permalink-to-' + node.id, node.name, '/data-portal/node/' + node.id );

          // https://mbraak.github.io/jqTree/#functions-loaddata
          //

          //$.get('/node_tree/api/getImmediateChildrenOfParentnode_tree/', { node: node.id }, getImmediateChildrenOfParentnode_treeCallback );

          // get the node_tree node content for display on the right hand side

          $.get('/node_tree/api/getnode_treeContent/' + node.id, node_treeContentCallback);  
            
          taxon = node.name; // node.name is the node title field

          if ( taxon !== '' ) {
            var params = {
              'taxon': taxon,
            }

            console.log( '[' + taxon + ']' );

            $.get('/node_tree/api/noticeProgressnode_treeata', callbackNoticeProgressnode_treeata );

            $.get('/node_tree/externalapi', params, getnode_treeataCallback);  // TO DO  - to remove 
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
  var node_treeContentCallback = function(response) {
    console.log(response);
    // Replaces #node_treecontentdisplay with article - #node_treecontentdisplay doesn't exist?? 

    $('.node_treecontentdisplay').html(response.data);
  }    

  /**
   * 
   * @param {*} response 
   */
  var getnode_treeataCallback = function(response) {
    if ( Object.keys(response.data).length > 0 ) {

      console.dir(response.data);

      console.dir( Object.keys(response.data).length );

      // just relay the response.data content onto the next api call, no need to package or format
      $.post( '/node_tree/api/getnode_treeataOutput', response.data, getnode_treeataDisplayCallback, 'text' );
    }
  }   

  /**
   * 
   * @param {*} response 
   */
  var getnode_treeataDisplayCallback  = function(response) {
    //console.log(response);

    var responseAsObject = JSON.parse( response );

    $('.node_treeisplaycontainer').empty();

    $('.node_treeisplaycontainer').html(responseAsObject.html);

  }

  /**
   * 
   * @param {*} response 
   */
  var callbackNoticeProgressnode_treeata = function(response) {

    // not required, don't know why
    //var responseAsObject = JSON.parse( response );


    $('.node_treeisplaycontainer').empty();

    // var responseAsObject = response.html;
    $('.node_treeisplaycontainer').html(response.html);
  }


  /**
   * 
   * @returns 
   */
  var getNodeIdFromUrl = function() {
    var path = window.location.pathname;
  
    // [A-Z0-9]
    // var regexForDataPortalPath = /\/data\-portal\/node\/\d+/m;
    var regexForDataPortalPath = /\/data\-portal\/node\/[A-Z0-9]+/m;

    var nodeUrlFrags = path.match(regexForDataPortalPath);

    if (nodeUrlFrags) {
      var nodeId = path.split("/")[3];
    }

    return nodeId;
  }
    
})(jQuery, Drupal);