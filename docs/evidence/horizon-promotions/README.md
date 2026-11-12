# Horizon offer and header preview

Captured from the isolated local WordPress 7.1.4 review site on November 12, 2026, using the Free plugin and mocked Horizon options. These are browser screenshots of the implemented UI.

- `header-desktop.png`: desktop account controls, campaign pill and notification count.
- `promo-desktop.png`: the open notification panel at 1440 × 900.
- `promo-mobile.png`: the panel at 390 × 844, with a single purchase action and coupon copy control.

The fixture uses a rolling active window so the offer can be reviewed before the sale. Its countdown is test data. The actual sale runs November 20 00:00 through December 1 23:59 UTC, with code BFCM2026 for 30% off the first payment. Public Horizon content still needs to be published separately.

Reduced motion was enabled during capture to show settled interface states. These evidence files are excluded from the release package by `/docs` in `.distignore`.

## Final RTL and LTR review

`rtl-desktop.png` / `rtl-mobile.png` use WordPress فارسی (`fa_IR`). The corresponding `ltr-*` images use English (`en_US`). Desktop is 1440 px wide and mobile is 390 px wide. Existing untranslated plugin strings remain English, with their punctuation and dates isolated from the surrounding RTL layout.

The operational and migration notices in these screenshots are synthetic fixtures passed through the real notice renderer. They demonstrate long diagnostic text, wrapping, action placement, dismiss controls and severity colors. The local database does not require the migrations shown in these fixtures. Traffic and sale content are also review fixtures.

The audit covered the seven report pages, Heatmaps, AI Analysis, Email Report, Shortcodes, Customize, Videos, Upgrade to Pro and all eight settings tabs in both locales. It also checked Migration, 320 px and tablet layouts, Free and licensed Pro, populated and empty reports, large metric values, keyboard controls, and vertical scrolling at both report boundaries. Full-resolution screenshots and test logs were retained in the isolated local review workspace; these representative images are excluded from release packages by `.distignore`.

`browscap-ltr-*` and `browscap-rtl-*` show the real Browscap installation notice on Overview. It aligns with the report cards, keeps 16 px above and 20 px below, and has a 44 px dismiss target. Spacing and keyboard dismissal were checked at 1440, 768, 390 and 320 px in both locales.
