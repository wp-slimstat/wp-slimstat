# Notification audiences for SlimStat 6.1.1

Use the Horizon plugin slug `wp-slimstat`. Tags combine with **AND**. The examples below target administrators and require the version that implements these audience rules.

| Audience | Copy these tags into Horizon |
| --- | --- |
| Everyone without usable Pro, including no plugin, no key and an invalid license | `is-admin`, `is-version-6.1.1`, `no-premium` |
| Pro plugin is not active, including sites where it is not installed | `is-admin`, `is-version-6.1.1`, `no-addon-wp-slimstat-pro` |
| Pro plugin is active but its license does not unlock Pro | `is-admin`, `is-version-6.1.1`, `has-addon-wp-slimstat-pro`, `no-premium` |
| Pro plugin is active and no license key is entered | `is-admin`, `is-version-6.1.1`, `has-addon-wp-slimstat-pro`, `no-license` |
| Pro plugin is active and the license server has rejected the current key | `is-admin`, `is-version-6.1.1`, `has-addon-wp-slimstat-pro`, `is-license-invalid` |
| Pro plugin is active with a verified license | `is-admin`, `is-version-6.1.1`, `is-license-active` |

For one sale notification covering all users without usable Pro, use the first row. Do not create overlapping copies for each subgroup unless they need different messages.

## Meanings and edge cases

- `is-premium` and `is-license-active` mean Pro is active and its license currently unlocks its features. `no-premium` is the inverse; `is-free` is its alias.
- `has-addon-wp-slimstat-pro` checks WordPress plugin activation, including network activation, independently of licensing. `has-addon` is its alias. `no-addon-wp-slimstat-pro` is the inverse. An installed but deactivated plugin belongs to the latter audience.
- `no-license` means there is no current key after trimming whitespace. It does not prove the customer has never bought Pro. Network-controlled keys use the same source as Pro licensing.
- `is-license-inactive` means a key is entered but Pro is not usable. This includes a deactivated Pro plugin, an invalid key, a site awaiting activation, and unverified status. It excludes missing keys, so use `no-premium` when those users must also receive the message.
- `is-license-invalid` means a definitive `invalid` verdict, including expired or revoked licenses. It excludes `domain_not_activated`, failed verification and network outages. Combine it with the Pro addon tag when the message only applies to an active Pro plugin.
- Audience evaluation uses the existing local, key-and-domain-bound license verdict. It makes no license-server request. A confirmed license retains the existing 72-hour outage grace period. After grace expires it is unverified, not invalid. Saving/renewing a license updates targeting as soon as the verified verdict changes; remote-only changes follow the existing license refresh schedule.
- Tags are evaluated when the cached feed is displayed, so a license or plugin-state change updates cards and unread counts without another Horizon fetch. Existing dismissals remain dismissed.
- `is-admin` requires the WordPress `manage_options` capability. Report viewers without it do not receive these examples.

## Publishing safely

Always keep `is-version-6.1.1` in new audience definitions. Older SlimStat versions treat unknown tags as matching. The version tag prevents those versions from receiving a message intended for a narrower audience. The new addon, `is-free` and invalid-license tags are hidden by 6.1.1 unless an adequate version tag is present. Existing license tags retain their historical 5.4.0 minimum, but the examples require 6.1.1 to obtain the corrected license semantics.

SlimStat 6.1.1 hides notices with unknown or malformed tags instead of showing them to everyone. Missing tags or an empty tag list retain untargeted behavior. Use only the tags documented here or the existing `is-version-*`, `is-locale-*`, `is-country-*` and `is-email-*` forms. Country matching uses the WordPress timezone, not a visitor's IP address.

Horizon must accept and expose these exact tag strings before publishing. In particular, add `is-license-invalid` to its tag catalog if the catalog restricts selectable values. The addon forms are the documented Horizon dynamic-tag format. This PR changes the plugin consumer; it does not publish or change Horizon records.

Notification targeting applies to the bell and its cards. Header marketing campaigns still preserve the Pro license-repair action and are hidden whenever the Pro plugin is active, even if a campaign's tags would match.

## Link attribution

New links to the SlimStat website default to `utm_source=wp-slimstat` and `utm_medium=link`; contextual help retains its existing `help` medium. Header controls use `utm_campaign=header` and a placement such as `cta`, `docs`, `support` or `find-license`. Horizon banners default to `utm_campaign=horizon` and `utm_content=header-banner-<id>`; notification buttons use `utm_campaign=notifications` and `utm_content=primary-<id>` or `secondary-<id>`.

For the sale, Horizon can publish `https://wp-slimstat.com/black-friday/?utm_campaign=bfcm2026`. Existing UTM values, other query parameters and anchors are preserved. Remaining website links in SlimStat screens receive the screen ID as their campaign and `content-link` as their placement, including links inserted later. This adds URL parameters only; it sends no tracking request and includes no site URL, user information or report filters. Links to the local site and other domains are unchanged.

On narrow screens, the header offer is represented by its notification only when both publisher URLs explicitly carry the same nonempty `utm_campaign` (for this sale, `bfcm2026`). Notification IDs and marketing-campaign IDs are independent. Different or missing campaign values keep the header offer visible, including after the notification panel refreshes.
