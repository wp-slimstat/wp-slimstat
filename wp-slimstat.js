// NOTE: Fingerprint2 (fingerprintjs2) library is required as a global dependency
// and should be loaded before this script

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
    var pendingInteractions = [];

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

    // Offline persistence helpers
    var OFFLINE_KEY = "slimstat_offline_queue";
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
    function storeOffline(payload) {
        var arr = loadOfflineQueue();
        arr.push({ p: payload, t: Date.now() });
        saveOfflineQueue(arr);
    }
    function flushOfflineQueue() {
        var arr = loadOfflineQueue();
        if (!arr.length) return;
        saveOfflineQueue([]); // clear first to avoid loops
        arr.forEach(function(item) {
            sendToServer(item.p, true, { priority: "normal" });
        });
    }

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
            var values = components.map((c) => c.value);
            fingerprintHash = Fingerprint2.x64hash128(values.join(""), 31);
        } catch (e) {
            fingerprintHash = ""; // graceful fallback
        }
    }

    function buildSlimStatData(components) {
        var screenres = getComponentValue(components, "screenResolution", [0, 0]);
        return "&sw=" + screenres[0] + "&sh=" + screenres[1] + "&bw=" + window.innerWidth + "&bh=" + window.innerHeight + "&sl=" + getServerLatency() + "&pp=" + getPagePerformance() + "&fh=" + fingerprintHash + "&tz=" + getComponentValue(components, "timezoneOffset", 0);
    }

    // -------------------------- Transport -------------------------- //
    function sendToServer(payload, useBeacon, opts) {
        if (isEmpty(payload)) return false;
        opts = opts || {};
        var params = currentSlimStatParams();
        var transports = ["rest", "ajax", "adblock"];
        var endpoints = { rest: params.ajaxurl_rest, ajax: params.ajaxurl_ajax, adblock: params.ajaxurl_adblock };
        var selected = params.transport;
        var order = [selected].concat(transports.filter((t) => t !== selected));

        // Enqueue logic (default: queued). Pass opts.immediate=true to bypass queue.
        if (!opts.immediate) {
            // Queue pressure control: drop oldest non-high if above high watermark
            if (requestQueue.length > QUEUE_HIGH_WATERMARK) {
                for (var i = requestQueue.length - 1; i >= 0 && requestQueue.length > QUEUE_HIGH_WATERMARK; i--) {
                    if (requestQueue[i].opts.priority !== "high") requestQueue.splice(i, 1);
                }
            }
            if (opts.priority === "high") {
                // Avoid duplicates of same payload at head
                if (!requestQueue.length || requestQueue[0].payload !== payload) requestQueue.unshift({ payload, useBeacon, opts });
            } else {
                requestQueue.push({ payload, useBeacon, opts });
            }
            processQueue();
            return true;
        }

        function sendXHR(url, onFail) {
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
                        if (!isNaN(parsed) && parsed > 0) params.id = xhr.responseText; // store new id
                    } else if (onFail) onFail();
                }
            };
            xhr.send(payload);
            return true;
        }

        function trySend(i) {
            if (i >= order.length) return false;
            var method = order[i];
            var url = endpoints[method];
            if (!url) return trySend(i + 1);
            if (useBeacon && navigator.sendBeacon && i === 0) {
                var ok = navigator.sendBeacon(url, payload);
                return ok || trySend(i + 1);
            }
            return sendXHR(url, function () {
                trySend(i + 1);
            });
        }
        return trySend(0);
    }

    function processQueue() {
        if (queueInFlight) return;
        var item = requestQueue.shift();
        if (!item) return;
        queueInFlight = true;
        // Force immediate send (not enqueuing again)
        var done = function () {
            queueInFlight = false;
            // Process next after a micro delay to allow ID assignment, etc.
            setTimeout(processQueue, 0);
        };
        // Wrap original send with callback hooking via XHR readyState (monkey patch)
        var params = currentSlimStatParams();
        var originalId = params.id;
        // We can't directly get callback from sendToServer; instead we replicate logic here for queue items
        (function queuedSend(payload, useBeacon, opts) {
            opts = opts || {};
            var transports = ["rest", "ajax", "adblock"];
            var endpoints = { rest: params.ajaxurl_rest, ajax: params.ajaxurl_ajax, adblock: params.ajaxurl_adblock };
            var selected = params.transport;
            var order = [selected].concat(transports.filter((t) => t !== selected));
            function sendXHR(url, onFail) {
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
                if (params.wp_rest_nonce) xhr.setRequestHeader("X-WP-Nonce", params.wp_rest_nonce);
                xhr.withCredentials = true;
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            var parsed = parseInt(xhr.responseText, 10);
                            if (!isNaN(parsed) && parsed > 0) params.id = xhr.responseText; // store new id
                        }
                        done();
                    }
                };
                try {
                    xhr.send(payload);
                } catch (e) {
                    done();
                }
                return true;
            }
            function trySend(i) {
                if (i >= order.length) {
                    done();
                    return false;
                }
                var method = order[i];
                var url = endpoints[method];
                if (!url) return trySend(i + 1);
                if (useBeacon && navigator.sendBeacon && i === 0) {
                    navigator.sendBeacon(url, payload);
                    // Beacon is fire-and-forget; we mark done immediately
                    done();
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
        });
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
            if (classes.some((c) => doNotTrack.indexOf(c) !== -1)) return false;
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
            var  FP_EXCLUDES = { excludes: { adBlock: true, addBehavior: true, userAgent: true, canvas: true, webgl: true, colorDepth: true, deviceMemory: true, hardwareConcurrency: true, sessionStorage: true, localStorage: true, indexedDb: true, openDatabase: true, cpuClass: true, plugins: true, webglVendorAndRenderer: true, hasLiedLanguages: true, hasLiedResolution: true, hasLiedOs: true, hasLiedBrowser: true, fonts: true, audio: true } };

    function buildPageviewBase(params) {
        if (!isEmpty(params.id) && parseInt(params.id, 10) > 0) return "action=slimtrack&id=" + params.id;
        var base = "action=slimtrack&ref=" + base64Encode(document.referrer) + "&res=" + base64Encode(window.location.href);
        if (!isEmpty(params.ci)) base += "&ci=" + params.ci;
        return base;
    }

    function sendPageview(options) {
        options = options || {};
        extractSlimStatParams();
        var params = currentSlimStatParams();
        var payloadBase = buildPageviewBase(params);
        if (!payloadBase) return;

        // Prevent duplicate pageview requests
        if (pageviewInProgress) {
            return;
        }

        // De-duplicate rapid navigations (e.g., WP Interactivity quick transitions)
        var now = Date.now();
        if (payloadBase === lastPageviewPayload && now - lastPageviewSentAt < 150) return; // skip
        lastPageviewPayload = payloadBase;
        lastPageviewSentAt = now;
        var waitForId = isEmpty(params.id) || parseInt(params.id, 10) <= 0; // when new pageview
        var useBeacon = !waitForId; // need sync response when creating id
        // Avoid parallel initial pageview duplication
        if (inflightPageview && waitForId) return;
        inflightPageview = waitForId;
        pageviewInProgress = true;

        // Reset finalization state when starting new pageview
        // Note: finalizationInProgress is now managed in initSlimStatRuntime scope

        var run = function () {
            Fingerprint2.get(FP_EXCLUDES, function (components) {
                initFingerprintHash(components);
                // Initial pageview (no id yet) should be immediate for faster id assignment
                sendToServer(payloadBase + buildSlimStatData(components), useBeacon, { immediate: isEmpty(params.id) });
                showOptoutMessage();
                inflightPageview = false;
            });
        };
        if (window.requestIdleCallback) window.requestIdleCallback(run);
        else setTimeout(run, 250);
    }

    // -------------------------- Opt-out UI -------------------------- //
    function showOptoutMessage() {
        var params = currentSlimStatParams();
        var optCookies = params.oc ? params.oc.split(",") : [];
        var show = optCookies.length > 0;
        for (var i = 0; i < optCookies.length; i++)
            if (getCookie(optCookies[i])) {
                show = false;
                break;
            }
        if (!show) return false;
        var xhr;
        try {
            xhr = new XMLHttpRequest();
        } catch (e) {
            return false;
        }
        xhr.open("POST", params.ajaxurl, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhr.withCredentials = true;
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                var div = document.createElement("div");
                div.innerHTML = xhr.responseText;
                document.body.appendChild(div);
            }
        };
        xhr.send("action=slimstat_optout_html");
        return true;
    }

    function optOut(event, cookieValue) {
        event = event || window.event;
        if (event && event.preventDefault) event.preventDefault();
        else if (event) event.returnValue = false;
        var params = currentSlimStatParams();
        var expiration = new Date(Date.now() + 31536000000); // 1 year
        document.cookie = "slimstat_optout_tracking=" + cookieValue + ";path=" + (params.baseurl || "/") + ";expires=" + expiration.toGMTString();
        var target = event.target || event.srcElement;
        if (target && target.parentNode && target.parentNode.parentNode) target.parentNode.parentNode.removeChild(target.parentNode);
    }

    // -------------------------- Navigation / Interactivity Integration -------------------------- //
    function setupNavigationHooks() {
        // WordPress Interactivity API Event
        addEvent(document, "wp-interactivity:navigate", function () {
            // Finalize current pageview (if any) before starting a new one
            var params = currentSlimStatParams();
            if (params.id && parseInt(params.id, 10) > 0) {
                sendToServer("action=slimtrack&id=" + params.id, true, { priority: "high" });
            }
            params.id = null; // force new id on next pageview
            sendPageview();
        });

        // History API overrides (fallback for SPAs / Interactivity polyfills)
        if (window.history && history.pushState) {
            var originalPush = history.pushState;
            var originalReplace = history.replaceState;
            history.pushState = function () {
                var params = currentSlimStatParams();
                if (params.id) sendToServer("action=slimtrack&id=" + params.id, true, { priority: "high" }); // finalize existing
                params.id = null; // force new id
                var res = originalPush.apply(this, arguments);
                sendPageview();
                return res;
            };
            history.replaceState = function () {
                var res = originalReplace.apply(this, arguments);
                sendPageview();
                return res;
            };
            addEvent(window, "popstate", function () {
                currentSlimStatParams().id = null;
                sendPageview();
            });
        }
    }

    // -------------------------- Event Delegation for Clicks -------------------------- //
    function setupClickDelegation() {
        addEvent(document.body, "click", function (e) {
            var target = e.target;
            while (target && target !== document.body) {
                if (target.matches && target.matches("a,button,input,area")) {
                    trackInteraction(e, null, null);
                    break;
                }
                target = target.parentNode;
            }
        });
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
        show_optout_message: showOptoutMessage,
        add_event: addEvent,
        in_array: anySubstring,
        empty: isEmpty,
        get_cookie: getCookie,
        send_to_server: sendToServer,
        ss_track: trackInteraction,
        init_fingerprint_hash: initFingerprintHash,
        get_slimstat_data: buildSlimStatData,
        get_component_value: getComponentValue,
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

window.SlimStat = SlimStat;

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
    // Track whether we've already finalized the current pageview (avoid duplicate beacons)
    var finalized = false;

    function finalizeCurrent(reason) {
        if (finalized) return;
        var p = window.SlimStatParams || {};
        if (p.id && parseInt(p.id, 10) > 0) {
            // Attach a tiny hint (reason) so backend could differentiate (ignored if unsupported)
            var payload = "action=slimtrack&id=" + p.id + (reason ? "&fv=" + encodeURIComponent(reason) : "");
            SlimStat.send_to_server(payload, true, { priority: "high", immediate: false });
            finalized = true;
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
    var lastParams = JSON.stringify(window.SlimStatParams || {});
            var  observer = new MutationObserver(function () {
        SlimStat._extract_params();
        var serialized = JSON.stringify(window.SlimStatParams || {});
        if (serialized !== lastParams) lastParams = serialized; // reserved for future diff-based logic
    });
    observer.observe(document.head, { childList: true, subtree: true });
    observer.observe(document.body, { childList: true, subtree: true });

    // Initial pageview
    SlimStat.add_event(window, "load", function () {
        SlimStat._extract_params();
        SlimStat._send_pageview();
        // Flush any offline stored payloads after initial pageview queued
        setTimeout(function () {
            try {
                if (navigator.onLine !== false) typeof flushOfflineQueue === "function" && flushOfflineQueue();
            } catch (e) {}
        }, 500);
    });

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
        flushOfflineQueue();
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
                if (target.matches && target.matches("a,button,input,area")) {
                    SlimStat.ss_track(e, null, null);
                    break;
                }
                target = target.parentNode;
            }
        });
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