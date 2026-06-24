/**
 * Shared wp.i18n accessor for SlimStat admin scripts.
 *
 * Centralizes the wp.i18n binding (plus a tiny fallback for the rare out-of-order
 * load) so admin.js and goals-funnels.js don't each re-roll it. Enqueued as the
 * 'slimstat-i18n' handle with a 'wp-i18n' dependency; every admin script that
 * needs translation depends on 'slimstat-i18n' and reads window.wpSlimstatI18n.
 *
 * Call sites still pass the 'wp-slimstat' text domain — `wp i18n make-pot` only
 * extracts a JS string when its gettext call carries a literal domain argument.
 */
(function (window) {
    'use strict';

    window.wpSlimstatI18n = (window.wp && window.wp.i18n) ? window.wp.i18n : {
        __: function (s) { return s; },
        _n: function (s, p, n) { return n === 1 ? s : p; },
        sprintf: function () {
            var args = arguments, i = 0;
            return String(args[0]).replace(/%(?:(\d+)\$)?[ds]/g, function (_m, pos) {
                return args[pos ? parseInt(pos, 10) : ++i];
            });
        }
    };
})(window);
