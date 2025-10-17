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
        // Ensure global object exists and get params directly
        if (!window.SlimStatParams) window.SlimStatParams = {};
        var params = window.SlimStatParams;
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
        for (var i = 0; i < components.length; i++) if (components[i].key === key) return components[i].value;
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
            // FingerprintJS v4 returns an object with visitorId; prefer that directly
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
        var screenres = [0, 0];
        try {
            if (components && components.length) {
                screenres = getComponentValue(components, "screenResolution", [0, 0]);
            } else if (window.screen) {
                screenres = [window.screen.width || 0, window.screen.height || 0];
            }
        } catch (e) {
            screenres = [0, 0];
        }
        var tzOffset = 0;
        try {
            if (components && components.length) {
                tzOffset = getComponentValue(components, "timezoneOffset", 0);
            } else {
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
                }
            }
            queueInFlight = false;
            // Process next after a micro delay to allow ID assignment, etc.
            setTimeout(processQueue, 50); // increased delay to prevent tight loops on failure
        };

        processQueueItem(item, done);
    }

    function processQueueItem(item, callback) {
        // Ensure global object exists and get params directly
        if (!window.SlimStatParams) window.SlimStatParams = {};
        var params = window.SlimStatParams;
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

    function buildPageviewBase(params) {
        if (!isEmpty(params.id) && parseInt(params.id, 10) > 0) return "action=slimtrack&id=" + params.id;
        var base = "action=slimtrack&ref=" + base64Encode(document.referrer) + "&res=" + base64Encode(window.location.href);
        if (!isEmpty(params.ci)) base += "&ci=" + params.ci;
        return base;
    }

    function sendPageview(options) {
        options = options || {};
        extractSlimStatParams();

        // Ensure global object exists and get params directly
        if (!window.SlimStatParams) window.SlimStatParams = {};
        var params = window.SlimStatParams;

        // CMP gating: only send if CMP grants consent or custom hook allows (when PII-like features are active).
        var cmpAllows = true;
        try {
            var integrationKey = (window.SlimStatParams && window.SlimStatParams.consent_integration) || "";
            var consentLevel = (window.SlimStatParams && window.SlimStatParams.consent_level_integration) || "functional";
            if (integrationKey === "wp_consent_api" && typeof window.wp_has_consent === "function") {
                cmpAllows = !!window.wp_has_consent(consentLevel);
            } else if (integrationKey === "real_cookie_banner_pro") {
                // Real Cookie Banner: use consentApi to check service consent (service keys are site-configured)
                var p = window.consentApi && typeof window.consentApi.consent === "function" ? window.consentApi.consent("analytics") : null;
                if (p && typeof p.then === "function") {
                    // Async; pessimistically block now, init will run when promise resolves externally
                    cmpAllows = false;
                } else {
                    try {
                        var dp = window.consentApi && typeof window.consentApi.consentSync === "function" ? window.consentApi.consentSync("analytics-with-data-processing") : null;
                        cmpAllows = !!(dp && dp.cookie != null && dp.cookieOptIn);
                    } catch (ee) {
                        cmpAllows = false;
                    }
                }
            } else if (integrationKey === "borlabs_cookie") {
                // Borlabs Cookie: analytics typically allowed when integration active; we allow and rely on server/CMP to block if needed
                cmpAllows = true;
            }
        } catch (e) {
            cmpAllows = true;
        }
        // Respect DNT if enabled and header present
        try {
            var dntEnabled = window.SlimStatParams && window.SlimStatParams.respect_dnt === "on";
            if (dntEnabled && navigator && navigator.doNotTrack == "1") return;
        } catch (e) {}

        // Mirror server policy: if PII-like features likely active (tracker cookie on OR IP not anonymized & not hashed), require explicit consent
        var likelyPII = true;
        try {
            var s = window.SlimStatParams || {};
            var anonMode = s.anonymous_tracking === "on";
            var setCookie = s.set_tracker_cookie === "on";
            var anonymize = s.anonymize_ip === "on" || anonMode;
            var hash = s.hash_ip === "on" || anonMode;
            likelyPII = !anonMode && (setCookie || (!anonymize && !hash));
        } catch (e) {
            likelyPII = true;
        }
        if (likelyPII && !cmpAllows) return;

        // Check if this is a navigation event (not initial page load)
        var isNavigationEvent = options.isNavigation || false;

        // For navigation events, always track regardless of javascript_mode
        // For initial page load, skip if server-side tracking is active
        if (!isNavigationEvent && !isEmpty(params.id) && parseInt(params.id, 10) > 0) {
            // Server-side tracking is active for initial page load, skip pageview but allow interactions
            return;
        }

        // For navigation events, we need to track the new page, not the current one
        if (isNavigationEvent) {
            // Force a new pageview for the navigation event
            params.id = null;
        }

        var payloadBase = buildPageviewBase(params);

        if (!payloadBase) return;

        // Prevent duplicate pageview requests
        if (pageviewInProgress) {
            return;
        }

        // De-duplicate rapid navigations (e.g., WP Interactivity quick transitions)
        var now = Date.now();
        if (payloadBase === lastPageviewPayload && now - lastPageviewSentAt < 150) {
            return;
        }

        lastPageviewPayload = payloadBase;
        lastPageviewSentAt = now;
        var waitForId = SlimStat.empty(params.id) || parseInt(params.id, 10) <= 0; // when new pageview
        var useBeacon = !waitForId; // need sync response when creating id

        // Avoid parallel initial pageview duplication
        if (inflightPageview && waitForId) return;
        inflightPageview = waitForId;
        pageviewInProgress = true;

        // Reset finalization state when starting new pageview
        // Note: finalizationInProgress is now managed in initSlimStatRuntime scope

        var run = function () {
            // FingerprintJS v4 async init; if it fails, proceed without fingerprint
            try {
                var fpPromise = FingerprintJS.load ? FingerprintJS.load() : null;
                if (fpPromise && typeof fpPromise.then === "function") {
                    fpPromise
                        .then(function (fp) {
                            return fp.get();
                        })
                        .then(function (result) {
                            initFingerprintHash(result);
                            sendToServer(payloadBase + buildSlimStatData([]), useBeacon, { immediate: isEmpty(params.id) });
                        })
                        .catch(function () {
                            initFingerprintHash(null);
                            sendToServer(payloadBase + buildSlimStatData([]), useBeacon, { immediate: isEmpty(params.id) });
                        })
                        .finally(function () {
                            inflightPageview = false;
                            pageviewInProgress = false;
                            setTimeout(function () {
                                pageviewInProgress = false;
                            }, 100);
                        });
                } else {
                    // Library not available; proceed without fingerprint
                    initFingerprintHash(null);
                    sendToServer(payloadBase + buildSlimStatData([]), useBeacon, { immediate: isEmpty(params.id) });
                    inflightPageview = false;
                    pageviewInProgress = false;
                    setTimeout(function () {
                        pageviewInProgress = false;
                    }, 100);
                }
            } catch (e) {
                initFingerprintHash(null);
                sendToServer(payloadBase + buildSlimStatData([]), useBeacon, { immediate: isEmpty(params.id) });
                inflightPageview = false;
                pageviewInProgress = false;
                setTimeout(function () {
                    pageviewInProgress = false;
                }, 100);
            }
        };
        if (window.requestIdleCallback) window.requestIdleCallback(run);
        else setTimeout(run, 250);
    }

    // -------------------------- GDPR Consent & Opt-out UI (removed) -------------------------- //
    function showGdprConsentBanner() {
        return false;
    }
    function handleGdprConsent() {
        return false;
    }
    function showOptoutMessage() {
        return false;
    }
    function optOut() {
        return false;
    }

    // GDPR Theme Mode Helper
    function initGdprThemeMode() {
        // Ensure global object exists and get params directly
        if (!window.SlimStatParams) window.SlimStatParams = {};
        var params = window.SlimStatParams;

        // Get theme mode setting
        var themeMode = params.gdpr_theme_mode || "auto";

        // Remove any existing theme classes
        document.body.classList.remove("gdpr-light-mode", "gdpr-dark-mode");

        switch (themeMode) {
            case "light":
                document.body.classList.add("gdpr-light-mode");
                document.body.classList.remove("gdpr-dark-mode", "gdpr-auto-mode");
                break;
            case "dark":
                document.body.classList.add("gdpr-dark-mode");
                document.body.classList.remove("gdpr-light-mode", "gdpr-auto-mode");
                break;
            case "auto":
            default:
                document.body.classList.add("gdpr-auto-mode");
                document.body.classList.remove("gdpr-light-mode", "gdpr-dark-mode");

                // Follow system preference
                if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
                    document.body.classList.add("gdpr-dark-mode");
                } else {
                    document.body.classList.add("gdpr-light-mode");
                }

                // Listen for system theme changes
                if (window.matchMedia) {
                    var mediaQuery = window.matchMedia("(prefers-color-scheme: dark)");
                    mediaQuery.addListener(function (e) {
                        if (themeMode === "auto") {
                            document.body.classList.remove("gdpr-light-mode", "gdpr-dark-mode");
                            if (e.matches) {
                                document.body.classList.add("gdpr-dark-mode");
                            } else {
                                document.body.classList.add("gdpr-light-mode");
                            }
                        }
                    });
                }
                break;
        }
    }

    // Inline GDPR CSS injection removed; stylesheet is loaded server-side

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
        optout: optOut,
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

    // Track whether we've already finalized the current pageview (avoid duplicate beacons)
    var finalizedPageviews = {};
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

    function finalizeCurrent(reason) {
        var p = window.SlimStatParams || {};
        if (!p.id || parseInt(p.id, 10) <= 0 || finalizedPageviews[p.id]) return; // no pageview id yet or already finalized

        var now = Date.now();
        if (finalizationInProgress || (reason === lastFinalizationReason && now - lastFinalizationTime < FINALIZATION_COOLDOWN)) return;

        finalizationInProgress = true;
        lastFinalizationReason = reason;
        lastFinalizationTime = now;

        // Old behavior: send a simple finalize to let the server compute dt_out
        var payload = "action=slimtrack&id=" + p.id + (reason ? "&fv=" + encodeURIComponent(reason) : "");
        SlimStat.send_to_server(payload, true, { priority: "high", immediate: false });
        finalizedPageviews[p.id] = true;
        setTimeout(function () {
            finalizationInProgress = false;
        }, 120);
    }

    // Observe for parameter mutations (meta tag or script changes)
    // Only observe if we don't have an ID yet (to avoid unnecessary tracking requests)
    var lastParams = JSON.stringify(window.SlimStatParams || {});
    var observer = new MutationObserver(function () {
        var params = window.SlimStatParams || {};
        // Only extract params if we don't have an ID yet (initial page load)
        if (SlimStat.empty(params.id) || parseInt(params.id, 10) <= 0) {
            SlimStat._extract_params();
            var serialized = JSON.stringify(window.SlimStatParams || {});
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
    if (window.wp_listen_for_consent_change) {
        var consentRetried = false;
        window.wp_listen_for_consent_change(function (category) {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "functional";

            var shouldTrack = !consentRetried && category === selectedCategory && (!params.id || parseInt(params.id, 10) <= 0);

            // If consent was granted for our category and we haven't tracked yet, send pageview now
            if (shouldTrack) {
                consentRetried = true;
                SlimStat._send_pageview();
            }
        });
    }

    // Standard WP Consent API event listener
    document.addEventListener("wp_consent_change", function (event) {
        if (event.detail && event.detail.category) {
            var category = event.detail.category;
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "functional";
            var consentRetried = window.slimstatConsentRetried || false;

            var shouldTrack = !consentRetried && category === selectedCategory && (!params.id || parseInt(params.id, 10) <= 0);

            if (shouldTrack) {
                // Double-check with WP Consent API if available
                if (typeof window.wp_has_consent === "function" && !window.wp_has_consent(selectedCategory)) return;
                window.slimstatConsentRetried = true;
                SlimStat._send_pageview();
            }
        }
    });

    // CMP-specific listeners
    (function registerCmpListeners() {
        function tryTrackIfAllowed() {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "functional";
            if (params.id && parseInt(params.id, 10) > 0) return;
            if (typeof window.wp_has_consent === "function" && !window.wp_has_consent(selectedCategory)) return;
            if (!window.slimstatConsentRetried) {
                window.slimstatConsentRetried = true;
                SlimStat._send_pageview();
            }
        }

        // Complianz: enable specific category
        document.addEventListener("cmplz_enable_category", function (e) {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "functional";
            var cat = (e && e.detail && (e.detail.category || e.detail)) || "";
            if (cat === selectedCategory) tryTrackIfAllowed();
        });

        // Complianz: status event (allow/deny)
        document.addEventListener("cmplz_event_status", function (e) {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "functional";
            var d = (e && e.detail) || {};
            var cat = d.category || d.type || "";
            var allowed = d.status === "allow" || d.enabled === true;
            if (cat === selectedCategory && allowed) tryTrackIfAllowed();
        });

        // Real Cookie Banner
        window.addEventListener("RealCookieBannerConsentChanged", function (e) {
            var params = currentSlimStatParams();
            var selectedCategory = params.consent_level_integration || "functional";
            var ok = false;
            if (e && e.detail && e.detail.consent && selectedCategory in e.detail.consent) {
                ok = !!e.detail.consent[selectedCategory];
            }
            if (typeof window.wp_has_consent === "function") ok = !!window.wp_has_consent(selectedCategory);
            if (ok) tryTrackIfAllowed();
        });

        // Borlabs Cookie (generic consent saved event)
        window.addEventListener("borlabs-cookie-consent-saved", function () {
            tryTrackIfAllowed();
        });

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
        var params = window.SlimStatParams || {};
        if (document.visibilityState === "hidden" && params.id && parseInt(params.id, 10) > 0) {
            debouncedFinalize("visibility");
        }
    });
    SlimStat.add_event(window, "pagehide", function () {
        // Only finalize if we have an active ID
        var params = window.SlimStatParams || {};
        if (params.id && parseInt(params.id, 10) > 0) {
            debouncedFinalize("pagehide");
        }
    });
    SlimStat.add_event(window, "beforeunload", function () {
        // Only finalize if we have an active ID
        var params = window.SlimStatParams || {};
        if (params.id && parseInt(params.id, 10) > 0) {
            debouncedFinalize("beforeunload");
        }
    });

    // Add a small delay between finalization attempts to prevent rapid-fire duplicates
    var finalizationTimeout = null;
    function debouncedFinalize(reason) {
        // Don't finalize if already finalized for this pageview ID
        var p = window.SlimStatParams || {};
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
})();
