// Heatmap capture in the built tracker: what a click and a scroll put on the wire.
// Standalone page, no WordPress and no database: run with `npm run test:heatmap-capture`.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('@playwright/test');

const bundle = fs.readFileSync(`${__dirname}/../wp-slimstat.min.js`, 'utf8');
const page = (hm) => `<!doctype html><html><head><meta name="viewport" content="width=device-width">
<script>var SlimStatParams = ${JSON.stringify({
    id: '5.0123456789abcdef', ajaxurl: 'http://capture.test/hit', transport: 'ajax', gdpr_enabled: 'off',
    ...(hm ? { hm } : {}),
})};</script></head>
<body style="margin:0;height:3000px">
<nav><a id="buy" class="btn is-active" href="#x" style="display:inline-block;width:200px;height:40px">Buy   now</a></nav>
<div class="hero"><p style="width:400px;height:100px">Plain text the visitor reads</p></div>
<input id="email" value="secret@example.com" style="width:200px;height:30px">
<div class="noslimstat"><button style="width:100px;height:30px">Private</button></div>
<script>${bundle}</script></body></html>`;

async function defaultClicks(tab) {
    await tab.click('#buy', { position: { x: 50, y: 10 } });
    await tab.click('input#email');
    await tab.click('.noslimstat button');
    for (let i = 0; i < 3; i++) await tab.click('.hero p', { position: { x: 20, y: 20 } });
    await tab.mouse.wheel(0, 1200);
}

async function capture(browser, hm, act = defaultClicks) {
    const tab = await browser.newPage({ viewport: { width: 1280, height: 720 } });
    await tab.route('**/*', (route) => route.abort());
    await tab.setContent(page(hm));
    await tab.evaluate(() => {
        window.__sent = [];
        window.SlimStat.send_to_server = (payload) => window.__sent.push(payload);
    });
    await act(tab);
    await tab.waitForTimeout(100);
    await tab.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    await tab.waitForTimeout(200);
    const sent = await tab.evaluate(() => window.__sent);
    await tab.close();
    return sent.filter((p) => p.includes('&hm=')).map((p) => ({
        payload: p,
        hm: JSON.parse(decodeURIComponent(p.slice(p.indexOf('&hm=') + 4))),
    }));
}

(async () => {
    const browser = await chromium.launch();
    try {
        const full = await capture(browser, { l: 'full', r: 10000 });
        assert.equal(full.length, 1, 'one batch, riding on the finalize request');
        assert.match(full[0].payload, /^action=slimtrack&id=5\.0123456789abcdef&fv=pagehide&hm=/);
        const { hm } = full[0];
        assert.deepEqual([hm.v, hm.vw, hm.vh], [1, 1280, 720]);
        assert.deepEqual(hm.s[0], ['#buy', 'Buy now'], 'stable id selector; interactive label, whitespace collapsed');
        assert.deepEqual(hm.s[1], ['#email', ''], 'never an input value');
        assert.deepEqual(hm.s[2], ['div.hero:nth-of-type(1) > p', ''], 'no label from plain text; position among sibling divs');
        assert(!JSON.stringify(hm).includes('secret@example.com') && !JSON.stringify(hm).includes('Private'));
        assert.equal(hm.r.length, 5, '.noslimstat click skipped');
        assert.deepEqual(hm.r[0].slice(0, 5), [0, 1, 0, 2500, 2500], 'seq, interactive, selector 0, offset inside element x10000');
        assert.deepEqual(hm.r.map((r) => r[1]), [1, 1, 2, 2, 6], 'dead clicks on plain text; third quick click is rage');
        assert.equal(hm.sc[1], 3000, 'document height');
        assert(hm.sc[0] >= 1900, `scroll depth reaches the bottom of the scrolled viewport (${hm.sc[0]})`);
        console.log('PASS: full level sends element-relative clicks, flags and scroll on the finalize request');

        const main = await capture(browser, { l: 'main', r: 10000 });
        assert.equal(main.length, 1);
        assert.deepEqual(main[0].hm.r.map((r) => r[1]), [1, 1], 'main level: interactive clicks only');
        assert.equal(main[0].hm.sc, undefined, 'main level: no scroll row');
        console.log('PASS: main level keeps interactive clicks, no scroll');

        const busy = await capture(browser, { l: 'main', r: 10000 }, async (tab) => {
            for (let i = 0; i < 25; i++) await tab.click('#buy', { position: { x: 4 + i * 7, y: 10 } });
        });
        // Batch edges move when the tracker's stale-id recovery blinks the id (it does here, the
        // network is aborted); what must hold is every click once, under one unbroken seq.
        assert(busy.length >= 2 && busy.every((b) => b.hm.r.length <= 20), 'flushes before 20 rows pile up');
        assert(busy[busy.length - 1].payload.includes('&fv='), 'the rest rides on finalize');
        assert.deepEqual(busy.flatMap((b) => b.hm.r.map((r) => r[0])), [...Array(25).keys()], 'seq 0..24, none reused');
        console.log('PASS: a busy page flushes every 20 clicks');

        assert.deepEqual(await capture(browser, { l: 'full', r: 5 }), [], 'id 5 is outside a 5/10000 sample');
        assert.deepEqual(await capture(browser, null), [], 'no hm param, no capture');
        console.log('PASS: sampled-out and capture-off pages send nothing');
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
