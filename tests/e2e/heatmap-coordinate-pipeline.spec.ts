import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { WP_ROOT, BASE_URL } from './helpers/env';
import { requireProBooted } from './helpers/pro-state';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, closeDb, getPool } from './helpers/setup';
import { runWordPressFixture } from './helpers/chart';

function recordPoints() {
  let library:any;
  Object.defineProperty(window,'h337',{configurable:true,get:()=>library,set:value=>{
    library=value; const create=library.create;
    library.create=function(...args:any[]){const map=create.apply(this,args);const set=map.setData;
      map.setData=function(data:any){(window as any).__heatmapPoints=data.data;return set.call(this,data);};return map;};
  }});
}

const fixture=path.join(WP_ROOT,'wp-content/mu-plugins/oct24-coordinates.php');
const resource='/qa-oct24-coordinates/';
test.afterAll(closeDb);

test('real clicks reach storage and both overlays within two CSS pixels at each viewport', async ({page,browser},info)=>{
  test.setTimeout(180000);
  await requireProBooted(page);
  await snapshotSlimstatOptions();
  await page.addInitScript(recordPoints);
  fs.writeFileSync(fixture,`<?php
add_action('template_redirect', static function(){
 if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='${resource}') return;
 status_header(200); ?><!doctype html><html><head><meta name="viewport" content="width=device-width"><?php wp_head(); ?>
 <style>html{margin:0!important}body{margin:0;height:3000px;width:calc(100vw + 300px);position:relative}button{position:absolute;left:100px;width:200px;height:100px;padding:0;border:0}#qa-top{top:100px}#qa-middle{top:1200px}#qa-bottom{top:2400px}</style></head><body>
 <button id="qa-top">Top</button><button id="qa-middle">Middle</button><button id="qa-bottom">Bottom</button>
 <?php wp_footer(); ?></body></html><?php exit;
});`);
  try {
    await setSlimstatOptions({is_tracking:'on',javascript_mode:'on',ignore_wp_users:'off',ignore_capabilities:'',gdpr_enabled:'off',anonymous_tracking:'off',do_not_track:'off',heatmap_capture:'full',heatmap_rate:10000,heatmap_pages:resource,auto_purge:0});
    runWordPressFixture(`<?php \\SlimStat\\Heatmap\\Store::setup(); \\SlimStat\\Heatmap\\Store::invalidate();`);
    for(const [width,height,dpr,device] of [[1280,720,1,'desktop'],[820,1180,2,'tablet'],[390,844,3,'mobile']] as const) {
      const context=await browser.newContext({viewport:{width,height},deviceScaleFactor:dpr});
      const visitor=await context.newPage();
      await visitor.goto(BASE_URL+resource);
      await expect.poll(()=>visitor.evaluate(()=>(window as any).SlimStatParams?.id||'')).toMatch(/^\d+\./);
      const id=await visitor.evaluate(()=>parseInt((window as any).SlimStatParams.id));
      const expected=[{x:121,y:131},{x:180,y:1250},{x:250,y:2480},{x:125,y:1800}];
      for(const [target,position] of [['#qa-top',{x:20,y:30}],['#qa-middle',{x:80,y:50}],['#qa-bottom',{x:150,y:80}]] as const) {
        await visitor.locator(target).click({position});
        if (target==='#qa-top') await visitor.locator(target).click({position:{x:22,y:32}});
      }
      await visitor.evaluate(()=>scrollTo(100,1600));
      await visitor.mouse.click(25,200);
      await visitor.evaluate(()=>window.dispatchEvent(new Event('pagehide')));
      await expect.poll(async()=>{const [rows]=await getPool().query('SELECT COUNT(*) n FROM wp_slim_heatmap WHERE id=? AND kind=0',[id]);return (rows as any)[0].n;}).toBe(5);
      const [stored]=await getPool().query('SELECT x,y,vw,vh FROM wp_slim_heatmap WHERE id=? AND kind=0 ORDER BY seq',[id]);
      expect(stored).toEqual([{x:120,y:130},{x:122,y:132},...expected.slice(1)].map(p=>({...p,vw:width,vh:height})));
      await context.close();
      await page.goto('/wp-admin/admin.php?page=slimheatmap&heatmap='+encodeURIComponent(resource)+'&device='+device);
      const frame=page.frameLocator('.ss-hmv-frame iframe');
      await expect(frame.locator('.ss-hmo')).toHaveCount(1);
      // h337's public renderer data is the point-centre oracle, independent of its blur radius.
      await expect.poll(()=>frame.locator('.ss-hmo canvas').count()).toBe(1);
      await expect.poll(()=>frame.locator('body').evaluate(()=>(window as any).__heatmapPoints?.length)).toBe(4);
      const drawn=await frame.locator('body').evaluate(()=>(window as any).__heatmapPoints);
      for(const point of expected) expect(drawn.some((p:any)=>Math.abs(p.x-point.x)<=2&&Math.abs(p.y-point.y)<=2)).toBe(true);
      const at=await frame.locator('.ss-hmo-hit').evaluateAll(nodes=>nodes.map(n=>({x:parseFloat((n as HTMLElement).style.left),y:parseFloat((n as HTMLElement).style.top)})));
      // Blank-space clicks have no label; inspect the same rendered point data via a message on redraw below.
      expect(at).toEqual(expect.arrayContaining(expected.slice(0,3)));
      const data=await page.evaluate(async()=>{const c=(window as any).SlimStatHeatmapViewer;return (window as any).wp.apiFetch({path:c.route+'?page='+encodeURIComponent('/qa-oct24-coordinates/')+'&device='+new URLSearchParams(location.search).get('device')});});
      expect(data.clicks.bins.reduce((n:number,b:any)=>n+b.n,0)).toBe(5);
      // The two top clicks share a relative-position bin: arithmetic centroid, weight two.
      expect(data.clicks.bins.find((b:any)=>b.s==='#qa-top')).toMatchObject({x:121,y:131,n:2});
      expect(drawn.find((p:any)=>p.x===121)).toMatchObject({y:131,value:2});
      expect(data.clicks.legacy).toEqual([]);
      for(const point of expected) expect(data.clicks.bins.some((b:any)=>Math.abs(b.x-point.x)<=2&&Math.abs(b.y-point.y)<=2)).toBe(true);
      await page.locator('.ss-hmv-stage').screenshot({path:info.outputPath(`embedded-${device}.png`)});
      const standalone=await browser.newContext({viewport:{width,height},deviceScaleFactor:dpr,storageState:await page.context().storageState()});
      await standalone.addInitScript(recordPoints);
      const onpage=await standalone.newPage();
      const url=new URL(data.preview);url.searchParams.set('slimstat_heatmap_view',new URLSearchParams({page:resource,device,days:'30',show:'clicks',filter:'all'}).toString());
      await onpage.goto(url.href);
      await expect(onpage.locator('.ss-hmo')).toHaveCount(1);
      // Standalone and embedded share the draw function; labelled hit centres stay identical.
      await expect.poll(()=>onpage.locator('.ss-hmo canvas').count()).toBe(1);
      await expect.poll(()=>onpage.evaluate(()=>(window as any).__heatmapPoints?.length)).toBe(4);
      const standalonePoints=await onpage.evaluate(()=>(window as any).__heatmapPoints);
      expect(standalonePoints).toEqual(drawn.map((p:any)=>({...p})));
      await onpage.locator('#qa-middle').scrollIntoViewIfNeeded();
      await onpage.screenshot({path:info.outputPath(`onpage-${device}.png`)});
      await standalone.close();
    }
  } finally {
    fs.rmSync(fixture,{force:true});
    await getPool().query('DELETE FROM wp_slim_stats WHERE resource=?',[resource]);
    await restoreSlimstatOptions();
  }
});
