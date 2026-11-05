/** SlimStat Form Analytics: structural behavior only. @license GPL-2.0-or-later */
(function () {
    "use strict";
    if (!window.SlimStat || window.SlimStatForms) return;
    var states = [], timer = null, pageId = "", observer, changesObserver, extension;
    var selector = 'input[data-slimstat-form][name="_slimstat_form"]';

    function params() { return window.SlimStatParams || {}; }
    function allowed() {
        var decision = SlimStat.consent.checkAllowed(params());
        return decision.allowed && decision.mode === "full";
    }
    function extra(state, method) {
        try {
            if (method === "attach") state.extra = extension(state.form, eligibleField, allowed);
            else if (state.extra && typeof state.extra[method] === "function") return state.extra[method]();
        } catch (ignore) { /* Optional analytics must never affect a form or shared tracking. */ }
        return null;
    }
    function context(state) {
        if (!allowed() || !params().id || parseInt(params().id, 10) < 1) return false;
        var id = String(params().id);
        if (pageId !== id) {
            pageId = id;
            states.forEach(function (item) { item.flags = 0; item.sent = 0; item.fieldSent = ""; extra(item, "reset"); });
        }
        var input = state.form.querySelector('input[name="_slimstat_context"]');
        if (!input) {
            input = document.createElement("input");
            input.type = "hidden";
            input.name = "_slimstat_context";
            state.form.appendChild(input);
        }
        input.value = id; // Our signed page context, never a visitor-entered field.
        // Ninja's documented extra-data API avoids its filtered field payload.
        if (state.key.indexOf("ninja:") === 0 && window.nfRadio && typeof window.nfRadio.channel === "function") {
            try { window.nfRadio.channel("form-" + state.key.slice(6)).request("add:extra", "slimstat_context", id); } catch (ignore) { /* The form still submits normally. */ }
        }
        return true;
    }
    function flush() {
        timer = null;
        if (!allowed() || !pageId) return;
        var changed = [], forms = [], size = 32;
        states.forEach(function (state) {
            var fields = extra(state, "snapshot");
            var fieldData = fields ? JSON.stringify(fields) : "";
            if (!state.flags || (state.flags === state.sent && fieldData === (state.fieldSent || ""))) return;
            var item = { token: state.token, flags: state.flags };
            if (fields) item.fields = fields;
            var bytes = JSON.stringify(item).length + 1;
            if (size + bytes > 7500) {
                if (timer === null) timer = setTimeout(flush, 250);
                return;
            }
            size += bytes;
            changed.push({ state: state, fields: fieldData });
            forms.push(item);
        });
        if (!changed.length) return;
        var payload = "action=slimtrack&id=" + encodeURIComponent(pageId) + "&fa=" + encodeURIComponent(JSON.stringify({ v: 1, forms: forms }));
        var sendingPage = pageId;
        changed.forEach(function (item) { item.state.sent = item.state.flags; item.state.fieldSent = item.fields; });
        var accepted = SlimStat.send_to_server(payload, false, {
            priority: "normal",
            onComplete: function (success, rejected) {
                if (!success && !rejected && pageId === sendingPage) changed.forEach(function (item) { item.state.sent = 0; item.state.fieldSent = ""; });
            }
        });
        if (!accepted) changed.forEach(function (item) { item.state.sent = 0; item.state.fieldSent = ""; });
    }
    function mark(state, flag) {
        if (!context(state)) return;
        var flags = state.flags | 1 | flag | (state.invalid ? 8 : 0);
        if (flags === state.flags) return;
        state.flags = flags;
        if (timer === null) timer = setTimeout(flush, 250);
    }
    function visible(state) {
        if (document.visibilityState === "hidden") return false;
        var box = state.form.getBoundingClientRect();
        var style = window.getComputedStyle(state.form);
        return box.width > 0 && box.height > 0 && box.top < window.innerHeight && box.bottom > 0 && box.left < window.innerWidth && box.right > 0 && style.visibility !== "hidden" && style.display !== "none";
    }
    function resume() {
        states.forEach(function (state) {
            mark(state, visible(state) ? 2 : 0);
        });
    }
    function eligibleField(element) {
        return element && /^(INPUT|SELECT|TEXTAREA)$/.test(element.tagName)
            && !/^(hidden|password|submit|button|reset|image|file)$/.test(element.type || "")
            && element.getClientRects().length > 0
            && !(element.autocomplete || "").split(/\s+/).some(function (token) { return /^(cc-|current-password|new-password)/.test(token); });
    }
    function bind(marker) {
        if (states.length >= 20 || !marker.form || states.some(function (item) { return item.form === marker.form; })) return;
        var state = { form: marker.form, token: marker.value, key: marker.getAttribute("data-slimstat-form"), flags: 0, sent: 0, invalid: marker.getAttribute("data-slimstat-invalid") === "1" };
        states.push(state);
        if (extension) extra(state, "attach");
        if (changesObserver && state.form.parentElement !== document.body) {
            changesObserver.observe(state.form.parentElement, { childList: true, subtree: true });
        }
        state.form.addEventListener("focusin", function (event) {
            if (eligibleField(event.target)) mark(state, 4);
        });
        state.form.addEventListener("input", function (event) {
            if (eligibleField(event.target)) mark(state, 4);
        });
        state.form.addEventListener("invalid", function () { mark(state, 8); }, true);
        if (state.key.indexOf("snow:") === 0) state.form.addEventListener("smf.invalid", function () { mark(state, 8); });
        if (window.jQuery) {
            // Public validation events; neither error messages nor field values are read.
            window.jQuery(state.form).on("invalid-form.slimstatForms fluentform_validation_failed.slimstatForms", function () { mark(state, 8); });
        }
        state.form.addEventListener("submit", function () {
            context(state);
            flush(); // Never prevents, delays or verifies a submission.
        }, true);
        state.form.addEventListener("click", function (event) {
            var button = event.target.closest && event.target.closest('button,input[type="submit"]');
            if (state.extra && button && button.form === state.form) flush();
        }, true);
        if (observer) observer.observe(state.form);
        mark(state, visible(state) ? 2 : 0);
    }
    function scan(root) {
        if (!root || root.nodeType !== 1) return;
        if (states.some(function (state) { return !state.form.isConnected; })) flush();
        states = states.filter(function (state) {
            if (state.form.isConnected) return true;
            if (observer) observer.unobserve(state.form);
            extra(state, "destroy");
            return false;
        });
        if (root.matches(selector)) bind(root);
        root.querySelectorAll(selector).forEach(bind);
    }
    if (window.IntersectionObserver) {
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                states.forEach(function (state) { if (state.form === entry.target && visible(state)) mark(state, 2); });
            });
        });
    }
    window.SlimStatForms = {
        scan: scan,
        extend: function (factory) {
            if (extension || typeof factory !== "function") return;
            extension = factory;
            states.forEach(function (state) { extra(state, "attach"); });
        }
    };
    function scanChanges(changes) {
        changes.forEach(function (change) {
            change.addedNodes.forEach(function (node) { if (node.nodeType === 1) scan(node); });
        });
    }
    if (window.MutationObserver) {
        changesObserver = new MutationObserver(scanChanges);
        // A finite startup window discovers builders that render asynchronously.
        // Later replacements are observed only inside a known form's parent.
        var startupObserver = new MutationObserver(scanChanges);
        startupObserver.observe(document.body, { childList: true, subtree: true });
        setTimeout(function () { startupObserver.disconnect(); }, 10000);
    }
    scan(document.documentElement);
    function markFormInvalid(form) {
        if (form && form.jquery) form = form[0];
        states.forEach(function (state) { if (state.form === form) mark(state, 8); });
    }
    if (window.jQuery) {
        window.jQuery(document).on("frmFormErrors.slimstatForms", function (event, form) { markFormInvalid(form); });
        window.jQuery(document).on("frmAddFieldError.slimstatForms", function (event, container) {
            if (container && container.jquery && container[0]) markFormInvalid(container[0].closest("form"));
        });
    }
    if (window.nfRadio && typeof window.nfRadio.channel === "function") {
        window.nfRadio.channel("fields").on("add:error", function (field) {
            if (!field || typeof field.get !== "function") return;
            var key = "ninja:" + field.get("formID");
            states.forEach(function (state) { if (state.key === key) mark(state, 8); });
        });
    }
    // A late dynamic form can opt in through scan(), or bind on its first focus.
    document.addEventListener("focusin", function (event) {
        var form = event.target && event.target.form;
        if (!form) return;
        var marker = form.querySelector(selector);
        if (marker) {
            bind(marker);
            states.forEach(function (state) { if (state.form === form && eligibleField(event.target)) mark(state, 4); });
        }
    });
    document.addEventListener("slimstat:pageview", resume);
    document.addEventListener("slimstat:consent:updated", function () {
        if (!allowed()) {
            states.forEach(function (state) {
                state.flags = 0; state.sent = 0; state.fieldSent = ""; extra(state, "reset");
                var input = state.form.querySelector('input[name="_slimstat_context"]');
                if (input) input.remove();
                if (state.key.indexOf("ninja:") === 0 && window.nfRadio && typeof window.nfRadio.channel === "function") {
                    try { window.nfRadio.channel("form-" + state.key.slice(6)).request("add:extra", "slimstat_context", ""); } catch (ignore) { /* No analytics context survives revocation. */ }
                }
            });
            pageId = "";
            return;
        }
        resume();
    });
    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "hidden") flush(); else resume();
    });
    window.addEventListener("pagehide", flush);
}());
