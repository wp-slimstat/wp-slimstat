const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function load(file, overrides = {}) {
  const context = vm.createContext({
    BASE_URL: 'https://example.test/ab~user', __ENV: {},
    console: { log() {} },
    Trend: function () { this.add = () => {}; }, Rate: function () { this.add = () => {}; }, Counter: function () { this.add = () => {}; },
    encoding: { b64encode: (value, alphabet) => Buffer.from(value).toString(alphabet === 'rawurl' ? 'base64url' : 'base64') },
    check: (response, checks) => Object.values(checks).every(check => check(response)),
    ...overrides,
  });
  const source = fs.readFileSync(path.join(__dirname, 'perf', file), 'utf8')
    .replace(/^import .*;$/gm, '')
    .replace('export default function (', 'function run(')
    .replace(/export /g, '');
  vm.runInContext(source, context);
  return context;
}

const tracker = load('issue-303-browscap-fileinfo.js');
const payload = JSON.parse(vm.runInContext('buildPayload(7)', tracker));
const standard = payload.res.replace(/\./g, '+').replace(/_/g, '/').replace(/-/g, '=');
assert.equal(Buffer.from(standard, 'base64').toString(), 'https://example.test/ab~user/perf-303-baseline-7');
assert.match(payload.res, /^[A-Za-z0-9._-]+$/);

const requested = [];
let posts = [{ id: 17 }];
let pages = [{ id: 23 }];
const returning = load('returning-visitor-load.js', {
  http: { get(url) {
    requested.push(url);
    return {
      status: 200, body: 'slimstat '.repeat(100),
      json: () => url.includes('/wp/v2/posts') ? posts : pages,
    };
  } },
});
const discovered = () => JSON.parse(JSON.stringify(vm.runInContext('setup().pages', returning)));
assert.deepEqual(discovered(), ['/', '/?p=17', '/?page_id=23']);
assert.ok(requested.slice(1).every(url => url.includes('status=publish')));
posts = []; pages = [];
assert.deepEqual(discovered(), ['/'], 'An empty site can still test the front page.');
pages = [{ id: -1 }];
assert.throws(discovered, /Invalid published pages ID/);
pages = { code: 'rest_forbidden' };
assert.throws(discovered, /Cannot discover published pages/);
const agents = [];
let clears = 0;
const visitor = load('returning-visitor-load.js', {
  __VU: 1, __ITER: 0, sleep() {},
  http: {
    cookieJar: () => ({ clear() { clears++; }, cookiesForURL: () => ({ slimstat_tracking_code: ['17.checksum'] }) }),
    get(url, options) {
      agents.push(options.headers['User-Agent']);
      return { status: 200, body: 'slimstat '.repeat(100), timings: { duration: 10 } };
    },
  },
});
vm.runInContext('Math.random = () => 0.9; run({pages: ["/"]}); __ITER++; run({pages: ["/"]});', visitor);
assert.equal(new Set(agents).size, 1, 'Returning visits retain the same identity.');
assert.equal(clears, 1, 'A VU starts as a new visitor even when random selects returning.');
vm.runInContext('Math.random = () => 0.1; __ITER++; run({pages: ["/"]});', visitor);
assert.equal(new Set(agents).size, 2, 'A new visitor cannot reuse the old cookieless identity.');
assert.equal(clears, 2);
console.log('PASS: tracker alphabet, published page discovery and distinct load-test visitors');
