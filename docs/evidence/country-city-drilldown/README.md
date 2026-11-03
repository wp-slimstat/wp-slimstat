# Country → City drilldown: browser evidence

Captured on disposable WordPress 7.1.4 (PHP 8.3, MariaDB 10.11) with seeded fixture rows, not a live site. Pro screenshots used a booted copy of wp-slimstat-pro 3.0.1. "Geolocation is off" in the map capture is the existing notice for a missing DB-IP file on the fixture site; the fixture rows carry their countries and cities directly.

- [free-top-city](free-top-city.png): Free, City precision on. The real top city, blurred placeholder rows and the upsell. No other city is in the response.
- [pro-all-cities](pro-all-cities.png): Pro. Every city, shares of the country's 62 pageviews, and the no-city note.
- [city-tracking-disabled](city-tracking-disabled.png): City precision set to Country. The same state for Free and Pro.
- [free-390](free-390.png): Free at 390 px.
- [map-list-keyboard-focus](map-list-keyboard-focus.png): Audience Location's country list. Each row is a keyboard button.
