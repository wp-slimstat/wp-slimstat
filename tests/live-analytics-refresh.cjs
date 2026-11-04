const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../admin/assets/js/live-analytics.js`, 'utf8');
const updates = [];
const element = {textContent: '2'};
const scope = {
  window: {slimstatUpdateAdminBar: data => updates.push(data)},
  document: {addEventListener() {}, getElementById: () => element, querySelector: () => element},
  localStorage: {getItem: () => String(Date.now() - 20 * 60000), setItem() {}},
  setInterval: () => 1, clearInterval() {}, setTimeout: () => 1, clearTimeout() {}, Date, Intl,
};
vm.createContext(scope);
vm.runInContext(source, scope);
const live = new scope.window.LiveAnalytics({chart_data: [4, 2, 0], auto_refresh: false, report_id: 'fixture'});
live.createChart = function () { this.chart = {data: {datasets: [{data: this.config.chart_data}]}, options: {scales: {y: {}}}, update() {}}; };
for (const method of ['addEventListeners', 'initMetricSwitching', 'initializeEmptyState', 'initLiveIndicatorRefresh']) live[method] = () => {};
live.init();
assert.deepEqual(Array.from(live.chart.data.datasets[0].data), [4, 2, 0], 'Returning after idle must preserve the server-provided history');
live.animateValue = () => {};
live.updateChart = () => {};
live.handleEmptyState = () => {};
live.updateUI({users_live: 3, active_users_per_minute: {data: [4, 2, 1], max_value: 4, peak_index: 0}});
assert.equal(updates.at(-1)?.online.count, 3, 'Live refresh must synchronize the admin-bar online count');
assert.deepEqual(Array.from(updates.at(-1).chart.data), [4, 2, 1], 'The user chart and admin-bar chart must use the same returned snapshot');
updates.length = 0;
live.currentMetric = 'pages';
live.updateUI({users_live: 3, active_users_per_minute: {data: [9, 8, 7]}});
assert.equal(updates.at(-1)?.online.count, 3, 'Changing the chart metric still synchronizes the visitor count');
assert.equal(updates.at(-1).chart, undefined, 'Page counts must never replace the admin-bar visitor chart');
console.log('PASS: initial history after idle and live/header snapshot synchronization');
