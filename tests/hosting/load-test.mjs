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
