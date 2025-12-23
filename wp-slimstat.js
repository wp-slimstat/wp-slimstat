import FingerprintJS from "@fingerprintjs/fingerprintjs";

/**
 * SlimStat: Browser tracking helper (refactored for maintainability)
 * Public API surface preserved (SlimStat.*) while internals modernized and scoped.
 * NOTE: Legacy browsers still supported via simple polyfills below.
 */
// eslint-disable-next-line no-var
var SlimStat = (function () {
    var BASE64_KEY_STR = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-";
    var fingerprintHash = "";
    var lastPageviewPayload = "";
    var lastPageviewSentAt = 0;
    var inflightPageview = false;
    // Queue to enforce sequential sending order for tracking requests
    var requestQueue = [];
    var queueInFlight = false;
    var MAX_QUEUE_ATTEMPTS = 4;
    var QUEUE_HIGH_WATERMARK = 80; // drop low-priority if exceeded
    var lastInteractionPayload = "";
    var lastInteractionTime = 0;
    var PENDING_INTERACTIONS_LIMIT = 20;

    // Initialize these variables with default values to prevent runtime errors
    var pendingInteractions = [];
    var loadOfflineQueue = function () {
        return [];
    };
    var saveOfflineQueue = function () {};
    var currentSlimStatParams = function () {
        return {};
    };
    var pageviewInProgress = false;

    function bufferInteraction(raw) {
        if (pendingInteractions.length >= PENDING_INTERACTIONS_LIMIT) pendingInteractions.shift();
        pendingInteractions.push(raw);
    }

    function flushPendingInteractions() {
        if (!pendingInteractions.length) return;
        var params = currentSlimStatParams();
        if (!params.id || parseInt(params.id, 10) <= 0) return; // still can't flush
        while (pendingInteractions.length) {
            var raw = pendingInteractions.shift();
            var payload = "action=slimtrack&id=" + params.id + raw;
            sendToServer(payload, true, { priority: "normal" });
        }
    }

    // Offline persistence helpers will be defined in the outer scope and assigned here
    var OFFLINE_KEY = "slimstat_offline_queue";

    // -------------------------- Generic Helpers -------------------------- //
    function utf8Encode(string) {
        string = (string || "").replace(/\r\n/g, "\n");
        var utftext = "";
        for (var n = 0; n < string.length; n++) {
            var c = string.charCodeAt(n);
            if (c < 128) utftext += String.fromCharCode(c);
            else if (c < 2048) {
                utftext += String.fromCharCode((c >> 6) | 192, (c & 63) | 128);
            } else {
                utftext += String.fromCharCode((c >> 12) | 224, ((c >> 6) & 63) | 128, (c & 63) | 128);
            }
        }
        return utftext;
    }

    function base64Encode(input) {
        var output = "";
        var i = 0;
        input = utf8Encode(input);
        while (i < input.length) {
            var chr1 = input.charCodeAt(i++);
            var chr2 = input.charCodeAt(i++);
            var chr3 = input.charCodeAt(i++);
            var enc1 = chr1 >> 2;
            var enc2 = ((chr1 & 3) << 4) | (chr2 >> 4);
            var enc3 = ((chr2 & 15) << 2) | (chr3 >> 6);
            var enc4 = chr3 & 63;
            if (isNaN(chr2)) enc3 = enc4 = 64;
            else if (isNaN(chr3)) enc4 = 64;
            output += BASE64_KEY_STR.charAt(enc1) + BASE64_KEY_STR.charAt(enc2) + BASE64_KEY_STR.charAt(enc3) + BASE64_KEY_STR.charAt(enc4);
        }
        return output;
    }

    function isEmpty(v) {
        if (v === undefined || v === null) return true;
        var t = typeof v;
        if (t === "boolean") return !v;
        if (t === "number") return isNaN(v) || v === 0;
        if (t === "string") return v.length === 0;
        if (Array.isArray(v)) return v.length === 0;
        if (t === "object") return Object.keys(v).length === 0;
        return false;
    }

    function anySubstring(str, needles) {
        if (!str || !needles || !needles.length) return false;
        for (var i = 0; i < needles.length; i++) {
            if (str.indexOf(needles[i].trim()) !== -1) return true;
        }
        return false;
    }

    function getCookie(name) {
        var value = "; " + document.cookie;
        var parts = value.split("; " + name + "=");
        if (parts.length === 2) return parts.pop().split(";").shift();
        return "";
    }

    function addEvent(obj, type, fn) {
        if (!obj) return;
        if (obj.addEventListener) obj.addEventListener(type, fn, false);
        else if (obj.attachEvent) obj.attachEvent("on" + type, fn);
        else obj["on" + type] = fn;
    }

    function getServerLatency() {
        var timing = (window.performance || {}).timing || {};
        if (!timing.responseEnd || !timing.connectEnd) return 0;
        return timing.responseEnd - timing.connectEnd;
    }

    function getPagePerformance() {
        var timing = (window.performance || {}).timing || {};
        if (!timing.loadEventEnd || !timing.responseEnd) return 0;
        return timing.loadEventEnd - timing.responseEnd;
    }

    function getComponentValue(components, key, def) {
        // FingerprintJS v4 API - components is now an object with component names as keys
        if (components && components[key] && components[key].value !== undefined) {
            return components[key].value;
        }
        return def;
    }

    // This function will be defined in the outer scope and assigned to SlimStat
    // function currentSlimStatParams() { ... }

    // -------------------------- Parameters Extraction -------------------------- //
    function extractSlimStatParams() {
        var meta = document.querySelector('meta[name="slimstat-params"]');
        if (meta) {
            try {
                window.SlimStatParams = JSON.parse(meta.getAttribute("content")) || {};
            } catch (e) {
                /* ignore */
            }
        } else {
            // Fallback: look through inline scripts (same as legacy)
            var scripts = document.querySelectorAll("script");
            for (var i = scripts.length - 1; i >= 0; i--) {
                var match = scripts[i].textContent.match(/var\s+SlimStatParams\s*=\s*({[\s\S]*?});/);
                if (match) {
                    try {
                        // eslint-disable-next-line no-new-func
                        window.SlimStatParams = new Function("return " + match[1])() || {};
                        break;
                    } catch (e) {
                        /* ignore */
                    }
                }
            }
        }
        return currentSlimStatParams();
    }

    // -------------------------- Fingerprint -------------------------- //
    function initFingerprintHash(result) {
        try {
            // FingerprintJS v4 API - result contains visitorId and components
            if (result && result.visitorId) {
                fingerprintHash = result.visitorId;
                return;
            }
            // Graceful fallback
            fingerprintHash = "";
        } catch (e) {
            fingerprintHash = ""; // graceful fallback
        }
    }

    function buildSlimStatData(components) {
        // Components are optional; compute directly if not provided
        // FingerprintJS v4 returns components as an object, not an array
        var hasComponents = components && typeof components === "object" && !Array.isArray(components);

        var screenres = [0, 0];
        try {
            if (hasComponents) {
                screenres = getComponentValue(components, "screenResolution", [0, 0]);
            }
            // Fallback to window.screen if components not available or screenResolution not found
            if (!screenres || screenres[0] === 0) {
                if (window.screen) {
                    screenres = [window.screen.width || 0, window.screen.height || 0];
                }
            }
        } catch (e) {
            screenres = [0, 0];
        }

        var tzOffset = 0;
        try {
            if (hasComponents) {
                tzOffset = getComponentValue(components, "timezoneOffset", 0);
            }
            // Fallback to Date API if components not available or timezoneOffset not found
            if (tzOffset === 0 && !hasComponents) {
                tzOffset = new Date().getTimezoneOffset();
            }
        } catch (e) {
            tzOffset = 0;
        }

        return "&sw=" + screenres[0] + "&sh=" + screenres[1] + "&bw=" + window.innerWidth + "&bh=" + window.innerHeight + "&sl=" + getServerLatency() + "&pp=" + getPagePerformance() + "&fh=" + fingerprintHash + "&tz=" + tzOffset;
    }

    // -------------------------- Transport -------------------------- //
    function sendToServer(payload, useBeacon, opts) {
        if (isEmpty(payload)) return false;
        opts = opts || {};

        // All requests now go through the queue to ensure consistent handling.
        // Immediate sends are pushed to the front.
        var item = { payload: payload, useBeacon: useBeacon, opts: opts, attempts: 0 };

        // Check for duplicate payloads in queue to prevent duplicates
        var isDuplicate = requestQueue.some(function (qItem) {
            return qItem.payload === payload;
        });
        if (isDuplicate) {
            return false;
        }

        // Queue pressure control: drop oldest non-high if above high watermark
        if (requestQueue.length > QUEUE_HIGH_WATERMARK) {
            for (var i = requestQueue.length - 1; i >= 0 && requestQueue.length > QUEUE_HIGH_WATERMARK; i--) {
                if (requestQueue[i].opts.priority !== "high") requestQueue.splice(i, 1);
            }
        }

        if (opts.immediate || opts.priority === "high") {
            // Avoid duplicates of same payload at head
            if (!requestQueue.length || requestQueue[0].payload !== payload) {
                requestQueue.unshift(item);
            }
        } else {
            requestQueue.push(item);
        }

        // Start processing if not already running
        if (!queueInFlight) {
            processQueue();
        }

        return true;
    }

    function processQueue() {
        if (queueInFlight || !requestQueue.length) return;
        var item = requestQueue.shift();
        if (!item) return;

        queueInFlight = true;

        var done = function (success) {
            if (!success && item) {
                item.attempts = (item.attempts || 0) + 1;
                if (item.attempts < MAX_QUEUE_ATTEMPTS) {
                    // Re-queue with a delay and exponential backoff
                    var delay = 500 * Math.pow(2, item.attempts);
                    setTimeout(function () {
                        requestQueue.unshift(item);
                    }, delay);
                } else {
                    // Max attempts reached, move to offline storage
                    SlimStat.store_offline(item.payload);
                    if (item.opts && typeof item.opts.onComplete === "function") {
                        item.opts.onComplete(false);
                    }
                }
            } else {
                if (item.opts && typeof item.opts.onComplete === "function") {
                    item.opts.onComplete(!!success);
                }
            }
            queueInFlight = false;
            // Process next after a micro delay to allow ID assignment, etc.
            setTimeout(processQueue, 50); // increased delay to prevent tight loops on failure
        };

        processQueueItem(item, done);
    }

    function processQueueItem(item, callback) {
        var params = currentSlimStatParams();
        var payload = item.payload;
        var useBeacon = item.useBeacon;
        var transports = ["rest", "ajax", "adblock_bypass"];
        var endpoints = { rest: params.ajaxurl_rest, ajax: params.ajaxurl_ajax, adblock_bypass: params.ajaxurl_adblock };
        var selected = params.transport;
        var order = [selected].concat(
            transports.filter(function (t) {
                return t !== selected;
            })
        );
        function sendXHR(url, onFail, xhrOpts) {
            xhrOpts = xhrOpts || { useNonce: true };
            var xhr;
            try {
                xhr = new XMLHttpRequest();
            } catch (e) {
                if (onFail) onFail();
                return false;
            }
            xhr.open("POST", url, true);
            xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            if (xhrOpts.useNonce && params.wp_rest_nonce) xhr.setRequestHeader("X-WP-Nonce", params.wp_rest_nonce);
            xhr.withCredentials = true;
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    // Special handling for nonce failure: retry immediately without nonce
                    if (xhr.status === 403 && xhrOpts.useNonce && params.wp_rest_nonce) {
                        // To prevent loops, we only retry once without the nonce.
                        // The onFail logic will be handled by the retry's result.
                        sendXHR(url, onFail, { useNonce: false });
                        return;
                    }
                    if (xhr.status === 200) {
                        var parsed = parseInt(xhr.responseText, 10);
                        if (!isNaN(parsed) && parsed > 0) {
                            params.id = xhr.responseText; // store new id
                            // Mark that we've successfully tracked the initial pageview for this load
                            try {
                                window.slimstatPageviewTracked = true;
                            } catch (trackErr) {
                                /* ignore */
                            }
                            flushPendingInteractions(); // Flush buffered interactions now that we have an ID
                        }
                        callback(true);
                    } else {
                        // Non-200 status is a failure, trigger retry/failover
                        if (onFail) onFail();
                    }
                }
            };
            try {
                xhr.send(payload);
            } catch (e) {
                // This catches network errors before send, also a failure
                if (onFail) onFail();
            }
            return true;
        }
        function trySend(i) {
            if (i >= order.length) {
                // All transport methods have been tried and failed
                callback(false);
                return false;
            }
            var method = order[i];
            var url = endpoints[method];
            if (!url) return trySend(i + 1);
            if (useBeacon && navigator.sendBeacon && i === 0) {
                // Beacon is fire-and-forget; we assume success for queue processing
                var ok = navigator.sendBeacon(url, payload);
                if (ok) {
                    callback(true);
                    return true;
                }
                // If beacon fails, immediately try next method
                return trySend(i + 1);
            }
            return sendXHR(
                url,
                function () {
                    trySend(i + 1);
                },
                { useNonce: true }
            );
        }
        trySend(0);
    }

    // -------------------------- Interaction Tracking -------------------------- //
    function trackInteraction(event, note, useBeacon) {
        var params = currentSlimStatParams();
        if (isEmpty(params.id) || isNaN(parseInt(params.id, 10)) || parseInt(params.id, 10) <= 0) {
            // Buffer interaction until we have an id
            try {
                var minimal = buildInteractionRaw(event, note);
                bufferInteraction(minimal);
            } catch (e) {
                /* ignore */
            }
            return false;
        }
        if (!event || isEmpty(event.type) || event.type === "focus") return false;

        useBeacon = typeof useBeacon === "boolean" ? useBeacon : true;
        var target = event.target || event.srcElement;
        if (!target) return false;

        var noteObj = {};
        if (!isEmpty(note)) noteObj.note = note;

        // Derive resource URL
        var resourceUrl = "";
        (function derive() {
            if (!target.nodeName) return;
            var node = target.nodeName.toLowerCase();
            if (node === "input" || node === "button") {
                var p = target.parentNode;
                while (p && p.nodeName && p.nodeName.toLowerCase() !== "form") p = p.parentNode;
                if (p && p.action) resourceUrl = p.action;
                return;
            }
            // anchor resolution (support nested nodes)
            if (!target.href || typeof target.href !== "string") {
                var p = target.parentNode;
                while (p && p.nodeName && !p.href) p = p.parentNode;
                if (p) {
                    if (p.hash && p.hostname === location.hostname) resourceUrl = p.hash;
                    else if (p.href) resourceUrl = p.href;
                }
            } else if (target.hash) resourceUrl = target.hash;
            else resourceUrl = target.href;
        })();

        // Element attributes
        if (typeof target.getAttribute === "function") {
            if (target.textContent) noteObj.text = target.textContent;
            var val = target.getAttribute("value");
            if (val) noteObj.value = val;
            var title = target.getAttribute("title");
            if (title) noteObj.title = title;
            var idAttr = target.getAttribute("id");
            if (idAttr) noteObj.id = idAttr;
        }
        noteObj.type = event.type;
        if (event.type === "keypress") noteObj.key = String.fromCharCode(parseInt(event.which, 10));
        else if (event.type === "mousedown") noteObj.button = event.which === 1 ? "left" : event.which === 2 ? "middle" : "right";

        var doNotTrack = params.dnt ? params.dnt.split(",") : [];
        if (resourceUrl && doNotTrack.length && anySubstring(resourceUrl, doNotTrack)) return false;

        // class-based do not track
        if (doNotTrack.length && target.className && typeof target.className === "string") {
            var classes = target.className.split(" ");
            if (
                classes.some(function (c) {
                    return doNotTrack.indexOf(c) !== -1;
                })
            )
                return false;
        }
        if (doNotTrack.length && target.attributes && target.attributes.rel && target.attributes.rel.value) {
            if (anySubstring(target.attributes.rel.value, doNotTrack)) return false;
        }

        // Coordinates
        var position = "0,0";
        if (!isEmpty(event.pageX) && !isEmpty(event.pageY)) position = event.pageX + "," + event.pageY;
        else if (!isEmpty(event.clientX)) position = event.clientX + (document.body.scrollLeft || 0) + (document.documentElement.scrollLeft || 0) + "," + (event.clientY + (document.body.scrollTop || 0) + (document.documentElement.scrollTop || 0));

        var fingerprintParam = resourceUrl ? "&fh=" + fingerprintHash : "";
        var raw = "&res=" + base64Encode(resourceUrl) + "&pos=" + position + "&no=" + base64Encode(JSON.stringify(noteObj)) + fingerprintParam;
        var payload = "action=slimtrack&id=" + params.id + raw;
        var now = Date.now();
        if (payload === lastInteractionPayload && now - lastInteractionTime < 1000) return false; // dedupe bursts
        lastInteractionPayload = payload;
        lastInteractionTime = now;
        var sent = sendToServer(payload, useBeacon);
        if (sent) {
            // Flag that at least one meaningful interaction happened this pageview
            try {
                window.__slimstatHasInteraction = true;
            } catch (e) {
                /* ignore */
            }
        }
        return sent;
    }

    function buildInteractionRaw(event, note) {
        // Reconstruct minimal raw (without id) for buffering.
        var target = (event && (event.target || event.srcElement)) || {};
        var resourceUrl = "";
        try {
            if (target.href) resourceUrl = target.href;
        } catch (e) {
            /* ignore */
        }
        var noteObj = { type: event ? event.type : "unknown" };
        if (note) noteObj.note = note;
        var position = "0,0";
        if (event && !isEmpty(event.pageX) && !isEmpty(event.pageY)) position = event.pageX + "," + event.pageY;
        return "&res=" + base64Encode(resourceUrl) + "&pos=" + position + "&no=" + base64Encode(JSON.stringify(noteObj));
    }

    // -------------------------- Pageview Logic -------------------------- //
    // FP_EXCLUDES retained for backward compatibility, not used by FingerprintJS v4
    var FP_EXCLUDES = {};

    // -------------------------- Consent Helpers -------------------------- //
    var lastConsentSnapshot = null;
    var CONSENT_UPGRADE_STATE_KEY = "slimstat_consent_upgrade_state";
    var CONSENT_UPGRADE_TS_KEY = "slimstat_consent_upgrade_ts";

    function getConsentUpgradeStore(key) {
        try {
            return sessionStorage.getItem(key) || "";
        } catch (e) {
            return window[key] || "";
        }
    }

    function setConsentUpgradeStore(key, value) {
        try {
            if (value === "" || value === null || typeof value === "undefined") {
                sessionStorage.removeItem(key);
            } else {
                sessionStorage.setItem(key, value);
            }
        } catch (e) {
            if (value === "" || value === null || typeof value === "undefined") {
                delete window[key];
            } else {
                window[key] = value;
            }
        }
    }

    function markConsentUpgradePending() {
        setConsentUpgradeStore(CONSENT_UPGRADE_STATE_KEY, "pending");
        setConsentUpgradeStore(CONSENT_UPGRADE_TS_KEY, Date.now().toString());
    }

    function markConsentUpgradeDone(success) {
        if (success) {
            setConsentUpgradeStore(CONSENT_UPGRADE_STATE_KEY, "done");
            setConsentUpgradeStore(CONSENT_UPGRADE_TS_KEY, Date.now().toString());
        } else {
            setConsentUpgradeStore(CONSENT_UPGRADE_STATE_KEY, "");
            setConsentUpgradeStore(CONSENT_UPGRADE_TS_KEY, "");
        }
    }

    function hasConsentUpgradeSucceeded() {
        return getConsentUpgradeStore(CONSENT_UPGRADE_STATE_KEY) === "done";
    }

    function claimConsentUpgradeSlot(force) {
        if (force === true) {
            markConsentUpgradePending();
            return true;
        }

        var state = getConsentUpgradeStore(CONSENT_UPGRADE_STATE_KEY);
        if ("done" === state) {
            return false;
        }

        if ("pending" === state) {
            var ts = parseInt(getConsentUpgradeStore(CONSENT_UPGRADE_TS_KEY) || "0", 10);
            if (Date.now() - ts < 5000) {
                return false;
            }
        }

        markConsentUpgradePending();
        return true;
    }

    function requestConsentUpgrade(extraOptions) {
        extraOptions = extraOptions || {};
        var force = extraOptions.force === true;

        if (!claimConsentUpgradeSlot(force)) {
            return false;
        }

        var requestOptions = {
            isConsentRetry: true,
            consentUpgrade: true,
        };

        if (extraOptions.consent) {
            requestOptions.consent = extraOptions.consent;
        }
        if (extraOptions.consentNonce) {
            requestOptions.consentNonce = extraOptions.consentNonce;
        }

        SlimStat._send_pageview(requestOptions);
        return true;
    }

    function isFunction(value) {
        return typeof value === "function";
    }

    function isObject(value) {
        return value !== null && typeof value === "object";
    }

    function getCookieStrict(name) {
        if (!name) return null;
        try {
            var safeName = name.replace(/([.$?*|{}()\[\]\\\/\+^])/g, "\\$1");
            var pattern = "(?:^|;)\\s*" + safeName + "=([^;]*)";
            var match = document.cookie.match(pattern);
            return match ? decodeURIComponent(match[1]) : null;
        } catch (e) {
            return null;
        }
    }

    function detectRealCookieBannerConsent(category) {
        try {
            // Latest API (RCB 4.x+): window.rcb() function
            if (isFunction(window.rcb)) {
                try {
                    var rcbConsent = window.rcb("consent", category);
                    if (rcbConsent === true || rcbConsent === false) return !!rcbConsent;
                    if (isObject(rcbConsent) && "cookie" in rcbConsent) return !!rcbConsent.cookie;
                    if (isObject(rcbConsent) && "consent" in rcbConsent) return !!rcbConsent.consent;
                } catch (e) {}
            }

            // New API: window.RCB.consent.get()
            if (isObject(window.RCB) && isObject(window.RCB.consent) && isFunction(window.RCB.consent.get)) {
                var rcbNew = window.RCB.consent.get(category);
                if (rcbNew === true || rcbNew === false) return !!rcbNew;
                if (isObject(rcbNew) && "cookie" in rcbNew) return !!rcbNew.cookie;
                if (isObject(rcbNew) && "consent" in rcbNew) return !!rcbNew.consent;
            }

            // Current API: window.rcbConsentManager.getUserDecision()
            if (isObject(window.rcbConsentManager) && isFunction(window.rcbConsentManager.getUserDecision)) {
                var decision = window.rcbConsentManager.getUserDecision();
                if (decision && decision.decision) {
                    if (decision.decision === "all") return true;
                    if (typeof decision.decision === "object") {
                        var value = decision.decision[category];
                        if (typeof value === "boolean") return value;
                        if (Array.isArray(value)) return value.length > 0;
                    }
                }
            }

            // Legacy API: window.realCookieBanner.consent.get()
            var rcb = window.realCookieBanner || window.RealCookieBanner || null;
            if (isObject(rcb) && isObject(rcb.consent) && isFunction(rcb.consent.get)) {
                var consent = rcb.consent.get(category);
                if (consent === true || consent === false) return !!consent;
                if (isObject(consent) && "cookie" in consent) return !!consent.cookie;
                if (consent) return true;
            }

            // Very old API: window.__rcb
            var legacy = window.__rcb || window.__RCB || null;
            if (isObject(legacy) && legacy.consent) {
                var legacyVal = legacy.consent[category];
                if (typeof legacyVal === "boolean") return legacyVal;
                if (Array.isArray(legacyVal)) return legacyVal.length > 0;
            }

            // Cookie fallback
            var possibleNames = ["real_cookie_banner", "rcb_consent", "rcb_acceptance", "real_cookie_consent", "rcb-consent"];
            for (var i = 0; i < possibleNames.length; i++) {
                var raw = getCookieStrict(possibleNames[i]);
                if (!raw) {
                    continue;
                }
                try {
                    var parsed = JSON.parse(raw);
                    if (parsed) {
                        if (typeof parsed[category] === "boolean") return parsed[category];
                        if (typeof parsed.consent === "boolean") return parsed.consent;
                        if (typeof parsed[category] === "object" && parsed[category].cookie !== undefined) return !!parsed[category].cookie;
                    }
                } catch (err) {
                    var normalized = raw.toLowerCase();
                    if (raw.indexOf(category) !== -1 || raw === "1" || normalized === "true" || normalized === "all" || normalized === "accepted") {
                        return true;
                    }
                }
            }
        } catch (error) {}
        return null;
    }

    function detectWPConsentAPI(category) {
        try {
            if (isFunction(window.wp_has_service_consent)) {
                try {
                    var serviceConsent = window.wp_has_service_consent(category);
                    if (serviceConsent) return true;
                    if (isFunction(window.wp_is_service_denied) && window.wp_is_service_denied(category)) {
                        return false;
                    }
                } catch (err) {}
            }

            if (isFunction(window.wp_has_consent)) {
                try {
                    var hasConsent = window.wp_has_consent(category);
                    if (hasConsent) return true;
                    return false;
                } catch (err2) {}
            }

            var consentObj = window.wpConsent || window.WPConsent || null;
            if (isObject(consentObj) && isFunction(consentObj.get)) {
                var value = consentObj.get(category);
                if (value === true || value === false) {
                    return !!value;
                }
            }
        } catch (err3) {}
        return null;
    }

    function detectSlimStatBanner(consentCookieName, category) {
        try {
            var cookieName = consentCookieName || "slimstat_gdpr_consent";
            var value = getCookieStrict(cookieName);
            if (!value) {
                return null;
            }
            if (value === "accepted") {
                return true;
            }
            if (value === "denied") {
                return false;
            }
            try {
                var parsed = JSON.parse(value);
                if (parsed && parsed[category] !== undefined) {
                    return !!parsed[category];
                }
            } catch (err) {
                /* ignore */
            }
            return value.length > 0;
        } catch (err4) {
            return null;
        }
    }

    function normalizeConsent(raw) {
        var normalized = {
            functional: "deny",
            statistics: "deny",
            statistics_anonymous: "deny",
            marketing: "deny",
        };

        if (typeof raw === "boolean") {
            normalized.statistics = raw ? "allow" : "deny";
            return normalized;
        }

        if (typeof raw === "string") {
            if (raw === "accepted" || raw === "allow" || raw === "grant") {
                normalized.statistics = "allow";
            } else if (raw === "denied" || raw === "deny" || raw === "revoke") {
                normalized.statistics = "deny";
            }
            return normalized;
        }

        if (!isObject(raw) && !Array.isArray(raw)) {
            return normalized;
        }

        var data = raw;
        if (Array.isArray(raw)) {
            data = { allowed: raw };
        }

        if (Array.isArray(data.allowed)) {
            for (var i = 0; i < data.allowed.length; i++) {
                var category = data.allowed[i];
                if (normalized.hasOwnProperty(category)) {
                    normalized[category] = "allow";
                }
            }
            return normalized;
        }

        if (Array.isArray(data.denied)) {
            for (var j = 0; j < data.denied.length; j++) {
                var deniedCategory = data.denied[j];
                if (normalized.hasOwnProperty(deniedCategory)) {
                    normalized[deniedCategory] = "deny";
                }
            }
        }

        var categories = ["functional", "statistics", "statistics_anonymous", "marketing"];
        for (var k = 0; k < categories.length; k++) {
            var cat = categories[k];
            if (data[cat] !== undefined) {
                if (typeof data[cat] === "boolean") {
                    normalized[cat] = data[cat] ? "allow" : "deny";
                } else if (typeof data[cat] === "string") {
                    normalized[cat] = ["allow", "accepted", "grant", "true"].indexOf(data[cat]) !== -1 ? "allow" : "deny";
                }
            } else if (data.groups && data.groups[cat] !== undefined) {
                var groupValue = data.groups[cat];
                if (typeof groupValue === "boolean") {
                    normalized[cat] = groupValue ? "allow" : "deny";
                } else if (typeof groupValue === "string") {
                    normalized[cat] = ["allow", "accepted", "grant", "true"].indexOf(groupValue) !== -1 ? "allow" : "deny";
                }
            } else if (data.decision !== undefined) {
                if (data.decision === "all") {
                    normalized[cat] = "allow";
                } else if (isObject(data.decision) && data.decision[cat] !== undefined) {
                    var decisionValue = data.decision[cat];
                    if (typeof decisionValue === "boolean") {
                        normalized[cat] = decisionValue ? "allow" : "deny";
                    } else if (typeof decisionValue === "string") {
                        normalized[cat] = ["allow", "accepted", "grant", "true"].indexOf(decisionValue) !== -1 ? "allow" : "deny";
                    }
                }
            }
        }

        return normalized;
    }

    function sendConsentChangeToServer(source, parsedConsent, pageviewId) {
        try {
            var params = currentSlimStatParams();
            var nonce = params.wp_rest_nonce || "";
            var restUrl = "";

            // Try to get REST URL from params first
            if (params.resturl) {
                restUrl = params.resturl;
            } else if (typeof window.wpApiSettings !== "undefined" && window.wpApiSettings.root) {
                restUrl = window.wpApiSettings.root;
            } else {
                // Fallback: construct REST URL from current site URL
                var siteUrl = window.location.origin;
                if (params.baseurl && params.baseurl !== "/") {
                    var basePath = params.baseurl.replace(/\/$/, "");
                    restUrl = siteUrl + basePath + "/wp-json/";
                } else {
                    restUrl = siteUrl + "/wp-json/";
                }
            }

            // Ensure restUrl ends with /
            if (restUrl && restUrl.charAt(restUrl.length - 1) !== "/") {
                restUrl += "/";
            }

            var endpoint = restUrl + "slimstat/v1/consent-change";
            var payload = {
                source: source,
                parsed: parsedConsent,
                ts: Date.now(),
                mode: {
                    gdprEnabled: params.gdpr_enabled !== "off",
                    anonymousTrackingEnabled: params.anonymous_tracking === "on",
                },
                nonce: nonce,
            };

            if (pageviewId) {
                payload.pageview_id = String(pageviewId);
            }

            if (typeof window.fetch === "function") {
                fetch(endpoint, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-WP-Nonce": nonce,
                    },
                    credentials: "same-origin",
                    body: JSON.stringify(payload),
                })
                    .then(function (response) {
                        if (!response.ok) {
                            return;
                        }
                        return response.json();
                    })
                    .catch(function () {});
            } else {
                var xhr = new XMLHttpRequest();
                xhr.open("POST", endpoint, true);
                xhr.setRequestHeader("Content-Type", "application/json");
                xhr.setRequestHeader("X-WP-Nonce", nonce);
                xhr.onload = function () {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            var responseData = JSON.parse(xhr.responseText);
                        } catch (parseError) {
                            /* ignore */
                        }
                    }
                };
                xhr.onerror = function () {};
                xhr.send(JSON.stringify(payload));
            }
        } catch (error) {}
    }

    function emitConsentEvent(detail) {
        if (!detail) {
            return;
        }
        try {
            var event = new CustomEvent("slimstat:consent:updated", { detail: detail });
            document.dispatchEvent(event);
        } catch (err) {
            try {
                var fallback = document.createEvent("CustomEvent");
                fallback.initCustomEvent("slimstat:consent:updated", true, true, detail);
                document.dispatchEvent(fallback);
            } catch (compatError) {
                /* ignore */
            }
        }
    }

    function maybeEmitConsentChange(detail) {
        if (!detail) {
            return;
        }
        var snapshot = detail.allowed + "|" + detail.mode + "|" + detail.reason;
        if (snapshot !== lastConsentSnapshot) {
            lastConsentSnapshot = snapshot;
            // Only emit consent event if it's a meaningful change (not just initial check)
            // This prevents duplicate pageview requests during initial load
            var params = currentSlimStatParams();
            var hasPageviewId = params.id && parseInt(params.id, 10) > 0;

            // If we have a pageview ID, emit the event (consent changed after tracking)
            // If we don't have an ID yet, the initial pageview will handle consent, so skip event
            if (hasPageviewId) {
                emitConsentEvent(detail);
            }
        }
    }

    function slimstatConsentAllowed(params, options) {
        options = options || {};
        var s = params || {};
        var anonMode = s.anonymous_tracking === "on";
        var setCookie = s.set_tracker_cookie === "on";
        var anonymizeIP = s.anonymize_ip === "on";
        var hashIP = s.hash_ip === "on";
        var integrationKey = s.consent_integration || "";
        var consentLevel = s.consent_level_integration || "statistics";

        /* debug logging removed */

        try {
            var dntEnabled = s.respect_dnt === "on";
            if (dntEnabled && typeof navigator !== "undefined" && (navigator.doNotTrack === "1" || navigator.doNotTrack === "yes")) {
                var blocked = { allowed: false, mode: "blocked", reason: "dnt" };
                maybeEmitConsentChange(blocked);
                return blocked;
            }
        } catch (err) {
            /* ignore */
        }

        var collectsPII = !!(setCookie || (!anonymizeIP && !hashIP));
        var requiresCmpCheck = collectsPII || anonMode;
        var cmpAllows = null;

        if (requiresCmpCheck) {
            if (integrationKey === "wp_consent_api" || integrationKey === "wpconsent" || integrationKey === "wp_consent" || integrationKey === "") {
                var jsConsent = detectWPConsentAPI(consentLevel);
                if (jsConsent !== null) {
                    cmpAllows = jsConsent;
                }
                if (cmpAllows === null && s.server_side_consent !== undefined) {
                    cmpAllows = !!s.server_side_consent;
                }
                if (integrationKey === "" && cmpAllows === null) {
                    cmpAllows = true;
                }
            }

            if (cmpAllows === null && (integrationKey === "real_cookie_banner" || integrationKey === "rcb" || integrationKey === "realcookie")) {
                var rcbConsent = detectRealCookieBannerConsent(consentLevel);
                if (rcbConsent !== null) {
                    cmpAllows = rcbConsent;
                } else {
                    if (options.isConsentRetry) {
                        var fallback = detectWPConsentAPI(consentLevel);
                        if (fallback !== null) {
                            cmpAllows = fallback;
                        }
                    }
                }
            }

            if (cmpAllows === null && (integrationKey === "slimstat_banner" || integrationKey === "slimstat")) {
                var cookieName = s.gdpr_cookie_name || "slimstat_gdpr_consent";
                var bannerConsent = detectSlimStatBanner(cookieName, consentLevel);
                if (bannerConsent !== null) {
                    cmpAllows = bannerConsent;
                }
            }

            if (cmpAllows === null) {
                if (anonMode) {
                    cmpAllows = true;
                } else if (collectsPII && integrationKey && integrationKey !== "") {
                    cmpAllows = false;
                } else {
                    cmpAllows = true;
                }
            } else {
            }

            if (cmpAllows !== true && hasConsentUpgradeSucceeded()) {
                cmpAllows = true;
            }
        }

        if (anonMode) {
            var cmpGranted = cmpAllows === true;
            var anonDecision = {
                allowed: true,
                mode: cmpGranted ? "full" : "anonymous",
                reason: cmpGranted ? "anonymous_mode_consented" : "anonymous_mode",
            };
            maybeEmitConsentChange(anonDecision);
            return anonDecision;
        }

        if (!collectsPII) {
            var noPii = { allowed: true, mode: "full", reason: "no_pii" };
            maybeEmitConsentChange(noPii);
            return noPii;
        }

        if (cmpAllows === false) {
            var denied = { allowed: false, mode: "blocked", reason: "cmp_denied" };
            maybeEmitConsentChange(denied);
            return denied;
        }

        var allowedResult = { allowed: true, mode: "full", reason: "cmp_allowed" };
        maybeEmitConsentChange(allowedResult);
        return allowedResult;
    }

    function buildPageviewBase(params) {
        if (!isEmpty(params.id) && parseInt(params.id, 10) > 0) return "action=slimtrack&id=" + params.id;
        var base = "action=slimtrack&ref=" + base64Encode(document.referrer) + "&res=" + base64Encode(window.location.href);
        if (!isEmpty(params.ci)) base += "&ci=" + params.ci;
        return base;
    }

    function sendPageview(options) {
        options = options || {};
        extractSlimStatParams();

        // Prevent duplicate requests with stronger locking mechanism
        var requestKey = "slimstat_pageview_" + (options.isNavigation ? "nav" : "init") + "_" + (options.isConsentRetry ? "retry" : "normal");
        if (window.sendingSlimStatPageview || window[requestKey]) {
            return;
        }
        window.sendingSlimStatPageview = true;
        window[requestKey] = true;

        var params = currentSlimStatParams();

        var consentUpgradeParam = "";
        if (options.consentUpgrade) {
            consentUpgradeParam = "&consent_upgrade=1";
            if (params.id) {
                // Send the current pageview ID (with checksum) so the server can
                // update this specific record, same as in the explicit upgrade AJAX.
                consentUpgradeParam += "&pageview_id=" + encodeURIComponent(params.id);
            }
        }

        var consentDecision = slimstatConsentAllowed(params, {
            isNavigation: !!options.isNavigation,
            isConsentRetry: !!options.isConsentRetry,
        });

        if (!consentDecision.allowed) {
            window.sendingSlimStatPageview = false;
            delete window[requestKey];
            return;
        }

        if (options.consentUpgrade && consentDecision.mode === "full") {
            consentUpgradeParam = "&consent_upgrade=1";
            if (params.id) {
                // Send the current pageview ID (with checksum) so the server can
                // update this specific record, same as in the explicit upgrade AJAX.
                consentUpgradeParam += "&pageview_id=" + encodeURIComponent(params.id);
            }
        }

        // Check if this is a navigation event (not initial page load)
        var isNavigationEvent = options.isNavigation || false;
        var isConsentRetry = options.isConsentRetry || false;

        // For navigation events, always track regardless of javascript_mode
        // For initial page load, skip if server-side tracking is active
        if (!isNavigationEvent && !isConsentRetry && !isEmpty(params.id) && parseInt(params.id, 10) > 0) {
            // Server-side tracking is active for initial page load, skip pageview but allow interactions
            window.sendingSlimStatPageview = false;
            delete window[requestKey];
            return;
        }

        // For navigation events, we need to track the new page, not the current one
        if (isNavigationEvent) {
            // Force a new pageview for the navigation event
            params.id = null;
        }

        var payloadBase = buildPageviewBase(params);

        if (!payloadBase) {
            window.sendingSlimStatPageview = false;
            delete window[requestKey];
            return;
        }

        // Prevent duplicate pageview requests
        if (pageviewInProgress) {
            window.sendingSlimStatPageview = false;
            delete window[requestKey];
            return;
        }

        // De-duplicate rapid navigations (e.g., WP Interactivity quick transitions)
        var now = Date.now();
        if (payloadBase === lastPageviewPayload && now - lastPageviewSentAt < 150) {
            window.sendingSlimStatPageview = false;
            delete window[requestKey];
            return;
        }

        lastPageviewPayload = payloadBase;
        lastPageviewSentAt = now;
        var waitForId = SlimStat.empty(params.id) || parseInt(params.id, 10) <= 0; // when new pageview
        var useBeacon = !waitForId; // need sync response when creating id

        // Avoid parallel initial pageview duplication
        if (inflightPageview && waitForId) {
            window.sendingSlimStatPageview = false;
            delete window[requestKey];
            return;
        }
        inflightPageview = waitForId;
        pageviewInProgress = true;

        // Reset finalization state when starting new pageview
        // Note: finalizationInProgress is now managed in initSlimStatRuntime scope

        // Consolidated flag reset helper to prevent race conditions
        var resetPageviewFlags = function () {
            // Single source of truth for flag resets
            // Delay allows sendToServer queue to process before allowing next pageview
            setTimeout(function () {
                inflightPageview = false;
                pageviewInProgress = false;
                window.sendingSlimStatPageview = false;
                delete window[requestKey];
            }, 200);
        };

        var onComplete = function (success) {
            if (options.consentUpgrade) {
                handleConsentUpgradeResult(!!success);
            }
            resetPageviewFlags();
        };

        // Add consent parameters if provided (from banner accept)
        if (options.consent && (options.consent === "accepted" || options.consent === "denied")) {
            consentUpgradeParam += "&banner_consent=" + encodeURIComponent(options.consent);
            if (options.consentNonce) {
                consentUpgradeParam += "&banner_consent_nonce=" + encodeURIComponent(options.consentNonce);
            }
        }

        var run = function () {
            // If anonymous mode is active, skip fingerprinting entirely to ensure no PII is collected/sent
            if (consentDecision.mode === "anonymous") {
                initFingerprintHash(null);
                sendToServer(payloadBase + buildSlimStatData({}) + consentUpgradeParam, useBeacon, { immediate: isEmpty(params.id), onComplete: onComplete });
                return;
            }

            // FingerprintJS v4 async init; if it fails, proceed without fingerprint
            try {
                // Safely check if FingerprintJS library is available
                var fpPromise = null;
                if (typeof FingerprintJS !== "undefined" && FingerprintJS.load) {
                    fpPromise = FingerprintJS.load();
                }

                // Only proceed with promise chain if we have a valid promise
                if (fpPromise && typeof fpPromise.then === "function") {
                    fpPromise
                        .then(function (result) {
                            initFingerprintHash(result);
                            sendToServer(payloadBase + buildSlimStatData(result.components || {}) + consentUpgradeParam, useBeacon, { immediate: isEmpty(params.id), onComplete: onComplete });
                        })
                        .catch(function () {
                            initFingerprintHash(null);
                            sendToServer(payloadBase + buildSlimStatData({}) + consentUpgradeParam, useBeacon, { immediate: isEmpty(params.id), onComplete: onComplete });
                        });
                } else {
                    // Library not available; proceed without fingerprint
                    initFingerprintHash(null);
                    sendToServer(payloadBase + buildSlimStatData({}) + consentUpgradeParam, useBeacon, { immediate: isEmpty(params.id), onComplete: onComplete });
                }
            } catch (e) {
                // Catch synchronous errors (shouldn't happen, but defensive)
                initFingerprintHash(null);
                sendToServer(payloadBase + buildSlimStatData({}) + consentUpgradeParam, useBeacon, { immediate: isEmpty(params.id), onComplete: onComplete });
            }
        };
        if (window.requestIdleCallback) window.requestIdleCallback(run);
        else setTimeout(run, 250);
    }

    // -------------------------- Consent Management -------------------------- //
    // GDPR consent is now handled by external CMP plugins (Complianz, Cookie Notice, etc.)
    // SlimStat integrates via WP Consent API or custom integrations
    // No internal banner or consent UI is provided

    // -------------------------- Offline Data Handling -------------------------- //
    function storeOffline(payload) {
        try {
            var offline = loadOfflineQueue();
            offline.push({ p: payload, t: Date.now() });
            saveOfflineQueue(offline);
        } catch (e) {
            // Silently fail if localStorage is not available
        }
    }

    function flushOfflineQueue() {
        try {
            var offline = loadOfflineQueue();
            if (!offline.length) return;

            var params = currentSlimStatParams();
            if (!params.id || parseInt(params.id, 10) <= 0) return; // need valid ID to send

            // Send offline items in batches to avoid overwhelming the server
            var batchSize = 5;
            var sent = 0;
            var toRemove = [];

            for (var i = 0; i < offline.length && sent < batchSize; i++) {
                var item = offline[i];
                if (item && item.p) {
                    // Update payload with current ID if it has a placeholder
                    var payload = item.p;
                    if (payload.indexOf("id=pending") !== -1) {
                        payload = payload.replace("id=pending", "id=" + params.id);
                    }

                    if (sendToServer(payload, false, { priority: "normal" })) {
                        toRemove.push(i);
                        sent++;
                    }
                }
            }

            // Remove sent items from offline queue
            if (toRemove.length > 0) {
                for (var j = toRemove.length - 1; j >= 0; j--) {
                    offline.splice(toRemove[j], 1);
                }
                saveOfflineQueue(offline);
            }
        } catch (e) {
            // Silently fail if there are any issues
        }
    }

    // -------------------------- Public API (legacy names preserved) -------------------------- //
    return {
        // legacy constant (used by base64 algorithm)
        base64_key_str: BASE64_KEY_STR,
        // expose fingerprint
        get fingerprint_hash() {
            return fingerprintHash;
        },
        set fingerprint_hash(v) {
            fingerprintHash = v;
        },
        // legacy wrappers
        utf8_encode: utf8Encode,
        base64_encode: base64Encode,
        get_page_performance: getPagePerformance,
        get_server_latency: getServerLatency,
        // Deprecated GDPR UI removed
        add_event: addEvent,
        in_array: anySubstring,
        empty: isEmpty,
        get_cookie: getCookie,
        send_to_server: sendToServer,
        ss_track: trackInteraction,
        init_fingerprint_hash: initFingerprintHash,
        get_slimstat_data: buildSlimStatData,
        get_component_value: getComponentValue,
        // Offline data handling
        store_offline: storeOffline,
        flush_offline_queue: flushOfflineQueue,
        consent: {
            checkAllowed: slimstatConsentAllowed,
            emit: emitConsentEvent,
            normalize: normalizeConsent,
            sendChange: sendConsentChangeToServer,
        },
        requestConsentUpgrade: requestConsentUpgrade,
        // New internal helpers (not documented previously)
        _extract_params: extractSlimStatParams,
        _send_pageview: sendPageview,
        // Expose functions for the runtime
        _assign_runtime_helpers: function (helpers) {
            pendingInteractions = helpers.pendingInteractions;
            loadOfflineQueue = helpers.loadOfflineQueue;
            saveOfflineQueue = helpers.saveOfflineQueue;
            currentSlimStatParams = helpers.currentSlimStatParams;
            pageviewInProgress = helpers.pageviewInProgress;
        },
    };
})();

// Polyfills for ES5 and older browsers
if (!Element.prototype.matches) {
    Element.prototype.matches =
        Element.prototype.matchesSelector ||
        Element.prototype.mozMatchesSelector ||
        Element.prototype.msMatchesSelector ||
        Element.prototype.oMatchesSelector ||
        Element.prototype.webkitMatchesSelector ||
        function (s) {
            var matches = (this.document || this.ownerDocument).querySelectorAll(s),
                i = matches.length;
            // eslint-disable-next-line no-empty
            while (--i >= 0 && matches.item(i) !== this) {}
            return i > -1;
        };
}
if (!String.prototype.trim) {
    String.prototype.trim = function () {
        return this.replace(/^\s+|\s+$/g, "");
    };
}
if (!Array.isArray) {
    Array.isArray = function (arg) {
        return Object.prototype.toString.call(arg) === "[object Array]";
    };
}
if (!window.requestIdleCallback) {
    window.requestIdleCallback = function (callback) {
        return setTimeout(callback, 250);
    };
}

// Main initialization (refactored)
(function initSlimStatRuntime() {
    // These functions and variables are now defined in this scope
    // and will be shared with the SlimStat object.
    var pendingInteractions = [];
    var OFFLINE_KEY = "slimstat_offline_queue";
    var pageviewInProgress = false;

    // Helper functions for consent detection (local copies for scope access)
    function isFunction(value) {
        return typeof value === "function";
    }

    function isObject(value) {
        return value !== null && typeof value === "object";
    }

    function getCookieStrict(name) {
        if (!name) return null;
        try {
            var safeName = name.replace(/([.$?*|{}()\[\]\\\/\+^])/g, "\\$1");
            var pattern = "(?:^|;)\\s*" + safeName + "=([^;]*)";
            var match = document.cookie.match(pattern);
            return match ? decodeURIComponent(match[1]) : null;
        } catch (e) {
            return null;
        }
    }

    function loadOfflineQueue() {
        try {
            var raw = localStorage.getItem(OFFLINE_KEY);
            if (!raw) return [];
            var arr = JSON.parse(raw);
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    function saveOfflineQueue(arr) {
        try {
            localStorage.setItem(OFFLINE_KEY, JSON.stringify(arr.slice(-200))); // cap
        } catch (e) {
            /* ignore */
        }
    }

    function currentSlimStatParams() {
        // Ensure global object exists
        if (!window.SlimStatParams) window.SlimStatParams = {};
        return window.SlimStatParams;
    }

    // Share these with the SlimStat IIFE
    SlimStat._assign_runtime_helpers({
        pendingInteractions: pendingInteractions,
        loadOfflineQueue: loadOfflineQueue,
        saveOfflineQueue: saveOfflineQueue,
        currentSlimStatParams: currentSlimStatParams,
        pageviewInProgress: pageviewInProgress,
    });

    var requestConsentUpgrade =
        SlimStat.requestConsentUpgrade ||
        function () {
            return false;
        };

    // Track whether we've already finalized the current pageview (avoid duplicate beacons)
    var finalizedPageviews = {};
    // Track currently in-flight finalization requests to avoid races
    var inFlightFinalizations = {};
    // Finalization state management (moved from SlimStat closure to avoid scope issues)
    var finalizationInProgress = false;
    var lastFinalizationReason = "";
    var lastFinalizationTime = 0;
    var FINALIZATION_COOLDOWN = 1000; // 1 second cooldown between finalizations
    // Global interaction flag used to avoid sending a duplicate pageview when the user leaves
    try {
        if (typeof window.__slimstatHasInteraction === "undefined") window.__slimstatHasInteraction = false;
    } catch (e) {
        /* ignore */
    }
    // Global flag to prevent concurrent pageview sends
    try {
        if (typeof window.sendingSlimStatPageview === "undefined") window.sendingSlimStatPageview = false;
        if (typeof window.slimstatPageviewTracked === "undefined") window.slimstatPageviewTracked = false;
    } catch (e) {
        /* ignore */
    }

    function finalizeCurrent(reason) {
        var p = currentSlimStatParams();
        if (!p.id || parseInt(p.id, 10) <= 0 || finalizedPageviews[p.id] || inFlightFinalizations[p.id]) return; // no pageview id yet or already finalized/in-flight

        var now = Date.now();
        if (finalizationInProgress || (reason === lastFinalizationReason && now - lastFinalizationTime < FINALIZATION_COOLDOWN)) return;

        finalizationInProgress = true;
        lastFinalizationReason = reason;
        lastFinalizationTime = now;

        // Mark in-flight to prevent concurrent senders (race protection)
        inFlightFinalizations[p.id] = true;

        // Old behavior: send a simple finalize to let the server compute dt_out
        var payload = "action=slimtrack&id=" + p.id + (reason ? "&fv=" + encodeURIComponent(reason) : "");
        SlimStat.send_to_server(payload, true, { priority: "high", immediate: false });

        // Mark finalized and clear in-flight after a short window
        finalizedPageviews[p.id] = true;
        setTimeout(function () {
            delete inFlightFinalizations[p.id];
            finalizationInProgress = false;
        }, 120);
    }

    // Observe for parameter mutations (meta tag or script changes)
    // Only observe if we don't have an ID yet (to avoid unnecessary tracking requests)
    var lastParams = JSON.stringify(currentSlimStatParams());
    var observer = new MutationObserver(function () {
        var params = currentSlimStatParams();
        // Only extract params if we don't have an ID yet (initial page load)
        if (SlimStat.empty(params.id) || parseInt(params.id, 10) <= 0) {
            SlimStat._extract_params();
            var serialized = JSON.stringify(currentSlimStatParams());
            if (serialized !== lastParams) lastParams = serialized; // reserved for future diff-based logic
        }
    });
    observer.observe(document.head, { childList: true, subtree: true });
    observer.observe(document.body, { childList: true, subtree: true });

    // Initial pageview
    SlimStat.add_event(window, "load", function () {
        SlimStat._extract_params();

        // Proceed with normal tracking; consent is gated by CMP checks in sendPageview()
        SlimStat._send_pageview();

        // Flush any offline stored payloads after initial pageview queued
        setTimeout(function () {
            try {
                if (navigator.onLine !== false) SlimStat.flush_offline_queue();
            } catch (e) {}
        }, 500);
    });

    // Listen for WP Consent API consent changes and retry pageview if previously blocked
    document.addEventListener("wp_listen_for_consent_change", function (event) {
        try {
            var detail = (event && event.detail) || {};
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "statistics";
            var retryKey = "slimstatConsentRetried_" + selectedCategory;

            if (detail[selectedCategory] && detail[selectedCategory] === "allow" && (!window[retryKey] || window[retryKey] === false)) {
                window[retryKey] = true;
                SlimStat._send_pageview({
                    consentUpgrade: true,
                });
            }
        } catch (e) {
            /* ignore */
        }
    });

    // Backwards compatibility: some integrations expose a helper on window
    if (typeof window.wp_listen_for_consent_change === "function") {
        try {
            window.wp_listen_for_consent_change(function (category) {
                var params = currentSlimStatParams();
                var selectedCategory = params.consent_level_integration || "statistics";
                var retryKey = "slimstatConsentRetried_" + selectedCategory;

                if (category === selectedCategory && (!window[retryKey] || window[retryKey] === false)) {
                    window[retryKey] = true;
                    SlimStat._send_pageview({
                        consentUpgrade: true,
                    });
                }
            });
        } catch (e) {
            /* ignore */
        }
    }

    // Listen for consent type definitions to catch late initializations
    document.addEventListener("wp_consent_type_defined", function () {
        try {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "statistics";
            var retryKey = "slimstatConsentRetried_" + selectedCategory;

            if (!window[retryKey]) {
                if (typeof window.wp_has_consent === "function") {
                    if (window.wp_has_consent(selectedCategory)) {
                        window[retryKey] = true;
                        SlimStat._send_pageview({
                            consentUpgrade: true,
                        });
                    }
                } else {
                    window[retryKey] = true;
                    SlimStat._send_pageview({
                        consentUpgrade: true,
                    });
                }
            }
        } catch (e) {
            /* ignore */
        }
    });

    // Standard WP Consent API event listener
    document.addEventListener("wp_consent_change", function (event) {
        if (event.detail && event.detail.category) {
            var category = event.detail.category;
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "statistics";

            // Use category-specific retry flag to prevent race conditions between CMPs
            var retryKey = "slimstatConsentRetried_" + selectedCategory;
            var consentRetried = window[retryKey] || false;

            var shouldTrack = !consentRetried && category === selectedCategory && (!params.id || parseInt(params.id, 10) <= 0);

            if (shouldTrack) {
                // Double-check with WP Consent API if available
                if (typeof window.wp_has_consent === "function" && !window.wp_has_consent(selectedCategory)) return;
                window[retryKey] = true;
                SlimStat._send_pageview({
                    consentUpgrade: true,
                });
            }

            // Send consent change to server via REST API
            if (category === selectedCategory) {
                try {
                    var hasConsent = false;
                    if (typeof window.wp_has_consent === "function") {
                        hasConsent = window.wp_has_consent(selectedCategory);
                    } else if (event.detail.consent !== undefined) {
                        hasConsent = event.detail.consent === true || event.detail.consent === "allow";
                    }

                    var parsedConsent = normalizeConsent({
                        statistics: hasConsent ? "allow" : "deny",
                    });

                    var pageviewId = null;
                    if (params.id && parseInt(params.id, 10) > 0) {
                        pageviewId = parseInt(params.id, 10);
                    }

                    sendConsentChangeToServer("wp_consent_api", parsedConsent, pageviewId);
                } catch (consentError) {}
            }
        }
    });

    // CMP-specific listeners
    // Define tryTrackIfAllowed in outer scope so consent helpers can access it
    function tryTrackIfAllowed(extraOptions) {
        var params = currentSlimStatParams();
        var selectedCategory = params.consent_level_integration || "statistics";
        var integrationKey = params.consent_integration || "";

        if (typeof window.wp_has_consent === "function") {
            try {
                var hasConsent = window.wp_has_consent(selectedCategory);
                if (!hasConsent) {
                    return;
                }
            } catch (err) {
                return;
            }
        }

        if (integrationKey === "real_cookie_banner" || integrationKey === "rcb" || integrationKey === "realcookie") {
            var rcbConsent = detectRealCookieBannerConsent(selectedCategory);
            if (rcbConsent === false) {
                return;
            }
        }

        requestConsentUpgrade(extraOptions || {});
    }

    // CMP-specific listeners
    (function registerCmpListeners() {
        // Complianz: enable specific category
        document.addEventListener("cmplz_enable_category", function (e) {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "statistics";
            var cat = (e && e.detail && (e.detail.category || e.detail)) || "";
            if (cat === selectedCategory) tryTrackIfAllowed();
        });

        // Complianz: status event (allow/deny)
        document.addEventListener("cmplz_event_status", function (e) {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "statistics";
            var d = (e && e.detail) || {};
            var cat = d.category || d.type || "";
            var allowed = d.status === "allow" || d.enabled === true;
            if (cat === selectedCategory && allowed) tryTrackIfAllowed();
        });

        // Real Cookie Banner - multiple event names for compatibility
        var rcbHandlerDebounceTimer = null;
        var rcbHandlerLastCall = 0;
        function handleRCBConsentChange(e) {
            var now = Date.now();
            var params = currentSlimStatParams();
            var integrationKey = params.consent_integration || "";

            if (integrationKey !== "real_cookie_banner" && integrationKey !== "rcb" && integrationKey !== "realcookie") {
                return;
            }

            var selectedCategory = params.consent_level_integration || "statistics";
            var ok = false;
            var consentData = null;

            if (e && e.detail) {
                if (e.detail.consent && selectedCategory in e.detail.consent) {
                    var categoryConsent = e.detail.consent[selectedCategory];
                    if (typeof categoryConsent === "boolean") {
                        ok = categoryConsent;
                        consentData = e.detail.consent;
                    } else if (categoryConsent && categoryConsent.cookie !== null) {
                        ok = true;
                        consentData = e.detail.consent;
                    }
                } else if (e.detail.button && (e.detail.button === "accept_all" || e.detail.button === "accept_essentials" || e.detail.button === "save")) {
                    var consentCheck = SlimStat.consent.checkAllowed(params, {});
                    ok = consentCheck && consentCheck.allowed && consentCheck.mode === "full";
                    if (e.detail.consent) {
                        consentData = e.detail.consent;
                    }
                }
            }

            if (!ok && typeof window.wp_has_consent === "function") {
                ok = !!window.wp_has_consent(selectedCategory);
            }

            // Send consent change to server via REST API
            try {
                var parsedConsent = normalizeConsent(consentData || { statistics: ok });
                var pageviewId = null;
                if (params.id && parseInt(params.id, 10) > 0) {
                    pageviewId = parseInt(params.id, 10);
                }
                sendConsentChangeToServer("real_cookie_banner", parsedConsent, pageviewId);
            } catch (rcbError) {}

            if (!ok) {
                var consentCheck = SlimStat.consent.checkAllowed(params, {});
                ok = consentCheck && consentCheck.allowed && consentCheck.mode === "full";
            }

            if (ok) {
                clearTimeout(rcbHandlerDebounceTimer);
                var timeSinceLastCall = now - rcbHandlerLastCall;
                var debounceDelay = timeSinceLastCall < 100 ? 100 - timeSinceLastCall : 0;
                rcbHandlerDebounceTimer = setTimeout(function () {
                    // Rely on tryTrackIfAllowed so the new consent upgrade flow runs uniformly
                    var params = currentSlimStatParams();
                    if (!params.id || parseInt(params.id, 10) <= 0) {
                        tryTrackIfAllowed();
                    } else {
                        SlimStat.requestConsentUpgrade();
                    }
                }, debounceDelay);
                rcbHandlerLastCall = now;
            }
        }

        // Listen for all RCB event variations
        document.addEventListener("RealCookieBannerConsentChanged", handleRCBConsentChange);
        document.addEventListener("rcb-consent-changed", handleRCBConsentChange);
        document.addEventListener("rcb-consent-update", handleRCBConsentChange);
        document.addEventListener("rcb-consent-saved", handleRCBConsentChange);

        // CookieYes (cookie-law-info) events
        // Fire after a short delay to allow WP Consent API state to update
        document.addEventListener("cookieyes_consent_update", function () {
            setTimeout(tryTrackIfAllowed, 50);
        });
        document.addEventListener("cookieyes_preferences_update", function () {
            setTimeout(tryTrackIfAllowed, 50);
        });
        // Older CookieYes/CLI plugins
        document.addEventListener("cli_consent_update", function () {
            setTimeout(tryTrackIfAllowed, 50);
        });
    })();

    // Before unload finalize if we have an active id
    // Use multiple lifecycle signals to improve reliability across SPA / tab discard / mobile browsers
    SlimStat.add_event(document, "visibilitychange", function () {
        // Only finalize if we have an active ID and the page is actually hidden
        var params = currentSlimStatParams();
        if (document.visibilityState === "hidden" && params.id && parseInt(params.id, 10) > 0) {
            debouncedFinalize("visibility");
        }
    });
    SlimStat.add_event(window, "pagehide", function () {
        // Only finalize if we have an active ID
        var params = currentSlimStatParams();
        if (params.id && parseInt(params.id, 10) > 0) {
            debouncedFinalize("pagehide");
        }
    });
    SlimStat.add_event(window, "beforeunload", function () {
        // Only finalize if we have an active ID
        var params = currentSlimStatParams();
        if (params.id && parseInt(params.id, 10) > 0) {
            debouncedFinalize("beforeunload");
        }
    });

    // Add a small delay between finalization attempts to prevent rapid-fire duplicates
    var finalizationTimeout = null;
    function debouncedFinalize(reason) {
        // Don't finalize if already finalized for this pageview ID
        var p = currentSlimStatParams();
        if (!p.id || finalizedPageviews[p.id]) return;

        if (finalizationTimeout) {
            clearTimeout(finalizationTimeout);
        }
        finalizationTimeout = setTimeout(function () {
            finalizeCurrent(reason);
        }, 50);
    }

    // Online event to resend offline queue
    SlimStat.add_event(window, "online", function () {
        SlimStat.flush_offline_queue();
        flushPendingInteractions();
    });

    // Before unload, persist any pending interactions that don't have an ID yet
    SlimStat.add_event(window, "beforeunload", function () {
        var params = currentSlimStatParams();
        if ((!params.id || parseInt(params.id, 10) <= 0) && pendingInteractions.length > 0) {
            // No ID assigned, so we can't send these. Store them offline.
            // We assume they are for the most recent pageview attempt.
            var offline = loadOfflineQueue();
            pendingInteractions.forEach(function (raw) {
                // To send these later, we need to stub a payload.
                // We'll add a placeholder that the server-side can reconcile.
                var placeholderPayload = "action=slimtrack&id=pending" + raw;
                offline.push({ p: placeholderPayload, t: Date.now() });
            });
            saveOfflineQueue(offline);
            pendingInteractions.length = 0; // Clear buffer
        }
    });

    function setupClickDelegation() {
        SlimStat.add_event(document.body, "click", function (e) {
            var target = e.target;
            while (target && target !== document.body) {
                // Skip GDPR consent buttons to avoid duplicate processing
                if (target.hasAttribute && target.hasAttribute("data-consent")) {
                    break;
                }
                if (target.matches && target.matches("a,button,input,area")) {
                    SlimStat.ss_track(e, null, null);
                    break;
                }
                target = target.parentNode;
            }
        });

        // No GDPR consent buttons; managed by CMPs
    }

    function setupNavigationHooks() {
        // WordPress Interactivity API Event
        SlimStat.add_event(document, "wp-interactivity:navigate", function () {
            // Prevent duplicate navigation events
            if (pageviewInProgress) {
                return;
            }

            // Capture current URL; only act if it actually changes
            var oldPathname = window.location.pathname;
            var oldSearch = window.location.search;

            // Defer the new pageview call to allow the DOM and URL to update
            setTimeout(function () {
                var newPathname = window.location.pathname;
                var newSearch = window.location.search;
                if (newPathname !== oldPathname || newSearch !== oldSearch) {
                    var params = currentSlimStatParams();
                    if (params.id && parseInt(params.id, 10) > 0) {
                        debouncedFinalize("navigation");
                    }
                    SlimStat._send_pageview({ isNavigation: true });
                }
            }, 150);
        });

        // History API overrides (fallback for SPAs / Interactivity polyfills)
        if (window.history && history.pushState) {
            var originalPush = history.pushState;
            var originalReplace = history.replaceState;

            var stateChangeHandler = function (isReplace) {
                var oldPathname = window.location.pathname;
                var oldSearch = window.location.search;

                // Apply original function
                var originalFunc = isReplace ? originalReplace : originalPush;
                var originalArgs = Array.prototype.slice.call(arguments, 1);
                var res = originalFunc.apply(this, originalArgs);

                // After a short delay, check if navigation occurred
                setTimeout(function () {
                    var newPathname = window.location.pathname;
                    var newSearch = window.location.search;

                    // A navigation is a change in pathname or a significant change in search params
                    if (newPathname !== oldPathname || newSearch !== oldSearch) {
                        var params = currentSlimStatParams();
                        if (params.id && parseInt(params.id, 10) > 0) {
                            debouncedFinalize("history");
                        }
                        SlimStat._send_pageview({ isNavigation: true });
                    }
                }, 150);

                return res;
            };

            history.pushState = function () {
                var args = Array.prototype.slice.call(arguments);
                args.unshift(false);
                return stateChangeHandler.apply(this, args);
            };

            history.replaceState = function () {
                var args = Array.prototype.slice.call(arguments);
                args.unshift(true);
                return stateChangeHandler.apply(this, args);
            };

            SlimStat.add_event(window, "popstate", function () {
                // Prevent duplicate popstate events
                if (pageviewInProgress) {
                    return;
                }

                // Defer to allow URL to update
                setTimeout(function () {
                    // Always track navigation events for SPA behavior
                    // This ensures navigation is tracked even when server-side tracking is active
                    currentSlimStatParams().id = null;
                    SlimStat._send_pageview({ isNavigation: true });
                }, 150);
            });
        }
    }

    // Setup interaction tracking
    setupClickDelegation();
    setupNavigationHooks();

    /**
     * Setup Consent Upgrade Handler
     *
     * Listens for consent events from various CMPs (Consent Management Platforms)
     * and upgrades anonymous tracking to full PII tracking when consent is granted.
     *
     * Flow:
     * 1. User visits site → Anonymous tracking (hashed IP, no cookies)
     * 2. User grants consent → Consent event fired
     * 3. AJAX request sent to upgrade existing pageview record
     * 4. IP hash replaced with real IP, tracking cookie set
     */
    function setupConsentUpgradeHandler() {
        var legacyEvents = ["RCB/OptIn", "RCB/OptIn/All", "cookieyes_consent_update", "cookieyes_preferences_update", "cli_consent_update", "wp_listen_load", "wp_consent_type_functional", "wp_consent_type_statistics", "slimstat_banner_consent"];

        legacyEvents.forEach(function (eventName) {
            document.addEventListener(eventName, function (e) {
                requestConsentUpgrade(e);
            });
        });

        document.addEventListener("slimstat:consent:updated", function (event) {
            if (event && event.detail && event.detail.allowed && event.detail.mode === "full") {
                requestConsentUpgrade();
            }
        });

        SlimStat.requestConsentUpgrade = requestConsentUpgrade;
    }

    function initSlimStatBanner() {
        var bannerInitialized = false;

        function attachBannerHandlers() {
            if (bannerInitialized) {
                return;
            }

            var params = currentSlimStatParams();
            if (!params || params.use_slimstat_banner !== "on") {
                return;
            }

            var banner = document.getElementById("slimstat-gdpr-banner");
            if (!banner) {
                return;
            }

            bannerInitialized = true;

            setTimeout(function () {
                if (banner && banner.classList) {
                    banner.classList.add("show");
                } else if (banner) {
                    banner.style.display = "block";
                }
            }, 50);

            var buttons = banner.querySelectorAll("[data-consent]");
            for (var i = 0; i < buttons.length; i++) {
                (function (button) {
                    if (button.addEventListener) {
                        button.addEventListener(
                            "click",
                            function (event) {
                                if (event && typeof event.preventDefault === "function") {
                                    event.preventDefault();
                                }
                                if (event && typeof event.stopPropagation === "function") {
                                    event.stopPropagation();
                                }
                                var consent = button.getAttribute("data-consent") || "";
                                submitBannerDecision(consent, banner);
                            },
                            false
                        );
                    } else if (button.attachEvent) {
                        button.attachEvent("onclick", function (event) {
                            if (event && typeof event.preventDefault === "function") {
                                event.preventDefault();
                            }
                            if (event && typeof event.stopPropagation === "function") {
                                event.stopPropagation();
                            }
                            var consent = button.getAttribute("data-consent") || "";
                            submitBannerDecision(consent, banner);
                        });
                    } else {
                        button.onclick = function (event) {
                            if (event && typeof event.preventDefault === "function") {
                                event.preventDefault();
                            }
                            if (event && typeof event.stopPropagation === "function") {
                                event.stopPropagation();
                            }
                            var consent = button.getAttribute("data-consent") || "";
                            submitBannerDecision(consent, banner);
                        };
                    }
                })(buttons[i]);
            }
        }

        function submitBannerDecision(consent, bannerEl) {
            if (!consent || (consent !== "accepted" && consent !== "denied")) {
                return;
            }

            var params = currentSlimStatParams();
            var nonce = params.wp_rest_nonce || "";
            var cookieName = params.gdpr_cookie_name || "slimstat_gdpr_consent";
            var cookiePath = params.gdpr_cookie_path || params.baseurl || "/";

            // Set cookie immediately
            try {
                var expiry = new Date();
                expiry.setTime(expiry.getTime() + 365 * 24 * 60 * 60 * 1000);
                var cookie = cookieName + "=" + consent + "; path=" + cookiePath + "; expires=" + expiry.toUTCString() + "; SameSite=Lax";
                if (window && window.location && window.location.protocol === "https:") {
                    cookie += "; Secure";
                }
                document.cookie = cookie;
            } catch (cookieError) {
                /* ignore cookie errors */
            }

            // Close banner with animation (before request)
            if (bannerEl && bannerEl.classList) {
                bannerEl.classList.remove("show");
                bannerEl.classList.add("hiding");
            } else if (bannerEl) {
                // Fallback for browsers without classList
                bannerEl.style.transition = "transform 0.3s ease-out, opacity 0.3s ease-out";
                bannerEl.style.transform = "translateY(100%)";
                bannerEl.style.opacity = "0";
            }

            // Remove banner from DOM after animation completes
            setTimeout(function () {
                if (bannerEl && bannerEl.parentNode) {
                    bannerEl.parentNode.removeChild(bannerEl);
                }
            }, 350);

            // Dispatch consent event immediately
            if (consent === "accepted") {
                try {
                    if (typeof CustomEvent === "function") {
                        document.dispatchEvent(new CustomEvent("slimstat_banner_consent", { detail: { consent: consent } }));
                    } else {
                        var evt = document.createEvent("Event");
                        evt.initEvent("slimstat_banner_consent", true, true);
                        document.dispatchEvent(evt);
                    }
                } catch (dispatchError) {
                    /* ignore */
                }

                // Send consent change to server via REST API
                try {
                    var parsedConsent = normalizeConsent(consent);
                    var pageviewId = null;
                    if (params.id && parseInt(params.id, 10) > 0) {
                        pageviewId = parseInt(params.id, 10);
                    }
                    sendConsentChangeToServer("slimstat_banner", parsedConsent, pageviewId);
                } catch (apiError) {}

                try {
                    requestConsentUpgrade({ consent: consent, consentNonce: nonce });
                } catch (sendError) {}
            } else if (consent === "denied") {
                // Send consent change to server via REST API
                try {
                    var parsedConsentDenied = normalizeConsent(consent);
                    sendConsentChangeToServer("slimstat_banner", parsedConsentDenied, null);
                } catch (apiError) {}

                // Call revocation handler to delete tracking cookie
                try {
                    var ajaxUrl = params.ajaxurl || "/wp-admin/admin-ajax.php";
                    var revokeXhr = new XMLHttpRequest();
                    revokeXhr.open("POST", ajaxUrl, true);
                    revokeXhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                    revokeXhr.send("action=slimstat_consent_revoked&nonce=" + encodeURIComponent(nonce));
                    revokeXhr.onload = function () {};
                    revokeXhr.onerror = function () {};
                } catch (revokeError) {
                    /* ignore */
                }
            }
        }

        if (document.readyState && document.readyState !== "loading") {
            attachBannerHandlers();
        }

        if (document.addEventListener) {
            document.addEventListener("DOMContentLoaded", attachBannerHandlers, false);
            window.addEventListener("load", attachBannerHandlers, false);
        } else if (document.attachEvent) {
            document.attachEvent("onreadystatechange", function () {
                if (document.readyState === "complete") {
                    attachBannerHandlers();
                }
            });
            window.attachEvent("onload", attachBannerHandlers);
        } else {
            if (document.readyState === "complete") {
                attachBannerHandlers();
            }
            window.onload = attachBannerHandlers;
        }
    }

    // Initialize consent helpers
    initSlimStatBanner();
    setupConsentUpgradeHandler();
})();
