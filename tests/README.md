# Tests

M6 scenarios are included in the full runner and can be run separately:

    php tests/integration.php enabled oauth
    php tests/integration.php unset oauth

Real WordPress is used; all HTTP is intercepted and no external mail is sent.
Tests cover PKCE/state/session binding, replay/denial/expiry, encrypted tokens,
rotation, cache/config invalidation, competing/stale refresh claims, disconnect
during refresh, invalid token/scope responses, 401 renewal, MIME/Bcc, Gmail uploads,
Graph modes/size limits, safe diagnostics, setup HTML and admin authorization.
Connection tests deliberately do not claim to verify mailbox permissions.
Set SW_MAILGATE_TEST_PREVIEW to an existing directory to export fixture-only
editor HTML for browser checks.

Run the full regression suite from the repository root after Composer install and
with the disposable WordPress/database settings described below:

    WP_ROOT=/tmp/wordpress WP_DB_HOST=127.0.0.1:3306 php tests/run.php

Every scenario runs in a fresh PHP process. This preserves constant isolation,
standalone WordPress stubs and the lazy-loading lifecycle checks.

## Coverage and SonarQube Cloud

Install Xdebug, enable its coverage mode and run:

    XDEBUG_MODE=coverage WP_ROOT=/tmp/wordpress WP_DB_HOST=127.0.0.1:3306 php tests/run.php --coverage

The development-only php-code-coverage library collects each scenario through an
auto-prepended bootstrap, merges the process data and writes coverage/clover.xml.
It includes src/, templates/ and the two plugin entry points, including production
files never loaded by the tests. WordPress, tests and vendor code are excluded.
The runner checks that every instrumented scenario produced a fragment and stops
on test failure or a missing coverage driver. Raw fragments are private local
build data; only the Clover XML is uploaded as a CI artifact.

The extension-free SMTP diagnostic regression still runs with php -n. Coverage
adds a second diagnostic run with the driver loaded and mb_check_encoding disabled.
The test SMTP server subprocesses are fixtures, outside the measured source scope.

CI collects coverage on PHP 8.2 with current WordPress and runs the full suite on
the other PHP/WordPress combinations without instrumentation. Once all matrix
jobs pass, the scanner imports the Clover artifact for master and same-repository
pull requests. Relative source paths keep the report portable between jobs.
Fork pull requests run the tests and produce the artifact without requiring secrets.

One-time repository setup:

1. In GitHub Settings > Secrets and variables > Actions, create SONAR_TOKEN as a
   repository secret with permission to analyze the existing project.
2. Create SONAR_ORGANIZATION as a repository variable containing the SonarQube
   Cloud organization key (not its display name).
3. In SonarQube Cloud > Project Administration > Analysis Method, turn automatic
   analysis off. Coverage requires CI-based analysis.
4. Confirm that sonar.projectKey in sonar-project.properties matches the project;
   the linked project is sw-wordpress_sw-mailgate.

The scan reports an explicit configuration error if either GitHub setting is
missing. Do not place tokens in source files or pass them through test scripts.
The existing SonarQube quality gate continues to control its analysis check;
this change does not add an arbitrary coverage threshold.

See the official [PHP coverage guide](https://docs.sonarsource.com/sonarqube-cloud/analyzing-source-code/test-coverage/php-test-coverage).

Issue #8's SMTP diagnostic regression runs without PHP extensions loaded:

    WP_ROOT=/tmp/wordpress php -n -d disable_functions=mb_check_encoding tests/smtp-diagnostics.php

It uses WordPress's PHPMailer and the real provider against a loopback SMTP
fixture. The explicit disable also covers PHP builds with mbstring compiled in.
Sending and connection tests must succeed without mb_check_encoding, retain
valid UTF-8, omit invalid UTF-8/control data and keep AUTH/DATA private.

Run the checkout and uninstall smoke tests after `composer install`:

```sh
php tests/bootstrap.php
php tests/uninstall.php . delete
php tests/uninstall.php . preserve
php tests/uninstall.php . default
composer cs
```

The release workflow runs the same entry points against the packaged plugin,
including the prefixed autoloader.

`integration.php` boots real WordPress and uses a **disposable MariaDB/MySQL
database**. It creates WordPress tables with the `swmg_test_` prefix and deletes
the plugin's test data. Never point it at a production installation or database.
Download an unconfigured WordPress copy, enable PHP `mysqli` and `sodium`, then:

```sh
WP_ROOT=/tmp/wordpress WP_DB_HOST=127.0.0.1:3306 \
  WP_DB_NAME=mailgate_test WP_DB_USER=root WP_DB_PASSWORD='' \
  php tests/integration.php enabled
```

Run `integration.php` separately with `unset`, `disabled`, `string`, and `integer`
to verify logging stays off without an explicit boolean `true` constant. These
scenarios test that stored options and submitted settings cannot enable logging,
no bodies are captured, and no log rows are written. Separate processes isolate
immutable PHP constants. The `enabled` scenario exercises the full log feature set.

The transport is a deterministic test provider; it never sends external mail.
The suite covers schema upgrades, grouped failover outcomes, opt-in content,
recipient masking, Bcc/Reply-To privacy lookup, export/erasure, retention, resend,
query filtering, spreadsheet formula escaping, native admin rendering, database
failures during delivery and uninstall policies. CI runs it on WordPress 6.8 and
current WordPress with PHP 8.2/8.4.

Set `SW_MAILGATE_TEST_PLUGIN` to test a staged release directory instead of the
source checkout.

M3 redirection scenarios use the same isolated database and WordPress bootstrap:

```sh
php tests/integration.php enabled redirection ui
php tests/integration.php unset redirection ui
php tests/integration.php enabled redirection constant
php tests/integration.php enabled redirection empty
php tests/integration.php enabled redirection invalid
php tests/integration.php enabled redirection invalid-type
php tests/integration.php enabled redirection production
```

Use the same `WP_ROOT` / `WP_DB_*` environment variables as above. These scenarios
cover mixed To/Cc/Bcc allowlists, exact domain matching, Bcc header confidentiality,
deferred recipients, failover, native SMTP without configured connections, scoped
test-form bypass and cleanup, forced/disabled/invalid configuration, staging notices,
redirected log status and privacy lookup, masking, resend, settings validation, and
uninstall. SMTP is an in-memory test double; these tests do not connect to a server.

M4 Mailgun scenarios:

```sh
php tests/integration.php enabled mailgun api
php tests/integration.php unset mailgun api
php tests/integration.php enabled mailgun constant
```

The same `WP_ROOT` / `WP_DB_*` variables apply. WordPress's `pre_http_request`
intercepts every Mailgun request, so no API credentials or live account are needed
and no external mail is sent. Tests inspect the multipart wire payload, full
To/Cc/Bcc envelope and Bcc-only messages, MIME headers, HTML/plain alternatives and
binary attachments, regional hosts, read-only domain tests, encrypted keys and
wp-config overrides, TLS/timeouts/redirect settings, HTTP and transport failure
classification, ambiguous successful responses, safe diagnostics, redirection,
admin fields/test actions, logging opt-in and uninstall cleanup.

M5 failover hardening:

    php tests/integration.php enabled failover run

Uses the same disposable `WP_ROOT` / `WP_DB_*` configuration. A scripted transport
and `pre_http_request` interception cover the circuit breaker (threshold, cooldown,
half-open trial, recovery, all-paused fallback, explicit test mails, reset, disabled
breaker without writes), admin notice and connection list, alert mails that avoid the
failing connection, ambiguous Mailgun timeouts with and without failover, and the retry
queue: encryption at rest, backoff, cron scheduling, attempt-group continuation,
To/Cc/Bcc, HTML, custom headers, attachments whose temporary file was deleted,
per-connection senders, expiry, undecryptable entries, privacy erasure, size limits,
settings validation, the settings page, the dashboard widget and uninstall.

Issue #10's opaque secret regression:

    php tests/integration.php enabled issues secrets

Use the disposable WordPress/database configuration above. It exercises real
slashed form submissions, encrypted storage and SMTP credential retrieval for
spaces, tabs, whitespace-only values, quotes, backslashes and opaque bytes. It
also checks empty-field retention, explicit deletion/replacement and Mailgun's
rejection of invalid API keys without silently changing their stored bytes.

Issue #6, #12 and #13 regressions:

```sh
php tests/multisite-batches.php
php tests/integration.php enabled issues smtp
php tests/integration.php enabled issues editor
php tests/integration.php enabled issues editor-false
php tests/integration.php enabled multisite setup
php tests/integration.php enabled multisite run
```

Use the same disposable `WP_ROOT` / `WP_DB_*` configuration. SMTP cases run actual
PHPMailer and SMTP protocol handling with scripted I/O, covering mixed/all-recipient
rejections, DATA rejection/response loss, QUIT/RSET, post-acceptance callbacks/cleanup,
failover, explicit log states and disabled PHPMailer exceptions. No SMTP network
connection is made. Editor cases parse the generated HTML and exercise the real
save handler, including conflicting constants and forged locked-field submissions.

The multisite setup creates a network under the separate `swmg_multisite_` table
prefix; run it before the multisite test. Real WordPress site switching, role caches,
per-site options/tables and hook failures are exercised on four sites. The standalone
batch test additionally checks 205 simulated sites across three bounded queries and
supports a staged release directory as its optional argument.

Issue #9's TLS policy regression:

    WP_ROOT=/tmp/wordpress php tests/smtp-tls-policy.php

This standalone test uses OpenSSL and WordPress's PHPMailer, with real STARTTLS
and implicit TLS handshakes against a loopback fixture. It checks rejection of
untrusted and mismatched certificates despite inherited insecure options, explicit
verification opt-out, configured peer names and preservation of trust stores.

## Lazy mailer lifecycle

After `tests/integration.php enabled` has initialized the disposable database, run each
scenario in a fresh PHP process with the same `WP_ROOT` / `WP_DB_*` configuration:

```sh
for scenario in idle short-true short-false send foreign smtp retry; do
  php tests/lazy-loading.php "$scenario" || exit 1
done
```

These tests boot the real WordPress plugin lifecycle without manually installing the
catcher. Idle and intercepted sends must leave PHPMailer unloaded. First and repeated
sends use a deterministic provider; the competing-mailer case checks a lower-priority
replacement. The direct SMTP probe uses a closed loopback port. The retry case starts
with no prior mail operation and delivers a sealed fixture through the cron hook,
without replaying `wp_mail` filters. No external mail is sent.

PHPMailer is loaded only for an actual send, connection probe or retry. Reading or
configuring the global mailer before the first send is not supported; configure it
through the normal WordPress mail hooks instead. A later callback at the same final
priority can still replace the global mailer; competing mailer plugins are not
universally interoperable.
