# SlimStat 6.0.1: human smoke test

Release PR: [#378](https://github.com/wp-slimstat/wp-slimstat/pull/378). Written 2026-11-02.

This is a short, hands-on check of the 6.0.1 update. It takes about 45 minutes. You do not need to read code. Tick each box as you go. If you see a 🚨 result, stop that section, take a screenshot, and report it on the PR.

- ✅ means the result we expect.
- 🚨 means a bug. Report it.

## 0. Before you start

- [ ] Use a **test or staging site**. Never use a live site for this.
- [ ] Back up the site's database first.
- [ ] The site should already run **SlimStat 6.0.0**. If you also have a site on 5.5.x, run sections 1 and 7 there too.
- [ ] Have two logins ready: an **Administrator** and a **Subscriber**.
- [ ] Turn on the WordPress debug log (`WP_DEBUG` and `WP_DEBUG_LOG` set to true). Note how many lines `wp-content/debug.log` has now.
- [ ] Get the zip files from the release owner. Install Free first: **Plugins → Add New → Upload Plugin**, then pick **Replace current with uploaded**.
  - Free: `wp-slimstat-6.0.1-beta-83812dcd.zip`, SHA-256 `65de63cc4c53769dbf887530e6a02891cadc35c72dc4c0be07adf912e02a1e0b`
  - Pro: `wp-slimstat-pro-3.0.1-beta-f48e478f.zip`, SHA-256 `c4b33759514fe3250f9bd9529f4ef071d63bbd0102465db2e06a99675bdf55b4`
  - Ask the release owner if you are unsure how to check a SHA-256. A different value means a different file. Do not test with it.
- [ ] If the site uses SlimStat Pro, install the Pro 3.0.1 zip **after** Free. Test both together.
- [ ] Right after installing, clear the server's PHP cache (opcache) or restart the site. A blank screen straight after an update is usually old cached code, not a bug.
- [ ] If the site has a page cache (a caching plugin or the host's cache), clear it after the update.

To create test visits: open the site in a **private/incognito window** and click through 4 or 5 pages. Use a different browser for a second visitor.

## 1. The update installs and the site stays up

- [ ] **Plugins** shows SlimStat **6.0.1**.
- [ ] The front page loads. ✅ Normal page. 🚨 White screen or "critical error".
- [ ] `/wp-login.php` loads and you can log in. 🚨 Any error on the login screen.
- [ ] The admin area shows no red error banner from SlimStat.

## 2. Visits are counted

This release sends the pageview sooner and makes the tracker safer when it loads at the top of the page.

- [ ] Make 4 or 5 page visits in a private window (see above).
- [ ] Open **SlimStat → Real-time** (or Access Log). ✅ Your visits appear within a minute. 🚨 No new visits.
- [ ] Click a link and submit a form (for example, the search box) in the private window. ✅ The click and the search show up once each. 🚨 They are missing or appear twice.
- [ ] Go to **SlimStat → Settings → General** and switch **Tracking mode** to **Server**. Save, make 2 more visits, and check they appear. Switch back to the original mode afterwards.
- [ ] **Online Users** shows your private-window visitor. ✅ Bots are not shown there.

## 3. Consent banner (privacy)

Turn on the consent banner for this section: **SlimStat → Settings → Tracker → GDPR compliance mode** on, using SlimStat's own banner.

- [ ] In a new private window, look at the banner **before** clicking anything. Make 2 visits. ✅ **No** new visit is recorded yet. 🚨 Visits recorded before you accept.
- [ ] Click **Accept**. Visit one more page. ✅ Now the visit is recorded.
- [ ] In another private window, click **Decline**. Make 2 visits. ✅ Nothing is recorded. 🚨 Any visit recorded after declining.
- [ ] After accepting or declining, the banner hides. Try typing in a form on the page and pressing **Tab** through the page. ✅ Forms and links work normally. 🚨 You cannot click or type into a form, or Tab jumps into an invisible banner.
- [ ] On a phone-sized window (narrow the browser), the banner does not cover forms or menus after it hides.
- [ ] Repeat the Accept test with **Settings → General → Tracking mode** set to **Server**. ✅ No visit before Accept; one visit after.
- [ ] Use the consent-withdrawal link or button (if your site shows one). ✅ It works, and new visits stop being recorded.
- [ ] Turn **GDPR compliance mode** off, save the Tracker settings, then turn it back on. ✅ Your earlier banner text and choices are still there. 🚨 They were reset.

## 4. Reports and charts

- [ ] Open the **WordPress Dashboard** (the main admin home page). ✅ It loads quickly. SlimStat widgets may show "loading" for a moment, then fill in. 🚨 The page hangs for many seconds, or widgets stay empty.
- [ ] Open each SlimStat report screen (Overview, Audience, Site Analysis, Traffic Sources). ✅ Each one loads with numbers and charts. 🚨 Blank boxes, error text, or a spinner that never stops.
- [ ] Scroll down a long report page. ✅ Boxes lower on the page load as you reach them.
- [ ] On a chart, change the time unit (Daily → Weekly → Monthly). ✅ The chart redraws. The numbers under the chart (the legend totals) still make sense. 🚨 The chart breaks, or the totals show 0 when there is data.
- [ ] Apply a filter (for example, one browser or one country), then change the chart's time unit. ✅ The filter is still applied to the chart. 🚨 The chart goes back to showing everything.
- [ ] Go to **SlimStat → Settings → Reports** and turn **Comparison chart** off (the "previous period" line). Reload a report. ✅ Charts show only the current period, and the legend has no "previous" numbers. Turn it back **on**. ✅ The previous-period line comes back.
- [ ] In a report with several pages of results, go to page 2 straight after the screen opens. ✅ You stay on page 2. 🚨 It jumps back to page 1.
- [ ] Open the date picker and pick a preset such as "Last 7 days". ✅ The highlighted preset matches the dates shown.

## 5. Saved filters

- [ ] Apply a filter, save it, then open it again from the saved list. ✅ The report shows the same filtered numbers.
- [ ] Delete the saved filter. ✅ It disappears from the list and other saved filters stay.
- [ ] If an old saved filter shows **without an Apply link**, that is expected (see "The dark side" below). ✅ You can still delete it.

## 6. Permissions

- [ ] Log in as the **Subscriber**. Try opening a SlimStat report URL directly (copy it from the Administrator's browser). ✅ Access is refused. 🚨 The Subscriber sees any SlimStat numbers.
- [ ] As the Subscriber, look at the Dashboard. ✅ No SlimStat widgets with data.

## 7. Migration screen

- [ ] Open **SlimStat → Migration** as the Administrator. ✅ It shows a clear status. Any database repair waits for you to click a button. 🚨 It changes the database without asking, or shows a PHP error.
- [ ] Dismiss a migration notice. ✅ Migration does **not** now say "complete" just because you dismissed it.
- [ ] If a repair button is offered, back up first, then click it once. ✅ It finishes with a message, and reports still load afterwards.

## 8. Extras (only if the site has them)

**Heatmaps (Pro):**
- [ ] Open a page's heatmap. ✅ The page title reads normally (no `&amp;` or similar codes). Clicks show on the right page.
- [ ] With the consent banner on, clicks made **before** accepting do not appear on the heatmap.

**WooCommerce:**
- [ ] With the consent banner on, open the shop in a private window. Before accepting, check the browser's cookies (Developer tools → Application → Cookies). ✅ No SlimStat/WooCommerce attribution cookie yet. Accept, then ✅ the cookie appears.
- [ ] Place a test order after arriving from a link with `?utm_source=test`. ✅ The order shows under that source in SlimStat's ecommerce report. Revenue matches the order total.
- [ ] Deactivate WooCommerce, then use **Tools → Export Personal Data** for a test customer. ✅ The export still works.

**Browser detection (Browscap):**
- [ ] If Browscap is turned on (**Settings → Tracker**), open that settings page. ✅ It shows its status. If it says the data is missing, the **retry** button works or explains what to fix.

## 9. Error log

- [ ] Open `wp-content/debug.log`. ✅ No new lines mentioning `wp-slimstat` with "Fatal", "Warning" or "Deprecated" since you started. 🚨 Any new SlimStat fatal error. Copy the lines into your report.

---

# ⚠️ The dark side of this release

## Things that will look like bugs but are intentional

- **Fewer visits from robots.** Headless browsers and speed-test tools (HeadlessChrome, PhantomJS, Lighthouse) now count as bots. Sites that run uptime or speed monitors may see lower totals than before. That is the fix.
- **Online Users can be lower.** It now leaves out bots.
- **Dashboard widgets load a moment later.** They fill in after the page opens instead of slowing the whole page down.
- **Boxes far down a report page load only when you scroll to them.**
- **Old broken saved filters have no Apply link.** They are kept so you can see and delete them, but they cannot run a half-filtered report.
- **Clicks in the first instant of a page load are not recorded.** Tracking starts when the page is ready.
- **Pages served from an old page cache** may use the previous tracker until the cache is cleared. They keep working; clear the cache to get the new behaviour.
- **Migration may show warnings it hid before.** It now says when it cannot check something, instead of saying "all done".
- **Page totals combine** only if the site owner turned on the optional URL clean-up setting.
- **The old add-on catalog page is gone.** Saved add-on licence settings are kept.
- **Pro stays off on an old Free.** If Free is older than 6.0.0, Pro 3.0.1 does not start and shows a notice saying "SlimStat Analytics Pro 3.0.0 requires SlimStat Analytics 6.0.0 or newer". The "3.0.0" in that notice is a known wording slip. The behaviour is correct: update Free and Pro starts.

## Things this release does NOT fix

- Clicks and form submits that happen **before** the page is ready are still not captured.
- Not every caching or speed-optimisation plugin (for example, Autoptimize) has been tested with the new tracker.
- Database repairs are never automatic. If Migration offers one, someone must click it.
- Very old sites with unusual database changes may still show Migration warnings that need a manual repair.

## Risks to check before merging this PR

- **Chart totals changed today.** After the last full QA run, the code that builds the chart legend numbers (current and previous period) was tidied into one shared place. Section 4's time-unit, filter and comparison-on/off checks cover this. Please do them carefully.
- **Ship Free before Pro.** Pro 3.0.1 ([wp-slimstat-pro#58](https://github.com/wp-slimstat/wp-slimstat-pro/pull/58)) expects Free 6.0.1. Test them together, and publish Free first.
- **Consent and privacy.** Section 3 is the most important. A visit recorded before Accept, or after Decline, is a privacy bug and blocks the release.
- **Cached sites.** Test once with a page cache on and once after clearing it. Report any visits that stop being counted.
- **Upgrading from 5.5.x.** The visitor-ID column is added only when an admin runs SlimStat → Migration. Until then, visits must still be recorded.
- **Not checked by people yet:** every hosting setup, every consent plugin other than SlimStat's own banner, and remote/external analytics databases. Automated tests cover parts of these.

## When this is published

Merging this PR does **not** publish the update. After merging, wait for the master CI run to finish green, then create the GitHub release `v6.0.1` on master. That release tag is what sends the update to WordPress.org.
