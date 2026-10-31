// Execute the shipped loader with controlled visibility and request completion.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../admin/assets/js/admin.js', 'utf8');
const start = source.indexOf('// Asynchronous reports are loaded dynamically');
const end = source.indexOf('// ----- END: DATA REFRESH', start);
assert(start >= 0 && end > start, 'real loader must be found');
const loader = source.slice(start, end);

function run(observerSupported, enabled = true) {
    const widgets = Array.from({ length: 9 }, (_, i) => ({ id: 'slim_p1_' + i, chart: i === 8 }));
    const started = [], pending = [], observed = new Set();
    let visibility, maximum = 0, active = 0;
    function jQuery(target) {
        if (typeof target === 'string') return { each: fn => widgets.forEach(w => fn.call(w)) };
        return { attr: () => target.id, find: () => ({ length: target.chart ? 1 : 0 }) };
    }
    jQuery.when = value => value && value.always ? value : { then: fn => fn() };
    class Observer {
        constructor(fn, options) { visibility = fn; assert.equal(options.rootMargin, '200px'); }
        observe(w) { observed.add(w); }
        unobserve(w) { observed.delete(w); }
    }
    const context = {
        jQuery,
        window: observerSupported ? { IntersectionObserver: Observer } : {},
        SlimStatAdminParams: { async_load: enabled ? 'on' : 'no' },
        SlimStatReportBody: () => ({ html: () => {} }),
        SlimStatAdmin: { refresh_report: id => () => {
            started.push(id);
            active++;
            maximum = Math.max(maximum, active);
            return {
                always: fn => { pending.push(() => { active--; fn(); }); },
                then: function () { return this; },
            };
        } },
    };
    vm.runInNewContext(loader, context);
    if (!enabled) { assert.equal(started.length, 0); return; }
    if (observerSupported) {
        assert.equal(started.length, 0, 'offscreen widgets wait for visibility');
        assert.equal(observed.size, 8, 'charts retain their own loader');
        visibility(widgets.slice(0, 5).map(target => ({ target, isIntersecting: true })));
        assert.equal(started.length, 3, 'fill three slots without waiting for serial completion');
        assert.equal(observed.size, 3, 'queued widgets are observed only once');
        while (pending.length) pending.shift()();
        assert.equal(started.length, 5, 'all nearby widgets complete');
        visibility(widgets.slice(5, 8).map(target => ({ target, isIntersecting: true })));
    } else {
        assert.equal(started.length, 3, 'fallback also limits concurrency');
    }
    // Completion/failure both release a slot through always().
    while (pending.length) pending.pop()();
    assert.equal(started.length, 8);
    assert.equal(new Set(started).size, 8);
    assert.equal(maximum, 3);
    assert.equal(active, 0);
}
run(true);
run(false);
run(true, false);
console.log('PASS: visibility, three request slots, completion/failure refill, chart exclusion and async preference');
