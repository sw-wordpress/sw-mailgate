# SW Mailgate

[![Release](https://img.shields.io/github/v/release/sw-wordpress/sw-mailgate?sort=semver)](https://github.com/sw-wordpress/sw-mailgate/releases)
[![CI](https://github.com/sw-wordpress/sw-mailgate/actions/workflows/tests.yml/badge.svg)](https://github.com/sw-wordpress/sw-mailgate/actions/workflows/tests.yml)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-mailgate&metric=coverage)](https://sonarcloud.io/summary/overall?id=sw-wordpress_sw-mailgate)
[![SonarQube Issues](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-mailgate&metric=vulnerabilities)](https://sonarcloud.io/project/issues?id=sw-wordpress_sw-mailgate&impactSoftwareQualities=SECURITY&resolved=false)
![PHPStan level 8](https://img.shields.io/badge/phpstan%20level-8%20of%209-brightgreen?style=flat-square&logo=php)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)
![WordPress 6.8+](https://img.shields.io/badge/WordPress-6.8%2B-21759b?style=flat-square&logo=wordpress&logoColor=white)
[![License GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

Sends WordPress mail through [SMTP](https://en.wikipedia.org/wiki/Simple_Mail_Transfer_Protocol),
[Mailgun](https://www.mailgun.com/), Gmail or Microsoft 365 instead of PHP `mail()`.
If one connection fails, the next one takes over. Optional delivery logs and staging
redirection show and control where mail goes.

## Features

- **Failover chain** — Mail goes through the first enabled connection; temporary or login errors move it to the next.
- **Several providers** — SMTP, Mailgun API, Gmail and Microsoft 365 via API or SMTP with [OAuth 2.0](https://en.wikipedia.org/wiki/OAuth).
- **Staging redirection** — Sends all mail to one test inbox, with an allowlist for addresses or domains.
- **Optional delivery log** — Off by default; with address masking, retention, CSV export and resend.
- **Retry queue** — Keeps mail that no connection could send and retries it later (opt-in).
- **Encrypted credentials** — Passwords, API keys and tokens are stored encrypted with [libsodium](https://en.wikipedia.org/wiki/Libsodium).

## Languages

English.

## Usage

1. Open **Mailgate → Connections** and under **Add connection** click a provider, e.g. **SMTP** or **Mailgun**.
2. Enter the provider data and the **From address**, keep **Use this connection** ticked and click **Add connection**.
3. In the saved connection, click **Test connection (no mail is sent)**, then **Send test mail** to check real delivery.
4. Optional: add more connections and order them with ↑/↓; later ones are fallbacks.
5. On staging sites, enter a test inbox under **Mailgate → Settings → Redirect mail to** and click **Save Changes**.

## Configuration

### Requirements

PHP 8.2+ with sodium, WordPress 6.8+, MySQL or MariaDB, single site or multisite; HTTPS for Gmail and Microsoft 365 OAuth.

### Settings

Connections are managed under **Mailgate → Connections**; redirection, log options, failover,
retry queue and uninstall behaviour under **Mailgate → Settings**. Optional `wp-config.php` constants:

```php
// Default: not set (off). Only boolean true enables the delivery log; the admin cannot.
define( 'SW_MAILGATE_LOG_ENABLED', true );

// Default: not set (admin setting applies). Forces and locks the redirect target; '' disables redirection.
define( 'SW_MAILGATE_REDIRECT_TO', 'staging-inbox@example.com' );

// Default: not set (key derived from WordPress salts). Dedicated encryption key, at least 32 characters.
define( 'SW_MAILGATE_KEY', 'a-long-random-string' );

// Default: not set. SW_MAILGATE_<CONNECTION ID>_<FIELD KEY> overrides one field of a connection
// and locks it in the editor, e.g. for the connection ID "primary":
define( 'SW_MAILGATE_PRIMARY_PASSWORD', 'smtp-password' );
```

Details on logging, redirection rules and overrides: [docs/configuration.md](docs/configuration.md).
Failover, circuit breaker and retry queue: [docs/delivery.md](docs/delivery.md).

### Background tasks

WP-Cron deletes expired log entries daily and, while the retry queue holds messages, retries them
every few minutes. On sites with little traffic or with `DISABLE_WP_CRON`, run WordPress cron from
the server, e.g. `*/5 * * * * wp cron event run --due-now --path=/path/to/wordpress`.

### Integrations

- **Gmail** — Sends via the Gmail API (send-only) or Google SMTP with XOAUTH2.
- **Microsoft 365** — Sends via Microsoft Graph (signed-in or application mailbox) or SMTP with XOAUTH2.
- **Mailgun** — Sends via the Mailgun HTTP API, US or EU region.

Setup and permissions: [docs/providers.md](docs/providers.md), OAuth step by step (German):
[docs/oauth-einrichtung.md](docs/oauth-einrichtung.md).

### Deactivation and removal

Deactivating stops the scheduled tasks and keeps all data; WordPress sends mail on its own again.
Deleting the plugin keeps connections, credentials, settings, logs and queued mail unless
**Delete connections, credentials, settings and logs when uninstalling** is ticked under
**Mailgate → Settings**. On multisite, each site's own choice applies. OAuth consent must be
revoked at Google or Microsoft separately. The WordPress privacy tools export and erase log
entries and erase queued mail of an address. Details: [docs/configuration.md](docs/configuration.md#multisite-uninstall).

## Troubleshooting

| Problem | Solution |
|---|---|
| Log shows **partial delivery** or **delivery uncertain**. | Some recipients may already have the mail. Check the provider's records before resending; see [docs/delivery.md](docs/delivery.md). |
| Connection test succeeds, but mail fails. | The test sends nothing. Use **Send test mail** to check sender and Send As permissions. |
| Mailgun connection test fails with a sending-only key. | The test needs permission to read domain details; sending may still work. |
| Notice: stored credentials cannot be decrypted. | `SW_MAILGATE_KEY` or the WordPress salts changed. Enter passwords and API keys again. |
| Notice: the redirection address is invalid. | Mail is blocked until you fix **Redirect mail to** or `SW_MAILGATE_REDIRECT_TO`. |
| The mail log stays empty. | Logging only works with `define( 'SW_MAILGATE_LOG_ENABLED', true );` in `wp-config.php`. |

## Built with AI

This plugin was implemented with AI assistance. Why it exists and how AI is used:
[sw-wordpress manifest](https://github.com/sw-wordpress/.github/blob/main/profile/README.md).

AI agents must follow the project rules before changing code:

- Rules and mandatory coverage: [AGENTS.md](AGENTS.md)
- Current status: [TODO.md](TODO.md)
- Behaviour and setup details: [docs/](docs/), tests: [tests/README.md](tests/README.md)
- README structure: [README-STANDARD.md](https://github.com/sw-wordpress/sw-securelogin/blob/master/docs/README-STANDARD.md)

## Development

Requirements: PHP 8.2+ with sodium and mysqli, Composer, a disposable WordPress copy with an empty
MariaDB/MySQL database for integration tests, and Xdebug for coverage. JavaScript and CSS are plain
files; there is no build step.

```sh
composer install
composer cs                  # WordPress coding standards (composer cs:fix fixes)
php tests/bootstrap.php      # smoke test of the checkout

# Full regression suite; never point it at a production database
WP_ROOT=/tmp/wordpress WP_DB_HOST=127.0.0.1:3306 php tests/run.php

# Same with coverage, writes coverage/clover.xml
XDEBUG_MODE=coverage WP_ROOT=/tmp/wordpress WP_DB_HOST=127.0.0.1:3306 php tests/run.php --coverage
```

Every change needs tests; line coverage must stay above 80 %. Single scenarios, database settings
and SonarQube setup: [tests/README.md](tests/README.md). GitHub Actions run the suite on every push;
pushing a version tag builds the release ZIP with [Mozart](https://github.com/coenjacobs/mozart).

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — see [LICENSE](LICENSE). Provided by Stefan Warnat.

Bundled third-party components:

- [PHPMailer](https://github.com/PHPMailer/PHPMailer) `OAuthTokenProvider` interface (compatibility copy for older WordPress) — LGPL-2.1, license text in `compat/PHPMailer/LICENSE`
- [Composer](https://getcomposer.org/) class loader (generated autoloader) — MIT, license text in `vendor-prefixed/composer/LICENSE` of the release build
