# Video tutorials: catalog format and publishing guide

SlimStat → Videos (free plugin) lists tutorials from one static JSON file on wp-slimstat.com.
Publishing, editing, reordering or removing a video is a change to that file. It never needs a
plugin release.

```
wp-slimstat.com/videos/videos.json        static file, CORS-readable
        │  fetched by the admin user's browser, after they opt in, at most once a day,
        │  credentials: 'omit', referrerPolicy: 'no-referrer', no headers, no parameters
        ▼
localStorage (per browser)                saved catalog + per-site, per-user state
        │
        ├── SlimStat → Videos             one row per category, thumbnails, titles
        │        │ click
        │        ▼
        │   wp-slimstat.com/videos/{slug}/   new tab; the website embeds and plays the video
        │
        └── every other admin page         ▶ before "SlimStat" while new videos are unseen
```

The WordPress server never requests the catalog, stores nothing for this feature (no options,
user meta, transients, cron or tables), and loads nothing on the site's front end.

Files in this folder:

| File | What it is |
|---|---|
| `videos.schema.json` | JSON Schema (draft 2020-12) for the catalog |
| `videos.example.json` | A valid catalog built from the videos already on the website. The plugin's test suite loads it, so it cannot drift from what the plugin accepts |

## What the website has to provide

1. **`/videos/videos.json`**, matching the schema. In this repository's sibling project
   (`astro.wp-slimstat.com`), the simplest way is a generated route, for example
   `src/pages/videos/videos.json.ts`, that maps `src/data/videos.ts` to this format. That keeps
   one source of truth: `videos.ts` would gain `category` and `order`, and `id` can be the
   existing object key (`overview`, `heatmaps`, …).
2. **Response headers** for that file:

   ```
   /videos/videos.json
     Access-Control-Allow-Origin: *
     Cache-Control: public, max-age=3600
   ```

   `Access-Control-Allow-Origin: *` is required: without it the browser refuses the response
   and the Videos page shows its error state with a link to the website. Do not add
   `Access-Control-Allow-Credentials`; the plugin never sends credentials. Cloudflare Pages adds
   an `ETag`. The daily check always revalidates against it (`cache: 'no-cache'`), so an
   unchanged file costs a 304 and any `max-age` adds no delay for the plugin.
   If you want the ETag readable from script, `Access-Control-Expose-Headers: ETag` is
   harmless, but the plugin does not need it.
3. **A watch page per video at `/videos/{slug}/`** (it already exists). The plugin opens it in a
   new tab with `rel="noopener noreferrer"`, so the website sees no referrer and cannot reach
   back into the admin. Autoplay is the website's call, within browser autoplay rules and the
   visitor's consent.
4. **Thumbnails on wp-slimstat.com**, e.g. the existing `/images/videos/*.webp` posters. Any
   other host (including `i.ytimg.com`) is replaced by a placeholder, so the admin never makes a
   request to YouTube.

## Fields

| Field | Required | Rules |
|---|---|---|
| `version` | yes | Exactly the number `1`. Any other value rejects the whole file. |
| `videos[].id` | yes | `^[a-z0-9][a-z0-9_-]{0,63}$`, unique, **permanent**. The new-video marker tracks ids. |
| `videos[].title` | yes | Plain text, up to 200 characters (longer is cut). Markup is shown literally. |
| `videos[].slug` | yes | `^[a-z0-9]+(?:-[a-z0-9]+)*$`, up to 100. The link is `https://wp-slimstat.com/videos/{slug}/`. |
| `videos[].youtubeId` | yes | 11 characters, `[A-Za-z0-9_-]`. Used by the website's player; a video without one is skipped. |
| `videos[].published` | yes | `2026-11-03` or `2026-11-03T09:05:58+00:00` (a date-time needs `Z` or an offset). |
| `videos[].thumbnail` | no | `https://wp-slimstat.com/...` or `/images/...`. Otherwise a placeholder is shown. |
| `videos[].category` | no | A category id. Unknown or missing ids go to the last row, "More videos". |
| `videos[].order` | no | Integer; lowest first within the row. Default 1000. Ties: newest first, then title. |
| `videos[].duration` | no | `m:ss` or `h:mm:ss`, shown on the thumbnail. |
| `videos[].description` | no | Plain text, up to 500 characters, two lines under the title. |
| `categories[]` | no | `{ id, title, order }`. Reorders rows and adds new ones (see below). |

Validation is per entry: a malformed video is skipped and the rest still show. Unknown fields are
ignored, so new optional fields can be added at any time. A change that would break an existing
field's meaning needs a new file (`videos.v2.json`) and a plugin release that reads it; never
change `version` in place, or every installed plugin stops listing videos.

## Categories

The plugin ships these ids with translated labels, in this default order:

| id | Label | Default order |
|---|---|---|
| `getting-started` | Getting started | 10 |
| `dashboard-reports` | Dashboard and reports | 20 |
| `real-time` | Real-time | 30 |
| `woocommerce` | WooCommerce | 40 |
| `heatmaps` | Heatmaps | 50 |
| `goals-funnels` | Goals and funnels | 60 |
| `advanced` | Advanced features | 70 |
| `pro` | SlimStat Pro | 80 |

- An `order` in `categories[]` overrides the default, so rows can be reordered without a release.
- A built-in id always shows the plugin's translated label; its `title` in the file is ignored.
- A new id needs a `title`, which is shown as is (it is not translated). Without a title its
  videos go to "More videos".
- Empty categories are not shown.

## Publishing a video

1. Publish it on YouTube and give it a watch page at `/videos/{slug}/` with a poster under
   `/images/videos/`, as for any website video.
2. Add an entry to `videos.json` with a **new, never-used `id`** and the real publication date.
3. Validate the file before deploying, e.g.
   `python3 -c "import json,jsonschema; jsonschema.validate(json.load(open('videos.json')), json.load(open('videos.schema.json')))"`.
4. Deploy. Browsers pick it up within a day: each one keeps its copy for 24 hours, then
   revalidates with the website.

**Editing** a title, thumbnail, description, category or order: change it in place, keep the
`id`. It is not announced as new.

**Removing**: delete the entry. It disappears from the page on each browser's next refresh.

## The new-video marker

On every admin page, a user who turned on "Mark the SlimStat menu with ▶ when new videos are
published" sees `▶ SlimStat` in the sidebar, and a "New" pill on Videos, while there are new
videos. A video is new for that user when:

- its `id` is not among the ids the Videos page last showed them, **and**
- its `published` date is no earlier than a week before their baseline.

The baseline is taken the first time the catalog loads for that user (on the Videos page, or on
the first check after they opt in): everything in the catalog at that moment counts as seen, so
turning the marker on never announces the existing back catalogue. The week of grace covers a
video added to the file a few days after its publication date. The marker clears once the Videos
page has loaded and shown the new videos, and the Videos page flags them with "New" for that
visit.

Checks happen in the background on an admin page that shows the menu, at most once a day,
including after a failed attempt; a failure keeps the saved list and is retried the next day.
Offline, nothing is requested.

## Privacy

- Nothing is requested until the user chooses **Show videos**, or turns on the marker.
- Requests go from the user's browser straight to wp-slimstat.com, with no cookies, no
  referrer, no custom headers and no query string. No site, analytics or account data is sent.
- The choices and the list of seen videos are stored in that browser's `localStorage`, keyed
  by a hash of the site address and user ID. "Stop loading videos in this browser" deletes them.
- The readme's "Please note" section discloses the service and links the privacy policy.

## Turning the feature off for a site

Remove the screen with the existing filter; the marker script stops loading with it:

```php
add_filter('slimstat_screens_info', function ($screens) {
    unset($screens['slimvideos']);
    return $screens;
});
```

## Where it lives in the plugin

| Path | Role |
|---|---|
| `src/Services/Admin/VideoTutorials.php` | Catalog URL, built-in categories, enqueues |
| `admin/view/videos.php` | The page: every state, server-rendered and translated |
| `admin/assets/js/video-catalog.js` | Fetch, cache, validation, new-video detection, menu marker |
| `admin/assets/js/videos.js` / `admin/assets/css/videos.css` | The page renderer and its styles (Videos screen only) |
| `tests/video-catalog.cjs` | The client, run as shipped in a sandbox |
| `tests/Integration/VideoTutorialsTest.php` | Menu position, enqueue gating, no server-side state |
| `tests/e2e/video-tutorials.spec.ts` | The page and the marker in a real browser, with wp-slimstat.com mocked |
