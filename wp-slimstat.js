import Fingerprint2 from "fingerprintjs2";

/**
 * SlimStat: Browser tracking helper (refactored for maintainability)
 * Public API surface preserved (SlimStat.*) while internals modernized and scoped.
 * NOTE: Legacy browsers still supported via simple polyfills below.
 */
// eslint-disable-next-line no-var
var SlimStat = (function () {
    const BASE64_KEY_STR = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-";
    let fingerprintHash = "";
    let lastPageviewPayload = "";
    let lastPageviewSentAt = 0;
    let inflightPageview = false;
    // Queue to enforce sequential sending order for tracking requests
    const requestQueue = [];
    let queueInFlight = false;
    const MAX_QUEUE_ATTEMPTS = 4;
    const QUEUE_HIGH_WATERMARK = 80; // drop low-priority if exceeded
    let lastInteractionPayload = "";
    let lastInteractionTime = 0;
    const PENDING_INTERACTIONS_LIMIT = 20;
    const pendingInteractions = [];

    function bufferInteraction(raw) {
        if (pendingInteractions.length >= PENDING_INTERACTIONS_LIMIT) pendingInteractions.shift();
        pendingInteractions.push(raw);
    }

    function flushPendingInteractions() {
        if (!pendingInteractions.length) return;
        const params = currentSlimStatParams();
        if (!params.id || parseInt(params.id, 10) <= 0) return; // still can't flush
        while (pendingInteractions.length) {
            const raw = pendingInteractions.shift();
            const payload = "action=slimtrack&id=" + params.id + raw;
            sendToServer(payload, true, { priority: "normal" });
        }
    }

    // Offline persistence helpers
    const OFFLINE_KEY = "slimstat_offline_queue";
    function loadOfflineQueue() {
        try {
            const raw = localStorage.getItem(OFFLINE_KEY);
            if (!raw) return [];
            const arr = JSON.parse(raw);
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
        const arr = loadOfflineQueue();
        arr.push({ p: payload, t: Date.now() });
        saveOfflineQueue(arr);
    }
    function flushOfflineQueue() {
        const arr = loadOfflineQueue();
        if (!arr.length) return;
        saveOfflineQueue([]); // clear first to avoid loops
        arr.forEach((item) => {
            sendToServer(item.p, true, { priority: "normal" });
        });
    }

    // -------------------------- Generic Helpers -------------------------- //
    function utf8Encode(string) {
        string = (string || "").replace(/\r\n/g, "\n");
        let utftext = "";
        for (let n = 0; n < string.length; n++) {
            const c = string.charCodeAt(n);
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
        let output = "";
        let i = 0;
        input = utf8Encode(input);
        while (i < input.length) {
            const chr1 = input.charCodeAt(i++);
            const chr2 = input.charCodeAt(i++);
            const chr3 = input.charCodeAt(i++);
            const enc1 = chr1 >> 2;
            const enc2 = ((chr1 & 3) << 4) | (chr2 >> 4);
            let enc3 = ((chr2 & 15) << 2) | (chr3 >> 6);
            let enc4 = chr3 & 63;
            if (isNaN(chr2)) enc3 = enc4 = 64;
            else if (isNaN(chr3)) enc4 = 64;
            output += BASE64_KEY_STR.charAt(enc1) + BASE64_KEY_STR.charAt(enc2) + BASE64_KEY_STR.charAt(enc3) + BASE64_KEY_STR.charAt(enc4);
        }
        return output;
    }

    function isEmpty(v) {
        if (v === undefined || v === null) return true;
        const t = typeof v;
        if (t === "boolean") return !v;
        if (t === "number") return isNaN(v) || v === 0;
        if (t === "string") return v.length === 0;
        if (Array.isArray(v)) return v.length === 0;
        if (t === "object") return Object.keys(v).length === 0;
        return false;
    }

    function anySubstring(str, needles) {
        if (!str || !needles || !needles.length) return false;
        for (let i = 0; i < needles.length; i++) {
            if (str.indexOf(needles[i].trim()) !== -1) return true;
        }
        return false;
    }

    function getCookie(name) {
        const value = "; " + document.cookie;
        const parts = value.split("; " + name + "=");
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
        const timing = (window.performance || {}).timing || {};
        if (!timing.responseEnd || !timing.connectEnd) return 0;
        return timing.responseEnd - timing.connectEnd;
    }

    function getPagePerformance() {
        const timing = (window.performance || {}).timing || {};
        if (!timing.loadEventEnd || !timing.responseEnd) return 0;
        return timing.loadEventEnd - timing.responseEnd;
    }

    function getComponentValue(components, key, def) {
        for (let i = 0; i < components.length; i++) if (components[i].key === key) return components[i].value;
        return def;
    }

    function currentSlimStatParams() {
        // Ensure global object exists
        if (!window.SlimStatParams) window.SlimStatParams = {};
        return window.SlimStatParams;
    }

    // -------------------------- Parameters Extraction -------------------------- //
    function extractSlimStatParams() {
        const meta = document.querySelector('meta[name="slimstat-params"]');
        if (meta) {
            try {
                window.SlimStatParams = JSON.parse(meta.getAttribute("content")) || {};
            } catch (e) {
                /* ignore */
            }
        } else {
            // Fallback: look through inline scripts (same as legacy)
            const scripts = document.querySelectorAll("script");
            for (let i = scripts.length - 1; i >= 0; i--) {
                const match = scripts[i].textContent.match(/var\s+SlimStatParams\s*=\s*({[\s\S]*?});/);
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
    function initFingerprintHash(components) {
        try {
            const values = components.map((c) => c.value);
            fingerprintHash = Fingerprint2.x64hash128(values.join(""), 31);
        } catch (e) {
            fingerprintHash = ""; // graceful fallback
        }
    }

    function buildSlimStatData(components) {
        const screenres = getComponentValue(components, "screenResolution", [0, 0]);
        return "&sw=" + screenres[0] + "&sh=" + screenres[1] + "&bw=" + window.innerWidth + "&bh=" + window.innerHeight + "&sl=" + getServerLatency() + "&pp=" + getPagePerformance() + "&fh=" + fingerprintHash + "&tz=" + getComponentValue(components, "timezoneOffset", 0);
    }

    // -------------------------- Transport -------------------------- //
    function sendToServer(payload, useBeacon, opts) {
        if (isEmpty(payload)) return false;
        opts = opts || {};
        const params = currentSlimStatParams();
        const transports = ["rest", "ajax", "adblock"];
        const endpoints = { rest: params.ajaxurl_rest, ajax: params.ajaxurl_ajax, adblock: params.ajaxurl_adblock };
        const selected = params.transport;
        const order = [selected].concat(transports.filter((t) => t !== selected));

        // Enqueue logic (default: queued). Pass opts.immediate=true to bypass queue.
        if (!opts.immediate) {
            // Queue pressure control: drop oldest non-high if above high watermark
            if (requestQueue.length > QUEUE_HIGH_WATERMARK) {
                for (let i = requestQueue.length - 1; i >= 0 && requestQueue.length > QUEUE_HIGH_WATERMARK; i--) {
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
            let xhr;
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
                        const parsed = parseInt(xhr.responseText, 10);
                        if (!isNaN(parsed) && parsed > 0) params.id = xhr.responseText; // store new id
                    } else if (onFail) onFail();
                }
            };
            xhr.send(payload);
            return true;
        }

        function trySend(i) {
            if (i >= order.length) return false;
            const method = order[i];
            const url = endpoints[method];
            if (!url) return trySend(i + 1);
            if (useBeacon && navigator.sendBeacon && i === 0) {
                const ok = navigator.sendBeacon(url, payload);
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
        const item = requestQueue.shift();
        if (!item) return;
        queueInFlight = true;
        // Force immediate send (not enqueuing again)
        const done = function () {
            queueInFlight = false;
            // Process next after a micro delay to allow ID assignment, etc.
            setTimeout(processQueue, 0);
        };
        // Wrap original send with callback hooking via XHR readyState (monkey patch)
        const params = currentSlimStatParams();
        const originalId = params.id;
        // We can't directly get callback from sendToServer; instead we replicate logic here for queue items
        (function queuedSend(payload, useBeacon, opts) {
            opts = opts || {};
            const transports = ["rest", "ajax", "adblock"];
            const endpoints = { rest: params.ajaxurl_rest, ajax: params.ajaxurl_ajax, adblock: params.ajaxurl_adblock };
            const selected = params.transport;
            const order = [selected].concat(transports.filter((t) => t !== selected));
            function sendXHR(url, onFail) {
                let xhr;
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
                            const parsed = parseInt(xhr.responseText, 10);
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
                const method = order[i];
                const url = endpoints[method];
                if (!url) return trySend(i + 1);
                if (useBeacon && navigator.sendBeacon && i === 0) {
                    navigator.sendBeacon(url, payload);
                    // Beacon is fire-and-forget; we mark done immediately
                    done();
                    return true;
                }
                return sendXHR(url, function () {
                    trySend(i + 1);
                });
            }
            trySend(0);
        })(item.payload, item.useBeacon, item.opts);
    }

    // -------------------------- Interaction Tracking -------------------------- //
    function trackInteraction(event, note, useBeacon) {
        const params = currentSlimStatParams();
        if (isEmpty(params.id) || isNaN(parseInt(params.id, 10)) || parseInt(params.id, 10) <= 0) {
            // Buffer interaction until we have an id
            try {
                const minimal = buildInteractionRaw(event, note);
                bufferInteraction(minimal);
            } catch (e) {
                /* ignore */
            }
            return false;
        }
        if (!event || isEmpty(event.type) || event.type === "focus") return false;

        useBeacon = typeof useBeacon === "boolean" ? useBeacon : true;
        const target = event.target || event.srcElement;
        if (!target) return false;

        const noteObj = {};
        if (!isEmpty(note)) noteObj.note = note;

        // Derive resource URL
        let resourceUrl = "";
        (function derive() {
            if (!target.nodeName) return;
            const node = target.nodeName.toLowerCase();
            if (node === "input" || node === "button") {
                let p = target.parentNode;
                while (p && p.nodeName && p.nodeName.toLowerCase() !== "form") p = p.parentNode;
                if (p && p.action) resourceUrl = p.action;
                return;
            }
            // anchor resolution (support nested nodes)
            if (!target.href || typeof target.href !== "string") {
                let p = target.parentNode;
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
            const val = target.getAttribute("value");
            if (val) noteObj.value = val;
            const title = target.getAttribute("title");
            if (title) noteObj.title = title;
            const idAttr = target.getAttribute("id");
            if (idAttr) noteObj.id = idAttr;
        }
        noteObj.type = event.type;
        if (event.type === "keypress") noteObj.key = String.fromCharCode(parseInt(event.which, 10));
        else if (event.type === "mousedown") noteObj.button = event.which === 1 ? "left" : event.which === 2 ? "middle" : "right";

        const doNotTrack = params.dnt ? params.dnt.split(",") : [];
        if (resourceUrl && doNotTrack.length && anySubstring(resourceUrl, doNotTrack)) return false;

        // class-based do not track
        if (doNotTrack.length && target.className && typeof target.className === "string") {
            const classes = target.className.split(" ");
            if (classes.some((c) => doNotTrack.indexOf(c) !== -1)) return false;
        }
        if (doNotTrack.length && target.attributes && target.attributes.rel && target.attributes.rel.value) {
            if (anySubstring(target.attributes.rel.value, doNotTrack)) return false;
        }

        // Coordinates
        let position = "0,0";
        if (!isEmpty(event.pageX) && !isEmpty(event.pageY)) position = event.pageX + "," + event.pageY;
        else if (!isEmpty(event.clientX)) position = event.clientX + (document.body.scrollLeft || 0) + (document.documentElement.scrollLeft || 0) + "," + (event.clientY + (document.body.scrollTop || 0) + (document.documentElement.scrollTop || 0));

        const fingerprintParam = resourceUrl ? "&fh=" + fingerprintHash : "";
        const raw = "&res=" + base64Encode(resourceUrl) + "&pos=" + position + "&no=" + base64Encode(JSON.stringify(noteObj)) + fingerprintParam;
        const payload = "action=slimtrack&id=" + params.id + raw;
        const now = Date.now();
        if (payload === lastInteractionPayload && now - lastInteractionTime < 1000) return false; // dedupe bursts
        lastInteractionPayload = payload;
        lastInteractionTime = now;
        return sendToServer(payload, useBeacon);
    }

    function buildInteractionRaw(event, note) {
        // Reconstruct minimal raw (without id) for buffering.
        const target = (event && (event.target || event.srcElement)) || {};
        let resourceUrl = "";
        try {
            if (target.href) resourceUrl = target.href;
        } catch (e) {
            /* ignore */
        }
        const noteObj = { type: event ? event.type : "unknown" };
        if (note) noteObj.note = note;
        let position = "0,0";
        if (event && !isEmpty(event.pageX) && !isEmpty(event.pageY)) position = event.pageX + "," + event.pageY;
        return "&res=" + base64Encode(resourceUrl) + "&pos=" + position + "&no=" + base64Encode(JSON.stringify(noteObj));
    }

    // -------------------------- Pageview Logic -------------------------- //
    const FP_EXCLUDES = { excludes: { adBlock: true, addBehavior: true, userAgent: true, canvas: true, webgl: true, colorDepth: true, deviceMemory: true, hardwareConcurrency: true, sessionStorage: true, localStorage: true, indexedDb: true, openDatabase: true, cpuClass: true, plugins: true, webglVendorAndRenderer: true, hasLiedLanguages: true, hasLiedResolution: true, hasLiedOs: true, hasLiedBrowser: true, fonts: true, audio: true } };

    function buildPageviewBase(params) {
        if (!isEmpty(params.id) && parseInt(params.id, 10) > 0) return "action=slimtrack&id=" + params.id;
        let base = "action=slimtrack&ref=" + base64Encode(document.referrer) + "&res=" + base64Encode(window.location.href);
        if (!isEmpty(params.ci)) base += "&ci=" + params.ci;
        return base;
    }

    function sendPageview(options = {}) {
        extractSlimStatParams();
        const params = currentSlimStatParams();
        const payloadBase = buildPageviewBase(params);
        if (!payloadBase) return;
        // De-duplicate rapid navigations (e.g., WP Interactivity quick transitions)
        const now = Date.now();
        if (payloadBase === lastPageviewPayload && now - lastPageviewSentAt < 150) return; // skip
        lastPageviewPayload = payloadBase;
        lastPageviewSentAt = now;
        const waitForId = isEmpty(params.id) || parseInt(params.id, 10) <= 0; // when new pageview
        const useBeacon = !waitForId; // need sync response when creating id
        // Avoid parallel initial pageview duplication
        if (inflightPageview && waitForId) return;
        inflightPageview = waitForId;

        const run = function () {
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
        const params = currentSlimStatParams();
        const optCookies = params.oc ? params.oc.split(",") : [];
        let show = optCookies.length > 0;
        for (let i = 0; i < optCookies.length; i++)
            if (getCookie(optCookies[i])) {
                show = false;
                break;
            }
        if (!show) return false;
        let xhr;
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
                const div = document.createElement("div");
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
        const params = currentSlimStatParams();
        const expiration = new Date(Date.now() + 31536000000); // 1 year
        document.cookie = "slimstat_optout_tracking=" + cookieValue + ";path=" + (params.baseurl || "/") + ";expires=" + expiration.toGMTString();
        const target = event.target || event.srcElement;
        if (target && target.parentNode && target.parentNode.parentNode) target.parentNode.parentNode.removeChild(target.parentNode);
    }

    // -------------------------- Navigation / Interactivity Integration -------------------------- //
    function setupNavigationHooks() {
        // WordPress Interactivity API Event
        addEvent(document, "wp-interactivity:navigate", function () {
            // Finalize current pageview (if any) before starting a new one
            const params = currentSlimStatParams();
            if (params.id && parseInt(params.id, 10) > 0) {
                sendToServer("action=slimtrack&id=" + params.id, true, { priority: "high" });
            }
            params.id = null; // force new id on next pageview
            sendPageview();
        });

        // History API overrides (fallback for SPAs / Interactivity polyfills)
        if (window.history && history.pushState) {
            const originalPush = history.pushState;
            const originalReplace = history.replaceState;
            history.pushState = function () {
                const params = currentSlimStatParams();
                if (params.id) sendToServer("action=slimtrack&id=" + params.id, true, { priority: "high" }); // finalize existing
                params.id = null; // force new id
                const res = originalPush.apply(this, arguments);
                sendPageview();
                return res;
            };
            history.replaceState = function () {
                const res = originalReplace.apply(this, arguments);
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
            let target = e.target;
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
        _setup_navigation_hooks: setupNavigationHooks,
        _setup_click_delegation: setupClickDelegation,
    };
})();

// Polyfills for ES5 and older browsers
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
    let finalized = false;

    function finalizeCurrent(reason) {
        if (finalized) return;
        const p = window.SlimStatParams || {};
        if (p.id && parseInt(p.id, 10) > 0) {
            // Attach a tiny hint (reason) so backend could differentiate (ignored if unsupported)
            const payload = "action=slimtrack&id=" + p.id + (reason ? "&fv=" + encodeURIComponent(reason) : "");
            SlimStat.send_to_server(payload, true, { priority: "high", immediate: false });
            finalized = true;
        }
    }

    // Observe for parameter mutations (meta tag or script changes)
    let lastParams = JSON.stringify(window.SlimStatParams || {});
    const observer = new MutationObserver(function () {
        SlimStat._extract_params();
        const serialized = JSON.stringify(window.SlimStatParams || {});
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
        if (document.visibilityState === "hidden") finalizeCurrent("visibility");
    });
    SlimStat.add_event(window, "pagehide", function () {
        finalizeCurrent("pagehide");
    });
    SlimStat.add_event(window, "beforeunload", function () {
        finalizeCurrent("beforeunload");
    });

    // Online event to resend offline queue
    SlimStat.add_event(window, "online", function () {
        flushOfflineQueue();
        flushPendingInteractions();
    });

    // Setup interaction tracking
    SlimStat._setup_click_delegation();
    SlimStat._setup_navigation_hooks();
})();
