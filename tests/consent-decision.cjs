const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../wp-slimstat.js`, 'utf8');
const start = source.indexOf('    function slimstatConsentAllowed(');
const end = source.indexOf('\n    function ', start + 1);
assert(start >= 0 && end > start, 'actual consent decision function must be found');
let consent = null;
const scope = {
    navigator: {}, maybeEmitConsentChange() {}, hasConsentUpgradeSucceeded: () => false,
    markConsentUpgradeDone() {},
    detectSlimStatBanner: () => consent,
};
vm.createContext(scope);
vm.runInContext(source.slice(start, end), scope);
const settings = {gdpr_enabled: 'on', anonymous_tracking: 'on', set_tracker_cookie: 'on',
    consent_integration: 'slimstat_banner', use_slimstat_banner: 'on'};
for (const [choice, mode] of [[null, 'anonymous'], [false, 'anonymous'], [true, 'full']]) {
    consent = choice;
    const result = scope.slimstatConsentAllowed(settings, {});
    assert.equal(result.allowed, true);
    assert.equal(result.mode, mode, `banner consent ${choice}`);
}
console.log('PASS: unknown/denied banner consent stays anonymous; accepted consent allows PII');

let upgradeState = 'done';
scope.markConsentUpgradeDone = success => { upgradeState = success ? 'done' : ''; };
scope.hasConsentUpgradeSucceeded = () => upgradeState === 'done';
consent = false;
scope.slimstatConsentAllowed(settings, {});
assert.equal(upgradeState, '', 'explicit denial must invalidate earlier consent upgrade');

const callbackStart = source.indexOf('        var onComplete = function (success) {');
const callbackEnd = source.indexOf('\n\n        // Add consent parameters', callbackStart);
assert(callbackStart >= 0 && callbackEnd > callbackStart, 'actual completion callback must be found');
scope.options = {consentUpgrade: true};
scope.resetPageviewFlags = () => {};
vm.runInContext(source.slice(callbackStart, callbackEnd), scope);
for (const [mode, success, expected] of [['anonymous', true, ''], ['full', true, 'done'], ['full', false, '']]) {
    scope.consentDecision = {mode};
    scope.onComplete(success);
    assert.equal(upgradeState, expected, `${mode} completion ${success}`);
}
console.log('PASS: denial clears stale upgrade; only successful full-consent completion records done');

const runtime = source.slice(source.indexOf('// Expose SlimStat to the global scope'));
for (const privateName of ['markConsentUpgradeDone', 'normalizeConsent', 'sendConsentChangeToServer', 'detectRealCookieBannerConsent']) {
    assert(!runtime.includes(`${privateName}(`), `runtime must use exported consent helpers: ${privateName}`);
}
console.log('PASS: runtime consent callers do not reference private module functions');

const requestStart = source.indexOf('    function requestConsentUpgrade(');
const requestEnd = source.indexOf('\n    function ', requestStart + 1);
assert(requestStart >= 0 && requestEnd > requestStart);
let sent = 0;
scope.window = {sendingSlimStatPageview: true};
scope.pendingConsentUpgrade = null;
scope.currentSlimStatParams = () => settings;
scope.claimConsentUpgradeSlot = () => true;
scope.SlimStat = {_send_pageview: () => { sent++; }};
vm.runInContext(source.slice(requestStart, requestEnd), scope);
consent = false;
scope.requestConsentUpgrade({});
assert.equal(scope.pendingConsentUpgrade, null, 'denied consent must not queue an upgrade');
consent = true;
scope.requestConsentUpgrade({consent: 'accepted'});
assert.equal(sent, 0);
assert.equal(scope.pendingConsentUpgrade.consent, 'accepted');
const resetStart = source.indexOf('        var resetPageviewFlags = function () {');
const resetEnd = source.indexOf('\n\n        var onComplete', resetStart);
assert(resetStart >= 0 && resetEnd > resetStart);
scope.setTimeout = callback => callback();
scope.requestKey = 'test-pageview';
vm.runInContext(source.slice(resetStart, resetEnd), scope);
scope.resetPageviewFlags();
assert.equal(sent, 1, 'completion must deliver exactly one queued grant');
assert.equal(scope.pendingConsentUpgrade, null);
console.log('PASS: consent granted during an in-flight pageview is delivered once after completion');
// Exercise every actual WP Consent API grant listener while preparation is locked.
for (const name of ['wp_consent_type_defined', 'wp_listen_for_consent_change', 'wp_consent_change', 'legacy-helper']) {
    const marker = name === 'legacy-helper' ? '    if (typeof window.wp_listen_for_consent_change === "function")' : `    document.addEventListener("${name}"`;
    const listenerStart = source.indexOf(marker);
    const listenerEnd = name === 'legacy-helper' ? source.indexOf('    // Listen for consent type definitions', listenerStart) : source.indexOf('\n    });', listenerStart) + '\n    });'.length;
    let grant;
    scope.document = {addEventListener: (event, fn) => { grant = fn; }};
    scope.window = {sendingSlimStatPageview: true, wp_has_consent: () => true, wp_listen_for_consent_change: fn => { grant = fn; }};
    scope.pendingConsentUpgrade = null;
    vm.runInContext(source.slice(listenerStart, listenerEnd), scope);
    const before = sent;
    grant(name === 'legacy-helper' ? 'statistics' : {detail: {statistics: 'allow', category: 'statistics', consent: 'allow'}});
    assert.notEqual(scope.pendingConsentUpgrade, null, `${name} grant must survive the preparation lock`);
    assert.equal(sent, before, `${name} must wait for the active preparation`);
    scope.resetPageviewFlags();
    assert.equal(sent, before + 1, `${name} delivers exactly one queued grant`);
}
console.log('PASS: all four WP Consent API grant listeners queue during locked preparation');


const beaconDeclaration = source.match(/var useBeacon = ([^;]+); \/\/ creation and consent upgrades/);
assert(beaconDeclaration, 'pageview beacon decision must be present');
for (const [waitForId, consentUpgrade, expected] of [[true, false, false], [false, true, false], [false, false, true]]) {
    assert.equal(vm.runInNewContext(beaconDeclaration[1], {waitForId, options: {consentUpgrade}}), expected);
}
console.log('PASS: consent upgrades await acknowledged response and retain transport fallback');

const flushStart = source.indexOf('    function storeOffline(');
const flushEnd = source.indexOf('\n    // -------------------------- Public API', flushStart);
assert(flushStart >= 0 && flushEnd > flushStart);
vm.runInContext(source.slice(flushStart, flushEnd), scope);
let offline = [{t: Date.now(), p: 'action=slimtrack&res=page-a'}, {t: Date.now(), p: 'action=slimtrack&res=page-b'}, {t: Date.now(), p: 'action=slimtrack&id=pending&no=click'}];
const delivered = [];
const acknowledgements = [];
scope.offlineInFlight = {};
scope.OFFLINE_MAX_AGE = 30 * 60 * 1000;
scope.OFFLINE_REPLAY_KEY = 'slimstat_offline_replay';
let sessionPending = [];
scope.loadSessionState = () => JSON.parse(JSON.stringify(sessionPending));
scope.saveSessionState = (key, value) => { sessionPending = JSON.parse(JSON.stringify(value)); return true; };
const durableCount = () => offline.length + sessionPending.length;
scope.loadOfflineQueue = () => offline;
scope.saveOfflineQueue = value => { offline = value; };
scope.sendToServer = (payload, beacon, opts) => { delivered.push(payload); acknowledgements.push(opts.onComplete); return true; };
scope.navigator.onLine = true;
settings.anonymous_tracking = 'off';
consent = false;
scope.flushOfflineQueue();
assert.equal(delivered.length, 0, 'withdrawn consent must stop offline replay');
assert.equal(offline.length, 3, 'blocked replay must not discard queued data');
consent = true;
scope.flushOfflineQueue();
assert.deepEqual(delivered, ['action=slimtrack&res=page-a', 'action=slimtrack&res=page-b']);
assert.equal(durableCount(), 3, 'queued transport is not an acknowledgement; reload must retain the payloads');
assert.equal(offline.length, 1, 'active replays must be owned by this tab');
scope.flushOfflineQueue();
assert.equal(delivered.length, 2, 'repeated online signals must not duplicate an in-flight replay');
acknowledgements[0](false);
assert.equal(durableCount(), 3, 'failed transport must retain durable payloads');
scope.flushOfflineQueue();
assert.equal(delivered.length, 3, 'a failed replay can be retried');
acknowledgements[2](true);
acknowledgements[1](true);
assert.equal(offline.length, 1, 'only interactions need an assigned pageview ID');
settings.id = '123.signed';
scope.flushOfflineQueue();
assert.equal(delivered[3], 'action=slimtrack&id=123.signed&no=click');
assert.equal(sessionPending.length, 1, 'the original pending payload remains durable until acknowledged');
assert.equal(sessionPending[0].p, 'action=slimtrack&id=123.signed&no=click', 'reload must retain the interaction\'s resolved pageview ID');
acknowledgements[3](false, true);
assert.equal(offline.length, 0);
scope.flushOfflineQueue();
assert.equal(delivered.length, 4, 'an empty replay must not enqueue duplicates');
offline = [{t: Date.now(), p: 'action=slimtrack&res=page-c'}];
scope.flushOfflineQueue();
offline.push({t: Date.now(), p: 'action=slimtrack&res=new-while-sending'}, null);
acknowledgements[4](true);
assert.deepEqual(offline.map(item => item && item.p), ['action=slimtrack&res=new-while-sending', null], 'acknowledgement must preserve newly queued data');
offline = [{t: Date.now(), p: 'action=slimtrack&res=shared-tab-page'}];
scope.flushOfflineQueue();
const otherTab = vm.createContext({...scope, offlineInFlight: {}, loadSessionState: () => [], saveSessionState: () => true});
vm.runInContext(source.slice(flushStart, flushEnd), otherTab);
otherTab.flushOfflineQueue();
assert.equal(delivered.filter(payload => payload === 'action=slimtrack&res=shared-tab-page').length, 1, 'another tab must not replay a payload already being delivered');
scope.offlineInFlight = {};
scope.restoreOfflineReplays();
assert.equal(offline.length, 1, 'reload recovers this tab\'s unacknowledged replay');
assert.equal(sessionPending.length, 0, 'restored entries must not remain claimed');
const sentBeforeStorageFailure = delivered.length;
const saveSession = scope.saveSessionState;
scope.saveSessionState = () => false;
scope.flushOfflineQueue();
assert.equal(offline.length, 1, 'unavailable tab storage must not discard queued data');
assert.equal(delivered.length, sentBeforeStorageFailure);
scope.saveSessionState = saveSession;
scope.saveOfflineQueue = () => {};
scope.flushOfflineQueue();
assert.equal(sessionPending.length, 0, 'a failed shared-queue claim must be abandoned');
assert.equal(delivered.length, sentBeforeStorageFailure);
scope.saveOfflineQueue = value => { offline = value; };
console.log('PASS: offline replay survives reload, owns in-flight data per tab, preserves new data and honors rejection/consent');

const apiStart = source.indexOf('    function detectWPConsentAPI(');
const apiEnd = source.indexOf('\n    function ', apiStart + 1);
assert(apiStart >= 0 && apiEnd > apiStart);
scope.isFunction = value => typeof value === 'function';
scope.isObject = value => value !== null && typeof value === 'object';
vm.runInContext(source.slice(apiStart, apiEnd), scope);
// WP Consent API 2.x services are distinct from categories. A permissive service
// result must never override the selected statistics category's denial.
scope.window = {wp_has_service_consent: () => true, wp_has_consent: () => false};
assert.equal(scope.detectWPConsentAPI('statistics'), false, 'service fallback cannot grant category consent');
for (const type of [undefined, '', 'optin', 'optout']) {
    for (const choice of ['', 'deny', 'allow']) {
        scope.window = {wp_fallback_consent_type: '', wp_has_consent: category => {
            assert.equal(category, 'statistics');
            const effectiveType = scope.window.wp_consent_type ?? scope.window.wp_fallback_consent_type;
            return !effectiveType || (effectiveType === 'optout' && choice === '') || choice === 'allow';
        }};
        if (type !== undefined) scope.window.wp_consent_type = type;
        assert.equal(scope.detectWPConsentAPI('statistics'), choice === 'allow' || (type === 'optout' && choice === ''), `type=${type}, choice=${choice}`);
        assert.equal(scope.window.wp_consent_type, type, 'temporary consent type must be restored');
        assert.equal(Object.hasOwn(scope.window, 'wp_consent_type'), type !== undefined);
    }
}
console.log('PASS: WP Consent API categories respect denial and unset types without changing CMP state');

// Run the actual bootstrap for both parser-time and late/deferred script execution.
const initialStart = source.indexOf('    function startInitialPageview()');
const initialEnd = source.indexOf('    // Listen for WP Consent API', initialStart);
assert(initialStart >= 0 && initialEnd > initialStart);
for (const readyState of ['loading', 'interactive', 'complete']) {
    let sends = 0;
    const handlers = {};
    const env = { document: {readyState}, navigator: {onLine: true}, setTimeout() {},
        SlimStat: { _extract_params() {}, _send_pageview() { sends++; }, add_event(target, event, fn) { handlers[event] = fn; } } };
    vm.runInNewContext(source.slice(initialStart, initialEnd), env);
    if (readyState === 'loading') { assert.equal(sends, 0); assert.equal(typeof handlers.DOMContentLoaded, 'function'); handlers.DOMContentLoaded(); }
    assert.equal(sends, 1, `one initial dispatch for ${readyState}`);
    assert.equal(handlers.load, undefined, 'load must not dispatch a second pageview');
}
const idle = source.match(/if \(window.requestIdleCallback\) window.requestIdleCallback\(run[^;]+;/);
assert(idle);
let deadline;
vm.runInNewContext(idle[0], {window: {requestIdleCallback(fn, options) { deadline = options && options.timeout; }}, run() {}});
assert(deadline > 0 && deadline <= 1000, 'initial pageview idle delay has a bounded deadline');
console.log('PASS: parser-time and late bootstraps dispatch once; idle delay is bounded');

// Real storage loader: expiry removes stale data, and failed replay cannot renew its age.
const queueStart = source.lastIndexOf('    function loadOfflineQueue()');
const queueEnd = source.indexOf('    function currentSlimStatParams()', queueStart);
let storedQueue = JSON.stringify([{p:'expired',t:Date.now()-1800001},{p:'current',t:Date.now()}, {p:'no-timestamp'}]);
const ttlScope = vm.createContext({SlimStat:{consent:{checkAllowed:()=>({mode:'full'})}},currentSlimStatParams:()=>({}),OFFLINE_KEY:'test',OFFLINE_MAX_AGE:1800000,localStorage:{getItem:()=>storedQueue,setItem:(k,v)=>{storedQueue=v;}}});
vm.runInContext(source.slice(queueStart,queueEnd),ttlScope);
assert.equal(ttlScope.loadOfflineQueue().length,1);
assert.equal(JSON.parse(storedQueue)[0].p,'current');
const originalTime=Date.now()-1700000;
offline=[{p:'retains-age',t:originalTime}]; scope.flushOfflineQueue();
acknowledgements[acknowledgements.length-1](false);
assert.equal(offline[0].t,originalTime,'failed replay must not renew its expiry');
sessionPending=[{p:'expired-session',t:Date.now()-1800001}]; scope.restoreOfflineReplays();
assert.equal(sessionPending.length,0); assert(!offline.some(item=>item.p==='expired-session'));
console.log('PASS: 30-minute expiry prunes shared and session queues and survives retries');

// Exercise the real withdrawal cleanup and a retry created before that withdrawal.
const changeStart = source.indexOf('    function maybeEmitConsentChange(');
const changeEnd = source.indexOf('\n    function ', changeStart + 1);
const removed = [];
const revokeScope = {consentGeneration: 0, pendingInteractions: ['private-click'], lastConsentSnapshot: 'true|full|cmp_allowed',
    localStorage: {removeItem: key => removed.push(key)}, sessionStorage: {removeItem: key => removed.push(key)},
    OFFLINE_REPLAY_KEY: 'slimstat_offline_replay', PENDING_SESSION_KEY: 'slimstat_pending_session',
    currentSlimStatParams: () => ({}), slimstatConsentAllowed: () => ({allowed: true, mode: 'full'})};
vm.createContext(revokeScope);
vm.runInContext(source.slice(changeStart, changeEnd), revokeScope);
revokeScope.maybeEmitConsentChange({allowed: false, mode: 'blocked', reason: 'cmp_denied'});
assert.equal(revokeScope.consentGeneration, 1);
assert.equal(revokeScope.pendingInteractions.length, 0);
assert.deepEqual(removed.sort(), ['slimstat_offline_queue', 'slimstat_offline_replay', 'slimstat_pending_session'].sort());
const processStart = source.indexOf('    function processQueueItem(');
const processEnd = source.indexOf('\n    function ', processStart + 1);
// processQueueItem asks the shared payloadBlocked() guard, so load it beside it.
const blockedStart = source.indexOf('    function payloadBlocked(');
const processSource = source.slice(blockedStart, source.indexOf('\n    function ', blockedStart + 1)) + source.slice(processStart, processEnd);
vm.runInContext(processSource, revokeScope);
let staleRejected = false;
revokeScope.processQueueItem({consentGeneration: 0, payload: 'id=old&no=private'}, result => { staleRejected = result.rejected; });
assert.equal(staleRejected, true, 'a later grant cannot revive a retry from before withdrawal');
console.log('PASS: withdrawal clears pending/device data and rejects delayed retries after re-consent');

scope.navigator = {globalPrivacyControl: true};
assert.equal(scope.slimstatConsentAllowed({...settings, gdpr_enabled: 'off', respect_dnt: 'on'}, {}).allowed, false);
console.log('PASS: Global Privacy Control blocks tracking even with consent mode disabled');

// Deterministic races against the actual pageview preparation and transport code.
async function preparationAndTransportRaces() {
    const pageStart = source.indexOf('    function sendPageview(');
    const pageEnd = source.indexOf('\n    // -------------------------- Consent Management', pageStart);
    const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
    for (const phase of ['idle', 'load', 'get', 'unchanged', 'anonymous']) {
        let mode = phase === 'anonymous' ? 'anonymous' : 'full';
        let idleRun, resolveLoad, resolveGet, gets = 0;
        const payloads = [], fingerprints = [];
        const params = {id: null, set_tracker_cookie: 'on'};
        const env = vm.createContext({
            consentGeneration: 0, pageviewInProgress: false, inflightPageview: false,
            pendingConsentUpgrade: null, lastPageviewPayload: '', lastPageviewSentAt: 0,
            extractSlimStatParams() {}, currentSlimStatParams: () => params,
            slimstatConsentAllowed: () => {
                // The initial anonymous decision itself advances the generation.
                if (phase === 'anonymous') env.consentGeneration = 1;
                return {allowed: true, mode};
            },
            window: {requestIdleCallback: fn => { idleRun = fn; }},
            SlimStat: {empty: value => !value}, isEmpty: value => !value,
            buildPageviewBase: (p, allow) => 'action=slimtrack&res=page' + (allow ? '&sid=old-private-session' : ''),
            buildSlimStatData: () => '&fh=' + (fingerprints.at(-1)?.visitorId || ''),
            initFingerprintHash: value => fingerprints.push(value),
            sendToServer: (payload, beacon, opts) => { payloads.push(payload); opts.onComplete(true); },
            setTimeout: fn => fn(), clearSessionState() {}, PENDING_SESSION_KEY: 'pending',
            FingerprintJS: {load: () => new Promise(resolve => { resolveLoad = resolve; })},
        });
        vm.runInContext(source.slice(pageStart, pageEnd), env);
        env.sendPageview();
        const revoke = () => { env.consentGeneration++; mode = 'anonymous'; };
        if (phase === 'idle') revoke();
        idleRun();
        if (phase !== 'idle' && phase !== 'anonymous') {
            if (phase === 'load') revoke();
            resolveLoad({get: () => { gets++; return new Promise(resolve => { resolveGet = resolve; }); }});
            await settle();
            if (phase !== 'load') {
                if (phase === 'get') { revoke(); mode = 'full'; } // revoke then grant again
                resolveGet({visitorId: 'old-private-fingerprint', components: {}});
                await settle();
            }
        }
        if (['idle', 'load', 'get'].includes(phase)) {
            assert.equal(payloads.length, 0, `${phase}: no stale identity is sent`);
            assert.equal(fingerprints.length, 0, `${phase}: no stale fingerprint is persisted`);
            if (phase !== 'get') assert.equal(gets, 0, `${phase}: no fingerprint collection starts`);
        } else {
            assert.equal(payloads.length, 1, `${phase}: ordinary pageview still works`);
            assert.equal(payloads[0].includes('sid='), phase === 'unchanged');
        }
        assert.equal(env.window.sendingSlimStatPageview, false, `${phase}: pageview lock released`);
    }
    for (const response of [{status: 500, body: ''}, {status: 403, body: ''}, {status: 200, body: '-101'}, {status: 200, body: '77.signed'}]) {
        const requests = [], results = [];
        const params = {id: '12.signed', ajaxurl_rest: '/rest', ajaxurl_ajax: '/ajax', is_logged_in: '1'};
        const env = vm.createContext({
            consentGeneration: 0, currentSlimStatParams: () => params,
            slimstatConsentAllowed: () => ({allowed: true, mode: 'full'}),
            shouldValidateIdWithXHR: () => false, isEmpty: value => !value,
            buildTransportOrder: () => ['rest_pretty', 'ajax'],
            XMLHttpRequest: function () { requests.push(this); this.open = () => {}; this.setRequestHeader = () => {}; this.send = () => {}; },
            rememberTransportFailure() {}, rememberTransportSuccess() {},
        });
        vm.runInContext(processSource, env);
        env.processQueueItem({consentGeneration: 0, payload: 'action=slimtrack&id=12.signed&fh=private', useBeacon: false}, result => results.push(result));
        assert.equal(requests.length, 1);
        env.consentGeneration++; // a withdrawal followed by a new grant
        Object.assign(requests[0], {readyState: 4, status: response.status, responseText: response.body});
        requests[0].onreadystatechange();
        requests[0].onreadystatechange();
        assert.equal(requests.length, 1, 'stale response cannot cause failover or stale-ID replay');
        assert.equal(results.length, 1, 'stale completion delivered once');
        assert.equal(results[0].rejected, true);
        assert.equal(params.id, '12.signed', 'stale success cannot restore an identifier');
    }
    console.log('PASS: idle/fingerprint races, anonymous initial counts, revoked transport failover/recovery and stale success are covered');
}
preparationAndTransportRaces().catch(error => { console.error(error); process.exitCode = 1; });

const setupSource = fs.readFileSync(`${__dirname}/e2e/helpers/setup.ts`, 'utf8');
const configStart = setupSource.indexOf('function activePhpConfig(');
const configEnd = setupSource.indexOf('\nexport function', configStart);
const configScope = vm.createContext({});
vm.runInContext(setupSource.slice(configStart, configEnd).replace('content: string): string', 'content)'), configScope);
const cronPattern = /\bdefine\s*\(\s*['"]DISABLE_WP_CRON['"]\s*,\s*true\s*\)/i;
for (const comment of ["// define('DISABLE_WP_CRON', true);", "# define('DISABLE_WP_CRON', true);", "/* define('DISABLE_WP_CRON', true); */"]) {
    assert.equal(cronPattern.test(configScope.activePhpConfig(comment)), false, 'commented cron definition is inactive');
}
assert.equal(cronPattern.test(configScope.activePhpConfig("define( 'DISABLE_WP_CRON', true );")), true);
assert.equal(configScope.activePhpConfig("define('URL', 'https://example.test/#path');"), "define('URL', 'https://example.test/#path');");
console.log('PASS: wp-config cron detection ignores comments and preserves quoted URL values');
