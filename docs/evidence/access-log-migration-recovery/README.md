# Access Log and migration recovery verification

Captured on a disposable WordPress 6.9.4 site, PHP 8.3.37, MySQL 8.0.46.
The before screenshots use development commit `1144d704`; the after screenshots use this branch.
All visitor rows are synthetic.

| Scenario | Before | After |
| --- | --- | --- |
| Five pageviews sharing visit 3100074, with two missing fingerprints | [Two headers](access-log-before.png) | [One header](access-log-after.png) |
| Refused online repair of latin1 VARCHAR(255) city | [Bare Failed label](migration-before.png) | [Reason, recovery order, and status beside its own heading](migration-after.png) |

[Persian admin locale at 600px](migration-rtl-narrow.png): actual fa_IR WordPress styles, status and actions remain readable, no horizontal overflow.

Verification:

- Access Log regression failed on development (two headers instead of one), then passed after the fix. Different fingerprints, visit IDs, IPs, browsers, platforms and usernames still separate groups.
- Six browser scenarios passed with Free alone, then all six passed with licensed Pro enabled through the local test fixture. They cover missing metadata, online refusal, manual SQL recovery, custom attributes, the two-button recovery, and safe rendering of JSON/permission/transport errors.
- Free: 839 unit tests / 2,487 assertions; 208 integration tests / 872 assertions; source suite; 85 live report escaping assertions; PHPStan; POT generation and freshness check.
- Pro: 157 unit tests / 402 assertions; source suite; 43 mutation controls; PHPStan; POT freshness check.
- Pro's database probe passed on same-database and separate-database MySQL connections and on a separate MariaDB server. Historical values survive conversion and width repair. Custom archive definitions block both tables without mutation.
- Packaged Free passes Plugin Check with zero errors. Its established slug triggers the existing `trademarked_term` advisory. Pro retains its private updater, which is outside WordPress.org distribution rules; its companion PR fixes nested macOS metadata packaging.

The conversion remains an explicit optional step because it can pause tracking writes. No automatic blocking fallback was added.
