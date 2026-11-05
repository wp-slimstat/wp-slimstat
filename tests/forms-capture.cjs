/** Structural behavior, consent and browser event contracts. @license GPL-2.0-or-later */
const {chromium}=require('playwright');
const fs=require('fs');
const assert=require('node:assert/strict');
const source=fs.readFileSync(require('path').join(__dirname,'../wp-slimstat-forms.js'),'utf8');
(async()=>{
 const browser=await chromium.launch();
 try {
  const page=await browser.newPage({viewport:{width:1000,height:700}});
  await page.setContent('<div id="mount"><form><input type="hidden" name="_slimstat_form" data-slimstat-form="cf7:1" value="cf7:1.signature"><input style="display:none" value="PRIVATE_HONEYPOT"><input id="name" value="PRIVATE_NAME"><input id="password" type="password" value="PRIVATE_PASSWORD"><input id="card" autocomplete="section-billing cc-number" value="PRIVATE_CARD"><input required id="required"><button>Send</button></form></div>');
  await page.evaluate(()=>{window.payloads=[];window.allow=false;window.SlimStatParams={id:'1.signature'};window.SlimStat={consent:{checkAllowed:()=>({allowed:window.allow,mode:window.allow?'full':'blocked'})},send_to_server:(payload)=>{window.payloads.push(payload);return true;}};});
  await page.addScriptTag({content:source});
  if (process.env.FORMS_QA_PRO_SCRIPT) await page.addScriptTag({content:fs.readFileSync(process.env.FORMS_QA_PRO_SCRIPT,'utf8')});
  await page.locator('#name').fill('PRIVATE_CHANGED');
  await page.waitForTimeout(300);
  assert.equal((await page.evaluate(()=>window.payloads)).length,0,'No collection before consent');
  assert.equal(await page.locator('[name=_slimstat_context]').count(),0,'No page context before consent');
  await page.evaluate(()=>{window.allow=true;document.dispatchEvent(new Event('slimstat:consent:updated'));});
  await page.waitForTimeout(300);
  let payloads=await page.evaluate(()=>window.payloads);
  const batch=p=>JSON.parse(new URLSearchParams(p).get('fa'));
  assert.equal(batch(payloads[0]).forms[0].flags,3,'Consent starts a fresh visible cohort, excluding earlier input');
  await page.locator('#password').focus();
  await page.locator('#card').focus();
  await page.waitForTimeout(300);
  assert.equal((await page.evaluate(()=>window.payloads)).length,1,'Sensitive controls do not start form analytics');
  await page.locator('#name').fill('PRIVATE_SENTINEL');
  await page.waitForTimeout(300);
  payloads=await page.evaluate(()=>window.payloads);
  assert.equal(batch(payloads.at(-1)).forms[0].flags,7,'Normal input starts a form');
  await page.locator('button').click();
  await page.waitForTimeout(300);
  payloads=await page.evaluate(()=>window.payloads);
  assert.equal(batch(payloads.at(-1)).forms[0].flags,15,'Native validation failure is observed');
  assert(!payloads.some(p=>/PRIVATE|password|cc-number/.test(p)),'Only structural flags leave the browser');
  assert(payloads.every(p=>batch(p).forms.every(f=>f.flags<16)),'Browser cannot claim successful submissions or spam');
  if (process.env.FORMS_QA_PRO_SCRIPT) {
   const fields=batch(payloads.at(-1)).forms[0].fields;
   assert.equal(fields[0],'11','Sensitive and hidden controls are excluded from field layout');
   assert(fields[3].some(row=>row[0]===0 && (row[2]&1)), 'Interacted field is observed without its value');
   assert(fields[3].some(row=>row[0]===1 && (row[2]&2)), 'Native field validation is structural');
  }
  await page.evaluate(()=>{window.allow=false;document.dispatchEvent(new Event('slimstat:consent:updated'));});
  assert.equal(await page.locator('[name=_slimstat_context]').count(),0,'Revocation removes signed context');
  const count=payloads.length;
  await page.locator('#name').fill('PRIVATE_AFTER_REVOKE');
  await page.waitForTimeout(300);
  assert.equal((await page.evaluate(()=>window.payloads)).length,count,'No requests after revocation');
  console.log('PASS: consent, revocation, visibility, starts, native errors, sensitive controls, value minimization and success authority');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
