# Country → City drilldown: browser evidence

Captured on disposable WordPress 7.1.4 (PHP 8.3, MariaDB 10.11) with 92 seeded fixture pageviews, not a live site. Pro captures used a booted copy of wp-slimstat-pro 3.0.1. "Geolocation is off" in the map capture is the existing notice for a missing DB-IP file on the fixture site; the fixture rows carry their countries and cities directly. The Dashboard widget's "62 (0.00%)" is the same on `development` and unrelated to this feature.

Entry points
- [01-map-and-country-list](01-map-and-country-list.png): Audience Location. Map regions and country rows open the drilldown; the hovered row is underlined.
- [02-opened-from-map](02-opened-from-map.png): Germany, opened by clicking its map region (Free).
- [03-top-countries-report](03-top-countries-report.png): the standalone Top Countries report. Every part of a row opens it.

Free and Pro
- [04-free-dialog](04-free-dialog.png): Free. The real top city, blurred placeholder rows, the upsell and the no-city note.
- [05-pro-dialog](05-pro-dialog.png): Pro. Every city, with shares of the country's 62 pageviews.
- [06-pro-show-more](06-pro-show-more.png): Pro with "Rows to show" set to 3, paging with Show more cities.

Other states
- [07-city-precision-off](07-city-precision-off.png): Geolocation precision set to Country. The same state for Free and Pro.
- [08-no-city-data](08-no-city-data.png): a country whose pageviews carry no city.
- [09-free-390px](09-free-390px.png): Free at 390 px.
- [10-wp-dashboard-widget](10-wp-dashboard-widget.png): opened from the Top Countries widget on the WordPress Dashboard.
