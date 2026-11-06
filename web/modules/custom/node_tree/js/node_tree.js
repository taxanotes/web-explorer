/**
 * @param $
 * @param Drupal
 * @param drupalSettings
 * @param once
 * @file
 * Builds the lazily loaded jqTree and opens it at the selected node.
 */

(($, Drupal, drupalSettings, once) => {
  // All routes are built with Drupal.url() so a site served from a sub-path
  // (base path) works too. The tree page path comes from the settings
  // (drupalSettings.nodeTree.path, attached by the page and the block).
  const CHILDREN_URL = Drupal.url(
    'node_tree/api/getImmediateChildrenOfParent/',
  );
  const EXPAND_URL = Drupal.url('node_tree/api/getExpandedTreePathToNode/');
  const CONTENT_URL = Drupal.url('node_tree/api/getNodeTreeContent/');

  /**
   * The expander icons: a boxed minus (open) and plus (closed), as inline SVG
   * so they stay crisp at any zoom. jqTree clones the element for each node;
   * css/node_tree.css sizes the box and widens it on touch screens.
   *
   * @param {string} paths
   *   The SVG path data drawn inside the box.
   *
   * @return {jQuery}
   *   The icon element.
   */
  function expanderIcon(paths) {
    return $(
      `<svg class="node-tree-expander" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><rect x="1.5" y="1.5" width="13" height="13" rx="1.5"/><path d="${paths}"/></svg>`,
    );
  }
  const OPENED_ICON = expanderIcon('M4.5 8h7');
  const CLOSED_ICON = expanderIcon('M4.5 8h7M8 4.5v7');

  /**
   * The base of the tree permalinks: <path>/node/.
   *
   * @return {string}
   *   The URL prefix, base path included.
   */
  function treeUrl() {
    const path =
      (drupalSettings.nodeTree && drupalSettings.nodeTree.path) || 'node-tree';
    return Drupal.url(`${path}/node/`);
  }

  /**
   * The identifier of a permalink URL (<path>/node/<id>), or null.
   *
   * @return {string|null}
   *   The decoded identifier, or null when the URL is not a permalink.
   */
  function getIdFromUrl() {
    const base = treeUrl();
    const { pathname } = window.location;
    if (pathname.indexOf(base) !== 0) {
      return null;
    }
    const id = pathname.substring(base.length).split('/')[0];
    return id ? decodeURIComponent(id) : null;
  }

  /**
   * The URL jqTree loads a node's children from.
   *
   * @param {object|null} node
   *   The jqTree node being opened, or null for the roots.
   *
   * @return {string}
   *   The children endpoint, with the node's identifier when there is one.
   */
  function dataUrl(node) {
    return node
      ? `${CHILDREN_URL}?node=${encodeURIComponent(node.id)}`
      : CHILDREN_URL;
  }

  /**
   * Scrolls the tree's own scrollable box so that an element is visible.
   *
   * Only the box scrolls (the block has a max-height), never the page: a
   * node's page must not jump to its sidebar when the tree opens.
   *
   * @param {HTMLElement} container
   *   The tree's container.
   * @param {HTMLElement} element
   *   The element to bring into view.
   */
  function scrollIntoTree(container, element) {
    if (!element) {
      return;
    }
    let box = container;
    while (
      box &&
      box !== document.body &&
      window.getComputedStyle(box).overflowY !== 'auto' &&
      window.getComputedStyle(box).overflowY !== 'scroll'
    ) {
      box = box.parentElement;
    }
    if (!box || box === document.body) {
      return;
    }
    const boxRect = box.getBoundingClientRect();
    const rect = element.getBoundingClientRect();
    if (rect.top < boxRect.top || rect.bottom > boxRect.bottom) {
      box.scrollTop +=
        rect.top -
        boxRect.top -
        Math.max(0, (boxRect.height - rect.height) / 2);
    }
  }

  /**
   * Renders a node into the content panel of the tree page.
   *
   * @param {string} id
   *   The node's identifier.
   */
  function showContent(id) {
    $('.node-tree-content').html(
      `<p class="node-tree-loading">${Drupal.t('Loading…')}</p>`,
    );
    $.getJSON(CONTENT_URL + encodeURIComponent(id))
      .done((response) => {
        const $panel = $('.node-tree-content').html(response.data);
        if (response.title) {
          const title = document.createElement('h2');
          title.className = 'node-tree-title';
          title.textContent = response.title;
          $panel.prepend(title);
        }
      })
      .fail(() => {
        $('.node-tree-content').html(`<p>${Drupal.t('Not found.')}</p>`);
      });
  }

  /**
   * Binds the selection handler of a tree.
   *
   * @param {jQuery} $tree
   *   The jqTree element.
   */
  function bindEvents($tree) {
    $tree.on('tree.select', (event) => {
      if (event.node) {
        const { node } = event;
        const path = treeUrl() + encodeURIComponent(node.id);
        // Outside the tree page (the block in a sidebar, say) there is no
        // content panel: selecting a node opens its tree permalink instead.
        if (!$('.node-tree-content').length) {
          window.location.assign(path);
          return;
        }
        // The permalink load selects the node the URL already names; do not
        // push a second, identical history entry for it (compare decoded, a
        // typed identifier keeps its colons while the pushed one is encoded).
        if (
          decodeURIComponent(window.location.pathname) !==
          decodeURIComponent(path)
        ) {
          window.history.pushState(`permalink-to-${node.id}`, node.name, path);
        }
        showContent(node.id);
      }
    });
  }

  // jqTree requests its lazy loads with cache: false, which appends a
  // `_=<timestamp>` parameter and defeats the page cache for anonymous
  // visitors. The endpoints are cache-tagged and vary on the `node` query
  // argument, so plain GETs are correct.
  $.ajaxPrefilter((options) => {
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
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.nodeTreeOpenTreeAt = {
    attach(context) {
      once('node_tree', '#node_tree', context).forEach((container) => {
        const $container = $(container);
        const $tree = $('<div id="id_node_tree"></div>').attr(
          'data-url',
          CHILDREN_URL,
        );
        $container.append($tree);
        const onTreePage = $('.node-tree-content').length > 0;
        const id =
          getIdFromUrl() ||
          (!onTreePage &&
            drupalSettings.nodeTree &&
            drupalSettings.nodeTree.selected) ||
          null;
        if (id) {
          $container.prepend(
            `<p class="node-tree-loading">${Drupal.t(
              'Loading the tree at the selected node…',
            )}</p>`,
          );
          $.getJSON(EXPAND_URL + encodeURIComponent(id), (data) => {
            $container.find('.node-tree-loading').remove();
            $tree.tree({
              data,
              autoOpen: false,
              dragAndDrop: false,
              dataUrl,
              openedIcon: OPENED_ICON,
              closedIcon: CLOSED_ICON,
            });
            const target = $tree.tree('getNodeById', id);
            if (target) {
              if (onTreePage) {
                // selectNode fires tree.select, which renders the node.
                bindEvents($tree);
                $tree.tree('selectNode', target);
              } else {
                // On a node's page the selection is only a highlight: bind
                // the events after it, so it does not navigate, and without
                // the focus jqTree would set, which scrolls the page to the
                // tree (below the content on a phone).
                $tree.tree('selectNode', target, { mustSetFocus: false });
                bindEvents($tree);
              }
              scrollIntoTree(container, target.element);
            } else {
              bindEvents($tree);
            }
          });
        } else {
          $tree.tree({
            dataUrl,
            dragAndDrop: false,
            openedIcon: OPENED_ICON,
            closedIcon: CLOSED_ICON,
          });
          bindEvents($tree);
        }
      });
    },
  };
})(jQuery, Drupal, drupalSettings, once);
