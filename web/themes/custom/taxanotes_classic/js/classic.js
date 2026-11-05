/**
 * @file
 * Taxanotes classic: the phone menu toggle, the front page slideshow and
 * the taxon page tabs. Plain DOM, no jQuery; everything degrades to the
 * stacked markup without JavaScript.
 */

(function (Drupal, once) {

  'use strict';

  /**
   * The main menu: a toggle button on a phone (css/tabs.css hides the list
   * below 740 px until it is open).
   */
  Drupal.behaviors.taxanotesClassicMenu = {
    attach: function (context) {
      once('taxanotes-classic-menu', '#region-menu', context).forEach(function (region) {
        var menu = region.querySelector('ul.menu');
        if (!menu) {
          return;
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'menu-toggle';
        button.setAttribute('aria-expanded', 'false');
        button.textContent = Drupal.t('Menu');
        button.addEventListener('click', function () {
          var open = region.classList.toggle('menu-open');
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        region.insertBefore(button, region.firstChild);
      });
    }
  };

  /**
   * The slideshow: the view's rows (large image, thumbnail, name) become
   * one visible slide plus a thumbnail pager, advancing every six seconds
   * (the Scratchpads used Views Slideshow's cycle with thumbnails).
   */
  Drupal.behaviors.taxanotesClassicSlideshow = {
    attach: function (context) {
      once('taxanotes-classic-slideshow', '.taxanotes-slideshow', context).forEach(function (view) {
        var rows = Array.prototype.slice.call(view.querySelectorAll('.views-row'));
        if (rows.length < 1) {
          return;
        }
        var pager = document.createElement('div');
        pager.className = 'slideshow-pager';
        var thumbs = [];
        rows.forEach(function (row, i) {
          var thumb = row.querySelector('.views-field-field-media-image-1');
          if (thumb) {
            thumb.setAttribute('role', 'button');
            thumb.setAttribute('tabindex', '0');
            thumb.setAttribute('aria-label', Drupal.t('Show image @n', {'@n': i + 1}));
            thumb.addEventListener('click', function () { show(i, true); });
            thumb.addEventListener('keydown', function (e) {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                show(i, true);
              }
            });
            pager.appendChild(thumb);
          }
          thumbs.push(thumb);
        });
        var content = view.querySelector('.view-content') || view;
        content.parentNode.insertBefore(pager, content.nextSibling);

        var current = 0;
        var timer = null;
        function show(i, manual) {
          rows[current].classList.remove('is-active');
          if (thumbs[current]) {
            thumbs[current].classList.remove('is-active');
          }
          current = i;
          rows[current].classList.add('is-active');
          if (thumbs[current]) {
            thumbs[current].classList.add('is-active');
          }
          if (manual) {
            restart();
          }
        }
        function next() {
          show((current + 1) % rows.length, false);
        }
        function restart() {
          if (timer) {
            window.clearInterval(timer);
          }
          timer = rows.length > 1 ? window.setInterval(next, 6000) : null;
        }
        view.addEventListener('mouseenter', function () {
          if (timer) {
            window.clearInterval(timer);
            timer = null;
          }
        });
        view.addEventListener('mouseleave', restart);
        show(0, false);
        restart();
      });
    }
  };

  /**
   * The taxon page tabs: Overview (the taxon's fields and classification),
   * then one tab per marked block (data-taxanotes-tab: Descriptions, Images,
   * Literature) that has content. The hash selects a tab (#literature), so
   * links into a tab keep working.
   */
  Drupal.behaviors.taxanotesClassicTaxonTabs = {
    attach: function (context) {
      if (!document.body.classList.contains('page-node-type-taxon')) {
        return;
      }
      // The authors after the name, as the Scratchpads page title had them.
      once('taxanotes-classic-subtitle', '.node--type-taxon .field--name-field-authors', context).forEach(function (authors) {
        var title = document.querySelector('#region-content h1.page-title');
        var text = authors.textContent.trim();
        if (title && text) {
          var span = document.createElement('span');
          span.className = 'subtitle';
          span.textContent = text;
          title.appendChild(document.createTextNode(' '));
          title.appendChild(span);
          authors.remove();
        }
      });
      once('taxanotes-classic-tabs', '#region-content .region-content', context).forEach(function (region) {
        var blocks = Array.prototype.slice.call(region.children).filter(function (el) {
          return el.classList.contains('block');
        });
        if (!blocks.length) {
          return;
        }
        var panels = [];
        var overview = document.createElement('div');
        overview.className = 'taxanotes-tab-panel';
        overview.id = 'overview';
        panels.push({id: 'overview', label: Drupal.t('Overview'), panel: overview});

        blocks.forEach(function (block) {
          var id = block.getAttribute('data-taxanotes-tab');
          if (!id) {
            overview.appendChild(block);
            return;
          }
          // A view that rendered only its empty text has nothing to tab.
          if (block.querySelector('.view-empty') && !block.querySelector('.view-content')) {
            block.remove();
            return;
          }
          var panel = document.createElement('div');
          panel.className = 'taxanotes-tab-panel';
          panel.id = id;
          panel.appendChild(block);
          panels.push({id: id, label: block.getAttribute('data-taxanotes-tab-label') || id, panel: panel});
        });
        if (panels.length < 2) {
          region.appendChild(overview);
          return;
        }

        var tabs = document.createElement('div');
        tabs.className = 'tabs taxanotes-tabs clearfix';
        var list = document.createElement('ul');
        list.className = 'tabs primary clearfix';
        list.setAttribute('role', 'tablist');
        tabs.appendChild(list);
        region.appendChild(tabs);

        var links = [];
        panels.forEach(function (entry) {
          var li = document.createElement('li');
          var a = document.createElement('a');
          a.href = '#' + entry.id;
          a.textContent = entry.label;
          a.setAttribute('role', 'tab');
          a.addEventListener('click', function (e) {
            e.preventDefault();
            activate(entry.id);
            if (window.history && window.history.replaceState) {
              window.history.replaceState(null, '', '#' + entry.id);
            }
          });
          li.appendChild(a);
          list.appendChild(li);
          links.push({id: entry.id, li: li, a: a});
          entry.panel.setAttribute('role', 'tabpanel');
          region.appendChild(entry.panel);
        });

        function activate(id) {
          panels.forEach(function (entry) {
            var on = entry.id === id;
            entry.panel.hidden = !on;
          });
          links.forEach(function (link) {
            var on = link.id === id;
            link.li.classList.toggle('is-active', on);
            link.a.classList.toggle('is-active', on);
            link.a.setAttribute('aria-selected', on ? 'true' : 'false');
          });
        }
        var wanted = window.location.hash.replace('#', '');
        var found = panels.some(function (entry) { return entry.id === wanted; });
        activate(found ? wanted : 'overview');
        window.addEventListener('hashchange', function () {
          var h = window.location.hash.replace('#', '');
          if (panels.some(function (entry) { return entry.id === h; })) {
            activate(h);
          }
        });
      });
    }
  };

})(Drupal, once);
