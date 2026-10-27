import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('./load.js', import.meta.url), 'utf8')
  .replace(/^import .*;$/gm, '').replace('export default function', 'function iterate').replace(/export /g, '');
let posts = 0, jarSets = 0, reports = 0;
const jar = { cookiesForURL: () => ({wordpress_logged_in_test: ['session']}), set: () => jarSets++, clear: () => {} };
const response = {status: 200, body: '<form id="slimstat-filters-form">', timings: {duration: 1}};
const context = vm.createContext({
  __ENV: {SLIMSTAT_HOSTING_DISPOSABLE:'1', RUN_ID:'test', MODE:'admin', REPORT_FROM:'2026-08-01', REPORT_TO:'2026-08-31'},
  BASE_URL:'http://127.0.0.1:18827', __ITER:0, __VU:1,
  credentials: () => ({user:'admin',pass:'test'}),
  http: {get: url => { if(url.includes('admin.php')) reports++; return response; }, post: () => {posts++;return response;}, cookieJar: () => jar},
  Rate: class {add(){}}, Trend: class {add(){}}, check: () => {}, sleep: () => {}, fail: msg => {throw Error(msg);}
});
vm.runInContext(source, context);
assert.equal(typeof context.setup, 'function', 'Authenticate before measured iterations');
const data = context.setup();
context.iterate(data);context.iterate(data);
assert.equal(posts, 1);assert.equal(jarSets, 1);assert.equal(reports, 2);
context.http.post = () => ({status:200,body:'<form id="loginform">'});
assert.throws(() => context.setup(), /login failed/i);
console.log('PASS: authenticate once outside measurements, import session, reject failed authentication');

const visited = [];
context.http.get = url => { visited.push(url); return response; };
for (let vu = 1; vu <= 6; vu++) { context.__VU = vu; context.iterate(data); }
assert.equal(new Set(visited.map(url => new URL(url).searchParams.get('page'))).size, 6,
  'The first six VUs must cover every report even when later iterations are slow');
for (const mode of ['tracking', 'admin', 'concurrency']) {
  const isolated = vm.createContext({...context, __ENV: {...context.__ENV, MODE: mode}});
  const config = vm.runInContext(source + '; options;', isolated);
  assert.equal(config.thresholds.http_503[0], 'rate==0');
  assert.equal(config.thresholds.operation_failed[0], 'rate<0.001');
  if (mode === 'concurrency') assert.equal(config.thresholds.operation_ms, undefined,
    'The documented concurrency budget is zero503; the200ms budget belongs to50RPS tracking');
  else assert.equal(config.thresholds.operation_ms[0], mode === 'admin' ? 'p(95)<500' : 'p(95)<200');
}
console.log('PASS: complete initial report mix and original scenario-specific budgets');
