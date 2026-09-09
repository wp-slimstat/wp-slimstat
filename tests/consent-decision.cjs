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

const beaconDeclaration = source.match(/var useBeacon = ([^;]+); \/\/ creation and consent upgrades/);
assert(beaconDeclaration, 'pageview beacon decision must be present');
for (const [waitForId, consentUpgrade, expected] of [[true, false, false], [false, true, false], [false, false, true]]) {
    assert.equal(vm.runInNewContext(beaconDeclaration[1], {waitForId, options: {consentUpgrade}}), expected);
}
console.log('PASS: consent upgrades await acknowledged response and retain transport fallback');
