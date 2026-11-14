/**
 * Settings screen: search with deep links, old-link redirects and the unsaved-changes bar.
 *
 * Reads window.slimstatSettingsIndex (labels and help text only, never stored values).
 * Without JavaScript the page works as before: the search stays hidden, Save Changes posts.
 */
(function (window, document) {
    'use strict';

    var data = window.slimstatSettingsIndex;
    if (!data || !data.rows) {
        return;
    }
    var i18n = window.wpSlimstatI18n;
    var tab = parseInt(new URLSearchParams(window.location.search).get('tab'), 10) || 1;

    function find(id) {
        return document.getElementById(id);
    }

    function link(row) {
        return data.url + row.t + '#' + row.id;
    }

    // Old links point at a setting that now lives on another tab: send them there.
    function locate(hash) {
        for (var i = 0; i < data.rows.length; i++) {
            var r = data.rows[i];
            if (r.id === hash || r.a === hash || r.a + '-label' === hash) {
                return r;
            }
        }
        return null;
    }

    // A row hidden by its conditional field: the field to change is the last visible one it
    // depends on (a parent hides its children, so earlier ones are already set).
    function controller(row) {
        var fields = (row.getAttribute('data-conditional-field') || '').split(',');
        for (var i = fields.length - 1; i >= 0; i--) {
            var el = find('slimstat-setting-' + fields[i].trim());
            if (el && el.getClientRects().length) {
                return el;
            }
        }
        return null;
    }

    function reveal(target) {
        var row = target.closest('tr') || target;
        var hint = document.querySelector('.slimstat-settings-search__hint');
        if (hint) {
            hint.hidden = true;
        }
        var parent = row.getClientRects().length ? null : controller(row);
        if (parent) {
            // Explain the dependency and open the controlling setting; never change it for the user.
            var hidden = locate(row.id);
            var shown = locate(parent.id);
            if (hint && hidden && shown) {
                /* translators: 1: a setting that is hidden, 2: the setting that shows it. */
                hint.textContent = i18n.sprintf(i18n.__('“%1$s” is hidden until you change “%2$s”.', 'wp-slimstat'), hidden.l, shown.l);
                hint.hidden = false;
                // The visible hint is not a live region; the search status announces it.
                hint.parentNode.querySelector('[role=status]').textContent = hint.textContent;
            }
            window.location.replace('#' + parent.id);
            target = row = parent;
        }
        row.scrollIntoView({ block: 'center' });
        var control = target.matches('input, select, textarea') ? target : row.querySelector('input:not([type=hidden]), select, textarea');
        if (control) {
            control.focus({ preventScroll: true });
        }
    }

    var hash = decodeURIComponent(window.location.hash.slice(1));
    if (hash) {
        var target = find(hash);
        var moved = target ? null : locate(hash);
        if (moved && moved.t !== tab) {
            window.location.replace(data.url + moved.t + '#' + hash);
            return;
        }
        target = target || (moved && find(moved.id));
        if (target) {
            window.addEventListener('load', function () { reveal(target); });
        }
    }

    // Search.
    var box = document.querySelector('.slimstat-settings-search');
    var input = find('slimstat-settings-search-input');
    var list = find('slimstat-settings-search-results');
    if (box && input && list) {
        box.hidden = false;
        var status = box.querySelector('[role=status]');
        var hits = [];
        var active = -1;

        var close = function () {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        };

        var go = function (row) {
            close();
            if (row.t === tab && find(row.id)) {
                window.location.hash = row.id;
                reveal(find(row.id));
            } else {
                window.location.href = link(row);
            }
        };

        var mark = function (i) {
            var items = list.children;
            if (active >= 0 && items[active]) {
                items[active].setAttribute('aria-selected', 'false');
            }
            active = i;
            if (i >= 0 && items[i]) {
                items[i].setAttribute('aria-selected', 'true');
                items[i].scrollIntoView({ block: 'nearest' });
                input.setAttribute('aria-activedescendant', items[i].id);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        };

        var score = function (row, words) {
            // Lowercased once per row, not on every keystroke.
            if (undefined === row.ll) {
                row.ll = row.l.toLowerCase();
                row.kl = row.k.toLowerCase();
                row.rl = (row.k + ' ' + row.s + ' ' + row.g + ' ' + row.d).toLowerCase();
            }
            var label = row.ll;
            var rest = row.rl;
            var total = 0;
            for (var i = 0; i < words.length; i++) {
                var w = words[i];
                if (0 === label.indexOf(w)) { total += 4; }
                else if (label.indexOf(w) >= 0) { total += 3; }
                else if (row.kl.indexOf(w) >= 0) { total += 2; }
                else if (rest.indexOf(w) >= 0) { total += 1; }
                else { return 0; }
            }
            return total;
        };

        var render = function () {
            var words = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
            list.textContent = '';
            hits = [];
            if (!words.length) {
                close();
                status.textContent = '';
                return;
            }
            data.rows.forEach(function (row) {
                var s = score(row, words);
                if (s) { hits.push([s, row]); }
            });
            hits.sort(function (a, b) { return b[0] - a[0]; });
            hits = hits.slice(0, 8).map(function (h) { return h[1]; });
            hits.forEach(function (row, i) {
                var li = document.createElement('li');
                li.id = 'slimstat-settings-search-option-' + i;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                var title = document.createElement('span');
                title.className = 'slimstat-settings-search__label';
                title.textContent = row.l;
                var where = document.createElement('span');
                where.className = 'slimstat-settings-search__where';
                where.textContent = row.s ? row.g + ' / ' + row.s : row.g;
                li.appendChild(title);
                li.appendChild(where);
                li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                li.addEventListener('click', function () { go(row); });
                list.appendChild(li);
            });
            if (!hits.length) {
                var empty = document.createElement('li');
                empty.className = 'slimstat-settings-search__empty';
                empty.setAttribute('aria-disabled', 'true');
                empty.textContent = i18n.__('No settings match. Try another word.', 'wp-slimstat');
                list.appendChild(empty);
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
            /* translators: %d: number of matching settings. */
            status.textContent = i18n.sprintf(i18n._n('%d setting found', '%d settings found', hits.length, 'wp-slimstat'), hits.length);
        };

        input.addEventListener('input', render);
        input.addEventListener('focus', function () { if (input.value.trim()) { render(); } });
        input.addEventListener('blur', close);
        input.addEventListener('keydown', function (e) {
            if ('ArrowDown' === e.key || 'ArrowUp' === e.key) {
                e.preventDefault();
                if (list.hidden) { render(); }
                if (hits.length) {
                    var next = active + ('ArrowDown' === e.key ? 1 : -1);
                    mark(next >= hits.length ? 0 : (next < 0 ? hits.length - 1 : next));
                }
            } else if ('Enter' === e.key) {
                // Never submit the settings form from the search field.
                e.preventDefault();
                var pick = hits[active >= 0 ? active : 0];
                if (pick && !list.hidden) { go(pick); }
            } else if ('Escape' === e.key && !list.hidden) {
                e.preventDefault();
                close();
            }
        });
    }

    // Unsaved changes: compare the form with what was loaded, after the page scripts settle.
    var bar = document.querySelector('.slimstat-save-bar');
    var form = bar && bar.closest('form');
    if (!form) {
        return;
    }
    var note = bar.querySelector('.slimstat-save-bar__status');
    var serialize = function () { return new URLSearchParams(new FormData(form)).toString(); };
    var saved = null;
    var dirty = false;
    // CodeMirror and TinyMCE write to their textarea only on submit, so each is compared with its own start.
    var editors = [];
    var check = function () {
        if (null === saved) { return; }
        var now = serialize() !== saved || editors.some(function (changed) { return changed(); });
        if (now === dirty) { return; }
        dirty = now;
        bar.classList.toggle('is-dirty', dirty);
        note.textContent = dirty ? i18n.__('You have unsaved changes.', 'wp-slimstat') : '';
    };
    window.addEventListener('load', function () {
        saved = serialize();
        form.querySelectorAll('.CodeMirror').forEach(function (el) {
            var cm = el.CodeMirror;
            var clean = cm.changeGeneration();
            editors.push(function () { return !cm.isClean(clean); });
            cm.on('change', check);
        });
        if (window.tinymce) {
            var track = function (ed) {
                if (!form.contains(ed.getElement())) { return; }
                var start = null;
                var begin = function () { start = ed.getContent(); };
                if (ed.initialized) { begin(); } else { ed.on('init', begin); }
                editors.push(function () { return null !== start && ed.getContent() !== start; });
                ed.on('input change undo redo', check);
            };
            window.tinymce.editors.forEach(track);
            window.tinymce.on('AddEditor', function (e) { track(e.editor); });
        }
    });
    form.addEventListener('input', check);
    form.addEventListener('change', check);
    form.addEventListener('submit', function () { saved = null; dirty = false; });
    window.addEventListener('beforeunload', function (e) {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})(window, document);
