/**
 * @file
 * "Find Event" typeahead (Event Search Block).
 *
 * ARIA combobox: type 2+ characters, suggestions load from the block's
 * endpoint, Up/Down move through them, Enter (on a highlighted one) or a
 * click opens the event, Esc closes. Enter with nothing highlighted does
 * nothing. All text is inserted with textContent (never as HTML).
 */
(function (Drupal, once) {
  'use strict';

  var MIN_CHARS = 2;
  var DEBOUNCE_MS = 200;

  Drupal.behaviors.cscEventSearch = {
    attach: function (context) {
      once('csc-event-search', '[data-csc-event-search]', context).forEach(init);
    }
  };

  function init(root) {
    var input = root.querySelector('.csc-event-search__input');
    var past = root.querySelector('.csc-event-search__past-input');
    var list = root.querySelector('.csc-event-search__list');
    var status = root.querySelector('.csc-event-search__status');
    var form = root.querySelector('form');
    var endpoint = root.getAttribute('data-endpoint');
    var limit = root.getAttribute('data-limit') || '8';
    var timer = null;
    var controller = null;
    var active = -1;

    form.addEventListener('submit', function (e) { e.preventDefault(); });

    function options() {
      return list.querySelectorAll('[role="option"]');
    }

    function setActive(index) {
      var items = options();
      active = index;
      items.forEach(function (li, i) {
        var on = i === index;
        li.classList.toggle('is-active', on);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) {
          li.scrollIntoView({block: 'nearest'});
        }
      });
      if (index >= 0 && items[index]) {
        input.setAttribute('aria-activedescendant', items[index].id);
      }
      else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function setExpanded(open) {
      list.hidden = !open;
      input.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!open) {
        setActive(-1);
      }
    }

    function render(results) {
      list.textContent = '';
      active = -1;
      if (!results.length) {
        var none = document.createElement('li');
        none.className = 'csc-event-search__none';
        none.setAttribute('role', 'presentation');
        none.textContent = Drupal.t('No events found');
        list.appendChild(none);
        status.textContent = Drupal.t('No events found');
      }
      results.forEach(function (r, i) {
        var li = document.createElement('li');
        li.id = list.id + '-opt-' + i;
        li.className = 'csc-event-search__option';
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.dataset.url = r.url;

        var title = document.createElement('span');
        title.className = 'csc-event-search__title';
        title.textContent = r.title;
        li.appendChild(title);

        var meta = document.createElement('span');
        meta.className = 'csc-event-search__meta';
        meta.textContent = (r.past ? Drupal.t('Past') + ' · ' : '') + r.date;
        if (r.past) {
          li.classList.add('is-past');
        }
        li.appendChild(meta);

        list.appendChild(li);
      });
      if (results.length) {
        status.textContent = Drupal.formatPlural(results.length, '1 event found', '@count events found');
      }
      setExpanded(true);
    }

    function search() {
      var q = input.value.trim();
      if (controller) {
        controller.abort();
      }
      if (q.length < MIN_CHARS) {
        list.textContent = '';
        status.textContent = '';
        setExpanded(false);
        return;
      }
      controller = new AbortController();
      var url = endpoint + '?q=' + encodeURIComponent(q) +
        '&past=' + (past.checked ? 1 : 0) + '&limit=' + encodeURIComponent(limit);
      fetch(url, {signal: controller.signal, headers: {Accept: 'application/json'}})
        .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
        .then(function (data) { render(data.results || []); })
        .catch(function (err) {
          if (err && err.name === 'AbortError') {
            return;
          }
          list.textContent = '';
          setExpanded(false);
          status.textContent = Drupal.t('Search is unavailable right now.');
        });
    }

    function go(li) {
      if (li && li.dataset.url) {
        window.location.href = li.dataset.url;
      }
    }

    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(search, DEBOUNCE_MS);
    });
    past.addEventListener('change', search);

    input.addEventListener('focus', function () {
      if (options().length && input.value.trim().length >= MIN_CHARS) {
        setExpanded(true);
      }
    });

    input.addEventListener('keydown', function (e) {
      var items = options();
      var open = !list.hidden && items.length;
      if (e.key === 'ArrowDown' && open) {
        e.preventDefault();
        setActive(active + 1 >= items.length ? 0 : active + 1);
      }
      else if (e.key === 'ArrowUp' && open) {
        e.preventDefault();
        setActive(active <= 0 ? items.length - 1 : active - 1);
      }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (open && active >= 0) {
          go(items[active]);
        }
      }
      else if (e.key === 'Escape' && !list.hidden) {
        e.preventDefault();
        setExpanded(false);
      }
    });

    // mousedown (not click) so the choice lands before the input blurs.
    list.addEventListener('mousedown', function (e) {
      var li = e.target.closest('[role="option"]');
      if (li) {
        e.preventDefault();
        go(li);
      }
    });

    list.addEventListener('mousemove', function (e) {
      var li = e.target.closest('[role="option"]');
      if (li) {
        setActive(Array.prototype.indexOf.call(options(), li));
      }
    });

    // Close when focus leaves the whole widget.
    root.addEventListener('focusout', function (e) {
      if (!root.contains(e.relatedTarget)) {
        setExpanded(false);
      }
    });
  }

})(Drupal, once);
