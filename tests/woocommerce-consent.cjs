const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const php = fs.readFileSync(`${__dirname}/../src/Services/GDPRService.php`, 'utf8');
const scripts = [...php.matchAll(/<<<'JS'\n([\s\S]*?)\nJS/g)].map(m => m[1]);
assert.equal(scripts.length, 2, 'exercise the actual before/after WooCommerce scripts');
for (const permitted of [false, true]) {
 for (const cookie of ['', 'slimstat_gdpr_consent=denied', 'slimstat_gdpr_consent=accepted', 'slimstat_gdpr_consent=accepted-invalid']) {
  let listener, consentAPI = true, actual;
  const wc = {params: {allowTracking: permitted}};
  const scope = {window: {wc_order_attribution: wc, wp_has_consent: () => consentAPI},
   document: {cookie, addEventListener: (event, fn) => {assert.equal(event, 'slimstat_banner_consent'); listener = fn;}}};
  vm.createContext(scope);
  vm.runInContext(scripts[0], scope);
  assert.equal(wc.params.allowTracking, permitted && cookie === 'slimstat_gdpr_consent=accepted', 'no pre-consent initialization, including cached pages');
  wc.setOrderTracking = allowed => {actual = allowed;};
  vm.runInContext(scripts[1], scope);
  scope.document.cookie = 'slimstat_gdpr_consent=accepted'; listener();
  assert.equal(actual, permitted, 'banner acceptance cannot override another server filter');
  wc.setOrderTracking(false); assert.equal(actual, false, 'another integration can deny');
  consentAPI = false; listener(); assert.equal(actual, false, 'WP Consent API denial is preserved');
  consentAPI = true; scope.document.cookie = 'slimstat_gdpr_consent=denied'; listener();
  assert.equal(actual, false, 'withdrawal clears attribution');
  wc.setOrderTracking(true); assert.equal(actual, false, 'another integration cannot bypass the banner');
 }
}
console.log('PASS: Woo initialization, cached consent, grant/withdrawal and independent denial gates');
