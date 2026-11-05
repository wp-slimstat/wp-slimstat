/** Manual final audit and PR evidence. Requires explicitly configured disposable QA credentials. */
const { chromium } = require('playwright');
const AxeBuilder = require(process.env.FORMS_QA_AXE).default;
const fs = require('fs');
const path = require('path');
const fixture = JSON.parse(fs.readFileSync(process.env.FORMS_QA_FIXTURE,'utf8'));
const output = process.env.FORMS_QA_SCREENSHOTS;
if(!output || !process.env.FORMS_QA_PASSWORD_FILE) throw new Error('Provide the private QA password file and screenshot directory.');
(async()=>{
  fs.mkdirSync(output,{recursive:true});
  const browser=await chromium.launch();
  const errors=[];const findings=[];
  try {
    const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
    const page=await context.newPage();
    page.on('pageerror',error=>errors.push(error.message));
    const base=process.env.FORMS_QA_URL||'http://127.0.0.1:9434';
    await page.goto(base+'/wp-login.php');
    await page.waitForFunction(()=>document.activeElement.id==='user_login');
    await page.locator('#user_login').fill('slimformsqa');
    await page.locator('#user_pass').fill(fs.readFileSync(process.env.FORMS_QA_PASSWORD_FILE,'utf8').trim());
    await page.locator('#wp-submit').click();await page.waitForURL(/\/wp-admin\//);
    for(const [name,query] of [['overview',''],['detail','&form=cf7%3A'+fixture.form],['setup','&tab=setup']]) {
      await page.goto(base+'/wp-admin/admin.php?page=slimforms'+query);
      await page.locator('.ss-forms-content').waitFor();
      const result=await new AxeBuilder({page}).include('.ss-forms').include('#slimstat-filters-form').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
      findings.push({name,violations:result.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:result.incomplete.map(v=>v.id)});
      await page.screenshot({path:path.join(output,name+'-desktop.png'),fullPage:true});
      if(name==='detail') {
        const premium=page.locator('[data-forms-premium] details').first();
        if(await premium.count()){await premium.locator('summary').click();await page.locator('[data-forms-premium]').screenshot({path:path.join(output,'premium-fields.png')});}
      }
      await page.setViewportSize({width:390,height:844});
      findings.push({name:name+'-mobile',overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)});
      await page.screenshot({path:path.join(output,name+'-mobile.png'),fullPage:true});
      await page.setViewportSize({width:1440,height:1000});
    }
    fs.writeFileSync(path.join(output,'audit.json'),JSON.stringify({errors,findings},null,2)+'\n');
    console.log(JSON.stringify({errors,findings}));
    if(errors.length||findings.some(f=>f.overflow||f.violations?.length)) process.exitCode=1;
  } finally {await browser.close();}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
