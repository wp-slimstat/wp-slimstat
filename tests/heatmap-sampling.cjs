const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const source = fs.readFileSync(`${__dirname}/../wp-slimstat.js`, 'utf8');
const fn = source.match(/    function heatmapSampled\(id, hm\) \{[\s\S]*?\n    \}/);
assert(fn);
const scope = {}; vm.runInNewContext(fn[0], scope);
const ids = [...Array(10000)].map((_, i) => i + 1).concat([2147483647, 4294967295, 1000000000000, Number.MAX_SAFE_INTEGER - 1, Number.MAX_SAFE_INTEGER]);
let longest = 0, gap = 0;
for (const id of ids.slice(0,10000)) { gap = scope.heatmapSampled(id, {r:2500}) ? 0 : gap+1; longest = Math.max(longest, gap); }
assert(longest < 10, `25% sampling must not leave ${longest} consecutive visits uncaptured`);
const php = `require $argv[1]; $ids=json_decode(stream_get_contents(STDIN),true); $result=[]; foreach ([0,1,100,500,1000,2500,5000,10000] as $rate) { $result[$rate]=array_map(static function($id) use($rate) { return \\SlimStat\\Heatmap\\Store::sampled($id,$rate,2); },$ids); } echo json_encode($result);`;
const server = JSON.parse(execFileSync('php', ['-r', php, `${__dirname}/../src/Heatmap/Store.php`], {input:JSON.stringify(ids), encoding:'utf8'}));
for (const rate of [0,1,100,500,1000,2500,5000,10000]) {
    const client = ids.map(id => scope.heatmapSampled(id, {r:rate}));
    assert.deepEqual(client, server[rate], `PHP/JS agreement rate=${rate}`);
    assert.equal(client.slice(0,10000).filter(Boolean).length, rate, `exact rate ${rate}`);
    if (rate === 2500 || rate === 5000) {
        let max=0, gap=0;
        for (const selected of client.slice(0,10000)) { gap=selected?0:gap+1; max=Math.max(max,gap); }
        assert(max<10, `samples spread over consecutive visits; rate=${rate}, gap=${max}`);
    }
}
console.log('PASS: eight exact rates, spread, and PHP/JS agreement through MAX_SAFE_INTEGER');
